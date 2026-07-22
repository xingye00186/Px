<?php

namespace Px\Render;
use Px\Css\StyleRecalcPass;
use Px\Css\CssMappings;
use Px\Css\ComputedStyle;

use native_types;
use Px\Dom\VNode;
use Px\Css\StyleResolver;

use Px\Core\Config;
use Px\Component\Contracts\ReactiveComponentInterface;
use Px\Component\ReactiveComponent;
use Px\Theme\ThemeProvider;

/**
 * RenderTreeManager — VNode → RenderNode 转换管理
 *
 * 职责：
 *   1. 将 VNode 树转换为 RenderNode 树
 *   2. 通过 type+key 匹配实现 RenderNode 跨帧复用
 *   3. 维护 groupId → RenderNode[] 和 VNode → RenderNode 映射
 *   4. 在 RenderNode 树上执行命中测试和滚动容器查找
 *
 * 核心原则：
 *   - 不预先计算任何坐标，所有偏移统一由 LayoutResolver 在布局阶段处理
 *   - 使用 type+key 显式匹配，替代旧的 spl_object_hash 隐式匹配
 *   - 正常渲染循环中不清空映射（仅全量重置时调用 clear()）
 *   - 未匹配/未引用的旧 RenderNode 树通过 destroyRenderNodeTree 清理
 */
class RenderTreeManager
{
    /** @var array<callable> RenderNode 销毁回调（Application 注册用于清理 ScrollManager/InteractionState） */
    private array $destroyCallbacks = [];

    /**
     * 注册 RenderNode 销毁回调。当节点被 destroyRenderNodeTree 销毁时触发。
     * 用于清理 ScrollManager、InteractionState 等外部状态映射中的 orphan 条目。
     */
    public function onDestroyNode(callable $callback): void
    {
        $this->destroyCallbacks[] = $callback;
    }

/**
     * 递归生成 RenderNode 树的调试快照文本。
     * AOT 安全：无引用传参，无 mb_ 函数，纯字符串拼接。
     */
    public function dumpRenderTree(?RenderNode $node, int $frame, array $events, string $detail = 'normal'): string
    {
        if ($node === null) {
            return "";
        }

        $output = "";

        // header: frame number + event summary
        $output .= "Frame #";
        $output .= (string)$frame;
        $eventCount = count($events);
        $output .= " events=";
        $output .= (string)$eventCount;
        $output .= " detail=";
        $output .= $detail;
        $output .= "\n";

        // dump tree from root
        $output .= $this->dumpNode($node, "", $detail);

        return $output;
    }

    /**
     * 递归输出单个 RenderNode 及其子树。
     *
     * @param string $detail 'minimal' | 'normal' | 'verbose'
     */
    private function dumpNode(?RenderNode $node, string $prefix, string $detail = 'normal'): string
    {
        if ($node === null) {
            return "";
        }

        $output = "";

        // ── 基本信息（所有级别共有） ──
        $output .= $prefix;
        $output .= $node->type;
        if ($node->key !== null) {
            $output .= "[" . $node->key . "]";
        }
        $output .= " (";
        $output .= (string)$node->x;
        $output .= ",";
        $output .= (string)$node->y;
        $output .= " ";
        $output .= (string)$node->w;
        $output .= "x";
        $output .= (string)$node->h;
        $output .= ")";

        // ── scroll info（所有级别） ──
        if ($node->isScrollContainer) {
            $output .= " scroll";
            if ($detail !== 'minimal') {
                $output .= " ch=";
                $output .= (string)$node->contentHeight;
                $output .= " cw=";
                $output .= (string)$node->contentWidth;
                $output .= " maxScroll=";
                $output .= (string)max($node->contentHeight - $node->h, 0);
            }
            $output .= " st=";
            $output .= (string)$node->scrollTop;
            $output .= " sl=";
            $output .= (string)$node->scrollLeft;
        }

        // ── normal/verbose 级别附加信息 ──
        if ($detail !== 'minimal') {
            $cs = $node->computedStyle;
            // border info
            $bw = $cs?->borderWidth?->top?->toPx() ?? 0;
            if ($bw > 0) {
                $output .= " bw=";
                $output .= (string)$bw;
            }

            // overflow
            $overflow = $cs?->overflow?->value ?? $cs?->overflowX?->value ?? '';
            if ($overflow !== '' && $overflow !== 'visible') {
                $output .= " ov=";
                $output .= $overflow;
            }

            // flex grow/shrink
            $flexGrowRaw = $cs?->getRaw('flexGrow');
            $fg = is_numeric($flexGrowRaw) ? (string)$flexGrowRaw : '';
            if ($fg !== '' && $fg !== 0) {
                $output .= " fg=";
                $output .= (string)$fg;
            }
        }

        // ── verbose 级别：完整 style 和脏标记 ──
        if ($detail === 'verbose') {
            if ($node->layoutDirty) {
                $output .= " DIRTY";
            }
            $output .= " layer=";
            $output .= (string)$node->layer;
        }

        // content / text
        if ($node->content !== null && $node->content !== "") {
            $content = $node->content;
            if (strlen($content) > 40) {
                $content = substr($content, 0, 40) . "...";
            }
            $output .= ' text="';
            $output .= $content;
            $output .= '"';
        }

        // groupId（verbose 级别才输出）
        if ($node->groupId !== null && $detail === 'verbose') {
            $output .= " gid=";
            $output .= $node->groupId;
        }

        $output .= "\n";

        // children
        $childPrefix = $prefix . "  ";
        foreach ($node->children as $child) {
            $output .= $this->dumpNode($child, $childPrefix, $detail);
        }

        return $output;
    }

    private ?RenderNode $rootRenderNode = null;

    /** @var RenderNode[] 顶层 #root 的所有直接子节点（用于跨帧 candidates 传递） */
    private array $rootRenderNodes = [];

    /** @var array<string, RenderNode[]> groupId => RenderNode[] */
    private array $groupIdToRenderNodeMap = [];

    // ── 基础方法 ──────────────────────────

    public function getRootRenderNode(): ?RenderNode
    {
        return $this->rootRenderNode;
    }

    /** @return RenderNode[] */
    public function getRootRenderNodes(): array
    {
        return $this->rootRenderNodes;
    }

    /**
     * 清空所有映射（仅在全量重置时调用）。
     * 正常渲染循环中不要调用 clear()。
     */
    public function clear(): void
    {
        $this->rootRenderNode = null;
        $this->rootRenderNodes = [];
        $this->groupIdToRenderNodeMap = [];
    }

    // ── 查找方法 ──────────────────────────

    /**
     * 根据 VNode 查找对应的 RenderNode（通过树遍历）。
     */
    public function findRenderNodeBySourceVNode(VNode $vnode): ?RenderNode
    {
        if ($this->rootRenderNode === null) return null;
        return $this->findRNByVNodeRecursive($vnode, $this->rootRenderNode);
    }

    private function findRNByVNodeRecursive(VNode $vnode, RenderNode $node): ?RenderNode
    {
        if ($node->sourceVNode === $vnode) return $node;
        foreach ($node->children as $child) {
            $found = $this->findRNByVNodeRecursive($vnode, $child);
            if ($found !== null) return $found;
        }
        return null;
    }

    /**
     * 根据 groupId 查找所有匹配的 RenderNode。
     * 一个 groupId 可能对应多个节点（如组件内多个元素）。
     */
    public function findRenderNodeByGroupId(string $groupId): array
    {
        return $this->groupIdToRenderNodeMap[$groupId] ?? [];
    }

    /**
     * 返回第一个匹配的 RenderNode（用于调试）。
     */
    public function findFirstRenderNodeByGroupId(string $groupId): ?RenderNode
    {
        $nodes = $this->findRenderNodeByGroupId($groupId);
        return $nodes[0] ?? null;
    }

    // ── 匹配与清理方法 ─────────────────────

    /**
     * 在新 VNode 和旧 RenderNode 候选池之间匹配。
     *
     * 匹配规则：
     *   1. $vnode->key !== null → 遍历 candidates 找 key + type 匹配
     *   2. $vnode->key === null → 按 $index 逐位匹配（type 相同且 key 为 null）
     *
     * @param VNode $vnode 新 VNode
     * @param array $candidates 旧 RenderNode 候选列表
     * @param int $index 在父级子节点中的位置（用于位置匹配）
     * @return RenderNode|null 匹配的旧 RenderNode，或 null
     */
    private function findMatchingRenderNode(VNode $vnode, array &$candidates, int $index): ?RenderNode
    {
        $key = $vnode->key;

        if ($key !== null) {
            // key 匹配 + 从候选池移除（防止后续索引匹配二次命中）
            foreach ($candidates as $i => $candidate) {
                if ($candidate->key === $key && $candidate->type === $vnode->type) {
                    unset($candidates[$i]);
                    return $candidate;
                }
            }
            return null;
        }

        // 位置匹配（静态节点）
        if (isset($candidates[$index])) {
            $candidate = $candidates[$index];
            if ($candidate->key === null && $candidate->type === $vnode->type) {
                unset($candidates[$index]);
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 比较两个 VNode 的布局相关属性是否相等。
     *
     * 消费 patchFlags 跳过不必要的比较：
     *   - PATCH_NONE: 完全静态，直接返回 true（编译器保证无变化）
     *   - 仅 PATCH_EVENT: 事件不影响布局，跳过 style/class 比较
     *   - PATCH_STYLE: 比较 :style 和 style
     *   - PATCH_CLASS: 比较 class
     *   - 始终比较 scroll/bind（布局强相关）
     *
     * @return bool true=布局相关属性完全一致，可走洁净路径
     */
    private function areVNodesEqual(VNode $a, VNode $b): bool
    {
        if ($a->type !== $b->type) return false;
        if ($a->key !== $b->key) return false;

        // patchFlag 快速路径：完全静态节点跳过所有 props 比较
        $flags = $a->patchFlags;
        if ($flags === VNode::PATCH_NONE) {
            return true;
        }

        // 仅比较 patchFlag 标记的动态属性
        if (($flags & VNode::PATCH_STYLE) !== 0) {
            if (($a->props['style'] ?? '') !== ($b->props['style'] ?? '')) return false;
            if (($a->props[':style'] ?? '') !== ($b->props[':style'] ?? '')) return false;
        }
        if (($flags & VNode::PATCH_CLASS) !== 0) {
            if (($a->props['class'] ?? '') !== ($b->props['class'] ?? '')) return false;
        }
        if (($flags & VNode::PATCH_PROPS) !== 0) {
            // 比较所有 : 开头的动态属性（排除已处理的 :style/:class）
            foreach ($a->props as $k => $v) {
                if (str_starts_with($k, ':') && $k !== ':style' && $k !== ':class') {
                    if (($a->props[$k] ?? '') !== ($b->props[$k] ?? '')) return false;
                }
            }
        }
        if (($flags & VNode::PATCH_TEXT) !== 0) {
            // {{ }} 动态文本内容比较
            $aText = is_string($a->children) ? $a->children : '';
            $bText = is_string($b->children) ? $b->children : '';
            if ($aText !== $bText) return false;
        }

        // scroll/bind 始终比较（布局强相关，不受 patchFlag 控制）
        if (($a->props[':scroll-top'] ?? '') !== ($b->props[':scroll-top'] ?? '')) return false;
        if (($a->props[':scroll-left'] ?? '') !== ($b->props[':scroll-left'] ?? '')) return false;
        if (($a->props[':bind'] ?? '') !== ($b->props[':bind'] ?? '')) return false;
        if (($a->props['bind'] ?? '') !== ($b->props['bind'] ?? '')) return false;
        if (($a->props['v-model'] ?? '') !== ($b->props['v-model'] ?? '')) return false;
        return true;
    }

    /**
     * 递归销毁 RenderNode 子树，并从所有映射/动画中移除。
     *
     * 清理：
     *   - 从父节点 children 中移除
     *   - 从 groupIdToRenderNodeMap 移除所有后代节点
     *   - 取消 AnimationManager 中的动画
     *   - 递归销毁子节点
     *   - 断开 sourceVNode/computedStyle 引用（帮助 GC）
     */
    public function destroyRenderNodeTree(RenderNode $rn, bool $removeFromParent = true): void
    {
        if (Config::get('debug_diag_enabled', false)) {
            $dsp = $rn->computedStyle?->display?->value ?? '';
            error_log('[DIAG] DESTROY: type=' . $rn->type . ' dsp=' . $dsp
                . ' children=' . count($rn->children));
        }

        // 1. 从父节点 children 中移除
        if ($removeFromParent && $rn->parent !== null) {
            $parent = $rn->parent;
            $idx = array_search($rn, $parent->children, true);
            if ($idx !== false) {
                array_splice($parent->children, $idx, 1);
            }
            $rn->parent = null;
        }

        // 2. 从 groupIdToRenderNodeMap 中移除所有后代节点
        $groupIds = [];
        $this->collectGroupIds($rn, $groupIds);
        foreach ($groupIds as $gid) {
            if (isset($this->groupIdToRenderNodeMap[$gid])) {
                $this->groupIdToRenderNodeMap[$gid] = array_values(
                    array_filter(
                        $this->groupIdToRenderNodeMap[$gid],
                        fn($n) => $n !== $rn && !$this->isDescendantOf($n, $rn)
                    )
                );
                if (empty($this->groupIdToRenderNodeMap[$gid])) {
                    unset($this->groupIdToRenderNodeMap[$gid]);
                }
            }
        }

        // 3. 取消 AnimationManager 中的动画
        \Px\Animation\AnimationManager::getInstance()->cancelAllTransitions($rn);

        // 3.5 调用所有销毁回调（清理 ScrollManager/InteractionState 等外部映射）
        foreach ($this->destroyCallbacks as $cb) {
            $cb($rn);
        }

        // 4. 递归销毁子节点
        foreach ($rn->children as $child) {
            $this->destroyRenderNodeTree($child, false);
        }
        $rn->children = [];

        // 5. 断开引用（帮助 GC）
        $rn->sourceVNode = null;
        $rn->computedStyle = null;
        $rn->cachedFragment = null;
        $rn->cachedConstraintSpace = null;
    }

    /**
     * 递归收集节点及其所有后代的 groupId（去重）。
     */
    private function collectGroupIds(RenderNode $node, array &$collector): void
    {
        if ($node->groupId !== null) {
            $collector[$node->groupId] = true;
        }
        foreach ($node->children as $child) {
            $this->collectGroupIds($child, $collector);
        }
    }

    /**
     * 检查 $node 是否为 $ancestor 的后代。
     */
    private function isDescendantOf(RenderNode $node, RenderNode $ancestor): bool
    {
        $current = $node;
        while ($current !== null) {
            if ($current === $ancestor) return true;
            $current = $current->parent;
        }
        return false;
    }

    // ── VNode → RenderNode 转换 ──────────

    /**
     * 将 VNode 树转换为 RenderNode 树，内嵌 bind 值同步。
     *
     * 布局职责边界：
     *   - RenderTreeManager 只做 VNode → RenderNode 映射和样式合并，
     *     不预先计算任何坐标。
     *   - 所有偏移（父组件传递的定位、margin/padding、相对定位、
     *     绝对定位、滚动偏移等）统一由 LayoutResolver 在布局阶段处理。
     *   - #component 占位符的 style(left/top) 已在 Application::expandComponentNode
     *     中写入子组件根元素 VNode 的 style，此处无需额外处理。
     *
     * @param VNode $vnode 源 VNode 节点
     * @param RenderNode|null $parent 父 RenderNode
     * @param ReactiveComponent $root 根组件（用于 bind 回退）
     * @param array<string, ReactiveComponent> $componentByGroupId 组件注册表
     * @param array|null $candidates 上一帧该位置旧 RenderNode 候选列表
     *        普通元素：[$selfOld]，用于自我匹配后提取旧 children 匹配子节点
     *        #root：旧子节点列表（因为 #root 无 RenderNode）
     *        #component：透传
     * @param string $currentGroupId 当前组件的 groupId
     *        由 #component handler 传入组件实例 ID，#root handler 传入 'app'。
     *        所有子节点继承此 groupId，不再从 VNode.groupId 读取。
     * @return RenderNode|null 转换后的 RenderNode
     */
    public function updateFromVNode(
        VNode $vnode,
        ?RenderNode $parent,
        ReactiveComponentInterface $root,
        array $componentByGroupId,
        ?array $candidates = null,
        string $currentGroupId = 'app',
        string $parentClassStr = '',
        array $parentStyle = []
    ): ?RenderNode {
        \Px\Core\PerfCounter::start('tree_convert');
        try {
            // 组件占位节点：递归处理子组件树，传递旧根 RenderNode 作为候选
            // Vue 3 标准：父组件 props['style'] 全部透传合并到子组件根元素
            if ($vnode->isComponent()) {
                $instance = $vnode->componentInstance;
                if ($instance === null) {
                    if (Config::get('debug_diag_enabled', false)) {
                        error_log('[DIAG] RTM: #component(' . $vnode->componentClass . ') SKIPPED - instance=null');
                    }
                    return null;
                }

                // 记录展开前的子节点数，用于定位第一个新增的子 RenderNode
                $beforeCount = $parent !== null ? count($parent->children) : 0;
                
                // 从组件实例获取 groupId，传递给子 VNode 树
                // （替代已废弃的 setGroupIdRecursive 对 VNode.groupId 的写入）
                $childGroupId = $instance->getId();
                
                $oldRootRN = $instance->getRootRenderNode();

                // ── Vue 3 × Blink 融合点：组件级 RenderNode 子树跳过 ──
                // Vue 3: !$instance->renderDirty → vnodeCache 复用 → VNode 树未变
                // Blink: ChildNeedsStyleRecalc = false → O(1) 跳过子树
                // 融合：组件未 dirty → VNode 树是缓存对象 → RenderNode 树也不需要重建
                //       直接复用旧 RN 子树，跳过 O(N) 递归遍历
                // 注意：用 renderDirty 而非 dirty——dirty 在 getVNodeTree() 中已被清除
                $wasRenderDirty = $instance->isRenderDirty();
                $instance->clearRenderDirty();
                if (!$wasRenderDirty && $oldRootRN !== null) {
                    // 1. 重新挂接到父节点（每帧 parent->children 被清空重建）
                    $oldRootRN->parent = $parent;
                    if ($parent !== null) {
                        $parent->children[] = $oldRootRN;
                    }

                    // 2. 父驱动 style 透传（仅当 placeholder style 变化时更新）
                    $placeholderStyle = $vnode->props['style'] ?? [];
                    if (!empty($placeholderStyle)) {
                        $parsedDecls = is_string($placeholderStyle)
                            ? \Px\Css\StyleResolver::parseInlineStyle($placeholderStyle)
                            : $placeholderStyle;
                        // 检测 style 是否实际变化，避免不必要的 layoutDirty
                        $currentArr = $oldRootRN->computedStyle?->toExportArray() ?? [];
                        $styleChanged = false;
                        foreach ($parsedDecls as $sk => $sv) {
                            if (($currentArr[$sk] ?? null) !== $sv) {
                                $styleChanged = true;
                                break;
                            }
                        }
                        if ($styleChanged) {
                            // 组件 style 透传合并—走 StylePool 池化（以旧 CS 为父身份）
                            $oldRootRN->computedStyle = \Px\Css\StylePool::intern(
                                $parsedDecls,
                                $oldRootRN->computedStyle,
                                $oldRootRN->type,
                                \Px\Css\StylePool::fingerprintInline($parsedDecls),
                                ''
                            );
                            $oldRootRN->layoutDirty = true;
                        }
                    }

                    // 3. 注册 groupId（仅根节点，内部节点已在首帧注册且 groupId 不变）
                    if ($oldRootRN->groupId !== null) {
                        $this->groupIdToRenderNodeMap[$oldRootRN->groupId][] = $oldRootRN;
                    }

                    // 4. 同步 scroll bind 值（父组件可能更新了绑定值）
                    $component = $componentByGroupId[$childGroupId] ?? $root;
                    $scrollBindKey = $vnode->props[':scroll-top'] ?? '';
                    if ($scrollBindKey !== '') {
                        $oldRootRN->scrollTop = (int) $component->getBindValue($scrollBindKey);
                    }
                    $scrollLeftBindKey = $vnode->props[':scroll-left'] ?? '';
                    if ($scrollLeftBindKey !== '') {
                        $oldRootRN->scrollLeft = (int) $component->getBindValue($scrollLeftBindKey);
                    }

                    \Px\Core\PerfCounter::inc('component_skip');
                    return $oldRootRN;
                }

                // 组件 dirty → 走原有递归路径
                $childRN = $this->updateFromVNode(
                    $instance->getVNodeTree(),
                    $parent,
                    $root,
                    $componentByGroupId,
                    $oldRootRN !== null ? [$oldRootRN] : null,
                    $childGroupId,
                    $vnode->props['class'] ?? '',
                    $parentStyle
                );

                // 保留 scrollTop 值：从旧子树复制到新子树（仅 scroll containers）
                if ($oldRootRN !== null && $childRN !== null) {
                    $this->copyScrollTopFromOld($childRN, $oldRootRN);
                }

                // 清理旧框架组件根节点：旧帧残留的 RenderNode 树不再需要
                if ($oldRootRN !== null && $oldRootRN !== $childRN) {
                    $this->destroyRenderNodeTree($oldRootRN);
                }

                // 存储当前根 RenderNode 供下一帧 scrollTop 保留使用
                $instance->setRootRenderNode($childRN);

                // Vue 3 标准：父组件 props['style'] 全部透传合并到子组件根元素
                // 子组件自身 style 为基准，父组件 style 覆盖（CSS 标准层叠规则）
                $placeholderStyle = $vnode->props['style'] ?? [];
                if (!empty($placeholderStyle)) {
                    $targetRN = null;
                    if ($parent !== null && $beforeCount < count($parent->children)) {
                        // 常规情况：通过 parent->children 定位新创建的 RN
                        $newChildren = array_slice($parent->children, $beforeCount);
                        if (count($newChildren) > 0) {
                            $targetRN = $newChildren[0];
                        }
                    } elseif ($parent === null && $childRN !== null) {
                        // #component 直接作为 #root 子节点（parent=null）时，
                        // 子组件树的 RN 由递归返回的 $childRN 直接持有
                        $targetRN = $childRN;
                    }

                    if ($targetRN !== null) {
                        $parsedDecls = is_string($placeholderStyle)
                            ? \Px\Css\StyleResolver::parseInlineStyle($placeholderStyle)
                            : $placeholderStyle;
                        // 父组件 style 透传到子组件根—走 StylePool 池化（以子根 RN 当前 CS 为父身份）
                        $targetRN->computedStyle = \Px\Css\StylePool::intern(
                            $parsedDecls,
                            $targetRN->computedStyle,
                            $targetRN->type,
                            \Px\Css\StylePool::fingerprintInline($parsedDecls),
                            ''
                        );
                        $targetRN->layoutDirty = true;
                    }
                }

                return $childRN;
            }

            // #root 节点
            if ($vnode->type === '#root') {
                $result = null;
                $children = VNode::childrenToArray($vnode->children);

                if ($parent === null) {
                    $this->rootRenderNodes = [];
                    $this->groupIdToRenderNodeMap = [];
                }

                $consumedCandidates = [];

                foreach ($children as $i => $child) {

                    $matchedOld = ($candidates !== null)
                        ? $this->findMatchingRenderNode($child, $candidates, $i)
                        : null;

                    if ($matchedOld !== null) {
                        $consumedCandidates[] = $matchedOld;
                    }

                    $childCandidates = $matchedOld !== null ? [$matchedOld] : null;

                    $childRN = $this->updateFromVNode(
                        $child, $parent, $root, $componentByGroupId, $childCandidates,
                        $currentGroupId,
                        $vnode->props['class'] ?? '',
                        $parentStyle
                    );
                    if ($childRN !== null) {
                        if ($parent === null) {
                            $this->rootRenderNode = $childRN;
                            $this->rootRenderNodes[] = $childRN;
                        }
                        $result = $childRN;
                    }
                }

                if ($candidates !== null) {
                    foreach ($candidates as $oldRN) {
                        if (!in_array($oldRN, $consumedCandidates, true)) {
                            $this->destroyRenderNodeTree($oldRN);
                        }
                    }
                }

                return $result;
            }

            // 普通元素节点 — 读取预计算的样式（由 StyleRecalcPass 写入 VNode）
            $pseudoStyles = [];
            $computedStyle = $vnode->computedStyle;
            if ($computedStyle === null) {
                \Px\Core\PerfCounter::start('sub:style_fallback');
                // 降级：StyleRecalcPass 未运行时内联解析
                // 防御：style 可能为 string（编译期未覆盖路径）→ 强制 array
                $rawInlineStyle = $vnode->props['style'] ?? [];
                $inlineStyleForResolve = is_array($rawInlineStyle) ? $rawInlineStyle : [];
                // 降级路径下构造一次性临时父 CS（仅供 StyleResolver 接口），不入池避免污染
                $tempParentCS = !empty($parentStyle) ? new ComputedStyle($parentStyle) : null;
                $computedStyle = StyleResolver::resolve(
                    inlineStyle: $inlineStyleForResolve,
                    className: $vnode->props['class'] ?? '',
                    parentCS: $tempParentCS,
                    elementType: $vnode->type,
                    parentClassStr: $parentClassStr,
                    precedingSiblingClasses: [],
                    pseudoStyles: $pseudoStyles
                );
                \Px\Core\PerfCounter::end('sub:style_fallback');
            }
            // 补充 pseudoStyles：StyleRecalcPass 已运行时从 theme 提取伪类/伪元素定义
            // （resolveClassStyles 通过 by-ref 填充，但 StyleRecalcPass 运行后不会进入降级分支）
            if (empty($pseudoStyles)) {
                $pseudoStyles = \Px\Css\StyleResolver::extractPseudoStyles(
                    $vnode->props['class'] ?? '',
                    $vnode->type
                );
            }
            $resolvedStyle = $computedStyle->toExportArray();

            // 合并 HTML align 属性到 textAlign（CSS text-align 优先）—走 StylePool::withOverride 池化派生
            if (($vnode->props['align'] ?? '') !== '' && empty($resolvedStyle['textAlign'])) {
                $computedStyle = \Px\Css\StylePool::withOverride(
                    $computedStyle,
                    ['textAlign' => $vnode->props['align']],
                    $vnode->type
                );
                $resolvedStyle = $computedStyle->toExportArray();
            }

            // 合并 :style 动态绑定（StyleRecalcPass 只解析静态 style，丢弃 :style）
            // 编译期已数组化，直接合并零 regex；走 StylePool::withOverride 池化派生
            $dynamicStyle = $vnode->props[':style'] ?? [];
            if (!empty($dynamicStyle) && is_array($dynamicStyle)) {
                \Px\Core\PerfCounter::start('sub:style_dynamic');
                $computedStyle = \Px\Css\StylePool::withOverride(
                    $computedStyle,
                    $dynamicStyle,
                    $vnode->type
                );
                $resolvedStyle = $computedStyle->toExportArray();
                \Px\Core\PerfCounter::end('sub:style_dynamic');
            }

            // 计算 LayoutBoundary 标记：显式固定 width+height → 布局可独立于父约束
            $isLayoutBoundary = false;
            if ($computedStyle !== null) {
                $w = $computedStyle->width;
                $h = $computedStyle->height;
                $isLayoutBoundary = (
                    $w !== null && !$w->isPercent() && !$w->isAuto() && $w->toPx() > 0
                    && $h !== null && !$h->isPercent() && !$h->isAuto() && $h->toPx() > 0
                );
            }

            $renderNode = null;
            // ── 跟踪父 VNode 是否变化（用于 patchKeyedChildren head/tail sync 门控）──
            // 对标 Blink ChildNeedsStyleRecalc：父 style 变化时子节点可能受 CSS 继承影响
            $parentVNodeChanged = true;

            if ($candidates !== null) {
                $matched = $this->findMatchingRenderNode($vnode, $candidates, 0);
                if ($matched !== null) {
                    $renderNode = $matched;
                }
            }

            $groupId = $currentGroupId;

            if ($renderNode === null) {
                $renderNode = new RenderNode($vnode->type, $computedStyle, null, $vnode->key);
                $renderNode->sourceVNode = $vnode;
                $renderNode->groupId = $groupId;
                $renderNode->layoutDirty = true;
                $renderNode->pseudoStyles = $pseudoStyles;
                $renderNode->isLayoutBoundary = $isLayoutBoundary;
                // 从 computedStyle 检测滚动容器
                $ovX = $computedStyle?->overflowX?->value ?? $computedStyle?->overflow?->value ?? '';
                $ovY = $computedStyle?->overflowY?->value ?? $computedStyle?->overflow?->value ?? '';
                if ($ovX === 'auto' || $ovX === 'scroll' || $ovY === 'auto' || $ovY === 'scroll') {
                    $renderNode->isScrollContainer = true;
                }
            } else {
                $oldVNode = $renderNode->sourceVNode;
                $renderNode->computedStyle = $computedStyle;
                $renderNode->sourceVNode = $vnode;
                $renderNode->groupId = $groupId;
                $renderNode->pseudoStyles = $pseudoStyles;
                $renderNode->isLayoutBoundary = $isLayoutBoundary;

                $vnodeChildren = is_array($vnode->children)
                    ? VNode::childrenToArray($vnode->children)
                    : [];
                $isLeaf = count($vnodeChildren) === 0;
                $hasExplicitTop = array_key_exists('top', $resolvedStyle);

                // ── 脏位分类判定 ────────────────────────────────────
                // 优先走快速路径：VNode props 完全一致 → 完全洁净
                $oldStyle = ($oldVNode !== null) ? $oldVNode->computedStyle : null;
                if ($oldVNode !== null && $this->areVNodesEqual($vnode, $oldVNode)) {
                    // VNode 完全一致（含 style/class/bind）→ 完全洁净
                    $parentVNodeChanged = false;
                    // 注意：保留外部事件设置的 dirty bits（如鼠标 hover 调用的 markStyleDirty）
                    // 外部设置的 styleDirty=true 不应被 VNode 比较结果覆盖
                    if ($renderNode->layoutDirty || $renderNode->styleDirty) {
                        // 外部 dirty 已存在：确保 paintDirty 同步
                        $renderNode->paintDirty = true;
                    } else {
                        $renderNode->layoutDirty = false;
                        $renderNode->paintDirty = false;
                        $renderNode->styleDirty = false;
                    }
                } elseif ($oldStyle !== null) {
                    // 检查是否有几何关键属性变化（用 toExportArray 得到标量值）
                    $isGeometryChange = false;
                    $oldDecl = $oldStyle->toExportArray();
                    $geoKeys = ['width','height','minWidth','maxWidth','minHeight','maxHeight',
                        'display','position','flex','flexDirection','flexWrap',
                        'alignItems','alignContent','justifyContent',
                        'boxSizing','overflow','overflowX','overflowY',
                        'padding','margin','borderWidth'];
                    foreach ($geoKeys as $k) {
                        $oldV = $oldDecl[$k] ?? null;
                        $newV = $resolvedStyle[$k] ?? null;
                        if ($oldV !== $newV) {
                            $isGeometryChange = true;
                            break;
                        }
                    }
                    if ($isGeometryChange) {
                        $renderNode->layoutDirty = true;
                        $renderNode->paintDirty = true;
                        $renderNode->styleDirty = false;
                        $renderNode->cachedFragment = null;
                        $renderNode->cachedConstraintSpace = null;
                        $renderNode->layoutCacheVersion++;
                    } else {
                        // 仅样式/内容变化 → 跳过布局
                        $renderNode->layoutDirty = false;
                        $renderNode->paintDirty = true;
                        $renderNode->styleDirty = true;
                    }
                } else {
                    // 无旧 VNode → 视为完全脏
                    $renderNode->layoutDirty = true;
                    $renderNode->paintDirty = true;
                    $renderNode->styleDirty = true;
                }

                if ($renderNode->type !== $vnode->type) {
                    $renderNode->type = $vnode->type;
                    $renderNode->key = $vnode->key;
                    $this->destroyRenderNodeTree($renderNode);
                } elseif ($renderNode->key !== $vnode->key) {
                    $renderNode->key = $vnode->key;
                }
            }

            // 同步 dataset（data-* attributes -> 驼峰式 Map）
            // 同时捕获 img/input 等需要的 props 供 paint 使用
            // dataset 已在 Fragment 自包含路径中由 LayoutOrchestrator 从 sourceVNode 构建

            // 同步 scroll bind 值
            $component = $componentByGroupId[$groupId] ?? $root;
            $scrollBindKey = $vnode->props[':scroll-top'] ?? '';
            if ($scrollBindKey !== '') {
                $renderNode->scrollTop = (int) $component->getBindValue($scrollBindKey);
            }
            $scrollLeftBindKey = $vnode->props[':scroll-left'] ?? '';
            if ($scrollLeftBindKey !== '') {
                $renderNode->scrollLeft = (int) $component->getBindValue($scrollLeftBindKey);
            }

            if ($renderNode->groupId === null) {
                if ($parent !== null && $parent->groupId !== null) {
                    $renderNode->groupId = $parent->groupId;
                    trigger_error('VNode groupId not set, inheriting from parent', E_USER_WARNING);
                } else {
                    $renderNode->groupId = 'app';
                }
            }

            if ($renderNode->groupId !== null) {
                $this->groupIdToRenderNodeMap[$renderNode->groupId][] = $renderNode;
            }

            $oldParent = $renderNode->parent;
            $renderNode->parent = $parent;
            if ($parent !== null) {
                $parent->children[] = $renderNode;
            }

            // Positioning ancestor 缓存失效：parent 变化时递归标记所有后代
            if ($oldParent !== $parent) {
                $invalidateStack = [$renderNode];
                while (count($invalidateStack) > 0) {
                    $n = array_pop($invalidateStack);
                    foreach ($n->children as $c) {
                        $invalidateStack[] = $c;
                    }
                }
            }

            $oldChildren = $renderNode->children;
            $renderNode->clearChildren();

            $isGrid = Config::get('debug_diag_enabled', false)
                && ($resolvedStyle['display'] ?? '') === 'grid';
            if ($isGrid) {
                error_log('[DIAG] RTM grid BEFORE: renderNode=' . spl_object_hash($renderNode)
                    . ' oldChildren=' . count($oldChildren)
                    . ' newVNodeChildren=' . count(VNode::childrenToArray($vnode->children)));
            }

            // AOT 兼容: php::Variant 在 use native_types 模式下 is_string() 可能返回 false
            if ($vnode->children !== null && !($vnode->children instanceof VNode) && !is_array($vnode->children)) {
                $renderNode->content = (string)$vnode->children;
            } else {
                $childVNodes = VNode::childrenToArray($vnode->children);
                // 如果无子 VNode，从 :bind / v-model 解析文本内容
                if (empty($childVNodes) && $vnode->props !== null) {
                    $component = $componentByGroupId[$currentGroupId] ?? $root;
                    $bindKey = $vnode->props[':bind'] ?? $vnode->props['bind'] ?? '';
                    if ($bindKey !== '') {
                        $renderNode->content = $component->getBindValue($bindKey);
                    }
                    $vModel = $vnode->props['v-model'] ?? '';
                    if ($vModel !== '') {
                        $renderNode->content = $component->getBindValue($vModel);
                    }
                }

                \Px\Core\PerfCounter::start('sub:children_walk');
                $this->patchKeyedChildren(
                    $renderNode, $childVNodes, $oldChildren,
                    $root, $componentByGroupId, $currentGroupId,
                    $vnode->props['class'] ?? '', $resolvedStyle,
                    $parentVNodeChanged
                );
                \Px\Core\PerfCounter::end('sub:children_walk');

                if ($isGrid) {
                    error_log('[DIAG] RTM grid AFTER: renderNode=' . spl_object_hash($renderNode)
                        . ' children=' . count($renderNode->children)
                        . ' oldChildren=' . count($oldChildren));
                }
            }

            // Create ::before pseudo-element RenderNode if defined
            $beforeStyle = $renderNode->pseudoStyles['before'] ?? null;
            if ($beforeStyle !== null && is_array($beforeStyle) && isset($beforeStyle['content']) && $beforeStyle['content'] !== '') {
                // 伪元素走 StylePool 池化（以宿主 RN 当前 CS 为父身份）
                $beforeCS = \Px\Css\StylePool::intern(
                    $beforeStyle,
                    $renderNode->computedStyle,
                    'span',
                    \Px\Css\StylePool::fingerprintInline($beforeStyle),
                    '::before'
                );
                $beforeRN = new RenderNode('span', $beforeCS, $beforeStyle['content']);
                $beforeRN->parent = $renderNode;
                $beforeRN->groupId = $renderNode->groupId;
                $beforeRN->layoutDirty = true;
                array_unshift($renderNode->children, $beforeRN);
            }

            // Create ::after pseudo-element RenderNode if defined
            $afterStyle = $renderNode->pseudoStyles['after'] ?? null;
            if ($afterStyle !== null && is_array($afterStyle) && isset($afterStyle['content']) && $afterStyle['content'] !== '') {
                // 伪元素走 StylePool 池化（以宿主 RN 当前 CS 为父身份）
                $afterCS = \Px\Css\StylePool::intern(
                    $afterStyle,
                    $renderNode->computedStyle,
                    'span',
                    \Px\Css\StylePool::fingerprintInline($afterStyle),
                    '::after'
                );
                $afterRN = new RenderNode('span', $afterCS, $afterStyle['content']);
                $afterRN->parent = $renderNode;
                $afterRN->groupId = $renderNode->groupId;
                $afterRN->layoutDirty = true;
                $renderNode->children[] = $afterRN;
            }

            return $renderNode;
        } finally {
            \Px\Core\PerfCounter::end('tree_convert');
        }
    }

    // ── 命中测试 ──────────────────────────

    /**
     * Dirty 传播：子节点脏了父链全标记。
     * Blink markNeedsLayout() 向上传播的等价实现——
     * updateFromVNode 是自上而下处理的，父节点不知道子节点是否变脏。
     * 此方法自底向上传播 layoutDirty，确保布局阶段不会跳过有脏子树的父节点。
     */
    public function propagateLayoutDirty(RenderNode $node): void
    {
        // 先递归子节点（DFS 自底向上）
        foreach ($node->children as $child) {
            $this->propagateLayoutDirty($child);
        }
        // 子节点中有几何变化的 → 传播 layoutDirty（父链全量布局）
        foreach ($node->children as $child) {
            if ($child->layoutDirty) {
                $node->childrenNeedLayout = true;
                $node->markLayoutDirty(true);
                return;
            }
        }
        // 子节点中只有视觉变化的 → 传播 styleDirty（仅重绘，不布局）
        foreach ($node->children as $child) {
            if ($child->styleDirty) {
                $node->childrenNeedLayout = true;
                $node->markStyleDirty(true);
                return;
            }
        }
        // 所有子节点洁净 → 清除 childrenNeedLayout
        $node->childrenNeedLayout = false;
    }

    /**
     * Vue 3 patchKeyedChildren 适配 — 增量 children 更新
     *
     * 替代 clearChildren + rebuild + O(N×M) 线性 key 扫描。
     *
     * 算法（对标 Vue 3 patchKeyedChildren + Blink LayoutTreeBuilder）：
     *   Phase 1: Head sync — 从头匹配 areVNodesEqual=true 的节点，完全跳过
     *   Phase 2: Tail sync — 从尾匹配 areVNodesEqual=true 的节点，完全跳过
     *   Phase 3: Mount — 旧节点全消耗，挂载剩余新节点
     *   Phase 4: Unmount — 新节点全消耗，卸载剩余旧节点
     *   Phase 5: Keyed diff — key→index Map O(1) 查找 + 未消耗旧节点卸载
     *
     * head/tail sync 的跳过路径（对标 Blink ChildNeedsStyleRecalc = false）：
     *   - 不构建 ComputedStyle、不递归 children、不传播脏标记
     *   - 仅同步 bind 值（scroll-top / scroll-left / :bind / v-model）
     *   - 注册 groupId、更新 sourceVNode
     *
     * parentStyleChanged 门控（对标 Blink ChildNeedsStyleRecalc）：
     *   - 父 VNode 变化时，子节点可能受 CSS 继承影响
     *   - 此时禁用 head/tail sync，所有子节点走完整 updateFromVNode
     */
    private function patchKeyedChildren(
        RenderNode $parent,
        array $newVNodes,
        array $oldChildren,
        ReactiveComponentInterface $root,
        array $componentByGroupId,
        string $currentGroupId,
        string $parentClassStr,
        array $parentStyle,
        bool $parentStyleChanged
    ): void {
        $e1 = count($oldChildren) - 1;
        $e2 = count($newVNodes) - 1;
        $i = 0;

        $component = $componentByGroupId[$currentGroupId] ?? $root;

        // Phase 1: Head sync — 从头匹配未变节点，O(1) 跳过
        while ($i <= $e1 && $i <= $e2) {
            $oldRN = $oldChildren[$i];
            $newVN = $newVNodes[$i];
            if ($oldRN->type === $newVN->type
                && $oldRN->key === $newVN->key
                && !$parentStyleChanged
                && $oldRN->sourceVNode !== null
                && $this->areVNodesEqual($newVN, $oldRN->sourceVNode)) {
                // ✅ 完全跳过 — 不构建 ComputedStyle、不递归 children
                $oldRN->parent = $parent;
                $parent->children[] = $oldRN;
                $oldRN->sourceVNode = $newVN;
                $this->syncBindValues($oldRN, $newVN, $component);
                if ($oldRN->groupId !== null) {
                    $this->groupIdToRenderNodeMap[$oldRN->groupId][] = $oldRN;
                }
                \Px\Core\PerfCounter::inc('child_skip');
                $i++;
            } else {
                break;
            }
        }

        // Phase 2: Tail sync — 从尾匹配未变节点，O(1) 跳过
        $tailSynced = [];
        while ($i <= $e1 && $i <= $e2) {
            $oldRN = $oldChildren[$e1];
            $newVN = $newVNodes[$e2];
            if ($oldRN->type === $newVN->type
                && $oldRN->key === $newVN->key
                && !$parentStyleChanged
                && $oldRN->sourceVNode !== null
                && $this->areVNodesEqual($newVN, $oldRN->sourceVNode)) {
                array_unshift($tailSynced, $oldRN);
                $oldRN->sourceVNode = $newVN;
                $this->syncBindValues($oldRN, $newVN, $component);
                \Px\Core\PerfCounter::inc('child_skip');
                $e1--;
                $e2--;
            } else {
                break;
            }
        }

        // Phase 3: Mount — 旧节点全消耗，挂载剩余新节点
        if ($i > $e1) {
            for (; $i <= $e2; $i++) {
                $this->updateFromVNode(
                    $newVNodes[$i], $parent, $root, $componentByGroupId, null,
                    $currentGroupId, $parentClassStr, $parentStyle
                );
            }
        }
        // Phase 4: Unmount — 新节点全消耗，卸载剩余旧节点
        elseif ($i > $e2) {
            for (; $i <= $e1; $i++) {
                $this->destroyRenderNodeTree($oldChildren[$i], false);
            }
        }
        // Phase 5: Keyed diff — key→index Map O(1) 查找
        else {
            $keyToOldIndex = [];
            for ($k = $i; $k <= $e1; $k++) {
                $key = $oldChildren[$k]->key;
                if ($key !== null) {
                    $keyToOldIndex[$key] = $k;
                }
            }

            $consumed = [];
            for ($k = $i; $k <= $e2; $k++) {
                $newVN = $newVNodes[$k];
                $key = $newVN->key;
                $matchedOld = null;

                if ($key !== null && isset($keyToOldIndex[$key])) {
                    $oldIdx = $keyToOldIndex[$key];
                    if (!in_array($oldIdx, $consumed, true)
                        && $oldChildren[$oldIdx]->type === $newVN->type) {
                        $matchedOld = $oldChildren[$oldIdx];
                        $consumed[] = $oldIdx;
                    }
                } elseif ($key === null) {
                    // 无 key 节点：位置匹配
                    for ($j = $i; $j <= $e1; $j++) {
                        if (!in_array($j, $consumed, true)
                            && $oldChildren[$j]->key === null
                            && $oldChildren[$j]->type === $newVN->type) {
                            $matchedOld = $oldChildren[$j];
                            $consumed[] = $j;
                            break;
                        }
                    }
                }

                $childCandidates = $matchedOld !== null ? [$matchedOld] : null;
                $this->updateFromVNode(
                    $newVN, $parent, $root, $componentByGroupId, $childCandidates,
                    $currentGroupId, $parentClassStr, $parentStyle
                );
            }

            // 卸载未消耗的旧节点
            for ($k = $i; $k <= $e1; $k++) {
                if (!in_array($k, $consumed, true)) {
                    $this->destroyRenderNodeTree($oldChildren[$k], false);
                }
            }
        }

        // 追加 tail-synced 节点（已按正序排列）
        foreach ($tailSynced as $rn) {
            $rn->parent = $parent;
            $parent->children[] = $rn;
            if ($rn->groupId !== null) {
                $this->groupIdToRenderNodeMap[$rn->groupId][] = $rn;
            }
        }
    }

    /**
     * 同步 bind 值（scroll-top / scroll-left / :bind / v-model）
     * 用于 head/tail sync 跳过路径——bind key 不变但 value 可能已变
     */
    private function syncBindValues(RenderNode $rn, VNode $vn, ReactiveComponentInterface $component): void
    {
        $scrollBindKey = $vn->props[':scroll-top'] ?? '';
        if ($scrollBindKey !== '') {
            $rn->scrollTop = (int) $component->getBindValue($scrollBindKey);
        }
        $scrollLeftBindKey = $vn->props[':scroll-left'] ?? '';
        if ($scrollLeftBindKey !== '') {
            $rn->scrollLeft = (int) $component->getBindValue($scrollLeftBindKey);
        }
        // 叶子节点 content bind
        $childVNodes = is_array($vn->children) ? VNode::childrenToArray($vn->children) : [];
        if (empty($childVNodes) && $vn->props !== null) {
            $bindKey = $vn->props[':bind'] ?? $vn->props['bind'] ?? '';
            if ($bindKey !== '') {
                $rn->content = $component->getBindValue($bindKey);
            }
            $vModel = $vn->props['v-model'] ?? '';
            if ($vModel !== '') {
                $rn->content = $component->getBindValue($vModel);
            }
        }
    }

    /**
     * 在 RenderNode 树上执行命中测试。
     * 返回命中的最上层可交互元素（有 @click 的 RenderNode）。
     */
    public function hitTest(int $x, int $y): ?RenderNode
    {
        if ($this->rootRenderNode === null) {
            return null;
        }
        return $this->hitTestRecursive($x, $y, $this->rootRenderNode);
    }

    private function hitTestRecursive(int $x, int $y, RenderNode $node): ?RenderNode
    {
        // pointer-events: none 的元素跳过命中测试
        if (($node->computedStyle?->pointerEvents?->value ?? '') === 'none') {
            return null;
        }

        // CSSOM View §7.1: 滚动容器内，将视口坐标转换为文档坐标
        // 子文档坐标 = 视口坐标 + scrollLeft/scrollTop
        $childX = $x;
        $childY = $y;
        if ($node->isScrollContainer) {
            $childX += $node->scrollLeft;
            $childY += $node->scrollTop;
        }

        // Layer-aware: 按 layer 递减遍历子节点（高 layer 优先命中）
        $layerGroups = [];
        foreach ($node->children as $i => $child) {
            $layerGroups[$child->layer][] = $i;
        }
        krsort($layerGroups);
        foreach ($layerGroups as $indices) {
            for ($j = count($indices) - 1; $j >= 0; $j--) {
                $child = $node->children[$indices[$j]];
                $found = $this->hitTestRecursive($childX, $childY, $child);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        // Transform 偏移：对 transform: translate(X,Y) 调整命中测试区域
        $hitOffX = 0;
        $hitOffY = 0;
        $xform = $node->computedStyle?->transform ?? '';
        if (is_array($xform)) {
            $hitOffX = (int)($xform['translateX'] ?? 0);
            $hitOffY = (int)($xform['translateY'] ?? 0);
        }

        // 检查自身是否可点击且在命中区域内（含 transform 偏移）
        if ($node->sourceVNode !== null
            && isset($node->sourceVNode->props['@click'])
            && $x >= $node->x + $hitOffX && $x <= $node->x + $node->w + $hitOffX
            && $y >= $node->y + $hitOffY && $y <= $node->y + $node->h + $hitOffY) {
            return $node;
        }

        return null;
    }

    // ── 滚动容器查找 ──────────────────────

    /**
     * 查找鼠标坐标下的滚动容器（最深层的子孙优先）。
     */
    public function findScrollContainerAt(int $x, int $y): ?RenderNode
    {
        if ($this->rootRenderNode === null) {
            return null;
        }
        return $this->findScrollContainerRecursive($x, $y, $this->rootRenderNode);
    }

    private function findScrollContainerRecursive(int $x, int $y, RenderNode $node): ?RenderNode
    {
        // pointer-events: none 的元素不参与滚动容器查找
        if (($node->computedStyle?->pointerEvents?->value ?? '') === 'none') {
            return null;
        }

        // CSSOM View §7.1: 滚动容器内，将视口坐标转换为文档坐标
        $childX = $x;
        $childY = $y;
        if ($node->isScrollContainer) {
            $childX += $node->scrollLeft;
            $childY += $node->scrollTop;
        }

        // Layer-aware: 按 layer 递减遍历子节点
        $layerGroups = [];
        foreach ($node->children as $i => $child) {
            $layerGroups[$child->layer][] = $i;
        }
        krsort($layerGroups);
        foreach ($layerGroups as $indices) {
            for ($j = count($indices) - 1; $j >= 0; $j--) {
                $child = $node->children[$indices[$j]];
                $found = $this->findScrollContainerRecursive($childX, $childY, $child);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        // Transform 偏移适配
        $hitOffX = 0;
        $hitOffY = 0;
        $xform = $node->computedStyle?->transform ?? '';
        if (is_array($xform)) {
            $hitOffX = (int)($xform['translateX'] ?? 0);
            $hitOffY = (int)($xform['translateY'] ?? 0);
        }

        // 检查自身是否为滚动容器且坐标命中（含 transform 偏移）
        if ($node->isScrollContainer
            && $x >= $node->x + $hitOffX && $x <= $node->x + $node->w + $hitOffX
            && $y >= $node->y + $hitOffY && $y <= $node->y + $node->h + $hitOffY) {
            return $node;
        }

        return null;
    }

    // ── 辅助方法 ──────────────────────────

    /**
     * 从 VNode.props 解析内联样式。
     * 支持 style（静态）和 :style（动态绑定）同时存在时合并，
     * :style 覆盖 style，符合 Vue 3 模板语义。
     */
    /**
     * 从旧 RenderNode 子树向新子树安全复制 scrollTop 值。
     *
     * 由于 #component 节点类型无法与旧 RenderNode 直接匹配，
     * updateFromVNode 每次为组件创建全新的子树，scrollTop 丢失。
     * 此方法在创建新子树后，遍历新旧子树并复制 scrollTop（仅限 scroll containers）。
     *
     * 遍历策略：同时 DFS 两棵树，按位置匹配子节点（与 updateFromVNode 的 key-less 匹配算法一致）。
     */
    public function copyScrollTopFromOld(RenderNode $newNode, RenderNode $oldNode): void
    {
        if ($oldNode->isScrollContainer) {
            $newNode->scrollTop = $oldNode->scrollTop;
            $newNode->scrollLeft = $oldNode->scrollLeft;
        }

        $newChildren = $newNode->children;
        $oldChildren = $oldNode->children;
        $minCount = min(count($newChildren), count($oldChildren));

        for ($i = 0; $i < $minCount; $i++) {
            $this->copyScrollTopFromOld($newChildren[$i], $oldChildren[$i]);
        }
    }


}
