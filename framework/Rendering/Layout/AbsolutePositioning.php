<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\CssStyleHelper;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;

/**
 * AbsolutePositioning — 绝对/固定定位布局
 *
 * Phase 3: 使用 FragmentBuilder 写入布局结果，不直接修改 RenderNode.x/y/w/h。
 * CSS Positioned Layout Module Level 3 §3.1-3.2:
 * - position:absolute 的 containing block = 最近定位祖先的 padding box
 * - position:fixed 的 containing block = viewport (0,0)
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
        $styleArr = $node->style; // backward compat via __get

        // 提取原始定位值
        $leftRaw = ($styleArr['left'] ?? 'auto') !== 'auto' ? $styleArr['left'] : null;
        $topRaw = ($styleArr['top'] ?? 'auto') !== 'auto' ? $styleArr['top'] : null;
        $rightRaw = ($styleArr['right'] ?? 'auto') !== 'auto' ? $styleArr['right'] : null;
        $bottomRaw = ($styleArr['bottom'] ?? 'auto') !== 'auto' ? $styleArr['bottom'] : null;

        $pos = $styleArr['position'] ?? 'absolute';
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

        $ancestorPaddingLeft = ($ancestor !== null) ? (int)($ancestor->style['paddingLeft'] ?? $ancestor->style['padding'] ?? 0) : 0;
        $ancestorPaddingTop = ($ancestor !== null) ? (int)($ancestor->style['paddingTop'] ?? $ancestor->style['padding'] ?? 0) : 0;
        $ancestorPaddingRight = ($ancestor !== null) ? (int)($ancestor->style['paddingRight'] ?? $ancestor->style['padding'] ?? 0) : 0;
        $ancestorPaddingBottom = ($ancestor !== null) ? (int)($ancestor->style['paddingBottom'] ?? $ancestor->style['padding'] ?? 0) : 0;

        $borderL = ($ancestor !== null) ? (int)($ancestor->style['borderLeftWidth'] ?? $ancestor->style['borderWidth'] ?? 0) : 0;
        $borderR = ($ancestor !== null) ? (int)($ancestor->style['borderRightWidth'] ?? $ancestor->style['borderWidth'] ?? 0) : 0;
        $borderT = ($ancestor !== null) ? (int)($ancestor->style['borderTopWidth'] ?? $ancestor->style['borderWidth'] ?? 0) : 0;
        $borderB = ($ancestor !== null) ? (int)($ancestor->style['borderBottomWidth'] ?? $ancestor->style['borderWidth'] ?? 0) : 0;
        $ancestorX = ($ancestor !== null) ? $ancestor->x : 0;
        $ancestorY = ($ancestor !== null) ? $ancestor->y : 0;

        $ancestorW = ($ancestor !== null) ? $ancestor->visualW - $borderL - $borderR : $viewportW;
        $ancestorH = ($ancestor !== null) ? $ancestor->visualH - $borderT - $borderB : $viewportH;

        $hasExplicitAncestorH = ($ancestor === null)
            || (array_key_exists('height', $ancestor->style) && $ancestor->style['height'] !== 'auto' && $ancestor->style['height'] !== '')
            || array_key_exists('heightPercent', $ancestor->style);
        $effectiveAncestorH = $hasExplicitAncestorH ? $ancestorH : 0;

        // 计算 left/top/width/height
        $left = (int)($leftRaw !== null ? CssStyleHelper::resolveWithCalc($styleArr, 'left', $ancestorW) : 0);
        $top = (int)($topRaw !== null ? CssStyleHelper::resolveWithCalc($styleArr, 'top', $effectiveAncestorH) : 0);
        $right = $rightRaw !== null ? (int)CssStyleHelper::resolveWithCalc($styleArr, 'right', $ancestorW) : null;
        $bottom = $bottomRaw !== null ? (int)CssStyleHelper::resolveWithCalc($styleArr, 'bottom', $effectiveAncestorH) : null;

        $width = (int)CssStyleHelper::resolveWithCalc($styleArr, 'width', $ancestorW);
        $height = (int)CssStyleHelper::resolveWithCalc($styleArr, 'height', $effectiveAncestorH);

        $ancestorContentW = ($ancestor !== null) ? CssStyleHelper::contentBoxWidth($ancestor->style, $ancestor->w) : $viewportW;

        $marginLeftRaw = $styleArr['marginLeft'] ?? $styleArr['margin'] ?? null;
        $marginLeft = (int)(($marginLeftRaw === 'auto') ? 0 : CssStyleHelper::resolveLength($styleArr, 'marginLeft', $ancestorContentW));
        $marginTopRaw = $styleArr['marginTop'] ?? $styleArr['margin'] ?? null;
        $marginTop = (int)(($marginTopRaw === 'auto') ? 0 : CssStyleHelper::resolveLength($styleArr, 'marginTop', $ancestorContentW));

        if ($leftRaw !== null && $rightRaw !== null && $width <= 0) {
            $width = (int)max(0, $ancestorW - $left - $right - $marginLeft
                - CssStyleHelper::resolveLength($styleArr, 'marginRight', $ancestorContentW));
        }
        if ($topRaw !== null && $bottomRaw !== null && $height <= 0 && $hasExplicitAncestorH) {
            $height = (int)max(0, $effectiveAncestorH - $top - $bottom - $marginTop
                - CssStyleHelper::resolveLength($styleArr, 'marginBottom', $ancestorContentW));
        }

        $hasExplicitW = $width > 0;
        $hasExplicitH = $height > 0;

        // Auto-size for text content
        if ((!$hasExplicitW || !$hasExplicitH) && $node->content !== null && is_string($node->content) && strlen($node->content) > 0) {
            $fs = (int)($node->style['fontSize']);
            $bd = ($styleArr['bold'] ?? 0) !== 0;
            $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($node->content, $fs, $bd) : 0);
            if ($measured > 0) {
                $padL = (int)($styleArr['paddingLeft'] ?? $styleArr['padding'] ?? 0);
                $padR = (int)($styleArr['paddingRight'] ?? $styleArr['padding'] ?? 0);
                $bwL = (int)($styleArr['borderLeftWidth'] ?? $styleArr['borderWidth'] ?? 0);
                $bwR = (int)($styleArr['borderRightWidth'] ?? $styleArr['borderWidth'] ?? 0);
                $autoW = $measured + $padL + $padR + $bwL + $bwR;
                if (!$hasExplicitW) {
                    $width = (int)max(0, (int)CssStyleHelper::applyMinMax($styleArr, $autoW, true));
                }
            }
            if (!$hasExplicitH) {
                $parentStyle = $constraints->parentContentY !== 0 ? $styleArr : [];
                $lineH = CssStyleHelper::lineHeight($styleArr, $fs, 16, $parentStyle);
                $padT = (int)($styleArr['paddingTop'] ?? $styleArr['padding'] ?? 0);
                $padB = (int)($styleArr['paddingBottom'] ?? $styleArr['padding'] ?? 0);
                $bwT = (int)($styleArr['borderTopWidth'] ?? $styleArr['borderWidth'] ?? 0);
                $bwB = (int)($styleArr['borderBottomWidth'] ?? $styleArr['borderWidth'] ?? 0);
                $height = max($lineH, $height);
            }
        }

        $paddingLeft = (int)CssStyleHelper::resolveLength($styleArr, 'paddingLeft', $ancestorContentW);
        $paddingRight = (int)CssStyleHelper::resolveLength($styleArr, 'paddingRight', $ancestorContentW);
        $paddingTop = (int)CssStyleHelper::resolveLength($styleArr, 'paddingTop', $ancestorContentW);

        // 计算最终 x/y（核心位置计算）
        $calcX = (int)($ancestorX + $borderL + $left + $marginLeft);
        $calcY = (int)($ancestorY + $borderT + $top + $marginTop);

        if ($right !== null && ($ancestor !== null || $isFixed)) {
            $rightEdge = $ancestorX + $borderL + $ancestorPaddingLeft + $ancestorContentW - $right;
            $calcX = (int)($rightEdge - ($width > 0 ? $width : 0));
        }
        if ($bottom !== null && ($ancestor !== null || $isFixed)) {
            $bottomEdge = $ancestorY + $ancestorH - $bottom;
            $calcY = (int)($bottomEdge - ($height > 0 ? $height : 0));
        }

        // margin:auto 居中
        $parentContentW = ($ancestor !== null) ? (int)max(0, $ancestorContentW) : 0;
        $paddingBottom = (int)CssStyleHelper::resolveLength($styleArr, 'paddingBottom', $ancestorContentW);
        $parentContentH = (int)(($ancestor !== null)
            ? CssStyleHelper::contentBoxHeight($ancestor->style, $ancestor->h) : 0);

        // Apply translate
        $translateX = 0;
        $translateY = 0;
        $transform = $styleArr['transform'] ?? null;
        if (is_array($transform)) {
            $translateX = (int)($transform['translateX'] ?? 0);
            $translateY = (int)($transform['translateY'] ?? 0);
        }
        if (!isset($styleArr['transform'])) {
            $translateX = (int)($styleArr['translateX'] ?? $translateX);
            $translateY = (int)($styleArr['translateY'] ?? $translateY);
        }

        $calcX += $translateX;
        $calcY += $translateY;

        // 通过 FragmentBuilder 写入布局结果
        $builder
            ->setPosition($calcX, $calcY)
            ->setSize($width > 0 ? $width : 0, $height > 0 ? $height : 0, $style)
            ->setLayer($constraints->containerWidth > 0 ? 1 : 0);

        // margin:auto 居中后调整
        // （注意：resolveMarginAuto 目前仍直接写 $node->x，需后续迁移）
    }

    public function resolveMarginAuto(RenderNode $node, ?ComputedStyle $style, int $parentContentW, int $parentContentH = 0): void
    {
        $styleArr = $style !== null ? $style->toExportArray() : [];

        $marginIsAuto = ($styleArr['margin'] ?? '') === 'auto';
        $isMarginLeftAuto = $styleArr['marginLeftAuto'] ?? $marginIsAuto;
        $isMarginRightAuto = $styleArr['marginRightAuto'] ?? $marginIsAuto;

        $prevOffsetX = $node->style['_marginAutoOffsetX'] ?? 0;
        if ($prevOffsetX !== 0) {
            $node->x -= $prevOffsetX;
        }

        $totalBoxW = max($node->w, $node->visualW ?? $node->w);
        $appliedOffset = 0;

        if ($isMarginLeftAuto && $isMarginRightAuto && $totalBoxW > 0 && $parentContentW > $totalBoxW && $parentContentW > 0) {
            $remaining = $parentContentW - $totalBoxW;
            $half = (int)(($remaining + 1) / 2);
            $node->x += $half;
            $appliedOffset = $half;
            $node->style['_computedMarginLeft'] = $half;
            $node->style['_computedMarginRight'] = $remaining - $half;
        } elseif ($isMarginLeftAuto && !$isMarginRightAuto && $parentContentW > $totalBoxW && $parentContentW > 0) {
            $remaining = $parentContentW - $totalBoxW;
            $node->x += $remaining;
            $appliedOffset = $remaining;
            $node->style['_computedMarginLeft'] = $remaining;
        } elseif (!$isMarginLeftAuto && $isMarginRightAuto && $parentContentW > $totalBoxW && $parentContentW > 0) {
            $node->style['_computedMarginRight'] = $parentContentW - $totalBoxW;
        }
        $node->style['_marginAutoOffsetX'] = $appliedOffset;

        $isMarginTopAuto = $styleArr['marginTopAuto'] ?? $marginIsAuto;
        $isMarginBottomAuto = $styleArr['marginBottomAuto'] ?? $marginIsAuto;

        if ($isMarginTopAuto && $isMarginBottomAuto && $node->h > 0 && $parentContentH > $node->h && $parentContentH > 0) {
            $remaining = $parentContentH - $node->h;
            $half = (int)($remaining / 2);
            $prevAutoY = $node->style['_marginAutoOffsetY'] ?? 0;
            $node->y -= $prevAutoY;
            $node->y += $half;
            $node->style['_marginAutoOffsetY'] = $half;
        }
    }

    private function resolvePositioningAncestor(RenderNode $node): void
    {
        if ($node->positioningAncestorValid) return;

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
        $node->positioningAncestor = null;
        $node->positioningAncestorValid = true;
    }
}
