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
        // 提取原始定位值（保留 null 用于 right/bottom 的判断）
        // CSS 规范初始值为 'auto'，表示未显式设置
        $leftRaw = ($style['left'] ?? 'auto') !== 'auto' ? $style['left'] : null;
        $topRaw = ($style['top'] ?? 'auto') !== 'auto' ? $style['top'] : null;
        $rightRaw = ($style['right'] ?? 'auto') !== 'auto' ? $style['right'] : null;
        $bottomRaw = ($style['bottom'] ?? 'auto') !== 'auto' ? $style['bottom'] : null;

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

        // CSS Positioned Layout §3.1: containing block = padding box
        // padding box 原点 = ancestor 坐标本身（不含 padding 偏移）
        // padding box 尺寸 = ancestor->w/h + padding 总和
        $ancestorX = ($ancestor !== null) ? $ancestor->x : 0;
        $ancestorY = ($ancestor !== null) ? $ancestor->y : 0;
        $ancestorW = ($ancestor !== null)
            ? $ancestor->w + $ancestorPaddingLeft + $ancestorPaddingRight
            : $viewportW;
        $ancestorH = ($ancestor !== null)
            ? $ancestor->h + $ancestorPaddingTop + $ancestorPaddingBottom
            : $viewportH;

        // CSS 2.2 §10.5: 包含块无显式高度时，top/bottom 百分比按 auto（0）处理
        // fixed 定位的包含块为 viewport，始终有显式高度
        $hasExplicitAncestorH = ($ancestor === null)
            || (array_key_exists('height', $ancestor->style) && $ancestor->style['height'] !== 'auto' && $ancestor->style['height'] !== '')
            || array_key_exists('heightPercent', $ancestor->style);
        $effectiveAncestorH = $hasExplicitAncestorH ? $ancestorH : 0;

        // CSS Positioned Layout §3.1: left/right % 基于包含块宽度，top/bottom % 基于包含块高度
        $left = (int)($leftRaw !== null ? PercentResolver::resolvePercent($style, 'left', 'leftPercent', $ancestorW) : 0);
        $top = (int)($topRaw !== null ? PercentResolver::resolvePercent($style, 'top', 'topPercent', $effectiveAncestorH) : 0);
        $right = $rightRaw !== null ? (int)PercentResolver::resolvePercent($style, 'right', 'rightPercent', $ancestorW) : null;
        $bottom = $bottomRaw !== null ? (int)PercentResolver::resolvePercent($style, 'bottom', 'bottomPercent', $effectiveAncestorH) : null;

        // 使用定位祖先尺寸解析百分比宽高（符合 CSS 规范）
        $width = (int)PercentResolver::resolvePercent($style, 'width', 'widthPercent', $ancestorW);
        $height = (int)PercentResolver::resolvePercent($style, 'height', 'heightPercent', $effectiveAncestorH);

        // CSS 2.2 §8.3, §8.4: margin/padding 百分比基于包含块 content box 宽度
        $ancestorContentW = ($ancestor !== null) ? PercentResolver::resolveContentWidth($ancestor->style, $ancestor->w) : $viewportW;

        // CSS Box Model §7: margin/padding 百分比基于包含块宽度
        $marginLeftRaw = $style['marginLeft'] ?? $style['margin'] ?? null;
        $marginLeft = (int)(($marginLeftRaw === 'auto') ? 0 : PercentResolver::resolveMarginPaddingPercent($style, 'marginLeft', 'marginLeftPercent', $ancestorContentW));

        $marginTopRaw = $style['marginTop'] ?? $style['margin'] ?? null;
        $marginTop = (int)(($marginTopRaw === 'auto') ? 0 : PercentResolver::resolveMarginPaddingPercent($style, 'marginTop', 'marginTopPercent', $ancestorContentW));

        // CSS 2.2 §10.3.7: Stretch-to-fill when left+right both set and width is auto
        if ($leftRaw !== null && $rightRaw !== null && $width <= 0) {
            $width = (int)max(0, $ancestorW - $left - $right - $marginLeft
                - PercentResolver::resolveMarginPaddingPercent($style, 'marginRight', 'marginRightPercent', $ancestorContentW));
        }
        // CSS 2.2 §10.6.4: Same for top+bottom and auto height
        if ($topRaw !== null && $bottomRaw !== null && $height <= 0 && $hasExplicitAncestorH) {
            $height = (int)max(0, $effectiveAncestorH - $top - $bottom - $marginTop
                - PercentResolver::resolveMarginPaddingPercent($style, 'marginBottom', 'marginBottomPercent', $ancestorContentW));
        }

        // Assign computed width/height to node (CSS 2.2 §10.3.7, §10.6.4)
        if ($width > 0) {
            $node->w = (int)$width;
        }
        if ($height > 0) {
            $node->h = (int)$height;
        }

        // Guard: margin:auto resolved later in resolveMarginAuto; treat as 0 here

        $paddingLeft = (int)PercentResolver::resolveMarginPaddingPercent($style, 'paddingLeft', 'paddingLeftPercent', $ancestorContentW);
        $paddingRight = (int)PercentResolver::resolveMarginPaddingPercent($style, 'paddingRight', 'paddingRightPercent', $ancestorContentW);
        $paddingTop = (int)PercentResolver::resolveMarginPaddingPercent($style, 'paddingTop', 'paddingTopPercent', $ancestorContentW);

        // relative: left/top 作为额外偏移（不改变 stack 推进位置）
        $node->x = (int)($ancestorX + $left + $marginLeft);

        $node->y = (int)($ancestorY + $top + $marginTop);

        // right/bottom 替代：相对于 padding box 的右边/下边（CSS Positioned Layout §3.1）
        // position:fixed 时 ancestor=null（视口参考系），使用 $viewportW/$viewportH
        if ($right !== null && ($ancestor !== null || $isFixed)) {
            // 元素右边缘 = padding box 右边界 - right
            // padding box 右边界 = ancestorX + ancestorW（含 padding）
            $rightEdge = $ancestorX + $ancestorW - $right;
            if ($width > 0) {
                $node->x = (int)($rightEdge - $width);
            } else {
                $node->x = (int)($rightEdge - $node->w);
            }

        }

        if ($bottom !== null && ($ancestor !== null || $isFixed)) {
            // 元素下边缘 = padding box 下边界 - bottom
            // padding box 下边界 = ancestorY + ancestorH（含 padding）
            $bottomEdge = $ancestorY + $ancestorH - $bottom;
            if ($height > 0) {
                $node->y = (int)($bottomEdge - $height);
            } else {
                $node->y = (int)($bottomEdge - $node->h);
            }

        }

        // ── margin:auto 水平 + 垂直居中 ──
        // margin:auto 时的父内容区宽度 = content box 宽度
        $parentContentW = ($ancestor !== null) ? (int)max(0, $ancestorContentW) : 0;

        $paddingBottom = (int)PercentResolver::resolveMarginPaddingPercent($style, 'paddingBottom', 'paddingBottomPercent', $ancestorContentW);

        $parentContentH = (int)(($ancestor !== null)
            ? PercentResolver::resolveContentHeight($ancestor->style, $ancestor->h)
            : 0);

        $this->resolveMarginAuto($node, $style, $parentContentW, $parentContentH);

        // Apply translate from animatedStyle

        $translateX = (int)($style['translateX'] ?? 0);

        $translateY = (int)($style['translateY'] ?? 0);

        $node->x += (int)$translateX;

        $node->y += (int)$translateY;

        // ── Set container's own visualW/visualH ──
        $node->visualW = (int)PercentResolver::resolveVisualW($style, $node->w);
        $node->visualH = (int)PercentResolver::resolveVisualH($style, $node->h);

        // Resolve children recursively

        $childOffsetX = (int)($node->x + $paddingLeft);

        $childOffsetY = (int)($node->y + $paddingTop);

        foreach ($node->children as $child) {
            $childCtx = new LayoutContext($childOffsetX, $childOffsetY, $node);
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

        // CSS 2.1 §10.3.3: margin:auto 居中使用的剩余空间 = 父内容宽度 - 子元素完整盒宽度
        $totalBoxW = max($node->w, $node->visualW ?? $node->w);

        if ($isMarginLeftAuto && $isMarginRightAuto && $totalBoxW > 0 && $parentContentW > $totalBoxW && $parentContentW > 0) {
            $remaining = $parentContentW - $totalBoxW;

            $half = (int)($remaining / 2);

            $node->x += $half;

        } elseif ($isMarginLeftAuto && !$isMarginRightAuto && $parentContentW > $totalBoxW && $parentContentW > 0) {
            $remaining = $parentContentW - $totalBoxW;

            $node->x += $remaining;

        }

        // Vertical auto margins: only when both auto (centering)

        $isMarginTopAuto = $style['marginTopAuto'] ?? $marginIsAuto;

        $isMarginBottomAuto = $style['marginBottomAuto'] ?? $marginIsAuto;

        if ($isMarginTopAuto && $isMarginBottomAuto && $node->h > 0 && $parentContentH > $node->h && $parentContentH > 0) {
            $remaining = $parentContentH - $node->h;

            $half = (int)($remaining / 2);

            // Undo previously applied auto margin offset to prevent accumulation on re-resolution
            $prevAutoY = $node->style['_marginAutoOffsetY'] ?? 0;
            $node->y -= $prevAutoY;
            $node->y += $half;
            $node->style['_marginAutoOffsetY'] = $half;

        }
    }
}
