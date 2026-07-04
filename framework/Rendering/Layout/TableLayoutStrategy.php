<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;

class TableLayoutStrategy implements LayoutStrategyInterface
{
    public function layout(LayoutInput $input): LayoutResult
    {
        // Intrinsic measurement mode
        if ($input->constraints->isIntrinsicMeasurement) {
            // Table intrinsic: aggregate children
            $totalW = 0; $maxH = 0;
            foreach ($input->childResults as $cr) { $totalW += $cr->w; if ($cr->h > $maxH) $maxH = $cr->h; }
            return new LayoutResult(w: $totalW, h: $maxH, minContentWidth: $totalW, maxContentWidth: $totalW, preferredContentWidth: $totalW, minContentHeight: $maxH, maxContentHeight: $maxH, preferredContentHeight: $maxH);
        }

        $c = $input->constraints;
        $s = $input->style;
        $children = $input->childResults;

        $display = $s->display?->value ?? 'table';
        $parentX = $c->parentContentX;
        $parentY = $c->parentContentY;

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $x = $parentX + $left;
        $y = $parentY + $top;

        $w = $s->width->toPx();
        if ($w <= 0) $w = $c->contentWidth;
        $h = $s->height->toPx();

        $stackedChildren = [];
        $currentY = $y;

        if ($display === 'table' || $display === 'table-caption') {
            // Single-pass cell width calculation: distribute equal widths
            foreach ($children as $cr) {
                $crStyle = $cr->style;
                $crDisplay = $crStyle?->display?->value ?? 'block';

                if ($crDisplay === 'table-row') {
                    $cellChildren = [];
                    $cellCount = count($cr->children);
                    $cellW = $cellCount > 0 ? (int)($w / $cellCount) : $w;
                    $lineH = 0;

                    foreach ($cr->children as $cell) {
                        $cellH = $cell->h;
                        $cellChildren[] = new LayoutResult(x: 0, y: 0, w: $cellW, h: $cellH, visualW: $cellW, visualH: $cellH, layer: $cell->layer, style: $cell->style, children: $cell->children);
                        if ($cellH > $lineH) $lineH = $cellH;
                    }

                    $normalizedCells = [];
                    foreach ($cellChildren as $cellFrag) {
                        $normalizedCells[] = new LayoutResult(x: count($normalizedCells) * $cellW, y: $currentY, w: $cellW, h: $lineH, visualW: $cellW, visualH: $lineH, layer: $cellFrag->layer, style: $cellFrag->style, children: $cellFrag->children);
                    }

                    $stackedChildren[] = new LayoutResult(x: $x, y: $currentY, w: $w, h: $lineH, children: $normalizedCells);
                    $currentY += $lineH;
                } else {
                    $stackedChildren[] = $cr;
                    $currentY += $cr->h;
                }
            }
        } else {
            $stackedChildren = $children;
        }

        if ($h <= 0) $h = max(0, $currentY - $y);

        return new LayoutResult(x: $x, y: $y, w: $w, h: $h, visualW: $s->visualWidth($w), visualH: $s->visualHeight($h), style: $s, children: $stackedChildren);
    }
}
