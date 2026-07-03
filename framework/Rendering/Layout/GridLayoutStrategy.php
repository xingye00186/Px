<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\CssMappings;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\LayoutConstraints;
use Px\Rendering\Layout\FragmentBuilder;
use Px\Rendering\Layout\Grid\GridFragmentMapper;
use Px\Rendering\Layout\Grid\GridItem;

/**
 * GridLayoutStrategy — CSS Grid 布局策略
 *
 * CSS Grid Layout Module Level 1:
 * 实现基本的 grid 布局算法，包括:
 * - grid-template-columns/rows 解析（px/%/fr/auto-fill）
 * - 跨列（grid-column: span N）
 * - 两阶段布局（内容高度探测 → 行高调整 + stretch）
 * - justify-items/justify-self/align-self
 * - Grid item 子节点百分比重解析（adjustGridItemChildren）
 */
class GridLayoutStrategy implements LayoutStrategyInterface
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
        $this->resolveGridLayout($node, $parentX, $parentY, $style, $builder);
    }

    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Grid layout: position children in a CSS grid.
     */
    public function resolveGridLayout(
        RenderNode    $node,
        int           $parentX,
        int           $parentY,
        ?ComputedStyle $computedStyle,
        ?FragmentBuilder $builder = null
    ): void
    {
        // ── 安全防护：保留 style 数组用于网格字符串属性，其余用 ComputedStyle 直访 ──
        $style = $computedStyle !== null ? $computedStyle->toExportArray() : [];
        foreach ($style as $sk => $sv) {
            if ($sv instanceof \Px\Rendering\CssLength || $sv instanceof \Px\Rendering\CssRect) {
                $style[$sk] = $sv->toPx();
            } elseif ($sv instanceof \Px\Rendering\CssKeyword) {
                $style[$sk] = $sv->value;
            } elseif ($sv instanceof \Px\Rendering\CssColor) {
                $style[$sk] = $sv->toBgr();
            }
        }

        $leftPx = $computedStyle?->left?->toPx() ?? 0;
        $topPx = $computedStyle?->top?->toPx() ?? 0;

        $width = $computedStyle?->width?->toPx() ?? 0;
        $height = $computedStyle?->height?->toPx() ?? 0;

        // CSS: grid item percentage width resolves against content width
        $parentW = (int)(($node->parent !== null)
            ? $node->parent->computedStyle?->contentBoxWidth($node->parent->w) ?? $node->parent->w
            : 0);

        $parentH = ($node->parent !== null) ? $node->parent->h : 0;

        $width = $computedStyle?->width?->resolveInContext($parentW) ?? 0;

        $height = $computedStyle?->height?->resolveInContext($parentH) ?? 0;

        // CSS Grid Level 1: block-level grid container with auto width fills containing block

        $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);

        if (!$hasExplicitW && $width === 0 && $node->parent !== null) {
            $width = $parentW;
        }

        // Note: height:auto for grid containers is content-based (computed below)

        $node->x = $left + $parentX;

        $node->y = $top + $parentY;

        // Apply translate from animatedStyle

        $translateX = $style['translateX'] ?? 0;

        $translateY = $style['translateY'] ?? 0;

        $node->x += $translateX;

        $node->y += $translateY;

        // ── 应用 min/max 约束到尺寸（在子节点递归之前，确保 parent->w/h 立即可用）──
        $node->w = (int)max(0, $width);
        { $_mnW = $computedStyle?->minWidth?->resolveInContext($parentW) ?? 0; $_mxW = $computedStyle?->maxWidth?->resolveInContext($parentW) ?? 0;
            if ($_mnW > 0 && $_mxW > 0 && $_mnW > $_mxW) $_mxW = 0;
            if ($_mnW > 0 && $node->w < $_mnW) $node->w = $_mnW;
            if ($_mxW > 0 && $node->w > $_mxW) $node->w = $_mxW;
        }

        $node->h = (int)max(0, $height);
        { $_mnH = $computedStyle?->minHeight?->resolveInContext($parentH) ?? 0; $_mxH = $computedStyle?->maxHeight?->resolveInContext($parentH) ?? 0;
            if ($_mnH > 0 && $_mxH > 0 && $_mnH > $_mxH) $_mxH = 0;
            if ($_mnH > 0 && $node->h < $_mnH) $node->h = $_mnH;
            if ($_mxH > 0 && $node->h > $_mxH) $node->h = $_mxH;
        }

        $node->visualW = $computedStyle?->visualWidth($node->w) ?? $node->w;
        $node->visualH = $computedStyle?->visualHeight($node->h) ?? $node->h;

        // Parse grid template

        $gridCols = $computedStyle?->gridTemplateColumns ?? '';

        $gridRows = $computedStyle?->gridTemplateRows ?? '';

        $colSpec = CssMappings::parseGridTemplateValue($gridCols);

        $rowSpec = CssMappings::parseGridTemplateValue($gridRows);

        // Gap values (must be defined before 1fr calculation)

        $colGap = (int)($computedStyle?->columnGap?->toPx() ?? $computedStyle?->gap?->toPx() ?? 0);

        $rowGap = (int)($computedStyle?->rowGap?->toPx() ?? $computedStyle?->gap?->toPx() ?? 0);

        $cols = null;

        $cellW = null;

        $colRepeat = $colSpec['repeat'] ?? null;

        // auto-fill/auto-fit: 根据容器宽度计算列数
        if ($colRepeat === 'auto-fill' || $colRepeat === 'auto-fit') {
            $minColW = (int)($colSpec['min'] ?? 245);

            $gridContentW = $computedStyle?->contentBoxWidth($node->w) ?? $node->w;

            // CSS Grid 规范 §7.1: cols = floor((availableW + gap) / (min + gap))
            $cols = (int)max(1, floor(($gridContentW + $colGap) / ($minColW + $colGap)));

            // ── 计算列宽与单元格数 ──
            $totalGaps = $colGap * ($cols - 1);

            $cellW = (int)max(0, ($gridContentW - $totalGaps) / $cols);
        }

        if ($cols === null) {
            $cols = $colSpec['count'] ?? 4;
        }

        if ($cellW === null) {
            $cellW = $colSpec['size'] ?? 80;
        }

        // 1fr 支持：根据容器宽度按比例分配
        if (($colSpec['unit'] ?? '') === 'fr' && $node->w > 0 && $colRepeat !== 'auto-fill' && $colRepeat !== 'auto-fit') {
            $gridContentW = $computedStyle?->contentBoxWidth($node->w) ?? $node->w;
            $totalGaps = $colGap * ($cols - 1);

            $cellW = (int)max(0, ($gridContentW - $totalGaps) / $cols);
        }

        $gridAutoRows = (int)($computedStyle?->gridAutoRows ?? 0);
        $defaultRowH = $gridAutoRows > 0 ? $gridAutoRows : 60;

        $rows = $rowSpec['count'] ?? 5;

        $cellH = (int)($rowSpec['size'] ?? $defaultRowH);

        // 1fr 支持：根据容器宽度按比例分配
        if (($rowSpec['unit'] ?? '') === 'fr' && $node->h > 0) {
            $totalGaps = $rowGap * ($rows - 1);

            $cellH = (int)max(0, (int)(($node->h - $totalGaps) / $rows));
        }

        // ── Explicit grid columns (mixed px/%/fr) ──
        $explicitColWidths = null;
        if (($colSpec['type'] ?? '') === 'explicit') {
            $sizes = $colSpec['sizes'] ?? [];
            $explicitColWidths = [];
            $totalFr = 0;
            $usedPx = 0;

            $gridContentW = $computedStyle?->contentBoxWidth($node->w) ?? $node->w;

            // First pass: resolve fixed (px/%) widths, mark fr as null, minmax as ['min'=>...,'fr'=>...]
            foreach ($sizes as $size) {
                $s = trim($size);
                $slen = strlen($s);

                // px suffix
                if ($slen > 2 && substr($s, -2) === 'px' && is_numeric(substr($s, 0, -2))) {
                    $w = (int)substr($s, 0, -2);
                    $explicitColWidths[] = $w;
                    $usedPx += $w;
                    continue;
                }

                // % suffix
                if ($slen > 1 && substr($s, -1) === '%' && is_numeric(substr($s, 0, -1))) {
                    $pct = (int)substr($s, 0, -1);
                    $pctW = (int)($gridContentW * $pct / 100);
                    $explicitColWidths[] = $pctW;
                    $usedPx += $pctW;
                    continue;
                }

                // fr suffix
                if ($slen > 2 && substr($s, -2) === 'fr' && is_numeric(substr($s, 0, -2))) {
                    $frVal = (int)substr($s, 0, -2);
                    $explicitColWidths[] = ['fr' => $frVal];
                    $totalFr += $frVal;
                    continue;
                }

                // minmax(a,b)
                if (substr($s, 0, 7) === 'minmax(' && substr($s, -1) === ')') {
                    $inner = substr($s, 7, -1);
                    $commaPos = strpos($inner, ',');
                    if ($commaPos !== false) {
                        $minStr = trim(substr($inner, 0, $commaPos));
                        $maxStr = trim(substr($inner, $commaPos + 1));

                        // Round-trip parse to verify (avoid preg_match in AOT)
                        $minVal = 0;
                        $minLen = strlen($minStr);
                        if ($minLen > 2 && substr($minStr, -2) === 'px' && is_numeric(substr($minStr, 0, -2))) {
                            $minVal = (int)substr($minStr, 0, -2);
                        } elseif ($minLen > 1 && substr($minStr, -1) === '%' && is_numeric(substr($minStr, 0, -1))) {
                            $minVal = (int)($gridContentW * (int)substr($minStr, 0, -1) / 100);
                        } elseif (is_numeric($minStr)) {
                            $minVal = (int)$minStr;
                        }

                        $maxVal = 0;
                        $isMaxFr = false;
                        $maxLen = strlen($maxStr);
                        if ($maxLen > 2 && substr($maxStr, -2) === 'fr' && is_numeric(substr($maxStr, 0, -2))) {
                            $maxVal = (int)substr($maxStr, 0, -2);
                            $isMaxFr = true;
                        } elseif ($maxLen > 2 && substr($maxStr, -2) === 'px' && is_numeric(substr($maxStr, 0, -2))) {
                            $maxVal = (int)substr($maxStr, 0, -2);
                        } elseif ($maxLen > 1 && substr($maxStr, -1) === '%' && is_numeric(substr($maxStr, 0, -1))) {
                            $maxVal = (int)($gridContentW * (int)substr($maxStr, 0, -1) / 100);
                        } elseif (is_numeric($maxStr)) {
                            $maxVal = (int)$maxStr;
                        }

                        if ($isMaxFr && $maxVal > 0) {
                            $explicitColWidths[] = ['min' => $minVal, 'fr' => $maxVal];
                            $usedPx += $minVal;
                            $totalFr += $maxVal;
                        } else {
                            $explicitColWidths[] = $minVal;
                            $usedPx += $minVal;
                        }
                        continue;
                    }
                }

                // Bare number: treat as px
                $w = is_numeric($s) ? (int)$s : 0;
                $explicitColWidths[] = $w;
                $usedPx += $w;
            }

            $cols = count($explicitColWidths);

            // Second pass: distribute remaining space to fr tracks
            if ($totalFr > 0) {
                $gridContentW = $computedStyle?->contentBoxWidth($node->w) ?? $node->w;
                $remaining = $gridContentW - $usedPx - $colGap * (int)max(0, $cols - 1);
                $frUnit = (int)max(0, (int)($remaining / $totalFr));
                foreach ($explicitColWidths as $i => $colW) {
                    if (is_array($colW) && isset($colW['fr'])) {
                        $fr = $colW['fr'];
                        $min = $colW['min'] ?? 0;
                        $explicitColWidths[(int)$i] = $min + $frUnit * $fr;
                    }
                }
            } else {
                // Replace remaining fr/minmax arrays with their min value
                foreach ($explicitColWidths as $i => $colW) {
                    if (is_array($colW) && isset($colW['fr'])) {
                        $explicitColWidths[(int)$i] = $colW['min'] ?? 0;
                    } elseif (is_array($colW) && isset($colW['min'])) {
                        $explicitColWidths[(int)$i] = $colW['min'];
                    }
                }
            }

            // Set cellW for backward compatibility (first column width)
            $cellW = (int)($explicitColWidths[0] ?? 80);
        }

        // Collect children and resolve their styles

        $children = [];

        foreach ($node->children as $child) {
            $this->resolver->resolveChildNode($child, $node->x, $node->y, $node);
            $children[] = $child;
        }

        // ── 两阶段 Grid 布局：先计算内容高度，再应用行高 ──
        $col = 0;
        $row = 0;
        $cellPaddingCol = $colGap;
        $cellPaddingRow = $rowGap;

        // 判断是否有显式 grid-template-rows
        $hasExplicitRows = ($rowSpec['type'] ?? '') === 'explicit' || !empty($rowSpec['size']);

        // ── Parse grid-template-areas for named area placement ──
        $areaMap = [];
        $areasRaw = $computedStyle?->gridTemplateAreas ?? '';
        if ($areasRaw !== '') {
            $areaMap = self::parseGridTemplateAreas($areasRaw);
        }

        // ── Pass 1: 定位 + 内容高度探测 ──
        // 仅设置水平方向 stretch（宽度），不垂直 stretch——让 grid item
        // 保持自然高度，从而可以探测每行实际需要的行高。
        $gridItems = [];
        foreach ($children as $i2 => $c2) {
            $gridItems[$i2] = new GridItem($c2);
        }
        $rowContentHeights = [];
        $itemRowMap = [];

        foreach ($children as $idx => $ch) {
            // typed access used directly

            // Use explicit grid-column/grid-row from style (CSS 1-based)
            // grid-area: name overrides explicit grid-column/grid-row
            $explicitCol = $ch->computedStyle?->getRaw('gridColumn') ?? null;
            $explicitRow = $ch->computedStyle?->getRaw('gridRow') ?? null;
            $gridAreaName = $ch->computedStyle?->getRaw('gridArea') ?? '';
            if ($gridAreaName !== '' && isset($areaMap[$gridAreaName])) {
                $area = $areaMap[$gridAreaName];
                $col = (int)($area['colStart']);
                $row = (int)($area['rowStart']);
                $colSpan = (int)($area['colEnd'] - $area['colStart']);
                // Skip standard grid-column/grid-row parsing
            } else {
            $colSpan = 1;
            if ($explicitCol !== null && $explicitCol !== '') {
                if (preg_match('/^span\s+(\d+)$/i', $explicitCol, $m)) {
                    $colSpan = (int)max(1, $m[1]);
                } elseif (preg_match('/^(\-?\d+)\s*\/\s*(\-?\d+)$/', $explicitCol, $m)) {
                    $startCol = (int)$m[1] - 1;
                    $endCol = (int)$m[2] - 1;
                    $col = (int)max(0, $startCol);
                    if ($endCol < 0 && $cols !== null) {
                        $colSpan = (int)max(1, $cols - $startCol);
                    } else {
                        $colSpan = (int)max(1, $endCol - $startCol + 1);
                    }
                } else {
                    $col = (int)$explicitCol - 1;
                }
            }
            if ($explicitRow !== null && $explicitRow !== '') {
                $row = (int)$explicitRow - 1;
            }
            }

            // Auto-placement: if item with span doesn't fit current row, wrap to next row first
            if ($colSpan > 1 && $cols !== null && $col + $colSpan > $cols) {
                $col = 0;
                $row++;
            }

            // 计算网格单元水平位置
            if ($explicitColWidths !== null) {
                $cellX = $node->x;
                for ($ci = 0; $ci < $col; $ci++) {
                    $cellX += (int)($explicitColWidths[$ci] + $colGap);
                }
            } else {
                $cellX = $node->x + $col * ($cellW + $colGap);
            }
            $cellY = $node->y + $row * ($cellH + $rowGap);

            // ── 跨列宽度计算 (grid-column span N / M / N) ──
            if ($colSpan > 1) {
                if ($explicitColWidths !== null) {
                    $cellWFinal = 0;
                    $maxCi = min($col + $colSpan, $cols);
                    for ($ci = $col; $ci < $maxCi; $ci++) {
                        $cellWFinal += (int)max(0, (int)($explicitColWidths[$ci] ?? $cellW));
                        if ($ci < $maxCi - 1) {
                            $cellWFinal += $colGap;
                        }
                    }
                } else {
                    $cellWFinal = (int)max(0, (int)($cellW * $colSpan + ($colSpan - 1) * $colGap));
                }
            } else {
                if ($explicitColWidths !== null && isset($explicitColWidths[$col])) {
                    $cellWFinal = (int)max(0, (int)$explicitColWidths[$col]);
                } else {
                    $cellWFinal = (int)max(0, (int)$cellW);
                }
            }
            $cellHFinal = (int)max(0, (int)$cellH);

            // ── Pass 1 只设宽度，不设高度 ──
            // justify-self / justify-items: 控制 grid item 水平对齐
            $explicitW = $ch->w;
            $justifySelf = $ch->computedStyle?->getRaw('justifySelf') ?? 'auto';
            if ($justifySelf === 'auto') {
                $justifySelf = $style['justifyItems'] ?? 'normal';
            }
            // Grid items: normal = stretch
            if ($justifySelf === 'normal' || $justifySelf === 'stretch' || $justifySelf === 'auto') {
                $gridItems[$idx]->x = $cellX;
                $gridItems[$idx]->w = $cellWFinal;
            } elseif ($justifySelf === 'center') {
                if ($explicitW > 0 && $explicitW < $cellWFinal) {
                    $gridItems[$idx]->x = $cellX + (int)(($cellWFinal - $explicitW) / 2);
                    $gridItems[$idx]->w = $explicitW;
                } else {
                    $gridItems[$idx]->x = $cellX;
                    $gridItems[$idx]->w = $cellWFinal;
                }
            } elseif ($justifySelf === 'end' || $justifySelf === 'flex-end') {
                if ($explicitW > 0 && $explicitW < $cellWFinal) {
                    $gridItems[$idx]->x = $cellX + $cellWFinal - $explicitW;
                    $gridItems[$idx]->w = $explicitW;
                } else {
                    $gridItems[$idx]->x = $cellX;
                    $gridItems[$idx]->w = $cellWFinal;
                }
            } else {
                // start / flex-start / other: keep explicit width
                if ($explicitW > 0 && $explicitW < $cellWFinal) {
                    $gridItems[$idx]->w = $explicitW;
                }
                $gridItems[$idx]->x = $cellX;
            }
            // align-self: 仅定位置 y，不强制高度
            $gridItems[$idx]->y = $cellY;
            // NOTE: 不设置 $ch->h，保留 resolveNode 后的自然高度

            // min/max 约束（仅宽度）
            $gridItems[$idx]->w = (int)max(0, $ch->w);
            { $__mnW = $ch->computedStyle?->minWidth?->resolveInContext($parentW) ?? 0; $__mxW = $ch->computedStyle?->maxWidth?->resolveInContext($parentW) ?? 0;
                if ($__mnW > 0 && $__mxW > 0 && $__mnW > $__mxW) $__mxW = 0;
                if ($__mnW > 0 && $ch->w < $__mnW) $gridItems[$idx]->w = $__mnW;
                if ($__mxW > 0 && $ch->w > $__mxW) $gridItems[$idx]->w = $__mxW;
            }
            $ch->visualW = $ch->computedStyle?->visualWidth($ch->w) ?? $ch->w;
            // ── sync GridItem x/w/y back to RenderNode ──
            $ch->x = $gridItems[$idx]->x;
            $ch->w = $gridItems[$idx]->w;
            $ch->y = $gridItems[$idx]->y;
            // 高度不应用 min/max——等调整后得到自然内容高度

            // 调整子节点（重解析 flex/grid 的百分比尺寸）
            $this->adjustGridItemChildren($ch, $node->parent, true);

            // 计算 grid item 的实际内容高度：从子节点的 bottom 边推算
            $actualContentH = $ch->h;
            if (!empty($ch->children)) {
                foreach ($ch->children as $gc) {
                    $gcBottomLocal = ($gc->y + $gc->visualH) - $cellY;
                    if ($gcBottomLocal > $actualContentH) {
                        $actualContentH = $gcBottomLocal;
                    }
                }
            }
            $rowContentHeights[$row] = max($rowContentHeights[$row] ?? 0, $actualContentH);
            $itemRowMap[$idx] = $row;

            $col += $colSpan;
            if ($col >= $cols) {
                $col = 0;
                $row++;
            }
        }

        // ── Pass 2: 确定行高 → stretch + re-adjust ──
        $actualRowHeights = [];
        if (!$hasExplicitRows) {
            foreach ($rowContentHeights as $r => $h) {
                $actualRowHeights[$r] = $h > 0 ? $h : $defaultRowH;
            }
        } else {
            $totalRows = max($row + 1, count($rowContentHeights));
            for ($r = 0; $r < $totalRows; $r++) {
                $actualRowHeights[$r] = $cellH;
            }
        }

        // 第二遍：更新 grid item 高度（stretch），并重新调整子节点
        foreach ($children as $idx => $ch) {
            $chRow = $itemRowMap[$idx] ?? 0;
            $actualRowH = $actualRowHeights[$chRow] ?? $cellH;

            // 计算该行新的 Y 位置（基于实际行高累加）
            $newCellY = $node->y;
            for ($r = 0; $r < $chRow; $r++) {
                $newCellY += ($actualRowHeights[$r] ?? $cellH) + $rowGap;
            }

            // typed access used directly
            $alignSelf = $ch->computedStyle?->getRaw('alignSelf') ?? 'auto';
            if ($alignSelf === 'auto') $alignSelf = 'stretch';

            // 保持水平位置不变（已在 Pass 1 中设置好）
            switch ($alignSelf) {
                case 'center':
                    $oldH = $ch->h;
                    $gridItems[$idx]->y = $newCellY + (int)(($actualRowH - $oldH) / 2);
                    break;
                case 'end':
                case 'flex-end':
                    $gridItems[$idx]->y = (int)($newCellY + $actualRowH - $ch->h);
                    break;
                case 'start':
                case 'flex-start':
                    $gridItems[$idx]->y = $newCellY;
                    break;
                default: // stretch
                    $gridItems[$idx]->y = $newCellY;
                    $gridItems[$idx]->h = $actualRowH;
                    $ch->visualH = $ch->computedStyle?->visualHeight($ch->h) ?? $ch->h;
                    break;
            }

            // min/max 约束
            $gridItems[$idx]->w = max(0, $ch->w);
            { $__mnW2 = $ch->computedStyle?->minWidth?->resolveInContext($parentW) ?? 0; $__mxW2 = $ch->computedStyle?->maxWidth?->resolveInContext($parentW) ?? 0;
                if ($__mnW2 > 0 && $__mxW2 > 0 && $__mnW2 > $__mxW2) $__mxW2 = 0;
                if ($__mnW2 > 0 && $ch->w < $__mnW2) $gridItems[$idx]->w = $__mnW2;
                if ($__mxW2 > 0 && $ch->w > $__mxW2) $gridItems[$idx]->w = $__mxW2;
            }
            $gridItems[$idx]->h = max(0, $ch->h);
            { $__mnH = $ch->computedStyle?->minHeight?->resolveInContext($parentH) ?? 0; $__mxH = $ch->computedStyle?->maxHeight?->resolveInContext($parentH) ?? 0;
                if ($__mnH > 0 && $__mxH > 0 && $__mnH > $__mxH) $__mxH = 0;
                if ($__mnH > 0 && $ch->h < $__mnH) $gridItems[$idx]->h = $__mnH;
                if ($__mxH > 0 && $ch->h > $__mxH) $gridItems[$idx]->h = $__mxH;
            }
            $ch->visualW = $ch->computedStyle?->visualWidth($ch->w) ?? $ch->w;
            $ch->visualH = $ch->computedStyle?->visualHeight($ch->h) ?? $ch->h;
            // ── sync GridItem x/w/y/h back to RenderNode ──
            $ch->x = $gridItems[$idx]->x;
            $ch->w = $gridItems[$idx]->w;
            $ch->y = $gridItems[$idx]->y;
            $ch->h = $gridItems[$idx]->h;

            // 如果高度变化了（stretch），需要重新调整子节点
            if ($alignSelf === 'stretch') {
                $this->adjustGridItemChildren($ch, $node->parent, true);
                // 恢复 grid cell 决定的位置和宽度（adjustGridItemChildren 内部会 restore）
                $gridItems[$idx]->y = $newCellY;
                $gridItems[$idx]->h = $actualRowH;
                $ch->visualH = $ch->computedStyle?->visualHeight($gridItems[$idx]->h) ?? $gridItems[$idx]->h;
                // ── sync GridItem y/h back to RenderNode ──
                $ch->y = $gridItems[$idx]->y;
                $ch->h = $gridItems[$idx]->h;
            }
        }

        // ── 容器高度 = 最后一行底部 ──
        $hasExplicitH = $computedStyle !== null && $computedStyle->height->toPx() > 0;
        $hasHPct = array_key_exists('heightPercent', $style);

        if (!$hasExplicitH && !$hasHPct) {
            $maxBottom = (int)$node->y;
            foreach ($children as $ch) {
                $chBottom = (int)($ch->y + $ch->visualH);
                if ($chBottom > $maxBottom) $maxBottom = $chBottom;
            }
            $contentH = (int)($maxBottom - $node->y);
            if ($contentH > $node->h) {
                $computedH = (int)max(0, $contentH);
                { $_mH = $computedStyle?->minHeight?->resolveInContext($parentH) ?? 0; $_xH = $computedStyle?->maxHeight?->resolveInContext($parentH) ?? 0;
                    if ($_mH > 0 && $_xH > 0 && $_mH > $_xH) $_xH = 0;
                    if ($_mH > 0 && $computedH < $_mH) $computedH = $_mH;
                    if ($_xH > 0 && $computedH > $_xH) $computedH = $_xH;
                }
                if ($computedH > $node->h) {
                    $node->h = $computedH;
                }
            }
        }
        // ── Set container's own visualW/visualH ──
        $node->visualW = $computedStyle?->visualWidth($node->w) ?? $node->w;
        $node->visualH = $computedStyle?->visualHeight($node->h) ?? $node->h;

        // ── 使用 GridFragmentMapper 同步 builder 中的子 Fragment ──
        // grid 算法直接写入 $node->children[$i]->x/y/w/h，但 builder 中的子 Fragment
        // 来自 resolveChildren（grid 算法之前），位置/尺寸已过时。
        // GridFragmentMapper 将 GridItem[] → LayoutFragment[]，保留孙子链。
        if ($builder !== null) {
            $originalChildren = $builder->getChildren();
            $gridItems = [];
            foreach ($node->children as $i => $ch) {
                $item = new GridItem($ch);
                $item->x = $ch->x;
                $item->y = $ch->y;
                $item->w = $ch->w;
                $item->h = $ch->h;
                $item->visualW = $ch->visualW;
                $item->visualH = $ch->visualH;
                $gridItems[] = $item;
            }
            $mappedFragments = GridFragmentMapper::toFragments($gridItems, $originalChildren);
            $builder->replaceChildren($mappedFragments);
            $builder
                ->setPosition($node->x, $node->y)
                ->setSize($node->w, $node->h, $computedStyle)
                ->setLayer($node->layer)
                ->setContentSize($node->contentWidth, $node->contentHeight);
        }
    }

    /**
     * Grid item 的子节点百分比尺寸修复。
     *
     * Grid item 的百分比宽度应相对于 grid cell 宽度解析，而非 grid 容器宽度。
     * 此方法在 grid item 被正确放置到 cell 后调用，重新解析子节点。
     *
     * CSS 规范: 重新解析时需保持 grid item 自身的布局上下文。
     */
    private function adjustGridItemChildren(RenderNode $gridItem, $parentCtx, bool $recurseChildren = true): void
    {
        if (empty($gridItem->children) || $gridItem->w <= 0) {
            return;
        }

        $display = $gridItem->computedStyle?->display?->value ?? 'block';

        if ($display === 'flex' || $display === 'inline-flex') {
            // Flex 容器: 整个 flex 布局重新处理。
            $gridItem->markSubtreeDirty();

            $this->resolver->getFlexStrategy()->resolveFlexLayout(
                $gridItem,
                $gridItem->x,
                $gridItem->y,
                $gridItem->computedStyle
            );

            return;
        }

        if ($display === 'grid') {
            // 嵌套 grid: 整个 grid 布局重新处理
            $gridItem->markSubtreeDirty();

            $this->resolveGridLayout(
                $gridItem,
                $gridItem->x,
                $gridItem->y,
                $gridItem->computedStyle
            );

            return;
        }

        // Block 显示: 逐个重新解析子节点
        foreach ($gridItem->children as $child) {
            $child->markSubtreeDirty();
            $this->resolver->resolveChildNode($child, $gridItem->x, $gridItem->y, $gridItem);
        }
    }

    /**
     * Parse CSS grid-template-areas ASCII-art string into area name → position mapping.
     *
     * Input: '"header header" "nav main" "footer footer"'
     * Output: [
     *   'header' => ['rowStart'=>0, 'colStart'=>0, 'rowEnd'=>1, 'colEnd'=>2],
     *   'nav'    => ['rowStart'=>1, 'colStart'=>0, 'rowEnd'=>2, 'colEnd'=>1],
     *   ...
     * ]
     */
    public static function parseGridTemplateAreas(string $areas): array
    {
        $map = [];
        $rows = [];
        // Split by quoted strings: "..."
        preg_match_all('/"([^"]*)"/', $areas, $matches);
        if (empty($matches[1])) {
            return $map;
        }
        foreach ($matches[1] as $rowStr) {
            $cells = preg_split('/\s+/', trim($rowStr));
            if (count($cells) > 0) {
                $rows[] = $cells;
            }
        }
        if (empty($rows)) {
            return $map;
        }
        $numCols = count($rows[0]);
        foreach ($rows as $ri => $row) {
            if (count($row) !== $numCols) {
                continue; // Malformed row, skip
            }
            for ($ci = 0; $ci < $numCols; $ci++) {
                $name = trim($row[$ci]);
                if ($name === '' || $name === '.') continue; // . = empty cell
                if (!isset($map[$name])) {
                    $map[$name] = [
                        'rowStart' => $ri,
                        'rowEnd'   => $ri + 1,
                        'colStart' => $ci,
                        'colEnd'   => $ci + 1,
                    ];
                } else {
                    // Extend existing area
                    if ($ri >= $map[$name]['rowEnd']) {
                        $map[$name]['rowEnd'] = $ri + 1;
                    }
                    if ($ci < $map[$name]['colStart']) {
                        $map[$name]['colStart'] = $ci;
                    }
                    if ($ci >= $map[$name]['colEnd']) {
                        $map[$name]['colEnd'] = $ci + 1;
                    }
                }
            }
        }
        return $map;
    }
}
