<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Core\Config;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\ComputedStyle;

/**
 * BlockLayoutStrategy 鈥?Block 甯冨眬绛栫暐
 *
 * Pure FragmentBuilder 瀹炵幇锛岀洿鎺ヤ娇鐢?LayoutConstraints + ComputedStyle銆?
 */
class BlockLayoutStrategy implements LayoutStrategyInterface
{
    /** HTML inline elements: width should be text-measured, not container-filled */
    private const INLINE_TYPES = ['#text','text','span','b','strong','em','i','code','br','a','label','abbr','cite','dfn','kbd','mark','q','samp','small','sub','sup','time','var'];

    private static function isInlineType(string $type): bool
    {
        return in_array($type, self::INLINE_TYPES, true);
    }

    /**
     * Pure FragmentBuilder 甯冨眬鍏ュ彛銆?
     * 鐩存帴浣跨敤 LayoutConstraints + ComputedStyle銆?
     */
    public function resolveWithBuilder(
        RenderNode         $node,
        LayoutConstraints  $constraints,
        ?ComputedStyle     $style,
        FragmentBuilder    $builder
    ): void
    {
        $this->resolveBlockLayout($node, $constraints->parentContentX, $constraints->parentContentY, $style, $builder, $constraints->contentWidth);
        // 涓嶈鍥烇細resolveBlockLayout 宸插湪鍐呴儴鍐欏叆 builder
    }
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Block layout for normal flow (static/relative).
     */
    public function resolveBlockLayout(
        RenderNode    $node,
        int           $parentX,
        int           $parentY,
        ?ComputedStyle $computedStyle,
        ?FragmentBuilder $builder = null,
        int           $constraintContentWidth = 0
    ): void
    {
        // Use a local helper to access style values from ComputedStyle
        $styleGet = function(string $key, mixed $default = null) use ($computedStyle) {
            return $computedStyle?->getRaw($key) ?? $default;
        };
        $cssLen = function(string $prop, int $default = 0) use ($computedStyle): int {
            if ($computedStyle === null) return $default;
            $getter = [$computedStyle, $prop];
            return is_callable($getter) ? $getter()->toPx() : $default;
        };

        $left = $computedStyle?->left?->toPx() ?? 0;
        $top = $computedStyle?->top?->toPx() ?? 0;

        // Container info from parent (via node->parent, directly)
        $parent = $node->parent;
        $parentW_raw = ($parent !== null) ? $parent->w : (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0);
        // Use constraint content width as fallback when parent width not yet calculated
        $usedConstraintFallback = false;
        if ($parentW_raw <= 0 && $constraintContentWidth > 0) {
            $parentW_raw = $constraintContentWidth;
            $usedConstraintFallback = true;
        }
        $parentH_raw = ($parent !== null) ? $parent->h : (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0);
        if ($parent !== null && $parent->computedStyle !== null) {
            $ps = $parent->computedStyle;
            $padL = $ps->padding->left->toPx();
            $padR = $ps->padding->right->toPx();
            $pbw = $ps->borderLeftWidth + $ps->borderRightWidth;
            // 濡傛灉鐖跺鍣ㄧ殑 w/h 灏氭湭璁＄畻锛?锛夛紝浣跨敤 computedStyle 涓殑鏄惧紡鍊?
            if ($parentW_raw <= 0) {
                $parentW_raw = $ps->width->toPx();
            }
            if ($parentH_raw <= 0) {
                $parentH_raw = $ps->height->toPx();
            }
            // 鏍规嵁 box-sizing 纭畾 parentW_raw 鏄惁鍖呭惈 padding/border
            // content-box: parentW_raw = 鍐呭瀹藉害锛宲adding/border 鍦ㄥ鍥达紝涓嶇敤鍑?
            // border-box:  parentW_raw = 鎬诲搴︼紝闇€鍑忓幓 padding/border 寰楀唴瀹瑰搴?
            $parentSizing = $ps->boxSizing->value;
            if ($parentSizing === 'border-box' && !$usedConstraintFallback) {
                $parentW = $parentW_raw - $padL - $padR - $pbw;
            } else {
                $parentW = (int)$parentW_raw;
            }
        } else {
            $parentW = (int)$parentW_raw;
        }
        // 鐖跺鍣ㄩ珮搴︼紙鐢ㄤ簬鐧惧垎姣旈珮搴﹁В鏋愶級
        $parentH = (int)$parentH_raw;

        $width = 0;
        $height = 0;
        if ($computedStyle !== null) {
            $width = $computedStyle->width->toPx();
            $height = $computedStyle->height->toPx();
            // Intrinsic sizing: min-content/max-content/fit-content
            if ($computedStyle->width->isIntrinsic()) {
                $textContent = $node->content ?? '';
                if (is_string($textContent) && strlen($textContent) > 0) {
                    $fs = $computedStyle->fontSize;
                    $bd = $computedStyle->bold;
                    $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($textContent, $fs, $bd) : (int)(strlen($textContent) * $fs * 0.6));
                    if ($computedStyle->width->unit === 'min-content') {
                        // Approximate: use measured width as min-content
                        $width = $measured;
                    } else {
                        // max-content / fit-content: use measured width
                        $width = $measured;
                    }
                }
            }
            if ($computedStyle->height->isIntrinsic()) {
                $textContent = $node->content ?? '';
                if (is_string($textContent) && strlen($textContent) > 0) {
                    $lineH = $computedStyle->lineHeight > 0 ? $computedStyle->lineHeight : (int)($computedStyle->fontSize * 1.2);
                    $height = $lineH;
                }
            }
            if ($computedStyle->width->isPercent()) {
                $width = $computedStyle->width->resolveInContext($parentW);
            }
            if ($computedStyle->height->isPercent()) {
                $height = $computedStyle->height->resolveInContext($parentH);
            }
        }

        // 鈹€鈹€ 鍒濆灏哄 鈹€鈹€
        $node->w = (int)max(0, $width);
        $node->h = (int)max(0, $height);

        // aspect-ratio: if one dim is auto, derive from the other
        $ar = $computedStyle?->aspectRatio ?? 0;
        if ($ar > 0) {
            if ($height === 0 && $node->w > 0) {
                $node->h = (int)($node->w / $ar);
            } elseif ($width === 0 && $node->h > 0) {
                $node->w = (int)($node->h * $ar);
            }
            // Re-clamp after aspect-ratio derivation
            $clampWidth();
            $clampHeight = function() use (&$node, $minH, $maxH) {
                if ($maxH > 0 && $node->h > $maxH) $node->h = $maxH;
                if ($minH > 0 && $node->h < $minH) $node->h = $minH;
            };
            $clampHeight();
        }

        // 鈹€鈹€ min/max-width 绾︽潫锛堝湪 auto-width 鍓嶅悗閮藉簲鐢ㄤ竴娆★級 鈹€鈹€
        $minW = $computedStyle?->minWidth?->toPx() ?? 0;
        $maxW = $computedStyle?->maxWidth?->toPx() ?? 0;
        $minH = $computedStyle?->minHeight?->toPx() ?? 0;
        $maxH = $computedStyle?->maxHeight?->toPx() ?? 0;
        $clampWidth = function() use (&$node, $minW, $maxW) {
            if ($maxW > 0 && $node->w > $maxW) $node->w = $maxW;
            if ($minW > 0 && $node->w < $minW) $node->w = $minW;
        };
        $clampWidth();

        $hasExplicitW = $computedStyle !== null && ($computedStyle->width->toPx() > 0 || $computedStyle->width->isPercent());
        if ($computedStyle !== null) {
            $node->visualW = $computedStyle->visualWidth($node->w);
            $node->visualH = $computedStyle->visualHeight($node->h);
        }

        // 鈹€鈹€ CSS 2.1 搂10.3.3: auto-fill width for block elements 鈹€鈹€
        if (!$hasExplicitW && $width === 0 && $parent !== null && !self::isInlineType($node->type)) {
            $ml = $computedStyle?->margin?->left->toPx() ?? 0;
            $mr = $computedStyle?->margin?->right->toPx() ?? 0;
            $autoPadL = $computedStyle?->padding?->left->toPx() ?? 0;
            $autoPadR = $computedStyle?->padding?->right->toPx() ?? 0;
            $autoBw = ($computedStyle?->borderLeftWidth ?? 0) + ($computedStyle?->borderRightWidth ?? 0);
            $sizing = $computedStyle?->boxSizing?->value ?? 'content-box';
            if ($sizing === 'border-box') {
                $autoW = max(0, $parentW - $ml - $mr);
            } else {
                $autoW = max(0, $parentW - $ml - $mr - $autoPadL - $autoPadR - $autoBw);
            }
            $node->w = (int)max(0, $autoW);
            if ($computedStyle !== null) $node->visualW = $computedStyle->visualWidth($node->w);
            $clampWidth(); // auto-width 鍚庡啀 clamp 涓€娆★紙min/max 绾︽潫锛?
        }

        // 鈹€鈹€ Text content measurement 鈹€鈹€
        if ($node->content !== null && is_string($node->content) && strlen($node->content) > 0) {
            $fs = $computedStyle?->fontSize ?? 14;
            $bd = $computedStyle?->bold ?? false;
            $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($node->content, $fs, $bd) : 0);
            if ($measured > 0) {
                if (self::isInlineType($node->type)) {
                    $node->w = (int)$measured;
                    if ($computedStyle !== null) $node->visualW = $computedStyle->visualWidth($node->w);
                }
            }
        } elseif ($node->type === 'br') {
            $node->w = 0;
            $node->visualW = 0;
            $fs = $computedStyle?->fontSize ?? 14;
            $lineH = $computedStyle?->lineHeight ?? (int)($fs * 1.2);
            $node->h = $lineH;
            $node->visualH = $lineH;
        }

        // 鈹€鈹€ Normal flow positioning 鈹€鈹€
        $positionVal = $computedStyle?->position?->value ?? 'static';
        $this->resolveNormalFlow($node, $parentX, $parentY, $positionVal, $computedStyle, $left, $top);

        // 鈹€鈹€ Scroll container post-processing 鈹€鈹€
        if ($node->isScrollContainer) {
            $padTop = $computedStyle?->padding?->top->toPx() ?? 0;
            $padLeft = $computedStyle?->padding?->left->toPx() ?? 0;
            $padRight = $computedStyle?->padding?->right->toPx() ?? 0;
            $padBottom = $computedStyle?->padding?->bottom->toPx() ?? 0;
            $childOffsetY = $node->y + $padTop;
            $this->finalizeScrollContainer($node, $parentX, $parentY, $computedStyle, $childOffsetY, $padLeft, $padRight, $padBottom);
        }

        // 鈹€鈹€ Normal Flow auto-stack for block containers 鈹€鈹€
        $displayVal = $computedStyle?->display?->value ?? 'block';
        if ($displayVal === 'block' && !$node->isScrollContainer) {
            $padTop = $computedStyle?->padding?->top->toPx() ?? 0;
            $padLeft = $computedStyle?->padding?->left->toPx() ?? 0;
            $padRight = $computedStyle?->padding?->right->toPx() ?? 0;
            $borderTop = $computedStyle?->borderTopWidth ?? 0;
            $borderLeft = $computedStyle?->borderLeftWidth ?? 0;

            if (count($node->children) > 0) {
                $stackY = $node->y + $borderTop + $padTop;
                $inlineStarted = false;
                $inlineCursorX = 0;
                $inlineCursorY = 0;
                $inlineLineMaxH = 0;
                $inlineContainerFS = $computedStyle?->fontSize ?? 16;
                $inlineContainerLH = $computedStyle?->lineHeight ?? (int)($inlineContainerFS * 1.2);
                $wsVal = $computedStyle?->whiteSpace?->value ?? 'normal';
                $inlineNoWrap = ($wsVal === 'nowrap' || $wsVal === 'pre');

                $containerW = $node->w;
                if ($computedStyle !== null) {
                    $containerW = $computedStyle->contentBoxWidth($node->w);
                }

                $prevMarginBottom = 0;
                $prevCollapsible = false;

                foreach ($node->children as $child) {
                    $childCS = $child->computedStyle;
                    $childPosition = $childCS?->position?->value ?? 'static';
                    $childDisplay = $childCS?->display?->value ?? 'block';

                    // Skip absolute/fixed/display:none children
                    if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') {
                        continue;
                    }

                    $mTop = $childCS?->margin?->top->toPx() ?? 0;
                    $mBottom = $childCS?->margin?->bottom->toPx() ?? 0;
                    $mLeft = $childCS?->margin?->left->toPx() ?? 0;
                    $mRight = $childCS?->margin?->right->toPx() ?? 0;

                    // Auto-width for block children
                    $childHasExplicitW = $childCS !== null && ($childCS->width->toPx() > 0 || $childCS->width->isPercent());
                    if ((!$childHasExplicitW || $child->w === 0) && !self::isInlineType($child->type)) {
                        $autoPadL = $childCS?->padding?->left->toPx() ?? 0;
                        $autoPadR = $childCS?->padding?->right->toPx() ?? 0;
                        $autoBw = ($childCS?->borderLeftWidth ?? 0) + ($childCS?->borderRightWidth ?? 0);
                        $childBoxSizing = $childCS?->boxSizing?->value ?? 'content-box';
                        if ($childBoxSizing === 'border-box') {
                            $autoW = max(0, $containerW - $mLeft - $mRight);
                        } else {
                            $autoW = max(0, $containerW - $mLeft - $mRight - $autoPadL - $autoPadR - $autoBw);
                        }
                        $child->w = $autoW;
                        $child->visualW = $childCS?->visualWidth($child->w) ?? $child->w;
                    }

                    // Text measurement for inline children
                    if ($child->content !== null && is_string($child->content) && strlen($child->content) > 0) {
                        $cfs = $childCS?->fontSize ?? $inlineContainerFS;
                        $cbd = $childCS?->bold ?? false;
                        $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($child->content, $cfs, $cbd) : 0);
                        if ($measured > 0) {
                            if (self::isInlineType($child->type)) {
                                $child->w = (int)$measured;
                                $child->visualW = $childCS?->visualWidth($child->w) ?? $child->w;
                            }
                        }
                        if (!($childCS?->height->toPx() ?? 0) > 0) {
                            $cLineH = $childCS?->lineHeight ?? (int)($cfs * 1.2);
                            if ($child->h < $cLineH) {
                                $child->h = $cLineH;
                                $cPadT = $childCS?->padding?->top->toPx() ?? 0;
                                $cPadB = $childCS?->padding?->bottom->toPx() ?? 0;
                                $cBtw = ($childCS?->borderTopWidth ?? 0) + ($childCS?->borderBottomWidth ?? 0);
                                $child->visualH = max(0, $child->h + $cPadT + $cPadB + $cBtw);
                            }
                        }
                    } else {
                        $child->w = max(0, $child->w);
                        $child->visualW = $childCS?->visualWidth($child->w) ?? $child->w;
                    }

                    // Inline-level children
                    $isInlineLevel = ($childDisplay === 'inline' || $childDisplay === 'inline-block' || self::isInlineType($child->type));
                    if ($isInlineLevel) {
                        if (!$inlineStarted) {
                            $inlineCursorX = $node->x + $padLeft;
                            $inlineCursorY = ($stackY > $node->y + $borderTop + $padTop) ? $stackY : $node->y + $borderTop + $padTop;
                            $inlineLineMaxH = 0;
                            $inlineStarted = true;
                        }
                        $childW = $child->w;
                        $contentRight = $node->x + $containerW;
                        if (!$inlineNoWrap && $inlineCursorX + $childW > $contentRight) {
                            $lineBoxH = max($inlineLineMaxH, $inlineContainerLH);
                            $inlineCursorY += $lineBoxH;
                            $inlineCursorX = $node->x + $padLeft;
                            $inlineLineMaxH = 0;
                        }
                        $child->x = $inlineCursorX;
                        $child->y = $inlineCursorY;
                        $inlineCursorX += $childW;
                        $inlineLineMaxH = max($inlineLineMaxH, $child->visualH);
                        continue;
                    }

                    // 鈹€鈹€ Margin collapse 鈹€鈹€
                    $childOverflow = $childCS?->overflowY?->value ?? $childCS?->overflow?->value ?? 'visible';
                    $createsBFC = ($childDisplay !== 'block') || ($childOverflow !== 'visible');
                    $isCollapsible = !$createsBFC;

                    $oldY = $child->y;
                    if ($isCollapsible && $prevCollapsible) {
                        $positiveMax = max($prevMarginBottom > 0 ? $prevMarginBottom : 0, $mTop > 0 ? $mTop : 0);
                        $negativeMin = min($prevMarginBottom < 0 ? $prevMarginBottom : 0, $mTop < 0 ? $mTop : 0);
                        $collapsed = $positiveMax + $negativeMin;
                        $child->y = $stackY - $prevMarginBottom + $collapsed;
                    } else {
                        $child->y = $stackY + $mTop;
                    }

                    $stackAdvanceY = $child->y;

                    // Position: set x for non-flex/grid children BEFORE relative offset
                    if ($childDisplay !== 'flex' && $childDisplay !== 'inline-flex' && $childDisplay !== 'grid') {
                        $child->x = $node->x + $borderLeft + $padLeft + $mLeft;
                    }

                    if ($childPosition === 'relative') {
                        $child->y += ($childCS?->top?->toPx() ?? 0);
                        $child->x += ($childCS?->left?->toPx() ?? 0);
                    }

                    // 鈹€鈹€ Auto margin centering for block elements (CSS 2.2 搂10.3.3) 鈹€鈹€
                    $mLauto = $childCS?->getRaw('marginLeftAuto') ?? false;
                    $mRauto = $childCS?->getRaw('marginRightAuto') ?? false;
                    if (($mLauto || $mRauto) && $child->w > 0) {
                        $containerContentW = $containerW;
                        // The 0px margin values in $mLeft/$mRight are placeholders for auto
                        $remaining = $containerContentW - $child->w - $mLeft - $mRight;
                        if ($remaining > 0) {
                            if ($mLauto && $mRauto) {
                                $child->x += (int)($remaining / 2);
                            } elseif ($mLauto) {
                                $child->x += $remaining;
                            }
                            // marginRightAuto alone: no x offset needed
                        }
                    }

                    $dy = $child->y - $oldY;
                    // Simple descendant shift: child's children get dy offset
                    foreach ($child->children as $gc) {
                        $gc->y += $dy;
                    }

                    $stackY = $stackAdvanceY + $child->visualH + $mBottom;

                    if ($isCollapsible) {
                        $prevMarginBottom = $mBottom;
                        $prevCollapsible = true;
                    } else {
                        $prevMarginBottom = 0;
                        $prevCollapsible = false;
                    }
                }
            }
        }

        // 鈹€鈹€ Auto-height for block containers 鈹€鈹€
        $hasExplicitH = $computedStyle !== null && $computedStyle->height->toPx() > 0;
        if (!$hasExplicitH && $displayVal === 'block' && !$node->isScrollContainer) {
            $maxBottom = 0;
            foreach ($node->children as $child) {
                $childPosition = $child->computedStyle?->position?->value ?? 'static';
                $childDisplay = $child->computedStyle?->display?->value ?? 'block';
                if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') continue;
                $childBottom = (int)($child->y + $child->visualH);
                if ($childBottom > $maxBottom) $maxBottom = $childBottom;
            }
            $ahBorderTop = $computedStyle?->borderTopWidth ?? 0;
            $ahPaddingTop = $computedStyle?->padding?->top->toPx() ?? 0;
            $contentTop = $node->y + $ahBorderTop + $ahPaddingTop;
            if ($maxBottom > $contentTop) {
                $node->h = $maxBottom - $contentTop;
                if ($computedStyle !== null) $node->visualH = $computedStyle->visualHeight($node->h);
            }
        }

        // 鈹€鈹€ 鍚屾瀛?fragment 浣嶇疆锛坅uto-stack 鐩存帴淇敼浜?RenderNode锛岄渶鍚屾鍒?builder锛夆攢鈹€
        if ($builder !== null && $builder->childCount() > 0 && count($node->children) > 0) {
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

        // 鈹€鈹€ 閫氳繃 builder 杈撳嚭鏈€缁堢粨鏋滐紙涓嶄緷璧?node 璇诲洖锛夆攢鈹€
        if ($builder !== null) {
            $builder
                ->setPosition($node->x, $node->y)
                ->setSize($node->w, $node->h, $computedStyle)
                ->setLayer($node->layer)
                ->setContentSize($node->contentWidth, $node->contentHeight);
        }
    }

    /**
     * Normal flow positioning for static/relative elements.
     */
    private function resolveNormalFlow(
        RenderNode $node,
        int $parentX,
        int $parentY,
        string $position,
        ?ComputedStyle $style,
        int $left,
        int $top
    ): void {
        if ($position === 'relative') {
            $node->x = $parentX + $left;
            $node->y = $parentY + $top;
        } elseif ($position === 'static') {
            // CSS 2.2 搂9.3.1: left/top 鍦?static 瀹氫綅涓嬫棤鏁?
            $node->x = $parentX;
            $node->y = $parentY;
        }
    }

    /**
     * Scroll container post-processing (contentSize + auto-stack).
     */
    public function finalizeScrollContainer(
        RenderNode $node,
        int $parentX,
        int $parentY,
        ?ComputedStyle $style,
        int $childOffsetY,
        int $paddingLeft,
        int $paddingRight,
        int $paddingBottom
    ): void {
        $childOffsetX = $node->x + $paddingLeft;
        $contentW = $node->w - $paddingLeft - $paddingRight;
        if ($contentW < 0) $contentW = 0;

        // Auto-stack children in scroll container
        $accY = $childOffsetY;
        foreach ($node->children as $child) {
            $childPosition = $child->computedStyle?->position?->value ?? 'static';
            $childDisplay = $child->computedStyle?->display?->value ?? 'block';
            if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') continue;

            $child->x = $childOffsetX;
            $child->y = $accY;
            $accY += $child->visualH;
        }

        $node->contentHeight = (int)max(0, $accY - $childOffsetY + $paddingBottom);

        // 鏍规嵁瀛愰」瀹為檯瀹藉害璁＄畻 contentWidth锛堟敮鎸佹按骞虫粴鍔級
        $maxChildRight = 0;
        foreach ($node->children as $child) {
            $cRight = (int)($child->x + $child->visualW);
            if ($cRight > $maxChildRight) $maxChildRight = $cRight;
        }
        $node->contentWidth = max($node->w, $maxChildRight);

        // Clamp scrollTop
        $maxScroll = max(0, $node->contentHeight - $node->h);
        if ($node->scrollTop > $maxScroll) $node->scrollTop = $maxScroll;
    }
}

