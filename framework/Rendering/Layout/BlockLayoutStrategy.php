<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\CssLength;

/**
 * BlockLayoutStrategy — Block 布局策略
 *
 * Pure function 实现：layout(LayoutInput) → LayoutResult。
 * 不接收 RenderNode，不产生副作用。
 */
class BlockLayoutStrategy implements LayoutStrategyInterface
{
    /** HTML inline elements: width should be text-measured, not container-filled */
    private const INLINE_TYPES = ['#text','text','span','b','strong','em','i','code','br','a','label','abbr','cite','dfn','kbd','mark','q','samp','small','sub','sup','time','var'];

    private static function isInlineType(string $type): bool
    {
        return in_array($type, self::INLINE_TYPES, true);
    }

    public function layout(LayoutInput $input): LayoutResult
    {
        $c = $input->constraints;
        $s = $input->style;
        $children = $input->childResults;
        $textContent = $input->textContent;

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $parentW = $c->containerWidth;
        $parentH = $c->containerHeight;

        $w = $this->computeBlockWidth($parentW, $s, $textContent);
        $h = $this->computeBlockHeight($parentH, $s, $textContent);

        $positionVal = $s->position?->value ?? 'static';
        $isStaticOrRelative = ($positionVal === 'static' || $positionVal === 'relative');
        $x = $isStaticOrRelative ? ($c->parentContentX + $left) : $c->parentContentX;
        $y = $isStaticOrRelative ? ($c->parentContentY + $top) : $c->parentContentY;

        $displayVal = $s->display?->value ?? 'block';
        $stackedChildren = [];
        if (count($children) > 0 && $displayVal === 'block') {
            $stackedChildren = $this->stackBlockChildren(
                $x, $y, $w, $s, $children, $textContent, $parentW
            );
        } else {
            foreach ($children as $cr) {
                $stackedChildren[] = new LayoutResult(
                    x: $cr->x, y: $cr->y,
                    w: $cr->w, h: $cr->h,
                    visualW: $cr->visualW, visualH: $cr->visualH,
                    layer: $cr->layer,
                    contentWidth: $cr->contentWidth,
                    contentHeight: $cr->contentHeight,
                    style: $cr->style,
                    children: $cr->children,
                );
            }
        }

        if ($h <= 0 && count($stackedChildren) > 0) {
            $maxBottom = $y;
            foreach ($stackedChildren as $cr) {
                $bottom = $cr->y + $cr->h;
                if ($bottom > $maxBottom) $maxBottom = $bottom;
            }
            $h = max(0, $maxBottom - $y);
        }

        return new LayoutResult(
            x: $x, y: $y, w: $w, h: $h,
            visualW: $s->visualWidth($w),
            visualH: $s->visualHeight($h),
            contentWidth: $w,
            contentHeight: $h,
            style: $s,
            children: $stackedChildren,
        );
    }

    private function computeBlockWidth(int $parentW, ComputedStyle $s, string $textContent): int
    {
        $width = $s->width->toPx();
        if ($s->width->isPercent()) {
            $width = $s->width->resolveInContext($parentW);
        }

        if ($s->width->isIntrinsic() && strlen($textContent) > 0) {
            $fs = $s->fontSize;
            $bd = $s->bold;
            $measured = (function_exists('sk_measure_text_width')
                ? (int)\sk_measure_text_width($textContent, $fs, $bd)
                : (int)(strlen($textContent) * $fs * 0.6));
            $width = $measured;
        }

        if ($width <= 0) {
            $ml = $s->margin?->left->toPx() ?? 0;
            $mr = $s->margin?->right->toPx() ?? 0;
            $autoPadL = $s->padding?->left->toPx() ?? 0;
            $autoPadR = $s->padding?->right->toPx() ?? 0;
            $autoBw = ($s->borderLeftWidth ?? 0) + ($s->borderRightWidth ?? 0);
            $sizing = $s->boxSizing?->value ?? 'content-box';
            if ($sizing === 'border-box') {
                $autoW = max(0, $parentW - $ml - $mr);
            } else {
                $autoW = max(0, $parentW - $ml - $mr - $autoPadL - $autoPadR - $autoBw);
            }
            $width = $autoW;

            if (strlen($textContent) > 0) {
                $fs = $s->fontSize;
                $bd = $s->bold;
                $measured = (function_exists('sk_measure_text_width')
                    ? (int)\sk_measure_text_width($textContent, $fs, $bd) : 0);
                if ($measured > 0) {
                    $width = $measured;
                }
            }
        }

        $minW = $s->minWidth?->toPx() ?? 0;
        $maxW = $s->maxWidth?->toPx() ?? 0;
        if ($minW > 0 && $width < $minW) $width = $minW;
        if ($maxW > 0 && $width > $maxW) $width = $maxW;

        return (int)max(0, $width);
    }

    private function computeBlockHeight(int $parentH, ComputedStyle $s, string $textContent): int
    {
        $height = $s->height->toPx();
        if ($s->height->isPercent()) {
            $height = $s->height->resolveInContext($parentH);
        }

        if ($s->height->isIntrinsic() && strlen($textContent) > 0) {
            $lineH = $s->lineHeight > 0 ? $s->lineHeight : (int)($s->fontSize * 1.2);
            $height = $lineH;
        }

        if ($height <= 0 && strlen($textContent) > 0) {
            $fs = $s->fontSize;
            $lineH = $s->lineHeight > 0 ? $s->lineHeight : (int)($fs * 1.2);
            $height = $lineH;
        }

        $ar = $s->aspectRatio ?? 0;
        if ($ar > 0 && $height <= 0) {
            $height = (int)($s->width->toPx() / $ar);
        }

        $minH = $s->minHeight?->toPx() ?? 0;
        $maxH = $s->maxHeight?->toPx() ?? 0;
        if ($minH > 0 && $height < $minH) $height = $minH;
        if ($maxH > 0 && $height > $maxH) $height = $maxH;

        return (int)max(0, $height);
    }

    private function stackBlockChildren(
        int $parentX, int $parentY, int $containerW,
        ComputedStyle $s, array $childResults, string $textContent,
        int $parentW
    ): array {
        $padTop = $s->padding?->top->toPx() ?? 0;
        $padLeft = $s->padding?->left->toPx() ?? 0;
        $borderTop = $s->borderTopWidth ?? 0;

        $stackY = $parentY + $borderTop + $padTop;
        $result = [];
        $prevMarginBottom = 0;
        $prevCollapsible = false;

        foreach ($childResults as $cr) {
            $childStyle = $cr->style;
            $childDisplay = $childStyle?->display?->value ?? 'block';
            $childPosition = $childStyle?->position?->value ?? 'static';

            if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') {
                $result[] = $cr;
                continue;
            }

            $mTop = $childStyle?->margin?->top->toPx() ?? 0;
            $mBottom = $childStyle?->margin?->bottom->toPx() ?? 0;
            $mLeft = $childStyle?->margin?->left->toPx() ?? 0;
            $mRight = $childStyle?->margin?->right->toPx() ?? 0;

            $chW = $cr->w;
            if ($chW <= 0) {
                $autoPadL = $childStyle?->padding?->left->toPx() ?? 0;
                $autoPadR = $childStyle?->padding?->right->toPx() ?? 0;
                $autoBw = ($childStyle?->borderLeftWidth ?? 0) + ($childStyle?->borderRightWidth ?? 0);
                $childBoxSizing = $childStyle?->boxSizing?->value ?? 'content-box';
                if ($childBoxSizing === 'border-box') {
                    $chW = max(0, $containerW - $mLeft - $mRight);
                } else {
                    $chW = max(0, $containerW - $mLeft - $mRight - $autoPadL - $autoPadR - $autoBw);
                }
            }

            $chH = $cr->h;

            if ($cr->style !== null && $cr->style->getRaw('_type') !== null) {
                $typeFromStyle = $cr->style->getRaw('_type');
                if (is_string($typeFromStyle) && self::isInlineType($typeFromStyle) && strlen($cr->style->getRaw('_content') ?? '') > 0) {
                    $content = (string)($cr->style->getRaw('_content') ?? '');
                    $fs = $cr->style->fontSize;
                    $bd = $cr->style->bold;
                    $measured = (function_exists('sk_measure_text_width')
                        ? (int)\sk_measure_text_width($content, $fs, $bd) : 0);
                    if ($measured > 0) {
                        $chW = $measured;
                    }
                    if ($chH <= 0) {
                        $lineH = $cr->style->lineHeight > 0 ? $cr->style->lineHeight : (int)($fs * 1.2);
                        $chH = $lineH;
                    }
                }
            }

            $childOverflow = $childStyle?->overflowY?->value ?? $childStyle?->overflow?->value ?? 'visible';
            $isCollapsible = ($childDisplay === 'block') && ($childOverflow === 'visible');

            $childY = 0;
            if ($isCollapsible && $prevCollapsible) {
                $positiveMax = max($prevMarginBottom > 0 ? $prevMarginBottom : 0, $mTop > 0 ? $mTop : 0);
                $negativeMin = min($prevMarginBottom < 0 ? $prevMarginBottom : 0, $mTop < 0 ? $mTop : 0);
                $collapsed = $positiveMax + $negativeMin;
                $childY = $stackY - $prevMarginBottom + $collapsed;
            } else {
                $childY = $stackY + $mTop;
            }

            $result[] = new LayoutResult(
                x: $parentX, y: $childY,
                w: $chW, h: $chH,
                visualW: $chW, visualH: $chH,
                layer: $cr->layer,
                style: $childStyle,
                children: $cr->children,
            );
            $stackY = $childY + $chH + $mBottom;
            $prevMarginBottom = $mBottom;
            $prevCollapsible = $isCollapsible;
        }

        return $result;
    }
}
