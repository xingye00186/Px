<?php

namespace Px\Rendering\Layout;

use native_types;

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
     * @param PhysicalFragment|null $inputFragment 可选输入 Fragment（缓存命中时）
     * @return PhysicalFragment 布局结果
     */
    abstract public function layout(ConstraintSpace $space, ?PhysicalFragment $inputFragment = null): PhysicalFragment;

    /**
     * 测量内在尺寸（两阶段 IntrinsicSizing 的 Pass 1）。
     *
     * @param ConstraintSpace $space 约束空间（isIntrinsicMeasurement=true）
     * @return IntrinsicSizes 内在尺寸
     */
    abstract public function intrinsicSize(ConstraintSpace $space): IntrinsicSizes;
}
