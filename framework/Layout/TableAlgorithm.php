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
        // UA 默认 2px 由 ComputedStyle defaults 按 <table> 元素注入（typed
        // 通道空串=未声明；div[display:table] 不适用，L24 实锤）。
        $isCollapse = ($s->borderCollapse?->value ?? 'separate') === 'collapse';
        $spacing = $isCollapse ? 0 : max(0, (int)($s->borderSpacing ?? 0));
        // 表自身 border 计入内容 origin 与表高（§17.6.1 表盒模型；真值
        // tb-fixed 表 h=41=1+2+35+2+1、tr x/y=表+3）。
        $tbL = (int)($s->getBorderLeftWidth() ?? 0);
        $tbR = (int)($s->getBorderRightWidth() ?? 0);
        $tbT = (int)($s->getBorderTopWidth() ?? 0);
        $tbB = (int)($s->getBorderBottomWidth() ?? 0);

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
            // ── table-layout:fixed 列宽消费（§17.5.2.1，对标 Blink
            // NGTableLayoutAlgorithm fixed 模式）：<col> 声明宽锁定列，
            // auto 列均分剩余（真值 tb-fixed col 60/auto → B 60/648）。
            // 列序 = colgroup 内 col 文档序；仅读显式声明（getRaw 区分）。
            $fixedColWidths = [];
            if (($s->tableLayout ?? '') === 'fixed') {
                foreach ($children as $cr) {
                    $crDisplay = $cr->style?->display?->value ?? 'block';
                    $colFrags = [];
                    if ($crDisplay === 'table-column-group') { $colFrags = $cr->children; }
                    elseif ($crDisplay === 'table-column') { $colFrags = [$cr]; }
                    foreach ($colFrags as $colF) {
                        $cwRaw = $colF->style?->getRaw('width');
                        $cwPx = 0;
                        if ($cwRaw !== null) {
                            $cwPx = is_object($cwRaw) ? (int)($colF->style?->width?->toPx() ?? 0) : (int)$cwRaw;
                        }
                        $fixedColWidths[] = max(0, $cwPx);
                    }
                }
            }

            // ── 第二遍：按组/行放置；caption-side:bottom 几何延后但**文档序不变**
            //（CSS 2.2 §17.4.1；导出序=DOM 序是按索引比较器的同构前提契约，
            // 若挖到尾部会造成 4 元素序列错位——比较器自盲家族）：
            // 先占位记录索引，行全部放置后回填平移到尾部 y。──
            $bottomCaptionSlots = [];
            // colgroup/col rect 回填槽：行区确定后才能给出列区几何
            //（B 真值 colgroup rect = 行区并集 710×35，非 h=0）。
            $columnSlots = [];
            $firstRowTop = -1; $lastRowBottom = -1;
            $currentY += $tbT; // 表上 border 内缩（§17.6.1）
            foreach ($children as $cr) {
                $crStyle = $cr->style;
                $crDisplay = $crStyle?->display?->value ?? 'block';
                if ($crDisplay === 'table-caption'
                    && (($crStyle?->captionSide ?? '') === 'bottom')) {
                    $bottomCaptionSlots[count($stackedChildren)] = $cr;
                    $stackedChildren[] = $cr; // 占位（保文档序），回填时替换
                    continue;
                }
                if ($crDisplay === 'table-row') {
                    $currentY += $spacing; // 行前竖向 spacing（首行=表边缘间距）
                    if ($firstRowTop < 0) $firstRowTop = $currentY;
                    $stackedChildren[] = $this->layoutRow($cr, $x, $currentY, $w, $maxColWidths, $spacing, $tbL, $tbR, $fixedColWidths);
                    $currentY += (int)$stackedChildren[count($stackedChildren) - 1]->getH();
                    $lastRowBottom = $currentY;
                } elseif (in_array($crDisplay, self::ROW_GROUP_DISPLAYS, true)) {
                    // row-group（thead/tbody/tfoot）：包裹行，自身几何 = 行并集
                    //（对标 Blink NGTableSection fragment）
                    $groupTop = $currentY;
                    $groupRows = [];
                    foreach ($cr->children as $g) {
                        if (($g->style?->display?->value ?? '') === 'table-row') {
                            $currentY += $spacing;
                            if ($firstRowTop < 0) $firstRowTop = $currentY;
                            $rowFrag = $this->layoutRow($g, $x, $currentY, $w, $maxColWidths, $spacing, $tbL, $tbR, $fixedColWidths);
                            $groupRows[] = $rowFrag;
                            $currentY += (int)$rowFrag->getH();
                            $lastRowBottom = $currentY;
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
                    // rect = 列区（B 真值 colgroup=行区并集），行区确定后回填。
                    $columnSlots[count($stackedChildren)] = $cr;
                    $stackedChildren[] = $cr; // 占位（保文档序）
                } else {
                    // caption（top）等非行子：盒与内容平移到当前流位置（CSS 2.2 §17.4；
                    // 预布局坐标残留同 cell 族缺陷）。
                    $dxE = (int)$x - (int)($cr->x ?? 0);
                    $dyE = (int)$currentY - (int)($cr->y ?? 0);
                    $stackedChildren[] = ($dxE !== 0 || $dyE !== 0) ? FlexAlgorithm::translateFragmentTree($cr, $dxE, $dyE) : $cr;
                    $currentY += (int)($cr->h ?? 0);
                }
            }
            // bottom caption 几何回填：平移到全部行之后（§17.4.1；caption 属
            // table wrapper box，在表盒外不受 border/spacing 内缩，真值
            // caption y = 行底+spacing+下border、x/w=表全宽），替换占位
            foreach ($bottomCaptionSlots as $slotIdx => $cr) {
                $dxE = (int)$x - (int)($cr->x ?? 0);
                $dyE = (int)($currentY + $spacing + $tbB) - (int)($cr->y ?? 0);
                $stackedChildren[$slotIdx] = ($dxE !== 0 || $dyE !== 0) ? FlexAlgorithm::translateFragmentTree($cr, $dxE, $dyE) : $cr;
                $currentY += $spacing + $tbB + (int)($cr->h ?? 0);
                // caption 已吐出表盒底部边缘，表高尾部不再叠加（置零）
                $spacing = 0; $tbB = 0;
            }
            // colgroup/col rect 回填：列区 = 行区并集（x/w 同行 rect，y=首行顶、
            // h=行并集高；B 真值 colgroup [表+3, 710, 35] 与 tr 同 rect）。
            if ($firstRowTop >= 0) {
                $colRectX = (int)($x + $tbL + $spacing);
                $colRectW = (int)max(0, $w - $tbL - $tbR - 2 * $spacing);
                $colRectH = (int)max(0, $lastRowBottom - $firstRowTop);
                foreach ($columnSlots as $slotIdx => $cr) {
                    $stackedChildren[$slotIdx] = new PhysicalFragment($colRectX, (int)$firstRowTop, $colRectW, $colRectH,
                        $colRectW, $colRectH, 0, $colRectW, $colRectH,
                        $cr->style, $cr->children, $cr->sourceNode,
                        0, 0, false,
                        $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles);
                }
            }
        } else {
            $stackedChildren = $children;
        }

        if ($h <= 0) $h = max(0, $currentY + $spacing + $tbB - $y); // 尾部边缘 spacing + 下 border 计入表高

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h, $s->visualWidth($w), $s->visualHeight($h), 0, (int)$w, (int)$h, $s, $stackedChildren, null);
    }

    /**
     * 单行放置：列宽归一（缩放到表内容宽）+ cell 等高（CSS 2.2 §17.5.3）。
     *
     * 行 rect = 表内容区横向收缩（x+bL+spacing、w-边缘-2×spacing，B 真值
     * tr [表+3, 710]）；table-layout:fixed 时 col 声明宽锁定、auto 列均分剩余
     *（§17.5.2.1）。
     */
    private function layoutRow(PhysicalFragment $row, int $x, int $currentY, int $w, array $maxColWidths, int $spacing = 0, int $tbL = 0, int $tbR = 0, array $fixedColWidths = []): PhysicalFragment
    {
        $cellCount = count($row->children);
        $lineH = 0;
        $colWidths = [];
        // 横向可用宽 = 表宽 − 左右 border − (n+1)×spacing（两端边缘 + cell 间隙，§17.6.1）
        $availW = max(0, $w - $tbL - $tbR - ($cellCount + 1) * $spacing);
        $useFixed = false;
        if (!empty($fixedColWidths) && $cellCount > 0) {
            // fixed 模式：声明列锁定，auto（0）列均分剩余（纯整数）
            $declaredSum = 0; $autoCount = 0;
            for ($colI = 0; $colI < $cellCount; $colI++) {
                $fcw = (int)($fixedColWidths[$colI] ?? 0);
                if ($fcw > 0) $declaredSum += $fcw; else $autoCount++;
            }
            if ($declaredSum > 0 && $declaredSum <= $availW) {
                $useFixed = true;
                $autoShare = $autoCount > 0 ? intdiv($availW - $declaredSum, $autoCount) : 0;
                for ($colI = 0; $colI < $cellCount; $colI++) {
                    $fcw = (int)($fixedColWidths[$colI] ?? 0);
                    $colWidths[$colI] = $fcw > 0 ? $fcw : $autoShare;
                }
            }
        }
        if (!$useFixed) {
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
        }

        $cellResults = [];
        $colX = $tbL + $spacing;
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
        return new PhysicalFragment((int)($x + $tbL + $spacing), (int)$currentY, (int)max(0, $w - $tbL - $tbR - 2 * $spacing), (int)$lineH, (int)max(0, $w - $tbL - $tbR - 2 * $spacing), (int)$lineH, 0, (int)max(0, $w - $tbL - $tbR - 2 * $spacing), (int)$lineH,
            $row->style, $normCells, $row->sourceNode,
            0, 0, false,
            $row->type, $row->content, $row->dataset, $row->pseudoStyles);
    }
}
