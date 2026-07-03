<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\ComputedStyle;

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
 * Pure FragmentBuilder 实现，直接使用 LayoutConstraints + ComputedStyle。
 */
class MultiColumnLayoutStrategy implements LayoutStrategyInterface
{
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Pure FragmentBuilder 布局入口。
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

        $columnCount = $style?->columnCount ?? 0;
        $rawColWidth = $style?->columnWidth;
        $columnWidth = $rawColWidth instanceof \Px\Rendering\CssLength ? $rawColWidth->toPx() : (int)($rawColWidth ?? 0);
        $rawColGap = $style?->columnGap;
        $columnGap = $rawColGap instanceof \Px\Rendering\CssLength ? $rawColGap->toPx() : (int)($rawColGap ?? 0);
        if ($columnGap <= 0) $columnGap = 16;

        // fallback to block if not multi-column
        if ($columnCount <= 0 && $columnWidth <= 0) {
            $w = $style?->width->toPx() ?? 0;
            $h = $style?->height->toPx() ?? 0;
            $builder->setPosition($parentX, $parentY)->setSize($w, $h, $style)->setLayer($node->layer);
            return;
        }

        // ── 容器尺寸 ──
        $w = $style?->width->toPx() ?? 0;
        $h = $style?->height->toPx() ?? 0;

        // 从父容器获取 content-box 宽度
        $parent = $node->parent;
        if ($w <= 0 && $parent !== null && $parent->computedStyle !== null) {
            $ps = $parent->computedStyle;
            $cpW = $parent->w - $ps->padding->left->toPx() - $ps->padding->right->toPx()
                   - $ps->borderLeftWidth - $ps->borderRightWidth;
            if ($cpW > $w) $w = $cpW;
        }
        if ($w <= 0) $w = $constraints->contentWidth;

        // ── Padding ──
        $padL = $style?->padding?->left->toPx() ?? 0;
        $padR = $style?->padding?->right->toPx() ?? 0;
        $padT = $style?->padding?->top->toPx() ?? 0;
        $padB = $style?->padding?->bottom->toPx() ?? 0;
        $contentW = max(0, $w - $padL - $padR);

        // ── Column count/width ──
        if ($columnCount > 0) {
            $effectiveColW = max(1, (int)(($contentW - ($columnCount - 1) * $columnGap) / $columnCount));
        } elseif ($columnWidth > 0) {
            $effectiveColW = $columnWidth;
            $columnCount = max(1, (int)(($contentW + $columnGap) / ($columnWidth + $columnGap)));
        } else {
            $effectiveColW = $contentW;
            $columnCount = 1;
        }

        // ── Layout children ──
        $children = $node->children;
        $childCount = count($children);

        $baseX = $parentX + $padL;
        $baseY = $parentY + $padT;

        if ($childCount === 0) {
            $builder->setPosition($parentX, $parentY)->setSize($w, $h > 0 ? $h : 0, $style)->setLayer($node->layer);
            return;
        }

        if ($h > 0) {
            // Fixed height: fill columns
            $currentCol = 0;
            $currentY = $baseY;

            foreach ($children as $child) {
                $childX = $baseX + $currentCol * ($effectiveColW + $columnGap);
                $child->x = $childX;
                $child->y = $currentY;
                $child->w = $effectiveColW;

                $currentY += $child->h;

                // Move to next column if overflowing
                if ($currentY > $baseY + $h && $currentCol < $columnCount - 1) {
                    $currentCol++;
                    $currentY = $baseY;
                }
            }
        } else {
            // Auto height: distribute children evenly
            if ($childCount <= $columnCount) {
                $colIdx = 0;
                foreach ($children as $child) {
                    $child->x = $baseX + $colIdx * ($effectiveColW + $columnGap);
                    $child->y = $baseY;
                    $child->w = $effectiveColW;
                    $colIdx++;
                }
            } else {
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
                        $child->w = $effectiveColW;
                        $colY += $child->h;
                    }
                    $startIdx += $colChildCount;
                }
            }
        }

        // ── Container height ──
        if ($h <= 0) {
            $maxColH = 0;
            foreach ($children as $child) {
                $childBottom = $child->y + $child->h;
                $colH = $childBottom - $baseY;
                if ($colH > $maxColH) $maxColH = $colH;
            }
            $h = $padT + $maxColH + $padB;
        }

        // ── Sync child coordinates to builder fragments ──
        $originalChildren = $builder->getChildren();
        $syncedChildren = [];
        foreach ($node->children as $i => $ch) {
            $orig = $originalChildren[$i] ?? null;
            $syncedChildren[] = new LayoutFragment(
                x: $ch->x,
                y: $ch->y,
                w: $ch->w,
                h: $ch->h,
                visualW: $ch->visualW,
                visualH: $ch->visualH,
                layer: $orig?->layer ?? 0,
                contentWidth: $orig?->contentWidth ?? 0,
                contentHeight: $orig?->contentHeight ?? 0,
                style: $ch->computedStyle,
                children: $orig?->children ?? [],
            );
        }
        $builder->replaceChildren($syncedChildren);

        $builder
            ->setPosition($parentX, $parentY)
            ->setSize($w, $h, $style)
            ->setLayer($node->layer)
            ->setContentSize($contentW, $h);
    }
}
