<?php

namespace Px\Rendering\Layout\Flex;

use Px\Rendering\Layout\LayoutResult;
use Px\Rendering\Layout\PhysicalFragment;

class FlexFragmentMapper
{
    public static function toResults(array $items, array $originalChildResults): array
    {
        $results = [];
        foreach ($items as $i => $item) {
            $orig = $originalChildResults[$i] ?? null;
            $results[] = new LayoutResult(x: $item->x, y: $item->y, w: $item->w, h: $item->h, visualW: $item->visualW, visualH: $item->visualH, layer: $orig?->layer ?? 0, contentWidth: $orig?->contentWidth ?? 0, contentHeight: $orig?->contentHeight ?? 0, style: $orig?->style, children: $orig?->children ?? []);
        }
        return $results;
    }

    public static function toFragments(array $items, array $originalChildFragments): array
    {
        $fragments = [];
        foreach ($items as $i => $item) {
            $orig = $originalChildFragments[$i] ?? null;
            $fragments[] = new PhysicalFragment((int)$item->x, (int)$item->y, (int)$item->w, (int)$item->h, (int)$item->visualW, (int)$item->visualH, (int)($orig?->layer ?? 0), (int)($orig?->contentWidth ?? 0), (int)($orig?->contentHeight ?? 0), $orig?->style, $orig?->children ?? [], null);
        }
        return $fragments;
    }
}
