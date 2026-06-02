<?php

namespace Px\Rendering;

use Px\ReactiveComponent;
use Px\Styling\Provider\ThemeProvider;

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
    private ?RenderNode $rootRenderNode = null;

    /** @var RenderNode[] 顶层 #root 的所有直接子节点（用于跨帧 candidates 传递） */
    private array $rootRenderNodes = [];

    /** @var array<string, VNode> spl_object_hash(RenderNode) => VNode */
    private array $renderNodeToVNodeMap = [];

    /** @var array<string, RenderNode[]> groupId => RenderNode[] */
    private array $groupIdToRenderNodeMap = [];

    /** @var array<string, RenderNode> spl_object_hash(VNode) => RenderNode（快速查找，每帧重建） */
    private array $vnodeToRenderNodeMap = [];

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
        $this->renderNodeToVNodeMap = [];
        $this->groupIdToRenderNodeMap = [];
        $this->vnodeToRenderNodeMap = [];
    }

    // ── 查找方法 ──────────────────────────

    /**
     * 根据 VNode 查找对应的 RenderNode。
     * 优先使用 vnodeToRenderNodeMap 快速查找，失败时回退到树遍历。
     */
    public function findRenderNodeBySourceVNode(VNode $vnode): ?RenderNode
    {
        $hash = spl_object_hash($vnode);
        if (isset($this->vnodeToRenderNodeMap[$hash])) {
            return $this->vnodeToRenderNodeMap[$hash];
        }
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
        if (($a->props['class'] ?? '') !== ($b->props['class'] ?? '')) return false;
        if (($a->props[':scroll-top'] ?? '') !== ($b->props[':scroll-top'] ?? '')) return false;
        if (($a->props[':scroll-left'] ?? '') !== ($b->props[':scroll-left'] ?? '')) return false;
        return true;
    }

    /**
     * 递归销毁 RenderNode 子树。
     *
     * 清理：
     *   - 从 renderNodeToVNodeMap 移除当前节点及其子孙的映射
     *   - 递归销毁子节点
     *   - 清空 children 数组
     */
    private function destroyRenderNodeTree(RenderNode $rn): void
    {
        // 从反向映射中移除
        unset($this->renderNodeToVNodeMap[spl_object_hash($rn)]);

        // 递归销毁子节点
        foreach ($rn->children as $child) {
            $this->destroyRenderNodeTree($child);
        }

        $rn->children = [];
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
     * @return RenderNode|null 转换后的 RenderNode
     */
    public function updateFromVNode(
        VNode $vnode,
        ?RenderNode $parent,
        ReactiveComponent $root,
        array $componentByGroupId,
        ?array $candidates = null
    ): ?RenderNode {
        // 组件占位节点：递归处理子组件树，$candidates 透传
        // 同时将父组件的 layoutOffset 应用到子组件第一个可渲染元素上
        if ($vnode->isComponent()) {
            $instance = $vnode->componentInstance;
            if ($instance === null) {
                return null;
            }

            // 记录展开前的子节点数，用于定位第一个新增的子 RenderNode
            $beforeCount = $parent !== null ? count($parent->children) : 0;

            $childRN = $this->updateFromVNode(
                $instance->getVNodeTree(),
                $parent,
                $root,
                $componentByGroupId,
                $candidates
            );

            // 应用 layoutOffset 到子组件第一个可渲染 RenderNode
            // 父组件对子组件的定位声明具有最高优先级（Vue 3 模板语义），
            // 直接覆盖子组件自身的 left/top
            if ($childRN !== null && $vnode->layoutOffset !== null) {
                $firstChild = $childRN;
                // 对于多根组件，找到第一个新增的 RenderNode
                if ($parent !== null && $beforeCount < count($parent->children)) {
                    $newChildren = array_slice($parent->children, $beforeCount);
                    if (count($newChildren) > 0) {
                        $firstChild = $newChildren[0];
                    }
                }
                $offset = $vnode->layoutOffset;
                if (isset($offset['left'])) {
                    $firstChild->style['left'] = $offset['left'];
                }
                if (isset($offset['top'])) {
                    $firstChild->style['top'] = $offset['top'];
                }
                $firstChild->layoutDirty = true;
            }

            return $childRN;
        }

        // #root 节点不产生渲染元素，$candidates 在此层含义 = 旧子节点列表
        if ($vnode->type === '#root') {
            $result = null;
            $children = $this->vnodeChildrenToArray($vnode->children);

            // 顶层 #root：重置每帧映射（vnodeToRenderNodeMap 和 groupIdToRenderNodeMap 每帧重建）
            if ($parent === null) {
                $this->rootRenderNodes = [];
                $this->vnodeToRenderNodeMap = [];
                $this->groupIdToRenderNodeMap = [];
            }

            // 跟踪 candidates 中被匹配的旧子节点，用于清理
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
                    $child, $parent, $root, $componentByGroupId, $childCandidates
                );
                if ($childRN !== null) {
                    if ($parent === null) {
                        $this->rootRenderNode = $childRN;
                        $this->rootRenderNodes[] = $childRN;
                    }
                    $result = $childRN;
                }
            }

            // 清理未被复用的旧 #root 子节点
            if ($candidates !== null) {
                foreach ($candidates as $oldRN) {
                    if (!in_array($oldRN, $consumedCandidates, true)) {
                        $this->destroyRenderNodeTree($oldRN);
                    }
                }
            }

            return $result;
        }

        // 普通元素节点
        $resolvedStyle = $this->resolveNodeStyle($vnode);
        $renderNode = null;

        // 从 candidates 匹配旧 RenderNode
        if ($candidates !== null) {
            $matched = $this->findMatchingRenderNode($vnode, $candidates, 0);
            if ($matched !== null) {
                $renderNode = $matched;
            }
        }

        if ($renderNode === null) {
            // ── 新建 RenderNode ──
            $renderNode = new RenderNode($vnode->type, $resolvedStyle, null, $vnode->key);
            $renderNode->sourceVNode = $vnode;
            $renderNode->groupId = $vnode->groupId;
            $renderNode->layoutDirty = true;
            $this->renderNodeToVNodeMap[spl_object_hash($renderNode)] = $vnode;
        } else {
            // ── 复用 RenderNode ──
            $renderNode->style = $resolvedStyle;
            $renderNode->lastPaintFrame = 0;
            $renderNode->sourceVNode = $vnode;
            $renderNode->groupId = $vnode->groupId;

            // 叶子节点洁净路径：
            //   叶子节点（无 VNode 子节点）+ 显式 top（禁用 auto-stack）+ 布局属性一致
            //   → 标记 layoutDirty=false，LayoutResolver 跳过位置重算
            //   否则 → layoutDirty=true，全量重算位置
            $oldVNode = $this->renderNodeToVNodeMap[spl_object_hash($renderNode)] ?? null;
            $vnodeChildren = is_array($vnode->children)
                ? $this->vnodeChildrenToArray($vnode->children)
                : [];
            $isLeaf = count($vnodeChildren) === 0;
            $hasExplicitTop = array_key_exists('top', $resolvedStyle);
            if ($isLeaf && $hasExplicitTop && $oldVNode !== null && $this->areVNodesEqual($vnode, $oldVNode)) {
                $renderNode->layoutDirty = false;
            } else {
                $renderNode->layoutDirty = true;
            }

            // 类型变化时重建子节点
            if ($renderNode->type !== $vnode->type) {
                $renderNode->type = $vnode->type;
                $renderNode->key = $vnode->key;
                $this->destroyRenderNodeTree($renderNode);
            } elseif ($renderNode->key !== $vnode->key) {
                $renderNode->key = $vnode->key;
            }
            // 始终更新 renderNodeToVNodeMap，下一帧需要从映射获取 oldVNode 进行洁净路径判断
            $this->renderNodeToVNodeMap[spl_object_hash($renderNode)] = $vnode;
        }

        // ── 同步 scroll bind 值到 RenderNode ──
        $component = $componentByGroupId[$vnode->groupId] ?? $root;
        $scrollBindKey = $vnode->props[':scroll-top'] ?? '';
        if ($scrollBindKey !== '') {
            $renderNode->scrollTop = (int) $component->getBindValue($scrollBindKey);
        }
        $scrollLeftBindKey = $vnode->props[':scroll-left'] ?? '';
        if ($scrollLeftBindKey !== '') {
            $renderNode->scrollLeft = (int) $component->getBindValue($scrollLeftBindKey);
        }

        // ── groupId 防御性检查 ──
        if ($renderNode->groupId === null) {
            if ($parent !== null && $parent->groupId !== null) {
                $renderNode->groupId = $parent->groupId;
                trigger_error('VNode groupId not set, inheriting from parent', E_USER_WARNING);
            } else {
                $renderNode->groupId = 'app';
            }
        }

        // 注册到 groupId 映射
        if ($renderNode->groupId !== null) {
            $this->groupIdToRenderNodeMap[$renderNode->groupId][] = $renderNode;
        }

        $renderNode->parent = $parent;
        if ($parent !== null) {
            $parent->children[] = $renderNode;
        }

        // ── 处理子节点（key-aware + consumed 跟踪防重复匹配） ──
        $oldChildren = $renderNode->children;
        $renderNode->clearChildren();

        if (is_string($vnode->children)) {
            $renderNode->content = $vnode->children;
        } else {
            $childVNodes = $this->vnodeChildrenToArray($vnode->children);
            $consumed = [];

            foreach ($childVNodes as $i => $childVNode) {
                $matchedOld = null;
                $matchedIdx = null;
                $key = $childVNode->key;

                if ($key !== null) {
                    // key 匹配：遍历 oldChildren 找 key + type 匹配
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
                    // 位置匹配（静态节点）
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
                    $childVNode, $renderNode, $root, $componentByGroupId, $childCandidates
                );
                // 子节点已在其自身的 updateFromVNode 中通过
                // $parent->children[] = $renderNode 添加到父级，
                // 此处不需要重复添加
            }

            // 清理未被复用的旧子节点树
            foreach ($oldChildren as $pos => $oldRN) {
                if (!in_array($pos, $consumed, true)) {
                    $this->destroyRenderNodeTree($oldRN);
                }
            }
        }

        // 注册到 VNode → RenderNode 快速查找映射（每帧重建，在 #root handler 中清理）
        $this->vnodeToRenderNodeMap[spl_object_hash($vnode)] = $renderNode;

        return $renderNode;
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
        // 反向遍历子节点（后渲染 = 视觉上层 = 优先命中）
        for ($i = count($node->children) - 1; $i >= 0; $i--) {
            $child = $node->children[$i];
            $found = $this->hitTestRecursive($x, $y, $child);
            if ($found !== null) {
                return $found;
            }
        }

        // 检查自身是否可点击且在命中区域内
        if ($node->sourceVNode !== null
            && isset($node->sourceVNode->props['@click'])
            && $x >= $node->x && $x <= $node->x + $node->w
            && $y >= $node->y && $y <= $node->y + $node->h) {
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
        // 反向遍历子节点
        for ($i = count($node->children) - 1; $i >= 0; $i--) {
            $child = $node->children[$i];
            $found = $this->findScrollContainerRecursive($x, $y, $child);
            if ($found !== null) {
                return $found;
            }
        }

        // 检查自身是否为滚动容器且坐标命中
        if ($node->isScrollContainer
            && $x >= $node->x && $x <= $node->x + $node->w
            && $y >= $node->y && $y <= $node->y + $node->h) {
            return $node;
        }

        return null;
    }

    // ── 辅助方法 ──────────────────────────

    /**
     * 从 VNode.props['style'] 解析内联样式。
     * VNode 不再持有 computedStyle，改为在转换时实时解析。
     */
    private function parseVNodeStyle(VNode $vnode): array
    {
        $styleStr = $vnode->props['style'] ?? '';
        if ($styleStr === '') {
            return [];
        }
        return CssMappings::parseInlineStyle($styleStr);
    }

    /**
     * 解析 VNode 的完整样式（CSS class + inline style 合并）。
     *
     * 解析顺序（后覆盖前）：
     *   1. CSS class 样式（从 ThemeProvider 全局注册表查找）
     *   2. 内联 style 属性（最高优先级）
     *
     * AOT 安全：仅使用静态方法调用和数组操作。
     */
    private function resolveNodeStyle(VNode $vnode): array
    {
        // 1. 解析内联 style
        $inlineStyle = $this->parseVNodeStyle($vnode);

        // 2. 获取 CSS class 名并拆分
        $classStr = $vnode->props['class'] ?? '';
        if ($classStr === '') {
            return $inlineStyle;
        }
        $classNames = explode(' ', $classStr);

        // 3. 从 ThemeProvider 搜索所有已注册的 class styles
        //    CSS class 是全局的，需要跨组件搜索
        $allRegistered = ThemeProvider::getAllClassStyles();
        $merged = [];

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
            }
        }

        // 4. 内联样式覆盖 class 样式
        foreach ($inlineStyle as $k => $v) {
            $merged[$k] = $v;
        }

        return $merged;
    }

    /**
     * 将 VNode children 统一为 VNode 数组。
     */
    private function vnodeChildrenToArray(mixed $children): array
    {
        if ($children === null) return [];
        if ($children instanceof VNode) return [$children];
        if (is_array($children)) {
            return array_values(array_filter($children, fn($c) => $c instanceof VNode));
        }
        return [];
    }
}
