<?php
namespace Px\Layout;
use Px\Render\RenderNode;
use native_types;
use Px\Css\ComputedStyle;
use Px\Css\CssLength;

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
        ?PhysicalFragment $inputFragment = null,
    ): PhysicalFragment {
        $s = $style ?? \Px\Css\StylePool::empty();
        // P2: 按需布局子项（对标 Blink：算法通过 LayoutChild 布局子项）
        $children = [];
        for ($ci = 0, $clen = count($childNodes); $ci < $clen; $ci++) {
            $children[] = $this->layoutChild($childNodes[$ci]);
        }
        $c = $space;

        // Intrinsic measurement mode
        if ($c->getIsIntrinsicMeasurement()) {
            $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
            $w = strlen($textContent) > 0 ? TextMeasureCache::measure($textContent, $fs, (bool)($s->getBold() ?? false)) : 0;
            $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
            return new PhysicalFragment((int)max(0, $w), (int)max(0, $h), 0, 0, 0, 0, 0, 0, 0, $s, [], null, 0, 0, false, '', null, [], [], 0, (int)max(0, $w));
        }

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $marginLeft = $s->margin?->left->toPx() ?? 0;
        $marginTop = $s->margin?->top->toPx() ?? 0;
        // CSS 两阶段布局：优先使用 determinedPercentageWidth 作为百分比基准
        $parentW = $c->getContentWidth();
        $parentH = $c->getContentHeight();
        $percBaseW = $c->getDeterminedPercentageWidth() ?? $parentW;
        $percBaseH = $c->getDeterminedPercentageHeight() ?? $parentH;

        $w = $this->computeBlockWidth($parentW, $s, $textContent, $percBaseW);
        $h = $this->computeBlockHeight($parentH, $s, $textContent, $percBaseH);

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
                    $stackedChildren[] = new PhysicalFragment((int)$cr->getX(), (int)$cr->getY(), (int)$cr->getW(), (int)$cr->getH(), 0, 0, (int)($cr->getLayer() ?? 0), (int)($cr->getContentWidth() ?? 0), (int)($cr->getContentHeight() ?? 0), $cr->style, $cr->children, $cr->sourceNode,
                    $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                    $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles);
                }
            }
            if (!empty($inlineBuffer)) { $this->flushInlineBuffer($inlineBuffer, $x, 0, $w, $y, $stackedChildren, $parentW); }
        }

        if ($h <= 0 && count($stackedChildren) > 0) {
            $maxBottom = $y;
            foreach ($stackedChildren as $cr) {
                $bottom = $cr->getY() + $cr->getH();
                // CSS 2.2 §10.6.3: auto-height 应包括最后一个正常流子元素的底边距
                if ($cr->style !== null) {
                    $bottom += (int)($cr->style->margin?->bottom->toPx() ?? 0);
                }
                if ($bottom > $maxBottom) $maxBottom = $bottom;
            }
            $h = max(0, $maxBottom - $y);
            // CSS 2.2 $10.6.3: auto-height 应包含 padding-bottom + border-bottom
            // 子元素 stack 到 maxBottom，下方 padding 和 border 应当计入高度
            $h += (int)($s->padding?->bottom->toPx() ?? 0);
            $h += (int)($s->getBorderBottomWidth() ?? 0);
        }

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h, $s->visualWidth($w), $s->visualHeight($h), 0, (int)$w, (int)$h, $s, $stackedChildren, null);
    }

    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        $s = $style ?? \Px\Css\StylePool::empty();
        $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
        $w = strlen($textContent) > 0 ? TextMeasureCache::measure($textContent, $fs, (bool)($s->getBold() ?? false)) : 0;
        $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
        return new IntrinsicSizes(max(0, $w), max(0, $w), max(0, $h), max(0, $h));
    }

    private function computeBlockWidth(int $parentW, ComputedStyle $s, string $textContent, int $percBaseW = 0): int
    {
        $width = $s->width?->toPx() ?? 0;
        $sizing = $s->boxSizing?->value ?? 'content-box';
        if ($s->width !== null && $s->width->isPercent()) {
            $pw = $percBaseW > 0 ? $percBaseW : $parentW;
            $width = $s->width->resolveInContext($pw);
            // CSS2.1 §10.2 + CSS-UI-3 §4.5: box-sizing:border-box时百分比width包含padding+border
            if ($sizing === 'border-box') {
                $padL = $s->padding?->left->toPx() ?? 0;
                $padR = $s->padding?->right->toPx() ?? 0;
                $bw = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
                $width = max(0, $width - $padL - $padR - $bw);
            }
        }
        if ($s->width !== null && $s->width->isIntrinsic() && strlen($textContent) > 0) {
            $fs = $s->getFontSize(); $bd = $s->getBold();
            $width = TextMeasureCache::measure($textContent, $fs, (bool)$bd);
        }

        if ($width <= 0) {
            $ml = $s->margin?->left->toPx() ?? 0; $mr = $s->margin?->right->toPx() ?? 0;
            $autoPadL = $s->padding?->left->toPx() ?? 0; $autoPadR = $s->padding?->right->toPx() ?? 0;
            $autoBw = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
            // CSS-UI-3 §4.5: box-sizing 影响 auto-fill 宽度的计算
            // content-box: width = 可用空间 - 外边距 (padding+border 在外面追加)
            // border-box:  width = 可用空间 - 外边距 - 内边距 - 边框 (全部在盒内)
            $width = ($sizing === 'border-box')
                ? max(0, $parentW - $ml - $mr - $autoPadL - $autoPadR - $autoBw)
                : max(0, $parentW - $ml - $mr);
        }
        $minW = $s->minWidth?->toPx() ?? 0; $maxW = $s->maxWidth?->toPx() ?? 0;
        if ($minW > 0 && $width < $minW) $width = $minW;
        if ($maxW > 0 && $width > $maxW) $width = $maxW;
        return (int)max(0, $width);
    }

    private function computeBlockHeight(int $parentH, ComputedStyle $s, string $textContent, int $percBaseH = 0): int
    {
        $height = $s->height?->toPx() ?? 0;
        if ($s->height !== null && $s->height->isPercent()) {
            $ph = $percBaseH > 0 ? $percBaseH : $parentH;
            $height = $s->height->resolveInContext($ph);
        }
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
                    $measured = TextMeasureCache::measure($content, $fs, (bool)$bd);
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
            $result[] = new PhysicalFragment((int)($parentX + $padLeft + ($childPosition === 'relative' ? $relLeft : 0)), (int)$childY, (int)$chW, (int)$chH, 0, 0, (int)($cr->getLayer() ?? 0), (int)($chW), (int)($chH), $childStyle, $cr->children, $cr->sourceNode,
                    $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                    $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles);
            $stackY = ($childY - ($childPosition === 'relative' ? $relTop : 0)) + $chH + $mBottom;
            $prevMarginBottom = $mBottom;
            $prevCollapsible = $isCollapsible;
        }
        if (!empty($inlineBuffer)) { $this->flushInlineBuffer($inlineBuffer, $parentX, $padLeft, $containerW, $stackY, $result, $parentW); }
        return $result;
    }

    private function flushInlineBuffer(array &$inlineBuffer, int $parentX, int $padLeft, int $containerW, int &$stackY, array &$result, int $parentW): void
    {
        // P4: 委派给 InlineAlgorithm（对标 Blink：块算法将 IFC 委派给内联算法）
        $availW = $containerW; if ($availW <= 0) $availW = $parentW; if ($availW <= 0) $availW = 10000;
        $ir = InlineAlgorithm::layoutInlineRun($inlineBuffer, $availW, $parentX, $stackY, $padLeft);
        foreach ($ir['items'] as $item) $result[] = $item;
        $stackY = $ir['nextY'];
        $inlineBuffer = [];
    }

    private function reResolveChild(ConstraintSpace $space, \Px\Render\RenderNode $child, ?PhysicalFragment $oldFrag): ?PhysicalFragment
    {
        if ($oldFrag === null) return null;
        $childStyle = $child->computedStyle;
        if ($childStyle === null) return $oldFrag;
        $h = $childStyle->height?->toPx() ?? 0;
        if ($childStyle->height !== null && $childStyle->height->isPercent()) { $h = $childStyle->height->resolveInContext($space->getContentHeight()); }
        $minH = $childStyle->minHeight?->toPx() ?? 0; $maxH = $childStyle->maxHeight?->toPx() ?? 0;
        if ($minH > 0 && $h < $minH) $h = $minH;
        if ($maxH > 0 && $h > $maxH) $h = $maxH;
        return new PhysicalFragment((int)$oldFrag->x, (int)$oldFrag->y, (int)$oldFrag->w, (int)max(0, $h), (int)$oldFrag->visualW, (int)$oldFrag->visualH, (int)$oldFrag->layer, (int)$oldFrag->contentWidth, (int)max(0, $h), $childStyle, $oldFrag->children, $oldFrag->sourceNode,
            $oldFrag->scrollTop, $oldFrag->scrollLeft, $oldFrag->isScrollContainer,
            $oldFrag->type, $oldFrag->content, $oldFrag->dataset, $oldFrag->pseudoStyles);
    }
}
