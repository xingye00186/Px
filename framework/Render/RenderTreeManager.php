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

    /** @var array<string, array> :style 字符串 → 解析结果缓存（方案1：同一字符串不重复 regex） */
    private static array $styleParseCache = [];

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
        // 新增：:bind / bind / v-model key 一致性检查（bind key 变 → 实际值可能变）
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
                \Px\Core\PerfCounter::start('sub:style_fallback');
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

            // 合并 HTML align 属性到 textAlign（CSS text-align 优先）
            if (($vnode->props['align'] ?? '') !== '' && empty($resolvedStyle['textAlign'])) {
                $resolvedStyle['textAlign'] = $vnode->props['align'];
                $computedStyle = new ComputedStyle($resolvedStyle);
            }

            // 合并 :style 动态绑定（StyleRecalcPass 只解析静态 style，丢弃 :style）
            // 支持字符串（正则解析）和数组（零正则合并，Vue 3 对象语法风格）
            $dynamicStyle = $vnode->props[':style'] ?? '';
            if ($dynamicStyle !== '') {
                \Px\Core\PerfCounter::start('sub:style_dynamic');
                if (is_array($dynamicStyle)) {
                    // 数组模式：直接数组合并，零 regex（方案2）
                    $resolvedStyle = $computedStyle->toExportArray();
                    foreach ($dynamicStyle as $k => $v) {
                        $resolvedStyle[$k] = $v;
                    }
                    $computedStyle = new ComputedStyle($resolvedStyle);
                    $resolvedStyle = $computedStyle->toExportArray();
                } else {
                    // 字符串模式：正则解析 + 缓存（同一字符串不重复 regex）
                    $styleStr = (string)$dynamicStyle;
                    if (!isset(self::$styleParseCache[$styleStr])) {
                        self::$styleParseCache[$styleStr] = \Px\Css\StyleResolver::parseInlineStyle($styleStr);
                    }
                    $dynamicParsed = self::$styleParseCache[$styleStr];
                    if (!empty($dynamicParsed)) {
                        $resolvedStyle = $computedStyle->toExportArray();
                        foreach ($dynamicParsed as $k => $v) {
                            $resolvedStyle[$k] = $v;
                        }
                        $computedStyle = new ComputedStyle($resolvedStyle);
                        $resolvedStyle = $computedStyle->toExportArray();
                    }
                }
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
                $consumed = [];

                \Px\Core\PerfCounter::start('sub:children_walk');
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
                \Px\Core\PerfCounter::end('sub:children_walk');

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
