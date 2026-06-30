<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Core\Config;
use Px\Rendering\ComputedStyle;
use Px\Rendering\CssMappings;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\CssStyleHelper;
use Px\Rendering\Layout\Flex\FlexItemCollector;
use Px\Rendering\Layout\Flex\FlexLineBreaker;
use Px\Rendering\Layout\Flex\FlexDistributor;
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
    private ?FlexItemCollector $collector = null;
    private ?FlexDistributor $distributor = null;

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
        $style = $computedStyle !== null ? $computedStyle->toExportArray() : [];

        $left = (int)($style['left'] ?? 0);

        $top = (int)($style['top'] ?? 0);

        $width = (int)($style['width'] ?? 0);

        $height = (int)($style['height'] ?? 0);

        // CSS 2.2 §10.3.7: margins apply to flex containers as block-level elements
        $cbWidth = $node->parent?->computedStyle?->contentBoxWidth($node->parent->w) ?? $node->parent?->w ?? 0;
        $marginLeft = CssStyleHelper::resolveLength($style, 'marginLeft', $cbWidth);
        $marginTop = CssStyleHelper::resolveLength($style, 'marginTop', $cbWidth);

        $node->x = $left + $parentX + $marginLeft;

        $node->y = $top + $parentY + $marginTop;

        // Apply translate from animatedStyle

        $translateX = (int)($style['translateX'] ?? 0);

        $translateY = (int)($style['translateY'] ?? 0);

        $node->x += $translateX;

        $node->y += $translateY;

        // CSS: flex item percentage width resolves against content width
        // When parent is null (top-level element under #root), use window viewport as containing block
        $parentW = (int)(($node->parent?->computedStyle?->contentBoxWidth($node->parent->w) ?? $node->parent?->w) ?: (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0));

        $parentH = $node->parent?->h ?? (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0);

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
                ? ($node->parent?->computedStyle?->contentBoxWidth($node->parent->w) ?? $node->parent?->w)
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

        $parentDisplay = $node->parent?->computedStyle?->display?->value ?? '';

        $isFlexOrGridItem = ($parentDisplay === 'flex' || $parentDisplay === 'grid');

        if (!$isFlexOrGridItem) {
            $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);

            if (!$hasExplicitW && $width === 0 && $node->parent !== null) {
                $width = (int)($node->parent->computedStyle?->contentBoxWidth($node->parent->w) ?? $node->parent->w);

                $node->w = (int)max(0, (int)$width);
            }
        } elseif ($node->parent !== null && $parentDisplay === 'flex') {
            // ── Scroll container post-processing for flex/grid display modes ──

            // cross-axis (width) size for correct first-pass internal layout.

            // Without this, flex items with display:flex/grid get width=0, causing
            // their internal grid to compute 1 column with inflated height, which
            // then triggers flex-shrink and damages sibling items' explicit sizes.

            $parentDirection = $node->parent->computedStyle?->flexDirection?->value ?? 'row';

            $parentIsColumn = ($parentDirection === 'column' || $parentDirection === 'column-reverse');

            $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);

            if ($parentIsColumn && !$hasExplicitW && $width === 0) {
                $parentContentW = (int)($node->parent->computedStyle?->contentBoxWidth($node->parent->w) ?? $node->parent->w);

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

        // ── Step 1-3: 使用 FlexItemCollector ──
        if ($this->collector === null) {
            $this->collector = new FlexItemCollector($this->resolver);
        }
        $collected = $this->collector->collect($node, $parentX, $parentY, $style);
        $children = $collected['children'];
        $absoluteChildren = $collected['absoluteChildren'];
        $flexItemData = $collected['flexItemData'];

        if (count($children) === 0) {
            if ($node->isScrollContainer) {
                $node->contentHeight = 0;
            }
            return;
        }

        // ── Step 3.5: 使用 FlexLineBreaker 分割行 ──
        $isWrapping = ($wrap === 'wrap');
        $breakResult = FlexLineBreaker::breakLines(
            $children, $flexItemData, $isWrapping, $isRow,
            $containerMain, $gap
        );
        $lines = $breakResult[0];
        $linesFlexData = $breakResult[1];

        // ── Per-line flex layout via FlexDistributor ──

        if ($this->distributor === null) {
            $this->distributor = new FlexDistributor($this->resolver);
        }

        $layoutParams = [
            'isRow' => $isRow,
            'isWrapping' => $isWrapping,
            'reversed' => $reversed,
            'gap' => $gap,
            'containerMain' => $containerMain,
            'containerCross' => $containerCross,
            'node' => $node,
            'paddingLeft' => $paddingLeft,
            'paddingTop' => $paddingTop,
            'justify' => $justify,
            'align' => $align,
            'containerContentW' => CssStyleHelper::contentBoxWidth($style, $node->w),
            'parentNode' => $node->parent,
        ];

        $accumulatedCrossOffset = 0;
        $lineCrossData = [];

        foreach ($lines as $lineChildren) {
            $lineCount = count($lineChildren);
            if ($lineCount === 0) continue;

            // Build line flex data from computedStyle
            $lineFlexData = [];
            foreach ($lineChildren as $ch) {
                $chCS = $ch->computedStyle;
                $flex = $chCS?->flex;
                $g = 0.0; $s = 1.0; $b = -1;
                if ($flex !== null) {
                    $g = $flex->grow;
                    $s = $flex->shrink;
                    if (!$flex->basis->isAuto()) $b = $flex->basis->toPx();
                }
                if ($g <= 0) {
                    $rawG = $chCS?->getRaw('flexGrow');
                    if (is_numeric($rawG)) $g = (float)$rawG;
                }
                if ($s >= 1.0) {
                    $rawS = $chCS?->getRaw('flexShrink');
                    if (is_numeric($rawS)) $s = (float)$rawS;
                }
                if ($b === -1) {
                    $bv = $chCS?->flexBasis;
                    if ($bv !== null && !$bv->isAuto()) $b = $bv->toPx();
                }
                $lineFlexData[] = [
                    'grow' => $g, 'shrink' => $s, 'basis' => $b,
                    'isFlexGrow' => ($g > 0),
                    'hasExplicitCrossSize' => $chCS?->getRaw($isRow ? 'height' : 'width') !== null,
                    'crossAxisSized' => false,
                ];
            }

            $this->distributor->distributeLine(
                $lineChildren, $lineFlexData, $style, $layoutParams, $accumulatedCrossOffset
            );

            // Calculate line maxCross after distribution
            $lineMaxCross = 0;
            foreach ($lineChildren as $ch) {
                $cross = $isRow ? $ch->visualH : $ch->visualW;
                if ($cross > $lineMaxCross) $lineMaxCross = $cross;
            }

            $lineCrossBase = $accumulatedCrossOffset;
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
                            $chStyle = $ch->computedStyle?->toExportArray() ?? [];
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

                    $mR = (int)($ch->computedStyle?->toExportArray() ?? []['marginRight'] ?? $ch->computedStyle?->toExportArray() ?? []['margin'] ?? 0);

                    if ($chRight + $mR > $maxRight) $maxRight = (int)($chRight + $mR);
                }

                $node->w = (int)max($node->w, $maxRight - $node->x + $paddingRight);
            }

            if (!$hasExplicitH && !$hasHPct) {
                $maxBottom = $node->y + $paddingTop;

                foreach ($children as $ch) {
                    $chBottom = $ch->y + $ch->visualH;

                    $mB = (int)($ch->computedStyle?->toExportArray() ?? []['marginBottom'] ?? $ch->computedStyle?->toExportArray() ?? []['margin'] ?? 0);

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

                    $mR = (int)($ch->computedStyle?->toExportArray() ?? []['marginRight'] ?? $ch->computedStyle?->toExportArray() ?? []['margin'] ?? 0);

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

                    $mB = (int)($ch->computedStyle?->toExportArray() ?? []['marginBottom'] ?? $ch->computedStyle?->toExportArray() ?? []['margin'] ?? 0);

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
            $rawBasis = $ch->computedStyle?->toExportArray() ?? []['flexBasis'] ?? $data['basis'] ?? '';
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
                        $ch->visualW = CssStyleHelper::visualWidth($ch->computedStyle?->toExportArray() ?? [], $ch->w);
                    } else {
                        $ch->h = (int)max(0, $resolvedBasis);
                        $ch->visualH = CssStyleHelper::visualHeight($ch->computedStyle?->toExportArray() ?? [], $ch->h);
                    }
                }
            // ── flex-basis: content —ignore width/height, always use content size ──
            } elseif ($basis === 'content') {
                $chText = $ch->content ?? '';
                if (is_string($chText) && strlen($chText) > 0) {
                    $chStyle = $ch->computedStyle?->toExportArray() ?? [];
                    CssStyleHelper::resolveFontSize($chStyle);
                    $fs = (int)($chStyle['fontSize'] ?? 14);
                    $bd = ($chStyle['bold'] ?? 0) !== 0;
                    $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($chText, $fs, $bd) : 0);
                    if ($measured > 0) {
                        if ($isRow) {
                            $ch->w = $measured;
                        } else {
                            $lineH = CssStyleHelper::lineHeight($ch->computedStyle?->toExportArray() ?? [], $fs, 16, $parentStyle);
                            if ($ch->h === 0 || $ch->h < $lineH) {
                                $ch->h = $lineH;
                            }
                        }
                    }
                }
            // ── flex-basis: auto (default) —use width/height if set, else content ──
            } else {
                $flexBasis = $ch->computedStyle?->toExportArray() ?? []['flexBasis'] ?? 'auto';

                if ($flexBasis !== 'auto') {
                    $basisVal = (int)$flexBasis;

                    if ($basisVal > 0) {
                        if ($isRow) {
                            $ch->w = (int)max(0, $basisVal);
                            $chStyle = $ch->computedStyle?->toExportArray() ?? [];
                            $ch->visualW = CssStyleHelper::visualWidth($chStyle, $ch->w);
                        } else {
                            $ch->h = (int)max(0, $basisVal);
                            $ch->visualH = CssStyleHelper::visualHeight($ch->computedStyle?->toExportArray() ?? [], $ch->h);
                        }
                    }
                }

                // -- Text measurement for flex-basis:auto (basis=-1 or 'auto') --

                $chText = $ch->content ?? '';

                if ((is_string($chText) && strlen($chText) > 0)) {
                    $chStyle = $ch->computedStyle?->toExportArray() ?? [];
                    CssStyleHelper::resolveFontSize($chStyle);
                    $fs = (int)($chStyle['fontSize'] ?? 14);

                    $bd = ($chStyle['bold'] ?? 0) !== 0;

                    $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($chText, $fs, $bd) : 0);

                    if ($measured > 0) {
                        if ($isRow) {
                            // Row: text width = measured content width
                            // Content-sized child (no explicit width, no flex-grow): always use text-measured
                            $hasFlexW = array_key_exists('width', $ch->computedStyle?->toExportArray() ?? []);
                            if (!$hasFlexW && !$data['isFlexGrow']) {
                                $ch->w = $measured;
                            } elseif ($ch->w === 0 || $ch->w < $measured) {
                                $ch->w = $measured;
                            }
                        } else {
                            // Column: text height = line-height (based on font size)
                            // CSS 2.2 §10.8.1: �?flex 容器继承 line-height
                            $lineH = CssStyleHelper::lineHeight($ch->computedStyle?->toExportArray() ?? [], $fs, 16, $parentStyle);

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
                        $hasFlexW = array_key_exists('width', $ch->computedStyle?->toExportArray() ?? []);
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
            $fs = $node->computedStyle?->fontSize ?? 14;
            $bd = $node->computedStyle?->bold ?? false;
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
        $chArr = $ch->computedStyle?->toExportArray() ?? [];
        if ($isRow) {
            if (isset($chArr['minWidth'])) {
                return (int)$chArr['minWidth'];
            }
        } else {
            if (isset($chArr['minHeight'])) {
                return (int)$chArr['minHeight'];
            }
        }

        // 2) Check overflow in the main axis
        // Order: overflowX/overflowY overrides, fallback to 'overflow' shorthand
        $ov = 'visible';
        if ($isRow) {
            if (isset($chArr['overflowX'])) {
                $ov = $chArr['overflowX'];
            } elseif (isset($chArr['overflow'])) {
                $ov = $chArr['overflow'];
            }
        } else {
            if (isset($chArr['overflowY'])) {
                $ov = $chArr['overflowY'];
            } elseif (isset($chArr['overflow'])) {
                $ov = $chArr['overflow'];
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

