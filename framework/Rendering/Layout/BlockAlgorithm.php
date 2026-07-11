<?php
namespace Px\Rendering\Layout;
use native_types;
use Px\Rendering\ComputedStyle;
use Px\Rendering\CssLength;

/**
 * BlockAlgorithm — Block 布局算法（完全实现）
 *
 * 取代 BlockLayoutStrategy，直接计算 Block 布局，
 * 不再依赖旧策略层和旧 DTO。
 */
class BlockAlgorithm extends LayoutAlgorithm
{
    private const INLINE_TYPES = ['#text','text','span','b','strong','em','i','code','br','a','label','abbr','cite','dfn','kbd','mark','q','samp','small','sub','sup','time','var'];

    private static function isInlineType(string $type): bool
    {
        return in_array($type, self::INLINE_TYPES, true);
    }

    public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        array $childFragments = [],
        ?PhysicalFragment $inputFragment = null
    ): PhysicalFragment {
        $s = $style ?? new ComputedStyle([]);
        $children = $childFragments;
        $c = $space;

        // Intrinsic measurement mode
        if ($c->getIsIntrinsicMeasurement()) {
            $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
            $w = strlen($textContent) > 0 ? (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($textContent, $fs, (int)($s->getBold() ?? 0)) : (int)(strlen($textContent) * $fs * 0.6)) : 0;
            $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
            return new PhysicalFragment((int)max(0, $w), (int)max(0, $h), 0, 0, 0, 0, 0, 0, 0, $s);
        }

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $marginLeft = $s->margin?->left->toPx() ?? 0;
        $marginTop = $s->margin?->top->toPx() ?? 0;
        $parentW = $c->getContentWidth();
        $parentH = $c->getContentHeight();

        $w = $this->computeBlockWidth($parentW, $s, $textContent);
        $h = $this->computeBlockHeight($parentH, $s, $textContent);

        $positionVal = $s->position?->value ?? 'static';
        $isStaticOrRelative = ($positionVal === 'static' || $positionVal === 'relative');
        $x = $isStaticOrRelative ? ((int)($c->getBfcOffsetX() ?? 0) + (int)($left ?? 0) + (int)($marginLeft ?? 0)) : (int)($c->getBfcOffsetX() ?? 0);
        $y = $isStaticOrRelative ? ((int)($c->getBfcOffsetY() ?? 0) + (int)($top ?? 0) + (int)($marginTop ?? 0)) : (int)($c->getBfcOffsetY() ?? 0);

        $displayVal = $s->display?->value ?? 'block';
        $stackedChildren = [];
        if (count($children) > 0 && ($displayVal === 'block' || $displayVal === 'flow-root')) {
            // Check percent-height children
            $hasPercentChild = false;
            foreach ($childNodes as $ch) {
                $chH = $ch->computedStyle?->height;
                if ($chH !== null && $chH->isPercent() && $h <= 0) {
                    $hasPercentChild = true; break;
                }
            }

            if ($hasPercentChild) {
                $pass1 = $this->stackBlockChildren($x, $y, $w, $s, $children, $textContent, $parentW);
                $computedH = $y;
                foreach ($pass1 as $cr) { $bottom = $cr->getY() + $cr->getH(); if ($bottom > $computedH) $computedH = $bottom; }
                $computedH = max(0, $computedH - $y);

                $reResolved = [];
                foreach ($childNodes as $i => $ch) {
                    $chH = $ch->computedStyle?->height;
                    if ($chH !== null && $chH->isPercent() && $computedH > 0) {
                        $newC = new ConstraintSpace($c->getContentWidth(), $computedH, $c->getBfcOffsetX(), $c->getBfcOffsetY(), 0, 0, $c->getPercentageWidth(), $computedH);
                        $reResolved[] = $this->reResolveChild($newC, $ch, $children[$i] ?? null);
                    } else {
                        $reResolved[] = $i < count($children) ? $children[$i] : null;
                    }
                }
                $children = [];
                foreach ($reResolved as $cr) { if ($cr !== null) $children[] = $cr; }
            }

            $stackedChildren = $this->stackBlockChildren($x, $y, $w, $s, $children, $textContent, $parentW);
        } else {
            // Handle inline + block children
            $inlineBuffer = [];
            foreach ($children as $cr) {
                $cDisplay = $cr->style?->display?->value ?? 'block';
                if ($cDisplay === 'inline' || $cDisplay === 'inline-block') {
                    $inlineBuffer[] = $cr;
                } else {
                    if (!empty($inlineBuffer)) { $this->flushInlineBuffer($inlineBuffer, $x, 0, $w, $y, $stackedChildren, $parentW); }
                    $stackedChildren[] = new PhysicalFragment((int)$cr->getX(), (int)$cr->getY(), (int)$cr->getW(), (int)$cr->getH(), 0, 0, (int)($cr->getLayer() ?? 0), (int)($cr->getContentWidth() ?? 0), (int)($cr->getContentHeight() ?? 0), $cr->style, $cr->children, null);
                }
            }
            if (!empty($inlineBuffer)) { $this->flushInlineBuffer($inlineBuffer, $x, 0, $w, $y, $stackedChildren, $parentW); }
        }

        if ($h <= 0 && count($stackedChildren) > 0) {
            $maxBottom = $y;
            foreach ($stackedChildren as $cr) { $bottom = $cr->getY() + $cr->getH(); if ($bottom > $maxBottom) $maxBottom = $bottom; }
            $h = max(0, $maxBottom - $y);
        }

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h, $s->visualWidth($w), $s->visualHeight($h), 0, (int)$w, (int)$h, $s, $stackedChildren, null);
    }

    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        $s = $style ?? new ComputedStyle([]);
        $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
        $w = strlen($textContent) > 0 ? (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($textContent, $fs, (int)($s->getBold() ?? 0)) : (int)(strlen($textContent) * $fs * 0.6)) : 0;
        $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
        return new IntrinsicSizes(max(0, $w), max(0, $w), max(0, $h), max(0, $h));
    }

    private function computeBlockWidth(int $parentW, ComputedStyle $s, string $textContent): int
    {
        $width = $s->width?->toPx() ?? 0;
        if ($s->width !== null && $s->width->isPercent()) { $width = $s->width->resolveInContext($parentW); }
        if ($s->width !== null && $s->width->isIntrinsic() && strlen($textContent) > 0) {
            $fs = $s->getFontSize(); $bd = $s->getBold();
            $width = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($textContent, $fs, $bd) : (int)(strlen($textContent) * $fs * 0.6));
        }
        if ($width <= 0) {
            $ml = $s->margin?->left->toPx() ?? 0; $mr = $s->margin?->right->toPx() ?? 0;
            $autoPadL = $s->padding?->left->toPx() ?? 0; $autoPadR = $s->padding?->right->toPx() ?? 0;
            $autoBw = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
            $sizing = $s->boxSizing?->value ?? 'content-box';
            $width = ($sizing === 'border-box') ? max(0, $parentW - $ml - $mr) : max(0, $parentW - $ml - $mr - $autoPadL - $autoPadR - $autoBw);
        }
        $minW = $s->minWidth?->toPx() ?? 0; $maxW = $s->maxWidth?->toPx() ?? 0;
        if ($minW > 0 && $width < $minW) $width = $minW;
        if ($maxW > 0 && $width > $maxW) $width = $maxW;
        return (int)max(0, $width);
    }

    private function computeBlockHeight(int $parentH, ComputedStyle $s, string $textContent): int
    {
        $height = $s->height?->toPx() ?? 0;
        if ($s->height !== null && $s->height->isPercent()) { $height = $s->height->resolveInContext($parentH); }
        if ($s->height !== null && $s->height->isIntrinsic() && strlen($textContent) > 0) { $height = $s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($s->getFontSize() * 1.2); }
        if ($height <= 0 && strlen($textContent) > 0) { $height = $s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($s->getFontSize() * 1.2); }
        $ar = $s->getAspectRatio() ?? 0;
        if ($ar > 0 && $height <= 0) { $height = (int)(($s->width?->toPx() ?? 0) / $ar); }
        $minH = $s->minHeight?->toPx() ?? 0; $maxH = $s->maxHeight?->toPx() ?? 0;
        if ($minH > 0 && $height < $minH) $height = $minH;
        if ($maxH > 0 && $height > $maxH) $height = $maxH;
        return (int)max(0, $height);
    }

    private function stackBlockChildren(int $parentX, int $parentY, int $containerW, ComputedStyle $s, array $childResults, string $textContent, int $parentW): array
    {
        $padTop = $s->padding?->top->toPx() ?? 0;
        $padLeft = $s->padding?->left->toPx() ?? 0;
        $borderTop = (int)($s->getBorderTopWidth() ?? 0);
        $stackY = $parentY + $borderTop + $padTop;
        $result = [];
        $prevMarginBottom = 0; $prevCollapsible = false;
        $inlineBuffer = [];

        foreach ($childResults as $cr) {
            $childStyle = $cr->style;
            $childDisplay = $childStyle?->display?->value ?? 'block';
            $childPosition = $childStyle?->position?->value ?? 'static';
            if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') { $result[] = $cr; continue; }
            $isInline = ($childDisplay === 'inline' || $childDisplay === 'inline-block');
            if ($isInline) { $inlineBuffer[] = $cr; continue; }
            if (!empty($inlineBuffer)) { $this->flushInlineBuffer($inlineBuffer, $parentX, $padLeft, $containerW, $stackY, $result, $parentW); }

            $mTop = $childStyle?->margin?->top->toPx() ?? 0;
            $mBottom = $childStyle?->margin?->bottom->toPx() ?? 0;
            $mLeft = $childStyle?->margin?->left->toPx() ?? 0;
            $mRight = $childStyle?->margin?->right->toPx() ?? 0;
            $chW = (int)($cr->getW() ?? 0);
            if ($chW <= 0) {
                $autoPadL = $childStyle?->padding?->left->toPx() ?? 0;
                $autoPadR = $childStyle?->padding?->right->toPx() ?? 0;
                $autoBw = (int)($childStyle?->getBorderLeftWidth() ?? 0) + (int)($childStyle?->getBorderRightWidth() ?? 0);
                $cs = $childStyle?->boxSizing?->value ?? 'content-box';
                $chW = ($cs === 'border-box') ? max(0, $containerW - $mLeft - $mRight) : max(0, $containerW - $mLeft - $mRight - $autoPadL - $autoPadR - $autoBw);
            }
            $chH = (int)($cr->getH() ?? 0);
            if ($childStyle !== null) {
                $typeFromStyle = $childStyle->getRaw('_type');
                if (is_string($typeFromStyle) && self::isInlineType($typeFromStyle) && strlen($childStyle->getRaw('_content') ?? '') > 0) {
                    $content = (string)($childStyle->getRaw('_content') ?? '');
                    $fs = $childStyle->getFontSize(); $bd = $childStyle->getBold();
                    $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($content, $fs, $bd) : 0);
                    if ($measured > 0) $chW = $measured;
                    if ($chH <= 0) $chH = $childStyle->getLineHeight() > 0 ? $childStyle->getLineHeight() : (int)($fs * 1.2);
                }
            }
            $overflowY = $childStyle?->overflowY?->value ?? $childStyle?->overflow?->value ?? 'visible';
            $isCollapsible = ($childDisplay === 'block') && ($overflowY === 'visible');
            $childY = ($isCollapsible && $prevCollapsible) ? ($stackY - $prevMarginBottom + max($prevMarginBottom > 0 ? $prevMarginBottom : 0, $mTop > 0 ? $mTop : 0) + min($prevMarginBottom < 0 ? $prevMarginBottom : 0, $mTop < 0 ? $mTop : 0)) : ($stackY + $mTop);
            $relTop = $childStyle?->top?->toPx() ?? 0;
            $relLeft = $childStyle?->left?->toPx() ?? 0;
            if ($childPosition === 'relative') { $childY += $relTop; }
            $result[] = new PhysicalFragment((int)($parentX + $padLeft + ($childPosition === 'relative' ? $relLeft : 0)), (int)$childY, (int)$chW, (int)$chH, 0, 0, (int)($cr->getLayer() ?? 0), (int)($chW), (int)($chH), $childStyle, $cr->children, null);
            $stackY = ($childY - ($childPosition === 'relative' ? $relTop : 0)) + $chH + $mBottom;
            $prevMarginBottom = $mBottom;
            $prevCollapsible = $isCollapsible;
        }
        if (!empty($inlineBuffer)) { $this->flushInlineBuffer($inlineBuffer, $parentX, $padLeft, $containerW, $stackY, $result, $parentW); }
        return $result;
    }

    private function layoutInlineBuffer(array $buffer, int $parentX, int $padLeft, int $containerW, int $startY): array
    {
        $availableW = $containerW; $result = []; $cursorX = $padLeft; $cursorY = 0; $lineMaxH = 0;
        foreach ($buffer as $cr) {
            $cStyle = $cr->style;
            $mLeft = $cStyle?->margin?->left->toPx() ?? 0;
            $mRight = $cStyle?->margin?->right->toPx() ?? 0;
            $mTop = $cStyle?->margin?->top->toPx() ?? 0;
            $mBottom = $cStyle?->margin?->bottom->toPx() ?? 0;
            $itemTotalW = ($cr->getW() ?? 0) + $mLeft + $mRight;
            $itemH = ($cr->getH() ?? 0) + $mTop + $mBottom;
            if ($cursorX + $itemTotalW > $availableW && $cursorX > $padLeft) { $cursorY += $lineMaxH; $cursorX = $padLeft; $lineMaxH = 0; }
            $result[] = new PhysicalFragment((int)($parentX + $cursorX + $mLeft), (int)($startY + $cursorY + $mTop), (int)($cr->getW() ?? 0), (int)($cr->getH() ?? 0), 0, 0, (int)($cr->getLayer() ?? 0), (int)($cr->getContentWidth() ?? 0), (int)($cr->getContentHeight() ?? 0), $cStyle, $cr->children, null);
            $cursorX += $itemTotalW;
            if ($itemH > $lineMaxH) $lineMaxH = $itemH;
        }
        return ['items' => $result, 'nextY' => $startY + $cursorY + $lineMaxH];
    }

    private function flushInlineBuffer(array &$inlineBuffer, int $parentX, int $padLeft, int $containerW, int &$stackY, array &$result, int $parentW): void
    {
        $availW = $containerW; if ($availW <= 0) $availW = $parentW; if ($availW <= 0) $availW = 10000;
        $ir = $this->layoutInlineBuffer($inlineBuffer, $parentX, $padLeft, $availW, $stackY);
        foreach ($ir['items'] as $item) $result[] = $item;
        $stackY = $ir['nextY'];
        $inlineBuffer = [];
    }

    private function reResolveChild(ConstraintSpace $space, \Px\Rendering\RenderNode $child, ?PhysicalFragment $oldFrag): ?PhysicalFragment
    {
        if ($oldFrag === null) return null;
        $childStyle = $child->computedStyle;
        if ($childStyle === null) return $oldFrag;
        $h = $childStyle->height?->toPx() ?? 0;
        if ($childStyle->height !== null && $childStyle->height->isPercent()) { $h = $childStyle->height->resolveInContext($space->getContentHeight()); }
        $minH = $childStyle->minHeight?->toPx() ?? 0; $maxH = $childStyle->maxHeight?->toPx() ?? 0;
        if ($minH > 0 && $h < $minH) $h = $minH;
        if ($maxH > 0 && $h > $maxH) $h = $maxH;
        return new PhysicalFragment((int)$oldFrag->x, (int)$oldFrag->y, (int)$oldFrag->w, (int)max(0, $h), (int)$oldFrag->visualW, (int)$oldFrag->visualH, (int)$oldFrag->layer, (int)$oldFrag->contentWidth, (int)max(0, $h), $childStyle, $oldFrag->children, null);
    }
}
