<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\CssLength;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;

/**
 * AbsolutePositioning 鈥?缁濆/鍥哄畾瀹氫綅甯冨眬
 *
 * CSS Positioned Layout Module Level 3 搂3.1-3.2:
 * - position:absolute 鐨?containing block = 鏈€杩戝畾浣嶇鍏堢殑 padding box
 * - position:fixed 鐨?containing block = viewport (0,0)
 *
 * Pure FragmentBuilder 瀹炵幇锛岀洿鎺ヤ娇鐢?LayoutConstraints + ComputedStyle銆?
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
        // 鐩存帴浣跨敤 ComputedStyle 灞炴€э紙涓嶈浆 array锛?
        $leftVal = $style?->left?->toPx() ?? 0;
        $topVal = $style?->top?->toPx() ?? 0;
        $rightVal = $style?->right?->toPx() ?? 0;
        $bottomVal = $style?->bottom?->toPx() ?? 0;

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

        // 浠庣鍏堢殑 computedStyle 鑾峰彇 padding/border
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
        // 绁栧厛瀹藉害鍙兘灏氭湭璁剧疆锛坮esolveChildren 鍏堜簬绛栫暐鎵ц锛夛紝浣跨敤 style 鍥為€€
        $ancestorRawW = $ancestor?->w ?? 0;
        if ($ancestorRawW <= 0 && $ancCS !== null) {
            $ancestorRawW = $ancCS->width->toPx();
        }
        $ancestorW = $ancestor ? ($ancestorRawW - $borderL - $borderR) : $viewportW;
        $ancestorH = $ancestor ? ($ancestor->h - $borderT - $borderB) : $viewportH;
        if ($ancestorH <= 0) {
            if ($ancCS !== null) {
                $ch = $ancCS->height->toPx();
                if ($ch > 0) {
                    $ancestorH = $ch - $borderT - $borderB;
                } else {
                    // Height auto: use visualHeight from layout (may be 0 during first pass)
                    // Fall back to ancestor's rendered h if available
                    $visH = $ancCS->visualHeight($ancestor->h);
                    if ($visH > 0) $ancestorH = $ancCS->contentBoxHeight($visH);
                }
            }
        }

        // 甯冨眬瀹瑰櫒瀹介珮
        $cbW = $ancestor ? ($ancestorW) : $viewportW;

        // 浠?style 璇诲彇 width/height锛圕ssLength 鎼哄甫鍗曚綅淇℃伅锛?
        $width = $style?->width?->toPx() ?? 0;
        $height = $style?->height?->toPx() ?? 0;
        if ($style?->width?->isPercent()) $width = $style->resolveWidth($cbW);
        if ($style?->height?->isPercent()) $height = $style->resolveHeight($ancestorH);

        // 璁＄畻杈硅窛
        $marginLeft = $style?->margin?->left?->toPx() ?? 0;
        $marginTop = $style?->margin?->top?->toPx() ?? 0;
        $marginRight = $style?->margin?->right?->toPx() ?? 0;
        $marginBottom = $style?->margin?->bottom?->toPx() ?? 0;

        // 濡傛灉 left+right 閮借缃笖 width=0锛岀敤涓よ€呭喅瀹氬搴?
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

        // 璁＄畻鏈€缁堝潗鏍?
        // CSS 2.2 搂9.3.2: 妫€娴?left/right/top/bottom 鏄惁鏄惧紡璁剧疆锛堝寘鎷€间负0锛?
        $hasLeft = $style?->getRaw('left') !== null;
        $hasRight = $style?->getRaw('right') !== null;
        $hasTop = $style?->getRaw('top') !== null;
        $hasBottom = $style?->getRaw('bottom') !== null;

        $calcX = $ancestorX + $borderL + $leftVal + $marginLeft;
        $calcY = $ancestorY + $borderT + $topVal + $marginTop;

        // right锛堝綋 left 鏈缃椂浣跨敤锛?
        if ($hasRight && !$hasLeft && ($ancestor !== null || $isFixed)) {
            $rightEdge = $ancestorX + $borderL + $ancestorPaddingLeft + $cbW - $rightVal;
            $calcX = $rightEdge - ($width > 0 ? $width : 0);
        }
        // bottom锛堝綋 top 鏈缃椂浣跨敤锛?
        if ($hasBottom && !$hasTop && ($ancestor !== null || $isFixed)) {
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

        // Apply margin:auto centering for absolute positioned elements
        // Must be after position/size are determined but before builder is finalized
        if ($style !== null && $width > 0) {
            $hasExplicitH = $height > 0;
            $this->resolveMarginAuto($node, $style, $cbW, $hasExplicitH ? $ancestorH : 0);
            // Update builder position with potentially auto-centered coordinates
            $builder->setPosition($node->x, $node->y);
        }

        // 鈹€鈹€ 鍚屾 builder 涓殑瀛?Fragment 涓虹粷瀵瑰畾浣嶇畻娉曡绠楃殑姝ｇ‘浣嶇疆 鈹€鈹€
        // resolveChildren 鍏堜簬缁濆瀹氫綅绠楁硶鎵ц锛屽叾涓殑瀛?Fragment 浣嶇疆宸茶繃鏃躲€?
        if ($builder->childCount() > 0 && count($node->children) > 0) {
            $existingChildren = $builder->getChildren();
            $updatedChildren = [];
            $childCount = min(count($existingChildren), count($node->children));
            for ($i = 0; $i < $childCount; $i++) {
                $child = $node->children[$i];
                $oldFrag = $existingChildren[$i];
                $updatedChildren[] = new LayoutFragment(
                    x: $child->x,
                    y: $child->y,
                    w: $oldFrag->w,
                    h: $oldFrag->h,
                    visualW: $oldFrag->visualW,
                    visualH: $oldFrag->visualH,
                    layer: $oldFrag->layer,
                    contentWidth: $oldFrag->contentWidth,
                    contentHeight: $oldFrag->contentHeight,
                    style: $oldFrag->style,
                    children: $oldFrag->children
                );
            }
            $builder->replaceChildren($updatedChildren);
        }
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
        if ($node->positioningAncestorValid) {
            error_log('[POS_ANC] CACHED anc=' . ($node->positioningAncestor?->type ?? 'null') . ' x=' . ($node->positioningAncestor?->x ?? -1));
            return;
        }

        $chainStr = 'anchor.type=' . $node->type . ' parent=' . ($node->parent?->type ?? 'null') . '@(' . ($node->parent?->x ?? -1) . ',' . ($node->parent?->y ?? -1) . ')';
        $ancestor = $node->parent;
        $depth = 0;
        while ($ancestor !== null) {
            $pos = $ancestor->computedStyle?->position?->value ?? 'static';
            $chainStr .= ' -> d=' . $depth . ' ' . $ancestor->type . '@(' . $ancestor->x . ',' . $ancestor->y . ')pos=' . $pos;
            if ($pos !== 'static') {
                $node->positioningAncestor = $ancestor;
                $node->positioningAncestorValid = true;
                error_log('[POS_ANC] FOUND depth=' . $depth . ' ' . $ancestor->type . ' x=' . $ancestor->x . ' y=' . $ancestor->y . ' pos=' . $pos . ' w=' . $ancestor->w . ' h=' . $ancestor->h . ' | ' . $chainStr);
                return;
            }
            $ancestor = $ancestor->parent;
            $depth++;
        }
        $node->positioningAncestor = null;
        $node->positioningAncestorValid = true;
        error_log('[POS_ANC] NOT FOUND (no positioned ancestor) | ' . $chainStr);
    }
}

