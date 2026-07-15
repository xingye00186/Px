<?php

namespace Px\Layout;

use native_types;

use Px\Css\ComputedStyle;
use Px\Layout\ConstraintSpace;
use Px\Layout\PhysicalFragment;
use Px\Layout\IntrinsicSizes;
use Px\Render\RenderNode;

/**
 * LayoutAlgorithm — 布局算法抽象基类（对标 Blink LayoutAlgorithm）
 *
 * 纯函数接口：layout() 接收约束，返回不可变 Fragment。
 * intrinsicSize() 提供两阶段内在尺寸测量。
 *
 * AOT 兼容：所有子类必须 use native_types。
 */
abstract class LayoutAlgorithm
{
    /** @var ChildLayoutProvider|null 由 LayoutOrchestrator 注入 */
    private ?ChildLayoutProvider $childLayoutProvider = null;

    /** 注入子项布局回调 */
    public function setChildLayoutProvider(?ChildLayoutProvider $p): void
    {
        $this->childLayoutProvider = $p;
    }

    /** 布局子项（替代码中直接使用 $childFragments[$i] 的模式） */
    protected function layoutChild(RenderNode $child, ConstraintSpace $space, int $layer = 0): PhysicalFragment
    {
        if ($this->childLayoutProvider !== null) {
            return $this->childLayoutProvider->layoutChild($child, $space, $layer);
        }
        // 降级：无提供者时返回空 Fragment
        return new PhysicalFragment(0, 0, 0, 0, 0, 0, 0, 0, 0, $child->computedStyle ?? new ComputedStyle([]), [], $child);
    }

    /**
     * 执行布局，返回不可变 Fragment。
     *
     * @param ConstraintSpace $space 约束空间
     * @param ComputedStyle|null $style 元素计算样式
     * @param string $textContent 文本内容
     * @param array $childNodes 子 RenderNode 节点
     * @param PhysicalFragment[] $childFragments 预计算子 fragment（即将废弃，改用 layoutChild）
     * @param PhysicalFragment|null $inputFragment 可选输入 Fragment（缓存命中时）
     * @param ConstraintSpace[]|null $childConstraints 子项约束空间（layoutChild 模式）
     * @param IntrinsicSizes[]|null $childIntrinsicSizes 子项内在尺寸（Phase A 收集）
     * @return PhysicalFragment 布局结果
     */
    abstract public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        array $childFragments = [],
        ?PhysicalFragment $inputFragment = null,
        ?array $childConstraints = null,
        ?array $childIntrinsicSizes = null,
    ): PhysicalFragment;

    /**
     * 测量内在尺寸（两阶段 IntrinsicSizing 的 Pass 1）。
     */
    abstract public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes;
}

