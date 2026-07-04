<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\Flex\FlexItem;
use Px\Rendering\Layout\Flex\FlexLineBreaker;
use Px\Rendering\Layout\Flex\FlexDistributor;
use Px\Rendering\Layout\Flex\FlexFragmentMapper;
use Px\Rendering\CssLength;

/**
 * FlexLayoutStrategy — Flex 布局策略
 *
 * Pure function 实现：layout(LayoutInput) → LayoutResult。
 * 不接收 RenderNode，不产生副作用。
 */
class FlexLayoutStrategy implements LayoutStrategyInterface
{
    public function layout(LayoutInput $input): LayoutResult
    {
        $c = $input->constraints;
        $s = $input->style;
        $childResults = $input->childResults;

        $parentX = $c->parentContentX;
        $parentY = $c->parentContentY;
        $parentW = $c->contentWidth;

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $x = $parentX + $left;
        $y = $parentY + $top;

        $w = $s->width->toPx();
        if ($w <= 0) $w = $parentW;
        $h = $s->height->toPx();

        $flexItems = [];
        $flexItemData = [];

        foreach ($childResults as $i => $cr) {
            $cs = $cr->style;
            if ($cs === null) continue;

            $grow = (float)($cs->getRaw('flexGrow') ?? 0);
            $shrink = (float)($cs->getRaw('flexShrink') ?? 1);
            $rawBasis = $cs->getRaw('flexBasis');
            $basis = -1;
            if ($rawBasis instanceof CssLength && !$rawBasis->isAuto()) {
                $basis = $rawBasis->toPx();
            }
            $isFlexGrow = ($grow > 0);

            $mL = $cs->margin?->left->toPx() ?? 0;
            $mR = $cs->margin?->right->toPx() ?? 0;
            $mT = $cs->margin?->top->toPx() ?? 0;
            $mB = $cs->margin?->bottom->toPx() ?? 0;

            $item = new FlexItem();
            $item->grow = $grow;
            $item->shrink = $shrink;
            $item->basis = $basis;
            $item->isFlexGrow = $isFlexGrow;
            $item->marginBefore = 0;
            $item->marginAfter = 0;
            $item->marginCrossBefore = 0;
            $item->marginCrossAfter = 0;
            $item->hasExplicitCrossSize = false;
            $item->originalChildren = $cr->children;

            $flexItems[] = $item;
            $flexItemData[] = [
                'grow' => $grow,
                'shrink' => $shrink,
                'basis' => $basis,
                'isFlexGrow' => $isFlexGrow,
                'hasExplicitCrossSize' => false,
                'crossAxisSized' => false,
                'marginLeft' => $mL,
                'marginRight' => $mR,
                'marginTop' => $mT,
                'marginBottom' => $mB,
            ];
        }

        $wrap = $s->getRaw('flexWrap');
        $isWrapping = ($wrap === 'wrap' || $wrap === 'wrap-reverse');
        $isRow = ($s->getRaw('flexDirection') !== 'column');
        $breaker = new FlexLineBreaker();
        $lines = $breaker->breakLines($flexItems, $flexItemData, $isWrapping, $isRow, $w, 0);

        $distributor = new FlexDistributor();
        // Simplified: apply results via mapper without full distribution
        $mappedResults = FlexFragmentMapper::toResults($flexItems, $childResults);

        if ($h <= 0 && count($mappedResults) > 0) {
            $maxBottom = $y;
            foreach ($mappedResults as $cr) {
                $bottom = $cr->y + $cr->h;
                if ($bottom > $maxBottom) $maxBottom = $bottom;
            }
            $h = max(0, $maxBottom - $y);
        }

        return new LayoutResult(
            x: $x, y: $y, w: $w, h: $h,
            visualW: $s->visualWidth($w),
            visualH: $s->visualHeight($h),
            style: $s,
            children: $mappedResults,
        );
    }
}
