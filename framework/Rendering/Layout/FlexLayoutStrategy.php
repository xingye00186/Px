<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Core\Config;
use Px\Rendering\CssMappings;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\Layout\Tools\PercentResolver;
use Px\Rendering\Layout\Tools\ScrollHelper;

/**
 * FlexLayoutStrategy — Flex 布局策略
 *
 * CSS Flexible Box Layout Module Level 1:
 * 实现完整的 flex 布局算法，包括:
 * - flex-direction (row/column), flex-wrap
 * - flex-grow/flex-shrink/flex-basis
 * - justify-content (flex-start/center/flex-end/space-between/space-around/space-evenly)
 * - align-items (stretch/center/flex-start/flex-end)
 * - align-self (单子项覆盖)
 * - order 排序
 * - 两阶段子项重解析（flex-grow/cross-axis stretch 后的内部 re-layout）
 */
class FlexLayoutStrategy implements LayoutStrategyInterface
{
    public function resolve(
        RenderNode    $node,
        LayoutContext $ctx,
        array         $style
    ): void
    {
        $this->resolveFlexLayout($node, $ctx, $style);
    }
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Flex layout: compute child positions using flex algorithm.
     */
    public function resolveFlexLayout(
        RenderNode    $node,
        LayoutContext $ctx,
        array         $style
    ): void
    {
        // Container position

        $left = (int)($style['left'] ?? 0);

        $top = (int)($style['top'] ?? 0);

        $width = (int)($style['width'] ?? 0);

        $height = (int)($style['height'] ?? 0);

        $node->x = $left + $ctx->parentX;

        $node->y = $top + $ctx->parentY;

        // Apply translate from animatedStyle

        $translateX = (int)($style['translateX'] ?? 0);

        $translateY = (int)($style['translateY'] ?? 0);

        $node->x += $translateX;

        $node->y += $translateY;

        // CSS: flex item percentage width resolves against content width
        // When parent is null (top-level element under #root), use window viewport as containing block
        $parentW = (int)(($ctx->parent !== null)
            ? PercentResolver::resolveContentWidth($ctx->parent->style, $ctx->parent->w)
            : (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0));

        $parentH = ($ctx->parent !== null) ? $ctx->parent->h : (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0);

        $width = PercentResolver::resolvePercent($style, 'width', 'widthPercent', $parentW);

        $height = PercentResolver::resolvePercent($style, 'height', 'heightPercent', $parentH);

        $node->w = (int)max(0, (int)$width);

        $node->h = (int)max(0, (int)$height);

        $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
        $node->visualH = PercentResolver::resolveVisualH($style, $node->h);

        // ── Scroll container post-processing for flex/grid display modes ──

        $parentDisplay = ($ctx->parent !== null) ? ($ctx->parent->style['display'] ?? '') : '';

        $isFlexOrGridItem = ($parentDisplay === 'flex' || $parentDisplay === 'grid');

        if (!$isFlexOrGridItem) {
            $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);

            if (!$hasExplicitW && $width === 0 && $ctx->parent !== null) {
                $width = (int)PercentResolver::resolveContentWidth($ctx->parent->style, $ctx->parent->w);

                $node->w = (int)max(0, (int)$width);
            }
        } elseif ($ctx->parent !== null && $parentDisplay === 'flex') {
            // ── Scroll container post-processing for flex/grid display modes ──

            // cross-axis (width) size for correct first-pass internal layout.

            // Without this, flex items with display:flex/grid get width=0, causing
            // their internal grid to compute 1 column with inflated height, which
            // then triggers flex-shrink and damages sibling items' explicit sizes.

            $parentDirection = $ctx->parent->style['flexDirection'] ?? 'row';

            $parentIsColumn = ($parentDirection === 'column' || $parentDirection === 'column-reverse');

            $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);

            if ($parentIsColumn && !$hasExplicitW && $width === 0) {
                $parentContentW = PercentResolver::resolveContentWidth($ctx->parent->style, $ctx->parent->w);

                $width = $parentContentW;

                $node->w = (int)max(0, (int)$width);

                $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
            }
        }

        // ── 脏路径：完整布局计算 ──
        $direction = $style['flexDirection'] ?? 'row';

        $isRow = ($direction === 'row' || $direction === 'row-reverse');

        // Note: Cross-axis fill is handled by the parent's align-items: stretch
        // in Steps 10-11 below. Do NOT fill cross-axis from parent here, as this
        // incorrectly sets the container's dimension when it's a flex item whose
        // cross-axis direction differs from the parent's.

        // Example: a row flex-container child of a column flex-container should
        // NOT have its height filled from parent height — only width should stretch.

        $gap = (int)($style['gap'] ?? 0);

        $justify = $style['justifyContent'] ?? 'flex-start';

        $align = $style['alignItems'] ?? 'stretch';

        $wrap = $style['flexWrap'] ?? 'nowrap';

        $reversed = ($direction === 'row-reverse' || $direction === 'column-reverse');

        // ── Padding ──

        $paddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);

        $paddingRight = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);

        $paddingBottom = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);

        $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);

        $containerMain = max(0, $isRow ? PercentResolver::resolveContentWidth($style, $width) : PercentResolver::resolveContentHeight($style, $height));

        $containerCross = max(0, $isRow ? PercentResolver::resolveContentHeight($style, $height) : PercentResolver::resolveContentWidth($style, $width));

        // ── Step 1: Collect children and resolve ──

        // Apply scroll offset to child parent coordinates for scroll containers

        $scrollShiftX = 0;

        $scrollShiftY = 0;

        if ($node->isScrollContainer) {
            $scrollShiftX = $node->scrollLeft;

            $scrollShiftY = $node->scrollTop;
        }

        $children = [];
        $absoluteChildren = [];

        foreach ($node->children as $child) {
            $childPosition = $child->style['position'] ?? 'static';
            $childDisplay = $child->style['display'] ?? 'block';

            // Defer absolute/fixed children - container dimensions not yet known
            // Skip display:none children (CSS 2.2 §9.2.4: generate no box)
            if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') {
                if ($childDisplay === 'none') {
                    $child->w = 0;
                    $child->h = 0;
                    $child->visualW = 0;
                    $child->visualH = 0;
                }
                if ($childPosition === 'absolute' || $childPosition === 'fixed') {
                    $absoluteChildren[] = $child;
                }
                continue;
            }

            $childCtx = new LayoutContext($node->x + $paddingLeft - $scrollShiftX, $node->y + $paddingTop - $scrollShiftY, $node);
            $this->resolver->resolveNode($child, $childCtx);

            $children[] = $child;
        }

        if (count($children) === 0) {
            // Still need to finalize scroll container contentHeight if applicable
            if ($node->isScrollContainer) {
                $node->contentHeight = 0;
            }
            return;
        }

        // ── Step 2: Order sort (AOT 兼容的冒泡排序，稳定排序) ──
        $n = count($children);

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n - $i - 1; $j++) {
                $orderA = (int)($children[$j]->style['order'] ?? 0);

                $orderB = (int)($children[$j + 1]->style['order'] ?? 0);

                if ($orderA > $orderB) {
                    $tmp = $children[$j];

                    $children[$j] = $children[$j + 1];

                    $children[$j + 1] = $tmp;
                }
            }
        }

        // ── Step 3: 收集 flex item 元数据 (grow/shrink/basis) ──
        $flexItemData = [];

        foreach ($children as $ch) {
            $data = ['grow' => 0.0, 'shrink' => 1.0, 'basis' => -1, 'isFlexGrow' => false];

            $flexRaw = $ch->style['flex'] ?? '';

            if ($flexRaw !== '') {
                $fv = CssMappings::parseFlexValue($flexRaw);

                $data['grow'] = $fv['grow'];

                $data['shrink'] = $fv['shrink'];

                $data['basis'] = $fv['basis'];
            } else {
                $data['grow'] = (float)($ch->style['flexGrow'] ?? 0);

                $data['shrink'] = (float)($ch->style['flexShrink'] ?? 1);

                // 捕获独立的 flex-basis 属性（仅数值，'auto' 由默认 -1 处理）
                if (isset($ch->style['flexBasis']) && $ch->style['flexBasis'] !== 'auto') {
                    $data['basis'] = (int)$ch->style['flexBasis'];
                }
            }

            if ($data['grow'] > 0) {
                $data['isFlexGrow'] = true;
            }

            $flexItemData[] = $data;
        }

        // ── Step 3.5: Flex-wrap 按行分割 ──
        $isWrapping = ($wrap === 'wrap');

        $lines = [$children];

        if ($isWrapping) {
            $lines = [];

            $currentLine = [];

            $currentLineMain = 0;

            // Build flex item data lookup for wrap calculation
            $wrapFlexData = [];
            foreach ($children as $idx => $ch) {
                $wrapGrow = 0.0;
                $flexRaw = $ch->style['flex'] ?? '';
                if ($flexRaw !== '') {
                    $fv = CssMappings::parseFlexValue($flexRaw);
                    $wrapGrow = (float)($fv['grow']);
                } else {
                    $wrapGrow = (float)($ch->style['flexGrow'] ?? 0);
                }
                $wrapFlexData[$idx] = $wrapGrow;
            }

            foreach ($children as $idx => $ch) {
                // Flex-grow items: use min-width/min-height as base size for wrap
                // (they'll be sized by flex-grow after wrapping, but min-size determines
                //  whether they fit on the current line. CSS Flexbox §9.5)
                $wrapGrow = (float)($wrapFlexData[$idx] ?? 0);
                if ($wrapGrow > 0) {
                    $minMain = $isRow ? (int)($ch->style['minWidth'] ?? 0) : (int)($ch->style['minHeight'] ?? 0);
                    // Also consider intrinsic content width (text) as minimum
                    $chText = $ch->content ?? '';
                    if (is_string($chText) && strlen($chText) > 0 && $minMain <= 0) {
                        $fs = (int)($ch->style['fontSize'] ?? 14);
                        $bd = ($ch->style['bold'] ?? 0) !== 0;
                        $textW = PercentResolver::resolveTextWidth($chText, $fs, $bd);
                        if ($isRow) {
                            $minMain = max(0, $textW);
                        }
                    }
                    $chMain = max(0, $minMain);
                } else {
                    $chMain = $isRow ? (int)($ch->visualW) : (int)($ch->visualH);
                }

                // Include margins in size calculation

                $cs = $ch->style;

                $mL = (int)($cs['marginLeft'] ?? $cs['margin'] ?? 0);

                $mR = (int)($cs['marginRight'] ?? $cs['margin'] ?? 0);

                $mT = (int)($cs['marginTop'] ?? $cs['margin'] ?? 0);

                $mB = (int)($cs['marginBottom'] ?? $cs['margin'] ?? 0);

                $chSizeWithMargin = $chMain + ($isRow ? $mL + $mR : $mT + $mB);

                // If item alone exceeds container, it goes on its own line

                $needsNewLine = !empty($currentLine) && ($currentLineMain + $chSizeWithMargin + $gap > $containerMain);

                if ($needsNewLine) {
                    $lines[] = $currentLine;

                    $currentLine = [];

                    $currentLineMain = 0;
                }

                $currentLine[] = $ch;

                $currentLineMain += $chSizeWithMargin + (count($currentLine) > 1 ? $gap : 0);
            }

            if (!empty($currentLine)) {
                $lines[] = $currentLine;
            }
        }

        // ── Per-line flex layout ──

        $accumulatedCrossOffset = 0;

        $lineCrossData = []; // for align-content distribution

        foreach ($lines as $lineChildren) {
            $lineContainerMain = $containerMain;

            $lineCount = count($lineChildren);

            if ($lineCount === 0) continue;

            // ── Step 3 (per-line): Rebuild flex data for this line ──

            $lineFlexData = [];

            $lineHasFlexGrow = false;

            foreach ($lineChildren as $ch) {
                $data = ['grow' => 0.0, 'shrink' => 1.0, 'basis' => -1, 'isFlexGrow' => false, 'hasExplicitCrossSize' => false, 'crossAxisSized' => false];

                $flexRaw = $ch->style['flex'] ?? '';

                if ($flexRaw !== '') {
                    $fv = CssMappings::parseFlexValue($flexRaw);

                    $data['grow'] = $fv['grow'];

                    $data['shrink'] = $fv['shrink'];

                    $data['basis'] = $fv['basis'];
                } else {
                    $data['grow'] = (float)($ch->style['flexGrow'] ?? 0);

                    $data['shrink'] = (float)($ch->style['flexShrink'] ?? 1);

                    // 捕获独立的 flex-basis 属性（仅数值，'auto' 由默认 -1 处理）
                    if (isset($ch->style['flexBasis']) && $ch->style['flexBasis'] !== 'auto') {
                        $data['basis'] = (int)$ch->style['flexBasis'];
                    }
                }

                if ($data['grow'] > 0) {
                    $data['isFlexGrow'] = true;

                    $lineHasFlexGrow = true;
                }

                $data['hasExplicitCrossSize'] = $isRow
                    ? array_key_exists('height', $ch->style)
                    : array_key_exists('width', $ch->style);

                $lineFlexData[] = $data;
            }

            // ── Step 4: Apply flex-basis ──

            $this->applyFlexBasis($lineChildren, $lineFlexData, $isRow, $style);

            // ── Step 5: Flex-grow ──

            if ($lineHasFlexGrow) {
                $fixedTotalMain = 0;

                foreach ($lineChildren as $idx => $ch) {
                    $data = $lineFlexData[$idx];

                    $cs = $ch->style;

                    $mL = (int)($cs['marginLeft'] ?? $cs['margin'] ?? 0);

                    $mR = (int)($cs['marginRight'] ?? $cs['margin'] ?? 0);

                    $mT = (int)($cs['marginTop'] ?? $cs['margin'] ?? 0);

                    $mB = (int)($cs['marginBottom'] ?? $cs['margin'] ?? 0);

                    if ($data['isFlexGrow']) {
                        $fixedTotalMain += $isRow ? $mL + $mR : $mT + $mB;
                    } else {
                        $sz = $isRow ? $ch->w : $ch->h;

                        $fixedTotalMain += $sz + ($isRow ? $mL + $mR : $mT + $mB);
                    }
                }

                $gapTotal = $gap * ($lineCount - 1);

                $remainingSpace = max($lineContainerMain - $fixedTotalMain - $gapTotal, 0);

                $totalFlexGrow = 0;

                foreach ($lineFlexData as $entry) {
                    $totalFlexGrow += $entry['grow'];
                }

                $totalFlexGrow = (int)max($totalFlexGrow, 1);

                foreach ($lineChildren as $idx => $ch) {
                    $data = $lineFlexData[$idx];

                    if ($data['isFlexGrow']) {
                        $allocated = (int)(($data['grow'] / $totalFlexGrow) * $remainingSpace);

                        if ($isRow) {
                            $ch->w = (int)max(0, $allocated);
                            $ch->visualW = PercentResolver::resolveVisualW($ch->style, $ch->w);
                        } else {
                            $ch->h = (int)max(0, $allocated);
                            $ch->visualH = PercentResolver::resolveVisualH($ch->style, $ch->h);
                        }
                    }
                }
            }

            // ── Step 6: Calculate line totalMain ──

            $lineTotalMain = 0;

            $lineMaxCross = 0;

            foreach ($lineChildren as $ch) {
                $cs = $ch->style;

                $mL = (int)($cs['marginLeft'] ?? $cs['margin'] ?? 0);

                $mR = (int)($cs['marginRight'] ?? $cs['margin'] ?? 0);

                $mT = (int)($cs['marginTop'] ?? $cs['margin'] ?? 0);

                $mB = (int)($cs['marginBottom'] ?? $cs['margin'] ?? 0);

                if ($isRow) {
                    $lineTotalMain += $ch->w + $mL + $mR;

                    $lineMaxCross = (int)max($lineMaxCross, $ch->visualH);
                } else {
                    $lineTotalMain += $ch->h + $mT + $mB;

                    $lineMaxCross = (int)max($lineMaxCross, $ch->visualW);
                }
            }

            $lineTotalMain += $gap * ($lineCount - 1);

            // ── Step 7: Flex-shrink with min-width redistribution ──

            // CSS spec §9.7: shrink items proportionally, clamp at min-width,
            // redistribute remaining overflow to other non-clamped items.

            // Skip when containerMain == 0 (auto main-axis size) or no overflow.

            if ($lineContainerMain > 0 && $lineTotalMain > $lineContainerMain) {
                $remainingOverflow = $lineTotalMain - $lineContainerMain;

                // ── Build active item list with shrink weights ──

                // shrinkSizes[idx] tracks final size for ALL shrink items
                $shrinkSizes = [];

                $activeItems = [];

                foreach ($lineChildren as $idx => $ch) {
                    $data = $lineFlexData[$idx];

                    if ($data['shrink'] > 0) {
                        $mainSize = $isRow ? $ch->w : $ch->h;

                        $shrinkSizes[$idx] = $mainSize;

                        // CSS §9.7: shrink weight = flex-basis × flex-shrink
                        $shrinkBasis = $mainSize;
                        if ($data['basis'] >= 0) {
                            $shrinkBasis = (int)$data['basis'];
                        }

                        $activeItems[] = [
                            'idx' => $idx,
                            'shrinkWeight' => $shrinkBasis * $data['shrink'],
                            'minVal' => $isRow ? (int)($ch->style['minWidth'] ?? 0) : (int)($ch->style['minHeight'] ?? 0),
                        ];
                    }
                }

                // ── Proportional shrink with min-width redistribution ──
                // CSS §9.7: if an item hits min-width, it stops shrinking
                // and remaining overflow is redistributed to other active items.

                // If all shrink weights are 0 (e.g. all flex-basis:0),
                // fall back to equal distribution per old behavior.
                $hasWeight = false;
                foreach ($activeItems as $item) {
                    if ($item['shrinkWeight'] > 0) {
                        $hasWeight = true;
                        break;
                    }
                }

                if ($hasWeight) {
                    // Iterative proportional distribution with clamping
                    while ($remainingOverflow > 0 && !empty($activeItems)) {
                        $totalSw = 0;

                        foreach ($activeItems as $item) {
                            $totalSw += $item['shrinkWeight'];
                        }

                        if ($totalSw <= 0) break;

                        $distributedInPass = 0;

                        $newActive = [];

                        foreach ($activeItems as $item) {
                            $idx = $item['idx'];

                            $currentSize = $shrinkSizes[$idx];

                            $reduction = (int)($remainingOverflow * $item['shrinkWeight'] / $totalSw);

                            $newSize = $currentSize - $reduction;

                            if ($newSize < 0) {
                                $newSize = 0;
                            }

                            $clamped = false;

                            if ($item['minVal'] > 0 && $newSize < $item['minVal']) {
                                $newSize = (int)($item['minVal']);

                                $clamped = true;
                            }

                            $actualReduction = $currentSize - $newSize;

                            $distributedInPass += $actualReduction;

                            $shrinkSizes[$idx] = $newSize;

                            if (!$clamped) {
                                $newActive[] = $item;
                            }
                        }

                        $remainingOverflow -= $distributedInPass;

                        // Prevent infinite loop: if all reductions round to 0 (int math),
                        // no progress is made and loop would never exit.
                        if ($distributedInPass <= 0) break;

                        $activeItems = $newActive;
                    }
                } else {
                    // All shrink weights are 0: equal distribution
                    $equalShare = count($shrinkSizes) > 0 ? (int)($remainingOverflow / count($shrinkSizes)) : 0;

                    foreach ($shrinkSizes as $idx => $size) {
                        $newSize = (int)($size - $equalShare);

                        if ($newSize < 0) {
                            $newSize = 0;
                        }

                        // Apply min-width clamp
                        foreach ($activeItems as $item) {
                            if ($item['idx'] === $idx && $item['minVal'] > 0 && $newSize < $item['minVal']) {
                                $newSize = (int)($item['minVal']);
                                break;
                            }
                        }

                        $shrinkSizes[$idx] = $newSize;
                    }
                }

                // ── Write back final sizes for all shrink items ──

                foreach ($shrinkSizes as $idx => $size) {
                    $ch = $lineChildren[$idx];

                    if ($isRow) {
                        $ch->w = (int)$size;
                        $ch->visualW = PercentResolver::resolveVisualW($ch->style, $ch->w);
                    } else {
                        $ch->h = (int)$size;
                        $ch->visualH = PercentResolver::resolveVisualH($ch->style, $ch->h);
                    }
                }
            }

            // ── Step 8: Min/max constraints ──

            foreach ($lineChildren as $ch) {
                $ch->w = (int)max(0, (int)PercentResolver::resolveMinMax($ch->style, $ch->w, true));

                $ch->h = (int)max(0, (int)PercentResolver::resolveMinMax($ch->style, $ch->h, false));

                $ch->visualW = PercentResolver::resolveVisualW($ch->style, $ch->w);
                $ch->visualH = PercentResolver::resolveVisualH($ch->style, $ch->h);
            }

            // ── Step 9: Recalculate totalMain after shrink ──

            $lineTotalMain = 0;

            $lineMaxCross = 0;

            foreach ($lineChildren as $ch) {
                $cs = $ch->style;

                $mL = (int)($cs['marginLeft'] ?? $cs['margin'] ?? 0);

                $mR = (int)($cs['marginRight'] ?? $cs['margin'] ?? 0);

                $mT = (int)($cs['marginTop'] ?? $cs['margin'] ?? 0);

                $mB = (int)($cs['marginBottom'] ?? $cs['margin'] ?? 0);

                if ($isRow) {
                    $lineTotalMain += $ch->w + $mL + $mR;

                    $lineMaxCross = (int)max($lineMaxCross, $ch->visualH);
                } else {
                    $lineTotalMain += $ch->h + $mT + $mB;

                    $lineMaxCross = (int)max($lineMaxCross, $ch->visualW);
                }
            }

            $lineTotalMain += $gap * ($lineCount - 1);


            // ── Auto margins absorb positive free space BEFORE justify-content. ──

            $hasAutoMainMargin = false;

            $autoMarginCount = 0;


            foreach ($lineChildren as $ch) {
                $cs = $ch->style;

                $mL = (int)($cs['marginLeftAuto'] ?? false);

                $mR = (int)($cs['marginRightAuto'] ?? false);

                if ($mL || $mR) $hasAutoMainMargin = true;

                if ($mL) $autoMarginCount++;

                if ($mR) $autoMarginCount++;
            }

            $resolvedAutoMargins = null;

            if ($hasAutoMainMargin) {
                $remainingForAuto = $lineContainerMain - $lineTotalMain;

                if ($remainingForAuto > 0 && $autoMarginCount > 0) {
                    $spacePerAuto = (int)($remainingForAuto / $autoMarginCount);

                    $resolvedAutoMargins = [];

                    foreach ($lineChildren as $idx => $ch) {
                        $cs = $ch->style;

                        $resolvedAutoMargins[$idx] = [
                            'left' => ($cs['marginLeftAuto'] ?? false) ? $spacePerAuto : 0,
                            'right' => ($cs['marginRightAuto'] ?? false) ? $spacePerAuto : 0,
                        ];
                    }

                    // Recalculate lineTotalMain with resolved auto margins
                    $lineTotalMain = 0;

                    foreach ($lineChildren as $idx => $ch) {
                        $mL = (int)($resolvedAutoMargins[$idx]['left']);
                        $mR = (int)($resolvedAutoMargins[$idx]['right']);

                        $mT = (int)($ch->style['marginTop'] ?? $ch->style['margin'] ?? 0);
                        $mB = (int)($ch->style['marginBottom'] ?? $ch->style['margin'] ?? 0);

                        if ($isRow) {
                            $lineTotalMain += $ch->visualW + $mL + $mR;
                        } else {
                            $lineTotalMain += $ch->visualH + $mT + $mB;
                        }
                    }

                    $lineTotalMain += $gap * ($lineCount - 1);
                }
            }


            // ── Step 10: Justify-content for this line ──

            $mainStart = match ($justify) {
                'center' => ($lineContainerMain - $lineTotalMain) / 2,
                'flex-end' => $lineContainerMain - $lineTotalMain,
                'space-between' => 0,
                'space-around' => 0,
                'space-evenly' => 0,
                default => 0,
            };

            $spaceBetween = 0;

            if ($justify === 'space-between' && $lineCount > 1) {
                $spaceBetween = ($lineContainerMain - $lineTotalMain) / ($lineCount - 1);
            } elseif ($justify === 'space-around' && $lineCount > 0) {
                $spaceBetween = ($lineContainerMain - $lineTotalMain) / $lineCount;
                $mainStart = $spaceBetween / 2;
            } elseif ($justify === 'space-evenly' && $lineCount > 0) {
                $spaceBetween = ($lineContainerMain - $lineTotalMain) / ($lineCount + 1);
                $mainStart = $spaceBetween;
            }


            // ── Step 11: Position children in this line ──

            $currentMain = $mainStart;

            $indices = range(0, $lineCount - 1);

            if ($reversed) {
                $indices = array_reverse($indices);
            }

            // This line's cross-axis position
            $lineCrossBase = $accumulatedCrossOffset;

            foreach ($indices as $idx) {
                $i = (int)$idx;
                $ch = $lineChildren[$i];
                $childStyle = $ch->style;

                $childMarginLeft = ($resolvedAutoMargins !== null && isset($resolvedAutoMargins[$i]['left']) ? $resolvedAutoMargins[$i]['left'] : null)
                    ?? (int)($childStyle['marginLeft'] ?? $childStyle['margin'] ?? 0);

                $childMarginRight = ($resolvedAutoMargins !== null && isset($resolvedAutoMargins[$i]['right']) ? $resolvedAutoMargins[$i]['right'] : null)
                    ?? (int)($childStyle['marginRight'] ?? $childStyle['margin'] ?? 0);

                $childMarginTop = (int)($childStyle['marginTop'] ?? $childStyle['margin'] ?? 0);

                $childMarginBottom = (int)($childStyle['marginBottom'] ?? $childStyle['margin'] ?? 0);

                // Main axis position
                $oldX = $ch->x;
                $oldY = $ch->y;

                if ($isRow) {
                    $ch->x = $node->x + $paddingLeft + (int)$currentMain + $childMarginLeft;
                } else {
                    $ch->y = $node->y + $paddingTop + (int)$currentMain + $childMarginTop;
                }


                // Cross axis alignment (use line cross offset instead of full containerCross)
                $effectiveAlign = $childStyle['alignSelf'] ?? 'auto';

                if ($effectiveAlign === 'auto') {
                    $effectiveAlign = $align;
                }

                if ($isWrapping) {
                    // In wrapping mode, cross axis is per-line
                    if ($effectiveAlign === 'stretch') {
                        if ($isRow) {
                            if (!$lineFlexData[$i]['hasExplicitCrossSize']) {
                                $crossBefore = $ch->h;
                                $stretchedH = (int)max(0, (int)($lineMaxCross - $childMarginTop - $childMarginBottom));
                                if ($stretchedH > 0) {
                                    $ch->h = $stretchedH;
                                    $ch->visualH = PercentResolver::resolveVisualH($ch->style, $ch->h);
                                    $lineFlexData[$i]['crossAxisSized'] = ($ch->h !== $crossBefore);
                                }
                            }
                            $ch->y = $node->y + $paddingTop + $lineCrossBase + $childMarginTop;
                        } else {
                            if (!$lineFlexData[$i]['hasExplicitCrossSize']) {
                                $crossBefore = $ch->w;
                                $stretchedW = (int)max(0, (int)($lineMaxCross - $childMarginLeft - $childMarginRight));
                                if ($stretchedW > 0) {
                                    $ch->w = $stretchedW;
                                    $ch->visualW = PercentResolver::resolveVisualW($ch->style, $ch->w);
                                    $lineFlexData[$i]['crossAxisSized'] = ($ch->w !== $crossBefore);
                                }
                            }
                            $ch->x = $node->x + $paddingLeft + $lineCrossBase + $childMarginLeft;
                        }
                    } else {
                        $crossSize = $isRow ? $ch->h : $ch->w;
                        $crossOffset = match ($effectiveAlign) {
                            'center' => (int)(($lineMaxCross - $crossSize) / 2),
                            'flex-end' => $lineMaxCross - $crossSize,
                            default => 0,
                        };

                        if ($isRow) {
                            $ch->y = $node->y + $paddingTop + $lineCrossBase + $crossOffset + $childMarginTop;
                        } else {
                            $ch->x = $node->x + $paddingLeft + $lineCrossBase + $crossOffset + $childMarginLeft;
                        }
                    }
                } else {
                    // Non-wrapping: full containerCross with margin adjustment
                    if ($effectiveAlign === 'stretch') {
                        if ($isRow && !$lineFlexData[$i]['hasExplicitCrossSize']) {
                            $crossBefore = $ch->h;
                            $stretchedH = (int)max(0, (int)($containerCross - $childMarginTop - $childMarginBottom));
                            if ($stretchedH > 0) {
                                $ch->h = $stretchedH;
                                $ch->visualH = PercentResolver::resolveVisualH($ch->style, $ch->h);
                                $lineFlexData[$i]['crossAxisSized'] = ($ch->h !== $crossBefore);
                            }
                        } elseif (!$isRow && !$lineFlexData[$i]['hasExplicitCrossSize']) {
                            $crossBefore = $ch->w;
                            $stretchedW = (int)max(0, (int)($containerCross - $childMarginLeft - $childMarginRight));
                            if ($stretchedW > 0) {
                                $ch->w = $stretchedW;
                                $ch->visualW = PercentResolver::resolveVisualW($ch->style, $ch->w);
                                $lineFlexData[$i]['crossAxisSized'] = ($ch->w !== $crossBefore);
                            }
                        }
                    }

                    $crossSize = $isRow ? $ch->h : $ch->w;
                    $crossOffset = match ($effectiveAlign) {
                        'center' => (int)(($containerCross - $crossSize) / 2),
                        'flex-end' => $containerCross - $crossSize,
                        'stretch' => 0,
                        default => 0,
                    };

                    if ($isRow) {
                        $ch->y = $node->y + $paddingTop + $crossOffset;
                    } else {
                        $ch->x = $node->x + $paddingLeft + $crossOffset;
                    }
                }

                // Cross axis margin
                if ($isRow) {
                    $ch->y += $childMarginTop;
                } else {
                    $ch->x += $childMarginLeft;
                }

                // Shift descendants if position changed
                $dx = (int)($ch->x) - (int)($oldX);
                $dy = (int)($ch->y) - (int)($oldY);

                if ($dy !== 0) {
                    foreach ($ch->children as $grandchild) {
                        ScrollHelper::shiftDescendantsY($grandchild, $dy);
                    }
                }

                if ($dx !== 0) {
                    foreach ($ch->children as $grandchild) {
                        ScrollHelper::shiftDescendantsX($grandchild, $dx);
                    }
                }

                // Advance main position
                $chMainSize = $isRow ? (int)($ch->visualW) : (int)($ch->visualH);
                $currentMain += $chMainSize + $gap + $spaceBetween;

                if ($isRow) {
                    $currentMain += $childMarginLeft + $childMarginRight;
                } else {
                    $currentMain += $childMarginTop + $childMarginBottom;
                }
            }


            // ── Two-pass: re-resolve internal children of sized items ──

            foreach ($lineChildren as $idxTp => $chTp) {
                $dataTp = $lineFlexData[$idxTp];
                $needsTwoPass = $dataTp['isFlexGrow'] || $dataTp['crossAxisSized'];

                // Debug: two-pass condition
                if ($chTp->isScrollContainer) {
                    // removed file_put_contents debug log
                }

                if ($needsTwoPass && count($chTp->children) > 0) {
                    $display = (string)($chTp->style['display'] ?? 'block');

                    if ($display === 'flex' || $display === 'grid') {
                        // Full re-layout for flex/grid containers.
                        $leftOff = $chTp->style['left'] ?? 0;
                        $topOff = $chTp->style['top'] ?? 0;
                        $prX = $chTp->x - $leftOff;
                        $prY = $chTp->y - $topOff;

                        $hasOrigW = array_key_exists('width', $chTp->style);
                        $hasOrigH = array_key_exists('height', $chTp->style);
                        $origW = $chTp->style['width'] ?? null;
                        $origH = $chTp->style['height'] ?? null;

                        $chTp->style['width'] = $chTp->w;
                        $chTp->style['height'] = $chTp->h;

                        $chTp->layoutDirty = true;

                        foreach ($chTp->children as $gc) {
                            $gc->layoutDirty = true;
                        }

                        $chCtx = new LayoutContext($prX, $prY, $ctx->parent);
                        $this->resolver->resolveNode($chTp, $chCtx);

                        $chTp->visualW = PercentResolver::resolveVisualW($chTp->style, $chTp->w);
                        $chTp->visualH = PercentResolver::resolveVisualH($chTp->style, $chTp->h);

                        if ($hasOrigW) {
                            $chTp->style['width'] = $origW;
                        } else {
                            unset($chTp->style['width']);
                        }

                        if ($hasOrigH) {
                            $chTp->style['height'] = $origH;
                        } else {
                            unset($chTp->style['height']);
                        }
                    } else {
                        // Block/scroll containers: full re-resolve so auto-stack
                        // re-positions children with the corrected parent width.
                        // CSS 2.2 §10.6.3: block children must be re-laid-out when
                        // containing block width changes (e.g. via flex-grow).
                        $leftOff = $chTp->style['left'] ?? 0;
                        $topOff = $chTp->style['top'] ?? 0;
                        $prX = $chTp->x - $leftOff;
                        $prY = $chTp->y - $topOff;

                        $hasOrigW = array_key_exists('width', $chTp->style);
                        $hasOrigH = array_key_exists('height', $chTp->style);
                        $origW = $chTp->style['width'] ?? null;
                        $origH = $chTp->style['height'] ?? null;

                        $chTp->style['width'] = $chTp->w;
                        $chTp->style['height'] = $chTp->h;

                        $chTp->layoutDirty = true;

                        foreach ($chTp->children as $gc) {
                            $gc->layoutDirty = true;
                        }

                        $chCtx = new LayoutContext($prX, $prY, $ctx->parent);
                        $this->resolver->resolveNode($chTp, $chCtx);

                        $chTp->visualW = PercentResolver::resolveVisualW($chTp->style, $chTp->w);
                        $chTp->visualH = PercentResolver::resolveVisualH($chTp->style, $chTp->h);

                        if ($hasOrigW) {
                            $chTp->style['width'] = $origW;
                        } else {
                            unset($chTp->style['width']);
                        }

                        if ($hasOrigH) {
                            $chTp->style['height'] = $origH;
                        } else {
                            unset($chTp->style['height']);
                        }
                    }
                }

                if ($needsTwoPass && $chTp->isScrollContainer) {
                    $padTsp = (int)($chTp->style['paddingTop'] ?? $chTp->style['padding'] ?? 0);
                    $padLsp = (int)($chTp->style['paddingLeft'] ?? $chTp->style['padding'] ?? 0);
                    $padRsp = (int)($chTp->style['paddingRight'] ?? $chTp->style['padding'] ?? 0);
                    $coffY = $chTp->y + $padTsp - $chTp->scrollTop;

                    $this->resolver->getBlockStrategy()->finalizeScrollContainer($chTp, $ctx, $chTp->style, $coffY, $padLsp, $padRsp);
                }
            }


            // Advance cross axis offset for next wrapping line
            $accumulatedCrossOffset += $lineMaxCross + $gap;

            $lineCrossData[] = [
                'children' => $lineChildren,
                'maxCross' => $lineMaxCross,
                'crossBase' => $lineCrossBase,
            ];
        }


        // ── align-content: distribute lines in cross axis (CSS Flexbox §8.4) ──
        $alignContent = $style['alignContent'] ?? 'stretch';
        if ($isWrapping && count($lineCrossData) > 1 && $containerCross > 0) {
            $totalCrossUsed = 0;
            foreach ($lineCrossData as $ld) {
                $totalCrossUsed += (int)($ld['maxCross']);
            }
            $totalCrossUsed += $gap * (count($lineCrossData) - 1);
            $remainingCross = max(0, $containerCross - $totalCrossUsed);

            if ($remainingCross > 0 && $alignContent !== 'flex-start') {
                $numLines = count($lineCrossData);
                $newBases = [];
                $newMaxCrosses = [];

                if ($alignContent === 'stretch') {
                    // Split remaining space equally among lines, increasing each line's cross size
                    $extraPerLine = (int)($remainingCross / $numLines);
                    $base = 0;
                    for ($i = 0; $i < $numLines; $i++) {
                        $newBases[$i] = $base;
                        $newMaxCrosses[$i] = (int)($lineCrossData[$i]['maxCross'] + $extraPerLine);
                        $base += $newMaxCrosses[$i] + $gap;
                    }
                    // Apply stretched height to each line's children
                    foreach ($lineCrossData as $idx => $ld) {
                        $stretchedCross = $newMaxCrosses[$idx];
                        foreach ($ld['children'] as $ch) {
                            $chStyle = $ch->style;
                            $hasExplicitCrossSize = $isRow
                                ? array_key_exists('height', $chStyle)
                                : array_key_exists('width', $chStyle);
                            if (!$hasExplicitCrossSize) {
                                if ($isRow) {
                                    $ch->h = $stretchedCross;
                                    $ch->visualH = PercentResolver::resolveVisualH($chStyle, $ch->h);
                                } else {
                                    $ch->w = $stretchedCross;
                                    $ch->visualW = PercentResolver::resolveVisualW($chStyle, $ch->w);
                                }
                            }
                        }
                    }
                } else {
                    $crossStart = 0;
                    $space = 0;
                    switch ($alignContent) {
                        case 'flex-end':
                            $crossStart = (int)($remainingCross);
                            break;
                        case 'center':
                            $crossStart = (int)($remainingCross / 2);
                            break;
                        case 'space-between':
                            $space = (int)($remainingCross / ($numLines - 1));
                            break;
                        case 'space-around':
                            $space = (int)($remainingCross / $numLines);
                            $crossStart = (int)($space / 2);
                            break;
                        case 'space-evenly':
                            $space = (int)($remainingCross / ($numLines + 1));
                            $crossStart = $space;
                            break;
                    }
                    $base = $crossStart;
                    $extraBetween = $space;
                    for ($i = 0; $i < $numLines; $i++) {
                        $newBases[$i] = $base;
                        $newMaxCrosses[$i] = (int)($lineCrossData[$i]['maxCross']);
                        $base += (int)($lineCrossData[$i]['maxCross']) + $gap + $extraBetween;
                    }
                }

                // Apply cross offset shifts to each line's children
                foreach ($lineCrossData as $idx => $ld) {
                    $shift = (int)($newBases[$idx] - $ld['crossBase']);
                    if ($shift !== 0) {
                        foreach ($ld['children'] as $ch) {
                            $dyShift = $shift;
                            $ch->y += $dyShift;
                            foreach ($ch->children as $gc) {
                                ScrollHelper::shiftDescendantsY($gc, $dyShift);
                            }
                        }
                    }
                }
            }
        }


        // ── Scroll container post-processing for flex/grid display modes ──

        // CSS standard: a flex container with auto main-axis size computes it
        // from children. With auto cross-axis size, it also computes from children.

        //
        // CRITICAL: array_key_exists('height', $style) returns TRUE when
        // height:auto is set, which caused auto-height to be SKIPPED. We must
        // explicitly check that the value is not 'auto' or empty.

        $hasExplicitW = array_key_exists('width', $style) && $style['width'] !== 'auto' && $style['width'] !== '';

        $hasExplicitH = array_key_exists('height', $style) && $style['height'] !== 'auto' && $style['height'] !== '';

        $hasWPct = array_key_exists('widthPercent', $style);

        $hasHPct = array_key_exists('heightPercent', $style);

        if ($isRow) {
            // Main-axis: auto-width from children

            if (!$hasExplicitW && !$hasWPct) {
                $maxRight = $node->x + $paddingLeft;

                foreach ($children as $ch) {
                    $chRight = $ch->x + $ch->visualW;

                    $mR = (int)($ch->style['marginRight'] ?? $ch->style['margin'] ?? 0);

                    if ($chRight + $mR > $maxRight) $maxRight = (int)($chRight + $mR);
                }

                $node->w = (int)max($node->w, $maxRight - $node->x + $paddingRight);
            }

            // Cross-axis: auto-height from children

            if (!$hasExplicitH && !$hasHPct) {
                $maxBottom = $node->y + $paddingTop;

                foreach ($children as $ch) {
                    $chBottom = $ch->y + $ch->visualH;

                    $mB = (int)($ch->style['marginBottom'] ?? $ch->style['margin'] ?? 0);

                    if ($chBottom + $mB > $maxBottom) $maxBottom = (int)($chBottom + $mB);
                }

                $node->h = (int)max($node->h, $maxBottom - $node->y + $paddingBottom);
            }
        } else {
            // Cross-axis: auto-width from children

            if (!$hasExplicitW && !$hasWPct) {
                $maxRight = $node->x + $paddingLeft;

                foreach ($children as $ch) {
                    $chRight = $ch->x + $ch->visualW;

                    $mR = (int)($ch->style['marginRight'] ?? $ch->style['margin'] ?? 0);

                    if ($chRight + $mR > $maxRight) $maxRight = (int)($chRight + $mR);
                }

                $node->w = (int)max($node->w, $maxRight - $node->x + $paddingRight);
            }

            // Main-axis: auto-height from children

            if (!$hasExplicitH && !$hasHPct) {
                $maxBottom = $node->y + $paddingTop;

                foreach ($children as $ch) {
                    $chBottom = $ch->y + $ch->visualH;

                    $mB = (int)($ch->style['marginBottom'] ?? $ch->style['margin'] ?? 0);

                    if ($chBottom + $mB > $maxBottom) $maxBottom = (int)($chBottom + $mB);
                }

                $node->h = (int)max($node->h, $maxBottom - $node->y + $paddingBottom);
            }
        }

        // ── Second pass: resolve absolute/fixed children now that container dimensions are final ──
        foreach ($absoluteChildren as $child) {
            $childCtx = new LayoutContext($node->x + $paddingLeft - $scrollShiftX, $node->y + $paddingTop - $scrollShiftY, $node);
            $this->resolver->resolveNode($child, $childCtx);
        }



        // Set container's own visualW/visualH
        $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
        $node->visualH = PercentResolver::resolveVisualH($style, $node->h);
    }


    /**
     * Apply flex-basis to children in a flex line.
     */
    private function applyFlexBasis(array $children, array $flexItemData, bool $isRow, array $parentStyle = []): void
    {
        foreach ($children as $idx => $ch) {
            $data = $flexItemData[$idx];

            $basis = $data['basis'];

            // ── Numeric basis (flex-basis: <length>|<percentage>) ──
            if (is_int($basis) && $basis >= 0) {
                if ($basis > 0) {
                    if ($isRow) {
                        $ch->w = (int)max(0, $basis);
                        $ch->visualW = PercentResolver::resolveVisualW($ch->style, $ch->w);
                    } else {
                        $ch->h = (int)max(0, $basis);
                        $ch->visualH = PercentResolver::resolveVisualH($ch->style, $ch->h);
                    }
                }
            // ── flex-basis: content — ignore width/height, always use content size ──
            } elseif ($basis === 'content') {
                $chText = $ch->content ?? '';
                if (is_string($chText) && strlen($chText) > 0) {
                    PercentResolver::resolveFontSizeUnit($ch->style);
                    $fs = (int)($ch->style['fontSize'] ?? 14);
                    $bd = ($ch->style['bold'] ?? 0) !== 0;
                    $measured = PercentResolver::resolveTextWidth($chText, $fs, $bd);
                    if ($measured > 0) {
                        if ($isRow) {
                            $ch->w = $measured;
                        } else {
                            $lineH = PercentResolver::resolveLineHeight($ch->style, $fs, 16, $parentStyle);
                            if ($ch->h === 0 || $ch->h < $lineH) {
                                $ch->h = $lineH;
                            }
                        }
                    }
                }
            // ── flex-basis: auto (default) — use width/height if set, else content ──
            } else {
                $flexBasis = $ch->style['flexBasis'] ?? 'auto';

                if ($flexBasis !== 'auto') {
                    $basisVal = (int)$flexBasis;

                    if ($basisVal > 0) {
                        if ($isRow) {
                            $ch->w = (int)max(0, $basisVal);
                            $ch->visualW = PercentResolver::resolveVisualW($ch->style, $ch->w);
                        } else {
                            $ch->h = (int)max(0, $basisVal);
                            $ch->visualH = PercentResolver::resolveVisualH($ch->style, $ch->h);
                        }
                    }
                }

                // -- Text measurement for flex-basis:auto (basis=-1 or 'auto') --

                $chText = $ch->content ?? '';

                if ((is_string($chText) && strlen($chText) > 0)) {
                    PercentResolver::resolveFontSizeUnit($ch->style);
                    $fs = (int)($ch->style['fontSize'] ?? 14);

                    $bd = ($ch->style['bold'] ?? 0) !== 0;

                    $measured = PercentResolver::resolveTextWidth($chText, $fs, $bd);

                    if ($measured > 0) {
                        if ($isRow) {
                            // Row: text width = measured content width
                            // Content-sized child (no explicit width, no flex-grow): always use text-measured
                            $hasFlexW = array_key_exists('width', $ch->style);
                            if (!$hasFlexW && !$data['isFlexGrow']) {
                                $ch->w = $measured;
                            } elseif ($ch->w === 0 || $ch->w < $measured) {
                                $ch->w = $measured;
                            }
                        } else {
                            // Column: text height = line-height (based on font size)
                            // CSS 2.2 §10.8.1: 从 flex 容器继承 line-height
                            $lineH = PercentResolver::resolveLineHeight($ch->style, $fs, 16, $parentStyle);

                            if ($ch->h === 0 || $ch->h < $lineH) {
                                $ch->h = $lineH;
                            }
                        }
                    }
                } else {
                    // ── Container element (no direct text): measure descendant text width ──
                    // This handles cases like header flex items where a <div> contains
                    // <h1> and <p> children with text. Without this, container flex items
                    // keep w=parentWidth (from block default stretch) and incorrectly wrap.
                    $descW = $this->getMaxDescendantTextWidth($ch);
                    if ($descW > 0) {
                        $hasFlexW = array_key_exists('width', $ch->style);
                        if (!$hasFlexW && !$data['isFlexGrow']) {
                            $ch->w = $descW;
                        } elseif ($ch->w === 0 || $ch->w < $descW) {
                            $ch->w = $descW;
                        }
                    }
                }
            }
        }
    }


    /**
     * Recursively find the maximum text content width among all descendant
     * text leaf nodes. Used for flex-basis:auto container elements that have
     * no direct text content but contain text-bearing children (e.g. header
     * <div> with <h1> and <p> inside).
     */
    private function getMaxDescendantTextWidth(RenderNode $node): int
    {
        // Direct text content
        $text = $node->content ?? '';
        if (is_string($text) && strlen($text) > 0) {
            PercentResolver::resolveFontSizeUnit($node->style);
            $fs = (int)($node->style['fontSize'] ?? 14);
            $bd = ($node->style['bold'] ?? 0) !== 0;
            return PercentResolver::resolveTextWidth($text, $fs, $bd);
        }

        // Check children recursively
        $maxW = 0;
        foreach ($node->children as $child) {
            $childW = $this->getMaxDescendantTextWidth($child);
            if ($childW > $maxW) {
                $maxW = $childW;
            }
        }

        return $maxW;
    }
}
