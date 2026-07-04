<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\Grid\GridPlacer;
use Px\Rendering\Layout\Grid\GridItem;
use Px\Rendering\CssLength;
use Px\Rendering\CssKeyword;

/**
 * GridLayoutStrategy — CSS Grid 布局策略
 *
 * Pure function 实现：layout(LayoutInput) → LayoutResult。
 * 不接收 RenderNode，不产生副作用。
 */
class GridLayoutStrategy implements LayoutStrategyInterface
{
    public function layout(LayoutInput $input): LayoutResult
    {
        // Intrinsic measurement mode
        if ($input->constraints->isIntrinsicMeasurement) {
            return new LayoutResult(w: 0, h: 0, minContentWidth: 0, maxContentWidth: 99999, preferredContentWidth: 0, minContentHeight: 0, maxContentHeight: 99999, preferredContentHeight: 0);
        }

        $c = $input->constraints;
        $s = $input->style;
        $childResults = $input->childResults;

        $parentX = $c->parentContentX;
        $parentY = $c->parentContentY;
        $parentW = $c->containerWidth;

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $x = $parentX + $left;
        $y = $parentY + $top;

        // Compute width
        $width = $s->width->toPx();
        if ($s->width->isPercent()) {
            $width = $s->width->resolveInContext($parentW);
        }
        if ($width <= 0) {
            $width = $parentW;
        }
        $width = max(0, $width);

        // Compute height
        $height = $s->height->toPx();
        if ($s->height->isPercent()) {
            $height = $s->height->resolveInContext($c->containerHeight);
        }

        $rawCols = $s->getRaw('gridTemplateColumns');
        $rawRows = $s->getRaw('gridTemplateRows');
        $gap = (int)($s->getRaw('gap') ?? 0);
        $cols = $this->parseTrackSizes($rawCols, $width, $gap);
        $rows = $this->parseTrackSizes($rawRows, $height > 0 ? $height : 0, $gap);

        if (empty($cols)) { $t = new \Px\Rendering\Layout\Grid\GridTrack(); $t->size = max(1, (int)($width / 2)); $t->start = 0; $t->end = $t->size; $cols = [$t]; }
        if (empty($rows)) { $t = new \Px\Rendering\Layout\Grid\GridTrack(); $t->size = 50; $t->start = 0; $t->end = 50; $rows = [$t]; }

        $gridItems = [];
        $numCols = count($cols);
        $idx = 0;
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

        $placer = new GridPlacer();
        $placer->placeItems($gridItems, $cols, $rows, 'row', 0, 0, $x, $y, $width, $height, 'start', 'start', $gap, $gap);

        $mappedResults = [];
        foreach ($gridItems as $gi) {
            $mappedResults[] = new LayoutResult(x: $gi->x, y: $gi->y, w: $gi->w, h: $gi->h, style: $gi->style, children: $gi->originalChildren ?? []);
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
        );
    }

    private function parseTrackSizes(?string $raw, int $containerSize, int $gap = 0): array
    {
        if ($raw === null || trim($raw) === '') { return []; }
        $raw = trim($raw);
        $sizes = [];
        if (preg_match('/^repeat\s*\(\s*(\d+)\s*,\s*(.+)\s*\)$/s', $raw, $m)) {
            $count = (int)$m[1];
            $sizeStr = trim($m[2]);
            $px = 0;
            if (preg_match('/^(\d+)px$/', $sizeStr, $sm)) { $px = (int)$sm[1]; }
            elseif (preg_match('/^(\d+)%$/', $sizeStr, $sm) && $containerSize > 0) { $px = (int)($containerSize * (int)$sm[1] / 100); }
            else { $px = (int)$sizeStr; }
            for ($i = 0; $i < $count; $i++) { $sizes[] = $px; }
        } else {
            $parts = preg_split('/\s+/', $raw);
            foreach ($parts as $p) {
                $p = trim($p); if ($p === '') { continue; }
                $px = 0;
                if (preg_match('/^(\d+)px$/', $p, $m)) { $px = (int)$m[1]; }
                elseif (preg_match('/^(\d+)%$/', $p, $m) && $containerSize > 0) { $px = (int)($containerSize * (int)$m[1] / 100); }
                else { $px = (int)$p; }
                if ($px > 0) { $sizes[] = $px; }
            }
        }
        // Convert to GridTrack[] with gap baked into positions
        $tracks = [];
        $pos = 0;
        foreach ($sizes as $sz) {
            $t = new \Px\Rendering\Layout\Grid\GridTrack();
            $t->size = $sz;
            $t->start = $pos;
            $pos += $sz;
            $t->end = $pos;
            $pos += $gap;
            $tracks[] = $t;
        }
        return $tracks;
    }
}
