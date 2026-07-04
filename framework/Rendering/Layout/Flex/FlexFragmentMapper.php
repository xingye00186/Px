<?php

namespace Px\Rendering\Layout\Flex;

use Px\Rendering\Layout\LayoutResult;

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
}
