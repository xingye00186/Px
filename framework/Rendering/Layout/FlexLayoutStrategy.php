<?php

namespace Px\Rendering\Layout;

use native_types;
use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\Flex\FlexItem;
use Px\Rendering\Layout\Flex\FlexLineBreaker;
use Px\Rendering\Layout\Flex\FlexFragmentMapper;
use Px\Rendering\CssLength;

class FlexLayoutStrategy implements LayoutStrategyInterface
{
    public function layout(LayoutInput $input): LayoutResult
    {
        if ($input->constraints->isIntrinsicMeasurement) {
            // Aggregate children natural sizes
            $totalW = 0; $maxH = 0;
            foreach ($input->childResults as $cr) { $totalW += $cr->w; if ($cr->h > $maxH) $maxH = $cr->h; }
            return new LayoutResult(w: $totalW, h: $maxH, minContentWidth: $totalW, maxContentWidth: $totalW, preferredContentWidth: $totalW, minContentHeight: $maxH, maxContentHeight: $maxH, preferredContentHeight: $maxH);
        }
        $c = $input->constraints;
        $s = $input->style;
        $childResults = $input->childResults;
        $parentX = $c->parentContentX;
        $parentY = $c->parentContentY;
        $parentW = $c->contentWidth;
        $parentH = $c->contentHeight;
        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $x = $parentX + $left;
        $y = $parentY + $top;
        $w = $s->width->toPx();
        if ($w <= 0) $w = $parentW;
        $h = $s->height->toPx();
        $isRow = ($s->getRaw("flexDirection") !== "column");
        $justify = $s->getRaw("justifyContent") ?? "flex-start";
        $align = $s->getRaw("alignItems") ?? "stretch";
        $alignContent = $s->alignContent?->value ?? 'stretch';
        $wrap = $s->getRaw("flexWrap");
        $isWrapping = ($wrap === "wrap" || $wrap === "wrap-reverse");
        $gap = (int)($s->getRaw("gap") ?? 0);

        // ── Step 1: Collect flex items ──
        $flexItems = [];
        $flexItemData = [];
        foreach ($childResults as $cr) {
            $cs = $cr->style;
            if ($cs === null) continue;
            $grow = (float)($cs->getRaw("flexGrow") ?? 0);
            $shrink = (float)($cs->getRaw("flexShrink") ?? 1);
            $rawBasis = $cs->getRaw("flexBasis");
            $basis = -1;
            if ($rawBasis instanceof CssLength && !$rawBasis->isAuto()) { $basis = $rawBasis->toPx(); }
            $hasExplicitCross = $cs->getRaw($isRow ? 'height' : 'width') !== null;
            $alignSelfRaw = $cs->getRaw('alignSelf');
            $item = new FlexItem();
            $item->grow = $grow;
            $item->shrink = $shrink;
            $item->basis = $basis;
            $item->isFlexGrow = ($grow > 0);
            $item->alignSelf = ($alignSelfRaw !== null && $alignSelfRaw !== 'auto') ? (string)$alignSelfRaw : 'auto';
            $item->originalChildren = $cr->children;
            $item->w = $cr->w; $item->h = $cr->h;
            $item->visualW = $cr->visualW; $item->visualH = $cr->visualH;
            $flexItems[] = $item;
            $flexItemData[] = [
                'grow' => $grow, 'shrink' => $shrink, 'basis' => $basis,
                'isFlexGrow' => ($grow > 0), 'hasExplicitCrossSize' => $hasExplicitCross,
                'crossAxisSized' => false,
                'marginLeft' => 0, 'marginRight' => 0, 'marginTop' => 0, 'marginBottom' => 0,
            ];
        }
        if (count($flexItems) === 0) {
            return new LayoutResult(x: $x, y: $y, w: $w, h: $h, visualW: $s->visualWidth($w), visualH: $s->visualHeight($h), style: $s);
        }

        // ── Step 2: Apply flex-basis ──
        foreach ($flexItems as $item) {
            if ($item->basis > 0) { if ($isRow) $item->w = $item->basis; else $item->h = $item->basis; }
        }

        // ── Step 3: Break into lines (FlexLineBreaker) ──
        $breaker = new FlexLineBreaker();
        $lines = $breaker->breakLines($flexItems, $flexItemData, $isWrapping, $isRow, $w, $gap);
        $lineGroups = $lines[0];
        $lineData = $lines[1] ?? [];
        $totalLines = count($lineGroups);

        // ── Step 4: Per-line grow/shrink + main-axis positioning ──
        $lineMaxCrosses = [];
        foreach ($lineGroups as $lineIdx => $lineItems) {
            $lineTotal = 0;
            foreach ($lineItems as $item) { $lineTotal += $isRow ? $item->w : $item->h; }

            // 4a. Flex-grow
            if ($lineTotal < $w && $lineTotal > 0) {
                $remaining = $w - $lineTotal;
                $growTotal = 0;
                foreach ($lineItems as $item) { $growTotal += $item->grow; }
                if ($growTotal > 0) {
                    foreach ($lineItems as $item) {
                        if ($item->grow > 0) {
                            $extra = (int)($remaining * $item->grow / $growTotal);
                            if ($isRow) $item->w += $extra; else $item->h += $extra;
                        }
                    }
                }
            }

            // 4b. Flex-shrink
            if ($lineTotal > $w) {
                $overflow = $lineTotal - $w;
                $shrinkTotal = 0;
                foreach ($lineItems as $item) { $shrinkTotal += $item->shrink; }
                if ($shrinkTotal > 0) {
                    foreach ($lineItems as $item) {
                        if ($item->shrink > 0) {
                            $reduction = (int)($overflow * $item->shrink / $shrinkTotal);
                            if ($isRow) $item->w = max(0, $item->w - $reduction);
                            else $item->h = max(0, $item->h - $reduction);
                        }
                    }
                }
            }

            // 4c. Recalc line totals + max cross
            $lineFinal = 0;
            $lineMaxCross = 0;
            foreach ($lineItems as $item) {
                $lineFinal += $isRow ? $item->w : $item->h;
                $cross = $isRow ? $item->h : $item->w;
                if ($cross > $lineMaxCross) $lineMaxCross = $cross;
            }

            // 4d. Justify-content
            $mainStart = 0; $spaceBetween = 0;
            $itemCount = count($lineItems);
            if ($justify === "center") { $mainStart = ($w - $lineFinal) / 2; }
            elseif ($justify === "flex-end") { $mainStart = $w - $lineFinal; }
            elseif ($justify === "space-between" && $itemCount > 1) { $spaceBetween = ($w - $lineFinal) / ($itemCount - 1); }
            elseif ($justify === "space-around") { $spaceBetween = ($w - $lineFinal) / $itemCount; $mainStart = $spaceBetween / 2; }
            elseif ($justify === "space-evenly") { $spaceBetween = ($w - $lineFinal) / ($itemCount + 1); $mainStart = $spaceBetween; }

            // 4e. Apply stretch to fill line maxCross (not container cross)
            foreach ($lineItems as $item) {
                $effAlign = $this->effectiveAlign($item, $align);
                $crossSize = $isRow ? $item->h : $item->w;
                if ($effAlign === 'stretch' && $crossSize < $lineMaxCross && $crossSize > 0) {
                    if ($isRow) $item->h = $lineMaxCross;
                    else $item->w = $lineMaxCross;
                }
            }
            // Recalculate maxCross after stretch
            $lineMaxCross = 0;
            foreach ($lineItems as $item) {
                $cross = $isRow ? $item->h : $item->w;
                if ($cross > $lineMaxCross) $lineMaxCross = $cross;
            }

            // 4f. Main-axis positioning
            $cursorMain = $x + (int)$mainStart;
            foreach ($lineItems as $item) {
                if ($isRow) {
                    $item->x = (int)$cursorMain;
                    $cursorMain += $item->w + (int)$spaceBetween + $gap;
                } else {
                    $item->y = (int)$cursorMain;
                    $cursorMain += $item->h + (int)$spaceBetween + $gap;
                }
            }

            $lineMaxCrosses[$lineIdx] = $lineMaxCross;
        }

        // ── Step 5: Cross-axis alignment (align-items/align-self per-item + align-content) ──
        // 5a. Calculate total cross size and align-content offsets
        $totalCross = 0;
        foreach ($lineMaxCrosses as $lmc) { $totalCross += $lmc + $gap; }
        $totalCross = max(0, $totalCross - $gap);

        $crossAvailable = $isRow ? $h : $w;
        if ($crossAvailable <= 0) $crossAvailable = $totalCross;

        // align-content for multi-line
        $lineCrossOffsets = [];
        if ($totalLines > 1 && $crossAvailable > $totalCross) {
            $extraCross = $crossAvailable - $totalCross;
            switch ($alignContent) {
                case 'center':
                    $offset = $extraCross / 2;
                    foreach ($lineMaxCrosses as $i => $lmc) { $lineCrossOffsets[$i] = $offset; $offset += $lmc + $gap; }
                    break;
                case 'flex-end': case 'end':
                    $offset = $extraCross;
                    foreach ($lineMaxCrosses as $i => $lmc) { $lineCrossOffsets[$i] = $offset; $offset += $lmc + $gap; }
                    break;
                case 'space-between':
                    $space = $extraCross / ($totalLines - 1);
                    $offset = 0;
                    foreach ($lineMaxCrosses as $i => $lmc) { $lineCrossOffsets[$i] = $offset; $offset += $lmc + $space; }
                    break;
                case 'space-around':
                    $space = $extraCross / $totalLines;
                    $offset = $space / 2;
                    foreach ($lineMaxCrosses as $i => $lmc) { $lineCrossOffsets[$i] = $offset; $offset += $lmc + $space; }
                    break;
                case 'space-evenly':
                    $space = $extraCross / ($totalLines + 1);
                    $offset = $space;
                    foreach ($lineMaxCrosses as $i => $lmc) { $lineCrossOffsets[$i] = $offset; $offset += $lmc + $space; }
                    break;
                default: // stretch
                    $stretchedCross = (int)($crossAvailable / $totalLines);
                    $offset = 0;
                    foreach ($lineMaxCrosses as $i => $lmc) {
                        $lineCrossOffsets[$i] = $offset;
                        $lineMaxCrosses[$i] = max($lmc, $stretchedCross - $gap);
                        $offset += $lineMaxCrosses[$i] + $gap;
                    }
                    break;
            }
        } else {
            $offset = 0;
            foreach ($lineMaxCrosses as $i => $lmc) { $lineCrossOffsets[$i] = $offset; $offset += $lmc + $gap; }
        }

        // 5b. Per-item cross-axis positioning
        $crossBase = $isRow ? $y : $x;
        foreach ($lineGroups as $lineIdx => $lineItems) {
            $lineCrossOffset = $lineCrossOffsets[$lineIdx];
            $lineMaxCross = $lineMaxCrosses[$lineIdx];
            foreach ($lineItems as $itemIdx => $item) {
                $effAlign = $this->effectiveAlign($item, $align);
                $crossSize = $isRow ? $item->h : $item->w;

                // Cross-axis offset within line
                $crossItemOffset = 0;
                if ($effAlign === 'center') {
                    $crossItemOffset = (int)(($lineMaxCross - $crossSize) / 2);
                } elseif ($effAlign === 'flex-end' || $effAlign === 'end') {
                    $crossItemOffset = $lineMaxCross - $crossSize;
                } // flex-start/baseline: offset = 0; stretch: already sized to lineMaxCross

                if ($isRow) {
                    $item->y = $crossBase + (int)$lineCrossOffset + $crossItemOffset;
                } else {
                    $item->x = $crossBase + (int)$lineCrossOffset + $crossItemOffset;
                }
            }
        }

        // ── Step 6: Map results ──
        $mappedResults = FlexFragmentMapper::toResults($flexItems, $childResults);
        if ($h <= 0 && count($mappedResults) > 0) {
            $maxBottom = $y;
            foreach ($mappedResults as $cr) { $b = $cr->y + $cr->h; if ($b > $maxBottom) $maxBottom = $b; }
            $h = max(0, $maxBottom - $y);
        }
        return new LayoutResult(x: $x, y: $y, w: $w, h: $h, visualW: $s->visualWidth($w), visualH: $s->visualHeight($h), style: $s, children: $mappedResults);
    }

    /** Resolve effective align value: align-self overrides align-items */
    private function effectiveAlign(FlexItem $item, string $containerAlign): string
    {
        if ($item->alignSelf !== 'auto') {
            return $item->alignSelf;
        }
        return $containerAlign;
    }
}