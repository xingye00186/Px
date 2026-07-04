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

        // Build GridItems from childResults
        $gridItems = [];
        foreach ($childResults as $cr) {
            $gi = new GridItem();
            $gi->x = $cr->x;
            $gi->y = $cr->y;
            $gi->w = $cr->w;
            $gi->h = $cr->h;
            $gi->style = $cr->style;
            $gridItems[] = $gi;
        }

        // Simple grid: lay items out sequentially by rows
        // Full GridPlacer integration requires column/row track parsing from style
        $mappedResults = [];
        $cellW = count($gridItems) > 0 ? (int)($width / count($gridItems)) : $width;
        foreach ($gridItems as $i => $gi) {
            $mappedResults[] = new LayoutResult(
                x: $x + ($i * $cellW), y: $y,
                w: $cellW, h: $gi->h > 0 ? $gi->h : 50,
                layer: 0,
                style: $gi->style,
                children: [],
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
        );
    }
}
