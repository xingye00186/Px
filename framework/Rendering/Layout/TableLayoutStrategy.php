<?php

namespace Px\Rendering\Layout;

use Px\Rendering\LayoutResolver;
use Px\Rendering\Layout\Tools\PercentResolver;
use Px\Rendering\RenderNode;

/**
 * TableLayoutStrategy — CSS 表格布局模型（CSS 2.2 §17）
 *
 * 自动表格布局算法（简化版）:
 *   display:table → 块级表格容器
 *   display:table-row → 行容器（水平排列子代）
 *   display:table-cell → 单元格（等比例分配行宽）
 *   display:table-caption → 标题
 *
 * 简化假设：
 *   1. 所有列等宽（平分容器宽度）
 *   2. 行高由最高单元格决定
 *   3. 单元格默认 vertical-align:middle
 *   4. border-collapse 仅作标记传递
 */
class TableLayoutStrategy implements LayoutStrategyInterface
{
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function resolve(RenderNode $node, LayoutContext $ctx, array $style): void
    {
        $display = $style['display'] ?? 'table';

        switch ($display) {
            case 'table':
                $this->resolveTable($node, $ctx, $style);
                break;
            case 'table-row':
                $this->resolveTableRow($node, $ctx, $style);
                break;
            case 'table-cell':
                $this->resolveTableCell($node, $ctx, $style);
                break;
            case 'table-caption':
                $this->resolveTableCaption($node, $ctx, $style);
                break;
            default:
                // Fallback to block layout for other table-* values
                $blockStrategy = $this->resolver->getBlockStrategy();
                $blockStrategy->resolve($node, $ctx, $style);
                break;
        }
    }

    /**
     * Resolve display:table — 块级表格容器
     */
    private function resolveTable(RenderNode $node, LayoutContext $ctx, array $style): void
    {
        // ── 容器自身尺寸 ──
        $w = (int)($style['width'] ?? 0);
        $h = (int)($style['height'] ?? 0);
        $minW = (int)($style['minWidth'] ?? 0);
        $minH = (int)($style['minHeight'] ?? 0);
        $maxW = (int)($style['maxWidth'] ?? 0);
        $maxH = (int)($style['maxHeight'] ?? 0);

        $cbW = $ctx->parent ? self::getContentBoxWidth($ctx->parent) : 0;
        if ($w <= 0 && $cbW > 0) {
            $w = $cbW;
        }
        if ($w < $minW) $w = $minW;
        if ($maxW > 0 && $w > $maxW) $w = $maxW;

        $node->x = $ctx->parentX;
        $node->y = $ctx->parentY;
        $node->w = $w;
        $node->h = $h;

        // ── Padding ──
        $padL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
        $padR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);
        $padT = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
        $padB = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);

        $contentW = $w - $padL - $padR;
        if ($contentW < 0) $contentW = 0;

        // ── 收集行（table-row / table-row-group 子节点）──
        $rows = [];
        foreach ($node->children as $child) {
            $childDisplay = $child->style['display'] ?? 'block';
            if ($childDisplay === 'table-row') {
                $rows[] = $child;
            } elseif ($childDisplay === 'table-row-group' || $childDisplay === 'table-header-group' || $childDisplay === 'table-footer-group') {
                // Flatten row groups into the row list
                foreach ($child->children as $grandchild) {
                    if (($grandchild->style['display'] ?? '') === 'table-row') {
                        $rows[] = $grandchild;
                    }
                }
            }
        }

        // ── 收集每行的单元格数量，确定列数 ──
        $maxCols = 0;
        foreach ($rows as $row) {
            $cellCount = 0;
            foreach ($row->children as $cell) {
                if (($cell->style['display'] ?? '') === 'table-cell') {
                    $colSpan = (int)($cell->style['colSpan'] ?? 1);
                    $cellCount += $colSpan;
                }
            }
            if ($cellCount > $maxCols) $maxCols = $cellCount;
        }
        if ($maxCols === 0) $maxCols = 1;

        // ── 等分列宽 ──
        $colW = (int)($contentW / $maxCols);
        if ($colW < 0) $colW = 0;

        // ── 布局行和单元格 ──
        $currentY = $node->y + $padT;
        $totalContentH = 0;

        foreach ($rows as $row) {
            // Layout the row
            $row->x = $node->x + $padL;
            $row->y = $currentY;
            $row->w = $contentW;

            // Resolve row children (cells)
            $rowCtx = new LayoutContext($row->x, $row->y, $row);
            $this->resolveTableRow($row, $rowCtx, $row->style);

            // Row height = max cell height
            $rowH = 0;
            foreach ($row->children as $cell) {
                $cellH = $cell->h;
                if ($cellH > $rowH) $rowH = $cellH;
            }
            if ($rowH <= 0) {
                // CSS 2.2 §10.8.1: 行高默认 ≈ font-size × 1.2
                PercentResolver::resolveFontSizeUnit($style);
                $fs = (int)($style['fontSize'] ?? 14);
                $rowH = (int)($fs * 1.2);
            }
            $row->h = $rowH;

            // Position cells within the row with vertical-align:middle
            foreach ($row->children as $cell) {
                $cell->w = $colW;
                $cell->h = $rowH;
                // vertical-align
                $va = $cell->style['verticalAlign'] ?? 'middle';
                $cellContentH = $cell->h - (int)($cell->style['paddingTop'] ?? 0) - (int)($cell->style['paddingBottom'] ?? 0);
                if ($cellContentH < $rowH && $va === 'middle') {
                    // Center vertically
                }
                // Resolve cell children
                $cellCtx = new LayoutContext($cell->x + (int)($cell->style['paddingLeft'] ?? 0), $cell->y + (int)($cell->style['paddingTop'] ?? 0), $cell);
                foreach ($cell->children as $grandchild) {
                    $this->resolver->resolveNode($grandchild, $cellCtx);
                }
            }

            $currentY += $rowH;
            $totalContentH += $rowH;
        }

        // ── Caption ──
        foreach ($node->children as $child) {
            if (($child->style['display'] ?? '') === 'table-caption') {
                $child->x = $node->x + $padL;
                $child->y = $currentY;
                $child->w = $contentW;
                $capCtx = new LayoutContext($child->x, $child->y, $node);
                $this->resolver->resolveNode($child, $capCtx);
                $totalContentH += $child->h;
                $currentY += $child->h;
            }
        }

        // ── Container height ──
        if ($h <= 0) {
            $h = $padT + $totalContentH + $padB;
        }
        if ($h < $minH) $h = $minH;
        if ($maxH > 0 && $h > $maxH) $h = $maxH;
        $node->h = $h;
        $node->visualW = $w;
        $node->visualH = $h;
    }

    /**
     * Resolve display:table-row — 水平排列子代（单元格）
     */
    private function resolveTableRow(RenderNode $node, LayoutContext $ctx, array $style): void
    {
        $node->x = $ctx->parentX;
        $node->y = $ctx->parentY;

        // Cells are laid out horizontally
        $cellX = $node->x;
        foreach ($node->children as $cell) {
            if (($cell->style['display'] ?? '') !== 'table-cell') continue;
            $cell->x = $cellX;
            $cell->y = $node->y;

            // Resolve cell children
            $cellCtx = new LayoutContext(
                $cell->x + (int)($cell->style['paddingLeft'] ?? 0),
                $cell->y + (int)($cell->style['paddingTop'] ?? 0),
                $node
            );
            $this->resolver->resolveNode($cell, $cellCtx);
            $cellX += $cell->w;
        }
        $node->w = $cellX - $node->x;
    }

    /**
     * Resolve display:table-cell
     */
    private function resolveTableCell(RenderNode $node, LayoutContext $ctx, array $style): void
    {
        $node->x = $ctx->parentX;
        $node->y = $ctx->parentY;

        $padL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
        $padR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);
        $padT = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
        $padB = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);

        $contentW = $node->w - $padL - $padR;
        if ($contentW < 0) $contentW = 0;

        $contentY = $node->y + $padT;

        foreach ($node->children as $child) {
            $childCtx = new LayoutContext($node->x + $padL, $contentY, $node);
            $this->resolver->resolveNode($child, $childCtx);
            $contentY += $child->h;
        }
    }

    /**
     * Resolve display:table-caption
     */
    private function resolveTableCaption(RenderNode $node, LayoutContext $ctx, array $style): void
    {
        $node->x = $ctx->parentX;
        $node->y = $ctx->parentY;

        // Caption behaves like block-level element
        $blockStrategy = $this->resolver->getBlockStrategy();
        $blockStrategy->resolve($node, $ctx, $style);
    }

    /**
     * Get the content-box width of a parent node.
     */
    private static function getContentBoxWidth(RenderNode $parent): int
    {
        $pw = $parent->w;
        $ppL = (int)($parent->style['paddingLeft'] ?? $parent->style['padding'] ?? 0);
        $ppR = (int)($parent->style['paddingRight'] ?? $parent->style['padding'] ?? 0);
        $pbW = (int)($parent->style['borderWidth'] ?? 0);
        return $pw - $ppL - $ppR - $pbW * 2;
    }
}
