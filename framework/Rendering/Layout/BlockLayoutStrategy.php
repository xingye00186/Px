<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Core\Config;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\ComputedStyle;
use Px\Rendering\CssStyleHelper;

/**
 * BlockLayoutStrategy — Block 布局策略
 *
 * Pure FragmentBuilder 实现，直接使用 LayoutConstraints + ComputedStyle。
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
        $this->resolveBlockLayout($node, $constraints->parentContentX, $constraints->parentContentY, $style, $builder);
        // 不读回：resolveBlockLayout 已在内部写入 builder
    }
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @deprecated 已弃用，请使用 resolveWithBuilder。Phase 3 后删除。
     */
    public function resolve(
        RenderNode    $node,
        object        $ctx,
        array         $style
    ): void {
        $this->resolveBlockLayout(
            $node,
            $ctx->parentX,
            $ctx->parentY,
            $node->computedStyle
        );
    }

    /**
     * Block layout for normal flow (static/relative).
     */
    public function resolveBlockLayout(
        RenderNode    $node,
        int           $parentX,
        int           $parentY,
        ?ComputedStyle $computedStyle,
        ?FragmentBuilder $builder = null
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

        $left = $computedStyle?->left ?? 0;
        $top = $computedStyle?->top ?? 0;

        // Container info from parent (via node->parent, directly)
        $parent = $node->parent;
        $parentW_raw = ($parent !== null) ? $parent->w : (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0);
        $parentH_raw = ($parent !== null) ? $parent->h : (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0);
        if ($parent !== null && $parent->computedStyle !== null) {
            $ps = $parent->computedStyle;
            $padL = $ps->padding->left->toPx();
            $padR = $ps->padding->right->toPx();
            $pbw = $ps->borderLeftWidth + $ps->borderRightWidth;
            // 如果父容器的 w/h 尚未计算（0），使用 computedStyle 中的显式值
            if ($parentW_raw <= 0) {
                $parentW_raw = $ps->width->toPx();
            }
            if ($parentH_raw <= 0) {
                $parentH_raw = $ps->height->toPx();
            }
            // 根据 box-sizing 确定 parentW_raw 是否包含 padding/border
            // content-box: parentW_raw = 内容宽度，padding/border 在外围，不用减
            // border-box:  parentW_raw = 总宽度，需减去 padding/border 得内容宽度
            $parentSizing = $ps->boxSizing->value;
            if ($parentSizing === 'border-box') {
                $parentW = $parentW_raw - $padL - $padR - $pbw;
            } else {
                $parentW = (int)$parentW_raw;
            }
        } else {
            $parentW = (int)$parentW_raw;
        }
        // 父容器高度（用于百分比高度解析）
        $parentH = (int)$parentH_raw;

        $width = 0;
        $height = 0;
        if ($computedStyle !== null) {
            $width = $computedStyle->width->toPx();
            $height = $computedStyle->height->toPx();
            if ($computedStyle->width->isPercent()) {
                $width = $computedStyle->width->resolveInContext($parentW);
            }
            if ($computedStyle->height->isPercent()) {
                $height = $computedStyle->height->resolveInContext($parentH);
            }
        }

        // ── 初始尺寸 ──
        $node->w = (int)max(0, $width);
        $node->h = (int)max(0, $height);

        // ── min/max-width 约束（在 auto-width 前后都应用一次） ──
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

        // ── CSS 2.1 §10.3.3: auto-fill width for block elements ──
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
            $clampWidth(); // auto-width 后再 clamp 一次（min/max 约束）
        }

        // ── Text content measurement ──
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

        // ── Normal flow positioning ──
        $positionVal = $computedStyle?->position?->value ?? 'static';
        $this->resolveNormalFlow($node, $parentX, $parentY, $positionVal, $computedStyle, $left, $top);

        // ── Scroll container post-processing ──
        if ($node->isScrollContainer) {
            $padTop = $computedStyle?->padding?->top->toPx() ?? 0;
            $padLeft = $computedStyle?->padding?->left->toPx() ?? 0;
            $padRight = $computedStyle?->padding?->right->toPx() ?? 0;
            $padBottom = $computedStyle?->padding?->bottom->toPx() ?? 0;
            $childOffsetY = $node->y + $padTop;
            $this->finalizeScrollContainer($node, $parentX, $parentY, $computedStyle, $childOffsetY, $padLeft, $padRight, $padBottom);
        }

        // ── Normal Flow auto-stack for block containers ──
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

                    // ── Margin collapse ──
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
                        $child->y += ($childCS?->top ?? 0);
                        $child->x += ($childCS?->left ?? 0);
                    }

                    // ── Auto margin centering for block elements (CSS 2.2 §10.3.3) ──
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

        // ── Auto-height for block containers ──
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

        // ── 同步子 fragment 位置（auto-stack 直接修改了 RenderNode，需同步到 builder）──
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

        // ── 通过 builder 输出最终结果（不依赖 node 读回）──
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
            // CSS 2.2 §9.3.1: left/top 在 static 定位下无效
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

        // 根据子项实际宽度计算 contentWidth（支持水平滚动）
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
