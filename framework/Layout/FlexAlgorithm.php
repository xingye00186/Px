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
        if ($w <= 0) $w = $parentW;
        // For flex items whose actual width is set by parent flex (inputFragment),
        // use the actual width instead of the constraint space parentW
        if ($inputFragment !== null && $inputFragment->getW() > 0 && $w <= 0) {
            $w = $inputFragment->getW();
        }
        $h = $s->height->toPx();

        // Apply flex container padding: subtract from content area, add to offset
        $flexPadL = $s->padding?->left->toPx() ?? 0;
        $flexPadR = $s->padding?->right->toPx() ?? 0;
        $flexPadT = $s->padding?->top->toPx() ?? 0;
        $flexPadB = $s->padding?->bottom->toPx() ?? 0;
        $x += $flexPadL;
        $y += $flexPadT;
        $w = max(0, $w - $flexPadL - $flexPadR);
        $h = max(0, $h - $flexPadT - $flexPadB);
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
        // Main-axis and cross-axis dimensions
        $containerMain = (int)($isRow ? $w : $h);
        $containerCross = (int)($isRow ? $h : $w);

        // ── Step 1: Collect flex items ──
        $flexItems = [];
        $flexItemData = [];
        foreach ($childResults as $cr) {
            $cs = $cr->style;
            if ($cs === null) continue;
            // CSS §9.2: 跳过 display:none 的子项（不影响 flex 布局）
            $childDisplay = $cs->display?->value ?? 'block';
            if ($childDisplay === 'none') continue;
            // Use resolved flex shorthand as fallback when individual props not set
            // (getRaw may return CssLength object which cannot be cast to float)
            $rawGrow = $cs->getRaw('flexGrow');
            $grow = $rawGrow !== null ? (float)$rawGrow : (float)$cs->flex->grow;
            $rawShrink = $cs->getRaw('flexShrink');
            $shrink = $rawShrink !== null ? (float)$rawShrink : (float)$cs->flex->shrink;
            $order = (int)($cs->getRaw("order") ?? 0);
            // flex-basis from resolved CssLength (not raw string from getRaw)
            $basisVal = $cs->flexBasis;
            $basis = -1;
            if ($basisVal instanceof CssLength && !$basisVal->isAuto() && $basisVal->toPx() > 0) {
                $basis = $basisVal->toPx();
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
            $item->content = $cr->style?->getRaw('_content');
            $item->originalChildren = $cr->children;
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
                if ($isRow) $item->w = 0; else $item->h = 0;
            }
            // Use visualW/H as fallback when content size is 0 (nested flex with explicit main size)
            if ($item->w <= 0 && $isRow && $hasMainSize) $item->w = (int)$cr->getVisualW();
            if ($item->h <= 0 && !$isRow && $hasMainSize) $item->h = (int)$cr->getVisualH();
            $item->visualW = (int)$cr->getVisualW(); $item->visualH = (int)$cr->getVisualH();
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
        $sortedFlexItemData = []; $sortedChildResults = [];
        foreach ($indices as $idx2) { $sortedFlexItemData[] = $flexItemData[$idx2]; $sortedChildResults[] = $childResults[$idx2]; }

        // ── Step 2: Apply flex-basis ──
        foreach ($sortedFlexItems as $fi) {
            $fi = objval($fi, FlexItem::class);
            if ($fi->basis > 0) { if ($isRow) $fi->w = $fi->basis; else $fi->h = $fi->basis; }
        }

        // ── Step 3: Break into lines (FlexLineBreaker) ──
        $breaker = new FlexLineBreaker();
        $lines = $breaker->breakLines($sortedFlexItems, $sortedFlexItemData, $isWrapping, $isRow, $containerMain, $gap);
        $lineGroups = $lines[0];
        $lineData = $lines[1] ?? [];
        $totalLines = count($lineGroups);

        // ── Step 4: Per-line grow/shrink + main-axis positioning ──
        $lineMaxCrosses = [];
        foreach ($lineGroups as $lineIdx => $lineItems) {
            $lineTotal = 0;
            foreach ($lineItems as $fi) { $fi = objval($fi, FlexItem::class); $lineTotal += $isRow ? $fi->w : $fi->h; }

            // 4a. Flex-grow (CSS spec: distribute remaining space; works when lineTotal==0 too)
            if ($lineTotal < $containerMain) {
                $remaining = $containerMain - $lineTotal;
                $growTotal = 0;
                foreach ($lineItems as $fi) { $fi = objval($fi, FlexItem::class); $growTotal += $fi->grow; }
                if ($growTotal > 0) {
                    // If lineTotal == 0 and all items are flex-grow, distribute full container size
                    // CSS §9.5.1: gap 应从主轴可用空间扣除
                    if ($lineTotal === 0) {
                        $itemsInLine = count($lineItems);
                        $totalGap = ($itemsInLine - 1) * $gap;
                        $available = max(0, $containerMain - $totalGap);
                        foreach ($lineItems as $fi) {
                            $fi = objval($fi, FlexItem::class);
                            if ($fi->grow > 0) {
                                $share = (int)($available * $fi->grow / $growTotal);
                                if ($isRow) { $fi->w = $share; $fi->visualW = $share; } else $fi->h = $share;
                            }
                        }
                    } else {
                        foreach ($lineItems as $fi) {
                            $fi = objval($fi, FlexItem::class);
                            if ($fi->grow > 0) {
                                $extra = (int)($remaining * $fi->grow / $growTotal);
                                if ($isRow) { $fi->w += $extra; $fi->visualW += $extra; } else $fi->h += $extra;
                            }
                        }
                    }
                }
            }

            // 4b. Flex-shrink (skip when auto-height container: containerMain <= 0 means no constraint)
            if ($lineTotal > $containerMain && $containerMain > 0) {
                $overflow = $lineTotal - $containerMain;
                $shrinkTotal = 0;
                foreach ($lineItems as $fi) { $fi = objval($fi, FlexItem::class); $shrinkTotal += $fi->shrink; }
                if ($shrinkTotal > 0) {
                    foreach ($lineItems as $fi) {
                        $fi = objval($fi, FlexItem::class);
                        if ($fi->shrink > 0) {
                            $reduction = (int)($overflow * $fi->shrink / $shrinkTotal);
                            if ($isRow) { $fi->w = max(0, $fi->w - $reduction); $fi->visualW = $fi->w; }
                            else $fi->h = max(0, $fi->h - $reduction);
                        }
                    }
                }
            }

            // 4b+. Clamp to min/max constraints (CSS Flexbox §4.5: min/max main/cross size)
            foreach ($lineItems as $fi) {
                $fi = objval($fi, FlexItem::class);
                $fcs = $fi->computedStyle;
                if ($fcs === null) continue;
                $maxW = $fcs->maxWidth?->toPx() ?? 0;
                $minW = $fcs->minWidth?->toPx() ?? 0;
                $maxH = $fcs->maxHeight?->toPx() ?? 0;
                $minH = $fcs->minHeight?->toPx() ?? 0;
                if ($maxW > 0 && $fi->w > $maxW) $fi->w = $maxW;
                if ($minW > 0 && $fi->w < $minW) $fi->w = $minW;
                if ($maxH > 0 && $fi->h > $maxH) $fi->h = $maxH;
                if ($minH > 0 && $fi->h < $minH) $fi->h = $minH;
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

            // 4e. Apply stretch to fill line maxCross; allow from zero (CSS stretch spec)
            foreach ($lineItems as $fi) {
                $fi = objval($fi, FlexItem::class);
                $effAlign = $this->effectiveAlign($fi, $align);
                $crossSize = $isRow ? $fi->h : $fi->w;
                if ($effAlign === 'stretch' && $crossSize < $lineMaxCross) {
                    if ($isRow) $fi->h = $lineMaxCross;
                    else $fi->w = $lineMaxCross;
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
            $mainBase = $isRow ? $x : $y;
            if ($isReverse) {
                // Reverse direction: main-start = right/bottom edge
                $cursorMain = $mainBase + $containerMain - (int)$mainStart;
                foreach ($lineItems as $fi) {
                    $fi = objval($fi, FlexItem::class);
                    if ($isRow) {
                        $cursorMain -= $fi->w;
                        $fi->x = (int)$cursorMain;
                        $cursorMain -= (int)$spaceBetween + $gap;
                    } else {
                        $cursorMain -= $fi->h;
                        $fi->y = (int)$cursorMain;
                        $cursorMain -= (int)$spaceBetween + $gap;
                    }
                }
            } else {
                $cursorMain = $mainBase + (int)$mainStart;
                foreach ($lineItems as $fi) {
                    $fi = objval($fi, FlexItem::class);
                    if ($isRow) {
                        $fi->x = (int)$cursorMain;
                        $cursorMain += $fi->w + (int)$spaceBetween + $gap;
                    } else {
                        $fi->y = (int)$cursorMain;
                        $cursorMain += $fi->h + (int)$spaceBetween + $gap;
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
        $crossBase = $isRow ? $y : $x;
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

                // Stretch items to fill lineMaxCross
                if ($effAlign === 'stretch') {
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
                } // flex-start/baseline: offset = 0; stretch: already sized to lineMaxCross

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
            // 宽度变化超过 5px 时重新布局（对标旧 Phase C 阈值）
            if ($p2OrigW > 0 && $p2ItemW > 0 && abs($p2OrigW - $p2ItemW) > 5 && $p2Idx < count($childNodes)) {
                $p2Space = new ConstraintSpace(
                    $p2ItemW, (int)$p2Fi->h > 0 ? (int)$p2Fi->h : $space->getContentHeight(),
                    $space->getParentContentX(), $space->getParentContentY(),
                    $p2ItemW, (int)$p2Fi->h > 0 ? (int)$p2Fi->h : $space->getContentHeight(),
                    $p2ItemW, $space->getPercentageHeight(),
                    0, 0, 0, 0, 0, 0, 0, 0,
                    true, false, 0, 0, 'block',
                    $p2ItemW, $space->getPercentageHeight(),
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
                ->build();
        }
        // Remap results to original DOM order (CSS §9.2: visual order ≠ DOM order)
        $resultsByOriginalIndex = [];
        foreach ($indices as $orderIdx => $domIdx) {
            if (isset($mappedResults[$orderIdx])) {
                $resultsByOriginalIndex[$domIdx] = $mappedResults[$orderIdx];
            }
        }
        ksort($resultsByOriginalIndex);
        $mappedResults = array_values($resultsByOriginalIndex);
        // Re-resolve flex:1 nested containers (flex-grow changes child sizes)
        if ($h <= 0 && count($mappedResults) > 0) {
            $maxBottom = $y;
            foreach ($mappedResults as $cr) {
                $chH = (int)($cr->getH() ?? 0);
                if ($chH <= 0) $chH = (int)($cr->getVisualH() ?? 0);
                $b = (int)($cr->getY() ?? 0) + $chH;
                if ($b > $maxBottom) $maxBottom = $b;
            }
            $h = max(0, $maxBottom - $y);
        }

        // Convert mapped LayoutResults to PhysicalFragment children
        $fragmentChildren = [];
        foreach ($mappedResults as $mr) {
            $fragmentChildren[] = $mr;
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
    private static function translateFragmentTree(\Px\Layout\PhysicalFragment $frag, int $dx, int $dy): \Px\Layout\PhysicalFragment
    {
        $translatedChildren = [];
        foreach ($frag->children as $child) {
            $translatedChildren[] = self::translateFragmentTree($child, $dx, $dy);
        }
        return new \Px\Layout\PhysicalFragment(
            $frag->x + $dx, $frag->y + $dy,
            $frag->w, $frag->h,
            $frag->visualW, $frag->visualH, $frag->layer,
            $frag->contentWidth, $frag->contentHeight,
            $frag->style, $translatedChildren, $frag->sourceNode,
            $frag->scrollTop, $frag->scrollLeft, $frag->isScrollContainer,
            $frag->type, $frag->content, $frag->dataset, $frag->pseudoStyles,
            $frag->availableWidth
        );
    }
}
