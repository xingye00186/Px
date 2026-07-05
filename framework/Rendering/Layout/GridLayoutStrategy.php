<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\Grid\GridPlacer;
use Px\Rendering\Layout\Grid\GridTrack;
use Px\Rendering\Layout\Grid\GridTracker;
use Px\Rendering\Layout\Grid\GridItem;
use Px\Rendering\CssLength;
use Px\Rendering\CssKeyword;

/**
 * GridLayoutStrategy — CSS Grid 布局策略
 *
 * Pure function 实现：layout(LayoutInput) → LayoutResult。
 * 不接收 RenderNode，不产生副作用。
 *
 * 多阶段支持：
 * - fr 轨道：GridTracker 确定性分配（容器剩余空间按比例）
 * - auto 轨道：Pass 0 用默认尺寸布局，收集内容宽度 →
 *   needsAnotherPass → Pass 1 以内容宽度重算轨道 → 重新布局
 */
class GridLayoutStrategy implements LayoutStrategyInterface
{
    public function layout(LayoutInput $input): LayoutResult
    {
        // Intrinsic measurement mode
        if ($input->constraints->isIntrinsicMeasurement) {
            $totalW = 0; $maxH = 0;
            foreach ($input->childResults as $cr) { $totalW += $cr->w; if ($cr->h > $maxH) $maxH = $cr->h; }
            return new LayoutResult(w: $totalW, h: $maxH, minContentWidth: $totalW, maxContentWidth: $totalW, preferredContentWidth: $totalW, minContentHeight: $maxH, maxContentHeight: $maxH, preferredContentHeight: $maxH);
        }

        $c = $input->constraints;
        $s = $input->style;
        $childResults = $input->childResults;
        $iteration = $input->iteration;

        $parentX = $c->parentContentX;
        $parentY = $c->parentContentY;
        $parentW = $c->containerWidth;

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $x = $parentX + $left;
        $y = $parentY + $top;

        // Compute container width
        $width = $s->width->toPx();
        if ($s->width->isPercent()) {
            $width = $s->width->resolveInContext($parentW);
        }
        if ($width <= 0) {
            $width = $parentW;
        }
        $width = max(0, $width);

        // Compute container height
        $height = $s->height->toPx();
        if ($s->height->isPercent()) {
            $height = $s->height->resolveInContext($c->containerHeight);
        }

        $rawCols = $s->getRaw('gridTemplateColumns');
        $rawRows = $s->getRaw('gridTemplateRows');
        $gap = (int)($s->getRaw('gap') ?? 0);
        if ($rawCols !== null && !is_string($rawCols)) {
            $rawCols = $rawCols instanceof \Px\Rendering\CssKeyword ? $rawCols->value : (string)$rawCols;
        }
        if ($rawRows !== null && !is_string($rawRows)) {
            $rawRows = $rawRows instanceof \Px\Rendering\CssKeyword ? $rawRows->value : (string)$rawRows;
        }

        // Use GridTracker for full fr/auto/px/%/minmax support
        $cols = $this->computeTracks($rawCols, $width, $gap);
        $rows = $this->computeTracks($rawRows, $height > 0 ? $height : 0, $gap);

        // Safe defaults
        if (empty($cols)) {
            $t = new GridTrack(); $t->size = max(1, (int)($width / 2)); $t->start = 0; $t->end = $t->size; $cols = [$t];
        }
        if (empty($rows)) {
            $t = new GridTrack(); $t->size = 50; $t->start = 0; $t->end = 50; $rows = [$t];
        }

        // Detect auto tracks that need content-based sizing
        $hasAutoCols = false;
        foreach ($cols as $col) { if ($col->isAuto) { $hasAutoCols = true; break; } }
        $hasAutoRows = false;
        foreach ($rows as $row) { if ($row->isAuto) { $hasAutoRows = true; break; } }
        $hasAuto = $hasAutoCols || $hasAutoRows;

        // ── Content-based auto track override (pass > 0) ──
        // On pass > 0, childResults have been re-resolved by LayoutResolver
        // with proper content sizes. Use max per-column/row widths to override auto tracks.
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

        // On iteration > 0, apply content-based sizes to auto tracks
        if ($iteration > 0) {
            if ($hasAutoCols) {
                foreach ($cols as $ci => $col) {
                    if ($col->isAuto && isset($colContentWidths[$ci])) {
                        $col->size = max(0, (int)$colContentWidths[$ci]);
                        $col->start = 0; $col->end = $col->size; // positions recomputed below
                    }
                }
            }
            if ($hasAutoRows) {
                foreach ($rows as $ri => $row) {
                    if ($row->isAuto && isset($rowContentHeights[$ri])) {
                        $row->size = max(0, (int)$rowContentHeights[$ri]);
                        $row->start = 0; $row->end = $row->size;
                    }
                }
            }
            // Recompute track positions after size changes
            $this->recomputeTrackPositions($cols, $gap);
            $this->recomputeTrackPositions($rows, $gap);
        }

        // Build grid items
        $gridItems = [];
        $numCols = count($cols);
        $idx = 0;
        // Ensure enough rows exist for all items
        $totalItems = count($childResults);
        $neededRows = $numCols > 0 ? (int)ceil($totalItems / $numCols) : $totalItems;
        while (count($rows) < $neededRows) {
            $t = new GridTrack();
            $t->size = 50; // default auto row size
            $t->start = count($rows) > 0 ? end($rows)->end + $gap : 0;
            $t->end = $t->start + $t->size;
            $rows[] = $t;
        }
        // Recompute row positions if we added rows
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
            $gi->h = $gi->rowStart < count($rows) ? $rows[$gi->rowStart]->size : 50;
            $gi->style = $cr->style;
            $gi->originalChildren = $cr->children;
            $gridItems[] = $gi;
            $idx++;
        }

        // Place items
        $placer = new GridPlacer();
        $placer->placeItems($gridItems, $cols, $rows, 'row', 0, 0, $x, $y, $width, $height, 'start', 'start', $gap, $gap);

        // Content-based auto track sizing (Pass 0 → Pass 1)
        $needsMore = false;
        if ($hasAuto && $iteration === 0) {
            // Check if any auto column needs adjustment (content wider than default)
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

        // Map to LayoutResult
        $mappedResults = [];
        foreach ($gridItems as $gri) {
            $mappedResults[] = new LayoutResult(
                x: $gri->x, y: $gri->y, w: $gri->w, h: $gri->h,
                style: $gri->style, children: $gri->originalChildren ?? []
            );
        }

        // Auto-height from content
        if ($height <= 0 && count($mappedResults) > 0) {
            $maxBottom = $y;
            foreach ($mappedResults as $cr) {
                $bottom = $cr->y + $cr->h;
                if ($bottom > $maxBottom) $maxBottom = $bottom;
            }
            $height = max(0, $maxBottom - $y);
        }

        return new LayoutResult(
            x: $x, y: $y, w: $width, h: $height,
            visualW: $s->visualWidth($width),
            visualH: $s->visualHeight($height),
            style: $s,
            children: $mappedResults,
            needsAnotherPass: $needsMore,
        );
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
}
