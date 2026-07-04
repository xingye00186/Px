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
        $needsMore = false;

        // ── 列平衡：两轮迭代 ──
        // Pass 0 (iteration=0): 等分布局，收集列高 → needsAnotherPass
        // Pass 1 (iteration>0): 贪心重分配——每项放入当前最矮的列
        $iteration = $input->iteration;

        // Per-column tracking: current y offset
        $colCurY = array_fill(0, $columnCount, $y);
        // Per-column item count for pass 0
        $colItemCounts = array_fill(0, $columnCount, 0);

        foreach ($children as $i => $cr) {
            if ($iteration > 0) {
                // Greedy: place in shortest column
                $shortestCol = 0;
                $shortestY = $colCurY[0];
                for ($c = 1; $c < $columnCount; $c++) {
                    if ($colCurY[$c] < $shortestY) {
                        $shortestCol = $c;
                        $shortestY = $colCurY[$c];
                    }
                }
                $colIdx = $shortestCol;
            } else {
                // Pass 0: sequential fill by fixed chunk size
                $colIdx = $perColumn > 0 ? (int)($i / $perColumn) : 0;
                if ($colIdx >= $columnCount) $colIdx = $columnCount - 1;
                $colItemCounts[$colIdx]++;
            }

            $cx = $x + $colIdx * ($colWidth + $colGap);
            $cy = $colCurY[$colIdx];

            $stackedChildren[] = new LayoutResult(
                x: $cx, y: $cy, w: $cr->w, h: $cr->h,
                visualW: $cr->visualW, visualH: $cr->visualH,
                layer: $cr->layer, style: $cr->style, children: $cr->children
            );
            $colCurY[$colIdx] = $cy + $cr->h;
            $colH = max($colH, $colCurY[$colIdx] - $y);
        }

        // Request second pass if columns are imbalanced (iteration 0 only)
        if ($iteration === 0 && count($children) > $columnCount) {
            $needsMore = true;
        }

        if ($h <= 0) $h = max(0, $colH);

        return new LayoutResult(x: $x, y: $y, w: $w, h: $h, visualW: $s->visualWidth($w), visualH: $s->visualHeight($h), style: $s, children: $stackedChildren, needsAnotherPass: $needsMore);
    }
}
