<?php
namespace Px\Layout;
use native_types;
use Px\Css\ComputedStyle;

/**
 * InlineAlgorithm — 内联格式化上下文 (IFC) 布局算法
 *
 * 取代 InlineLayoutStrategy，完全自包含。
 * 将子节点单行水平排列（CSS §9.4.2 Inline formatting context）。
 */
class InlineAlgorithm extends LayoutAlgorithm
{
    /**
     * 计算 Inline 元素的内在尺寸（对标 Blink NGInlineNode::ComputeMinMaxSizes）。
     *
     * min-content: 最长不可断词宽度（当前简化为单字符宽度 — 因 Px 无 word break 算法）
     * max-content: 全文本单行宽度（不换行）
     */
    public function computeMinMaxSizes(
        ConstraintSpace $space,
        ?\Px\Css\ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
    ): MinMaxSizes {
        $s = $style ?? \Px\Css\StylePool::empty();
        $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
        $bold = (bool)($s->getBold() ?? false);

        if (strlen($textContent) > 0) {
            // max-content = 全文本不换行宽度
            $maxW = TextMeasureCache::measure($textContent, $fs, $bold);
            // min-content = 最长不可断词宽度
            // 简化：取文本测量宽度（同 max-content，因无 word-break 算法）
            // 未来可改为按空格/连字符断开取最长片段
            $minW = $maxW;
            return new MinMaxSizes($minW, $maxW);
        }

        // 有子项时：累加子项宽度作为 max-content，取单个最大子项作为 min-content
        $minC = 0;
        $maxC = 0;
        foreach ($childNodes as $child) {
            $cw = 0;
            if ($child instanceof \Px\Render\RenderNode) {
                $childStyle = $child->computedStyle;
                $explicitW = $childStyle?->width?->toPx() ?? 0;
                if ($explicitW > 0) {
                    $cw = (int)$explicitW;
                } else {
                    $childContent = (string)($child->content ?? '');
                    if (strlen($childContent) > 0) {
                        $cfs = $childStyle?->getFontSize() ?? $fs;
                        $cbd = (bool)($childStyle?->getBold() ?? false);
                        $cw = TextMeasureCache::measure($childContent, $cfs, $cbd);
                    }
                }
            } else if ($child instanceof PhysicalFragment) {
                $cw = (int)$child->getW();
            }
            if ($cw > $minC) $minC = $cw;
            $maxC += $cw;
        }

        return new MinMaxSizes($minC, $maxC);
    }

    public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        ?PhysicalFragment $inputFragment = null,
    ): PhysicalFragment {
        $s = $style ?? \Px\Css\StylePool::empty();
        // P2: 按需布局子项
        $children = [];
        for ($ii = 0, $ilen = count($childNodes); $ii < $ilen; $ii++) {
            $children[] = $this->layoutChild($childNodes[$ii]);
        }

        // Intrinsic measurement mode
        if ($space->getIsIntrinsicMeasurement()) {
            $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
            $w = strlen($textContent) > 0 ? TextMeasureCache::measure($textContent, $fs, (bool)($s->getBold() ?? false)) : 0;
            $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
            return new PhysicalFragment((int)max(0, $w), (int)max(0, $h), 0, 0, null, null, 0, 0, 0, $s, [], null, 0, 0, false, '', null, [], [], 0, (int)max(0, $w));
        }

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        // 对标 Blink NGInlineLayoutAlgorithm：存放相对于约束根的坐标，bfc_offset 在 Px 中未启用
        $x = $left;
        $y = $top;

        $w = $s->width?->toPx() ?? 0;
        // CSS 2.2 §10.3.5：inline-block 且 width auto 时采用 shrink-to-fit：
        //   width = min(max-content, max(min-content, available))
        // Phase 4A: 使用 computeMinMaxSizes 精确计算（替代旧的 childrenW+textW 代理）
        $isInlineBlock = (($s->display?->value ?? 'inline') === 'inline-block');
        if ($w <= 0) {
            $availableW = (int)($space->getContentWidth() ?? 0);
            if ($isInlineBlock) {
                // 精确内在尺寸计算（对标 Blink NGInlineNode::ComputeMinMaxSizes）
                $sizes = $this->computeMinMaxSizes($space, $s, $textContent, $childNodes);
                $padLR = (int)($s->padding?->left->toPx() ?? 0) + (int)($s->padding?->right->toPx() ?? 0);
                $bwLR = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
                $sizing = $s->boxSizing?->value ?? 'content-box';
                if ($sizing === 'border-box') {
                    $w = $sizes->shrinkToFit($availableW);
                } else {
                    // content-box: shrink-to-fit 在内容区域内计算
                    $contentAvail = max(0, $availableW - $padLR - $bwLR);
                    $w = $sizes->shrinkToFit($contentAvail);
                }
                // min-width clamp
                $minW = $s->minWidth?->toPx() ?? 0;
                if ($minW > 0 && $w < $minW) $w = $minW;
            } else {
                // inline / 其他：保持旧行为（fill available）
                $w = $availableW;
            }
        }
        $h = $s->height?->toPx() ?? 0;
        if (strlen($textContent) > 0 && (int)($h ?? 0) <= 0) {
            $h = ((int)($s->getLineHeight() ?? 0) > 0) ? (int)$s->getLineHeight() : (int)($s->getFontSize() * 1.2);
        }

        // IFC: arrange children in a single line
        $stackedChildren = [];
        $cursorX = $x;
        foreach ($children as $cr) {
            $stackedChildren[] = new PhysicalFragment(
                (int)$cursorX, (int)$y,
                (int)($cr->getW() ?? 0), (int)($cr->getH() ?? 0),
                (int)($cr->getVisualW() ?? $cr->getW() ?? 0),
                (int)($cr->getVisualH() ?? $cr->getH() ?? 0),
                (int)($cr->getLayer() ?? 0),
                (int)($cr->getContentWidth() ?? 0),
                (int)($cr->getContentHeight() ?? 0),
                $cr->style, $cr->children, $cr->sourceNode,
                $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles
            );
            $cursorX += (int)($cr->w ?? 0);
        }

        // Baseline：inline/inline-block 元素的 first-baseline = ascent ≈ fontSize * 0.8
        $inlineBaseline = (int)(($s->getFontSize() > 0 ? $s->getFontSize() : 16) * 0.8);

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h, $s->visualWidth($w), $s->visualHeight($h), 0, (int)$w, (int)$h, $s, $stackedChildren, null,
            0, 0, false, '', null, [], [], 0, '', $inlineBaseline);
    }

    /**
     * IFC 内联运行布局（支持换行）—— 对标 Blink NGInlineLayoutAlgorithm。
     *
     * 由 BlockAlgorithm 委派调用：块容器内的内联子项通过此方法布局，
     * 而非在 BlockAlgorithm 内部处理（抽象层次分离）。
     *
     * @param PhysicalFragment[] $items 内联子项 fragment
     * @param int $availableW 可用宽度
     * @param int $startX 起始 X
     * @param int $startY 起始 Y
     * @param int $padLeft 左 padding
     * @return array{items: PhysicalFragment[], nextY: int}
     */
    public static function layoutInlineRun(array $items, int $availableW, int $startX, int $startY, int $padLeft = 0): array
    {
        $result = [];
        $cursorX = $padLeft;
        $cursorY = 0;
        $lineMaxH = 0;

        foreach ($items as $cr) {
            $cStyle = $cr->style;
            $mLeft = $cStyle?->margin?->left->toPx() ?? 0;
            $mRight = $cStyle?->margin?->right->toPx() ?? 0;
            $mTop = $cStyle?->margin?->top->toPx() ?? 0;
            $mBottom = $cStyle?->margin?->bottom->toPx() ?? 0;
            $itemTotalW = ($cr->getW() ?? 0) + $mLeft + $mRight;
            $itemH = ($cr->getH() ?? 0) + $mTop + $mBottom;

            // 换行：当前行剩余空间不足时换到下一行
            if ($cursorX + $itemTotalW > $availableW && $cursorX > $padLeft) {
                $cursorY += $lineMaxH;
                $cursorX = $padLeft;
                $lineMaxH = 0;
            }

            $result[] = new PhysicalFragment(
                (int)($startX + $cursorX + $mLeft), (int)($startY + $cursorY + $mTop),
                (int)($cr->getW() ?? 0), (int)($cr->getH() ?? 0),
                0, 0, (int)($cr->getLayer() ?? 0),
                (int)($cr->getContentWidth() ?? 0), (int)($cr->getContentHeight() ?? 0),
                $cStyle, $cr->children, $cr->sourceNode,
                $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles,
                // textWidth + displayText（保留原值）
                (int)$cr->textWidth, (string)$cr->displayText,
                // Baseline（对标 Blink NGPhysicalLineBoxFragment）：inline item 基线 = ascent ≈ fontSize * 0.8
                $cr->getBaseline() > 0 ? $cr->getBaseline() : (int)(($cStyle?->getFontSize() ?? 16) * 0.8)
            );
            $cursorX += $itemTotalW;
            if ($itemH > $lineMaxH) $lineMaxH = $itemH;
        }

        return ['items' => $result, 'nextY' => $startY + $cursorY + $lineMaxH];
    }
}
