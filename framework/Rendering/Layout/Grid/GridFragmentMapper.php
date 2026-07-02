<?php

namespace Px\Rendering\Layout\Grid;

use Px\Rendering\Layout\LayoutFragment;
use Px\Rendering\RenderNode;

/**
 * GridFragmentMapper — GridItem[] -> LayoutFragment[] 映射器
 *
 * 将 GridPlacer 计算后的 GridItem 坐标映射回 LayoutFragment，
 * 同时保留原始子 Fragment 中的孙子链（grandchildren），
 * 确保 applyTo 能递归回写所有层级的坐标。
 */
class GridFragmentMapper
{
    /**
     * 将 GridItem[] 映射为 LayoutFragment[]。
     *
     * @param GridItem[]        $items                   放置器计算后的 grid 子项
     * @param LayoutFragment[]  $originalChildFragments  布局阶段 resolveChildren 的原始输出（含孙子链）
     * @return LayoutFragment[]
     */
    public static function toFragments(
        array $items,
        array $originalChildFragments
    ): array {
        $fragments = [];
        foreach ($items as $i => $item) {
            $orig = $originalChildFragments[$i] ?? null;
            $fragments[] = new LayoutFragment(
                x: $item->x,
                y: $item->y,
                w: $item->w,
                h: $item->h,
                visualW: $item->node->visualW,
                visualH: $item->node->visualH,
                layer: $orig?->layer ?? 0,
                contentWidth: $orig?->contentWidth ?? 0,
                contentHeight: $orig?->contentHeight ?? 0,
                style: $item->node->computedStyle,
                children: $orig?->children ?? [],
            );
        }
        return $fragments;
    }
}
