<?php
namespace Px\Rendering\Layout;
use native_types;
use Px\Rendering\ComputedStyle;

/**
 * TableAlgorithm — Table 布局算法
 *
 * 取代 TableLayoutStrategy，完全自包含。
 * 支持 table/table-caption display 模式，两轮列宽协商。
 */
class TableAlgorithm extends LayoutAlgorithm
{
    public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        array $childFragments = [],
        ?PhysicalFragment $inputFragment = null
    ): PhysicalFragment {
        $s = $style ?? new ComputedStyle([]);
        $children = $childFragments;

        // Intrinsic measurement mode
        if ($space->getIsIntrinsicMeasurement()) {
            $totalW = 0; $maxH = 0;
            foreach ($children as $cr) { $totalW += (int)($cr->w ?? 0); $ch = (int)($cr->h ?? 0); if ($ch > $maxH) $maxH = $ch; }
            return new PhysicalFragment((int)max(0, $totalW), (int)max(0, $maxH), 0, 0, null, null, 0, 0, 0, $s);
        }

        $display = $s->display?->value ?? 'table';
        $x = ($space->getBfcOffsetX() ?? 0) + ((int)($s->left?->toPx() ?? 0));
        $y = ($space->getBfcOffsetY() ?? 0) + ((int)($s->top?->toPx() ?? 0));

        $w = $s->width?->toPx() ?? 0;
        if ($w <= 0) $w = $space->getContentWidth();
        $h = $s->height?->toPx() ?? 0;

        $stackedChildren = [];
        $currentY = $y;
        $needsMore = false;

        if ($display === 'table' || $display === 'table-caption') {
            // Collect max per-column widths
            $maxColWidths = []; $totalCols = 0;
            foreach ($children as $cr) {
                $crDisplay = $cr->style?->display?->value ?? 'block';
                if ($crDisplay === 'table-row') {
                    $colIdx = 0;
                    foreach ($cr->children as $cell) {
                        $cellW = (int)($cell->w ?? 0) > 0 ? (int)($cell->w ?? 0) : 80;
                        if (!isset($maxColWidths[$colIdx]) || $cellW > $maxColWidths[$colIdx]) $maxColWidths[$colIdx] = $cellW;
                        $colIdx++;
                    }
                    if ($colIdx > $totalCols) $totalCols = $colIdx;
                }
            }

            foreach ($children as $cr) {
                $crStyle = $cr->style;
                $crDisplay = $crStyle?->display?->value ?? 'block';
                if ($crDisplay === 'table-row') {
                    $cellResults = [];
                    $cellCount = count($cr->children);
                    $lineH = 0;
                    $colWidths = [];
                    for ($colI = 0; $colI < $cellCount; $colI++) {
                        $colWidths[$colI] = isset($maxColWidths[$colI]) ? $maxColWidths[$colI] : ($cellCount > 0 ? (int)($w / $cellCount) : $w);
                    }
                    $totalColW = array_sum($colWidths);
                    if ($totalColW > 0 && abs($totalColW - $w) > 1) {
                        $scale = $w / $totalColW;
                        foreach ($colWidths as $ci => $cw) { $colWidths[$ci] = (int)($cw * $scale); }
                    }

                    $colX = 0;
                    foreach ($cr->children as $ci => $cell) {
                        $cellH = (int)($cell->h ?? 0);
                        $cellW = $colWidths[$ci] ?? ($cellCount > 0 ? (int)($w / $cellCount) : $w);
                        $cellResults[] = new PhysicalFragment((int)$colX, 0, (int)$cellW, (int)$cellH, null, null, (int)($cell->layer ?? 0), (int)$cellW, (int)$cellH, $cell->style, $cell->children, null);
                        if ($cellH > $lineH) $lineH = $cellH;
                        $colX += $cellW;
                    }

                    $normCells = [];
                    foreach ($cellResults as $cf) {
                        $normCells[] = new PhysicalFragment((int)$cf->x, (int)$currentY, (int)$cf->w, (int)$lineH, null, null, (int)$cf->layer, (int)$cf->contentWidth, (int)$cf->contentHeight, $cf->style, $cf->children, null);
                    }
                    $stackedChildren[] = new PhysicalFragment((int)$x, (int)$currentY, (int)$w, (int)$lineH, null, null, 0, (int)$w, (int)$lineH, $crStyle, $normCells, null);
                    $currentY += $lineH;
                } else {
                    $stackedChildren[] = $cr;
                    $currentY += (int)($cr->h ?? 0);
                }
            }
        } else {
            $stackedChildren = $children;
        }

        if ($h <= 0) $h = max(0, $currentY - $y);

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h, $s->visualWidth($w), $s->visualHeight($h), 0, (int)$w, (int)$h, $s, $stackedChildren, null);
    }

    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        return new IntrinsicSizes(0, 0, 0, 0);
    }
}
