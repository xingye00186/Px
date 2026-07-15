<?php
namespace Px\Layout;

use Px\Render\RenderNode;

/**
 * ChildLayoutProvider — 子项布局回调接口（对标 Blink 的 ContainerLayoutBuilder）
 *
 * 由 LayoutOrchestrator 注入 Algorithm，使算法能自主调子项布局，
 * 无需外部预计算 childFragments。
 */
interface ChildLayoutProvider
{
    /** 以指定约束空间布局子节点，返回 PhysicalFragment */
    public function layoutChild(RenderNode $child, ConstraintSpace $space, int $layer = 0): PhysicalFragment;
}
