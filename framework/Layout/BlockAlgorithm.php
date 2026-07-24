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

    /**
     * 启用了 endMarginStrut 上传的 LayoutResult。
     *
     * 对标 Blink NGBlockLayoutAlgorithm::Layout()：末尾未消耗的 margin strut 作为 end_margin_strut_
     * 上传给父，以支持 CSS 2.2 §8.3.1 第 3 种场景：父与末子 margin-bottom 折叠。
     *
     * 条件（保守）：
     *   1. 自身不创建新 BFC（overflow visible + 非 float/OOF + 非 flex/grid 容器...）
     *   2. 自身无 padding-bottom + border-bottom（否则不能穿透）
     *   3. 自身无确定高度（否则 margin 不能逸出）
     *   4. 末子为 collapsible block（非 OOF、非创新 BFC）
     *
     * 未满足则 endMarginStrut 为 null，消费方需回退到“不折叠”行为。
     */
    public function layoutResult(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        ?PhysicalFragment $inputFragment = null,
    ): LayoutResult {
        $frag = $this->layout($space, $style, $textContent, $childNodes, $inputFragment);
        $endStrut = $this->extractEndMarginStrut($frag, $style);
        // intrinsicBlockSize 尚无独立追踪，暂与 fragment.h 一致
        return new LayoutResult($frag, $endStrut, (int)$frag->getH());
    }

    /**
     * 从 Fragment 末子提取 endMarginStrut，仅父子边界可穿透时非 null。
     * 对标 Blink 处理 "unresolved bottom margin"：父 padding-bottom/border-bottom/height 均为 0 且未创建新 BFC。
     */
    private function extractEndMarginStrut(PhysicalFragment $frag, ?ComputedStyle $selfStyle): ?MarginStrut
    {
        if ($selfStyle === null) return null;
        // 1. 自身创建新 BFC 则阻断穿透
        $selfOverflowY = $selfStyle->overflowY?->value ?? $selfStyle->overflow?->value ?? 'visible';
        $selfDisplay = $selfStyle->display?->value ?? 'block';
        $selfPosition = $selfStyle->position?->value ?? 'static';
        $selfFloat = $selfStyle->getRaw('float') ?? 'none';
        $selfFloatVal = is_object($selfFloat) ? ($selfFloat->value ?? 'none') : (string)$selfFloat;
        $createsNewBFC = ($selfOverflowY !== 'visible')
            || ($selfPosition === 'absolute' || $selfPosition === 'fixed')
            || ($selfFloatVal !== 'none')
            || ($selfDisplay === 'inline-block' || $selfDisplay === 'table-cell'
                || $selfDisplay === 'flex' || $selfDisplay === 'grid'
                || $selfDisplay === 'flow-root');
        if ($createsNewBFC) return null;

        // 2. 自身有 padding-bottom 或 border-bottom 则不可穿透
        $padB = (int)($selfStyle->padding?->bottom->toPx() ?? 0);
        $bwB = (int)($selfStyle->getBorderBottomWidth() ?? 0);
        if ($padB !== 0 || $bwB !== 0) return null;

        // 3. 自身有确定高度则不可穿透（min-height 也拦截）
        $selfH = $selfStyle->height;
        if ($selfH !== null && !$selfH->isAuto() && !$selfH->isIntrinsic() && $selfH->toPx() > 0) return null;
        $selfMinH = $selfStyle->minHeight;
        if ($selfMinH !== null && !$selfMinH->isAuto() && $selfMinH->toPx() > 0) return null;

        // 4. 逐个向后扫描末尾非 OOF collapsible block 子，提取其 margin-bottom
        for ($i = count($frag->children) - 1; $i >= 0; $i--) {
            $child = $frag->children[$i];
            $chStyle = $child->style;
            if ($chStyle === null) continue;
            $chDisp = $chStyle->display?->value ?? 'block';
            if ($chDisp === 'none') continue;
            $chPos = $chStyle->position?->value ?? 'static';
            if ($chPos === 'absolute' || $chPos === 'fixed') continue;
            // 遇到创建新 BFC 的子：阻断穿透
            $chOverflowY = $chStyle->overflowY?->value ?? $chStyle->overflow?->value ?? 'visible';
            $chFloat = $chStyle->getRaw('float') ?? 'none';
            $chFloatVal = is_object($chFloat) ? ($chFloat->value ?? 'none') : (string)$chFloat;
            $chCreatesBFC = ($chOverflowY !== 'visible')
                || ($chFloatVal !== 'none')
                || ($chDisp === 'inline-block' || $chDisp === 'table-cell'
                    || $chDisp === 'flex' || $chDisp === 'grid'
                    || $chDisp === 'flow-root');
            if ($chCreatesBFC) return null;
            if ($chDisp !== 'block') return null;

            $chMBottom = (int)($chStyle->margin?->bottom->toPx() ?? 0);
            if ($chMBottom === 0) return null;
            $strut = new MarginStrut();
            $strut->append($chMBottom);
            return $strut;
        }
        return null;
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
            return new PhysicalFragment((int)max(0, $w), (int)max(0, $h), 0, 0, 0, 0, 0, 0, 0, $s, [], null, 0, 0, false, '', null, [], [], (int)max(0, $w));
        }

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        // CSS 2.2 §8.3：margin 百分比基于**包含块的宽度**（inline-size），不论方向。
        // 使用 resolveBoxPercent($c->getContentWidth()) 而非直接 toPx() 以保证百分比正确解析。
        $cbW = $c->getContentWidth();
        $marginLeft = $s->margin?->left->resolveBoxPercent($cbW) ?? 0;
        $marginTop = $s->margin?->top->resolveBoxPercent($cbW) ?? 0;
        // CSS 两阶段布局：优先使用 determinedPercentageWidth 作为百分比基准
        $parentW = $c->getContentWidth();
        $parentH = $c->getContentHeight();
        $percBaseW = $c->getDeterminedPercentageWidth() ?? $parentW;
        $percBaseH = $c->getDeterminedPercentageHeight() ?? $parentH;

        $w = $this->computeBlockWidth($parentW, $s, $textContent, $percBaseW);
        $h = $this->computeBlockHeight($parentH, $s, $textContent, $percBaseH);
        // CSS-Sizing-4 §5 aspect-ratio 反向推导（H 显式 + W auto）：
        //   仅当宽度由默认 auto-fill 得到 (== parentW - margins) 且 height 显式声明时，
        //   尝试使用 aspect-ratio 推导 宽度 = 高度 * ratio。
        //   需避免与显式 width 冲突—仅当 raw width 未声明（getRaw('width') === null）时生效。
        $rawW = $s->getRaw('width');
        $arVal = $s->getAspectRatio() ?? 0;
        $hExplicit = ($s->height !== null && !$s->height->isAuto() && !$s->height->isIntrinsic() && $s->height->toPx() > 0);
        if ($arVal > 0 && $rawW === null && $hExplicit && $h > 0) {
            $derivedW = (int)($h * $arVal);
            if ($derivedW > 0) {
                // clamp 到 min/max width（同 computeBlockWidth）
                $minWc = $s->minWidth?->toPx() ?? 0;
                $maxWc = $s->maxWidth?->toPx() ?? 0;
                if ($minWc > 0 && $maxWc > 0 && $minWc > $maxWc) $maxWc = $minWc;
                if ($maxWc > 0 && $derivedW > $maxWc) $derivedW = $maxWc;
                if ($minWc > 0 && $derivedW < $minWc) $derivedW = $minWc;
                $w = $derivedW;
            }
        }

        $positionVal = $s->position?->value ?? 'static';
        // 对标 Blink/CSS 规范：left/top 仅对定位元素（relative）生效，static 元素忽略
        $isRelative = ($positionVal === 'relative');
        // 坐标为相对坐标（约束根）。Blink NGConstraintSpace 的 bfc_offset 在 Px 中未启用，此处仅使用 margin+left/top。
        $x = (int)($marginLeft ?? 0) + ($isRelative ? (int)($left ?? 0) : 0);
        $y = (int)($marginTop ?? 0) + ($isRelative ? (int)($top ?? 0) : 0);

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
                        $newC = new ConstraintSpace($c->getContentWidth(), $computedH, $c->getParentContentX(), $c->getParentContentY(), 0, 0, $c->getPercentageWidth(), $computedH);
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
                // CSS 2.2 §10.6.3: auto-height 仅基于**正常流**子元素计算 — OOF (position:absolute/fixed) 不参与
                $chPos = $cr->style?->position?->value ?? 'static';
                if ($chPos === 'absolute' || $chPos === 'fixed') continue;
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

    private function computeBlockWidth(int $parentW, ComputedStyle $s, string $textContent, int $percBaseW = 0): int
    {
        $width = $s->width?->toPx() ?? 0;
        $sizing = $s->boxSizing?->value ?? 'content-box';
        // 百分比或 calc() 均需基于包含块解析
        if ($s->width !== null && ($s->width->isPercent() || $s->width->isCalc())) {
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

        // CSS 2.2 §10.2: 仅当 width 为 auto 时才用可用空间填充，显式 width:0 应尊重
        $hasExplicitWidth = ($s->width !== null && !$s->width->isAuto() && !$s->width->isPercent() && !$s->width->isIntrinsic());
        if ($width <= 0 && !$hasExplicitWidth) {
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
        // CSS-UI-3 §4.5: box-sizing:border-box 时 min-width/max-width 也按 border-box 解释
        // → 需减 padding+border 得到 content-box 下的可比较值（与 $width 尺度坐标一致）
        if ($sizing === 'border-box') {
            $padL = $s->padding?->left->toPx() ?? 0;
            $padR = $s->padding?->right->toPx() ?? 0;
            $bw = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
            $deduct = $padL + $padR + $bw;
            if ($minW > 0) $minW = max(0, $minW - $deduct);
            if ($maxW > 0) $maxW = max(0, $maxW - $deduct);
        }
        // CSS 2.2 §10.4：若 min > max，则先令 max := min（征集中优先保障 min）
        if ($minW > 0 && $maxW > 0 && $minW > $maxW) $maxW = $minW;
        if ($maxW > 0 && $width > $maxW) $width = $maxW;
        if ($minW > 0 && $width < $minW) $width = $minW;
        return (int)max(0, $width);
    }

    private function computeBlockHeight(int $parentH, ComputedStyle $s, string $textContent, int $percBaseH = 0): int
    {
        $height = $s->height?->toPx() ?? 0;
        $sizing = $s->boxSizing?->value ?? 'content-box';
        if ($s->height !== null && ($s->height->isPercent() || $s->height->isCalc())) {
            $ph = $percBaseH > 0 ? $percBaseH : $parentH;
            $height = $s->height->resolveInContext($ph);
            // CSS-UI-3 §4.5: border-box 时百分比 height 包含 padding+border
            if ($sizing === 'border-box') {
                $padT = $s->padding?->top->toPx() ?? 0;
                $padB = $s->padding?->bottom->toPx() ?? 0;
                $bwv = (int)($s->getBorderTopWidth() ?? 0) + (int)($s->getBorderBottomWidth() ?? 0);
                $height = max(0, $height - $padT - $padB - $bwv);
            }
        }
        if ($s->height !== null && $s->height->isIntrinsic() && strlen($textContent) > 0) { $height = $s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($s->getFontSize() * 1.2); }
        // CSS 2.2 §10.6: 仅当 height 为 auto 时才用内容高度，显式 height:0 应尊重
        $hasExplicitHeight = ($s->height !== null && !$s->height->isAuto() && !$s->height->isPercent() && !$s->height->isIntrinsic());
        if ($height <= 0 && !$hasExplicitHeight && strlen($textContent) > 0) { $height = $s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($s->getFontSize() * 1.2); }
        $ar = $s->getAspectRatio() ?? 0;
        if ($ar > 0 && $height <= 0) { $height = (int)(($s->width?->toPx() ?? 0) / $ar); }
        $minH = $s->minHeight?->toPx() ?? 0; $maxH = $s->maxHeight?->toPx() ?? 0;
        // CSS-UI-3 §4.5: box-sizing:border-box 时 min-height/max-height 也按 border-box 解释
        if ($sizing === 'border-box') {
            $padT = $s->padding?->top->toPx() ?? 0;
            $padB = $s->padding?->bottom->toPx() ?? 0;
            $bwv = (int)($s->getBorderTopWidth() ?? 0) + (int)($s->getBorderBottomWidth() ?? 0);
            $deductV = $padT + $padB + $bwv;
            if ($minH > 0) $minH = max(0, $minH - $deductV);
            if ($maxH > 0) $maxH = max(0, $maxH - $deductV);
        }
        // CSS 2.2 §10.4：若 min > max，则先令 max := min（优先保障 min）
        if ($minH > 0 && $maxH > 0 && $minH > $maxH) $maxH = $minH;
        if ($maxH > 0 && $height > $maxH) $height = $maxH;
        if ($minH > 0 && $height < $minH) $height = $minH;
        return (int)max(0, $height);
    }

    private function stackBlockChildren(int $parentX, int $parentY, int $containerW, ComputedStyle $s, array $childResults, string $textContent, int $parentW): array
    {
        // CSS 2.2 §8.3：padding/margin 百分比均基于包含块的宽度（inline-size）。
        // 父自身的 padding：基于祖父的 width，但此处无导入；使用 $parentW（父的约束宽）作为基准。
        // 子的 padding/margin：基于父的 content-width = $containerW。
        $padTop = $s->padding?->top->resolveBoxPercent($parentW) ?? 0;
        $padLeft = $s->padding?->left->resolveBoxPercent($parentW) ?? 0;
        $borderTop = (int)($s->getBorderTopWidth() ?? 0);
        $borderLeft = (int)($s->getBorderLeftWidth() ?? 0);
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

            // 子的 margin/padding 百分比基准 = 父的 content-width ($containerW)
            $mTop = $childStyle?->margin?->top->resolveBoxPercent($containerW) ?? 0;
            $mBottom = $childStyle?->margin?->bottom->resolveBoxPercent($containerW) ?? 0;
            $mLeft = $childStyle?->margin?->left->resolveBoxPercent($containerW) ?? 0;
            $mRight = $childStyle?->margin?->right->resolveBoxPercent($containerW) ?? 0;
            $chW = (int)($cr->getW() ?? 0);
            if ($chW <= 0) {
                $autoPadL = $childStyle?->padding?->left->resolveBoxPercent($containerW) ?? 0;
                $autoPadR = $childStyle?->padding?->right->resolveBoxPercent($containerW) ?? 0;
                $autoBw = (int)($childStyle?->getBorderLeftWidth() ?? 0) + (int)($childStyle?->getBorderRightWidth() ?? 0);
                $cs = $childStyle?->boxSizing?->value ?? 'content-box';
                $chW = ($cs === 'border-box') ? max(0, $containerW - $mLeft - $mRight) : max(0, $containerW - $mLeft - $mRight - $autoPadL - $autoPadR - $autoBw);
            }
            $chH = (int)($cr->getH() ?? 0);
            // 对标 Blink：从 Fragment 直接读取 type/content（非从 ComputedStyle 逗逸口取）
            // 之前使用 $childStyle->getRaw('_type' / '_content') 从 ComputedStyle 逗逸靠样式传递非样式数据，弱化不可变契约。
            $childType = (string)$cr->type;
            $childContent = (string)($cr->content ?? '');
            if (self::isInlineType($childType) && strlen($childContent) > 0 && $childStyle !== null) {
                $fs = $childStyle->getFontSize(); $bd = $childStyle->getBold();
                $measured = TextMeasureCache::measure($childContent, $fs, (bool)$bd);
                if ($measured > 0) $chW = $measured;
                if ($chH <= 0) $chH = $childStyle->getLineHeight() > 0 ? $childStyle->getLineHeight() : (int)($fs * 1.2);
            }
            $overflowY = $childStyle?->overflowY?->value ?? $childStyle?->overflow?->value ?? 'visible';
            // CSS 2.2 §9.4.1: BFC 边界检测——以下情况创建新 BFC，阻断 margin 折叠
            $childFloat = $childStyle?->getRaw('float') ?? 'none';
            $childFloatVal = is_object($childFloat) ? ($childFloat->value ?? 'none') : (string)$childFloat;
            $createsBFC = ($overflowY !== 'visible')
                || ($childPosition === 'absolute' || $childPosition === 'fixed')
                || ($childFloatVal !== 'none')
                || ($childDisplay === 'inline-block' || $childDisplay === 'table-cell'
                    || $childDisplay === 'flex' || $childDisplay === 'grid'
                    || $childDisplay === 'flow-root');
            $isCollapsible = ($childDisplay === 'block') && !$createsBFC;
            // ── CSS 2.2 §8.3.1 margin 折叠 (对标 Blink NGMarginStrut) ──
            // 相邻兄弟 block 且两侧均不创建新 BFC 时，将前章 mBottom 与当前 mTop 折叠：
            //   正值取 max，负值取 min（最负），两者相加 = MarginStrut.resolve()
            if ($isCollapsible && $prevCollapsible) {
                $strut = new MarginStrut();
                $strut->append($prevMarginBottom);
                $strut->append($mTop);
                // 撤销上一子项已加的 mBottom（包含在 $stackY），重新导入折叠后的值
                $childY = $stackY - $prevMarginBottom + $strut->resolve();
            } else {
                $childY = $stackY + $mTop;
            }
            $relTop = $childStyle?->top?->toPx() ?? 0;
            $relLeft = $childStyle?->left?->toPx() ?? 0;
            if ($childPosition === 'relative') { $childY += $relTop; }
            // CSS 2.2 §10.3.3: margin auto 水平居中
            // margin auto 检测：从 getRaw 读取（typed 属性的 isAuto() 在默认值上不可靠）
            $rawML = $childStyle?->getRaw('marginLeft');
            $rawMR = $childStyle?->getRaw('marginRight');
            $marginLeftAuto = ($rawML !== null && (is_object($rawML) ? ($rawML->isAuto ?? false) : ($rawML === 'auto')));
            $marginRightAuto = ($rawMR !== null && (is_object($rawMR) ? ($rawMR->isAuto ?? false) : ($rawMR === 'auto')));
            // 也检查 StyleResolver 计算的 auto 标志
            if (!$marginLeftAuto) { $marginLeftAuto = (bool)($childStyle?->getRaw('marginLeftAuto') ?? false); }
            if (!$marginRightAuto) { $marginRightAuto = (bool)($childStyle?->getRaw('marginRightAuto') ?? false); }
            // CSS 2.2 §10.3.3：`margin: 0 auto` 仅当 width 不为 auto 且有剩余空间时生效
            // 若 width 为 auto（chW 已满 containerW），auto margin 均作 0 处理
            $chHasExplicitW = ($childStyle?->width !== null
                && !$childStyle->width->isAuto()
                && $childStyle->width->toPx() > 0);
            $xOffset = $mLeft;
            if ($marginLeftAuto && $marginRightAuto && $chHasExplicitW && $chW < $containerW) {
                $xOffset = max(0, (int)(($containerW - $chW) / 2));
            } elseif ($marginLeftAuto && $chHasExplicitW && $chW < $containerW) {
                $xOffset = max(0, $containerW - $chW - $mRight);
            }
            // CSS 2.2 §10.6.3：子项从父的 padding-box 左上角开始（= parent origin + border-left + padding-left）
            // 之前 childX 缺少 borderLeft 导致与 childY 不对称 bug
            $result[] = new PhysicalFragment((int)($parentX + $borderLeft + $padLeft + $xOffset + ($childPosition === 'relative' ? $relLeft : 0)), (int)$childY, (int)$chW, (int)$chH, 0, 0, (int)($cr->getLayer() ?? 0), (int)($chW), (int)($chH), $childStyle, $cr->children, $cr->sourceNode,
                    $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                    $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles);
            // ── endMarginStrut 消费（CSS 2.2 §8.3.1 场景 3）──
            // 若子允许 endMarginStrut 上传（子无 padding-bottom/border-bottom/height + 末孙为 collapsible），
            // 则将子自己的 margin-bottom 与子的 endMarginStrut 折叠，作为 effectiveMBottom。
            // 代替 $prevMarginBottom，使得相邻兄弟折叠与后续 stackY 基于折叠后的值。
            $childEndStrut = $isCollapsible ? $this->extractEndMarginStrut($cr, $childStyle) : null;
            $effectiveMBottom = $mBottom;
            $absorbedBottom = 0;
            if ($childEndStrut !== null) {
                $strut = new MarginStrut();
                $strut->append($mBottom);
                $strut->appendStrut($childEndStrut);
                $effectiveMBottom = $strut->resolve();
                // 子 fragment 的 h 已包含末孙 mBottom（auto-height 下），需从 stackY 中减去。
                $absorbedBottom = $childEndStrut->resolve();
            }
            $stackY = ($childY - ($childPosition === 'relative' ? $relTop : 0)) + $chH - $absorbedBottom + $effectiveMBottom;
            $prevMarginBottom = $effectiveMBottom;
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
