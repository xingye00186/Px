<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\Layout\Tools\PercentResolver;

/**
 * AbsolutePositioning — 绝对/固定定位布局
 *
 * CSS Positioned Layout Module Level 3 §3.1-3.2:
 * - position:absolute 的 containing block = 最近定位祖先的 padding box
 * - position:fixed 的 containing block = viewport (0,0)
 *
 * 根据 CSS 规范实现绝对/fixed 定位、定位祖先查找、margin:auto 居中。
 */
class AbsolutePositioning implements AbsoluteStrategy
{
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Absolute/fixed positioning.
     *
     * 使用 positioningAncestor（position != static 的最近祖先）作为参考系。
     * left/top 相对定位祖先的 padding box 偏移。
     * right/bottom 替代（当 left/top 未设时）。
     * position:fixed v1 退化为 absolute（TODO v2: viewport 参考系）。
     */
    public function resolveAbsolutePositioning(
        RenderNode    $node,
        LayoutContext $ctx,
        array         $style
    ): void
    {
        // 提取定位值
        $left = (int)($style['left'] ?? 0);
        $top = (int)($style['top'] ?? 0);
        $right = $style['right'] ?? null;
        $bottom = $style['bottom'] ?? null;

        // 判断定位模式：fixed vs absolute
        $pos = $style['position'] ?? 'absolute';

        $isFixed = ($pos === 'fixed');

        if ($isFixed) {
            // CSS Positioned Layout §3.2: fixed 的 containing block = viewport (0,0)
            // 不使用定位祖先：坐标相对于视口，不受任何祖先滚动影响
            // 但 right/bottom 需要视口尺寸，从 rootNode 获取
            $ancestor = null;
            $rootNode = $this->resolver->getRootNode();
            $viewportW = ($rootNode !== null) ? $rootNode->w : (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0);
            $viewportH = ($rootNode !== null) ? $rootNode->h : (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0);

        } else {
            // position:absolute — 查找并缓存定位祖先
            $this->resolvePositioningAncestor($node);

            $ancestor = $node->positioningAncestor;
            $viewportW = 0;
            $viewportH = 0;

        }

        // 参考系：定位祖先的 padding box（CSS Positioned Layout §3.1），退化时用 (0,0)
        $ancestorPaddingLeft = ($ancestor !== null) ? (int)($ancestor->style['paddingLeft'] ?? $ancestor->style['padding'] ?? 0) : 0;
        $ancestorPaddingTop = ($ancestor !== null) ? (int)($ancestor->style['paddingTop'] ?? $ancestor->style['padding'] ?? 0) : 0;
        $ancestorPaddingRight = ($ancestor !== null) ? (int)($ancestor->style['paddingRight'] ?? $ancestor->style['padding'] ?? 0) : 0;
        $ancestorPaddingBottom = ($ancestor !== null) ? (int)($ancestor->style['paddingBottom'] ?? $ancestor->style['padding'] ?? 0) : 0;

        $ancestorX = ($ancestor !== null) ? $ancestor->x + $ancestorPaddingLeft : 0;
        $ancestorY = ($ancestor !== null) ? $ancestor->y + $ancestorPaddingTop : 0;
        $ancestorW = ($ancestor !== null) ? $ancestor->w : $viewportW;
        $ancestorH = ($ancestor !== null) ? $ancestor->h : $viewportH;

        // 使用定位祖先尺寸解析百分比宽高（符合 CSS 规范）
        $width = PercentResolver::resolvePercent($style, 'width', 'widthPercent', $ancestorW);
        $height = PercentResolver::resolvePercent($style, 'height', 'heightPercent', $ancestorH);

        // CSS 2.2 §8.3, §8.4: margin/padding 百分比基于包含块 content box 宽度
        $ancestorContentW = ($ancestor !== null) ? PercentResolver::resolveContentWidth($ancestor->style, $ancestor->w) : $viewportW;

        // CSS Box Model §7: margin/padding 百分比基于包含块宽度
        $marginLeftRaw = $style['marginLeft'] ?? $style['margin'] ?? null;
        $marginLeft = ($marginLeftRaw === 'auto') ? 0 : PercentResolver::resolveMarginPaddingPercent($style, 'marginLeft', 'marginLeftPercent', $ancestorContentW);

        $marginTopRaw = $style['marginTop'] ?? $style['margin'] ?? null;
        $marginTop = ($marginTopRaw === 'auto') ? 0 : PercentResolver::resolveMarginPaddingPercent($style, 'marginTop', 'marginTopPercent', $ancestorContentW);

        // Guard: margin:auto resolved later in resolveMarginAuto; treat as 0 here

        $paddingLeft = PercentResolver::resolveMarginPaddingPercent($style, 'paddingLeft', 'paddingLeftPercent', $ancestorContentW);
        $paddingRight = PercentResolver::resolveMarginPaddingPercent($style, 'paddingRight', 'paddingRightPercent', $ancestorContentW);
        $paddingTop = PercentResolver::resolveMarginPaddingPercent($style, 'paddingTop', 'paddingTopPercent', $ancestorContentW);

        // relative: left/top 作为额外偏移（不改变 stack 推进位置）
        $node->x = $ancestorX + $left + $marginLeft;

        $node->y = $ancestorY + $top + $marginTop;

        // right/bottom 替代：相对于 padding box 的右边/下边（CSS Positioned Layout §3.1）
        // position:fixed 时 ancestor=null（视口参考系），使用 $viewportW/$viewportH
        if ($right !== null && ($ancestor !== null || $isFixed)) {
            // 元素右边缘 = padding box 右边界 - right - paddingRight
            $rightEdge = $ancestorX + $ancestorW - $ancestorPaddingLeft - $ancestorPaddingRight - $right;
            if ($width > 0) {
                $node->x = $rightEdge - $width;
            } else {
                $node->x = $rightEdge - $node->w;
            }

        }

        if ($bottom !== null && ($ancestor !== null || $isFixed)) {
            // 元素下边缘 = padding box 下边界 - bottom - paddingBottom
            $bottomEdge = $ancestorY + $ancestorH - $ancestorPaddingTop - $ancestorPaddingBottom - $bottom;
            if ($height > 0) {
                $node->y = $bottomEdge - $height;
            } else {
                $node->y = $bottomEdge - $node->h;
            }

        }

        // ── margin:auto 水平 + 垂直居中 ──
        // margin:auto 时的父内容区宽度 = content box 宽度
        $parentContentW = ($ancestor !== null) ? max(0, $ancestorContentW) : 0;

        $paddingBottom = PercentResolver::resolveMarginPaddingPercent($style, 'paddingBottom', 'paddingBottomPercent', $ancestorContentW);

        $parentContentH = ($ancestor !== null) ? (int)max(0, $ancestorH - $paddingTop - $paddingBottom) : 0;

        $this->resolveMarginAuto($node, $style, $parentContentW, $parentContentH);

        // Apply translate from animatedStyle

        $translateX = (int)($style['translateX'] ?? 0);

        $translateY = (int)($style['translateY'] ?? 0);

        $node->x += $translateX;

        $node->y += $translateY;

        // Resolve children recursively

        $childOffsetX = $node->x + $paddingLeft;

        $childOffsetY = $node->y + $paddingTop;

        foreach ($node->children as $child) {
            $childCtx = new LayoutContext($childOffsetX, $childOffsetY, $node, refval($ctx->scrollContainers));
            $this->resolver->resolveNode($child, $childCtx);
        }
    }

    /**
     * 查找并缓存节点的定位祖先（position != static 的最近祖先）。
     *
     * 为 position:absolute/fixed 提供 containing block 参考系。
     * 如果缓存有效（positioningAncestorValid === true）则跳过。
     * 从 parent 链向上遍历，找第一个 position !== static 的祖先。
     * 找不到时 positioningAncestor = null（退化为根节点 (0,0) 参考系）。
     *
     * AOT 兼容: 纯属性访问 + while 循环，符合 native_types。
     */
    private function resolvePositioningAncestor(RenderNode $node): void
    {
        if ($node->positioningAncestorValid) {
            return;
        }

        $ancestor = $node->parent;

        while ($ancestor !== null) {
            $pos = $ancestor->style['position'] ?? 'static';

            if ($pos !== 'static') {
                $node->positioningAncestor = $ancestor;

                $node->positioningAncestorValid = true;

                return;
            }

            $ancestor = $ancestor->parent;
        }

        // 找不到定位祖先 → 退化为 null（根节点 (0,0) 参考系）
        $node->positioningAncestor = null;

        $node->positioningAncestorValid = true;
    }

    /**
     * 解析 margin:auto 水平居中及垂直居中。
     * CSS 规范 10.6.2: margin-top/bottom:auto 在 normal flow 中使用值 0。
     * CSS 规范 10.6.4: 绝对定位元素 top+bottom+height 非 auto 时，
     *                 auto margin 吸收剩余空间均分（垂直居中）。
     * 算法: 剩余空间 = (父 content size - 子 size) / 2，各分一半。
     * AOT 兼容: 纯属性访问 + while 循环，符合 native_types。
     */
    public function resolveMarginAuto(RenderNode $node, array $style, int $parentContentW, int $parentContentH = 0): void
    {
        // Fallback: if raw 'margin'=>'auto' is set but flags aren't parsed (direct style array), treat all as auto
        $marginIsAuto = ($style['margin'] ?? '') === 'auto';

        $isMarginLeftAuto = $style['marginLeftAuto'] ?? $marginIsAuto;

        $isMarginRightAuto = $style['marginRightAuto'] ?? $marginIsAuto;

        if ($isMarginLeftAuto && $isMarginRightAuto && $node->w > 0 && $parentContentW > $node->w && $parentContentW > 0) {
            $remaining = $parentContentW - $node->w;

            $half = (int)($remaining / 2);

            $node->x += $half;

        } elseif ($isMarginLeftAuto && !$isMarginRightAuto && $parentContentW > $node->w && $parentContentW > 0) {
            $remaining = $parentContentW - $node->w;

            $node->x += $remaining;

        }

        // Vertical auto margins: only when both auto (centering)

        $isMarginTopAuto = $style['marginTopAuto'] ?? $marginIsAuto;

        $isMarginBottomAuto = $style['marginBottomAuto'] ?? $marginIsAuto;

        if ($isMarginTopAuto && $isMarginBottomAuto && $node->h > 0 && $parentContentH > $node->h && $parentContentH > 0) {
            $remaining = $parentContentH - $node->h;

            $half = (int)($remaining / 2);

            $node->y += $half;

        }
    }
}
