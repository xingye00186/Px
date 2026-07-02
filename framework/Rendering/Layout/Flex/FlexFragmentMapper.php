<?php

namespace Px\Rendering\Layout\Flex;

use Px\Rendering\Layout\LayoutFragment;
use Px\Rendering\RenderNode;

/**
 * FlexFragmentMapper — FlexItem[] -> LayoutFragment[] 映射器
 *
 * 将 FlexDistributor 计算后的 FlexItem 坐标映射回 LayoutFragment，
 * 同时保留原始子 Fragment 中的孙子链（grandchildren），
 * 确保 applyTo 能递归回写所有层级的坐标。
 */
class FlexFragmentMapper
{
    /**
     * 将 FlexItem[] 映射为 LayoutFragment[]。
     *
     * @param FlexItem[]        $items                   分布器计算后的 flex 子项
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
                visualW: $item->visualW,
                visualH: $item->visualH,
                layer: $orig?->layer ?? 0,
                contentWidth: $orig?->contentWidth ?? 0,
                contentHeight: $orig?->contentHeight ?? 0,
                style: $item->node->computedStyle,
                // Rebuild grandchildren from current RenderNode positions (not stale originals)
                // FlexDistributor + block descendant shift may have updated them,
                // so we need fresh reads from the actual RenderNode children.
                children: self::rebuildChildren($item->node, $orig),
            );
        }
        return $fragments;
    }

    /**
     * Rebuild child LayoutFragments from current RenderNode positions.
     * Preserves the tree structure from original Fragment but uses fresh coordinates.
     */
    private static function rebuildChildren(
        $node,
        ?LayoutFragment $orig
    ): array {
        if ($orig === null || $orig->children === []) {
            return $orig?->children ?? [];
        }
        $children = [];
        $count = min(count($orig->children), count($node->children));
        for ($i = 0; $i < $count; $i++) {
            $ch = $node->children[$i];
            $origCh = $orig->children[$i];
            $children[] = new LayoutFragment(
                x: $ch->x,
                y: $ch->y,
                w: $ch->w,
                h: $ch->h,
                visualW: $ch->visualW,
                visualH: $ch->visualH,
                layer: $ch->layer,
                contentWidth: $ch->contentWidth,
                contentHeight: $ch->contentHeight,
                style: $ch->computedStyle,
                children: $origCh?->children ?? [],
            );
        }
        return $children;
    }
}
