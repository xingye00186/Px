<?php

namespace Px\Rendering;

use Px\ReactiveComponent;
use Px\Styling\Provider\ThemeProvider;

/**
 * RenderTreeManager — VNode → RenderNode 转换管理
 *
 * 职责：
 *   1. 将 VNode 树转换为 RenderNode 树
 *   2. 通过 spl_object_hash 映射实现 RenderNode 复用
 *   3. 维护 groupId → RenderNode[] 映射
 *   4. 在 RenderNode 树上执行命中测试和滚动容器查找
 *
 * 核心原则：
 *   - 不预先计算任何坐标，所有偏移统一由 LayoutResolver 在布局阶段处理
 *   - 使用 spl_object_hash 作为映射键，无需修改编译器
 *   - 对象哈希在 VNode 生命周期内稳定（组件实例不变时）
 *   - 正常渲染循环中不清空映射（仅全量重置时调用 clear()）
 */
class RenderTreeManager
{
    private ?RenderNode $rootRenderNode = null;

    /** @var array<string, RenderNode> spl_object_hash(VNode) => RenderNode */
    private array $vnodeToRenderNodeMap = [];

    /** @var array<string, VNode> spl_object_hash(RenderNode) => VNode */
    private array $renderNodeToVNodeMap = [];

    /** @var array<string, RenderNode[]> groupId => RenderNode[] */
    private array $groupIdToRenderNodeMap = [];

    // ── 基础方法 ──────────────────────────

    public function getRootRenderNode(): ?RenderNode
    {
        return $this->rootRenderNode;
    }

    /**
     * 清空所有映射（仅在全量重置时调用）。
     * 正常渲染循环中不要调用 clear()。
     */
    public function clear(): void
    {
        $this->rootRenderNode = null;
        $this->vnodeToRenderNodeMap = [];
        $this->renderNodeToVNodeMap = [];
        $this->groupIdToRenderNodeMap = [];
    }

    // ── 查找方法 ──────────────────────────

    /**
     * 根据 VNode 查找对应的 RenderNode。
     */
    public function findRenderNodeBySourceVNode(VNode $vnode): ?RenderNode
    {
        $hash = spl_object_hash($vnode);
        return $this->vnodeToRenderNodeMap[$hash] ?? null;
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
     * @return RenderNode|null 转换后的 RenderNode
     */
    public function updateFromVNode(
        VNode $vnode,
        ?RenderNode $parent,
        ReactiveComponent $root,
        array $componentByGroupId
    ): ?RenderNode {
        // 组件占位节点：递归处理子组件树
        // #component 的定位已在 expandComponentNode 中写入子组件根元素 style
        if ($vnode->isComponent()) {
            $instance = $vnode->componentInstance;
            if ($instance === null) {
                return null;
            }
            return $this->updateFromVNode(
                $instance->getVNodeTree(),
                $parent,
                $root,
                $componentByGroupId
            );
        }

        // #root 节点不产生渲染元素，递归处理 children
        // 重要：$parent 必须透传给子节点，否则组件展开后的子树会脱离 RenderNode 树
        if ($vnode->type === '#root') {
            $result = null;
            $children = $this->vnodeChildrenToArray($vnode->children);
            foreach ($children as $child) {
                $childRenderNode = $this->updateFromVNode(
                    $child, $parent, $root, $componentByGroupId
                );
                if ($childRenderNode !== null) {
                    if ($parent === null) {
                        $this->rootRenderNode = $childRenderNode;
                    }
                    $result = $childRenderNode;
                }
            }
            return $result;
        }

        // 普通元素节点
        $hash = spl_object_hash($vnode);
        $renderNode = null;

        // 解析样式（CSS class + inline），不进行任何坐标计算
        $resolvedStyle = $this->resolveNodeStyle($vnode);

        if (isset($this->vnodeToRenderNodeMap[$hash])) {
            // ── 复用现有 RenderNode ──
            $renderNode = $this->vnodeToRenderNodeMap[$hash];

            // 类型变化时清除子节点（如 div→span 语义变化）
            // key 变化或不变时不清除（子节点由每帧全量重建处理）
            if ($renderNode->type !== $vnode->type) {
                $renderNode->type = $vnode->type;
                $renderNode->key = $vnode->key;
                $renderNode->clearChildren();
            } elseif ($renderNode->key !== $vnode->key) {
                $renderNode->key = $vnode->key;
            }

            // 更新样式、dirty 标记、sourceVNode 引用
            $renderNode->style = $resolvedStyle;
            $renderNode->layoutDirty = true;
            $renderNode->lastPaintFrame = 0;
            $renderNode->sourceVNode = $vnode;
            $renderNode->groupId = $vnode->groupId;
        } else {
            // ── 新建 RenderNode ──
            $renderNode = new RenderNode(
                $vnode->type,
                $resolvedStyle,
                null,
                $vnode->key
            );
            $renderNode->sourceVNode = $vnode;
            $renderNode->groupId = $vnode->groupId;
            $this->vnodeToRenderNodeMap[$hash] = $renderNode;
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

        // ── 处理子节点（key-aware + 每帧全量重建防累积） ──
        if (is_string($vnode->children)) {
            $renderNode->content = $vnode->children;
            $renderNode->clearChildren();
        } else {
            $childVNodes = $this->vnodeChildrenToArray($vnode->children);
            $oldChildren = $renderNode->children;
            $renderNode->children = [];

            // 构建 key → 旧 RenderNode 映射，清理旧的 spl_object_hash 映射条目
            $oldByKey = [];
            foreach ($oldChildren as $oldRN) {
                if ($oldRN->key !== null && $oldRN->sourceVNode !== null) {
                    $oldByKey[$oldRN->key] = $oldRN;
                    unset($this->vnodeToRenderNodeMap[spl_object_hash($oldRN->sourceVNode)]);
                }
            }

            foreach ($childVNodes as $childVNode) {
                $key = $childVNode->key;
                $hash = spl_object_hash($childVNode);

                // Key-based reuse: 将新 VNode hash 指向旧 RenderNode
                if ($key !== null && isset($oldByKey[$key]) && !isset($this->vnodeToRenderNodeMap[$hash])) {
                    $oldRN = $oldByKey[$key];
                    $this->vnodeToRenderNodeMap[$hash] = $oldRN;
                    $this->renderNodeToVNodeMap[spl_object_hash($oldRN)] = $childVNode;
                }

                $this->updateFromVNode(
                    $childVNode, $renderNode, $root, $componentByGroupId
                );
            }
        }

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
