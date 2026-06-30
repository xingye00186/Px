<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Core\Config;
use Px\Rendering\ComputedStyle;
use Px\Rendering\CssMappings;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\CssStyleHelper;
use Px\Rendering\Layout\LayoutConstraints;
use Px\Rendering\Layout\FragmentBuilder;

/**
 * FlexLayoutStrategy �?Flex 布局策略
 *
 * CSS Flexible Box Layout Module Level 1:
 * 实现完整�?flex 布局算法，包�?
 * - flex-direction (row/column), flex-wrap
 * - flex-grow/flex-shrink/flex-basis
 * - justify-content (flex-start/center/flex-end/space-between/space-around/space-evenly)
 * - align-items (stretch/center/flex-start/flex-end)
 * - align-self (单子项覆�?
 * - order 排序
 * - 两阶段子项重解析（flex-grow/cross-axis stretch 后的内部 re-layout�?
 */
class FlexLayoutStrategy implements LayoutStrategyInterface
{
    /**
     * Pure FragmentBuilder 布局入口。
     * 直接使用 LayoutConstraints + ComputedStyle。
     */
    public function resolveWithBuilder(
        RenderNode         $node,
        LayoutConstraints  $constraints,
        ?ComputedStyle     $style,
        FragmentBuilder    $builder
    ): void
    {
        $parentX = $constraints->parentContentX;
        $parentY = $constraints->parentContentY;
        $this->resolveFlexLayout($node, $parentX, $parentY, $style, $builder);

        // 同步 builder 中的子节点 Fragment 为 flex 算法计算的正确位置
        // flex 算法直接写入 $node->children[$i]->x/y，但 builder 中的子 Fragment
        // 来自 resolveChildren（flex 算法之前），位置已过时。
        // 此处用 flex 计算后的位置重建子 Fragment 列表，确保 applyTo 写入正确的值。
        $flexChildren = [];
        foreach ($node->children as $child) {
            $childCS = $child->computedStyle;
            $flexChildren[] = new LayoutFragment(
                x: $child->x,
                y: $child->y,
                w: $child->w,
                h: $child->h,
                visualW: $child->visualW,
                visualH: $child->visualH,
                layer: $child->layer,
                contentWidth: $child->contentWidth,
                contentHeight: $child->contentHeight,
                style: $childCS,
                children: [], // children will be applied via their own applyTo
            );
        }
        $builder->replaceChildren($flexChildren);
    }

    private LayoutResolver $resolver;

    /** @var array<string, int> Auto margin X offset tracking */
    private array $marginAutoOffsetsX = [];

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Flex layout: compute child positions using flex algorithm.
     */
    public function resolveFlexLayout(
        RenderNode    $node,
        int           $parentX,
        int           $parentY,
        ?ComputedStyle $computedStyle,
        ?FragmentBuilder $builder = null
    ): void
    {
        // Local style array from ComputedStyle for algorithm body
        $style = $node->getStyleArray();

        $left = (int)($style['left'] ?? 0);

        $top = (int)($style['top'] ?? 0);

        $width = (int)($style['width'] ?? 0);

        $height = (int)($style['height'] ?? 0);

        // CSS 2.2 §10.3.7: margins apply to flex containers as block-level elements
        $cbWidth = $node->parent ? CssStyleHelper::contentBoxWidth($node->parent->getStyleArray(), $node->parent->w) : 0;
        $marginLeft = CssStyleHelper::resolveLength($style, 'marginLeft', $cbWidth);
        $marginTop = CssStyleHelper::resolveLength($style, 'marginTop', $cbWidth);

        $node->x = $left + $parentX + $marginLeft;
        // DEBUG: check target-box positioning
        if ($node->w === 300 && $node->h >= 100) {
            $dbgPW = ($node->parent !== null) ? $node->parent->w : -1;
            $dbgPT = ($node->parent !== null) ? $node->parent->type : 'null';
            $dbgPX = ($node->parent !== null) ? $node->parent->x : -1;
            error_log('[FLEX_AUTOMARGIN] SET_X: left=' . $left . ' parentX=' . $parentX . ' x=' . $node->x . ' pw=' . $dbgPW . ' ptype=' . $dbgPT . ' px=' . $dbgPX);
        }

        $node->y = $top + $parentY + $marginTop;

        // Apply translate from animatedStyle

        $translateX = (int)($style['translateX'] ?? 0);

        $translateY = (int)($style['translateY'] ?? 0);

        $node->x += $translateX;

        $node->y += $translateY;

        // CSS: flex item percentage width resolves against content width
        // When parent is null (top-level element under #root), use window viewport as containing block
        $parentW = (int)(($node->parent !== null)
            ? CssStyleHelper::contentBoxWidth($node->parent->getStyleArray(), $node->parent->w)
            : (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0));

        $parentH = ($node->parent !== null) ? $node->parent->h : (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0);

        $width = CssStyleHelper::resolveWithCalc($style, 'width', $parentW);

        $height = CssStyleHelper::resolveWithCalc($style, 'height', $parentH);

        // CSS 2.2 §10.7: min/max constraints apply to flex containers too
        $node->w = (int)max(0, (int)CssStyleHelper::applyMinMax($style, $width, true));

        $node->h = (int)max(0, (int)CssStyleHelper::applyMinMax($style, $height, false));

        $node->visualW = CssStyleHelper::visualWidth($style, $node->w);
        $node->visualH = CssStyleHelper::visualHeight($style, $node->h);

        // ── Auto-margin centering for flex containers (CSS 2.2 §10.3.3) ──
        // Flex containers (display:flex) don't go through BlockLayoutStrategy's
        // auto-margin path (resolveNormalFlow). Handle margin:auto here so that
        // every re-resolution via two-pass preserves the centering offset.
        // Must be placed AFTER w/visualW are set so $totalW is correct.
        $checkML = $style['marginLeftAuto'] ?? false;
        $checkMR = $style['marginRightAuto'] ?? false;
        if ($checkML || $checkMR) {
            $cbW = ($node->parent !== null)
                ? CssStyleHelper::contentBoxWidth($node->parent->getStyleArray(), $node->parent->w)
                : 0;
            if ($cbW > 0) {
                $totalW = max($node->w, $node->visualW ?? $node->w);
                if ($checkML && $checkMR && $cbW > $totalW) {
                    $half = (int)(($cbW - $totalW + 1) / 2);
                    // 使用本地跟踪避免累积，不写入 getStyleArray（只读拷贝）
                    $nodeX = $this->marginAutoOffsetsX[$node->type] ?? 0;
                    $node->x += $half - $nodeX;
                    $this->marginAutoOffsetsX[$node->type] = $half;
                } elseif ($checkML && !$checkMR && $cbW > $totalW) {
                    $remaining = $cbW - $totalW;
                    $nodeX = $this->marginAutoOffsetsX[$node->type] ?? 0;
                    $node->x += $remaining - $nodeX;
                    $this->marginAutoOffsetsX[$node->type] = $remaining;
                } elseif (!$checkML && $checkMR && $cbW > $totalW) {
                    // right margin auto: no offset needed
                }
            }
        }

        // ── Scroll container post-processing for flex/grid display modes ──

        $parentDisplay = ($node->parent !== null) ? ($node->parent->getStyleArray()['display'] ?? '') : '';

        $isFlexOrGridItem = ($parentDisplay === 'flex' || $parentDisplay === 'grid');

        if (!$isFlexOrGridItem) {
            $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);

            if (!$hasExplicitW && $width === 0 && $node->parent !== null) {
                $width = (int)CssStyleHelper::contentBoxWidth($node->parent->getStyleArray(), $node->parent->w);

                $node->w = (int)max(0, (int)$width);
            }
        } elseif ($node->parent !== null && $parentDisplay === 'flex') {
            // ── Scroll container post-processing for flex/grid display modes ──

            // cross-axis (width) size for correct first-pass internal layout.

            // Without this, flex items with display:flex/grid get width=0, causing
            // their internal grid to compute 1 column with inflated height, which
            // then triggers flex-shrink and damages sibling items' explicit sizes.

            $parentDirection = $node->parent->getStyleArray()['flexDirection'] ?? 'row';

            $parentIsColumn = ($parentDirection === 'column' || $parentDirection === 'column-reverse');

            $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);

            if ($parentIsColumn && !$hasExplicitW && $width === 0) {
                $parentContentW = CssStyleHelper::contentBoxWidth($node->parent->getStyleArray(), $node->parent->w);

                $width = $parentContentW;

                $node->w = (int)max(0, (int)$width);

                $node->visualW = CssStyleHelper::visualWidth($style, $node->w);
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
        // NOT have its height filled from parent height —only width should stretch.

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

        $containerMain = max(0, $isRow ? CssStyleHelper::contentBoxWidth($style, $width) : CssStyleHelper::contentBoxHeight($style, $height));

        $containerCross = max(0, $isRow ? CssStyleHelper::contentBoxHeight($style, $height) : CssStyleHelper::contentBoxWidth($style, $width));

        // ── Step 1: Collect children and resolve ──

        // Apply scroll offset to child parent coordinates for scroll containers

        // A1: scroll offset handled by VNodeRenderer at draw time

        $children = [];
        $absoluteChildren = [];

        foreach ($node->children as $child) {
            $childPosition = $child->getStyleArray()['position'] ?? 'static';
            $childDisplay = $child->getStyleArray()['display'] ?? 'block';

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

            // Defer auto-margin for flex-grow items: their final width is
            // determined by flex-grow, not by first-pass auto-width.
            // Browser: auto-margin computed ONCE after final width is known.
            // Engine: two-pass re-resolves with correct width - skip first pass.
            $childGrow = (float)($child->getStyleArray()['flexGrow'] ?? 0);
            $this->resolver->resolveChildNode($child, $node->x + $paddingLeft, $node->y + $paddingTop, $node);

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
                $orderA = (int)($children[$j]->getStyleArray()['order'] ?? 0);

                $orderB = (int)($children[$j + 1]->getStyleArray()['order'] ?? 0);

                if ($orderA > $orderB) {
                    $tmp = $children[$j];

                    $children[$j] = $children[$j + 1];

                    $children[$j + 1] = $tmp;
                }
            }
        }

        // ── Step 3: 收集 flex item 元数�?(grow/shrink/basis) ──
        $flexItemData = [];

        foreach ($children as $ch) {
            $data = ['grow' => 0.0, 'shrink' => 1.0, 'basis' => -1, 'isFlexGrow' => false];

            $flexRaw = $ch->getStyleArray()['flex'] ?? '';

            if ($flexRaw !== '') {
                $fv = CssMappings::parseFlexValue($flexRaw);

                $data['grow'] = $fv['grow'];

                $data['shrink'] = $fv['shrink'];

                $data['basis'] = $fv['basis'];
            } else {
                $data['grow'] = (float)($ch->getStyleArray()['flexGrow'] ?? 0);

                $data['shrink'] = (float)($ch->getStyleArray()['flexShrink'] ?? 1);

                // 捕获独立�?flex-basis 属性（仅数值，'auto' 由默�?-1 处理�?
                if (isset($ch->getStyleArray()['flexBasis']) && $ch->getStyleArray()['flexBasis'] !== 'auto') {
                    $data['basis'] = (int)$ch->getStyleArray()['flexBasis'];
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
                $flexRaw = $ch->getStyleArray()['flex'] ?? '';
                if ($flexRaw !== '') {
                    $fv = CssMappings::parseFlexValue($flexRaw);
                    $wrapGrow = (float)($fv['grow']);
                } else {
                    $wrapGrow = (float)($ch->getStyleArray()['flexGrow'] ?? 0);
                }
                $wrapFlexData[$idx] = $wrapGrow;
            }

            foreach ($children as $idx => $ch) {
                // Flex-grow items: use min-width/min-height as base size for wrap
                // (they'll be sized by flex-grow after wrapping, but min-size determines
                //  whether they fit on the current line. CSS Flexbox §9.5)
                $wrapGrow = (float)($wrapFlexData[$idx] ?? 0);
                if ($wrapGrow > 0) {
                    $minMain = $isRow ? (int)($ch->getStyleArray()['minWidth'] ?? 0) : (int)($ch->getStyleArray()['minHeight'] ?? 0);
                    // Also consider intrinsic content width (text) as minimum
                    $chText = $ch->content ?? '';
                    if (is_string($chText) && strlen($chText) > 0 && $minMain <= 0) {
                        $fs = (int)($ch->getStyleArray()['fontSize'] ?? 14);
                        $bd = ($ch->getStyleArray()['bold'] ?? 0) !== 0;
                        $textW = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($chText, $fs, $bd) : 0);
                        if ($isRow) {
                            $minMain = max(0, $textW);
                        }
                    }
                    $chMain = max(0, $minMain);
                } else {
                    $chMain = $isRow ? (int)($ch->visualW) : (int)($ch->visualH);
                }

                // Include margins in size calculation

                $cs = $ch->getStyleArray();

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

                $flexRaw = $ch->getStyleArray()['flex'] ?? '';

                if ($flexRaw !== '') {
                    $fv = CssMappings::parseFlexValue($flexRaw);

                    $data['grow'] = $fv['grow'];

                    $data['shrink'] = $fv['shrink'];

                    $data['basis'] = $fv['basis'];
                } else {
                    $data['grow'] = (float)($ch->getStyleArray()['flexGrow'] ?? 0);

                    $data['shrink'] = (float)($ch->getStyleArray()['flexShrink'] ?? 1);

                    // 捕获独立�?flex-basis 属性（仅数值，'auto' 由默�?-1 处理�?
                    if (isset($ch->getStyleArray()['flexBasis']) && $ch->getStyleArray()['flexBasis'] !== 'auto') {
                        $data['basis'] = (int)$ch->getStyleArray()['flexBasis'];
                    }
                }

                if ($data['grow'] > 0) {
                    $data['isFlexGrow'] = true;

                    $lineHasFlexGrow = true;
                }

                $data['hasExplicitCrossSize'] = $isRow
                    ? array_key_exists('height', $ch->getStyleArray())
                    : array_key_exists('width', $ch->getStyleArray());

                $lineFlexData[] = $data;
            }

            // ── Step 4: Apply flex-basis ──
            // 传入容器内容区宽度供百分比 flex-basis 解析
            $containerContentW = CssStyleHelper::contentBoxWidth($style, $node->w);
            $this->applyFlexBasis($lineChildren, $lineFlexData, $isRow, $style, $containerContentW);

            // ── Step 5: Flex-grow ──

            if ($lineHasFlexGrow) {
                $fixedTotalMain = 0;

                foreach ($lineChildren as $idx => $ch) {
                    $data = $lineFlexData[$idx];

                    $cs = $ch->getStyleArray();

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

                // First pass: proportional allocation (only flex-grow items)
                $growAllocations = [];
                $totalAllocated = 0;
                foreach ($lineChildren as $idx => $ch) {
                    $data = $lineFlexData[$idx];
                    if ($data['isFlexGrow']) {
                        $allocated = (int)(($data['grow'] / $totalFlexGrow) * $remainingSpace);
                        $growAllocations[$idx] = max(0, $allocated);
                        $totalAllocated += $allocated;
                    }
                }

                // Second pass: distribute remainder to avoid truncation bias
                // CSS §9.7: remaining fractional space is distributed 1px at a time
                // to items with the largest fractional remainder (in order)
                $remainder = $remainingSpace - $totalAllocated;
                if ($remainder > 0) {
                    // Sort flex-grow items by their un-truncated fractional remainder
                    // (largest first), to allocate the leftover 1px units fairly
                    $growIndices = [];
                    foreach ($lineChildren as $idx => $ch) {
                        $data = $lineFlexData[$idx];
                        if ($data['isFlexGrow']) {
                            $exact = ($data['grow'] / $totalFlexGrow) * $remainingSpace;
                            $fractionalRemainder = $exact - (int)$exact;
                            $growIndices[] = ['idx' => $idx, 'fraction' => $fractionalRemainder];
                        }
                    }
                    // Sort by fractional remainder descending (bubble sort for AOT)
                    $gn = count($growIndices);
                    for ($gi = 0; $gi < $gn; $gi++) {
                        for ($gj = 0; $gj < $gn - $gi - 1; $gj++) {
                            if ($growIndices[$gj]['fraction'] < $growIndices[$gj + 1]['fraction']) {
                                $gtmp = $growIndices[$gj];
                                $growIndices[$gj] = $growIndices[$gj + 1];
                                $growIndices[$gj + 1] = $gtmp;
                            }
                        }
                    }
                    for ($gi = 0; $gi < $remainder && $gi < $gn; $gi++) {
                        $allocIdx = (int)$growIndices[$gi]['idx'];
                        if (!isset($growAllocations[$allocIdx])) {
                            $growAllocations[$allocIdx] = 0;
                        }
                        $growAllocations[$allocIdx] = $growAllocations[$allocIdx] + 1;
                    }
                }

                // Write back final allocations (only flex-grow items, non-grow keep original sizes)
                foreach ($growAllocations as $growIdx => $growSize) {
                    $ch = $lineChildren[$growIdx];
                    if ($isRow) {
                        $ch->w = (int)max(0, $growSize);
                        $ch->visualW = CssStyleHelper::visualWidth($ch->getStyleArray(), $ch->w);
                    } else {
                        $ch->h = (int)max(0, $growSize);
                        $ch->visualH = CssStyleHelper::visualHeight($ch->getStyleArray(), $ch->h);
                    }
                }
            }

            // ── Step 6: Calculate line totalMain ──

            $lineTotalMain = 0;

            $lineMaxCross = 0;

            foreach ($lineChildren as $ch) {
                $cs = $ch->getStyleArray();

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
                            'minVal' => self::resolveFlexMinMain($ch, $isRow, $mainSize),
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
                        $ch->visualW = CssStyleHelper::visualWidth($ch->getStyleArray(), $ch->w);
                    } else {
                        $ch->h = (int)$size;
                        $ch->visualH = CssStyleHelper::visualHeight($ch->getStyleArray(), $ch->h);
                    }
                }
            }

            // ── Step 8: Min/max constraints ──

            foreach ($lineChildren as $ch) {
                $ch->w = (int)max(0, (int)CssStyleHelper::applyMinMax($ch->getStyleArray(), $ch->w, true));

                $ch->h = (int)max(0, (int)CssStyleHelper::applyMinMax($ch->getStyleArray(), $ch->h, false));

                $ch->visualW = CssStyleHelper::visualWidth($ch->getStyleArray(), $ch->w);
                $ch->visualH = CssStyleHelper::visualHeight($ch->getStyleArray(), $ch->h);
            }

            // ── Step 9: Recalculate totalMain after shrink ──

            $lineTotalMain = 0;

            $lineMaxCross = 0;

            foreach ($lineChildren as $ch) {
                $cs = $ch->getStyleArray();

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
                $cs = $ch->getStyleArray();

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
                        $cs = $ch->getStyleArray();

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

                        $mT = (int)($ch->getStyleArray()['marginTop'] ?? $ch->getStyleArray()['margin'] ?? 0);
                        $mB = (int)($ch->getStyleArray()['marginBottom'] ?? $ch->getStyleArray()['margin'] ?? 0);

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
                $childStyle = $ch->getStyleArray();

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
                                if ($stretchedH > $ch->h && $stretchedH > 0) {
                                    $ch->h = $stretchedH;
                                    $ch->visualH = CssStyleHelper::visualHeight($ch->getStyleArray(), $ch->h);
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
                                    $ch->visualW = CssStyleHelper::visualWidth($ch->getStyleArray(), $ch->w);
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
                            if ($stretchedH > $ch->h && $stretchedH > 0) {
                                $ch->h = $stretchedH;
                                $ch->h = (int)max(0, (int)CssStyleHelper::applyMinMax($ch->getStyleArray(), $ch->h, false));
                                $ch->visualH = CssStyleHelper::visualHeight($ch->getStyleArray(), $ch->h);
                                $lineFlexData[$i]['crossAxisSized'] = ($ch->h !== $crossBefore);
                            }
                        } elseif (!$isRow && !$lineFlexData[$i]['hasExplicitCrossSize']) {
                            $crossBefore = $ch->w;
                            $stretchedW = (int)max(0, (int)($containerCross - $childMarginLeft - $childMarginRight));
                            if ($stretchedW > 0) {
                                $ch->w = $stretchedW;
                                $ch->visualW = CssStyleHelper::visualWidth($ch->getStyleArray(), $ch->w);
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
                        $grandchild->y += $dy;
                    }
                }

                if ($dx !== 0) {
                    foreach ($ch->children as $grandchild) {
                        $grandchild->x += $dx;
                    }
                }

                // Advance main position
                // Use visual box (visualW/visualH) which accounts for padding+border
                // in content-box mode. In border-box mode, visualW/H == w/h.
                // CSS §4.2: the item's box extent is w/h + padding + border for
                // content-box; the next item's position starts at this outer edge.
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



                if ($needsTwoPass && (count($chTp->children) > 0 || $chTp->content !== null)) {
                    $display = (string)($chTp->getStyleArray()['display'] ?? 'block');

                    if ($display === 'flex' || $display === 'grid') {
                        // Full re-layout for flex/grid containers.
                        $leftOff = $chTp->getStyleArray()['left'] ?? 0;
                        $topOff = $chTp->getStyleArray()['top'] ?? 0;
                        $prX = $chTp->x - $leftOff;
                        $prY = $chTp->y - $topOff;

                        $hasOrigW = array_key_exists('width', $chTp->getStyleArray());
                        $hasOrigH = array_key_exists('height', $chTp->getStyleArray());
                        $origW = $chTp->getStyleArray()['width'] ?? null;
                        $origH = $chTp->getStyleArray()['height'] ?? null;

                        $chTp->getStyleArray()['width'] = $chTp->w;
                        $chTp->getStyleArray()['height'] = $chTp->h;

                        $chTp->layoutDirty = true;

                        foreach ($chTp->children as $gc) {
                            $gc->layoutDirty = true;
                        }

                        $this->resolver->resolveChildNode($chTp, $prX, $prY, $node->parent);

                        $chTp->visualW = CssStyleHelper::visualWidth($chTp->getStyleArray(), $chTp->w);
                        $chTp->visualH = CssStyleHelper::visualHeight($chTp->getStyleArray(), $chTp->h);

                        if ($hasOrigW) {
                            $chTp->getStyleArray()['width'] = $origW;
                        } else {
                            unset($chTp->getStyleArray()['width']);
                        }

                        if ($hasOrigH) {
                            $chTp->getStyleArray()['height'] = $origH;
                        } else {
                            unset($chTp->getStyleArray()['height']);
                        }
                    } else {
                        // Block/scroll containers: full re-resolve so auto-stack
                        // re-positions children with the corrected parent width.
                        // CSS 2.2 §10.6.3: block children must be re-laid-out when
                        // containing block width changes (e.g. via flex-grow).
                        $leftOff = $chTp->getStyleArray()['left'] ?? 0;
                        $topOff = $chTp->getStyleArray()['top'] ?? 0;
                        $prX = $chTp->x - $leftOff;
                        $prY = $chTp->y - $topOff;

                        error_log('[DIAG_BLK2P] chTp=' . $chTp->type . ' w=' . $chTp->w . ' ctx_parent=' . ($node->parent !== null ? ('type=' . $node->parent->type . ' w=' . $node->parent->w) : 'null'));

                        $hasOrigW = array_key_exists('width', $chTp->getStyleArray());
                        $hasOrigH = array_key_exists('height', $chTp->getStyleArray());
                        $origW = $chTp->getStyleArray()['width'] ?? null;
                        $origH = $chTp->getStyleArray()['height'] ?? null;

                        $chTp->getStyleArray()['width'] = $chTp->w;
                        // Block-level flex items without explicit height:
                        // don't lock the stretched height �?let auto-height compute
                        // from re-laid-out children after width change (CSS §9.5).
                        if ($hasOrigH) {
                            $chTp->getStyleArray()['height'] = $chTp->h;
                        } else {
                            unset($chTp->getStyleArray()['height']);
                            // Reset node height so BlockLayoutStrategy's auto-height
                            // triggers (line 97: $height>0 || $node->h===0). Without this,
                            // the stretched height (244) persists and prevents recompute.
                            // Cross-axis stretch is re-applied after two-pass below,
                            // but main-axis size is NOT restored. Scroll containers
                            // rely on parent flex layout for height �?skip reset.
                            if (!$chTp->isScrollContainer) {
                                $chTp->h = 0;
                                $chTp->visualH = 0;
                            }
                        }

                        // Re-evaluate auto-margin: the first pass may have applied
                        // margin:auto with an incorrect parent width (before flex-grow).
                        // Clear computed margin style and restore flags so the
                        // re-layout (with correct post-grow width) recalculates them.
                        $chOrigML = $chTp->getStyleArray()['marginLeftAuto'] ?? false;
                        $chOrigMR = $chTp->getStyleArray()['marginRightAuto'] ?? false;
                        if ($chOrigML || $chOrigMR) {
                            // Clear first-pass computed margin values; re-layout will set correct ones
                            unset($chTp->getStyleArray()['marginLeft'], $chTp->getStyleArray()['marginRight']);
                            $chTp->getStyleArray()['marginLeftAuto'] = $chOrigML;
                            $chTp->getStyleArray()['marginRightAuto'] = $chOrigMR;
                        }
                        // Also fix descendant auto-margins: first pass may have computed
                        // margins with wrong parent width. Clear stale offset so
                        // re-layout (with correct post-grow width) recalculates correctly.
                        $stack = [$chTp];
                        while (!empty($stack)) {
                            $cur = array_pop($stack);
                            foreach ($cur->children as $gc) {
                                $gcML = $gc->getStyleArray()['marginLeftAuto'] ?? false;
                                $gcMR = $gc->getStyleArray()['marginRightAuto'] ?? false;
                                if ($gcML || $gcMR) {
                                    // Clear stale computed margin values
                                    unset($gc->getStyleArray()['marginLeft'], $gc->getStyleArray()['marginRight']);
                                    // Clear previous offset so undo doesn't subtract wrong value
                                    unset($gc->getStyleArray()['_marginAutoOffsetX']);
                                }
                                $stack[] = $gc;
                            }
                        }

                        $chTp->layoutDirty = true;

                        foreach ($chTp->children as $gc) {
                            $gc->layoutDirty = true;
                        }

                        // Clear defer marker: two-pass has correct final width,
                        // apply auto-margin now (browser-equivalent: one calculation)

                        $this->resolver->resolveChildNode($chTp, $prX, $prY, $node->parent);

                        $chTp->visualW = CssStyleHelper::visualWidth($chTp->getStyleArray(), $chTp->w);
                        // Only override visualH for items with explicit height.
                        // Auto-height items have correct visualH from resolveNode's
                        // $isAutoHeight logic (adds padding+border to content-h).
                        // Re-computing with resolveVisualH in border-box mode would
                        // treat content-h as total-h, producing incorrect small height.
                        if ($hasOrigH) {
                            $chTp->visualH = CssStyleHelper::visualHeight($chTp->getStyleArray(), $chTp->h);
                        }

                        if ($hasOrigW) {
                            $chTp->getStyleArray()['width'] = $origW;
                        } else {
                            unset($chTp->getStyleArray()['width']);
                        }

                        if ($hasOrigH) {
                            $chTp->getStyleArray()['height'] = $origH;
                        } else {
                            unset($chTp->getStyleArray()['height']);
                        }
                    }
                }

                if ($needsTwoPass && $chTp->isScrollContainer) {
                    $padTsp = (int)($chTp->getStyleArray()['paddingTop'] ?? $chTp->getStyleArray()['padding'] ?? 0);
                    $padLsp = (int)($chTp->getStyleArray()['paddingLeft'] ?? $chTp->getStyleArray()['padding'] ?? 0);
                    $padRsp = (int)($chTp->getStyleArray()['paddingRight'] ?? $chTp->getStyleArray()['padding'] ?? 0);
                    $padBsp = (int)($chTp->getStyleArray()['paddingBottom'] ?? $chTp->getStyleArray()['padding'] ?? 0);
                    // A1 重构: childOffsetY 不再减去 scrollTop，偏移由 VNodeRenderer 在绘制层处理
                    $coffY = $chTp->y + $padTsp;

                    $this->resolver->getBlockStrategy()->finalizeScrollContainer($chTp, 0, 0, $chTp->computedStyle, $coffY, $padLsp, $padRsp, $padBsp);
                }
            }

            // ── Re-apply cross-axis stretch after two-pass ──
            // Only for non-wrapping flex containers: cross-axis size is determined
            // by the parent container (e.g., scroll containers filling remaining space).
            // For wrapping containers, the cross-axis is determined by content height
            // and the two-pass already computed the correct auto-height.
            if ($align === 'stretch' && !$isWrapping) {
                $crossTarget = $containerCross;
                foreach ($lineChildren as $ci => $ch) {
                    $childDisplay = $ch->getStyleArray()['display'] ?? 'block';
                    if ($childDisplay === 'none') continue;
                    $hasExplicitCross = $isRow
                        ? array_key_exists('height', $ch->getStyleArray())
                        : array_key_exists('width', $ch->getStyleArray());
                    if ($hasExplicitCross) continue;
                    $childMarginT = (int)($ch->getStyleArray()['marginTop'] ?? $ch->getStyleArray()['margin'] ?? 0);
                    $childMarginB = (int)($ch->getStyleArray()['marginBottom'] ?? $ch->getStyleArray()['margin'] ?? 0);
                    $childMarginL = (int)($ch->getStyleArray()['marginLeft'] ?? $ch->getStyleArray()['margin'] ?? 0);
                    $childMarginR = (int)($ch->getStyleArray()['marginRight'] ?? $ch->getStyleArray()['margin'] ?? 0);
                    if ($isRow) {
                        $stretched = (int)max(0, $crossTarget - $childMarginT - $childMarginB);
                        // CSS 2.2 §10.7: stretch 时也要受 min/max-height 约束
                        if ($stretched > $ch->h && $stretched > 0) {
                            $ch->h = $stretched;
                            $ch->h = (int)max(0, (int)CssStyleHelper::applyMinMax($ch->getStyleArray(), $ch->h, false));
                            $ch->visualH = CssStyleHelper::visualHeight($ch->getStyleArray(), $ch->h);
                        }
                    } else {
                        $stretched = (int)max(0, $crossTarget - $childMarginL - $childMarginR);
                        // CSS 2.2 §10.7: stretch 时也要受 min/max-width 约束
                        if ($stretched > 0 && $stretched !== $ch->w) {
                            $ch->w = $stretched;
                            $ch->w = (int)max(0, (int)CssStyleHelper::applyMinMax($ch->getStyleArray(), $ch->w, true));
                            $ch->visualW = CssStyleHelper::visualWidth($ch->getStyleArray(), $ch->w);
                        }
                    }
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
                            $chStyle = $ch->getStyleArray();
                            $hasExplicitCrossSize = $isRow
                                ? array_key_exists('height', $chStyle)
                                : array_key_exists('width', $chStyle);
                            if (!$hasExplicitCrossSize) {
                                if ($isRow) {
                                    $ch->h = $stretchedCross;
                                    $ch->visualH = CssStyleHelper::visualHeight($chStyle, $ch->h);
                                } else {
                                    $ch->w = $stretchedCross;
                                    $ch->visualW = CssStyleHelper::visualWidth($chStyle, $ch->w);
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
                                $gc->y += $dyShift;
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
            // For flex-wrap:wrap, do NOT expand width from children �?items wrap,
            // container width stays constrained by parent (CSS §9.5).

            if (!$hasExplicitW && !$hasWPct && $wrap !== 'wrap') {
                $maxRight = $node->x + $paddingLeft;

                foreach ($children as $ch) {
                    $chRight = $ch->x + $ch->visualW;

                    $mR = (int)($ch->getStyleArray()['marginRight'] ?? $ch->getStyleArray()['margin'] ?? 0);

                    if ($chRight + $mR > $maxRight) $maxRight = (int)($chRight + $mR);
                }

                $node->w = (int)max($node->w, $maxRight - $node->x + $paddingRight);
            }

            if (!$hasExplicitH && !$hasHPct) {
                $maxBottom = $node->y + $paddingTop;

                foreach ($children as $ch) {
                    $chBottom = $ch->y + $ch->visualH;

                    $mB = (int)($ch->getStyleArray()['marginBottom'] ?? $ch->getStyleArray()['margin'] ?? 0);

                    if ($chBottom + $mB > $maxBottom) $maxBottom = (int)($chBottom + $mB);
                }

                $node->h = (int)max(0, $maxBottom - $node->y + $paddingBottom);
            }
        } else {
            // Cross-axis: auto-width from children

            if (!$hasExplicitW && !$hasWPct) {
                $maxRight = $node->x + $paddingLeft;

                foreach ($children as $ch) {
                    $chRight = $ch->x + $ch->visualW;

                    $mR = (int)($ch->getStyleArray()['marginRight'] ?? $ch->getStyleArray()['margin'] ?? 0);

                    if ($chRight + $mR > $maxRight) $maxRight = (int)($chRight + $mR);
                }

                $node->w = (int)max($node->w, $maxRight - $node->x + $paddingRight);
            }

            // Main-axis: auto-height from children
            // For flex-wrap:wrap, do NOT expand height from children �?items wrap,
            // container height stays constrained by parent (CSS §9.5).

            if (!$hasExplicitH && !$hasHPct && $wrap !== 'wrap') {
                $maxBottom = $node->y + $paddingTop;

                foreach ($children as $ch) {
                    $chBottom = $ch->y + $ch->visualH;

                    $mB = (int)($ch->getStyleArray()['marginBottom'] ?? $ch->getStyleArray()['margin'] ?? 0);

                    if ($chBottom + $mB > $maxBottom) $maxBottom = (int)($chBottom + $mB);
                }

                $node->h = (int)max(0, $maxBottom - $node->y + $paddingBottom);
            }
        }

        // ── Second pass: resolve absolute/fixed children now that container dimensions are final ──
        foreach ($absoluteChildren as $child) {
            $this->resolver->resolveChildNode($child, $node->x + $paddingLeft, $node->y + $paddingTop, $node);
        }



        // Set container's own visualW/visualH
        $node->visualW = CssStyleHelper::visualWidth($style, $node->w);
        $node->visualH = CssStyleHelper::visualHeight($style, $node->h);

        // 输出到 builder，确保 applyTo 不覆盖 flex 正确计算的容器值
        if ($builder !== null) {
            $builder
                ->setPosition($node->x, $node->y)
                ->setSize($node->w, $node->h, $computedStyle)
                ->setLayer($node->layer)
                ->setContentSize($node->contentWidth, $node->contentHeight);
        }
    }


    /**
     * Apply flex-basis to children in a flex line.
     */
    private function applyFlexBasis(array $children, array $flexItemData, bool $isRow, array $parentStyle = [], int $containerContentW = 0): void
    {
        foreach ($children as $idx => $ch) {
            $data = $flexItemData[$idx];
            $basis = $data['basis'];

            // ── Percentage flex-basis: 检查原始 CSS 值是否含 % ──
            $rawBasis = $ch->getStyleArray()['flexBasis'] ?? $data['basis'] ?? '';
            $isPercent = is_string($rawBasis) && str_ends_with($rawBasis, '%');

            // ── Numeric basis (flex-basis: <length>|<percentage>) ──
            if (is_int($basis) && $basis >= 0) {
                $resolvedBasis = $basis;
                if ($isPercent && $containerContentW > 0) {
                    // 解析百分比：flex-basis:30% → 30% * containerContentW
                    $pct = (int)$rawBasis;
                    $resolvedBasis = (int)($containerContentW * $pct / 100);
                }
                if ($resolvedBasis > 0) {
                    if ($isRow) {
                        $ch->w = (int)max(0, $resolvedBasis);
                        $ch->visualW = CssStyleHelper::visualWidth($ch->getStyleArray(), $ch->w);
                    } else {
                        $ch->h = (int)max(0, $resolvedBasis);
                        $ch->visualH = CssStyleHelper::visualHeight($ch->getStyleArray(), $ch->h);
                    }
                }
            // ── flex-basis: content —ignore width/height, always use content size ──
            } elseif ($basis === 'content') {
                $chText = $ch->content ?? '';
                if (is_string($chText) && strlen($chText) > 0) {
                    $chStyle = $ch->getStyleArray();
                    CssStyleHelper::resolveFontSize($chStyle);
                    $fs = (int)($chStyle['fontSize'] ?? 14);
                    $bd = ($chStyle['bold'] ?? 0) !== 0;
                    $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($chText, $fs, $bd) : 0);
                    if ($measured > 0) {
                        if ($isRow) {
                            $ch->w = $measured;
                        } else {
                            $lineH = CssStyleHelper::lineHeight($ch->getStyleArray(), $fs, 16, $parentStyle);
                            if ($ch->h === 0 || $ch->h < $lineH) {
                                $ch->h = $lineH;
                            }
                        }
                    }
                }
            // ── flex-basis: auto (default) —use width/height if set, else content ──
            } else {
                $flexBasis = $ch->getStyleArray()['flexBasis'] ?? 'auto';

                if ($flexBasis !== 'auto') {
                    $basisVal = (int)$flexBasis;

                    if ($basisVal > 0) {
                        if ($isRow) {
                            $ch->w = (int)max(0, $basisVal);
                            $ch->visualW = CssStyleHelper::visualWidth($ch->getStyleArray(), $ch->w);
                        } else {
                            $ch->h = (int)max(0, $basisVal);
                            $ch->visualH = CssStyleHelper::visualHeight($ch->getStyleArray(), $ch->h);
                        }
                    }
                }

                // -- Text measurement for flex-basis:auto (basis=-1 or 'auto') --

                $chText = $ch->content ?? '';

                if ((is_string($chText) && strlen($chText) > 0)) {
                    $chStyle = $ch->getStyleArray();
                    CssStyleHelper::resolveFontSize($chStyle);
                    $fs = (int)($chStyle['fontSize'] ?? 14);

                    $bd = ($chStyle['bold'] ?? 0) !== 0;

                    $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($chText, $fs, $bd) : 0);

                    if ($measured > 0) {
                        if ($isRow) {
                            // Row: text width = measured content width
                            // Content-sized child (no explicit width, no flex-grow): always use text-measured
                            $hasFlexW = array_key_exists('width', $ch->getStyleArray());
                            if (!$hasFlexW && !$data['isFlexGrow']) {
                                $ch->w = $measured;
                            } elseif ($ch->w === 0 || $ch->w < $measured) {
                                $ch->w = $measured;
                            }
                        } else {
                            // Column: text height = line-height (based on font size)
                            // CSS 2.2 §10.8.1: �?flex 容器继承 line-height
                            $lineH = CssStyleHelper::lineHeight($ch->getStyleArray(), $fs, 16, $parentStyle);

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
                        $hasFlexW = array_key_exists('width', $ch->getStyleArray());
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
            $nodeStyle = $node->getStyleArray();
            CssStyleHelper::resolveFontSize($nodeStyle);
            $fs = (int)($nodeStyle['fontSize'] ?? 14);
            $bd = ($nodeStyle['bold'] ?? 0) !== 0;
            return (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($text, $fs, $bd) : 0);
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

    /**
     * Resolve the minimum main-axis size for a flex item during shrink.
     *
     * CSS Flexbox §4.5: flex items have min-width/min-height: auto by default,
     * meaning the minimum size is the content-based size (auto keyword).
     *
     * - overflow:visible (default) �?min = content-based size (current computed size)
     * - overflow:auto/scroll/hidden �?min = 0 (enables clipping/scroll containment)
     * - explicit min-width/min-height set �?use that value
     *
     * @param RenderNode $ch The flex child node
     * @param bool $isRow Whether main axis is row (horizontal)
     * @param int $currentMainSize The item's current main-axis size before shrink
     * @return int Minimum main-axis size in pixels
     */
    private static function resolveFlexMinMain(RenderNode $ch, bool $isRow, int $currentMainSize): int
    {
        // 1) Explicit min-width/min-height takes priority
        if ($isRow) {
            if (isset($ch->getStyleArray()['minWidth'])) {
                return (int)$ch->getStyleArray()['minWidth'];
            }
        } else {
            if (isset($ch->getStyleArray()['minHeight'])) {
                return (int)$ch->getStyleArray()['minHeight'];
            }
        }

        // 2) Check overflow in the main axis
        // Order: overflowX/overflowY overrides, fallback to 'overflow' shorthand
        $ov = 'visible';
        if ($isRow) {
            if (isset($ch->getStyleArray()['overflowX'])) {
                $ov = $ch->getStyleArray()['overflowX'];
            } elseif (isset($ch->getStyleArray()['overflow'])) {
                $ov = $ch->getStyleArray()['overflow'];
            }
        } else {
            if (isset($ch->getStyleArray()['overflowY'])) {
                $ov = $ch->getStyleArray()['overflowY'];
            } elseif (isset($ch->getStyleArray()['overflow'])) {
                $ov = $ch->getStyleArray()['overflow'];
            }
        }

        // overflow:auto/scroll/hidden �?min is 0 (content can be clipped/scrolled)
        if ($ov !== 'visible') {
            return 0;
        }

        // 3) overflow:visible (default) �?CSS min-height:auto →content-based minimum
        // The content-based min is approximated by the current computed main-size
        // from initial layout resolution (before flex shrink).
        return $currentMainSize;
    }
}

