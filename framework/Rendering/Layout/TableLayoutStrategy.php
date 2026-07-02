<?php

namespace Px\Rendering\Layout;

use native_types;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\ComputedStyle;

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

        $display = $style?->display?->value ?? 'table';

        switch ($display) {
            case 'table':
                $this->resolveTable($node, $parentX, $parentY, $style, $builder);
                break;
            case 'table-row':
                $this->resolveTableRow($node, $parentX, $parentY, $style, $builder);
                break;
            case 'table-cell':
                $this->resolveTableCell($node, $parentX, $parentY, $style, $builder);
                break;
            case 'table-caption':
            default:
                // Caption / fallback: block-like positioning
                $bw = $style?->width->toPx() ?? 0;
                $bh = $style?->height->toPx() ?? 0;
                $builder
                    ->setPosition($parentX, $parentY)
                    ->setSize($bw, $bh, $style)
                    ->setLayer($node->layer);
                break;
        }
    }

    /**
     * Resolve display:table — 块级表格容器
     */
    private function resolveTable(
        RenderNode $node,
        int $parentX, int $parentY,
        ?ComputedStyle $style,
        FragmentBuilder $builder
    ): void {
        // ── 容器自身尺寸 ──
        $w = $style?->width->toPx() ?? 0;
        $h = $style?->height->toPx() ?? 0;

        // Content-box width from parent
        $parent = $node->parent;
        $cbW = 0;
        if ($parent !== null && $parent->computedStyle !== null) {
            $ps = $parent->computedStyle;
            $cbW = $parent->w
                - $ps->padding->left->toPx() - $ps->padding->right->toPx()
                - $ps->borderLeftWidth - $ps->borderRightWidth;
        }
        if ($w <= 0 && $cbW > 0) $w = $cbW;

        // ── Padding ──
        $padL = $style?->padding?->left->toPx() ?? 0;
        $padR = $style?->padding?->right->toPx() ?? 0;
        $padT = $style?->padding?->top->toPx() ?? 0;
        $padB = $style?->padding?->bottom->toPx() ?? 0;
        $contentW = max(0, $w - $padL - $padR);

        // ── 收集行 ──
        $rows = [];
        foreach ($node->children as $child) {
            $cd = $child->computedStyle?->display?->value ?? 'block';
            if ($cd === 'table-row') {
                $rows[] = $child;
            } elseif (in_array($cd, ['table-row-group', 'table-header-group', 'table-footer-group'], true)) {
                foreach ($child->children as $gc) {
                    if (($gc->computedStyle?->display?->value ?? '') === 'table-row') {
                        $rows[] = $gc;
                    }
                }
            }
        }

        // ── 确定列数 ──
        $maxCols = 0;
        foreach ($rows as $row) {
            $cellCount = 0;
            foreach ($row->children as $cell) {
                if (($cell->computedStyle?->display?->value ?? '') === 'table-cell') {
                    $cellCount++;
                }
            }
            if ($cellCount > $maxCols) $maxCols = $cellCount;
        }
        if ($maxCols === 0) $maxCols = 1;
        $colW = max(1, (int)($contentW / $maxCols));

        // ── 布局行和单元格 ──
        $currentY = $parentY + $padT;
        $totalContentH = 0;

        foreach ($rows as $row) {
            $row->x = $parentX + $padL;
            $row->y = $currentY;
            $row->w = $contentW;

            // Layout row children (cells) horizontally
            $cellX = $row->x;
            foreach ($row->children as $cell) {
                if (($cell->computedStyle?->display?->value ?? '') !== 'table-cell') continue;
                $cell->x = $cellX;
                $cell->y = $row->y;
                $cell->w = $colW;
                $cellX += $colW;
            }

            // Row height = max font-size * 1.2
            $rowH = (int)(($style?->fontSize ?? 14) * 1.2);
            foreach ($row->children as $cell) {
                $cell->h = $rowH;
            }
            $row->h = $rowH;

            $currentY += $rowH;
            $totalContentH += $rowH;
        }

        // ── Container height ──
        if ($h <= 0) $h = $padT + $totalContentH + $padB;
        $layer = $style?->zIndex ?? 0;
        if ($layer < $node->layer) $layer = $node->layer;

        $builder
            ->setPosition($parentX, $parentY)
            ->setSize($w, $h <= 0 ? $padT + $totalContentH + $padB : $h, $style)
            ->setLayer($layer)
            ->setContentSize($contentW, $totalContentH);
    }

    /**
     * Resolve display:table-row — 水平排列子代（单元格）
     */
    private function resolveTableRow(
        RenderNode $node,
        int $parentX, int $parentY,
        ?ComputedStyle $style,
        FragmentBuilder $builder
    ): void {
        $node->x = $parentX;
        $node->y = $parentY;

        $cellX = $node->x;
        foreach ($node->children as $cell) {
            if (($cell->computedStyle?->display?->value ?? '') !== 'table-cell') continue;
            $cell->x = $cellX;
            $cell->y = $node->y;
            $cellX += $cell->w;
        }
        $node->w = $cellX - $node->x;

        $builder
            ->setPosition($node->x, $node->y)
            ->setSize($node->w, $node->h, $style)
            ->setLayer($node->layer);
    }

    /**
     * Resolve display:table-cell
     */
    private function resolveTableCell(
        RenderNode $node,
        int $parentX, int $parentY,
        ?ComputedStyle $style,
        FragmentBuilder $builder
    ): void {
        $node->x = $parentX;
        $node->y = $parentY;

        $padL = $style?->padding?->left->toPx() ?? 0;
        $padR = $style?->padding?->right->toPx() ?? 0;
        $padT = $style?->padding?->top->toPx() ?? 0;

        $contentW = max(0, $node->w - $padL - $padR);

        $builder
            ->setPosition($node->x, $node->y)
            ->setSize($node->w, $node->h, $style)
            ->setLayer($node->layer);
    }
}
