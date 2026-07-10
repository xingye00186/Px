<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\ConstraintSpace;
use Px\Rendering\Layout\PhysicalFragment;
use Px\Rendering\Layout\IntrinsicSizes;
use Px\Rendering\RenderNode;

/**
 * LayoutAlgorithm — 布局算法抽象基类（对标 Blink LayoutAlgorithm）
 *
 * 纯函数接口：layout() 接收约束和可选输入 Fragment，返回不可变 Fragment。
 * intrinsicSize() 提供两阶段内在尺寸测量。
 *
 * AOT 兼容：所有子类必须 use native_types，不含闭包/回调。
 */
abstract class LayoutAlgorithm
{
    /**
     * 执行布局，返回不可变 Fragment。
     *
     * @param ConstraintSpace $space 约束空间
     * @param ComputedStyle|null $style 元素计算样式
     * @param string $textContent 文本内容（内在尺寸测量用）
     * @param array $childNodes 子 RenderNode 节点（部分策略需要）
     * @param PhysicalFragment[] $childFragments 子节点布局结果
     * @param PhysicalFragment|null $inputFragment 可选输入 Fragment（缓存命中时）
     * @return PhysicalFragment 布局结果
     */
    abstract public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        array $childFragments = [],
        ?PhysicalFragment $inputFragment = null
    ): PhysicalFragment;

    /**
     * 测量内在尺寸（两阶段 IntrinsicSizing 的 Pass 1）。
     *
     * @param ConstraintSpace $space 约束空间（isIntrinsicMeasurement=true）
     * @param ComputedStyle|null $style 元素计算样式
     * @param string $textContent 文本内容
     * @return IntrinsicSizes 内在尺寸
     */
    abstract public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes;
}
