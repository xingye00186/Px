<?php

namespace Px\Rendering\Layout\Flex;

use native_types;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\ComputedStyle;

/**
 * FlexDistributor — Flex 分布器
 *
 * 处理 flex-grow/shrink 分配、交叉轴尺寸决定、主轴/交叉轴定位、
 * justify-content/align-items/align-content 分布，以及两阶段重布局。
 *
 * 本类持有跨帧状态（marginAutoOffsetsX）用于 auto-margin 追踪。
 */
class FlexDistributor
{
    private LayoutResolver $resolver;

    /** @var array<string, int> Auto margin X offset tracking */
    private array $marginAutoOffsetsX = [];

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * 对单行执行 flex 分布计算。
     *
     * @param RenderNode[]  $lineChildren    该行子节点
     * @param array         $lineFlexData    该行 flex 元数据
     * @param array         $style           容器样式数组
     * @param array         $layoutParams    布局参数 [isRow, isWrapping, reversed, gap, containerMain, containerCross, node, paddingLeft, paddingTop, justify, align, containerContentW, parentNode]
     * @param int           $lineCrossBase   该行交叉轴基础偏移
     * @return array 更新后的 lineCrossData 项 ['children'=>..., 'maxCross'=>int, 'crossBase'=>int]
     */
    public function distributeLine(
        array $lineChildren,
        array $lineFlexData,
        array $style,
        array $layoutParams,
        int $lineCrossBase
    ): array {
        $isRow = $layoutParams['isRow'];
        $isWrapping = $layoutParams['isWrapping'];
        $reversed = $layoutParams['reversed'];
        $gap = $layoutParams['gap'];
        $containerMain = $layoutParams['containerMain'];
        $containerCross = $layoutParams['containerCross'];
        $node = $layoutParams['node'];
        $paddingLeft = $layoutParams['paddingLeft'];
        $paddingTop = $layoutParams['paddingTop'];
        $justify = $layoutParams['justify'];
        $align = $layoutParams['align'];
        $containerContentW = $layoutParams['containerContentW'];

        $lineCount = count($lineChildren);
        if ($lineCount === 0) {
            return ['children' => [], 'maxCross' => 0, 'crossBase' => $lineCrossBase];
        }

        // Step 4: Apply flex-basis (delegated to existing helper)
        $this->applyFlexBasis($lineChildren, $lineFlexData, $isRow, $style, $containerContentW);

        // Step 5: Flex-grow
        $lineHasFlexGrow = false;
        foreach ($lineFlexData as $d) {
            if ($d['isFlexGrow']) { $lineHasFlexGrow = true; break; }
        }

        if ($lineHasFlexGrow) {
            $this->applyFlexGrow($lineChildren, $lineFlexData, $isRow, $gap, $containerMain, $style);
        }

        // Step 6: Calculate line totals
        $result = $this->calcLineTotals($lineChildren, $lineFlexData, $isRow, $gap);
        $lineTotalMain = $result['totalMain'];
        $lineMaxCross = $result['maxCross'];

        // Step 7: Flex-shrink
        if ($containerMain > 0 && $lineTotalMain > $containerMain) {
            $this->applyFlexShrink($lineChildren, $lineFlexData, $isRow, $containerMain, $lineTotalMain);
            // Recalculate after shrink
            $result = $this->calcLineTotals($lineChildren, $lineFlexData, $isRow, $gap);
            $lineTotalMain = $result['totalMain'];
            $lineMaxCross = $result['maxCross'];
        }

        // Step 8: Min/max constraints
        $this->applyMinMax($lineChildren, $isRow);

        // Recalc after constraints
        $result = $this->calcLineTotals($lineChildren, $lineFlexData, $isRow, $gap);
        $lineTotalMain = $result['totalMain'];
        $lineMaxCross = $result['maxCross'];

        // Auto margins
        $resolvedAutoMargins = $this->resolveAutoMargins($lineChildren, $lineFlexData, $isRow, $containerMain, $lineTotalMain, $gap, $lineCount);

        // Step 10-11: Justify-content + positioning
        $this->applyPositioning(
            $lineChildren, $lineFlexData, $isRow, $isWrapping, $reversed,
            $justify, $align, $node, $paddingLeft, $paddingTop,
            $containerMain, $containerCross, $lineTotalMain, $lineMaxCross,
            $lineCrossBase, $gap, $resolvedAutoMargins
        );

        // Two-pass re-layout
        $this->twoPassReLayout($lineChildren, $lineFlexData, $node);

        return [
            'children' => $lineChildren,
            'maxCross' => $lineMaxCross,
            'crossBase' => $lineCrossBase,
        ];
    }

    // ── Step 4: Flex-basis apply ──
    private function applyFlexBasis(array $children, array &$flexData, bool $isRow, array $style, int $containerContentW): void
    {
        // Mirror of existing FlexLayoutStrategy::applyFlexBasis logic
        foreach ($children as $idx => $ch) {
            $data = $flexData[$idx];
            $basisVal = $data['basis'];
            if ($basisVal > 0) {
                if ($isRow) {
                    $ch->w = (int)max(0, $basisVal);
                    $ch->visualW = $this->visualWidth($ch, $ch->w);
                } else {
                    $ch->h = (int)max(0, $basisVal);
                    $ch->visualH = $this->visualHeight($ch, $ch->h);
                }
            }
            // Text measurement for flex-basis:auto
            $chText = $ch->content ?? '';
            if (is_string($chText) && strlen($chText) > 0) {
                $fs = $ch->computedStyle?->fontSize ?? 14;
                $bd = $ch->computedStyle?->bold ?? false;
                $measured = function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($chText, $fs, $bd) : 0;
                if ($measured > 0) {
                    if ($isRow) {
                        $hasFlexW = $ch->computedStyle?->getRaw('width') !== null;
                        if (!$hasFlexW && !$data['isFlexGrow']) {
                            $ch->w = $measured;
                        } elseif ($ch->w === 0 || $ch->w < $measured) {
                            $ch->w = $measured;
                        }
                    } else {
                        $lineH = $this->resolveLineHeight($ch, $fs);
                        if ($ch->h === 0 || $ch->h < $lineH) {
                            $ch->h = $lineH;
                        }
                    }
                }
            } else {
                $descW = $this->maxDescendantTextWidth($ch);
                if ($descW > 0) {
                    $hasFlexW = $ch->computedStyle?->getRaw('width') !== null;
                    if (!$hasFlexW && !$data['isFlexGrow']) {
                        $ch->w = $descW;
                    } elseif ($ch->w === 0 || $ch->w < $descW) {
                        $ch->w = $descW;
                    }
                }
            }
        }
    }

    // ── Step 5: Flex-grow ──
    private function applyFlexGrow(array $children, array &$flexData, bool $isRow, int $gap, int $containerMain, array $style): void
    {
        // [DIST_GROW] grow allocation
        $lineCount = count($children);
        $fixedTotalMain = 0;
        foreach ($children as $idx => $ch) {
            $data = $flexData[$idx];
            $chCS = $ch->computedStyle;
            $mL = $chCS?->margin?->left?->toPx() ?? 0;
            $mR = $chCS?->margin?->right?->toPx() ?? 0;
            $mT = $chCS?->margin?->top?->toPx() ?? 0;
            $mB = $chCS?->margin?->bottom?->toPx() ?? 0;
            if ($data['isFlexGrow']) {
                $fixedTotalMain += $isRow ? $mL + $mR : $mT + $mB;
            } else {
                $sz = $isRow ? $ch->w : $ch->h;
                $fixedTotalMain += $sz + ($isRow ? $mL + $mR : $mT + $mB);
            }
        }
        $gapTotal = $gap * ($lineCount - 1);
        $remainingSpace = max($containerMain - $fixedTotalMain - $gapTotal, 0);
        $totalFlexGrow = 0;
        foreach ($flexData as $entry) { $totalFlexGrow += $entry['grow']; }
        $totalFlexGrow = (int)max($totalFlexGrow, 1);

        $growAllocations = [];
        $totalAllocated = 0;
        foreach ($children as $idx => $ch) {
            $data = $flexData[$idx];
            if ($data['isFlexGrow']) {
                $allocated = (int)(($data['grow'] / $totalFlexGrow) * $remainingSpace);
                $growAllocations[$idx] = max(0, $allocated);
                $totalAllocated += $allocated;
            }
        }

        // Remainder distribution (fractional fairness)
        $remainder = $remainingSpace - $totalAllocated;
        if ($remainder > 0) {
            $growIndices = [];
            foreach ($children as $idx => $ch) {
                $data = $flexData[$idx];
                if ($data['isFlexGrow']) {
                    $exact = ($data['grow'] / $totalFlexGrow) * $remainingSpace;
                    $growIndices[] = ['idx' => $idx, 'fraction' => $exact - (int)$exact];
                }
            }
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
                $growAllocations[$allocIdx] = ($growAllocations[$allocIdx] ?? 0) + 1;
            }
        }

        foreach ($growAllocations as $growIdx => $growSize) {
            $ch = $children[$growIdx];
            if ($isRow) {
                $ch->w = (int)max(0, $growSize);
                $ch->visualW = $this->visualWidth($ch, $ch->w);
            } else {
                $ch->h = (int)max(0, $growSize);
                $ch->visualH = $this->visualHeight($ch, $ch->h);
            }
        }
    }

    // ── Step 6: Calculate line totals ──
    private function calcLineTotals(array $children, array &$flexData, bool $isRow, int $gap): array
    {
        $totalMain = 0;
        $maxCross = 0;
        foreach ($children as $ch) {
            $chCS = $ch->computedStyle;
            $mL = $chCS?->margin?->left?->toPx() ?? 0;
            $mR = $chCS?->margin?->right?->toPx() ?? 0;
            $mT = $chCS?->margin?->top?->toPx() ?? 0;
            $mB = $chCS?->margin?->bottom?->toPx() ?? 0;
            if ($isRow) {
                $totalMain += $ch->w + $mL + $mR;
                $maxCross = (int)max($maxCross, $ch->visualH);
            } else {
                $totalMain += $ch->h + $mT + $mB;
                $maxCross = (int)max($maxCross, $ch->visualW);
            }
        }
        $totalMain += $gap * (count($children) - 1);
        return ['totalMain' => $totalMain, 'maxCross' => $maxCross];
    }

    // ── Step 7: Flex-shrink ──
    private function applyFlexShrink(array $children, array &$flexData, bool $isRow, int $containerMain, int $lineTotalMain): void
    {
        $remainingOverflow = $lineTotalMain - $containerMain;
        $shrinkSizes = [];
        $activeItems = [];
        foreach ($children as $idx => $ch) {
            $data = $flexData[$idx];
            if ($data['shrink'] > 0) {
                $mainSize = $isRow ? $ch->w : $ch->h;
                $shrinkSizes[$idx] = $mainSize;
                $shrinkBasis = $data['basis'] >= 0 ? (int)$data['basis'] : $mainSize;
                $activeItems[] = [
                    'idx' => $idx,
                    'shrinkWeight' => $shrinkBasis * $data['shrink'],
                    'minVal' => $this->resolveFlexMinMain($ch, $isRow, $mainSize),
                ];
            }
        }

        $hasWeight = false;
        foreach ($activeItems as $item) { if ($item['shrinkWeight'] > 0) { $hasWeight = true; break; } }

        if ($hasWeight) {
            while ($remainingOverflow > 0 && !empty($activeItems)) {
                $totalSw = 0;
                foreach ($activeItems as $item) { $totalSw += $item['shrinkWeight']; }
                if ($totalSw <= 0) break;
                $distributedInPass = 0;
                $newActive = [];
                foreach ($activeItems as $item) {
                    $idx = $item['idx'];
                    $currentSize = $shrinkSizes[$idx];
                    $reduction = (int)($remainingOverflow * $item['shrinkWeight'] / $totalSw);
                    $newSize = max(0, $currentSize - $reduction);
                    $clamped = false;
                    if ($item['minVal'] > 0 && $newSize < $item['minVal']) {
                        $newSize = (int)$item['minVal'];
                        $clamped = true;
                    }
                    $actualReduction = $currentSize - $newSize;
                    $distributedInPass += $actualReduction;
                    $shrinkSizes[$idx] = $newSize;
                    if (!$clamped) $newActive[] = $item;
                }
                $remainingOverflow -= $distributedInPass;
                if ($distributedInPass <= 0) break;
                $activeItems = $newActive;
            }
        } else {
            $equalShare = count($shrinkSizes) > 0 ? (int)($remainingOverflow / count($shrinkSizes)) : 0;
            foreach ($shrinkSizes as $idx => $size) {
                $newSize = max(0, $size - $equalShare);
                foreach ($activeItems as $item) {
                    if ($item['idx'] === $idx && $item['minVal'] > 0 && $newSize < $item['minVal']) {
                        $newSize = (int)$item['minVal'];
                        break;
                    }
                }
                $shrinkSizes[$idx] = $newSize;
            }
        }

        foreach ($shrinkSizes as $idx => $size) {
            $ch = $children[$idx];
            if ($isRow) {
                $ch->w = (int)$size;
                $ch->visualW = $this->visualWidth($ch, $ch->w);
            } else {
                $ch->h = (int)$size;
                $ch->visualH = $this->visualHeight($ch, $ch->h);
            }
        }
    }

    // ── Step 8: Min/max constraints ──
    private function applyMinMax(array $children, bool $isRow): void
    {
        foreach ($children as $ch) {
            $chCS = $ch->computedStyle;
            $minW = $chCS?->minWidth?->toPx() ?? 0;
            $maxW = $chCS?->maxWidth?->toPx() ?? 0;
            $minH = $chCS?->minHeight?->toPx() ?? 0;
            $maxH = $chCS?->maxHeight?->toPx() ?? 0;
            if ($minW > 0 && $ch->w < $minW) $ch->w = $minW;
            if ($maxW > 0 && $ch->w > $maxW) $ch->w = $maxW;
            if ($minH > 0 && $ch->h < $minH) $ch->h = $minH;
            if ($maxH > 0 && $ch->h > $maxH) $ch->h = $maxH;
            $ch->visualW = $this->visualWidth($ch, $ch->w);
            $ch->visualH = $this->visualHeight($ch, $ch->h);
        }
    }

    // ── Auto margins ──
    private function resolveAutoMargins(array $children, array &$flexData, bool $isRow, int $containerMain, int $lineTotalMain, int $gap, int $lineCount): ?array
    {
        $hasAutoMainMargin = false;
        $autoMarginCount = 0;
        foreach ($children as $ch) {
            $chCS = $ch->computedStyle;
            $mL = (bool)($chCS?->getRaw('marginLeftAuto') ?? false);
            $mR = (bool)($chCS?->getRaw('marginRightAuto') ?? false);
            if ($mL || $mR) $hasAutoMainMargin = true;
            if ($mL) $autoMarginCount++;
            if ($mR) $autoMarginCount++;
        }
        if (!$hasAutoMainMargin) return null;

        $remainingForAuto = $containerMain - $lineTotalMain;
        if ($remainingForAuto <= 0 || $autoMarginCount <= 0) return null;

        $spacePerAuto = (int)($remainingForAuto / $autoMarginCount);
        $resolvedAutoMargins = [];
        foreach ($children as $idx => $ch) {
            $chCS = $ch->computedStyle;
            $resolvedAutoMargins[$idx] = [
                'left' => (bool)($chCS?->getRaw('marginLeftAuto') ?? false) ? $spacePerAuto : 0,
                'right' => (bool)($chCS?->getRaw('marginRightAuto') ?? false) ? $spacePerAuto : 0,
            ];
        }
        return $resolvedAutoMargins;
    }

    // ── Step 10-11: Justify-content + Positioning ──
    private function applyPositioning(
        array $children, array &$flexData, bool $isRow, bool $isWrapping, bool $reversed,
        string $justify, string $align, RenderNode $node,
        int $paddingLeft, int $paddingTop,
        int $containerMain, int $containerCross, int $lineTotalMain, int $lineMaxCross,
        int $lineCrossBase, int $gap, ?array $resolvedAutoMargins
    ): void {
        $lineCount = count($children);
        $mainStart = match ($justify) {
            'center' => ($containerMain - $lineTotalMain) / 2,
            'flex-end' => $containerMain - $lineTotalMain,
            'space-between', 'space-around', 'space-evenly' => 0,
            default => 0,
        };
        $spaceBetween = 0;
        if ($justify === 'space-between' && $lineCount > 1) {
            $spaceBetween = ($containerMain - $lineTotalMain) / ($lineCount - 1);
        } elseif ($justify === 'space-around' && $lineCount > 0) {
            $spaceBetween = ($containerMain - $lineTotalMain) / $lineCount;
            $mainStart = $spaceBetween / 2;
        } elseif ($justify === 'space-evenly' && $lineCount > 0) {
            $spaceBetween = ($containerMain - $lineTotalMain) / ($lineCount + 1);
            $mainStart = $spaceBetween;
        }

        $currentMain = $mainStart;
        $indices = range(0, $lineCount - 1);
        if ($reversed) $indices = array_reverse($indices);

        foreach ($indices as $idx) {
            $i = (int)$idx;
            $ch = $children[$i];
            $chCS = $ch->computedStyle;

            $childMarginLeft = ($resolvedAutoMargins[$i]['left'] ?? null)
                ?? $chCS?->margin?->left?->toPx() ?? 0;
            $childMarginRight = ($resolvedAutoMargins[$i]['right'] ?? null)
                ?? $chCS?->margin?->right?->toPx() ?? 0;
            $childMarginTop = $chCS?->margin?->top?->toPx() ?? 0;
            $childMarginBottom = $chCS?->margin?->bottom?->toPx() ?? 0;

            $oldX = $ch->x;
            $oldY = $ch->y;

            if ($isRow) {
                $ch->x = $node->x + $paddingLeft + (int)$currentMain + $childMarginLeft;
            } else {
                $ch->y = $node->y + $paddingTop + (int)$currentMain + $childMarginTop;
            }

            $effectiveAlign = $chCS?->alignSelf?->value ?? 'auto';
            if ($effectiveAlign === 'auto') $effectiveAlign = $align;

            if ($isWrapping) {
                if ($effectiveAlign === 'stretch') {
                    if ($isRow && !$flexData[$i]['hasExplicitCrossSize']) {
                        $stretchedH = (int)max(0, $lineMaxCross - $childMarginTop - $childMarginBottom);
                        if ($stretchedH > $ch->h && $stretchedH > 0) {
                            $ch->h = $stretchedH;
                            $ch->visualH = $this->visualHeight($ch, $ch->h);
                            $flexData[$i]['crossAxisSized'] = true;
                        }
                    } elseif (!$isRow && !$flexData[$i]['hasExplicitCrossSize']) {
                        $stretchedW = (int)max(0, $lineMaxCross - $childMarginLeft - $childMarginRight);
                        if ($stretchedW > 0) {
                            $ch->w = $stretchedW;
                            $ch->visualW = $this->visualWidth($ch, $ch->w);
                            $flexData[$i]['crossAxisSized'] = true;
                        }
                    }
                    if ($isRow) {
                        $ch->y = $node->y + $paddingTop + $lineCrossBase + $childMarginTop;
                    } else {
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
                if ($effectiveAlign === 'stretch') {
                    if ($isRow && !$flexData[$i]['hasExplicitCrossSize']) {
                        $stretchedH = (int)max(0, $containerCross - $childMarginTop - $childMarginBottom);
                        if ($stretchedH > $ch->h && $stretchedH > 0) {
                            $ch->h = $stretchedH;
                            $ch->visualH = $this->visualHeight($ch, $ch->h);
                            $flexData[$i]['crossAxisSized'] = true;
                        }
                    } elseif (!$isRow && !$flexData[$i]['hasExplicitCrossSize']) {
                        $stretchedW = (int)max(0, $containerCross - $childMarginLeft - $childMarginRight);
                        if ($stretchedW > 0) {
                            $ch->w = $stretchedW;
                            $ch->visualW = $this->visualWidth($ch, $ch->w);
                            $flexData[$i]['crossAxisSized'] = true;
                        }
                    }
                }
                $crossSize = $isRow ? $ch->h : $ch->w;
                $crossOffset = match ($effectiveAlign) {
                    'center' => (int)(($containerCross - $crossSize) / 2),
                    'flex-end' => $containerCross - $crossSize,
                    default => 0,
                };
                if ($isRow) {
                    $ch->y = $node->y + $paddingTop + $crossOffset;
                } else {
                    $ch->x = $node->x + $paddingLeft + $crossOffset;
                }
            }

            if ($isRow) {
                $ch->y += $childMarginTop;
            } else {
                $ch->x += $childMarginLeft;
            }

            // Shift descendants
            $dx = $ch->x - $oldX;
            $dy = $ch->y - $oldY;
            if ($dy !== 0) {
                foreach ($ch->children as $gc) { $gc->y += $dy; }
            }
            if ($dx !== 0) {
                foreach ($ch->children as $gc) { $gc->x += $dx; }
            }

            $chMainSize = $isRow ? $ch->visualW : $ch->visualH;
            $currentMain += $chMainSize + $gap + $spaceBetween;
            if ($isRow) {
                $currentMain += $childMarginLeft + $childMarginRight;
            } else {
                $currentMain += $childMarginTop + $childMarginBottom;
            }
        }
    }

    // ── Two-pass re-layout ──
    // CRITICAL: resolveChildNode resets the item's main-axis dimension to the
    // parent container's full extent. We must restore the flex-allocated size
    // after re-layout so the grow/shrink/stretch allocation is preserved.
    private function twoPassReLayout(array $children, array &$flexData, RenderNode $node): void
    {
        foreach ($children as $idxTp => $chTp) {
            $dataTp = $flexData[$idxTp];
            $needsTwoPass = $dataTp['isFlexGrow'] || $dataTp['crossAxisSized'];
            if ($needsTwoPass && (count($chTp->children) > 0 || $chTp->content !== null)) {
                // Save flex-allocated main-axis size
                $savedW = $chTp->w;
                $savedH = $chTp->h;

                $leftOff = (int)($chTp->computedStyle?->left ?? 0);
                $topOff = (int)($chTp->computedStyle?->top ?? 0);
                $chTp->layoutDirty = true;
                foreach ($chTp->children as $gc) { $gc->layoutDirty = true; }
                $this->resolver->resolveChildNode($chTp, $chTp->x - $leftOff, $chTp->y - $topOff, $node);

                // Restore flex-allocated main-axis size (resolveChildNode resets it)
                $chTp->w = $savedW;
                $chTp->h = $savedH;
                $chTp->visualW = $this->visualWidth($chTp, $chTp->w);
                $chTp->visualH = $this->visualHeight($chTp, $chTp->h);
            }
        }
    }

    // ── Helpers ──
    private function visualWidth(RenderNode $ch, int $contentW): int
    {
        return $ch->computedStyle?->visualWidth($contentW) ?? $contentW;
    }

    private function visualHeight(RenderNode $ch, int $contentH): int
    {
        return $ch->computedStyle?->visualHeight($contentH) ?? $contentH;
    }

    private function resolveLineHeight(RenderNode $ch, int $fontSize): int
    {
        $lh = $ch->computedStyle?->lineHeight ?? 0;
        return $lh > 0 ? $lh : (int)($fontSize * 1.2);
    }

    private function resolveFlexMinMain(RenderNode $ch, bool $isRow, int $currentMainSize): int
    {
        $chCS = $ch->computedStyle;
        if ($isRow) {
            $minVal = $chCS?->minWidth?->toPx() ?? 0;
        } else {
            $minVal = $chCS?->minHeight?->toPx() ?? 0;
        }
        if ($minVal > 0) return $minVal;

        // Check overflow
        $ov = $isRow
            ? ($chCS?->overflowX?->value ?? $chCS?->overflow?->value ?? 'visible')
            : ($chCS?->overflowY?->value ?? $chCS?->overflow?->value ?? 'visible');
        if ($ov !== 'visible') return 0;
        // Content-based minimum: text width (CSS §4.5, automatic minimum size)
        $chText = $ch->content ?? '';
        if (is_string($chText) && strlen($chText) > 0) {
            $fs = $ch->computedStyle?->fontSize ?? 14;
            $bd = $ch->computedStyle?->bold ?? false;
            $textW = function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($chText, $fs, $bd) : 0;
            return $textW > 0 ? $textW : 0;
        }
        // Check child text nodes
        $descW = $this->maxDescendantTextWidth($ch);
        return $descW > 0 ? $descW : 0;
    }

    private function maxDescendantTextWidth(RenderNode $node): int
    {
        $text = $node->content ?? '';
        if (is_string($text) && strlen($text) > 0) {
            $fs = $node->computedStyle?->fontSize ?? 14;
            $bd = $node->computedStyle?->bold ?? false;
            return function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($text, $fs, $bd) : 0;
        }
        $maxW = 0;
        foreach ($node->children as $child) {
            $childW = $this->maxDescendantTextWidth($child);
            if ($childW > $maxW) $maxW = $childW;
        }
        return $maxW;
    }
}
