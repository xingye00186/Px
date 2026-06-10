<?php

namespace Px\Rendering\Layout;

use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;

/**
 * MultiColumnLayoutStrategy — CSS 多列布局（CSS Multi-column Layout Module Level 1）
 *
 * 简化实现：
 *   1. column-count 指定列数
 *   2. column-width 指定列宽（用于弹性列数）
 *   3. column-gap 指定列间距（默认 16px）
 *   4. 内容在列间流动（从上到下，再从左到右）
 *   5. 列高度由容器高度或内容高度决定
 *
 * 未实现的特性：
 *   - column-rule（列分隔线）
 *   - column-span（跨列）
 *   - 列平衡算法（简化版固定列高由最长列决定）
 */
class MultiColumnLayoutStrategy implements LayoutStrategyInterface
{
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function resolve(RenderNode $node, LayoutContext $ctx, array $style): void
    {
        $display = $style['display'] ?? 'block';

        // 仅对 display: multi-column 或设置了 column-count/column-width 的元素执行
        $columnCount = (int)($style['columnCount'] ?? 0);
        $columnWidth = (int)($style['columnWidth'] ?? 0);
        $columnGap = (int)($style['columnGap'] ?? 16);

        // 默认 column-count=2 如果 column-width 也未指定
        if ($columnCount <= 0 && $columnWidth <= 0) {
            // Not a multi-column layout — fallback
            $blockStrategy = $this->resolver->getBlockStrategy();
            $blockStrategy->resolve($node, $ctx, $style);
            return;
        }

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

        // ── Padding ──
        $padL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
        $padR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);
        $padT = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
        $padB = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);

        $contentW = $w - $padL - $padR;
        if ($contentW < 0) $contentW = 0;

        // ── Determine column count and column width ──
        // CSS Multi-column: if column-count is set, use it to derive column width
        // If column-width is set, use it to derive column count
        if ($columnCount > 0) {
            // Fixed column count → calculate column width
            $effectiveColW = (int)(($contentW - ($columnCount - 1) * $columnGap) / $columnCount);
            if ($effectiveColW < 1) $effectiveColW = 1;
        } elseif ($columnWidth > 0) {
            // Fixed column width → calculate column count
            $effectiveColW = $columnWidth;
            $columnCount = max(1, (int)(($contentW + $columnGap) / ($columnWidth + $columnGap)));
        } else {
            $effectiveColW = $contentW;
            $columnCount = 1;
        }

        // ── Layout children in columns ──
        // Strategy: distribute children evenly across columns
        // Each column acts as a mini block layout
        $children = $node->children;
        $childCount = count($children);

        if ($childCount === 0) {
            $node->h = $h > 0 ? $h : 0;
            $node->visualW = $w;
            $node->visualH = $node->h;
            return;
        }

        // ── Calculate column layout ──
        // If explicit height is set, fill columns to that height
        // Otherwise, calculate based on content

        $baseX = $node->x + $padL;
        $baseY = $node->y + $padT;

        if ($h > 0) {
            // Fixed height: children fill columns to fixed height, then overflow to next column
            $currentCol = 0;
            $currentY = $baseY;
            $columnHeights = [];

            foreach ($children as $child) {
                // Check if we need to move to next column
                if ($currentY + $child->h > $baseY + $h && $currentCol < $columnCount - 1) {
                    $currentCol++;
                    $currentY = $baseY;
                }

                $colX = $baseX + $currentCol * ($effectiveColW + $columnGap);
                $child->x = $colX;
                $child->y = $currentY;

                // Resolve child first, then force column width
                $childCtx = new LayoutContext($colX, $currentY, $node);
                $this->resolver->resolveNode($child, $childCtx);
                $child->w = $effectiveColW;

                $currentY += $child->h;
                if (!isset($columnHeights[$currentCol])) {
                    $columnHeights[$currentCol] = 0;
                }
                if ($currentY - $baseY > $columnHeights[$currentCol]) {
                    $columnHeights[$currentCol] = $currentY - $baseY;
                }
            }
        } else {
            // Auto height: distribute children across columns as evenly as possible
            if ($childCount <= $columnCount) {
                // Fewer children than columns: one per column
                $colIdx = 0;
                foreach ($children as $child) {
                    $colX = $baseX + $colIdx * ($effectiveColW + $columnGap);
                    $child->x = $colX;
                    $child->y = $baseY;

                    $childCtx = new LayoutContext($colX, $baseY, $node);
                    $this->resolver->resolveNode($child, $childCtx);
                    $child->w = $effectiveColW;
                    $colIdx++;
                }
            } else {
                // More children than columns: distribute evenly
                $baseCount = (int)($childCount / $columnCount);
                $remainder = $childCount % $columnCount;
                $startIdx = 0;

                for ($colIdx = 0; $colIdx < $columnCount; $colIdx++) {
                    $colChildCount = $baseCount + ($colIdx < $remainder ? 1 : 0);
                    $colX = $baseX + $colIdx * ($effectiveColW + $columnGap);
                    $colY = $baseY;

                    for ($i = 0; $i < $colChildCount && $startIdx + $i < $childCount; $i++) {
                        $child = $children[$startIdx + $i];
                        $child->x = $colX;
                        $child->y = $colY;

                        $childCtx = new LayoutContext($colX, $colY, $node);
                        $this->resolver->resolveNode($child, $childCtx);
                        $child->w = $effectiveColW;
                        $colY += $child->h;
                    }
                    $startIdx += $colChildCount;
                }
            }
        }

        // ── Container height ──
        if ($h <= 0) {
            // Auto height: find the maximum column height
            $maxColH = $padT + $padB;
            $colIdx = 0;
            $colY = $baseY;

            foreach ($children as $child) {
                // Track column position
                $childBottom = $child->y + $child->h;
                $colH = $childBottom - $baseY + $padB;
                if ($colH > $maxColH) $maxColH = $colH;
            }

            $h = $maxColH;
        }

        if ($h < $minH) $h = $minH;
        if ($maxH > 0 && $h > $maxH) $h = $maxH;

        $node->h = $h;
        $node->visualW = $w;
        $node->visualH = $h;
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
