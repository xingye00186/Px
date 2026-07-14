<?php

namespace Px\Layout;

use native_types;
use Px\Css\ComputedStyle;
use Px\Css\CssKeyword;
use Px\Layout\ConstraintSpace;
use Px\Layout\PhysicalFragment;
use Px\Layout\IntrinsicSizes;
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
    public function layout(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = '', array $childNodes = [], array $childFragments = [], ?PhysicalFragment $inputFragment = null): PhysicalFragment
    {
        // ── Intrinsic measurement mode ──
        if ($space->isIntrinsicMeasurement) {
            $totalW = 0;
            $maxH = 0;
            foreach ($childFragments as $cr) {
                $totalW += $cr->w;
                if ($cr->h > $maxH) $maxH = $cr->h;
            }
            return new PhysicalFragment((int)$totalW, (int)$maxH, 0, 0, null, null, 0, 0, 0, $style ?? new ComputedStyle([]));
        }

        $c = $space;
        $s = $style ?? new ComputedStyle([]);
        $childResults = $childFragments;
        $iteration = 0;

        $parentX = $c->parentContentX;
        $parentY = $c->parentContentY;
        $parentW = $c->containerWidth;

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
            $t->size = 50;
            $t->start = 0;
            $t->end = 50;
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
        $hasAuto = $hasAutoCols || $hasAutoRows;

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

        // ── Apply content-based sizes to auto tracks (iteration > 0) ──
        if ($iteration > 0) {
            if ($hasAutoCols) {
                foreach ($cols as $ci => $col) {
                    if ($col->isAuto && isset($colContentWidths[$ci])) {
                        $col->size = max(0, (int)$colContentWidths[$ci]);
                        $col->start = 0;
                        $col->end = $col->size;
                    }
                }
            }
            if ($hasAutoRows) {
                foreach ($rows as $ri => $row) {
                    if ($row->isAuto && isset($rowContentHeights[$ri])) {
                        $row->size = max(0, (int)$rowContentHeights[$ri]);
                        $row->start = 0;
                        $row->end = $row->size;
                    }
                }
            }
            $this->recomputeTrackPositions($cols, $gap);
            $this->recomputeTrackPositions($rows, $gap);
        }

        // ── Build grid items ──
        $gridItems = [];
        $numCols = count($cols);
        $idx = 0;
        $totalItems = count($childResults);
        $neededRows = $numCols > 0 ? (int)ceil($totalItems / $numCols) : $totalItems;
        while (count($rows) < $neededRows) {
            $t = new GridTrack();
            $t->size = 50;
            $t->start = count($rows) > 0 ? end($rows)->end + $gap : 0;
            $t->end = $t->start + $t->size;
            $rows[] = $t;
        }
        if (count($rows) > 0 && $neededRows > 0) {
            $this->recomputeTrackPositions($rows, $gap);
        }
        foreach ($childResults as $cr) {
            $gi = new GridItem();
            $gi->colStart = $idx % $numCols;
            $gi->colEnd = $gi->colStart + 1;
            $gi->rowStart = (int)($idx / $numCols);
            $gi->rowEnd = $gi->rowStart + 1;
            $gi->w = $cols[$gi->colStart]->size;
            $gi->h = max(1, $gi->rowStart < count($rows) ? $rows[$gi->rowStart]->size : 50);
            $gi->style = $cr->style;
            $gi->originalChildren = $cr->children;
            $gridItems[] = $gi;
            $idx++;
        }

        // ── Place items ──
        $placer = new GridPlacer();
        $placer->placeItems($gridItems, $cols, $rows, 'row', 0, 0, $x, $y, $width, $height, 'start', 'start', $gap, $gap);

        // ── Needs another pass? (Pass 0 → Pass 1 for auto tracks) ──
        $needsMore = false;
        if ($hasAuto && $iteration === 0) {
            $needsAdjustment = false;
            foreach ($cols as $ci => $col) {
                if ($col->isAuto && isset($colContentWidths[$ci]) && $colContentWidths[$ci] > $col->size) {
                    $needsAdjustment = true;
                    break;
                }
            }
            if ($needsAdjustment) {
                $needsMore = true;
            }
        }

        // ── Map grid items to child PhysicalFragments ──
        $mappedFragments = [];
        foreach ($gridItems as $gri) {
            $gh = max(0, (int)($gri->h ?? 0));$gw = max(0, (int)($gri->w ?? 0));$mappedFragments[] = new PhysicalFragment((int)($gri->x ?? 0), (int)($gri->y ?? 0), $gw, $gh, (int)($gri->style?->visualWidth($gw) ?? $gw), (int)($gri->style?->visualHeight($gh) ?? $gh), 0, 0, 0, $gri->style, $gri->originalChildren ?? [], null);
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

    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        if ($space->isIntrinsicMeasurement) {
            return new IntrinsicSizes(0, 0, 0, 0);
        }
        // Return style-derived dimensions as intrinsic sizes
        $s = $style ?? new ComputedStyle([]);
        $parentW = $space->containerWidth;
        $width = $s->width->toPx();
        if ($s->width->isPercent()) {
            $width = $s->width->resolveInContext($parentW);
        }
        if ($width <= 0) {
            $width = $parentW;
        }
        $width = max(0, $width);
        return new IntrinsicSizes($width, $width, 0, 0);
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
        $maxH = 50;
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
