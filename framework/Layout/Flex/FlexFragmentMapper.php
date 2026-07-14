<?php

namespace Px\Layout\Flex;

use Px\Layout\PhysicalFragment;

class FlexFragmentMapper
{
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
