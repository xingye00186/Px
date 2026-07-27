<?php
namespace Px\Layout;
use native_types;
use Px\Css\ComputedStyle;

/**
 * TableAlgorithm — Table 布局算法（对标 Blink NGTableLayoutAlgorithm）
 *
 * 取代 TableLayoutStrategy，完全自包含。
 * 支持 table/table-caption/table-row-group(thead/tbody/tfoot)/table-row/
 * table-cell/table-column(-group) display 模式；列宽两轮协商。
 * 行遍历下探 row-group 层（HTML 树构建器保证 tr 恒在 row-group 内，
 * §13.2.6）；column/column-group 不产生盒几何流（CSS 2.2 §17.2.1）。
 */
class TableAlgorithm extends LayoutAlgorithm
{
    private const ROW_GROUP_DISPLAYS = ['table-row-group', 'table-header-group', 'table-footer-group'];

    public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        ?PhysicalFragment $inputFragment = null,
    ): PhysicalFragment {
        $s = $style ?? \Px\Css\StylePool::empty();
        // P2: 按需布局子项
        $children = [];
        for ($ti = 0, $tlen = count($childNodes); $ti < $tlen; $ti++) {
            $children[] = $this->layoutChild($childNodes[$ti]);
        }

        // Intrinsic measurement mode
        if ($space->getIsIntrinsicMeasurement()) {
            $totalW = 0; $maxH = 0;
            foreach ($children as $cr) { $totalW += (int)($cr->w ?? 0); $ch = (int)($cr->h ?? 0); if ($ch > $maxH) $maxH = $ch; }
            return new PhysicalFragment((int)max(0, $totalW), (int)max(0, $maxH), 0, 0, null, null, 0, 0, 0, $s);
        }

        $display = $s->display?->value ?? 'table';
        // 对标 Blink NGTableLayoutAlgorithm：存放相对于约束根的坐标，bfc_offset 在 Px 中未启用
        $x = (int)($s->left?->toPx() ?? 0);
        $y = (int)($s->top?->toPx() ?? 0);

        // 表宽：percent 按包含块解析（CSS 2.2 §10.2；此前 toPx() 直取使
        // width:100% → 100px，表本体塌宽致内部全错）。
        $w = 0;
        if ($s->width !== null && !$s->width->isAuto()) {
            $w = $s->width->isPercent()
                ? (int)($s->width->toPx() * $space->getContentWidth() / 100)
                : (int)$s->width->toPx();
        }
        if ($w <= 0) $w = $space->getContentWidth();
        $h = $s->height?->toPx() ?? 0;

        $stackedChildren = [];
        $currentY = $y;
        $needsMore = false;

        if ($display === 'table' || $display === 'table-caption') {
            // ── 第一遍：收集列宽（下探 row-group 层，对标 Blink 列约束收集）──
            $maxColWidths = []; $totalCols = 0;
            foreach ($children as $cr) {
                $crDisplay = $cr->style?->display?->value ?? 'block';
                $rowsOf = [];
                if ($crDisplay === 'table-row') {
                    $rowsOf[] = $cr;
                } elseif (in_array($crDisplay, self::ROW_GROUP_DISPLAYS, true)) {
                    foreach ($cr->children as $g) {
                        if (($g->style?->display?->value ?? '') === 'table-row') $rowsOf[] = $g;
                    }
                }
                foreach ($rowsOf as $row) {
                    $colIdx = 0;
                    foreach ($row->children as $cell) {
                        $cellW = (int)($cell->w ?? 0) > 0 ? (int)($cell->w ?? 0) : 80;
                        if (!isset($maxColWidths[$colIdx]) || $cellW > $maxColWidths[$colIdx]) $maxColWidths[$colIdx] = $cellW;
                        $colIdx++;
                    }
                    if ($colIdx > $totalCols) $totalCols = $colIdx;
                }
            }

            // ── 第二遍：按组/行放置 ──
            foreach ($children as $cr) {
                $crStyle = $cr->style;
                $crDisplay = $crStyle?->display?->value ?? 'block';
                if ($crDisplay === 'table-row') {
                    $stackedChildren[] = $this->layoutRow($cr, $x, $currentY, $w, $maxColWidths);
                    $currentY += (int)$stackedChildren[count($stackedChildren) - 1]->getH();
                } elseif (in_array($crDisplay, self::ROW_GROUP_DISPLAYS, true)) {
                    // row-group（thead/tbody/tfoot）：包裹行，自身几何 = 行并集
                    //（对标 Blink NGTableSection fragment）
                    $groupTop = $currentY;
                    $groupRows = [];
                    foreach ($cr->children as $g) {
                        if (($g->style?->display?->value ?? '') === 'table-row') {
                            $rowFrag = $this->layoutRow($g, $x, $currentY, $w, $maxColWidths);
                            $groupRows[] = $rowFrag;
                            $currentY += (int)$rowFrag->getH();
                        } else {
                            $groupRows[] = $g;
                        }
                    }
                    $stackedChildren[] = new PhysicalFragment((int)$x, (int)$groupTop, (int)$w, (int)max(0, $currentY - $groupTop),
                        (int)$w, (int)max(0, $currentY - $groupTop), 0, (int)$w, (int)max(0, $currentY - $groupTop),
                        $crStyle, $groupRows, $cr->sourceNode,
                        0, 0, false,
                        $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles);
                } elseif ($crDisplay === 'table-column' || $crDisplay === 'table-column-group') {
                    // CSS 2.2 §17.2.1：column 盒不产生几何流（不占 currentY）；
                    // 保留导出（浏览器 col rect 有几何，列区域近似后续补）。
                    $stackedChildren[] = new PhysicalFragment((int)$x, (int)$currentY, (int)($cr->getW() ?? 0), 0,
                        0, 0, 0, 0, 0,
                        $crStyle, $cr->children, $cr->sourceNode,
                        0, 0, false,
                        $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles);
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

    /**
     * 单行放置：列宽归一（缩放到表宽）+ cell 等高（CSS 2.2 §17.5.3）。
     */
    private function layoutRow(PhysicalFragment $row, int $x, int $currentY, int $w, array $maxColWidths): PhysicalFragment
    {
        $cellCount = count($row->children);
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

        $cellResults = [];
        $colX = 0;
        foreach ($row->children as $ci => $cell) {
            $cellH = (int)($cell->h ?? 0);
            $cellW = $colWidths[$ci] ?? ($cellCount > 0 ? (int)($w / $cellCount) : $w);
            $cellResults[] = new PhysicalFragment((int)$colX, 0, (int)$cellW, (int)$cellH, (int)$cellW, (int)$cellH, (int)($cell->layer ?? 0), (int)$cellW, (int)$cellH, $cell->style, $cell->children, $cell->sourceNode,
                $cell->scrollTop, $cell->scrollLeft, $cell->isScrollContainer,
                $cell->type, $cell->content, $cell->dataset, $cell->pseudoStyles);
            if ($cellH > $lineH) $lineH = $cellH;
            $colX += $cellW;
        }

        $normCells = [];
        foreach ($cellResults as $cf) {
            $normCells[] = new PhysicalFragment((int)$cf->x, (int)$currentY, (int)$cf->w, (int)$lineH, (int)$cf->w, (int)$lineH, (int)$cf->layer, (int)$cf->contentWidth, (int)$cf->contentHeight, $cf->style, $cf->children, $cf->sourceNode,
                $cf->scrollTop, $cf->scrollLeft, $cf->isScrollContainer,
                $cf->type, $cf->content, $cf->dataset, $cf->pseudoStyles);
        }
        return new PhysicalFragment((int)$x, (int)$currentY, (int)$w, (int)$lineH, (int)$w, (int)$lineH, 0, (int)$w, (int)$lineH,
            $row->style, $normCells, $row->sourceNode,
            0, 0, false,
            $row->type, $row->content, $row->dataset, $row->pseudoStyles);
    }
}
