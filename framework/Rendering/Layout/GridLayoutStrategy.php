<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\CssMappings;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;

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
class GridLayoutStrategy
{
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Grid layout: position children in a CSS grid.
     */
    public function resolveGridLayout(
        RenderNode  $node,
        int         $parentX,
        int         $parentY,
        ?RenderNode $parent,
        array       &$scrollContainers,
        array       $style
    ): void
    {
        $left = $style['left'] ?? 0;

        $top = $style['top'] ?? 0;

        $width = $style['width'] ?? 0;

        $height = $style['height'] ?? 0;

        // CSS: grid item percentage width resolves against content width
        $parentW = (int)(($parent !== null) ? max(0, $parent->w
            - (int)($parent->style['paddingLeft'] ?? $parent->style['padding'] ?? 0)
            - (int)($parent->style['paddingRight'] ?? $parent->style['padding'] ?? 0)) : 0);

        $parentH = ($parent !== null) ? $parent->h : 0;

        $width = PercentResolver::resolvePercent($style, 'width', 'widthPercent', $parentW);

        $height = PercentResolver::resolvePercent($style, 'height', 'heightPercent', $parentH);

        // CSS Grid Level 1: block-level grid container with auto width fills containing block

        $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);

        if (!$hasExplicitW && $width === 0 && $parent !== null) {
            $width = $parent->w;
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
        $node->w = (int)max(0, (int)PercentResolver::applyMinMax($style, $width, true));

        $node->h = (int)max(0, (int)PercentResolver::applyMinMax($style, $height, false));

        // Parse grid template

        $gridCols = $style['gridTemplateColumns'] ?? '';

        $gridRows = $style['gridTemplateRows'] ?? '';

        $colSpec = CssMappings::parseGridTemplateValue($gridCols);

        $rowSpec = CssMappings::parseGridTemplateValue($gridRows);

        // Gap values (must be defined before 1fr calculation)

        $colGap = (int)($style['gridColumnGap'] ?? $style['gap'] ?? 0);

        $rowGap = (int)($style['gridRowGap'] ?? $style['gap'] ?? 0);

        $cols = null;

        $cellW = null;

        $colRepeat = $colSpec['repeat'] ?? null;

        // auto-fill/auto-fit: 根据容器宽度计算列数
        if ($colRepeat === 'auto-fill' || $colRepeat === 'auto-fit') {
            $minColW = (int)($colSpec['min'] ?? 245);

            // CSS Grid 规范 §7.1: cols = floor((availableW + gap) / (min + gap))
            $cols = (int)max(1, floor(($node->w + $colGap) / ($minColW + $colGap)));

            // ── 计算列宽与单元格数 ──
            $totalGaps = $colGap * ($cols - 1);

            $cellW = (int)max(0, ($node->w - $totalGaps) / $cols);
        }

        if ($cols === null) {
            $cols = $colSpec['count'] ?? 4;
        }

        if ($cellW === null) {
            $cellW = $colSpec['size'] ?? 80;
        }

        // 1fr 支持：根据容器宽度按比例分配
        if (($colSpec['unit'] ?? '') === 'fr' && $node->w > 0 && $colRepeat !== 'auto-fill' && $colRepeat !== 'auto-fit') {
            $totalGaps = $colGap * ($cols - 1);

            $cellW = (int)max(0, ($node->w - $totalGaps) / $cols);
        }

        $rows = $rowSpec['count'] ?? 5;

        $cellH = (int)($rowSpec['size'] ?? 60);

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

            // First pass: resolve fixed (px/%) widths, mark fr as null
            foreach ($sizes as $size) {
                if (preg_match('/^(\d+(?:\.\d+)?)px$/i', $size, $m)) {
                    $w = (int)$m[1];
                    $explicitColWidths[] = $w;
                    $usedPx += $w;
                } elseif (preg_match('/^(\d+(?:\.\d+)?)%$/', $size, $m)) {
                    $pct = (int)$m[1];
                    $pctW = (int)($node->w * $pct / 100);
                    $explicitColWidths[] = $pctW;
                    $usedPx += $pctW;
                } elseif (preg_match('/^(\d+(?:\.\d+)?)fr$/i', $size, $m)) {
                    $explicitColWidths[] = null;
                    $totalFr += (int)$m[1];
                } else {
                    // Bare number: treat as px
                    $w = (int)$size;
                    $explicitColWidths[] = $w;
                    $usedPx += $w;
                }
            }

            $cols = count($explicitColWidths);

            // Second pass: distribute remaining space to fr tracks
            if ($totalFr > 0) {
                $remaining = $node->w - $usedPx - $colGap * (int)max(0, $cols - 1);
                $frUnit = (int)max(0, (int)($remaining / $totalFr));
                foreach ($explicitColWidths as $i => $colW) {
                    if ($colW === null) {
                        $explicitColWidths[(int)$i] = $frUnit;
                    }
                }
            } else {
                // Replace remaining nulls with 0 (no fr tracks with remaining space)
                foreach ($explicitColWidths as $i => $colW) {
                    if ($colW === null) {
                        $explicitColWidths[(int)$i] = 0;
                    }
                }
            }

            // Set cellW for backward compatibility (first column width)
            $cellW = (int)($explicitColWidths[0] ?? 80);
        }

        // Collect children and resolve their styles

        $children = [];

        foreach ($node->children as $child) {
            $this->resolver->resolveNode($child, $node->x, $node->y, $node, $scrollContainers);

            $children[] = $child;
        }

        // ── 两阶段 Grid 布局：先计算内容高度，再应用行高 ──
        $col = 0;
        $row = 0;
        $cellPaddingCol = $colGap;
        $cellPaddingRow = $rowGap;

        // 判断是否有显式 grid-template-rows
        $hasExplicitRows = ($rowSpec['type'] ?? '') === 'explicit' || !empty($rowSpec['size']);

        // ── Pass 1: 定位 + 内容高度探测 ──
        // 仅设置水平方向 stretch（宽度），不垂直 stretch——让 grid item
        // 保持自然高度，从而可以探测每行实际需要的行高。
        $rowContentHeights = [];
        $itemRowMap = [];

        foreach ($children as $idx => $ch) {
            $childStyle = $ch->style;

            // Use explicit grid-column/grid-row from style (CSS 1-based)
            $explicitCol = $childStyle['gridColumn'] ?? null;
            $explicitRow = $childStyle['gridRow'] ?? null;
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
            $justifySelf = $childStyle['justifySelf'] ?? 'auto';
            if ($justifySelf === 'auto') {
                $justifySelf = $style['justifyItems'] ?? 'normal';
            }
            // Grid items: normal = stretch
            if ($justifySelf === 'normal' || $justifySelf === 'stretch' || $justifySelf === 'auto') {
                $ch->x = $cellX;
                $ch->w = $cellWFinal;
            } elseif ($justifySelf === 'center') {
                if ($explicitW > 0 && $explicitW < $cellWFinal) {
                    $ch->x = $cellX + (int)(($cellWFinal - $explicitW) / 2);
                    $ch->w = $explicitW;
                } else {
                    $ch->x = $cellX;
                    $ch->w = $cellWFinal;
                }
            } elseif ($justifySelf === 'end' || $justifySelf === 'flex-end') {
                if ($explicitW > 0 && $explicitW < $cellWFinal) {
                    $ch->x = $cellX + $cellWFinal - $explicitW;
                    $ch->w = $explicitW;
                } else {
                    $ch->x = $cellX;
                    $ch->w = $cellWFinal;
                }
            } else {
                // start / flex-start / other: keep explicit width
                if ($explicitW > 0 && $explicitW < $cellWFinal) {
                    $ch->w = $explicitW;
                }
                $ch->x = $cellX;
            }
            // align-self: 仅定位置 y，不强制高度
            $ch->y = $cellY;
            // NOTE: 不设置 $ch->h，保留 resolveNode 后的自然高度

            // min/max 约束（仅宽度）
            $ch->w = (int)max(0, (int)PercentResolver::applyMinMax($childStyle, $ch->w, true));
            // 高度不应用 min/max——等调整后得到自然内容高度

            // 调整子节点（重解析 flex/grid 的百分比尺寸）
            $this->adjustGridItemChildren($ch, $scrollContainers);

            // 计算 grid item 的实际内容高度：从子节点的 bottom 边推算
            $actualContentH = $ch->h;
            if (!empty($ch->children)) {
                foreach ($ch->children as $gc) {
                    $gcBottomLocal = ($gc->y + $gc->h) - $cellY;
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
                $actualRowHeights[$r] = max(60, $h);
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

            $childStyle = $ch->style;
            $alignSelf = $childStyle['alignSelf'] ?? 'auto';
            if ($alignSelf === 'auto') $alignSelf = 'stretch';

            // 保持水平位置不变（已在 Pass 1 中设置好）
            switch ($alignSelf) {
                case 'center':
                    $oldH = $ch->h;
                    $ch->y = $newCellY + (int)(($actualRowH - $oldH) / 2);
                    break;
                case 'end':
                case 'flex-end':
                    $ch->y = (int)($newCellY + $actualRowH - $ch->h);
                    break;
                case 'start':
                case 'flex-start':
                    $ch->y = $newCellY;
                    break;
                default: // stretch
                    $ch->y = $newCellY;
                    $ch->h = $actualRowH;
                    break;
            }

            // min/max 约束
            $ch->w = max(0, (int)PercentResolver::applyMinMax($childStyle, $ch->w, true));
            $ch->h = max(0, (int)PercentResolver::applyMinMax($childStyle, $ch->h, false));

            // 如果高度变化了（stretch），需要重新调整子节点
            if ($alignSelf === 'stretch') {
                $this->adjustGridItemChildren($ch, $scrollContainers);
                // 恢复 grid cell 决定的位置和宽度（adjustGridItemChildren 内部会 restore）
                $ch->y = $newCellY;
                $ch->h = $actualRowH;
            }
        }

        // ── 容器高度 = 最后一行底部 ──
        $hasExplicitH = array_key_exists('height', $style) && $style['height'] !== 'auto' && $style['height'] !== '';
        $hasHPct = array_key_exists('heightPercent', $style);

        if (!$hasExplicitH && !$hasHPct) {
            $maxBottom = (int)$node->y;
            foreach ($children as $ch) {
                $chBottom = (int)($ch->y + $ch->h);
                if ($chBottom > $maxBottom) $maxBottom = $chBottom;
            }
            $contentH = (int)($maxBottom - $node->y);
            if ($contentH > $node->h) {
                $computedH = (int)PercentResolver::applyMinMax($style, $contentH, false);
                if ($computedH > $node->h) {
                    $node->h = $computedH;
                }
            }
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
    private function adjustGridItemChildren(RenderNode $gridItem, array &$scrollContainers): void
    {
        if (empty($gridItem->children) || $gridItem->w <= 0) {
            return;
        }

        $display = $gridItem->style['display'] ?? 'block';

        if ($display === 'flex' || $display === 'inline-flex') {
            // Flex 容器: 整个 flex 布局重新处理。
            ScrollHelper::markSubtreeDirty($gridItem);

            $savedX = $gridItem->x;
            $savedY = $gridItem->y;
            $savedW = $gridItem->w;
            $savedH = $gridItem->h;

            // 临时注入 cell 尺寸到 style,使 flex 布局使用正确的包含块尺寸。
            $hasOrigW = array_key_exists('width', $gridItem->style);
            $hasOrigH = array_key_exists('height', $gridItem->style);
            $origW = $gridItem->style['width'] ?? null;
            $origH = $gridItem->style['height'] ?? null;
            $gridItem->style['width'] = $savedW;
            $gridItem->style['height'] = $savedH;

            $this->resolver->getFlexStrategy()->resolveFlexLayout(
                $gridItem,
                $savedX, $savedY,
                $gridItem,
                $scrollContainers,
                $gridItem->style
            );

            // 还原原始 style
            if ($hasOrigW) {
                $gridItem->style['width'] = $origW;
            } else {
                unset($gridItem->style['width']);
            }
            if ($hasOrigH) {
                $gridItem->style['height'] = $origH;
            } else {
                unset($gridItem->style['height']);
            }

            // 恢复 grid cell 决定的坐标和尺寸
            $gridItem->x = $savedX;
            $gridItem->y = $savedY;
            $gridItem->w = $savedW;
            $gridItem->h = $savedH;

            return;
        }

        if ($display === 'grid') {
            // 嵌套 grid: 整个 grid 布局重新处理
            ScrollHelper::markSubtreeDirty($gridItem);

            $savedX = $gridItem->x;
            $savedY = $gridItem->y;
            $savedW = $gridItem->w;
            $savedH = $gridItem->h;

            // 同样注入 cell 尺寸到 style
            $hasOrigW = array_key_exists('width', $gridItem->style);
            $hasOrigH = array_key_exists('height', $gridItem->style);
            $origW = $gridItem->style['width'] ?? null;
            $origH = $gridItem->style['height'] ?? null;
            $gridItem->style['width'] = $savedW;
            $gridItem->style['height'] = $savedH;

            $this->resolveGridLayout(
                $gridItem,
                $savedX, $savedY,
                $gridItem,
                $scrollContainers,
                $gridItem->style
            );

            // 还原原始 style
            if ($hasOrigW) {
                $gridItem->style['width'] = $origW;
            } else {
                unset($gridItem->style['width']);
            }
            if ($hasOrigH) {
                $gridItem->style['height'] = $origH;
            } else {
                unset($gridItem->style['height']);
            }

            $gridItem->x = $savedX;
            $gridItem->y = $savedY;
            $gridItem->w = $savedW;
            $gridItem->h = $savedH;

            return;
        }

        // Block 显示: 逐个重新解析子节点
        foreach ($gridItem->children as $child) {
            ScrollHelper::markSubtreeDirty($child);
            $this->resolver->resolveNode($child, $gridItem->x, $gridItem->y, $gridItem, $scrollContainers);
        }
    }
}
