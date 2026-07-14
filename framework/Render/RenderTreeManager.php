<?php

namespace Px\Render;

use native_types;

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
    private function findMatchingRenderNode(VNode $vnode, array $candidates, int $index): ?RenderNode
    {
        $key = $vnode->key;

        if ($key !== null) {
            // key 匹配
            foreach ($candidates as $candidate) {
                if ($candidate->key === $key && $candidate->type === $vnode->type) {
                    return $candidate;
                }
            }
            return null;
        }

        // 位置匹配（静态节点）
        if (isset($candidates[$index])) {
            $candidate = $candidates[$index];
            if ($candidate->key === null && $candidate->type === $vnode->type) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 比较两个 VNode 的布局相关属性是否相等。
     *
     * 用于叶子节点（无 VNode 子节点）的洁净路径判断：
     *   - type, key
     *   - props['style'], props['class']
     *   - props[':scroll-top'], props[':scroll-left']
     *
     * 注意：非叶子节点即使在 type+key+props 一致的情况下也必须走脏路径，
     * 因为父节点的 auto-stack、contentHeight 等依赖子节点全量重算。
     *
     * @return bool true=布局相关属性完全一致，可走洁净路径
     */
    private function areVNodesEqual(VNode $a, VNode $b): bool
    {
        if ($a->type !== $b->type) return false;
        if ($a->key !== $b->key) return false;
        if (($a->props['style'] ?? '') !== ($b->props['style'] ?? '')) return false;
        if (($a->props[':style'] ?? '') !== ($b->props[':style'] ?? '')) return false;
        if (($a->props['class'] ?? '') !== ($b->props['class'] ?? '')) return false;
        if (($a->props[':scroll-top'] ?? '') !== ($b->props[':scroll-top'] ?? '')) return false;
        if (($a->props[':scroll-left'] ?? '') !== ($b->props[':scroll-left'] ?? '')) return false;
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

        // 4. 递归销毁子节点
        foreach ($rn->children as $child) {
            $this->destroyRenderNodeTree($child, false);
        }
        $rn->children = [];

        // 5. 断开引用（帮助 GC）
        $rn->sourceVNode = null;
        $rn->computedStyle = null;
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
            // 组件占位节点：递归处理子组件树，$candidates 透传
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
                
                // ⚠️ 不传递跨帧 candidates：#component VNode 类型无法与旧 RenderNode 直接匹配。
                // 使用 rootRenderNode 作为 candidate 会错误复用不再匹配的子树，导致显示异常。
                // scrollTop 保留改为在创建新子树后通过 copyScrollTopFromOld() 安全复制。
                $oldRootRN = $instance->getRootRenderNode();

                $childRN = $this->updateFromVNode(
                    $instance->getVNodeTree(),
                    $parent,
                    $root,
                    $componentByGroupId,
                    $candidates,
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
                $placeholderStyle = $vnode->props['style'] ?? '';
                if ($placeholderStyle !== '') {
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
                        $parsedDecls = StyleResolver::parseInlineStyle($placeholderStyle);
                        $targetRN->computedStyle = new ComputedStyle($parsedDecls, $targetRN->computedStyle?->toExportArray() ?? []);
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
                // 降级：StyleRecalcPass 未运行时内联解析
                $computedStyle = StyleResolver::resolve(
                    inlineStyle: $vnode->props['style'] ?? '',
                    className: $vnode->props['class'] ?? '',
                    parentDeclarations: $parentStyle,
                    elementType: $vnode->type,
                    parentClassStr: $parentClassStr,
                    precedingSiblingClasses: [],
                    parentStyleDeclarations: $parentStyle,
                    pseudoStyles: $pseudoStyles
                );
            }
            $resolvedStyle = $computedStyle->toExportArray();
            $renderNode = null;

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
            } else {
                $oldVNode = $renderNode->sourceVNode;
                $renderNode->computedStyle = $computedStyle;
                $renderNode->lastPaintFrame = 0;
                $renderNode->sourceVNode = $vnode;
                $renderNode->groupId = $groupId;
                $renderNode->pseudoStyles = $pseudoStyles;

                $vnodeChildren = is_array($vnode->children)
                    ? VNode::childrenToArray($vnode->children)
                    : [];
                $isLeaf = count($vnodeChildren) === 0;
                $hasExplicitTop = array_key_exists('top', $resolvedStyle);
                if ($isLeaf && $hasExplicitTop && $oldVNode !== null && $this->areVNodesEqual($vnode, $oldVNode)) {
                    $renderNode->layoutDirty = false;
                } else {
                    $renderNode->layoutDirty = true;
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
            $renderNode->dataset = [];
            if ($vnode->props !== null) {
                foreach ($vnode->props as $k => $v) {
                    if (str_starts_with((string)$k, 'data-')) {
                        $dsKey = substr((string)$k, 5);
                        $camelKey = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $dsKey))));
                        $renderNode->dataset[$camelKey] = (string)$v;
                    }
                }
            }

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
                $consumed = [];

                foreach ($childVNodes as $i => $childVNode) {
                    $matchedOld = null;
                    $matchedIdx = null;
                    $key = $childVNode->key;

                    if ($key !== null) {
                        foreach ($oldChildren as $pos => $oldRN) {
                            if (!in_array($pos, $consumed, true)
                                && $oldRN->key === $key
                                && $oldRN->type === $childVNode->type) {
                                $matchedOld = $oldRN;
                                $matchedIdx = $pos;
                                break;
                            }
                        }
                    } elseif (isset($oldChildren[$i]) && !in_array($i, $consumed, true)) {
                        $oldRN = $oldChildren[$i];
                        if ($oldRN->key === null && $oldRN->type === $childVNode->type) {
                            $matchedOld = $oldRN;
                            $matchedIdx = $i;
                        }
                    }

                    if ($matchedIdx !== null) {
                        $consumed[] = $matchedIdx;
                    }

                    $childCandidates = $matchedOld !== null ? [$matchedOld] : null;

                    $childRN = $this->updateFromVNode(
                        $childVNode, $renderNode, $root, $componentByGroupId, $childCandidates,
                        $currentGroupId,
                        $vnode->props['class'] ?? '',
                        $resolvedStyle
                    );
                }

                foreach ($oldChildren as $pos => $oldRN) {
                    if (!in_array($pos, $consumed, true)) {
                        $this->destroyRenderNodeTree($oldRN);
                    }
                }

                if ($isGrid) {
                    error_log('[DIAG] RTM grid AFTER: renderNode=' . spl_object_hash($renderNode)
                        . ' children=' . count($renderNode->children)
                        . ' consumed=' . count($consumed)
                        . ' destroyed=' . (count($oldChildren) - count($consumed)));
                }
            }

            // Create ::before pseudo-element RenderNode if defined
            $beforeStyle = $renderNode->pseudoStyles['before'] ?? null;
            if ($beforeStyle !== null && is_array($beforeStyle) && isset($beforeStyle['content']) && $beforeStyle['content'] !== '') {
                $beforeCS = new ComputedStyle($beforeStyle);
                $beforeRN = new RenderNode('span', $beforeCS, $beforeStyle['content']);
                $beforeRN->parent = $renderNode;
                $beforeRN->groupId = $renderNode->groupId;
                $beforeRN->layoutDirty = true;
                array_unshift($renderNode->children, $beforeRN);
            }

            // Create ::after pseudo-element RenderNode if defined
            $afterStyle = $renderNode->pseudoStyles['after'] ?? null;
            if ($afterStyle !== null && is_array($afterStyle) && isset($afterStyle['content']) && $afterStyle['content'] !== '') {
                $afterCS = new ComputedStyle($afterStyle);
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
    private function parseVNodeStyle(VNode $vnode): array
    {
        $result = [];

        // 1. 解析静态 style
        $staticStyle = $vnode->props['style'] ?? '';
        if ($staticStyle !== '') {
            $result = StyleResolver::parseInlineStyle($staticStyle);
        }

        // 2. 解析动态 :style 绑定，覆盖静态 style
        $dynamicStyle = $vnode->props[':style'] ?? '';
        if ($dynamicStyle !== '') {
            $dynamicParsed = StyleResolver::parseInlineStyle($dynamicStyle);
            foreach ($dynamicParsed as $k => $v) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * 解析 VNode 的完整样式（CSS class + inline style 合并 + 复杂选择器匹配）。
     *
     * 解析顺序（后覆盖前）：
     *   1. CSS class 样式（从 ThemeProvider 全局注册表查找）
     *   2. 复杂选择器匹配（后代/子代/兄弟选择器）
     *   3. 内联 style 属性（最高优先级）
     *
     * AOT 安全：仅使用静态方法调用和数组操作。
     *
     * @param VNode $vnode 当前 VNode
     * @param string $parentClassStr 父 VNode 的 class 字符串（用于复杂选择器匹配）
     * @param array $precedingSiblingClasses 前面兄弟节点的 class 字符串数组
     * @return array 合并后的样式
     */
    private function resolveNodeStyle(VNode $vnode, string $parentClassStr = '', array $precedingSiblingClasses = [], array $parentStyle = []): array
    {
        // 1. 解析内联 style
        $inlineStyle = $this->parseVNodeStyle($vnode);

        // 2. 获取所有已注册的 class styles（含通用选择器）
        $allRegistered = ThemeProvider::getAllClassStyles();

        // 3. 收集通用选择器（*、html、body）基础样式
        $universalBase = [];
        $htmlBase = [];
        $bodyBase = [];
        foreach ($allRegistered as $compName => $componentStyles) {
            // * 应用到所有元素
            if (isset($componentStyles['*'])) {
                foreach ($componentStyles['*'] as $k => $v) {
                    if ($k === 'bg' && $v === -1) continue;
                    $universalBase[$k] = $v;
                }
            }
            // html/body 仅应用于对应类型的节点
            if (isset($componentStyles['html'])) {
                foreach ($componentStyles['html'] as $k => $v) {
                    if ($k === 'bg' && $v === -1) continue;
                    $htmlBase[$k] = $v;
                }
            }
            if (isset($componentStyles['body'])) {
                foreach ($componentStyles['body'] as $k => $v) {
                    if ($k === 'bg' && $v === -1) continue;
                    $bodyBase[$k] = $v;
                }
            }
        }

        // 4. 获取 CSS class 名并拆分
        $classStr = $vnode->props['class'] ?? '';
        if ($classStr === '') {
            // 无 class 时仍应用通用选择器基础样式
            return array_merge($universalBase, $inlineStyle);
        }
        $classNames = explode(' ', $classStr);

        // 5. 从 ThemeProvider 搜索所有已注册的 class styles
        //    CSS class 是全局的，需要跨组件搜索
        $merged = $universalBase;  // 全局选择器基础样式（最低优先级）
        $hoverMerged = [];
        $focusMerged = [];
        $activeMerged = [];

        foreach ($classNames as $className) {
            if ($className === '') {
                continue;
            }
            foreach ($allRegistered as $compName => $componentStyles) {
                if (isset($componentStyles[$className])) {
                    foreach ($componentStyles[$className] as $k => $v) {
                        $merged[$k] = $v;
                    }
                }
                // Resolve :hover variant
                $hoverKey = $className . '__hover';
                if (isset($componentStyles[$hoverKey])) {
                    foreach ($componentStyles[$hoverKey] as $k => $v) {
                        $hoverMerged[$k] = $v;
                    }
                }
                // Resolve :focus variant
                $focusKey = $className . '__focus';
                if (isset($componentStyles[$focusKey])) {
                    foreach ($componentStyles[$focusKey] as $k => $v) {
                        $focusMerged[$k] = $v;
                    }
                }
                // Resolve :active variant
                $activeKey = $className . '__active';
                if (isset($componentStyles[$activeKey])) {
                    foreach ($componentStyles[$activeKey] as $k => $v) {
                        $activeMerged[$k] = $v;
                    }
                }

                // Resolve complex selectors (descendant, child, sibling)
                // Match rules where secondClass matches current element
                // Rules are stored as '__complex__N' keys
                foreach ($componentStyles as $styleKey => $styleValue) {
                    if (str_starts_with((string)$styleKey, '__complex__') && is_array($styleValue)) {
                        $rule = $styleValue;
                        if ($rule['secondClass'] === $className) {
                            $matches = CssMappings::matchComplexSelector(
                                $rule['combinator'],
                                $rule['firstClass'],
                                $rule['secondClass'],
                                $parentClassStr,
                                $classStr,
                                $precedingSiblingClasses
                            );
                            if ($matches) {
                                foreach ($rule['props'] as $k => $v) {
                                    $merged[$k] = $v;
                                }
                            }
                        }
                    }
                }

                // Resolve ::before / ::after pseudo-elements
                $beforeKey = $className . '__before';
                if (isset($componentStyles[$beforeKey])) {
                    $pseudoElProps = $componentStyles[$beforeKey];
                    if (!isset($merged['__beforeStyle'])) {
                        $merged['__beforeStyle'] = $pseudoElProps;
                    } else {
                        // Merge: later classes override earlier ones
                        foreach ($pseudoElProps as $k => $v) {
                            $merged['__beforeStyle'][$k] = $v;
                        }
                    }
                }
                $afterKey = $className . '__after';
                if (isset($componentStyles[$afterKey])) {
                    $pseudoElProps = $componentStyles[$afterKey];
                    if (!isset($merged['__afterStyle'])) {
                        $merged['__afterStyle'] = $pseudoElProps;
                    } else {
                        foreach ($pseudoElProps as $k => $v) {
                            $merged['__afterStyle'][$k] = $v;
                        }
                    }
                }
            }
        }

        // 4. 内联样式覆盖 class 样式
        foreach ($inlineStyle as $k => $v) {
            $merged[$k] = $v;
        }

        // 5. Store pseudo-class styles for runtime application
        if (count($hoverMerged) > 0) {
            $merged['__hoverStyle'] = $hoverMerged;
        }
        if (count($focusMerged) > 0) {
            $merged['__focusStyle'] = $focusMerged;
        }
        if (count($activeMerged) > 0) {
            $merged['__activeStyle'] = $activeMerged;
        }

        // CSS 继承传播：父节点已计算的继承属性 -> 当前节点未显式设置时继承
        // CSS 2.2 §6.1.1: color/font/line-height/text-align/visibility 等默认继承
        // parentStyle 使用引擎key名（如 fg 而非 color），直接匹配
        if (!empty($parentStyle)) {
            $inheritedKeys = ['fg','fontFamily','fontSize','fontWeight','bold','fontStyle','lineHeight','textAlign','textIndent','whiteSpace','wordBreak','visibility','opacity','cursor','direction','textShadow','letterSpacing','wordSpacing','verticalAlign','fontVariant','fontStretch','backgroundAttachment','outlineOffset','borderCollapse','borderSpacing','tableLayout','captionSide'];
            foreach ($inheritedKeys as $key) {
                if (!isset($merged[$key]) && isset($parentStyle[$key])) {
                    $merged[$key] = $parentStyle[$key];
                }
            }
        }
        return $merged;
    }

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
