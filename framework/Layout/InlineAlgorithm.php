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
            // CSS 2.2 §10.8: 行高计算
            // line-height: <length> → 绝对值
            // line-height: <number> → fontSize * number
            // line-height: normal  → fontSize * 1.2（待 Phase 5 接入字体度量后改为 ascent+descent）
            $declaredLH = (int)($s->getLineHeight() ?? 0);
            if ($declaredLH > 0) {
                $h = $declaredLH;
            } else {
                $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
                $h = (int)($fs * 1.2);
            }
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
     * IFC 内联运行布局（支持换行 + 基线对齐）—— 对标 Blink NGInlineLayoutAlgorithm。
     *
     * Phase 4B: 使用 InlineItem + LineBreaker + LineBox 架构。
     * 代替旧的简单光标累加模式，实现：
     *   - 每行独立的 ascent/descent/line-height 计算
     *   - vertical-align: baseline 对齐（同行内项对齐到行基线）
     *   - CSS 2.2 §10.8.1 half-leading 模型
     *
     * @param PhysicalFragment[] $items 内联子项 fragment
     * @param int $availableW 可用宽度
     * @param int $startX 起始 X
     * @param int $startY 起始 Y
     * @param int $padLeft 左 padding
     * @param string $textAlign 容器 text-align（行级 ApplyTextAlign）
     * @param ComputedStyle|null $containerStyle IFC 容器样式（strut 字体 metrics 源）
     * @return array{items: PhysicalFragment[], nextY: int}
     */
    public static function layoutInlineRun(array $items, int $availableW, int $startX, int $startY, int $padLeft = 0, string $textAlign = 'start', ?ComputedStyle $containerStyle = null): array
    {
        if (empty($items)) return ['items' => [], 'nextY' => $startY];

        // 行盒 root inline box strut（对标 Blink NGInlineBoxState 根盒，CSS 2.2
        // §10.8.1：每个行盒含容器字体 strut，即使行内只有 atomic inline）。
        // 字体 metrics 用 Segoe UI 实比（asc≈0.919×em-box、全高 1.363×fs，真值
        // _gt_linestrut T1-T7 反解）；half-leading = (lineHeight - fontHeight)/2 可负，
        // 负分量在 LineBreaker 的 item max 中自然淘汰（line-height:0 → 行高=item 高）。
        $strutA = 0;
        $strutD = 0;
        if ($containerStyle !== null) {
            $lhUsed = (int)$containerStyle->getLineHeight();
            // 完整 strut 模型（CSS 2.2 §10.8.1 / Blink NGInlineBoxState）：
            //   显式值（>0 含 px/number）→ half-leading 模型；
            //   normal（-1 哨兵）→ 字体自然高（half-leading=0）；
            //   显式 0 → strut 负分量被 item max 淘汰（行高=item 高）。
            // 整数确定性算术（对标 Blink LayoutUnit 定点思想）：不依赖 round()
            // 库语义——PHP round 与 AOT Variant 链的半数/浮点行为分叉曾造成
            // 双模式 ≤3px 行盒级残差（compare_php_aot 20 case）。
            $cfs = (int)($containerStyle->getFontSize() ?: 16);
            $fontAscent = intdiv($cfs * 1088 + 500, 1000);   // Segoe UI ascent/em ≈1.088
            $fontDescent = intdiv($cfs * 275 + 500, 1000);   // Segoe UI descent/em ≈0.275
            if ($lhUsed < 0) {
                $lhUsed = $fontAscent + $fontDescent; // normal = 字体自然高
            }
            // round-half-away-from-zero 的纯整数形式：round(num/2)
            $hlNum = $lhUsed - ($fontAscent + $fontDescent);
            $halfLeading = intdiv($hlNum >= 0 ? $hlNum + 1 : $hlNum - 1, 2);
            $strutA = $fontAscent + $halfLeading;
            $strutD = $fontDescent + $halfLeading;
        }

        // Step 1: 构建 InlineItem 序列（对标 Blink InlineItemsBuilder）
        $inlineItems = [];
        foreach ($items as $cr) {
            $cStyle = $cr->style;
            // <br> → 强制断行项（对标 Blink NGInlineItem forced break）：忽略其
            // 预布局几何（block 预布局给了容器宽 0 高，属错误契约），宽 0、
            // 不贡献 ascent/descent（行高由 strut/同行项决定，§10.8.1）。
            if (($cr->type ?? '') === 'br') {
                $inlineItems[] = new InlineItem(
                    InlineItem::TYPE_FORCED_BREAK,
                    0, 0, 0, $cr, '', $cStyle, 0, 0
                );
                continue;
            }
            $fs = $cStyle?->getFontSize() ?? 16;
            if ($fs <= 0) $fs = 16;
            $mLeft = (int)($cStyle?->margin?->left->toPx() ?? 0);
            $mRight = (int)($cStyle?->margin?->right->toPx() ?? 0);
            $mTop = (int)($cStyle?->margin?->top->toPx() ?? 0);
            $mBottom = (int)($cStyle?->margin?->bottom->toPx() ?? 0);

            // atomic inline 的 ascent/descent：
            // CSS 2.2 §10.8.1: 替换元素/inline-block 的 content-box 高度作为 ascent
            // margin 单独处理（贡献行高但不影响基线计算）
            $itemH = (int)($cr->getH() ?? 0);
            $itemAscent = $itemH + $mTop + $mBottom; // 行盒贡献 = margin-box
            $itemDescent = 0;

            $inlineItems[] = new InlineItem(
                InlineItem::TYPE_ATOMIC,
                (int)($cr->getW() ?? 0),
                $itemAscent,
                $itemDescent,
                $cr,
                '',
                $cStyle,
                $mLeft,
                $mRight
            );
        }

        // Step 2: 行断裂（对标 Blink NGLineBreaker）
        $effectiveAvail = max(1, $availableW - $padLeft);
        $defaultLH = 19; // 16 * 1.2 ≈ 19
        $lines = LineBreaker::breakLines($inlineItems, $effectiveAvail, $defaultLH, $strutA, $strutD);

        // Step 3: 按行放置（对标 Blink NGPhysicalLineBoxFragment 布局）
        $result = [];
        $cursorY = 0;

        foreach ($lines as $line) {
            // text-align 行级偏移（对标 Blink NGInlineLayoutAlgorithm::ApplyTextAlign，
            // CSS 2.2 §16.2：作用于行盒内全部 inline-level box，含 inline-block）。
            // free 可为负（溢出行）：Blink 不 clamp，center 两侧均溢。
            $alignFree = $effectiveAvail - $line->width;
            $alignOffset = 0;
            if ($textAlign === 'center') {
                $alignOffset = (int)($alignFree / 2);
            } elseif ($textAlign === 'right' || $textAlign === 'end') {
                $alignOffset = $alignFree;
            }
            $cursorX = $padLeft + $alignOffset;
            foreach ($line->items as $item) {
                $cr = $item->fragment;
                if ($cr === null) continue;

                // <br> 放置：0 宽 × 行高盒（对齐 Blink br getBoundingClientRect：
                // 宽 0、高 = 行高），保留 dataset/sourceNode 供 px-id 对比。
                if ($item->type === InlineItem::TYPE_FORCED_BREAK) {
                    $result[] = new PhysicalFragment(
                        (int)($startX + $cursorX), (int)($startY + $cursorY),
                        0, (int)$line->height(),
                        0, 0, (int)($cr->getLayer() ?? 0),
                        0, (int)$line->height(),
                        $cr->style, [], $cr->sourceNode,
                        0, 0, false,
                        $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles
                    );
                    continue;
                }

                // vertical-align 实现（对标 Blink NGInlineLayoutAlgorithm::PlaceItems）
                $va = $item->style?->verticalAlign?->value ?? 'baseline';
                $lineH = $line->height();
                $itemContentH = (int)($cr->getH() ?? 0);
                $mTop = (int)($item->fragment->style?->margin?->top->toPx() ?? 0);

                switch ($va) {
                    case 'top':
                        // CSS 2.2 §10.8.1: 对齐行盒顶部
                        $itemY = $cursorY;
                        break;
                    case 'bottom':
                        // CSS 2.2 §10.8.1: 对齐行盒底部
                        $itemY = $cursorY + ($lineH - $itemContentH - $mTop);
                        break;
                    case 'middle':
                        // CSS 2.2 §10.8.1: 对齐行盒中线 (x-height/2 + baseline)
                        $itemY = $cursorY + (int)(($lineH - $itemContentH) / 2);
                        break;
                    default: // baseline
                        $itemY = $cursorY + ($line->baseline - $item->ascent);
                        if ($itemY < $cursorY) $itemY = $cursorY;
                        // baseline 模式加 margin-top 偏移
                        $itemY += $mTop;
                        break;
                }
                // top/bottom/middle 不加 mTop（已在计算中考虑）
                $finalY = ($va === 'baseline') ? $itemY : (int)($itemY + $mTop);
                if ($va === 'baseline') $finalY = $itemY; // 已在 switch 内加过
                else $finalY = (int)$itemY;

                $result[] = new PhysicalFragment(
                    (int)($startX + $cursorX + $item->marginLeft),
                    (int)($startY + $finalY),
                    (int)($cr->getW() ?? 0), (int)($cr->getH() ?? 0),
                    0, 0, (int)($cr->getLayer() ?? 0),
                    (int)($cr->getContentWidth() ?? 0), (int)($cr->getContentHeight() ?? 0),
                    $cr->style, $cr->children, $cr->sourceNode,
                    $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                    $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles,
                    (int)$cr->textWidth, (string)$cr->displayText,
                    $item->ascent
                );
                $cursorX += $item->totalWidth();
            }
            $cursorY += $line->height();
        }

        return ['items' => $result, 'nextY' => $startY + $cursorY];
    }
}
