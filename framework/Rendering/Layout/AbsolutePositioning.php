<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\CssLength;
use Px\Rendering\CssStyleHelper;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;

/**
 * AbsolutePositioning — 绝对/固定定位布局
 *
 * CSS Positioned Layout Module Level 3 §3.1-3.2:
 * - position:absolute 的 containing block = 最近定位祖先的 padding box
 * - position:fixed 的 containing block = viewport (0,0)
 *
 * Pure FragmentBuilder 实现，直接使用 LayoutConstraints + ComputedStyle。
 */
class AbsolutePositioning implements AbsoluteStrategy
{
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function resolveAbsolutePositioning(
        RenderNode         $node,
        LayoutConstraints  $constraints,
        ?ComputedStyle     $style,
        FragmentBuilder    $builder
    ): void
    {
        // 直接使用 ComputedStyle 属性（不转 array）
        $leftVal = $style?->left ?? 0;
        $topVal = $style?->top ?? 0;
        $rightVal = $style?->right ?? 0;
        $bottomVal = $style?->bottom ?? 0;

        $pos = $style?->position?->value ?? 'absolute';
        $isFixed = ($pos === 'fixed');

        if ($isFixed) {
            $ancestor = null;
            $rootNode = $this->resolver->getRootNode();
            $viewportW = ($rootNode !== null) ? $rootNode->w : (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0);
            $viewportH = ($rootNode !== null) ? $rootNode->h : (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0);
        } else {
            $this->resolvePositioningAncestor($node);
            $ancestor = $node->positioningAncestor;
            $viewportW = 0;
            $viewportH = 0;
        }

        // 从祖先的 computedStyle 获取 padding/border
        $ancCS = $ancestor?->computedStyle;
        $ancestorPaddingLeft = $ancCS?->padding?->left?->toPx() ?? 0;
        $ancestorPaddingTop = $ancCS?->padding?->top?->toPx() ?? 0;
        $ancestorPaddingRight = $ancCS?->padding?->right?->toPx() ?? 0;
        $ancestorPaddingBottom = $ancCS?->padding?->bottom?->toPx() ?? 0;

        $borderL = $ancCS?->borderLeftWidth ?? 0;
        $borderR = $ancCS?->borderRightWidth ?? 0;
        $borderT = $ancCS?->borderTopWidth ?? 0;
        $borderB = $ancCS?->borderBottomWidth ?? 0;

        $ancestorX = $ancestor?->x ?? 0;
        $ancestorY = $ancestor?->y ?? 0;
        // 祖先宽度可能尚未设置（resolveChildren 先于策略执行），使用 style 回退
        $ancestorRawW = $ancestor?->w ?? 0;
        if ($ancestorRawW <= 0 && $ancCS !== null) {
            $ancestorRawW = $ancCS->width->toPx();
        }
        $ancestorW = $ancestor ? ($ancestorRawW - $borderL - $borderR) : $viewportW;
        $ancestorH = $ancestor ? ($ancestor->h - $borderT - $borderB) : $viewportH;
        if ($ancestorH <= 0 && $ancCS !== null) {
            $ch = $ancCS->height->toPx();
            if ($ch > 0) $ancestorH = $ch - $borderT - $borderB;
        }

        // 布局容器宽高
        $cbW = $ancestor ? ($ancestorW) : $viewportW;

        // 从 style 读取 width/height（CssLength 携带单位信息）
        $width = $style?->width?->toPx() ?? 0;
        $height = $style?->height?->toPx() ?? 0;
        if ($style?->width?->isPercent()) $width = $style->resolveWidth($cbW);
        if ($style?->height?->isPercent()) $height = $style->resolveHeight($ancestorH);

        // 计算边距
        $marginLeft = $style?->margin?->left?->toPx() ?? 0;
        $marginTop = $style?->margin?->top?->toPx() ?? 0;
        $marginRight = $style?->margin?->right?->toPx() ?? 0;
        $marginBottom = $style?->margin?->bottom?->toPx() ?? 0;

        // 如果 left+right 都设置且 width=0，用两者决定宽度
        if ($leftVal !== 0 && $rightVal !== 0 && $width <= 0) {
            $width = max(0, $ancestorW - $leftVal - $rightVal - $marginLeft - $marginRight);
        }
        if ($topVal !== 0 && $bottomVal !== 0 && $height <= 0) {
            $height = max(0, $ancestorH - $topVal - $bottomVal - $marginTop - $marginBottom);
        }

        $hasExplicitW = $width > 0 || ($style?->width?->toPx() ?? 0) > 0;
        $hasExplicitH = $height > 0 || ($style?->height?->toPx() ?? 0) > 0;

        // Auto-size for text content
        if ((!$hasExplicitW || !$hasExplicitH) && $node->content !== null && is_string($node->content) && strlen($node->content) > 0) {
            $fs = $style?->fontSize ?? 14;
            $bd = $style?->bold ?? false;
            $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($node->content, $fs, $bd) : 0);
            if ($measured > 0) {
                $padL = $style?->padding?->left?->toPx() ?? 0;
                $padR = $style?->padding?->right?->toPx() ?? 0;
                $bwL = $style?->borderLeftWidth ?? 0;
                $bwR = $style?->borderRightWidth ?? 0;
                if (!$hasExplicitW) $width = max(0, $measured + $padL + $padR + $bwL + $bwR);
            }
            if (!$hasExplicitH) {
                $lh = $style?->lineHeight ?? (int)($fs * 1.2);
                $padT = $style?->padding?->top?->toPx() ?? 0;
                $padB = $style?->padding?->bottom?->toPx() ?? 0;
                $height = max($lh, $height);
            }
        }

        // 计算最终坐标
        $calcX = $ancestorX + $borderL + $leftVal + $marginLeft;
        $calcY = $ancestorY + $borderT + $topVal + $marginTop;

        // right/bottom 覆盖
        if ($rightVal !== 0 && ($ancestor !== null || $isFixed)) {
            $rightEdge = $ancestorX + $borderL + $ancestorPaddingLeft + $cbW - $rightVal;
            $calcX = $rightEdge - ($width > 0 ? $width : 0);
        }
        if ($bottomVal !== 0 && ($ancestor !== null || $isFixed)) {
            $bottomEdge = $ancestorY + $ancestorH - $bottomVal;
            $calcY = $bottomEdge - ($height > 0 ? $height : 0);
        }

        // translate
        $rawTX = $style?->getRaw('translateX');
        $rawTY = $style?->getRaw('translateY');
        $calcX += $rawTX instanceof CssLength ? $rawTX->toPx() : (int)($rawTX ?? 0);
        $calcY += $rawTY instanceof CssLength ? $rawTY->toPx() : (int)($rawTY ?? 0);

        $builder
            ->setPosition($calcX, $calcY)
            ->setSize($width > 0 ? $width : 0, $height > 0 ? $height : 0, $style)
            ->setLayer($constraints->containerWidth > 0 ? 1 : 0);
    }

    public function resolveMarginAuto(RenderNode $node, ?ComputedStyle $style, int $parentContentW, int $parentContentH = 0): void
    {
        $isMarginLeftAuto = false;
        $isMarginRightAuto = false;

        $rawMargin = $style?->getRaw('margin');
        if (is_string($rawMargin) && strtolower(trim($rawMargin)) === 'auto') {
            $isMarginLeftAuto = true;
            $isMarginRightAuto = true;
        }
        if ($style?->getRaw('marginLeftAuto') ?? false) $isMarginLeftAuto = true;
        if ($style?->getRaw('marginRightAuto') ?? false) $isMarginRightAuto = true;

        $totalBoxW = max($node->w, $node->visualW ?? $node->w);

        if ($isMarginLeftAuto && $isMarginRightAuto && $totalBoxW > 0 && $parentContentW > $totalBoxW) {
            $remaining = $parentContentW - $totalBoxW;
            $half = (int)(($remaining + 1) / 2);
            $node->x += $half;
        } elseif ($isMarginLeftAuto && !$isMarginRightAuto && $parentContentW > $totalBoxW) {
            $node->x += $parentContentW - $totalBoxW;
        }

        // Vertical auto margin
        $isMarginTopAuto = $style?->getRaw('marginTopAuto') ?? false;
        $isMarginBottomAuto = $style?->getRaw('marginBottomAuto') ?? false;

        if ($isMarginTopAuto && $isMarginBottomAuto && $node->h > 0 && $parentContentH > $node->h) {
            $remaining = $parentContentH - $node->h;
            $half = (int)($remaining / 2);
            $node->y += $half;
        }
    }

    private function resolvePositioningAncestor(RenderNode $node): void
    {
        if ($node->positioningAncestorValid) return;

        $ancestor = $node->parent;
        while ($ancestor !== null) {
            $pos = $ancestor->computedStyle?->position?->value ?? 'static';
            if ($pos !== 'static') {
                $node->positioningAncestor = $ancestor;
                $node->positioningAncestorValid = true;
                return;
            }
            $ancestor = $ancestor->parent;
        }
        $node->positioningAncestor = null;
        $node->positioningAncestorValid = true;
    }
}
