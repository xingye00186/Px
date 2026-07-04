<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\CssLength;

class MultiColumnLayoutStrategy implements LayoutStrategyInterface
{
    public function layout(LayoutInput $input): LayoutResult
    {
        // Intrinsic measurement mode
        if ($input->constraints->isIntrinsicMeasurement) {
            // MultiColumn intrinsic: aggregate children
            $totalW = 0; $maxH = 0;
            foreach ($input->childResults as $cr) { $totalW += $cr->w; if ($cr->h > $maxH) $maxH = $cr->h; }
            return new LayoutResult(w: $totalW, h: $maxH, minContentWidth: $totalW, maxContentWidth: $totalW, preferredContentWidth: $totalW, minContentHeight: $maxH, maxContentHeight: $maxH, preferredContentHeight: $maxH);
        }

        $c = $input->constraints;
        $s = $input->style;
        $children = $input->childResults;

        $parentX = $c->parentContentX;
        $parentY = $c->parentContentY;

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $x = $parentX + $left;
        $y = $parentY + $top;

        $w = $s->width->toPx();
        if ($w <= 0) $w = $c->contentWidth;
        $h = $s->height->toPx();

        $columnCount = $s->columnCount > 0 ? $s->columnCount : 1;
        $rawColWidth = $s->columnWidth;
        $colWidth = $rawColWidth instanceof CssLength ? $rawColWidth->toPx() : (int)($rawColWidth ?? 0);
        $rawColGap = $s->columnGap;
        $colGap = $rawColGap instanceof CssLength ? $rawColGap->toPx() : (int)($rawColGap ?? 0);
        if ($colGap <= 0) $colGap = 16;

        if ($colWidth <= 0) {
            $colWidth = (int)(($w - ($columnCount - 1) * $colGap) / $columnCount);
        } else {
            $columnCount = max(1, (int)(($w + $colGap) / ($colWidth + $colGap)));
            $colWidth = (int)(($w - ($columnCount - 1) * $colGap) / $columnCount);
        }

        $stackedChildren = [];
        $perColumn = count($children) > 0 ? (int)ceil(count($children) / $columnCount) : 0;
        $colH = 0;

        foreach ($children as $i => $cr) {
            $colIdx = $perColumn > 0 ? (int)($i / $perColumn) : 0;
            if ($colIdx >= $columnCount) $colIdx = $columnCount - 1;
            $posInCol = $i % $perColumn;

            $cx = $x + $colIdx * ($colWidth + $colGap);
            $cy = $y + $posInCol * $cr->h;

            $stackedChildren[] = new LayoutResult(x: $cx, y: $cy, w: $cr->w, h: $cr->h, visualW: $cr->visualW, visualH: $cr->visualH, layer: $cr->layer, style: $cr->style, children: $cr->children);
            $colH = max($colH, $cy + $cr->h - $y);
        }

        if ($h <= 0) $h = max(0, $colH);

        return new LayoutResult(x: $x, y: $y, w: $w, h: $h, visualW: $s->visualWidth($w), visualH: $s->visualHeight($h), style: $s, children: $stackedChildren);
    }
}
