<?php

namespace Px\Layout;

use native_types;
use Px\Css\ComputedStyle;
use Px\Css\CssKeyword;
use Px\Layout\ConstraintSpace;
use Px\Layout\PhysicalFragment;
use Px\Layout\Grid\GridPlacer;
use Px\Layout\Grid\GridTrack;
use Px\Layout\Grid\GridTracker;
use Px\Layout\Grid\GridItem;

/**
 * GridAlgorithm — CSS Grid 布局算法（自包含实现）
 *
 * 纯函数实现，直接操作 ConstraintSpace/ComputedStyle 参数。
 * 完成两阶段布局：fr 轨道、auto 轨道、NeedsAnotherPass 信号。
 */
class GridAlgorithm extends LayoutAlgorithm
{
    public function layout(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = '', array $childNodes = [], ?PhysicalFragment $inputFragment = null): PhysicalFragment
    {
        // ── Intrinsic measurement mode ──
        if ($space->isIntrinsicMeasurement) {
            $totalW = 0;
            $maxH = 0;
            for ($gxi = 0, $gxlen = count($childNodes); $gxi < $gxlen; $gxi++) {
                $icr = $this->layoutChild($childNodes[$gxi]);
                $totalW += $icr->w;
                if ($icr->h > $maxH) $maxH = $icr->h;
            }
            return new PhysicalFragment((int)$totalW, (int)$maxH, 0, 0, null, null, 0, 0, 0, $style ?? \Px\Css\StylePool::empty());
        }

        $c = $space;
        $s = $style ?? \Px\Css\StylePool::empty();
        // P2: 按需布局子项（对标 Blink：算法通过 LayoutChild 布局子项）
        $childResults = [];
        for ($gxi = 0, $gxlen = count($childNodes); $gxi < $gxlen; $gxi++) {
            $childResults[] = $this->layoutChild($childNodes[$gxi]);
        }

        $parentX = $c->parentContentX;
        $parentY = $c->parentContentY;
        // 对标 Blink：使用 contentWidth（子项可用约束宽度），与 Flex/Block 一致
        $parentW = $c->getContentWidth();

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        // 相对父容器，BlockAlgorithm::stackBlockChildren 处理堆叠
        $x = $left;
        $y = $top;

        // ── Container width ──
        $width = $s->width->toPx();
        if ($s->width->isPercent()) {
            $width = $s->width->resolveInContext($parentW);
        }
        if ($width <= 0) {
            $width = $parentW;
        }
        $width = max(0, $width);

        // ── Container height ──
        $height = $s->height->toPx();
        if ($s->height->isPercent()) {
            $height = $s->height->resolveInContext($c->containerHeight);
        }

        // ── Grid template ──
        $rawCols = $s->getRaw('gridTemplateColumns');
        $rawRows = $s->getRaw('gridTemplateRows');
        $gap = (int)($s->gap?->toPx() ?? $s->getRaw('gap') ?? 0);
        if ($rawCols !== null && !is_string($rawCols)) {
            $rawCols = $rawCols instanceof CssKeyword ? $rawCols->value : (string)$rawCols;
        }
        if ($rawRows !== null && !is_string($rawRows)) {
            $rawRows = $rawRows instanceof CssKeyword ? $rawRows->value : (string)$rawRows;
        }

        // ── grid-auto-rows / grid-auto-columns（对标 Blink NGGridLayoutAlgorithm）──
        // Spec CSS Grid §12.4：隐式行/列尺寸由 grid-auto-rows/columns 控制，
        // 未声明时为 auto（基于内容 max）。
        // 注：css-mappings 中 parser=parsePixels → rawDeclarations 中存储为 CssLength 对象。
        $autoRowSize = 0;
        $rawAutoRows = $s->getRaw('gridAutoRows');
        if ($rawAutoRows !== null) {
            if ($rawAutoRows instanceof \Px\Css\CssLength) {
                $autoRowSize = (int)$rawAutoRows->toPx();
            } else if ($rawAutoRows instanceof CssKeyword) {
                // auto/min-content/max-content 等关键字 → 降级到内容基础
                $autoRowSize = 0;
            } else if (is_numeric($rawAutoRows)) {
                $autoRowSize = (int)$rawAutoRows;
            } else if (is_string($rawAutoRows)) {
                $r = trim($rawAutoRows);
                if (str_ends_with($r, 'px')) $autoRowSize = (int)substr($r, 0, -2);
                else if (ctype_digit($r)) $autoRowSize = (int)$r;
            }
        }

        // ── Compute tracks ──
        $cols = $this->computeTracks($rawCols, $width, $gap);
        $rows = $this->computeTracks($rawRows, $height > 0 ? $height : 0, $gap);

        // ── Safe defaults ──
        if (empty($cols)) {
            $t = new GridTrack();
            $t->size = max(1, (int)($width / 2));
            $t->start = 0;
            $t->end = $t->size;
            $cols = [$t];
        }
        if (empty($rows)) {
            $t = new GridTrack();
            // 隐式行尺寸：优先 grid-auto-rows，否则基于内容 max
            $t->size = $autoRowSize > 0 ? $autoRowSize : $this->estimateAutoRowSize($childResults, count($cols), 0);
            $t->start = 0;
            $t->end = $t->size;
            $rows = [$t];
        }

        // ── Detect auto tracks ──
        $hasAutoCols = false;
        foreach ($cols as $col) {
            if ($col->isAuto) { $hasAutoCols = true; break; }
        }
        $hasAutoRows = false;
        foreach ($rows as $row) {
            if ($row->isAuto) { $hasAutoRows = true; break; }
        }

        // ── Content-based auto track override (pass > 0) ──
        $colContentWidths = [];
        $rowContentHeights = [];
        if ($hasAutoCols) {
            $numCols = count($cols);
            foreach ($childResults as $ci => $cr) {
                $colIdx = $ci % $numCols;
                if (!isset($colContentWidths[$colIdx]) || $cr->w > $colContentWidths[$colIdx]) {
                    $colContentWidths[$colIdx] = $cr->w;
                }
            }
        }
        if ($hasAutoRows) {
            $numCols = count($cols);
            foreach ($childResults as $ri => $cr) {
                $rowIdx = $numCols > 0 ? (int)($ri / $numCols) : 0;
                if (!isset($rowContentHeights[$rowIdx]) || $cr->h > $rowContentHeights[$rowIdx]) {
                    $rowContentHeights[$rowIdx] = $cr->h;
                }
            }
        }

        // ── Build grid items ──
        $gridItems = [];
        $numCols = count($cols);
        $idx = 0;
        $totalItems = count($childResults);
        // 对标 CSS Grid §10.4: grid-auto-flow 控制隐式子项摆放方向
        $rawAutoFlow = $s->getRaw('gridAutoFlow');
        $autoFlowVal = 'row';
        if ($rawAutoFlow !== null) {
            if ($rawAutoFlow instanceof CssKeyword) $autoFlowVal = (string)$rawAutoFlow->value;
            else if (is_string($rawAutoFlow)) $autoFlowVal = $rawAutoFlow;
        }
        $isColumnFlow = (str_contains($autoFlowVal, 'column'));
        // column-flow 需基于行数确定尺寸（确保至少一行）
        $numRowsInit = max(1, count($rows));
        $neededRows = $isColumnFlow
            ? $numRowsInit
            : ($numCols > 0 ? (int)ceil($totalItems / $numCols) : $totalItems);
        // 列优先下：需要的隐式列数 = ceil(totalItems / numRows)
        $neededColsForColumnFlow = $isColumnFlow && $numRowsInit > 0
            ? (int)ceil($totalItems / $numRowsInit)
            : $numCols;
        while (count($rows) < $neededRows) {
            $t = new GridTrack();
            // 隐式行尺寸：优先 grid-auto-rows，否则基于内容 max
            $t->size = $autoRowSize > 0 ? $autoRowSize : $this->estimateAutoRowSize($childResults, count($cols), count($rows));
            $t->start = count($rows) > 0 ? end($rows)->end + $gap : 0;
            $t->end = $t->start + $t->size;
            $rows[] = $t;
        }
        if (count($rows) > 0 && $neededRows > 0) {
            $this->recomputeTrackPositions($rows, $gap);
        }
        $numRows = count($rows);
        foreach ($childResults as $cr) {
            $gi = new GridItem();
            if ($isColumnFlow && $numRows > 0) {
                // column-first placement: fill top-to-bottom, then next column
                $gi->colStart = (int)($idx / $numRows);
                $gi->rowStart = $idx % $numRows;
            } else {
                // row-first (default)
                $gi->colStart = $numCols > 0 ? ($idx % $numCols) : 0;
                $gi->rowStart = $numCols > 0 ? (int)($idx / $numCols) : 0;
            }
            $gi->colEnd = $gi->colStart + 1;
            $gi->rowEnd = $gi->rowStart + 1;
            $safeCol = min($gi->colStart, max(0, $numCols - 1));
            $gi->w = $numCols > 0 ? $cols[$safeCol]->size : 0;
            $gi->h = max(1, $gi->rowStart < $numRows ? $rows[$gi->rowStart]->size : ($autoRowSize > 0 ? $autoRowSize : max(1, (int)($cr->getH() ?? 0))));
            $gi->style = $cr->style;
            $gi->originalChildren = $cr->children;
            $gridItems[] = $gi;
            $idx++;
        }

        // ── Pass 2: 用确定的轨道约束重新布局子项（对标 Blink GridAlgorithm 两阶段） ──
        // Blink: 轨道尺寸确定后，用 track size 作为子项约束重新 LayoutChild
        $idx2 = 0;
        foreach ($gridItems as $gri2) {
            $trackW = max(0, (int)($gri2->w ?? 0));
            $trackH = max(0, (int)($gri2->h ?? 0));
            if ($trackW > 0 && $idx2 < count($childNodes)) {
                // 构建轨道约束：用 track width 作为子项可用宽度
                $trackSpace = new ConstraintSpace(
                    $trackW, $trackH > 0 ? $trackH : $c->getContentHeight(),
                    $c->getParentContentX(), $c->getParentContentY(),
                    $trackW, $trackH > 0 ? $trackH : $c->getContentHeight(),
                    $trackW, $c->getPercentageHeight(),
                    0, 0, 0, 0, 0, 0, 0, 0,
                    true, false, 'block',
                    $trackW, $c->getPercentageHeight(),
                );
                $reFrag = $this->layoutChild($childNodes[$idx2], $trackSpace);
                $childResults[$idx2] = $reFrag;
                $gri2->originalChildren = $reFrag->children;
            }
            $idx2++;
        }

        // ── Place items ──
        $placer = new GridPlacer();
        $placer->placeItems($gridItems, $cols, $rows, 'row', 0, 0, $x, $y, $width, $height, 'start', 'start', $gap, $gap);

        // ── Map grid items to child PhysicalFragments ──
        $mappedFragments = [];
        $giIdx = 0;
        foreach ($gridItems as $gri) {
            $gh = max(0, (int)($gri->h ?? 0));$gw = max(0, (int)($gri->w ?? 0));
            // Adjust children positions when grid track width differs from original fragment width
            $origFrag = $childResults[$giIdx] ?? null;
            $oldW = $origFrag !== null ? (int)$origFrag->getW() : 0;
            $oldH = $origFrag !== null ? (int)$origFrag->getH() : 0;
            $newW = $gw;
            $children = $gri->originalChildren ?? [];

            // 对标 Blink: justify-self 控制子项在 grid area 内的内联轴对齐
            $itemX = (int)($gri->x ?? 0);
            $itemW = $gw; // 默认用轨道宽度
            $justifySelf = $gri->style?->justifySelf?->value ?? 'auto';
            if ($justifySelf === 'auto') {
                $justifySelf = $s->getRaw('justifyItems') ?? 'stretch';
                if (!is_string($justifySelf)) { $justifySelf = 'stretch'; }
            }
            // 从子项样式读取显式宽度（对标 Blink：justify-self 基于子项自身尺寸）
            $explicitW = 0;
            if ($gri->style !== null) {
                $wVal = $gri->style->width;
                if ($wVal !== null && !$wVal->isPercent() && $wVal->toPx() > 0) {
                    $explicitW = (int)$wVal->toPx();
                }
            }
            // 当子项有显式宽度且小于轨道时，应用 justify-self
            if ($explicitW > 0 && $explicitW < $gw && $justifySelf !== 'stretch') {
                $itemW = $explicitW;
                if ($justifySelf === 'center') {
                    $itemX += (int)(($gw - $explicitW) / 2);
                } elseif ($justifySelf === 'end' || $justifySelf === 'flex-end') {
                    $itemX += ($gw - $explicitW);
                }
            } elseif ($oldW > 0 && $oldW < $gw && $justifySelf !== 'stretch') {
                $itemW = $oldW;
                if ($justifySelf === 'center') {
                    $itemX += (int)(($gw - $oldW) / 2);
                } elseif ($justifySelf === 'end' || $justifySelf === 'flex-end') {
                    $itemX += ($gw - $oldW);
                }
            }

            if ($oldW > 0 && $newW > 0 && $oldW !== $newW && !empty($children)) {
                $childJustify = $gri->style?->justifyContent?->value ?? 'flex-start';
                $adjusted = [];
                foreach ($children as $ch) {
                    $chNewX = (int)$ch->getX();
                    if ($childJustify === 'center') {
                        $chNewX += (int)(($newW - $oldW) / 2);
                    } elseif ($childJustify === 'flex-end' || $childJustify === 'end') {
                        $chNewX += $newW - $oldW;
                    }
                    $adjusted[] = new PhysicalFragment(
                        $chNewX, (int)$ch->getY(),
                        (int)$ch->getW(), (int)$ch->getH(),
                        (int)$ch->getVisualW(), (int)$ch->getVisualH(),
                        (int)$ch->getLayer(),
                        (int)$ch->getContentWidth(), (int)$ch->getContentHeight(),
                        $ch->style, $ch->children ?? [], $ch->sourceNode
                    );
                }
                $children = $adjusted;
            }
            $firstChild = count($children) > 0 ? $children[0] : null;
            // grid cell fragment 代表原 div，元数据（type/content/sourceNode/dataset/pseudoStyles）
            // 取自原 fragment（$origFrag）而非第一个子节点——文本型 div 的内容存在
            // $origFrag->content（无子 fragment），取 firstChild 会丢失 content。
            // 对标 Blink NGGridLayoutAlgorithm：mapping fragment 需保留原子项的滚动状态与内容尺寸
            $origIsScroll = $origFrag?->isScrollContainer ?? false;
            $origScrollTop = (int)($origFrag?->scrollTop ?? 0);
            $origScrollLeft = (int)($origFrag?->scrollLeft ?? 0);
            $origContentW = (int)($origFrag?->contentWidth ?? 0);
            $origContentH = (int)($origFrag?->contentHeight ?? 0);
            $mappedFragments[] = new PhysicalFragment($itemX, (int)($gri->y ?? 0), $itemW, $gh, (int)($gri->style?->visualWidth($itemW) ?? $itemW), (int)($gri->style?->visualHeight($gh) ?? $gh), 0, $origContentW, $origContentH, $gri->style, $children, $origFrag?->sourceNode,
                $origScrollTop, $origScrollLeft, $origIsScroll,
                $origFrag?->type ?? '', $origFrag?->content, $origFrag?->dataset ?? [], $origFrag?->pseudoStyles ?? []);
            $giIdx++;
        }

        // ── Auto-height from content ──
        if ($height <= 0 && count($mappedFragments) > 0) {
            $maxBottom = $y;
            foreach ($mappedFragments as $cr) {
                $bottom = $cr->y + $cr->h;
                if ($bottom > $maxBottom) $maxBottom = $bottom;
            }
            $height = max(0, $maxBottom - $y);
        }

        // ── Build PhysicalFragment ──
        return new PhysicalFragment((int)$x, (int)$y, (int)$width, (int)$height, (int)$s->visualWidth($width), (int)$s->visualHeight($height), 0, 0, 0, $s, $mappedFragments, null);
    }

    /**
     * Compute track sizes using GridTracker for full CSS Grid spec support.
     */
    private function computeTracks(?string $raw, int $containerSize, int $gap = 0): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        return GridTracker::computeTracks(trim($raw), $containerSize, $gap);
    }

    /**
     * Recompute start/end positions for tracks after size changes.
     * @param GridTrack[] $tracks
     */
    private function recomputeTrackPositions(array &$tracks, int $gap): void
    {
        $pos = 0;
        foreach ($tracks as $t) {
            $t->start = $pos;
            $pos += max(0, $t->size);
            $t->end = $pos;
            $pos += $gap;
        }
    }

    /**
     * Estimate auto row size from child fragment heights.
     */
    private function estimateAutoRowSize(array $childResults, int $numCols, int $rowIdx): int
    {
        // CSS Grid §12.4: auto track size = max-content of items in that track
        $maxH = 0;
        $start = $rowIdx * $numCols;
        for ($i = $start; $i < $start + $numCols && $i < count($childResults); $i++) {
            $cr = $childResults[$i];
            $h = (int)($cr->getH() ?? 0);
            if ($h <= 0) $h = (int)($cr->getVisualH() ?? 0);
            if ($h > $maxH) $maxH = $h;
        }
        return max(1, $maxH);
    }
}
