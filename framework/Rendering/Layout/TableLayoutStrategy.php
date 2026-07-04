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
        $needsMore = false;

        // ── 多列宽协商：两轮迭代 ──
        // Pass 0 (iteration=0): 等分列宽布局，收集每列最大内容宽度 → needsAnotherPass
        // Pass 1 (iteration=1): 用收集到的最大宽度归一化列宽
        if ($display === 'table' || $display === 'table-caption') {
            $iteration = $input->iteration;

            // Collect max per-column widths from already-resolved children
            $maxColWidths = [];
            $totalCols = 0;
            foreach ($children as $cr) {
                $crDisplay = $cr->style?->display?->value ?? 'block';
                if ($crDisplay === 'table-row') {
                    $colIdx = 0;
                    foreach ($cr->children as $cell) {
                        $cellW = $cell->w > 0 ? $cell->w : 80;
                        if (!isset($maxColWidths[$colIdx]) || $cellW > $maxColWidths[$colIdx]) {
                            $maxColWidths[$colIdx] = $cellW;
                        }
                        $colIdx++;
                    }
                    if ($colIdx > $totalCols) $totalCols = $colIdx;
                }
            }

            // Request second pass for content-based normalization
            if ($iteration === 0 && $totalCols > 0 && !empty($maxColWidths)) {
                $needsMore = true;
            }

            foreach ($children as $cr) {
                $crStyle = $cr->style;
                $crDisplay = $crStyle?->display?->value ?? 'block';

                if ($crDisplay === 'table-row') {
                    $cellChildren = [];
                    $cellCount = count($cr->children);
                    $lineH = 0;

                    // Determine column widths
                    $colWidths = [];
                    for ($colI = 0; $colI < $cellCount; $colI++) {
                        if ($iteration > 0 && isset($maxColWidths[$colI])) {
                            $colWidths[$colI] = $maxColWidths[$colI];
                        } else {
                            $colWidths[$colI] = $cellCount > 0 ? (int)($w / $cellCount) : $w;
                        }
                    }
                    // Scale to fit container
                    $totalColW = array_sum($colWidths);
                    if ($totalColW > 0 && abs($totalColW - $w) > 1) {
                        $scale = $w / $totalColW;
                        foreach ($colWidths as $ci => $cw) {
                            $colWidths[$ci] = (int)($cw * $scale);
                        }
                    }

                    $colX = 0;
                    foreach ($cr->children as $ci => $cell) {
                        $cellH = $cell->h;
                        $cellW = $colWidths[$ci] ?? ($cellCount > 0 ? (int)($w / $cellCount) : $w);
                        $cellChildren[] = new LayoutResult(
                            x: $colX, y: 0, w: $cellW, h: $cellH,
                            visualW: $cellW, visualH: $cellH,
                            layer: $cell->layer, style: $cell->style, children: $cell->children
                        );
                        if ($cellH > $lineH) $lineH = $cellH;
                        $colX += $cellW;
                    }

                    $normalizedCells = [];
                    foreach ($cellChildren as $cellFrag) {
                        $normalizedCells[] = new LayoutResult(
                            x: $cellFrag->x, y: $currentY,
                            w: $cellFrag->w, h: $lineH,
                            visualW: $cellFrag->visualW, visualH: $lineH,
                            layer: $cellFrag->layer, style: $cellFrag->style, children: $cellFrag->children
                        );
                    }

                    $stackedChildren[] = new LayoutResult(
                        x: $x, y: $currentY, w: $w, h: $lineH, children: $normalizedCells
                    );
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

        return new LayoutResult(x: $x, y: $y, w: $w, h: $h, visualW: $s->visualWidth($w), visualH: $s->visualHeight($h), style: $s, children: $stackedChildren, needsAnotherPass: $needsMore);
    }
}
