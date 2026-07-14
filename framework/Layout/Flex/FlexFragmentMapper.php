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
            // When flex resize changes item width, adjust children x positions
            // to maintain their relative positions within the new container size
            if ($oldW > 0 && $newW > 0 && $oldW !== $newW && !empty($children)) {
                $adjusted = [];
                foreach ($children as $ch) {
                    $newX = $oldW > 0 ? (int)($ch->getX() * $newW / $oldW) : $ch->getX();
                    // Rebuild fragment with adjusted x
                    $adjusted[] = new PhysicalFragment(
                        $newX, (int)$ch->getY(),
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
