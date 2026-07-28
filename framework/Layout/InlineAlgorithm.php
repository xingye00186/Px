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
                // inline（非 inline-block）：
                // CSS 2.2 §10.3.1 非替换 inline 宽度由内容决定——含元素子时
                // 宽 = 子 margin-box 之和 + 自身水平边缘（单行内容宽）。
                // 此前 fill-available 属错误契约：flex/grid 容器消费该预布局
                // 宽时 item 占满容器（E 716 vs Blink fit-content 64，case-024
                // 行内 x 累加 3350 溢出族；Blink flex item blockify + §9.2 auto
                // 主轴 = fit-content）。IFC 盒栈路径不消费预布局宽，不受影响。
                // 无子纯文本/空 inline 保持旧行为（窄口径，避免宽面回归）。
                if (count($children) > 0) {
                    $sumW = 0;
                    foreach ($children as $cw) {
                        $sumW += (int)($cw->getW() ?? 0)
                            + (int)($cw->style?->margin?->left->toPx() ?? 0)
                            + (int)($cw->style?->margin?->right->toPx() ?? 0);
                    }
                    $padLR2 = (int)($s->padding?->left->toPx() ?? 0) + (int)($s->padding?->right->toPx() ?? 0);
                    $bwLR2 = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
                    $w = $sumW + $padLR2 + $bwLR2;
                } elseif (strlen($textContent) > 0) {
                    // 纯文本 inline（CSS 2.2 §10.3.1 内容宽）：文本测量 + 水平边缘。
                    // 此前 fill-available 使伪元素/文本 span 在 IFC 中独占行
                    //（case-050 ::before 716×19 挤断后续 spans 实锤）。
                    $fs2 = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
                    $padLR2 = (int)($s->padding?->left->toPx() ?? 0) + (int)($s->padding?->right->toPx() ?? 0);
                    $bwLR2 = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
                    $w = TextMeasureCache::measure($textContent, $fs2, (bool)($s->getBold() ?? false)) + $padLR2 + $bwLR2;
                } else {
                    // 空 inline：保持旧行为（fill available，窄口径）
                    $w = $availableW;
                }
            }
        }
        $h = $s->height?->toPx() ?? 0;
        // 含元素子的 inline 盒 auto 高：内容行高（子 margin-box 高 max）。
        // 此前恒 0（无文本分支不覆盖）——flex 容器交叉轴尺寸/align-items 消费
        // item 高时塌陷（case-024 px-36 容器 h=0 vs Blink 25）。
        if ($h <= 0 && count($children) > 0) {
            $maxChH = 0;
            foreach ($children as $chh) {
                $chTot = (int)($chh->getH() ?? 0)
                    + (int)($chh->style?->margin?->top->toPx() ?? 0)
                    + (int)($chh->style?->margin?->bottom->toPx() ?? 0);
                if ($chTot > $maxChH) $maxChH = $chTot;
            }
            $h = $maxChH + (int)($s->padding?->top->toPx() ?? 0) + (int)($s->padding?->bottom->toPx() ?? 0);
        }
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
     * @param string $direction 容器 direction（ltr/rtl，CSS 2.2 §9.10）
     * @return array{items: PhysicalFragment[], nextY: int}
     */
    public static function layoutInlineRun(array $items, int $availableW, int $startX, int $startY, int $padLeft = 0, string $textAlign = 'start', ?ComputedStyle $containerStyle = null, string $direction = 'ltr'): array
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

        // Step 1: 构建 InlineItem 序列（对标 Blink InlineItemsBuilder，含
        // inline box 递归展开 kOpenTag/kCloseTag）
        $inlineItems = [];
        self::buildInlineItems($items, $inlineItems);

        // Step 2: 行断裂（对标 Blink NGLineBreaker）；text-indent 首行缩进（§16.1）
        $effectiveAvail = max(1, $availableW - $padLeft);
        $defaultLH = 19; // 16 * 1.2 ≈ 19
        $tiRaw = $containerStyle?->getRaw('textIndent');
        // getRaw 安全通道：部分构造路径（pseudo/轻量 CS）textIndent typed
        // 属性未初始化，直读 getter 抛 Error（L17 回归实锤）。
        // 烘焙声明为字符串（'40px'/'2em'，插桩实锤）：em 按容器 fontSize
        // 解析（CSS 2.2 §4.3.2；(int)'2em'=2 致 x=30 族）。
        $tIndent = 0;
        if ($tiRaw !== null) {
            if (is_object($tiRaw)) {
                $tIndent = (($tiRaw->unit ?? '') === 'em')
                    ? (int)($tiRaw->value * ($containerStyle?->getFontSize() ?: 16))
                    : (int)$tiRaw->toPx();
            } else {
                $tiStr = (string)$tiRaw;
                if (str_ends_with($tiStr, 'em')) {
                    $tIndent = (int)((float)$tiStr * ($containerStyle?->getFontSize() ?: 16));
                } else {
                    $tIndent = (int)$tiStr;
                }
            }
            if ($tIndent < 0) $tIndent = 0;
        }
        $lines = LineBreaker::breakLines($inlineItems, $effectiveAvail, $defaultLH, $strutA, $strutD, $tIndent);

        // Step 3: 按行放置（对标 Blink NGPhysicalLineBoxFragment 布局）
        $result = [];
        $cursorY = 0;
        // inline box 盒栈（对标 Blink NGInlineBoxState 栈）：open 时记录 result
        // 插入点，close 时将其间子 fragment 聚合为盒 fragment（union 几何，
        // 对齐 getBoundingClientRect 跨行包围盒语义），子项作为其 children
        //（保持与浏览器 DOM 同构的导出顺序：盒在前、子在后）。盒可跨行。
        $boxStack = [];

        $isFirstLine = true;
        // RTL 基方向（对标 Blink NGLineBreaker::ComputeBaseDirection + bidi 重排，
        // CSS 2.2 §9.10）：中性内容在 RTL 段落基底 level 1 下视觉逆序、从行
        // inline-start（右端）起排——实现为行级镜像 x' = L+R-(x+w)（等价全逆序）。
        // 镜像轴 = 行 content 区 [L, R]；text-align 逻辑值在镜像前预翻转（镜像后
        // 净效果：rtl+start=右 ✓ rtl+end=左 ✓ 物理 left/right 保持 ✓ center 不变 ✓）。
        // 限制：嵌套 inline 盒内部子序未逆序（完整 bidi level 栈范畴，children 平移）。
        $isRtl = ($direction === 'rtl');
        $mirrorAxisSum = 2 * ($startX + $padLeft) + $effectiveAvail;
        foreach ($lines as $line) {
            $lineStartIdx = count($result);
            // text-align 行级偏移（对标 Blink NGInlineLayoutAlgorithm::ApplyTextAlign，
            // CSS 2.2 §16.2：作用于行盒内全部 inline-level box，含 inline-block）。
            // free 可为负（溢出行）：Blink 不 clamp，center 两侧均溢。
            $alignFree = $effectiveAvail - $line->width;
            $alignOffset = 0;
            if ($textAlign === 'center') {
                $alignOffset = (int)($alignFree / 2);
            } elseif ($isRtl) {
                // RTL 预翻转（配合行尾镜像）：end/物理 left → 排右镜像后落左；
                // start/物理 right → 排左镜像后落右
                if ($textAlign === 'end' || $textAlign === 'left') {
                    $alignOffset = $alignFree;
                }
            } elseif ($textAlign === 'right' || $textAlign === 'end') {
                $alignOffset = $alignFree;
            }
            $cursorX = $padLeft + $alignOffset;
            // text-indent 仅首行（CSS 2.2 §16.1；LineBreaker 首行宽已同步预留）
            if ($isFirstLine) { $cursorX += $tIndent; $isFirstLine = false; }
            foreach ($line->items as $item) {
                $cr = $item->fragment;
                if ($cr === null) continue;

                // inline box 开标签：压栈记录插入点，光标前进 inline-start 边缘；
                // 盒的 vertical-align 产生 baseline shift 作用于盒内全部子项
                //（对标 Blink NGInlineBoxState::ComputeBaselineShift，§10.8.1：
                // sub 下移、super 上移；真值反演 @fs16：sub=+5、super=-11+5=-6
                // 相对前项均化后 sub≈+5/16em、super≈-6/16em，纯整数 intdiv）。
                // shift 累加（嵌套盒叠加，Blink 相对父盒链）。
                if ($item->type === InlineItem::TYPE_OPEN_TAG) {
                    $boxVa = $item->style?->verticalAlign?->value ?? 'baseline';
                    $boxFs = (int)($item->style?->getFontSize() ?: 16);
                    $vShift = 0;
                    if ($boxVa === 'sub') {
                        $vShift = intdiv($boxFs * 5, 16);
                    } elseif ($boxVa === 'super') {
                        $vShift = -intdiv($boxFs * 6, 16);
                    } elseif ($boxVa === 'text-top') {
                        // 盒顶对齐父内容区顶：行 strut ascent 差（真值 @fs16 ≈ +6）
                        $vShift = intdiv($boxFs * 6, 16);
                    } elseif ($boxVa === 'text-bottom') {
                        // 盒底对齐父内容区底（真值 @fs16 ≈ +5）
                        $vShift = intdiv($boxFs * 5, 16);
                    }
                    $curShift = count($boxStack) > 0 ? (int)$boxStack[count($boxStack) - 1]['vShift'] : 0;
                    $boxStack[] = ['item' => $item, 'startIndex' => count($result), 'vShift' => $curShift + $vShift];
                    $cursorX += $item->totalWidth();
                    continue;
                }
                // inline box 闭标签：弹栈，union 盒内子 fragment 生成盒 fragment
                if ($item->type === InlineItem::TYPE_CLOSE_TAG) {
                    $cursorX += $item->totalWidth();
                    if (empty($boxStack)) continue; // 防御：栈失衡不崩溃
                    $st = array_pop($boxStack);
                    $openItem = $st['item'];
                    $kids = array_slice($result, (int)$st['startIndex']);
                    $result = array_slice($result, 0, (int)$st['startIndex']);
                    $bStyle = $openItem->style;
                    $bPadL = (int)($bStyle?->padding?->left->toPx() ?? 0);
                    $bPadR = (int)($bStyle?->padding?->right->toPx() ?? 0);
                    $bPadT = (int)($bStyle?->padding?->top->toPx() ?? 0);
                    $bPadB = (int)($bStyle?->padding?->bottom->toPx() ?? 0);
                    $bbL = (int)($bStyle?->getBorderLeftWidth() ?? 0);
                    $bbR = (int)($bStyle?->getBorderRightWidth() ?? 0);
                    $minX = 0; $minY = 0; $maxX = 0; $maxY = 0; $first = true;
                    foreach ($kids as $k) {
                        $kx1 = (int)$k->getX(); $ky1 = (int)$k->getY();
                        $kx2 = $kx1 + (int)$k->getW(); $ky2 = $ky1 + (int)$k->getH();
                        if ($first) { $minX = $kx1; $minY = $ky1; $maxX = $kx2; $maxY = $ky2; $first = false; }
                        else {
                            if ($kx1 < $minX) $minX = $kx1;
                            if ($ky1 < $minY) $minY = $ky1;
                            if ($kx2 > $maxX) $maxX = $kx2;
                            if ($ky2 > $maxY) $maxY = $ky2;
                        }
                    }
                    $bFrag = $openItem->fragment;
                    if ($first) {
                        // 空盒（防御，正常不可达：仅非空 children 才展开）
                        $minX = (int)($startX + $cursorX); $minY = (int)($startY + $cursorY);
                        $maxX = $minX; $maxY = $minY;
                    }
                    // 横向：union + 自身 padding/border 边缘（getBoundingClientRect border-box）。
                    // 纵向（CSS §10.6.1：非替换 inline 高度由 font 决定，不含 atomic 子
                    // 溢出部分）：content 高 = em box（fontSize），基线锤定——atomic 子
                    // baseline 对齐后 bottom=行基线（margin 0 时），据此反推：
                    //   top = 首行基线 - ascent_em - padT，bottom = 末行基线 + descent_em + padB
                    // ascent_em = fs×1088/1363（Segoe UI 实比，整数确定性算术）。
                    // 真值支撑：case-019 code B(225,h20) vs 公式(226,h20)，±1px 入 tol。
                    $bfs2 = (int)($bStyle?->getFontSize() ?? 16);
                    if ($bfs2 <= 0) $bfs2 = 16;
                    $ascEm = intdiv($bfs2 * 1088 + 681, 1363);
                    $descEm = $bfs2 - $ascEm;
                    $firstKidBaseline = $minY; $lastKidBaseline = $maxY;
                    if (!$first) {
                        // atomic 子 bottom ≈ 所在行基线（baseline 对齐、margin 0）
                        $firstKidBaseline = (int)$kids[0]->getY() + (int)$kids[0]->getH();
                        $lastKidBaseline = $maxY;
                    }
                    $bx = $minX - $bPadL - $bbL;
                    $by = $firstKidBaseline - $ascEm - $bPadT;
                    $bw = ($maxX + $bPadR + $bbR) - $bx;
                    $bh = ($lastKidBaseline + $descEm + $bPadB) - $by;
                    $result[] = new PhysicalFragment(
                        (int)$bx, (int)$by, (int)$bw, (int)$bh,
                        0, 0, (int)($bFrag?->getLayer() ?? 0),
                        (int)max(0, $bw - $bPadL - $bPadR - $bbL - $bbR), (int)max(0, $bh - $bPadT - $bPadB),
                        $bStyle, $kids, $bFrag?->sourceNode,
                        0, 0, false,
                        (string)($bFrag?->type ?? ''), $bFrag?->content, $bFrag?->dataset ?? [], $bFrag?->pseudoStyles ?? []
                    );
                    continue;
                }

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
                    case 'sub':
                        // §10.8.1 subscript：基线下移 fs/5（Blink ComputeBaselineShift
                        // kSub 事实语义，WebKit 血统；纯整数）
                        $itemY = $cursorY + ($line->baseline - $item->ascent) + intdiv((int)($item->style?->getFontSize() ?: 16), 5);
                        if ($itemY < $cursorY) $itemY = $cursorY;
                        $itemY += $mTop;
                        break;
                    case 'super':
                        // §10.8.1 superscript：基线上移 fs×3/10（Blink kSuper）
                        $itemY = $cursorY + ($line->baseline - $item->ascent) - intdiv((int)($item->style?->getFontSize() ?: 16) * 3, 10);
                        if ($itemY < $cursorY) $itemY = $cursorY;
                        $itemY += $mTop;
                        break;
                    case 'text-top':
                        // §10.8.1：盒顶对齐父内容区顶 = 行基线 − 父 font ascent
                        //（不含 half-leading；Segoe UI 实比同 strut 模型）
                        $itemY = $cursorY + ($line->baseline - intdiv(((int)($containerStyle?->getFontSize() ?: 16)) * 1088 + 500, 1000));
                        if ($itemY < $cursorY) $itemY = $cursorY;
                        $itemY += $mTop;
                        break;
                    case 'text-bottom':
                        // §10.8.1：盒底对齐父内容区底 = 行基线 + 父 font descent
                        $itemY = $cursorY + ($line->baseline + intdiv(((int)($containerStyle?->getFontSize() ?: 16)) * 275 + 500, 1000)) - $itemContentH;
                        if ($itemY < $cursorY) $itemY = $cursorY;
                        $itemY += $mTop;
                        break;
                    default: // baseline
                        $itemY = $cursorY + ($line->baseline - $item->ascent);
                        if ($itemY < $cursorY) $itemY = $cursorY;
                        // baseline 模式加 margin-top 偏移
                        $itemY += $mTop;
                        break;
                }
                // top/bottom/middle 不加 mTop（已在计算中考虑）；基线系
                //（baseline/sub/super/text-top/text-bottom）已在 switch 内加过
                $isBaselineFamily = ($va === 'baseline' || $va === 'sub' || $va === 'super' || $va === 'text-top' || $va === 'text-bottom');
                $finalY = $isBaselineFamily ? $itemY : (int)$itemY;
                // 包围 inline 盒的 baseline shift（NGInlineBoxState 盒栈传播）
                if (count($boxStack) > 0) {
                    $finalY += (int)$boxStack[count($boxStack) - 1]['vShift'];
                }

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
            // RTL 行尾镜像：本行新增顶层 fragment 统一 x' = axisSum-(x+w)，
            // children 随顶层平移（atomic 内部是独立 BFC，不参与 IFC 逆序）。
            if ($isRtl) {
                $n = count($result);
                for ($ri = $lineStartIdx; $ri < $n; $ri++) {
                    $result[$ri] = self::mirrorFragmentX($result[$ri], $mirrorAxisSum);
                }
            }
            $cursorY += $line->height();
        }

        return ['items' => $result, 'nextY' => $startY + $cursorY];
    }

    /**
     * RTL 镜像重建（不可变 fragment）：顶层 x' = axisSum-(x+w)，children 递归
     * 平移 dx（保持内部相对布局：atomic 内部是独立 BFC，CSS 2.2 §9.10 仅
     * IFC 同层参与基方向逆序）。
     */
    private static function mirrorFragmentX(PhysicalFragment $f, int $axisSum): PhysicalFragment
    {
        $oldX = (int)$f->getX();
        $newX = $axisSum - $oldX - (int)$f->getW();
        return self::shiftFragmentX($f, $newX - $oldX);
    }

    /** 水平平移重建（递归 children，dx=0 时直接复用原 fragment） */
    private static function shiftFragmentX(PhysicalFragment $f, int $dx): PhysicalFragment
    {
        if ($dx === 0) return $f;
        $kids = [];
        foreach ($f->children as $k) $kids[] = self::shiftFragmentX($k, $dx);
        return new PhysicalFragment(
            (int)($f->getX() + $dx), (int)$f->getY(), (int)$f->getW(), (int)$f->getH(),
            (int)$f->getVisualW(), (int)$f->getVisualH(), (int)$f->getLayer(),
            (int)$f->getContentWidth(), (int)$f->getContentHeight(),
            $f->style, $kids, $f->sourceNode,
            (int)$f->getScrollTop(), (int)$f->getScrollLeft(), $f->getIsScrollContainer(),
            (string)$f->type, $f->content, $f->dataset, $f->pseudoStyles,
            (int)$f->textWidth, (string)$f->displayText, (int)$f->getBaseline()
        );
    }

    /**
     * 递归构建 InlineItem 序列（对标 Blink NGInlineItemsBuilder）。
     *
     * 非原子 inline box（display:inline 且含元素子节点，如 code/span 嵌套）
     * 展开为 kOpenTag + 子项 + kCloseTag：子项与兄弟同一 line breaker 序列
     *（可在盒内断行，CSS 2.2 §9.4.2）。此前该类盒被当 atomic 携带 block
     * 预布局几何（容器宽）独占行——后续兄弟全部错位（case-019/050 x 大偏移族）。
     *
     * open tag 携带 inline-start 边缘宽（margin/border/padding-left）与盒自身
     * 字体 strut（§10.8.1：inline box 以其 line-height 贡献行高，即使无文本；
     * 公式同 root strut，整数确定性算术）；close tag 携带 inline-end 边缘宽。
     *
     * @param PhysicalFragment[] $frags
     * @param InlineItem[] $out 输出序列（引用累积）
     */
    private static function buildInlineItems(array $frags, array &$out): void
    {
        foreach ($frags as $cr) {
            $cStyle = $cr->style;
            // <br> → 强制断行项（对标 Blink NGInlineItem forced break）：忽略其
            // 预布局几何（block 预布局给了容器宽 0 高，属错误契约），宽 0、
            // 不贡献 ascent/descent（行高由 strut/同行项决定，§10.8.1）。
            if (($cr->type ?? '') === 'br') {
                $out[] = new InlineItem(
                    InlineItem::TYPE_FORCED_BREAK,
                    0, 0, 0, $cr, '', $cStyle, 0, 0
                );
                continue;
            }
            $mLeft = (int)($cStyle?->margin?->left->toPx() ?? 0);
            $mRight = (int)($cStyle?->margin?->right->toPx() ?? 0);
            $disp = $cStyle?->display?->value ?? 'inline';
            if ($disp === 'inline' && !empty($cr->children)) {
                $padL = (int)($cStyle?->padding?->left->toPx() ?? 0);
                $padR = (int)($cStyle?->padding?->right->toPx() ?? 0);
                $bL = (int)($cStyle?->getBorderLeftWidth() ?? 0);
                $bR = (int)($cStyle?->getBorderRightWidth() ?? 0);
                // 盒自身 strut（同 root strut 公式；line-height 三态，-1=normal）
                $bfs = (int)($cStyle?->getFontSize() ?? 16);
                if ($bfs <= 0) $bfs = 16;
                $fa = intdiv($bfs * 1088 + 500, 1000);
                $fd = intdiv($bfs * 275 + 500, 1000);
                $blh = (int)($cStyle?->getLineHeight() ?? -1);
                if ($blh < 0) $blh = $fa + $fd;
                $hlN = $blh - ($fa + $fd);
                $hl = intdiv($hlN >= 0 ? $hlN + 1 : $hlN - 1, 2);
                $out[] = new InlineItem(InlineItem::TYPE_OPEN_TAG, $padL + $bL, $fa + $hl, $fd + $hl, $cr, '', $cStyle, $mLeft, 0);
                self::buildInlineItems($cr->children, $out);
                $out[] = new InlineItem(InlineItem::TYPE_CLOSE_TAG, $padR + $bR, 0, 0, $cr, '', $cStyle, 0, $mRight);
                continue;
            }

            // atomic inline（inline-block/替换元素/文本载体）的 ascent/descent：
            // CSS 2.2 §10.8.1: content-box 高度作为 ascent，margin 单独处理
            //（贡献行高但不影响基线计算）
            $mTop = (int)($cStyle?->margin?->top->toPx() ?? 0);
            $mBottom = (int)($cStyle?->margin?->bottom->toPx() ?? 0);
            $itemH = (int)($cr->getH() ?? 0);
            $out[] = new InlineItem(
                InlineItem::TYPE_ATOMIC,
                (int)($cr->getW() ?? 0),
                $itemH + $mTop + $mBottom, // 行盒贡献 = margin-box
                0,
                $cr,
                '',
                $cStyle,
                $mLeft,
                $mRight
            );
        }
    }
}
