<?php

namespace Px\Rendering\Layout;

use native_types;
use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\Flex\FlexItem;
use Px\Rendering\Layout\Flex\FlexLineBreaker;
use Px\Rendering\Layout\Flex\FlexFragmentMapper;
use Px\Rendering\CssLength;

class FlexLayoutStrategy implements LayoutStrategyInterface
{
    public function layout(LayoutInput $input): LayoutResult
    {
        if ($input->constraints->isIntrinsicMeasurement) {
            return new LayoutResult(w: 0, h: 0, minContentWidth: 0, maxContentWidth: 99999, preferredContentWidth: 0, minContentHeight: 0, maxContentHeight: 99999, preferredContentHeight: 0);
        }
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
        $align = $s->getRaw("alignItems") ?? "stretch";
        $wrap = $s->getRaw("flexWrap");
        $isWrapping = ($wrap === "wrap" || $wrap === "wrap-reverse");
        $gap = (int)($s->getRaw("gap") ?? 0);

        $flexItems = [];
        $flexItemData = [];
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
            $flexItemData[] = [
                'grow' => $grow, 'shrink' => $shrink, 'basis' => $basis,
                'isFlexGrow' => ($grow > 0), 'hasExplicitCrossSize' => false,
                'crossAxisSized' => false,
                'marginLeft' => 0, 'marginRight' => 0, 'marginTop' => 0, 'marginBottom' => 0,
            ];
        }
        if (count($flexItems) === 0) {
            return new LayoutResult(x: $x, y: $y, w: $w, h: $h, visualW: $s->visualWidth($w), visualH: $s->visualHeight($h), style: $s);
        }

        // Apply flex-basis
        foreach ($flexItems as $item) {
            if ($item->basis > 0) { if ($isRow) $item->w = $item->basis; else $item->h = $item->basis; }
        }

        // Break into lines using FlexLineBreaker
        $breaker = new FlexLineBreaker();
        $lines = $breaker->breakLines($flexItems, $flexItemData, $isWrapping, $isRow, $w, $gap);
        $lineGroups = $lines[0];
        $lineData = $lines[1] ?? [];

        // Process each line: grow/shrink distribution
        $cursorY = $y;
        $maxH = 0;
        foreach ($lineGroups as $lineIdx => $lineItems) {
            $lineDataForLine = $lineData[$lineIdx] ?? [];
            $lineTotal = 0;
            foreach ($lineItems as $item) { $lineTotal += $isRow ? $item->w : $item->h; }

            // Grow
            if ($lineTotal < $w && $lineTotal > 0) {
                $remaining = $w - $lineTotal;
                $growTotal = 0;
                foreach ($lineItems as $item) { $growTotal += $item->grow; }
                if ($growTotal > 0) {
                    foreach ($lineItems as $item) {
                        if ($item->grow > 0) {
                            $extra = (int)($remaining * $item->grow / $growTotal);
                            if ($isRow) $item->w += $extra; else $item->h += $extra;
                        }
                    }
                }
            }

            // Shrink
            if ($lineTotal > $w) {
                $overflow = $lineTotal - $w;
                $shrinkTotal = 0;
                foreach ($lineItems as $item) { $shrinkTotal += $item->shrink; }
                if ($shrinkTotal > 0) {
                    foreach ($lineItems as $item) {
                        if ($item->shrink > 0) {
                            $reduction = (int)($overflow * $item->shrink / $shrinkTotal);
                            if ($isRow) $item->w = max(0, $item->w - $reduction);
                            else $item->h = max(0, $item->h - $reduction);
                        }
                    }
                }
            }

            // Recalc after grow/shrink
            $lineFinal = 0;
            foreach ($lineItems as $item) { $lineFinal += $isRow ? $item->w : $item->h; }

            // Justify-content per line
            $mainStart = 0; $spaceBetween = 0;
            $lineCount = count($lineItems);
            if ($justify === "center") { $mainStart = ($w - $lineFinal) / 2; }
            elseif ($justify === "flex-end") { $mainStart = $w - $lineFinal; }
            elseif ($justify === "space-between" && $lineCount > 1) { $spaceBetween = ($w - $lineFinal) / ($lineCount - 1); }
            elseif ($justify === "space-around") { $spaceBetween = ($w - $lineFinal) / $lineCount; $mainStart = $spaceBetween / 2; }
            elseif ($justify === "space-evenly") { $spaceBetween = ($w - $lineFinal) / ($lineCount + 1); $mainStart = $spaceBetween; }

            // Position items in this line
            $cursorX = $x + (int)$mainStart;
            $lineMaxCross = 0;
            foreach ($lineItems as $item) {
                $itemH = $item->h;
                // align-items: stretch fills cross-axis
                $crossVal = 0;
                if ($align === "stretch" && $h > 0) {
                    $crossVal = ($isRow ? $item->h : $item->w);
                    $targetCross = ($isRow ? $h : $w) / max(1, count($lineGroups));
                    if ($isRow) { if ($item->h < $targetCross) $item->h = (int)$targetCross; }
                    else { if ($item->w < $targetCross) $item->w = (int)$targetCross; }
                }
                $itemH = $isRow ? $item->h : $item->w;

                if ($isRow) {
                    $item->x = (int)$cursorX; $item->y = $cursorY;
                    $cursorX += $item->w + (int)$spaceBetween + $gap;
                } else {
                    $item->y = (int)$cursorY; $item->x = $x;
                    $cursorY += $item->h + (int)$spaceBetween + $gap;
                }
                if ($itemH > $lineMaxCross) $lineMaxCross = $itemH;
            }
            $cursorY += $lineMaxCross + $gap;
            if ($lineMaxCross > $maxH) $maxH = $lineMaxCross;
        }

        $mappedResults = FlexFragmentMapper::toResults($flexItems, $childResults);
        if ($h <= 0 && count($mappedResults) > 0) {
            $maxBottom = $y;
            foreach ($mappedResults as $cr) { $b = $cr->y + $cr->h; if ($b > $maxBottom) $maxBottom = $b; }
            $h = max(0, $maxBottom - $y);
        }
        return new LayoutResult(x: $x, y: $y, w: $w, h: $h, visualW: $s->visualWidth($w), visualH: $s->visualHeight($h), style: $s, children: $mappedResults);
    }
}