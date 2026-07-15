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
                $parentPadL = (int)($orig?->style?->padding?->left->toPx() ?? 0);
                $parentPadR = (int)($orig?->style?->padding?->right->toPx() ?? 0);
                $oldContentW = max(0, $oldW - $parentPadL - $parentPadR);
                $newContentW = max(0, $newW - $parentPadL - $parentPadR);
                $adjusted = [];
                foreach ($children as $ch) {
                    $chW = (int)$ch->getW();
                    $chNewX = (int)$ch->getX();
                    $chNewW = $chW;
                    // 缩小子元素宽度：子元素 auto-fill 宽度接近旧 content W 时同步缩放
                    $chPadL = (int)($ch->style?->padding?->left->toPx() ?? 0);
                    $chPadR = (int)($ch->style?->padding?->right->toPx() ?? 0);
                    $chBorderL = (int)($ch->style?->getBorderLeftWidth() ?? 0);
                    $chBorderR = (int)($ch->style?->getBorderRightWidth() ?? 0);
                    $chMarginL = (int)($ch->style?->margin?->left->toPx() ?? 0);
                    $chMarginR = (int)($ch->style?->margin?->right->toPx() ?? 0);
                    if ($chW > 0 && $chW >= $oldContentW && $oldContentW > 0) {
                        // 子元素 auto-fill：直接约束到新父容器 content width
                        $chNewW = max(1, $newContentW - $chPadL - $chPadR - $chBorderL - $chBorderR - $chMarginL - $chMarginR);
                    }
                    if ($childJustify === 'center') {
                        $chNewX += (int)(($newW - $oldW) / 2);
                    } elseif ($childJustify === 'flex-end' || $childJustify === 'end') {
                        $chNewX += $newW - $oldW;
                    }
                    $adjusted[] = new PhysicalFragment(
                        $chNewX, (int)$ch->getY(),
                        $chNewW, (int)$ch->getH(),
                        $chNewW, (int)$ch->getVisualH(),
                        (int)$ch->getLayer(),
                        $chNewW, (int)$ch->getContentHeight(),
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
