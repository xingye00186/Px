<?php
declare(strict_types=1);

namespace Px\Layout;

use Px\Render\RenderNode;

/**
 * 子项按需布局提供者（对标 Blink LayoutChild 机制）。
 *
 * Blink LayoutNG 中，算法通过 LayoutChild(child, constraint) 按需布局子项，
 * 而非预先布局所有子项再传给算法。这避免了：
 * 1. Phase B 全量预布局（浪费：算法可能只需要部分子项的尺寸）
 * 2. Phase C 重布局补丁（flex/grid 确定宽度后需要重新布局子项）
 *
 * Px 等价实现：算法接收 ChildLayoutProvider，按需调用 layoutChild()。
 * Provider 内部处理缓存（洁净子项直接复用 cachedFragment）。
 *
 * 使用方式：
 *   $provider = new ChildLayoutProvider($orchestrator, $node, $parentSpace, $parentStyle, $nodeLayer);
 *   $algo->layout($space, $style, $textContent, $node->children, $provider, $cached);
 *   // 算法内部：$childFrag = $provider->layoutChild($child, $childConstraint);
 */
class ChildLayoutProvider
{
    /** @var array<int, PhysicalFragment> 已布局子项缓存（index → fragment） */
    private array $laidOutChildren = [];

    public function __construct(
        private readonly LayoutOrchestrator $orchestrator,
        private readonly RenderNode $parentNode,
        private readonly ConstraintSpace $parentSpace,
        private readonly ?\Px\Css\ComputedStyle $parentStyle,
        private readonly int $nodeLayer,
    ) {}

    /**
     * 按需布局单个子项（对标 Blink LayoutChild）。
     *
     * 算法在需要子项尺寸时调用此方法。Provider 处理：
     * 1. 缓存命中：已布局过的子项直接返回
     * 2. 洁净跳过：layoutDirty=false 且约束未变 → 复用 cachedFragment
     * 3. 递归布局：调用 mainLayout 布局子项
     *
     * @param RenderNode $child 子项 RenderNode
     * @param ConstraintSpace|null $overrideSpace 可选的约束覆盖（flex/grid 确定的宽度）
     * @return PhysicalFragment 子项布局结果
     */
    public function layoutChild(RenderNode $child, ?ConstraintSpace $overrideSpace = null, int $layer = 0): PhysicalFragment
    {
        // 查找子项索引
        $index = $this->findChildIndex($child);
        if ($index < 0) {
            // 不在 children 中（不应发生），返回空 fragment
            return new PhysicalFragment(0, 0, 0, 0, 0, 0, 0, 0, 0, null, [], $child);
        }

        // 缓存命中：已布局过
        if (isset($this->laidOutChildren[$index])) {
            return $this->laidOutChildren[$index];
        }

        // 构建子项约束空间
        $childSpace = $overrideSpace ?? $this->orchestrator->buildChildSpacePublic($child, $this->parentSpace, $this->parentStyle);

        // 洁净跳过（对标 Blink：约束未变 + 非脏 → 复用缓存）
        if (!$child->layoutDirty && $child->cachedFragment !== null
            && $child->cachedConstraintSpace !== null
            && $childSpace->equals($child->cachedConstraintSpace)) {
            \Px\Core\PerfCounter::inc('layout_child_skip');
            $this->laidOutChildren[$index] = $child->cachedFragment;
            return $child->cachedFragment;
        }

        // 递归布局
        $fragment = $this->orchestrator->mainLayoutPublic($child, $childSpace, $this->nodeLayer, 0);
        $this->laidOutChildren[$index] = $fragment;
        return $fragment;
    }

    /**
     * 获取所有已布局的子项 fragment（按 children 顺序）。
     * 用于算法完成后构建父 fragment 的 children 数组。
     *
     * @return PhysicalFragment[]
     */
    public function getLaidOutChildren(): array
    {
        $result = [];
        $children = $this->parentNode->children;
        for ($i = 0; $i < count($children); $i++) {
            if (isset($this->laidOutChildren[$i])) {
                $result[] = $this->laidOutChildren[$i];
            }
        }
        return $result;
    }

    /**
     * 预布局所有子项（兼容模式：供尚未迁移到按需布局的算法使用）。
     * 等价于旧 Phase B，但通过 Provider 统一管理缓存。
     *
     * @return PhysicalFragment[]
     */
    public function layoutAllChildren(): array
    {
        $children = $this->parentNode->children;
        $result = [];
        for ($i = 0; $i < count($children); $i++) {
            $result[] = $this->layoutChild($children[$i]);
        }
        return $result;
    }

    private function findChildIndex(RenderNode $child): int
    {
        $children = $this->parentNode->children;
        for ($i = 0; $i < count($children); $i++) {
            if ($children[$i] === $child) {
                return $i;
            }
        }
        return -1;
    }
}
