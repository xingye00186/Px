<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\CssLength;

class AbsolutePositioning implements AbsoluteStrategy
{
    public function absoluteLayout(LayoutInput $input): LayoutResult
    {
        $c = $input->constraints;
        $s = $input->style;

        $leftVal = $s->left?->toPx() ?? 0;
        $topVal = $s->top?->toPx() ?? 0;
        $rightVal = $s->right?->toPx() ?? 0;
        $bottomVal = $s->bottom?->toPx() ?? 0;

        $ancW = $input->ancestorW ?? $c->containerWidth;
        $ancH = $input->ancestorH ?? $c->containerHeight;
        $ancX = $input->ancestorX ?? 0;
        $ancY = $input->ancestorY ?? 0;
        $bL = $input->ancestorBorderLeft;
        $bT = $input->ancestorBorderTop;

        $width = $s->width?->toPx() ?? 0;
        $height = $s->height?->toPx() ?? 0;
        if ($s->width?->isPercent()) $width = $s->width->resolveInContext($ancW);
        if ($s->height?->isPercent()) $height = $s->height->resolveInContext($ancH);

        $marginLeft = $s->margin?->left->toPx() ?? 0;
        $marginTop = $s->margin?->top->toPx() ?? 0;
        $marginRight = $s->margin?->right->toPx() ?? 0;
        $marginBottom = $s->margin?->bottom->toPx() ?? 0;

        if ($leftVal !== 0 && $rightVal !== 0 && $width <= 0) {
            $width = max(0, $ancW - $leftVal - $rightVal - $marginLeft - $marginRight);
        }
        if ($topVal !== 0 && $bottomVal !== 0 && $height <= 0) {
            $height = max(0, $ancH - $topVal - $bottomVal - $marginTop - $marginBottom);
        }

        $textContent = $input->textContent;
        if (($width <= 0 || $height <= 0) && strlen($textContent) > 0) {
            $fs = $s->fontSize ?? 14;
            $bd = $s->bold ?? false;
            $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($textContent, $fs, $bd) : 0);
            if ($measured > 0) {
                if ($width <= 0) {
                    $width = max(0, $measured + ($s->padding?->left->toPx() ?? 0) + ($s->padding?->right->toPx() ?? 0) + ($s->borderLeftWidth ?? 0) + ($s->borderRightWidth ?? 0));
                }
            }
            if ($height <= 0) {
                $height = max($s->lineHeight ?? (int)($fs * 1.2), $height);
            }
        }

        $hasLeft = $s->getRaw('left') !== null;
        $hasRight = $s->getRaw('right') !== null;
        $hasTop = $s->getRaw('top') !== null;
        $hasBottom = $s->getRaw('bottom') !== null;

        $cbOriginX = $ancX + $bL;
        $cbOriginY = $ancY + $bT;

        $calcX = $cbOriginX + $leftVal + $marginLeft;
        if ($hasRight && !$hasLeft) {
            $calcX = $cbOriginX + $ancW - $rightVal - ($width > 0 ? $width : 0);
        }

        $calcY = $cbOriginY + $topVal + $marginTop;
        if ($hasBottom && !$hasTop) {
            $calcY = $cbOriginY + $ancH - $bottomVal - ($height > 0 ? $height : 0);
        }

        $rawTX = $s->getRaw('translateX');
        $rawTY = $s->getRaw('translateY');
        $calcX += $rawTX instanceof CssLength ? $rawTX->toPx() : (int)($rawTX ?? 0);
        $calcY += $rawTY instanceof CssLength ? $rawTY->toPx() : (int)($rawTY ?? 0);

        $autoOffsetX = $this->computeMarginAutoOffsetX($s, max(0, $width), $ancW);
        $calcX += $autoOffsetX;

        $nodeW = max(0, $width);
        $nodeH = max(0, $height);

        return new LayoutResult(x: $calcX, y: $calcY, w: $nodeW, h: $nodeH, visualW: $s->visualWidth($nodeW), visualH: $s->visualHeight($nodeH), layer: 1, style: $s, children: []);
    }

    private function computeMarginAutoOffsetX(ComputedStyle $s, int $nodeW, int $parentContentW): int
    {
        $isMarginLeftAuto = false;
        $isMarginRightAuto = false;
        $rawMargin = $s->getRaw('margin');
        if (is_string($rawMargin) && strtolower(trim($rawMargin)) === 'auto') {
            $isMarginLeftAuto = true;
            $isMarginRightAuto = true;
        }
        if ($s->getRaw('marginLeftAuto') ?? false) $isMarginLeftAuto = true;
        if ($s->getRaw('marginRightAuto') ?? false) $isMarginRightAuto = true;
        if ($isMarginLeftAuto && $isMarginRightAuto && $nodeW > 0 && $parentContentW > $nodeW) {
            return (int)(($parentContentW - $nodeW + 1) / 2);
        } elseif ($isMarginLeftAuto && !$isMarginRightAuto && $parentContentW > $nodeW) {
            return $parentContentW - $nodeW;
        }
        return 0;
    }
}
