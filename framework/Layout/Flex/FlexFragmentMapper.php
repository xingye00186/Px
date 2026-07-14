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
            $oldW = $orig !== null ? (int)$orig->getW() : 0;
            $newW = (int)$item->w;
            $children = $orig?->children ?? [];
            // When flex resize changes item width, recompute children x positions
            // based on the flex item's own justify-content mode
            if ($oldW > 0 && $newW > 0 && $oldW !== $newW && !empty($children)) {
                $childJustify = $orig?->style?->justifyContent?->value ?? 'flex-start';
                $adjusted = [];
                foreach ($children as $ch) {
                    $chNewX = (int)$ch->getX();
                    if ($childJustify === 'center') {
                        // Center: children were centered in oldW, recenter in newW
                        $chNewX += (int)(($newW - $oldW) / 2);
                    } elseif ($childJustify === 'flex-end' || $childJustify === 'end') {
                        // flex-end: children shift by the full width change
                        $chNewX += $newW - $oldW;
                    }
                    // flex-start: children x unchanged
                    $adjusted[] = new PhysicalFragment(
                        $chNewX, (int)$ch->getY(),
                        (int)$ch->getW(), (int)$ch->getH(),
                        (int)$ch->getVisualW(), (int)$ch->getVisualH(),
                        (int)$ch->getLayer(),
                        (int)$ch->getContentWidth(), (int)$ch->getContentHeight(),
                        $ch->style, $ch->children ?? [], $ch->sourceNode
                    );
                }
                $children = $adjusted;
            }
            $fragments[] = new PhysicalFragment((int)$item->x, (int)$item->y, $newW, (int)$item->h, (int)$item->visualW, (int)$item->visualH, (int)($orig?->layer ?? 0), (int)($orig?->contentWidth ?? 0), (int)($orig?->contentHeight ?? 0), $orig?->style, $children, null);
        }
        return $fragments;
    }
}
