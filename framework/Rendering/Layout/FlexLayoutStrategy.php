<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\Flex\FlexItem;
use Px\Rendering\Layout\Flex\FlexFragmentMapper;
use Px\Rendering\CssLength;

class FlexLayoutStrategy implements LayoutStrategyInterface
{
    public function layout(LayoutInput $input): LayoutResult
    {
        $c = $input->constraints;
        $s = $input->style;
        $childResults = $input->childResults;

        $parentX = $c->parentContentX;
        $parentY = $c->parentContentY;
        $parentW = $c->contentWidth;

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $x = $parentX + $left;
        $y = $parentY + $top;

        $w = $s->width->toPx();
        if ($w <= 0) $w = $parentW;
        $h = $s->height->toPx();

        $isRow = ($s->getRaw("flexDirection") !== "column");
        $justify = $s->getRaw("justifyContent") ?? "flex-start";
        $wrap = $s->getRaw("flexWrap");

        $flexItems = [];
        foreach ($childResults as $cr) {
            $cs = $cr->style;
            if ($cs === null) continue;
            $grow = (float)($cs->getRaw("flexGrow") ?? 0);
            $shrink = (float)($cs->getRaw("flexShrink") ?? 1);
            $rawBasis = $cs->getRaw("flexBasis");
            $basis = -1;
            if ($rawBasis instanceof CssLength && !$rawBasis->isAuto()) { $basis = $rawBasis->toPx(); }
            $item = new FlexItem();
            $item->grow = $grow;
            $item->shrink = $shrink;
            $item->basis = $basis;
            $item->isFlexGrow = ($grow > 0);
            $item->originalChildren = $cr->children;
            $item->w = $cr->w; $item->h = $cr->h;
            $item->visualW = $cr->visualW; $item->visualH = $cr->visualH;
            $flexItems[] = $item;
        }

        if (count($flexItems) === 0) {
            return new LayoutResult(x: $x, y: $y, w: $w, h: $h, visualW: $s->visualWidth($w), visualH: $s->visualHeight($h), style: $s);
        }

        foreach ($flexItems as $item) {
            if ($item->basis > 0) { if ($isRow) { $item->w = $item->basis; } else { $item->h = $item->basis; } }
        }

        $totalMain = 0;
        foreach ($flexItems as $item) { $totalMain += $isRow ? $item->w : $item->h; }

        if ($totalMain < $w && $totalMain > 0) {
            $remaining = $w - $totalMain;
            $growTotal = 0;
            foreach ($flexItems as $item) { $growTotal += $item->grow; }
            if ($growTotal > 0) {
                foreach ($flexItems as $item) {
                    if ($item->grow > 0) {
                        $extra = (int)($remaining * $item->grow / $growTotal);
                        if ($isRow) { $item->w += $extra; } else { $item->h += $extra; }
                    }
                }
            }
        }

        if ($totalMain > $w) {
            $overflow = $totalMain - $w;
            $shrinkTotal = 0;
            foreach ($flexItems as $item) { $shrinkTotal += $item->shrink; }
            if ($shrinkTotal > 0) {
                foreach ($flexItems as $item) {
                    if ($item->shrink > 0) {
                        $reduction = (int)($overflow * $item->shrink / $shrinkTotal);
                        if ($isRow) { $item->w = max(0, $item->w - $reduction); } else { $item->h = max(0, $item->h - $reduction); }
                    }
                }
            }
        }

        $totalFinal = 0;
        foreach ($flexItems as $item) { $totalFinal += $isRow ? $item->w : $item->h; }
        $mainStart = 0; $spaceBetween = 0;
        $lineCount = count($flexItems);
        if ($justify === "center") { $mainStart = ($w - $totalFinal) / 2; }
        elseif ($justify === "flex-end") { $mainStart = $w - $totalFinal; }
        elseif ($justify === "space-between" && $lineCount > 1) { $spaceBetween = ($w - $totalFinal) / ($lineCount - 1); }
        elseif ($justify === "space-around") { $spaceBetween = ($w - $totalFinal) / $lineCount; $mainStart = $spaceBetween / 2; }
        elseif ($justify === "space-evenly") { $spaceBetween = ($w - $totalFinal) / ($lineCount + 1); $mainStart = $spaceBetween; }

        $cursorX = $x + (int)$mainStart;
        $cursorY = $y + (int)$mainStart;

        foreach ($flexItems as $item) {
            if ($isRow) {
                $item->x = (int)$cursorX; $item->y = $y;
                $cursorX += $item->w + (int)$spaceBetween;
            } else {
                $item->y = (int)$cursorY; $item->x = $x;
                $cursorY += $item->h + (int)$spaceBetween;
            }
        }

        $mappedResults = FlexFragmentMapper::toResults($flexItems, $childResults);

        if ($h <= 0 && count($mappedResults) > 0) {
            $maxBottom = $y;
            foreach ($mappedResults as $cr) { $bottom = $cr->y + $cr->h; if ($bottom > $maxBottom) $maxBottom = $bottom; }
            $h = max(0, $maxBottom - $y);
        }

        return new LayoutResult(x: $x, y: $y, w: $w, h: $h, visualW: $s->visualWidth($w), visualH: $s->visualHeight($h), style: $s, children: $mappedResults);
    }
}
