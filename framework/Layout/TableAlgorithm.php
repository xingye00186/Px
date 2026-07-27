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
                ? intdiv((int)$s->width->toPx() * $space->getContentWidth(), 100)
                : (int)$s->width->toPx();
        }
        if ($w <= 0) $w = $space->getContentWidth();
        $h = $s->height?->toPx() ?? 0;

        $stackedChildren = [];
        $currentY = $y;
        $needsMore = false;

        // CSS 2.2 §17.6.1 分离边框模型：border-spacing 作用于 cell 间及
        // 表内容边缘与 cell 之间；collapse 模型（§17.6.2）下 spacing 无效。
        $isCollapse = ($s->borderCollapse?->value ?? 'separate') === 'collapse';
        $spacing = $isCollapse ? 0 : max(0, (int)($s->borderSpacing ?? 0));

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
                    $currentY += $spacing; // 行前竖向 spacing（首行=表边缘间距）
                    $stackedChildren[] = $this->layoutRow($cr, $x, $currentY, $w, $maxColWidths, $spacing);
                    $currentY += (int)$stackedChildren[count($stackedChildren) - 1]->getH();
                } elseif (in_array($crDisplay, self::ROW_GROUP_DISPLAYS, true)) {
                    // row-group（thead/tbody/tfoot）：包裹行，自身几何 = 行并集
                    //（对标 Blink NGTableSection fragment）
                    $groupTop = $currentY;
                    $groupRows = [];
                    foreach ($cr->children as $g) {
                        if (($g->style?->display?->value ?? '') === 'table-row') {
                            $currentY += $spacing;
                            $rowFrag = $this->layoutRow($g, $x, $currentY, $w, $maxColWidths, $spacing);
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
                    // caption 等非行子：盒与内容平移到当前流位置（CSS 2.2 §17.4；
                    // 预布局坐标残留同 cell 族缺陷）。
                    $dxE = (int)$x - (int)($cr->x ?? 0);
                    $dyE = (int)$currentY - (int)($cr->y ?? 0);
                    $stackedChildren[] = ($dxE !== 0 || $dyE !== 0) ? FlexAlgorithm::translateFragmentTree($cr, $dxE, $dyE) : $cr;
                    $currentY += (int)($cr->h ?? 0);
                }
            }
        } else {
            $stackedChildren = $children;
        }

        if ($h <= 0) $h = max(0, $currentY + $spacing - $y); // 尾部边缘 spacing 计入表高

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h, $s->visualWidth($w), $s->visualHeight($h), 0, (int)$w, (int)$h, $s, $stackedChildren, null);
    }

    /**
     * 单行放置：列宽归一（缩放到表宽）+ cell 等高（CSS 2.2 §17.5.3）。
     */
    private function layoutRow(PhysicalFragment $row, int $x, int $currentY, int $w, array $maxColWidths, int $spacing = 0): PhysicalFragment
    {
        $cellCount = count($row->children);
        $lineH = 0;
        $colWidths = [];
        // 横向可用宽 = 表宽 − (n+1)×spacing（两端边缘 + cell 间隙，§17.6.1）
        $availW = max(0, $w - ($cellCount + 1) * $spacing);
        for ($colI = 0; $colI < $cellCount; $colI++) {
            $colWidths[$colI] = isset($maxColWidths[$colI]) ? $maxColWidths[$colI] : ($cellCount > 0 ? intdiv($availW, $cellCount) : $availW);
        }
        $totalColW = array_sum($colWidths);
        if ($totalColW > 0 && abs($totalColW - $availW) > 1) {
            // 列宽归一缩放：纯整数确定性算术（对标 Blink LayoutUnit 定点思想）。
            // 此前 $scale = $w/$totalColW 浮点中间值 + (int) 截断——PHP 与 AOT
            // Variant 链浮点精度分叉（compare_php_aot case-048 geo18+style12 实锤，
            // round 语义分叉同族）。
            foreach ($colWidths as $ci => $cw) { $colWidths[$ci] = intdiv($cw * $availW, $totalColW); }
        }

        $cellResults = [];
        $colX = $spacing;
        foreach ($row->children as $ci => $cell) {
            $cellH = (int)($cell->h ?? 0);
            $cellW = $colWidths[$ci] ?? ($cellCount > 0 ? intdiv($availW, $cellCount) : $availW);
            // cell 内容随 cell 盒平移（Px Fragment 绝对坐标契约；对标 Blink
            // cell 内容坐标相对 cell）：此前 children 携带预布局坐标不动，
            // 第二列起内容停留行首（case-048 x≈366 族）、下方行内容 y 错位
            //（y=110 族 48 条）实锤。dx/dy = 目标盒原点 − 预布局盒原点。
            $dx = (int)$colX - (int)($cell->x ?? 0);
            $dy = (int)$currentY - (int)($cell->y ?? 0);
            $movedKids = [];
            foreach ($cell->children as $ck) {
                $movedKids[] = ($dx !== 0 || $dy !== 0) ? FlexAlgorithm::translateFragmentTree($ck, $dx, $dy) : $ck;
            }
            $cellResults[] = new PhysicalFragment((int)$colX, 0, (int)$cellW, (int)$cellH, (int)$cellW, (int)$cellH, (int)($cell->layer ?? 0), (int)$cellW, (int)$cellH, $cell->style, $movedKids, $cell->sourceNode,
                $cell->scrollTop, $cell->scrollLeft, $cell->isScrollContainer,
                $cell->type, $cell->content, $cell->dataset, $cell->pseudoStyles);
            if ($cellH > $lineH) $lineH = $cellH;
            $colX += $cellW + $spacing;
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
