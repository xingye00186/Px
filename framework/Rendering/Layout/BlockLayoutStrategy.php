<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\CssLength;

/**
 * BlockLayoutStrategy 鈥?Block 甯冨眬绛栫暐
 *
 * Pure function 瀹炵幇锛歭ayout(LayoutInput) 鈫?LayoutResult銆?
 * 涓嶆帴鏀?RenderNode锛屼笉浜х敓鍓綔鐢ㄣ€?
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
        // Intrinsic measurement mode: return natural content size
        if ($input->constraints->isIntrinsicMeasurement) {
            $s = $input->style;
            $textContent = $input->textContent;
            $fs = $s->fontSize > 0 ? $s->fontSize : 16;
            $w = strlen($textContent) > 0 ? (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($textContent, $fs, $s->bold) : (int)(strlen($textContent) * $fs * 0.6)) : 0;
            $h = strlen($textContent) > 0 ? ($s->lineHeight > 0 ? $s->lineHeight : (int)($fs * 1.2)) : 0;
            return new LayoutResult(w: max(0, $w), h: max(0, $h), minContentWidth: max(0, $w), maxContentWidth: max(0, $w), preferredContentWidth: max(0, $w), minContentHeight: max(0, $h), maxContentHeight: max(0, $h), preferredContentHeight: max(0, $h));
        }

        $c = $input->constraints;
        $s = $input->style;
        $children = $input->childResults;
        $textContent = $input->textContent;

        $left = $s->left->toPx();
        $top = $s->top->toPx();
        $marginLeft = $s->margin->left->toPx();
        $marginTop = $s->margin->top->toPx();
        $parentW = $c->containerWidth;
        $parentH = $c->containerHeight;

        $w = $this->computeBlockWidth($parentW, $s, $textContent);
        $h = $this->computeBlockHeight($parentH, $s, $textContent);

        $positionVal = $s->position->value;
        $isStaticOrRelative = ($positionVal === 'static' || $positionVal === 'relative');
        $x = $isStaticOrRelative ? ($c->parentContentX + $left + $marginLeft) : $c->parentContentX;
        $y = $isStaticOrRelative ? ($c->parentContentY + $top + $marginTop) : $c->parentContentY;

        $displayVal = $s->display?->value ?? 'block';
        $stackedChildren = [];
        if (count($children) > 0 && $displayVal === 'block') {
            // Check if any child has percent height and parent height is auto
            $hasPercentChild = false;
            foreach ($input->childNodes as $ch) {
                $chH = $ch->computedStyle?->height;
                if ($chH !== null && $chH->isPercent() && $h <= 0 && $input->layoutCallback !== null) {
                    $hasPercentChild = true;
                    break;
                }
            }

            if ($hasPercentChild) {
                // Pass 1: treat percent height as auto, compute parent height
                $pass1 = $this->stackBlockChildren(
                    $x, $y, $w, $s, $children, $textContent, $parentW
                );
                $computedH = $y;
                foreach ($pass1 as $cr) {
                    $bottom = $cr->y + $cr->h;
                    if ($bottom > $computedH) $computedH = $bottom;
                }
                $computedH = max(0, $computedH - $y);

                // Pass 2: re-resolve percent children with computed parent height
                $reResolved = [];
                foreach ($input->childNodes as $i => $ch) {
                    $chH = $ch->computedStyle?->height;
                    if ($chH !== null && $chH->isPercent() && $computedH > 0) {
                        $newC = new LayoutConstraints(
                            containerWidth: $c->containerWidth,
                            containerHeight: $computedH,
                            parentContentX: $c->parentContentX,
                            parentContentY: $c->parentContentY,
                            contentWidth: $c->contentWidth,
                            contentHeight: $computedH,
                        );
                        $reResolved[] = $input->layoutCallback->reResolveChild($ch, $newC);
                    } else {
                        $reResolved[] = $i < count($children) ? $children[$i] : null;
                    }
                }
                $children = array_values(array_filter($reResolved));
            }

            $stackedChildren = $this->stackBlockChildren(
                $x, $y, $w, $s, $children, $textContent, $parentW
            );
        } else {
            // Also handle inline children in non-block display containers (e.g., <p>)
            $inlineBufferPassthrough = [];
            foreach ($children as $cr) {
                $cDisplay = $cr->style?->display?->value ?? 'block';
                if ($cDisplay === 'inline' || $cDisplay === 'inline-block') {
                    $inlineBufferPassthrough[] = $cr;
                } else {
                    if (!empty($inlineBufferPassthrough)) {
                        $this->flushInlineBuffer($inlineBufferPassthrough, $x, 0, $w, $y, $stackedChildren, $parentW);
                    }
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
            if (!empty($inlineBufferPassthrough)) {
                $this->flushInlineBuffer($inlineBufferPassthrough, $x, 0, $w, $y, $stackedChildren, $parentW);
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

            // Text measurement only for inline-level elements, not block fill
            // (type/text content detection is handled by the caller via style)
        } else {
            // border-box: CSS width is the total; content width = CSS width - padding - border,
            // but we keep CSS width here and let $cbW subtraction handle it (consistent with Flex/Grid/etc).
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

        // 鈹€鈹€ IFC: inline formatting context buffer 鈹€鈹€
        // Collect consecutive inline/inline-block items and flush them as
        // horizontally-wrapped lines (CSS 搂9.4.2 Inline formatting context).
        $inlineBuffer = [];

        foreach ($childResults as $cr) {
            $childStyle = $cr->style;
            $childDisplay = $childStyle?->display?->value ?? 'block';
            $childPosition = $childStyle?->position?->value ?? 'static';

            if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') {
                $result[] = $cr;
                continue;
            }

            $isInline = ($childDisplay === 'inline' || $childDisplay === 'inline-block');

            if ($isInline) {
                $inlineBuffer[] = $cr;
                continue;
            }

            // Block item: flush inline buffer first
            if (!empty($inlineBuffer)) {
                $this->flushInlineBuffer($inlineBuffer, $parentX, $padLeft, $containerW, $stackY, $result, $parentW);
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

            // Apply position:relative top/left offset (CSS 搂9.4.3)
            $relTop = $childStyle?->top?->toPx() ?? 0;
            $relLeft = $childStyle?->left?->toPx() ?? 0;
            if ($childPosition === 'relative' || $childPosition === 'static') {
                $childY += $relTop;
            }

            $result[] = new LayoutResult(
                x: $parentX + $padLeft + ($childPosition === 'relative' || $childPosition === 'static' ? $relLeft : 0), y: $childY,
                w: $chW, h: $chH,
                visualW: $chW, visualH: $chH,
                layer: $cr->layer,
                style: $childStyle,
                children: $cr->children,
            );
            // CSS 搂9.4.3: relative offset does NOT affect subsequent siblings.
            // $stackY uses the un-offset position (childY - relTop).
            $stackY = ($childY - $relTop) + $chH + $mBottom;
            $prevMarginBottom = $mBottom;
            $prevCollapsible = $isCollapsible;
        }

        // Flush remaining inline buffer at end of children
        if (!empty($inlineBuffer)) {
            $this->flushInlineBuffer($inlineBuffer, $parentX, $padLeft, $containerW, $stackY, $result, $parentW);
        }

        return $result;
    }

    /**
     * Layout inline/inline-block items in a horizontal flow with line wrapping.
     *
     * CSS 搂9.4.2: In inline formatting context, boxes are placed horizontally
     * one after another. When the remaining space on a line is insufficient,
     * a new line is started below.
     *
     * @param array $buffer Array of LayoutResult (inline items)
     * @param int $parentX Absolute X of parent
     * @param int $padLeft Parent padding-left
     * @param int $containerW Container content width
     * @param int $startY Starting Y position
     * @return array ['items' => LayoutResult[], 'nextY' => int]
     */
    private function layoutInlineBuffer(array $buffer, int $parentX, int $padLeft, int $containerW, int $startY): array
    {
        if (count($buffer) > 0) {
            $first = $buffer[0];
        }
        $availableW = $containerW;
        $result = [];
        $cursorX = $padLeft; // relative to parent content area
        $cursorY = 0; // relative offset within inline block
        $lineMaxH = 0;

        foreach ($buffer as $i => $cr) {
            $cStyle = $cr->style;
            $mLeft = $cStyle?->margin?->left->toPx() ?? 0;
            $mRight = $cStyle?->margin?->right->toPx() ?? 0;
            $mTop = $cStyle?->margin?->top->toPx() ?? 0;
            $mBottom = $cStyle?->margin?->bottom->toPx() ?? 0;

            $itemTotalW = $cr->w + $mLeft + $mRight;
            $itemH = $cr->h + $mTop + $mBottom;

            // Check if item fits on current line (CSS Text 搂3: soft wrap break)
            $wouldExceed = ($cursorX + $itemTotalW > $availableW);
            if ($i < 5 || $i % 20 === 0) {
            }
            if ($wouldExceed && $cursorX > $padLeft) {
                // Wrap to next line
                $cursorY += $lineMaxH;
                $cursorX = $padLeft;
                $lineMaxH = 0;
            }

            // Place item
            $itemX = $parentX + $cursorX + $mLeft;
            $itemY = $startY + $cursorY + $mTop;

            $result[] = new LayoutResult(
                x: $itemX,
                y: $itemY,
                w: $cr->w,
                h: $cr->h,
                visualW: $cr->visualW,
                visualH: $cr->visualH,
                layer: $cr->layer,
                style: $cStyle,
                children: $cr->children,
            );

            $cursorX += $itemTotalW;
            if ($itemH > $lineMaxH) $lineMaxH = $itemH;
        }

        $nextY = $startY + $cursorY + $lineMaxH;


        return ['items' => $result, 'nextY' => $nextY];
    }

    private function flushInlineBuffer(array &$inlineBuffer, int $parentX, int $padLeft, int $containerW, int &$stackY, array &$result, int $parentW): void
    {
        // containerW is the block's own computed width; for auto-width blocks with only
        // inline children, this can be 0. Fall back to parentW (constraints containerWidth).
        // If both are 0, use a generous fallback (inline items should at least fill one line).
        $availW = $containerW;
        if ($availW <= 0) $availW = $parentW;
        if ($availW <= 0) $availW = 10000; // generous fallback: inline items on single line
        $inlineResult = $this->layoutInlineBuffer($inlineBuffer, $parentX, $padLeft, $availW, $stackY);
        foreach ($inlineResult['items'] as $item) $result[] = $item;
        $stackY = $inlineResult['nextY'];
        $inlineBuffer = [];
        $prevMarginBottom = 0;
        $prevCollapsible = false;
    }
}