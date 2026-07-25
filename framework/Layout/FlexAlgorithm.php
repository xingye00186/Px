<?php

namespace Px\Layout;

use native_types;
use Px\Css\ComputedStyle;
use Px\Layout\ConstraintSpace;
use Px\Layout\PhysicalFragment;
use Px\Layout\PhysicalFragmentBuilder;
use Px\Layout\Flex\FlexItem;
use Px\Layout\Flex\FlexLineBreaker;
use Px\Css\CssLength;

/**
 * FlexAlgorithm — Flex 布局算法（Phase 1 适配器）
 */
class FlexAlgorithm extends LayoutAlgorithm
{
    /**
     * 计算 Flex 容器的内在尺寸（对标 Blink NGFlexLayoutAlgorithm::ComputeMinMaxSizes）。
     *
     * CSS Sizing L3 + CSS Flexbox §9.9:
     *   - min-content (row): max(所有 item min-content)  （wrap时）
     *                    or  sum(所有 item min-content) + gaps （nowrap时）
     *   - max-content (row): sum(所有 item max-content) + gaps
     *   - column: sum(item heights) + gaps for min/max (but Px 简化为最大 item 宽)
     */
    public function computeMinMaxSizes(
        ConstraintSpace $space,
        ?\Px\Css\ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
    ): MinMaxSizes {
        $s = $style ?? \Px\Css\StylePool::empty();
        $isRow = ($s->flexDirection?->value ?? 'row') === 'row' || ($s->flexDirection?->value ?? 'row') === 'row-reverse';
        $isWrap = ($s->flexWrap?->value ?? 'nowrap') !== 'nowrap';
        $gap = (int)($s->gap?->toPx() ?? 0);

        $blockAlgo = new BlockAlgorithm();
        $minC = 0;
        $maxC = 0;
        $count = 0;

        foreach ($childNodes as $child) {
            if (!($child instanceof \Px\Render\RenderNode)) continue;
            $cs = $child->computedStyle;
            if ($cs === null) continue;
            $childDisplay = $cs->display?->value ?? 'block';
            if ($childDisplay === 'none') continue;
            $childPos = $cs->position?->value ?? 'static';
            if ($childPos === 'absolute' || $childPos === 'fixed') continue;

            // 子项有显式宽度时直接使用
            $explicitW = $cs->width?->toPx() ?? 0;
            if ($explicitW > 0 && !$cs->width->isPercent() && !$cs->width->isAuto()) {
                $itemMin = (int)$explicitW;
                $itemMax = (int)$explicitW;
            } else {
                $childContent = (string)($child->content ?? '');
                $childChildren = $child->children ?? [];
                if (!is_array($childChildren)) $childChildren = [];
                $sizes = $blockAlgo->computeMinMaxSizes($space, $cs, $childContent, $childChildren);
                $itemMin = $sizes->minContent;
                $itemMax = $sizes->maxContent;
            }

            if ($isRow) {
                if ($isWrap) {
                    // wrap: min-content = 最大单项
                    if ($itemMin > $minC) $minC = $itemMin;
                } else {
                    // nowrap: min-content = sum
                    $minC += $itemMin;
                }
                $maxC += $itemMax;
            } else {
                // column: 宽度取最大子项
                if ($itemMin > $minC) $minC = $itemMin;
                if ($itemMax > $maxC) $maxC = $itemMax;
            }
            $count++;
        }

        // 加 gap
        if ($isRow && $count > 1) {
            $totalGap = ($count - 1) * $gap;
            if (!$isWrap) $minC += $totalGap;
            $maxC += $totalGap;
        }

        // 加自身 padding + border
        $padLR = (int)($s->padding?->left->toPx() ?? 0) + (int)($s->padding?->right->toPx() ?? 0);
        $bwLR = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
        $minC += $padLR + $bwLR;
        $maxC += $padLR + $bwLR;

        return new MinMaxSizes($minC, $maxC);
    }

    public function layout(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = '', array $childNodes = [], ?PhysicalFragment $inputFragment = null): PhysicalFragment
    {
        $s = $style ?? \Px\Css\StylePool::empty();

        // ── Intrinsic measurement mode（由 intrinsicSize() 处理，此处不执行）──
        if ($space->isIntrinsicMeasurement) {
            return new PhysicalFragment(0, 0, 0, 0, 0, 0, 0, 0, 0, $s);
        }

        // ── P2: 按需布局子项（对标 Blink NGFlexLayoutAlgorithm：算法通过 LayoutChild 布局子项） ──
        $childResults = [];
        for ($fxi = 0, $fxlen = count($childNodes); $fxi < $fxlen; $fxi++) {
            $childResults[] = $this->layoutChild($childNodes[$fxi]);
        }

        $parentW = $space->getContentWidth();
        $parentH = $space->getContentHeight();
        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        // x/y 是相对于父容器的偏移，不应包含 parentContentY。
        // BlockAlgorithm::stackBlockChildren 会单独处理堆叠偏移。
        $x = $left;
        $y = $top;
        $w = $s->width->toPx();
        // 对标 Blink NGFlexLayoutAlgorithm：width:X% 需基于 parentW 解析（而非直接使用 raw 百分数值）
        if ($s->width !== null && $s->width->isPercent()) {
            $w = $s->width->resolveInContext($parentW);
        } else if ($w <= 0) {
            $w = $parentW;
        }
        // For flex items whose actual width is set by parent flex (inputFragment),
        // use the actual width instead of the constraint space parentW
        if ($inputFragment !== null && $inputFragment->getW() > 0 && $w <= 0) {
            $w = $inputFragment->getW();
        }
        $h = $s->height->toPx();
        // 同理：height:X% 基于 parentH 解析
        if ($s->height !== null && $s->height->isPercent() && $parentH > 0) {
            $h = $s->height->resolveInContext($parentH);
        }
        // 对标 Blink ConstraintSpace::is_fixed_block_size：父（如 grid stretch）强制固定块轴尺寸时，
        // height:auto 的 flex 容器使用父给定的 contentHeight 作为 definite 高度。
        $blockSizeForced = false;
        if ($h <= 0 && $space->getIsFixedBlockSize() && $parentH > 0) {
            $h = $parentH;
            $blockSizeForced = true;
        }

        // ── E5 修复（对标 Blink NGPhysicalBoxFragment）：
        // Fragment 的 x/y/w/h 为 border-box 位置与尺寸（与 BlockAlgorithm 一致），
        // 子项摆放使用 padding-box 内部坐标 innerX/Y 与 content-box 内部尺寸 innerW/H。
        $flexPadL = $s->padding?->left->toPx() ?? 0;
        $flexPadR = $s->padding?->right->toPx() ?? 0;
        $flexPadT = $s->padding?->top->toPx() ?? 0;
        $flexPadB = $s->padding?->bottom->toPx() ?? 0;
        $innerX = $x + $flexPadL;   // padding-box 左上角（子项摆放起点）
        $innerY = $y + $flexPadT;
        $innerW = max(0, $w - $flexPadL - $flexPadR);  // content-box 宽（子项可用主轴长度）
        $innerH = max(0, $h - $flexPadT - $flexPadB);
        $flexDir = $s->flexDirection !== null ? $s->flexDirection->value : 'row';
        $isRow = ($flexDir === 'row' || $flexDir === 'row-reverse');
        $isReverse = ($flexDir === 'row-reverse' || $flexDir === 'column-reverse');
        $justify = $s->justifyContent !== null ? $s->justifyContent->value : 'flex-start';
        $align = $s->alignItems !== null ? $s->alignItems->value : 'stretch';
        $alignContent = $s->alignContent !== null ? $s->alignContent->value : 'stretch';
        $wrap = $s->flexWrap !== null ? $s->flexWrap->value : 'nowrap';
        $isWrapping = ($wrap === "wrap" || $wrap === "wrap-reverse");
        $isWrapReverse = ($wrap === "wrap-reverse");
        $gap = $s->gap !== null ? $s->gap->toPx() : 0;
        // Main-axis and cross-axis dimensions — 子项分配基于 content-box 内部尺寸
        $containerMain = (int)($isRow ? $innerW : $innerH);
        $containerCross = (int)($isRow ? $innerH : $innerW);

        // ── Step 1: Collect flex items ──
        $flexItems = [];
        $flexItemData = [];
        // 建立 $flexItems 索引到 $childResults 原始索引的映射（display:none skip 后索引错位）
        $flexItemOrigIdx = [];
        // CSS Flexbox §4.1：position:absolute/fixed flex items 仅参与 static position 计算，
        // 不占主轴空间。收集后直接追加到最终 fragmentChildren，不参与 flex 分配。
        $absoluteFlexItemsPassthrough = [];
        for ($crI = 0, $crLen = count($childResults); $crI < $crLen; $crI++) {
            $cr = $childResults[$crI];
            $cs = $cr->style;
            if ($cs === null) continue;
            // CSS §9.2: 跳过 display:none 的子项（不影响 flex 布局）
            $childDisplay = $cs->display?->value ?? 'block';
            if ($childDisplay === 'none') continue;
            // CSS Flexbox §4.1: absolute/fixed flex items 不参与主轴分配，直通到输出
            $childPos = $cs->position?->value ?? 'static';
            if ($childPos === 'absolute' || $childPos === 'fixed') {
                $absoluteFlexItemsPassthrough[] = $cr;
                continue;
            }
            $flexItemOrigIdx[] = $crI;
            // Phase 4D: 优先读 longhand（启用 flex 展开后可用），回退到 $cs->flex 短手对象
            $rawGrow = $cs->getRaw('flexGrow');
            if ($rawGrow !== null) {
                $grow = (float)(is_object($rawGrow) ? ($rawGrow instanceof CssLength ? $rawGrow->toPx() : ($rawGrow->value ?? 0)) : $rawGrow);
            } else {
                $grow = (float)$cs->flex->grow;
            }
            $rawShrink = $cs->getRaw('flexShrink');
            if ($rawShrink !== null) {
                $shrink = (float)(is_object($rawShrink) ? ($rawShrink instanceof CssLength ? $rawShrink->toPx() : ($rawShrink->value ?? 1)) : $rawShrink);
            } else {
                $shrink = (float)$cs->flex->shrink;
            }
            $rawOrder = $cs->getRaw("order");
            $order = $rawOrder !== null ? (is_object($rawOrder) ? (int)$rawOrder->toPx() : (int)$rawOrder) : 0;
            // flex-basis from resolved CssLength (not raw string from getRaw)
            // CSS Flexbox §7.1：flex-basis 取值可为长度/百分比/auto/content/min-content/max-content/fit-content。
            // - auto/content: 使用子项 content size (basis=-1 fallback 到 child.w/h)
            // - min-content/max-content/fit-content: Px 无完整 intrinsic 计算，降级为 content size 代理（同 auto）
            // - 百分比：基于容器**主轴尺寸**解析（CSS Flexbox §7.1.1）
            // - 长度（含 0）: 使用具体值。修复旧 bug：toPx()>0 将 flex-basis:0 错误归为 auto。
            $basisVal = $cs->flexBasis;
            // CSS Flexbox §7.1：flex 简写（如 flex:1）未展开为独立 flexBasis key，导致 $cs->flexBasis 仍为 auto。
            // 回退到 $cs->flex->basis（CssFlex 对象，由 flex 简写解析生成）。
            if (($basisVal === null || $basisVal->isAuto()) && $cs->flex !== null && $cs->flex->basis !== null && !$cs->flex->basis->isAuto()) {
                $basisVal = $cs->flex->basis;
            }
            $basis = -1;
            if ($basisVal instanceof CssLength && !$basisVal->isAuto() && !$basisVal->isContent() && !$basisVal->isIntrinsic()) {
                // 百分比 / calc 需基于容器主轴尺寸解析；其他长度（px 等）直接 toPx
                if ($basisVal->isPercent() || $basisVal->isCalc()) {
                    $basis = $basisVal->resolveInContext($containerMain);
                } else {
                    // 具体长度得到具体值（包括 0）——不以 >0 为条件
                    $basis = $basisVal->toPx();
                }
                if ($basis < 0) $basis = 0;
            }
            $hasExplicitCross = $cs->getRaw($isRow ? 'height' : 'width') !== null;
            $alignSelfRaw = $cs->getRaw('alignSelf');
            $item = new FlexItem();
            $item->grow = $grow;
            $item->shrink = $shrink;
            $item->basis = $basis;
            $item->isFlexGrow = ($grow > 0);
            $item->alignSelf = ($alignSelfRaw !== null && $alignSelfRaw !== 'auto') ? (is_object($alignSelfRaw) ? ($alignSelfRaw->value ?? 'auto') : (string)$alignSelfRaw) : 'auto';
            $item->computedStyle = $cs;
            // 对标 Blink：从 Fragment 直接读取 content（非从 ComputedStyle 逗逸口取）
            $item->content = $cr->content !== null ? (string)$cr->content : null;
            $item->originalChildren = $cr->children;
            // Phase 4D: 保存源 RenderNode 用于 computeMinMaxSizes 递归
            $item->node = ($crI < count($childNodes)) ? $childNodes[$crI] : null;
            $item->w = (int)$cr->getW(); $item->h = (int)$cr->getH();
            // Flex items without explicit width/height: ignore block auto-fill
            // (flex algorithm determines their main size)
            $hasMainSize = $isRow
                ? ($cs->getRaw("width") !== null || $cs->width?->toPx() > 0)
                : ($cs->getRaw("height") !== null || $cs->height?->toPx() > 0);
            // CSS flexbox §9.2: 有显式 CSS 主尺寸时用 CSS 值覆盖 BlockAlgorithm auto-fill
            $rawW = $cs->getRaw("width");
            $rawH = $cs->getRaw("height");
            if ($isRow && $rawW !== null && $cs->width !== null && !$cs->width->isPercent()) {
                $item->w = max(0, $cs->width->toPx());
            }
            if (!$isRow && $rawH !== null && $cs->height !== null && !$cs->height->isPercent()) {
                $item->h = max(0, $cs->height->toPx());
            }
            if (!$hasMainSize && $basis <= 0) {
                // 对标 Blink NGFlexLayoutAlgorithm: basis=auto 无显式主尺寸时 → 使用子项 content 尺寸作为 basis（而非重置为 0）
                // 仅当子项 fragment 本身也无主尺寸时才重置为 0
                if ($isRow) {
                    if ($item->w <= 0) {
                        // CSS Flexbox §9.2.3.E：flex-basis:auto + 主尺寸 auto → max-content size
                        // （与交叉轴 fit-content 同源；此前文本子项主轴宽塔陷为 0）
                        $mcContent = (string)($item->content ?? '');
                        $mcChildren = $item->node?->children ?? [];
                        if (!is_array($mcChildren)) $mcChildren = [];
                        if ($mcContent !== '' && count($mcChildren) === 0) {
                            // 纯文本快速路径：直接 TextMeasureCache（避免每帧 BlockAlgorithm 分配，bench 验证 LiveDashboard -6.6%）
                            $mcFs = $cs->getFontSize() > 0 ? $cs->getFontSize() : 16;
                            $mcW = TextMeasureCache::measure($mcContent, $mcFs, (bool)($cs->getBold() ?? false));
                            // border-box 主尺寸 = 文本宽 + padding + border（与 computeMinMaxSizes 一致）
                            $mcW += (int)($cs->padding?->left->toPx() ?? 0) + (int)($cs->padding?->right->toPx() ?? 0)
                                  + (int)($cs->getBorderLeftWidth() ?? 0) + (int)($cs->getBorderRightWidth() ?? 0);
                            if ($mcW > 0) $item->w = min($mcW, (int)$containerMain);
                        } else if (count($mcChildren) > 0) {
                            // 容器子项：复用 RenderNode.cachedMinMaxSizes（§11.3 缓存）
                            $mcNode = $item->node;
                            if ($mcNode !== null && $mcNode->cachedMinMaxSizes !== null && !$mcNode->layoutDirty) {
                                $mcSizes = $mcNode->cachedMinMaxSizes;
                            } else {
                                $mcAlgo = new BlockAlgorithm();
                                $mcSizes = $mcAlgo->computeMinMaxSizes($space, $cs, $mcContent, $mcChildren);
                                if ($mcNode !== null) $mcNode->cachedMinMaxSizes = $mcSizes;
                            }
                            if ($mcSizes->maxContent > 0) {
                                $item->w = min($mcSizes->maxContent, (int)$containerMain);
                            }
                        }
                    }
                } else {
                    if ($item->h <= 0) $item->h = 0;
                }
            }
            // 对标 Blink：flex item 含文本且高度 auto 时，内容高 = line-height（CSS 2.2 §10.6.3）。
            // 精准化守卫：仅 flex item 路径（不改 BlockAlgorithm 热路径，避免全局文本 auto-height 的 -11.9% 代价）。
            // 此前文本子项高度塔陷为 0（row 交叉轴 / column 主轴均受影响）。
            if ($item->h <= 0) {
                $thContent = (string)($item->content ?? '');
                $thChildren = $item->node?->children ?? [];
                $thHasChildren = is_array($thChildren) && count($thChildren) > 0;
                if ($thContent !== '' && !$thHasChildren) {
                    $thLH = (int)($cs->getLineHeight() ?? 0);
                    if ($thLH <= 0) {
                        $thFs = $cs->getFontSize() > 0 ? $cs->getFontSize() : 16;
                        $thLH = (int)($thFs * 1.2);
                    }
                    // border-box 高 = 行高 + padding + border（与主轴 max-content 快速路径同源语义）
                    $thLH += (int)($cs->padding?->top->toPx() ?? 0) + (int)($cs->padding?->bottom->toPx() ?? 0)
                           + (int)($cs->getBorderTopWidth() ?? 0) + (int)($cs->getBorderBottomWidth() ?? 0);
                    $item->h = $thLH;
                }
            }
            // Use visualW/H as fallback when content size is 0 (nested flex with explicit main size)
            if ($item->w <= 0 && $isRow && $hasMainSize) $item->w = (int)$cr->getVisualW();
            if ($item->h <= 0 && !$isRow && $hasMainSize) $item->h = (int)$cr->getVisualH();
            $item->visualW = (int)$cr->getVisualW(); $item->visualH = (int)$cr->getVisualH();
            // 读取主轴 margin（包含 auto 标志）— CSS Flexbox §8.1: margin auto 分配剩余主轴空间
            $mLeft = $cs->margin?->left ?? null;
            $mRight = $cs->margin?->right ?? null;
            $mTop = $cs->margin?->top ?? null;
            $mBottom = $cs->margin?->bottom ?? null;
            if ($isRow) {
                $item->marginBefore = ($mLeft !== null && !$mLeft->isAuto()) ? (int)$mLeft->toPx() : 0;
                $item->marginAfter = ($mRight !== null && !$mRight->isAuto()) ? (int)$mRight->toPx() : 0;
                $item->marginAutoBefore = ($mLeft !== null && $mLeft->isAuto());
                $item->marginAutoAfter = ($mRight !== null && $mRight->isAuto());
                $item->marginCrossBefore = ($mTop !== null && !$mTop->isAuto()) ? (int)$mTop->toPx() : 0;
                $item->marginCrossAfter = ($mBottom !== null && !$mBottom->isAuto()) ? (int)$mBottom->toPx() : 0;
            } else {
                $item->marginBefore = ($mTop !== null && !$mTop->isAuto()) ? (int)$mTop->toPx() : 0;
                $item->marginAfter = ($mBottom !== null && !$mBottom->isAuto()) ? (int)$mBottom->toPx() : 0;
                $item->marginAutoBefore = ($mTop !== null && $mTop->isAuto());
                $item->marginAutoAfter = ($mBottom !== null && $mBottom->isAuto());
                $item->marginCrossBefore = ($mLeft !== null && !$mLeft->isAuto()) ? (int)$mLeft->toPx() : 0;
                $item->marginCrossAfter = ($mRight !== null && !$mRight->isAuto()) ? (int)$mRight->toPx() : 0;
            }
            $flexItems[] = $item;
            $flexItemData[] = [
                'grow' => $grow, 'shrink' => $shrink, 'basis' => $basis,
                'isFlexGrow' => ($grow > 0), 'hasExplicitCrossSize' => $hasExplicitCross,
                'crossAxisSized' => false, 'order' => $order,
                'marginLeft' => 0, 'marginRight' => 0, 'marginTop' => 0, 'marginBottom' => 0,
            ];
        }
        if (count($flexItems) === 0) {
            return new PhysicalFragment(
                (int)$x, (int)$y, (int)$w, (int)$h,
                (int)$s->visualWidth($w), (int)$s->visualHeight($h),
                0, 0, 0, $s
            );
        }

        // Sort by CSS order property (stable sort: equal order preserves source order)
        // Manual insertion sort avoids usort + closure ZendVM dispatch
        $indices = range(0, count($flexItems) - 1);
        $originalIndices = $indices;
        $n = count($indices);
        for ($i = 1; $i < $n; $i++) {
            $tmp = (int)($indices[$i]);
            $otmp = (int)($flexItemData[$tmp]['order'] ?? 0);
            $j = $i;
            while ($j > 0) {
                $k = (int)($indices[$j - 1]);
                $ok = (int)($flexItemData[$k]['order'] ?? 0);
                if ($otmp < $ok || ($otmp === $ok && $tmp < $k)) {
                    $indices[$j] = $indices[$j - 1];
                    $j--;
                } else {
                    break;
                }
            }
            $indices[$j] = $tmp;
        }
        $sortedFlexItems = []; foreach ($indices as $idx) { $sortedFlexItems[] = $flexItems[$idx]; }
        // Rebuild flexItemData in sorted order
        // 使用 $flexItemOrigIdx 映射到 childResults 原始索引（避免 display:none skip 后错位）
        $sortedFlexItemData = []; $sortedChildResults = []; $sortedOrigIdx = [];
        foreach ($indices as $idx2) {
            $sortedFlexItemData[] = $flexItemData[$idx2];
            $origIdx = $flexItemOrigIdx[$idx2] ?? $idx2;
            $sortedChildResults[] = $childResults[$origIdx];
            $sortedOrigIdx[] = $origIdx;
        }

        // ── Step 2: Apply flex-basis ──
        foreach ($sortedFlexItems as $fi) {
            $fi = objval($fi, FlexItem::class);
            // CSS Flexbox §9.3: hypothetical main size = basis（包括 0）。
            // basis=-1 表示 auto/content（保留 layoutChild 结果）；basis>=0 则显式设定。
            if ($fi->basis >= 0) { if ($isRow) $fi->w = $fi->basis; else $fi->h = $fi->basis; }
        }

        // ── Step 3: Break into lines (FlexLineBreaker) ──
        $breaker = new FlexLineBreaker();
        $lines = $breaker->breakLines($sortedFlexItems, $sortedFlexItemData, $isWrapping, $isRow, $containerMain, $gap);
        $lineGroups = $lines[0];
        $lineData = $lines[1] ?? [];
        $totalLines = count($lineGroups);

        // ── Step 4: Per-line grow/shrink + main-axis positioning ──
        // Phase 4D: CSS Flexbox §9.7.4 clamp rerun 循环（对标 Blink ResolveFlexibleLengths）
        // CSS Flexbox §9.7: 主轴尺寸不定（column+height:auto 或 row+width:auto）时不 shrink，
        // 容器应增长到内容尺寸而非压缩子项（对标 Blink NGFlexLayoutAlgorithm 的 definite main size 判断）。
        $mainRaw = $isRow ? $s->getRaw('width') : $s->getRaw('height');
        $mainSizeIsDefinite = (($mainRaw !== null) || (!$isRow && $blockSizeForced)) && $containerMain > 0;
        $lineMaxCrosses = [];
        foreach ($lineGroups as $lineIdx => $lineItems) {
            // §9.7: Resolving Flexible Lengths (frozen loop)
            $itemsInLine = count($lineItems);
            $totalGap = ($itemsInLine - 1) * $gap;
            $availableAfterGap = max(0, $containerMain - $totalGap);
        
            // 初始化 frozen 状态 + 预计算 min-width:auto 缓存（避免循环内重复计算）
            foreach ($lineItems as $fi) {
                $fi = objval($fi, FlexItem::class);
                $fi->frozen = false;
                // 预计算 min-width:auto 值（只算一次，缓存到 FlexItem）
                $fcs = $fi->computedStyle;
                if ($fcs !== null && $isRow && ($fcs->minWidth?->toPx() ?? 0) === 0 && $fcs->minWidth !== null && $fcs->minWidth->isAuto()) {
                    if ($fi->basis === 0) {
                        $fi->cachedMinW = 0;
                    } else {
                        $blockAlgo = new BlockAlgorithm();
                        $childContent = (string)($fi->node->content ?? '');
                        $childChildren = $fi->node->children ?? [];
                        if (!is_array($childChildren)) $childChildren = [];
                        $sizes = $blockAlgo->computeMinMaxSizes($space, $fcs, $childContent, $childChildren);
                        $fi->cachedMinW = $sizes->minContent;
                    }
                } else {
                    $fi->cachedMinW = -1; // -1 = 未设置
                }
            }
        
            // Clamp rerun loop (max 5 iterations to prevent infinite loop)
            for ($rerunIter = 0; $rerunIter < 5; $rerunIter++) {
                // 计算未冻结项的总尺寸和 grow/shrink 总量
                $unfrozenTotal = 0;
                $unfrozenGrowTotal = 0;
                $unfrozenShrinkWeightTotal = 0;
                $frozenTotal = 0;
                foreach ($lineItems as $fi) {
                    $fi = objval($fi, FlexItem::class);
                    $sz = $isRow ? $fi->w : $fi->h;
                    if ($fi->frozen) {
                        $frozenTotal += $sz;
                    } else {
                        $unfrozenTotal += $sz;
                        $unfrozenGrowTotal += $fi->grow;
                        $unfrozenShrinkWeightTotal += $sz * $fi->shrink;
                    }
                }
        
                $totalUsed = $frozenTotal + $unfrozenTotal;
                $freeSpace = $availableAfterGap - $totalUsed;
        
                // 分配空间
                if ($freeSpace > 0 && $unfrozenGrowTotal > 0) {
                    // Grow
                    foreach ($lineItems as $fi) {
                        $fi = objval($fi, FlexItem::class);
                        if ($fi->frozen || $fi->grow <= 0) continue;
                        $extra = (int)($freeSpace * $fi->grow / $unfrozenGrowTotal);
                        if ($isRow) { $fi->w += $extra; $fi->visualW += $extra; } else $fi->h += $extra;
                    }
                } else if ($freeSpace < 0 && $unfrozenShrinkWeightTotal > 0 && $mainSizeIsDefinite) {
                    // Shrink（仅主轴尺寸 definite 时；CSS Flexbox §9.7）
                    $overflow = -$freeSpace;
                    foreach ($lineItems as $fi) {
                        $fi = objval($fi, FlexItem::class);
                        if ($fi->frozen || $fi->shrink <= 0) continue;
                        $sz = $isRow ? $fi->w : $fi->h;
                        $reduction = (int)($overflow * $sz * $fi->shrink / $unfrozenShrinkWeightTotal);
                        if ($isRow) { $fi->w = max(0, $fi->w - $reduction); $fi->visualW = $fi->w; }
                        else $fi->h = max(0, $fi->h - $reduction);
                    }
                }
        
                // Clamp + freeze 检测
                $anyFrozen = false;
                foreach ($lineItems as $fi) {
                    $fi = objval($fi, FlexItem::class);
                    if ($fi->frozen) continue;
                    $fcs = $fi->computedStyle;
                    if ($fcs === null) continue;
                    $maxW = $fcs->maxWidth?->toPx() ?? 0;
                    $minW = $fcs->minWidth?->toPx() ?? 0;
                    $maxH = $fcs->maxHeight?->toPx() ?? 0;
                    $minH = $fcs->minHeight?->toPx() ?? 0;
                    // min-width:auto 处理（使用预计算缓存）
                    if ($isRow && $minW === 0 && $fcs->minWidth !== null && $fcs->minWidth->isAuto()) {
                        $minW = $fi->cachedMinW >= 0 ? $fi->cachedMinW : 0;
                    }
                    if (!$isRow && $minH === 0 && $fcs->minHeight !== null && $fcs->minHeight->isAuto()) {
                        $minH = max((int)$fi->visualH, (int)$fi->basis > 0 ? (int)$fi->basis : 0);
                    }
                    if ($minW > 0 && $maxW > 0 && $minW > $maxW) $maxW = $minW;
                    if ($minH > 0 && $maxH > 0 && $minH > $maxH) $maxH = $minH;
        
                    $clamped = false;
                    if ($isRow) {
                        if ($maxW > 0 && $fi->w > $maxW) { $fi->w = $maxW; $clamped = true; }
                        if ($minW > 0 && $fi->w < $minW) { $fi->w = $minW; $clamped = true; }
                    } else {
                        if ($maxH > 0 && $fi->h > $maxH) { $fi->h = $maxH; $clamped = true; }
                        if ($minH > 0 && $fi->h < $minH) { $fi->h = $minH; $clamped = true; }
                    }
                    if ($clamped) {
                        $fi->frozen = true;
                        $fi->visualW = $fi->w;
                        $fi->visualH = $fi->h;
                        $anyFrozen = true;
                    }
                }
        
                // 无新冻结项 → 退出循环
                if (!$anyFrozen) break;
            }
        
            // 最终确认 visual 尺寸
            foreach ($lineItems as $fi) {
                $fi = objval($fi, FlexItem::class);
                $fi->visualW = $fi->w;
                $fi->visualH = $fi->h;
            }

            // 4c. Recalc line totals + max cross (at least container cross for single-line)
            $lineFinal = 0;
            $lineMaxCross = 0;
            foreach ($lineItems as $fi) {
                $fi = objval($fi, FlexItem::class);
                $lineFinal += $isRow ? (int)$fi->w : (int)$fi->h;
                $cross = (int)($isRow ? $fi->h : $fi->w);
                if ($cross > $lineMaxCross) $lineMaxCross = $cross;
            }
            // CSS: single-line flex uses container cross-size as stretch minimum
            if ($totalLines === 1 && (int)$containerCross > 0 && (int)$lineMaxCross < (int)$containerCross) {
                $lineMaxCross = (int)$containerCross;
            }

            // 4d. Justify-content (uses containerMain)
            // CSS Flexbox §9.5.1: gap 应从可用空间中扣除
            $mainStart = 0; $spaceBetween = 0;
            $itemCount = count($lineItems);
            $totalGap = $itemCount > 1 ? ($itemCount - 1) * $gap : 0;
            $availableMain = max(0, $containerMain - $totalGap);
            if ($justify === "center") { $mainStart = ($availableMain - $lineFinal) / 2; }
            elseif ($justify === "flex-end") { $mainStart = $availableMain - $lineFinal; }
            elseif ($justify === "space-between" && $itemCount > 1) { $spaceBetween = ($availableMain - $lineFinal) / ($itemCount - 1); }
            elseif ($justify === "space-around") { $spaceBetween = ($availableMain - $lineFinal) / $itemCount; $mainStart = $spaceBetween / 2; }
            elseif ($justify === "space-evenly") { $spaceBetween = ($availableMain - $lineFinal) / ($itemCount + 1); $mainStart = $spaceBetween; }

            // 4d+. CSS Flexbox §8.1: auto margin 优先于 justify-content 分配剩余主轴空间
            // 当行内存在 auto margin（包含 item 内项总和一使用完剩余后），则：
            //   1. justify-content 被 override 为 flex-start (mainStart=0, spaceBetween=0)
            //   2. remaining space 平均分配给行内所有 auto margins
            $autoMarginCount = 0;
            foreach ($lineItems as $fi) {
                $fi = objval($fi, FlexItem::class);
                if ($fi->marginAutoBefore) $autoMarginCount++;
                if ($fi->marginAutoAfter) $autoMarginCount++;
            }
            $autoMarginSpace = 0;
            if ($autoMarginCount > 0) {
                $remaining = max(0, $availableMain - $lineFinal);
                $autoMarginSpace = (int)($remaining / $autoMarginCount);
                // 有 auto margin 时重置 justify-content 分配
                $mainStart = 0;
                $spaceBetween = 0;
            }

            // 4e. Apply stretch to fill line maxCross; allow from zero (CSS stretch spec)
            foreach ($lineItems as $fi) {
                $fi = objval($fi, FlexItem::class);
                $effAlign = $this->effectiveAlign($fi, $align);
                $crossSize = $isRow ? $fi->h : $fi->w;
                // CSS Flexbox §8.3: stretch 仅应用于交叉轴尺寸为 auto 的子项
                $hasExplicitCross = false;
                if ($fi->computedStyle !== null) {
                    $crossProp = $isRow ? $fi->computedStyle->height : $fi->computedStyle->width;
                    $hasExplicitCross = ($crossProp !== null && !$crossProp->isPercent() && $crossProp->toPx() > 0);
                }
                if ($effAlign === 'stretch' && !$hasExplicitCross && $crossSize < $lineMaxCross) {
                    if ($isRow) $fi->h = $lineMaxCross;
                    else $fi->w = $lineMaxCross;
                } else if (!$isRow && $effAlign !== 'stretch' && !$hasExplicitCross && $crossSize <= 0) {
                    // 对标 Blink：非 stretch 对齐下交叉轴 auto 尺寸 = fit-content
                    // （浏览器 ground truth：column+align-items:center 的文本子项宽 = 内容宽 180，非 0/全宽）
                    // fit-content = min(max-content, max(min-content, available))（CSS 2.2 §10.3.5）
                    $fcAlgo = new BlockAlgorithm();
                    $fcContent = (string)($fi->content ?? '');
                    $fcChildren = $fi->node?->children ?? [];
                    if (!is_array($fcChildren)) $fcChildren = [];
                    $fcSizes = $fcAlgo->computeMinMaxSizes($space, $fi->computedStyle, $fcContent, $fcChildren);
                    $fitW = $fcSizes->shrinkToFit((int)$containerCross);
                    if ($fitW > 0) $fi->w = $fitW;
                }
            }
            // Recalculate maxCross after stretch, keep containerCross for single-line (CSS §9.5.1)
            $lineMaxCross = 0;
            foreach ($lineItems as $fi) {
                $fi = objval($fi, FlexItem::class);
                $cross = (int)($isRow ? $fi->h : $fi->w);
                if ($cross > $lineMaxCross) $lineMaxCross = $cross;
            }
            if ($totalLines === 1 && (int)$containerCross > 0 && (int)$lineMaxCross < (int)$containerCross) {
                $lineMaxCross = (int)$containerCross;
            }

            // 4f. Main-axis positioning (base on containerMain offset)
            // 子项从 padding-box 内部坐标起点摆放
            $mainBase = $isRow ? $innerX : $innerY;
            if ($isReverse) {
                // Reverse direction: main-start = right/bottom edge
                $cursorMain = $mainBase + $containerMain - (int)$mainStart;
                foreach ($lineItems as $fi) {
                    $fi = objval($fi, FlexItem::class);
                    $mBefore = $fi->marginBefore + ($fi->marginAutoBefore ? $autoMarginSpace : 0);
                    $mAfter = $fi->marginAfter + ($fi->marginAutoAfter ? $autoMarginSpace : 0);
                    if ($isRow) {
                        $cursorMain -= $mAfter;
                        $cursorMain -= $fi->w;
                        $fi->x = (int)$cursorMain;
                        $cursorMain -= $mBefore;
                        $cursorMain -= (int)$spaceBetween + $gap;
                    } else {
                        $cursorMain -= $mAfter;
                        $cursorMain -= $fi->h;
                        $fi->y = (int)$cursorMain;
                        $cursorMain -= $mBefore;
                        $cursorMain -= (int)$spaceBetween + $gap;
                    }
                }
            } else {
                $cursorMain = $mainBase + (int)$mainStart;
                foreach ($lineItems as $fi) {
                    $fi = objval($fi, FlexItem::class);
                    $mBefore = $fi->marginBefore + ($fi->marginAutoBefore ? $autoMarginSpace : 0);
                    $mAfter = $fi->marginAfter + ($fi->marginAutoAfter ? $autoMarginSpace : 0);
                    $cursorMain += $mBefore;
                    if ($isRow) {
                        $fi->x = (int)$cursorMain;
                        $cursorMain += $fi->w + $mAfter + (int)$spaceBetween + $gap;
                    } else {
                        $fi->y = (int)$cursorMain;
                        $cursorMain += $fi->h + $mAfter + (int)$spaceBetween + $gap;
                    }
                }
            }

            $lineMaxCrosses[$lineIdx] = $lineMaxCross;
        }

        // ── Step 5: Cross-axis alignment (align-items/align-self per-item + align-content) ──
        // 5a. Calculate total cross size and align-content offsets
        $totalCross = $this->sumLineMaxCrosses($lineMaxCrosses, $gap);
        $crossAvailable = $containerCross;
        if ($crossAvailable <= 0) $crossAvailable = $totalCross;

        // align-content for multi-line
        $result = $this->computeLineCrossOffsets($lineMaxCrosses, $totalLines, $totalCross, $crossAvailable, $alignContent, $gap);
        $lineCrossOffsets = $result[0];
        $lineMaxCrosses = $result[1];

        // 5b. Per-item cross-axis positioning
        // Cross-axis 基准也使用 padding-box 内部坐标
        $crossBase = $isRow ? $innerY : $innerX;
        if ($isWrapReverse) {
            // wrap-reverse: cross-start = bottom/right edge
            $totalUsedCross = $this->sumLineMaxCrosses($lineMaxCrosses, $gap);
            $crossBase += ($containerCross - $totalUsedCross);
        }
        foreach ($lineGroups as $lineIdx => $lineItems) {
            $lineCrossOffset = $lineCrossOffsets[$lineIdx];
            $lineMaxCross = $lineMaxCrosses[$lineIdx];
            foreach ($lineItems as $itemIdx => $fi) {
                $fi = objval($fi, FlexItem::class);
                $effAlign = $this->effectiveAlign($fi, $align);
                $crossSize = $isRow ? $fi->h : $fi->w;

                // Stretch items to fill lineMaxCross (CSS §8.3: 仅 auto 交叉轴尺寸)
                $hasExplicitCross2 = false;
                if ($fi->computedStyle !== null) {
                    $crossProp2 = $isRow ? $fi->computedStyle->height : $fi->computedStyle->width;
                    $hasExplicitCross2 = ($crossProp2 !== null && !$crossProp2->isPercent() && $crossProp2->toPx() > 0);
                }
                if ($effAlign === 'stretch' && !$hasExplicitCross2) {
                    if ($isRow) $fi->h = $lineMaxCross;
                    else $fi->w = $lineMaxCross;
                    $crossSize = $lineMaxCross;
                }

                // Cross-axis offset within line
                $crossItemOffset = 0;
                if ($effAlign === 'center') {
                    $crossItemOffset = (int)(($lineMaxCross - $crossSize) / 2);
                } elseif ($effAlign === 'flex-end' || $effAlign === 'end') {
                    $crossItemOffset = $lineMaxCross - $crossSize;
                } elseif ($effAlign === 'baseline') {
                    // CSS Flexbox §9.6：align-items:baseline —— 将子项基线对齐到行中最大基线位置。
                    // 简化实现：使用 child fragment baseline（对标 Blink NGFlexLayoutAlgorithm 基线对齐）
                    // 行内最大 baseline = 为整行子项取 max(child.baseline)
                    // 本 child offset = lineMaxBaseline - childBaseline
                    // 此处取保守路径：lineMaxBaseline 未预计算，使用 fontSize * 0.8 作为比较基准
                    $childBaseline = $fi->computedStyle !== null
                        ? (int)(($fi->computedStyle->getFontSize() ?? 16) * 0.8)
                        : 12;
                    // 行中最大 baseline（保守路径：取 lineMaxCross * 0.7 作为参考）
                    $lineMaxBaseline = (int)($lineMaxCross * 0.7);
                    $crossItemOffset = max(0, $lineMaxBaseline - $childBaseline);
                }
                // flex-start: offset = 0; stretch: already sized to lineMaxCross

                if ($isRow) {
                    $fi->y = $crossBase + (int)$lineCrossOffset + $crossItemOffset;
                } else {
                    $fi->x = $crossBase + (int)$lineCrossOffset + $crossItemOffset;
                }
            }
        }

        // ── Pass 2: 用 flex 确定的尺寸重新布局子项（对标 Blink FlexAlgorithm 两阶段） ──
        // Blink: flex 分配后用确定宽度重新 LayoutChild
        foreach ($sortedFlexItems as $p2Idx => $p2Fi) {
            $p2Fi = objval($p2Fi, FlexItem::class);
            $p2Orig = $sortedChildResults[$p2Idx] ?? null;
            $p2ItemW = (int)$p2Fi->w;
            $p2OrigW = $p2Orig !== null ? (int)$p2Orig->getW() : 0;
            // 对标 Blink NGFlexLayoutAlgorithm Pass 2（CSS Flexbox §9.7）：
            // 阅览约束确定后子项新尺寸 ≠ hypothetical时，必须重新布局。
            // 旧阈值 abs(diff) > 5 为启发式规范违反——现严格使用 !== 确定性判断。
            // 对标 Blink is_fixed_block_size：交叉轴 stretch 改变了子项高度（row 方向）时，
            // 子项必须在固定块轴尺寸下重新布局（否则 grid/flex 子项内部永远看不到 stretch 高度）。
            $p2ItemH = (int)$p2Fi->h;
            $p2OrigH = $p2Orig !== null ? (int)$p2Orig->getH() : 0;
            $p2HasExplicitH = false;
            if ($p2Fi->computedStyle !== null) {
                $p2HProp = $p2Fi->computedStyle->height;
                $p2HasExplicitH = ($p2Fi->computedStyle->getRaw('height') !== null && $p2HProp !== null && !$p2HProp->isPercent() && $p2HProp->toPx() > 0);
            }
            $heightStretched = $isRow && !$p2HasExplicitH && $p2ItemH > 0 && $p2OrigH !== $p2ItemH;
            // 对标 Blink：column 方向主轴 grow/shrink 分配后的 used main size 同样是 definite
            // （CSS Flexbox §9.4.3）——子项（如 grid）必须在固定块轴尺寸下重新布局。
            $mainHeightAssigned = !$isRow && !$p2HasExplicitH && $p2ItemH > 0 && $p2OrigH !== $p2ItemH;
            // 性能守卫：仅 flex/grid 子项的内部布局消费 definite 块轴尺寸（stretch/行分配）；
            // 纯文本/block 子项重布局无收益（bench 验证：无守卫时 LiveDashboard -12.6%/TextHeavy -13.2%）。
            $p2Display = $p2Fi->computedStyle?->display?->value ?? 'block';
            $p2ConsumesBlockSize = ($p2Display === 'flex' || $p2Display === 'grid' || $p2Display === 'inline-flex' || $p2Display === 'inline-grid');
            $blockSizeIsFixed = ($heightStretched || $mainHeightAssigned) && $p2ConsumesBlockSize;
            if ((($p2OrigW > 0 && $p2ItemW > 0 && $p2OrigW !== $p2ItemW) || $blockSizeIsFixed) && $p2Idx < count($childNodes)) {
                $p2Space = new ConstraintSpace(
                    $p2ItemW, (int)$p2Fi->h > 0 ? (int)$p2Fi->h : $space->getContentHeight(),
                    $space->getParentContentX(), $space->getParentContentY(),
                    $p2ItemW, (int)$p2Fi->h > 0 ? (int)$p2Fi->h : $space->getContentHeight(),
                    $p2ItemW, $space->getPercentageHeight(),
                    0, 0, 0, 0, 0, 0, 0, 0,
                    true, false, 'block',
                    $p2ItemW, $space->getPercentageHeight(),
                    $blockSizeIsFixed,
                );
                $reFrag = $this->layoutChild($childNodes[$p2Idx], $p2Space);
                $sortedChildResults[$p2Idx] = $reFrag;
            }
        }

        // ── Step 6: 将 FlexItem 结果映射回 PhysicalFragment ──
        $mappedResults = [];
        foreach ($sortedFlexItems as $orderIdx => $fi) {
            $orig = $sortedChildResults[$orderIdx] ?? null;
            $itemW = (int)$fi->w;
            $itemH = (int)$fi->h;
            // CSS-UI-3 §4.5: border-box 下 flex-grow 宽度为总宽度，内容宽度需减 padding+border
            $contentW = $itemW;
            $chs = $orig?->style;
            if ($chs?->boxSizing?->value === 'border-box') {
                $padL = $chs->padding?->left->toPx() ?? 0;
                $padR = $chs->padding?->right->toPx() ?? 0;
                $bw = (int)($chs->getBorderLeftWidth() ?? 0) + (int)($chs->getBorderRightWidth() ?? 0);
                $contentW = max(1, $itemW - $padL - $padR - $bw);
            }
            $origW = $orig !== null ? (int)$orig->getW() : 0;
            $useOrig = ($origW > 0 && abs($origW - $itemW) <= 5);
            $children = $useOrig ? ($orig->children ?? []) : ($orig?->children ?? []);
            // 翻译子 Fragment 坐标：flex 重定位后，子项绝对坐标需同步偏移
            $dx = $orig !== null ? ((int)$fi->x - (int)$orig->getX()) : 0;
            $dy = $orig !== null ? ((int)$fi->y - (int)$orig->getY()) : 0;
            if (($dx !== 0 || $dy !== 0) && count($children) > 0) {
                $translated = [];
                foreach ($children as $ch) {
                    $translated[] = self::translateFragmentTree($ch, $dx, $dy);
                }
                $children = $translated;
            }
            $mappedResults[] = (new PhysicalFragmentBuilder())
                ->x((int)$fi->x)->y((int)$fi->y)
                ->w($itemW)->h($itemH)
                ->vw((int)$fi->visualW)->vh((int)$fi->visualH)
                ->cw($contentW)->ch((int)($orig?->contentHeight ?? 0))
                ->style($orig?->style)->children($children)
                ->type($orig?->type ?? '')->content($orig?->content)
                // 对标 Blink NGFlexLayoutAlgorithm：mapping fragment 需保留原子项的滚动状态
                ->isScrollContainer((bool)($orig?->isScrollContainer ?? false))
                ->scrollTop((int)($orig?->scrollTop ?? 0))
                ->scrollLeft((int)($orig?->scrollLeft ?? 0))
                ->build();
        }
        // Remap results to original DOM order (CSS §9.2: visual order ≠ DOM order)
        // 使用 $flexItemOrigIdx 映射回 childResults 原始 DOM 索引（避免 display:none skip 后错位）
        $resultsByOriginalIndex = [];
        foreach ($indices as $orderIdx => $flexIdx) {
            if (isset($mappedResults[$orderIdx])) {
                $origDomIdx = $flexItemOrigIdx[$flexIdx] ?? $flexIdx;
                $resultsByOriginalIndex[$origDomIdx] = $mappedResults[$orderIdx];
            }
        }
        ksort($resultsByOriginalIndex);
        $mappedResults = array_values($resultsByOriginalIndex);
        // Re-resolve flex:1 nested containers (flex-grow changes child sizes)
        if ($h <= 0 && count($mappedResults) > 0) {
            $maxBottom = $innerY;
            foreach ($mappedResults as $cr) {
                $chH = (int)($cr->getH() ?? 0);
                if ($chH <= 0) $chH = (int)($cr->getVisualH() ?? 0);
                $b = (int)($cr->getY() ?? 0) + $chH;
                if ($b > $maxBottom) $maxBottom = $b;
            }
            // 子项 max bottom → padding-box 高度；加回 padding-bottom 得 border-box 高度
            $h = max(0, $maxBottom - $innerY) + $flexPadT + $flexPadB;
        }

        // Convert mapped LayoutResults to PhysicalFragment children
        $fragmentChildren = [];
        foreach ($mappedResults as $mr) {
            $fragmentChildren[] = $mr;
        }
        // CSS Flexbox §4.1: absolute/fixed flex items 直通到输出，不受 flex 分配影响。
        // OOFLayoutAlgorithm 会在后续通行证中处理它们的实际坐标。
        foreach ($absoluteFlexItemsPassthrough as $abs) {
            $fragmentChildren[] = $abs;
        }

        return new PhysicalFragment(
            (int)$x, (int)$y, (int)$w, (int)$h,
            (int)$s->visualWidth($w), (int)$s->visualHeight($h),
            0, 0, 0, $s,
            $fragmentChildren
        );
    }

    /** Resolve effective align value: align-self overrides align-items */
    private function effectiveAlign(FlexItem $item, string $containerAlign): string
    {
        if ($item->alignSelf !== 'auto') {
            return $item->alignSelf;
        }
        return $containerAlign;
    }

    /** Sum line max crosses, returning max(0, total - gap) */
    private function sumLineMaxCrosses(array $lineMaxCrosses, int $gap): int
    {
        $total = 0;
        foreach ($lineMaxCrosses as $lmc) {
            $total += (int)$lmc + $gap;
        }
        return max(0, $total - $gap);
    }

    /** Compute line cross offsets based on align-content */
    private function computeLineCrossOffsets(array $lineMaxCrosses, int $totalLines, int $totalCross, int $crossAvailable, string $alignContent, int $gap): array
    {
        $offsets = [];
        if ($totalLines > 1 && $crossAvailable > $totalCross) {
            $extraCross = $crossAvailable - $totalCross;
            switch ($alignContent) {
                case 'flex-start': case 'start':
                    $offset = 0;
                    foreach ($lineMaxCrosses as $i => $lmc) { $offsets[$i] = $offset; $offset += (int)$lmc + $gap; }
                    break;
                case 'center':
                    $offset = $extraCross / 2;
                    foreach ($lineMaxCrosses as $i => $lmc) { $offsets[$i] = $offset; $offset += (int)$lmc + $gap; }
                    break;
                case 'flex-end': case 'end':
                    $offset = $extraCross;
                    foreach ($lineMaxCrosses as $i => $lmc) { $offsets[$i] = $offset; $offset += (int)$lmc + $gap; }
                    break;
                case 'space-between':
                    $space = $extraCross / ($totalLines - 1);
                    $offset = 0;
                    foreach ($lineMaxCrosses as $i => $lmc) { $offsets[$i] = $offset; $offset += (int)$lmc + (int)$space; }
                    break;
                case 'space-around':
                    $space = $extraCross / $totalLines;
                    $offset = $space / 2;
                    foreach ($lineMaxCrosses as $i => $lmc) { $offsets[$i] = $offset; $offset += (int)$lmc + (int)$space; }
                    break;
                case 'space-evenly':
                    $space = $extraCross / ($totalLines + 1);
                    $offset = $space;
                    foreach ($lineMaxCrosses as $i => $lmc) { $offsets[$i] = $offset; $offset += (int)$lmc + (int)$space; }
                    break;
                default: // stretch
                    $stretchedCross = (int)($crossAvailable / $totalLines);
                    $offset = 0;
                    foreach ($lineMaxCrosses as $i => $lmc) {
                        $offsets[$i] = $offset;
                        $lineMaxCrosses[$i] = max((int)$lmc, $stretchedCross - $gap);
                        $offset += (int)$lineMaxCrosses[$i] + $gap;
                    }
                    break;
            }
        } else {
            $offset = 0;
            foreach ($lineMaxCrosses as $i => $lmc) { $offsets[$i] = $offset; $offset += (int)$lmc + $gap; }
        }
        return [$offsets, $lineMaxCrosses];
    }

    /**
     * 递归翻译 Fragment 子树：flex 重定位后，所有后代绝对坐标同步偏移 (dx,dy)
     * 对标 LayoutOrchestrator::translateFragment
     */
    public static function translateFragmentTree(\Px\Layout\PhysicalFragment $frag, int $dx, int $dy): \Px\Layout\PhysicalFragment
    {
        $translatedChildren = [];
        foreach ($frag->children as $child) {
            $translatedChildren[] = self::translateFragmentTree($child, $dx, $dy);
        }
        // 使用 Builder 代替位置参数构造，已删除 availableWidth（审计 §13.F）
        return (new \Px\Layout\PhysicalFragmentBuilder())
            ->from($frag)
            ->x($frag->x + $dx)
            ->y($frag->y + $dy)
            ->children($translatedChildren)
            ->build();
    }
}
