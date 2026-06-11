<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Core\Config;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\Layout\Tools\PercentResolver;
use Px\Rendering\Layout\Tools\ScrollHelper;

/**
 * BlockLayoutStrategy — Block 布局策略
 *
 * 处理 display:block（包括 inline-block）和 scroll-container 的布局。
 * 负责:
 * - 尺寸解析（百分比 + min/max + 文本测量）
 * - 按 position 分发到 normal flow 或 absolute/fixed
 * - Scroll container post-processing（auto-stack + contentHeight + clamp）
 * - Normal flow auto-stack
 */
class BlockLayoutStrategy implements LayoutStrategyInterface
{
    public function resolve(
        RenderNode    $node,
        LayoutContext $ctx,
        array         $style
    ): void
    {
        $this->resolveBlockLayout($node, $ctx, $style);
    }
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Block layout — normal flow (static/relative).
     *
     * 统一尺寸解析（百分比 + min/max + 文本测量），
     * 然后调用 resolveNormalFlow 定位。
     * 最后处理 scroll container post-processing + auto-width/height。
     */
    public function resolveBlockLayout(
        RenderNode    $node,
        LayoutContext $ctx,
        array         $style
    ): void
    {
        error_log('[DIAG_BLOCK] enter resolveBlockLayout type=' . $node->type . ' display=' . ($style['display'] ?? '?'));

        $left = (int)($style['left'] ?? 0);

        $top = (int)($style['top'] ?? 0);

        // CSS: percentage width resolves against content width
        // static/relative: containing block is content area (w minus padding)
        // When parent is null (top-level element under #root), use window viewport as containing block
        $parentW_raw = ($ctx->parent !== null) ? $ctx->parent->w : (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0);
        $parentH = ($ctx->parent !== null) ? $ctx->parent->h : (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0);
        if ($ctx->parent !== null) {
            $padL = (int)($ctx->parent->style['paddingLeft'] ?? $ctx->parent->style['padding'] ?? 0);
            $padR = (int)($ctx->parent->style['paddingRight'] ?? $ctx->parent->style['padding'] ?? 0);
            $parentW = PercentResolver::resolveContentWidth($ctx->parent->style, $parentW_raw);
        } else {
            $parentW = (int)$parentW_raw;
        }

        $width = PercentResolver::resolvePercent($style, 'width', 'widthPercent', $parentW);

        $height = PercentResolver::resolvePercent($style, 'height', 'heightPercent', $parentH);



        // flex:1 已移至 flex 布局专用路径 (Task D)


        // ── 应用 min/max 约束到尺寸（在子节点递归之前，确保 parent->w/h 立即可用）──
        $node->w = (int)max(0, (int)PercentResolver::resolveMinMax($style, $width, true));

        // Debug: span h before min/max
        $dbg_span_h_before = $node->h;

        $node->h = (int)max(0, (int)PercentResolver::resolveMinMax($style, $height, false));

        $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
        $node->visualH = PercentResolver::resolveVisualH($style, $node->h);




        // ── CSS 规范: 正常流块级元素未显式设置宽度时，应填充包含块内容宽度 ──
        $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);
        if (!$hasExplicitW && $width === 0 && $ctx->parent !== null) {
            $node->w = (int)max(0, (int)PercentResolver::resolveMinMax($style, $parentW, true));
            $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
        }


        // Resolve fontSize from relative unit (rem/em/vw/vh)
        PercentResolver::resolveFontSizeUnit($style);
        $node->style['fontSize'] = $style['fontSize'];

        // -- Nodes with text content: measure text width instead of filling parent --
        if ($node->content !== null && is_string($node->content) && strlen($node->content) > 0) {
            $fs = (int)($style['fontSize'] ?? 14);
            $bd = ($style['fontWeight'] ?? 'normal') === 'bold' || ($style['fontWeight'] ?? 'normal') === '700';
            $measured = PercentResolver::resolveTextWidth($node->content, $fs, $bd);
            if ($measured > 0) {
                // Text-measured width: only for text/span types (block layout width is auto-filled)
                // Flex items get their text-measured width in applyFlexBasis
                if ($node->type === 'text' || $node->type === 'span') {
                    $node->w = (int)min($measured, (int)max(0, (int)PercentResolver::resolveMinMax($style, $measured, true)));
                    $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
                }
            }
            // Text height = line-height if no explicit height
            if (!array_key_exists('height', $style) && !array_key_exists('heightPercent', $style)) {
                $lineH = PercentResolver::resolveLineHeight($style, $fs);

                if ($node->h === 0 || $node->h < $lineH) {
                    $node->h = $lineH;
                    $node->visualH = PercentResolver::resolveVisualH($style, $node->h);
                }
            }
        }


        // ── Normal flow positioning (static/relative) ──
        $position = $style['position'] ?? 'static';
        $this->resolveNormalFlow($node, $ctx, $position, $style, $left, $top);


        // ── Scroll container post-processing for flex/grid display modes ──

        if ($node->isScrollContainer) {
            $paddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);

            $paddingRight = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);

            $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);

            // A1 重构: childOffsetY 不再减 scrollTop，偏移由 VNodeRenderer 在绘制层处理
            $childOffsetY = $node->y + $paddingTop;

            $this->finalizeScrollContainer($node, $ctx, $style, $childOffsetY, $paddingLeft, $paddingRight);
        }


        // ── Task C: Normal Flow auto-stack for block containers ──

        $display = $style['display'] ?? 'block';


        if ($display === 'block' && !$node->isScrollContainer) {
            // Static/relative children in block containers auto-stack vertically (CSS normal flow).

            // Scroll containers have their own auto-stack in finalizeScrollContainer.


            $paddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);

            $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);

            $paddingRight = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);


            if (count($node->children) > 0) {
                $stackY = $node->y + $paddingTop;

                $containerW = PercentResolver::resolveContentWidth($node->style, $node->w);

                // CSS 2.2 §8.3.1: 跟踪上一个可折叠兄弟的 margin-bottom
                $prevMarginBottom = 0;
                $prevCollapsible = false;

                foreach ($node->children as $child) {
                    $childStyle = $child->style;

                    $childPosition = $childStyle['position'] ?? 'static';

                    // Skip absolute/fixed children — they don't participate in normal flow
                    if ($childPosition === 'absolute' || $childPosition === 'fixed') {
                        continue;
                    }

                    $mTop = PercentResolver::resolveMarginPaddingPercent($childStyle, 'marginTop', 'marginTopPercent', $containerW);

                    $mBottom = PercentResolver::resolveMarginPaddingPercent($childStyle, 'marginBottom', 'marginBottomPercent', $containerW);

                    // Auto-width: inherit from container padding area (skip if percentage width)

                    $hasExplicitWidth = array_key_exists('width', $child->style) || array_key_exists('widthPercent', $child->style);

                    if (!$hasExplicitWidth || $child->w === 0) {
                        $child->w = max(0, (int)$containerW);

                        $child->style['width'] = $containerW;

                        $child->visualW = PercentResolver::resolveVisualW($childStyle, $child->w);
                    }

                    // Resolve fontSize from relative unit for child
                    PercentResolver::resolveFontSizeUnit($child->style);
                    $childStyle['fontSize'] = $child->style['fontSize'];

                    // -- Children with text content: measure text width (only for content-sized children) --
                    if ($child->content !== null && is_string($child->content) && strlen($child->content) > 0) {
                        $fs = (int)($childStyle['fontSize'] ?? 14);
                        $bd = ($childStyle['fontWeight'] ?? 'normal') === 'bold' || ($childStyle['fontWeight'] ?? 'normal') === '700';
                        $measured = PercentResolver::resolveTextWidth($child->content, $fs, $bd);
                        if ($measured > 0) {
                            // Block auto-stack children fill parent width — text-measured width only for span/text
                            // Flex items get text-measured width in applyFlexBasis via explicit width check
                            if ($child->type === 'text' || $child->type === 'span') {
                                $child->w = min($measured, max(0, (int)PercentResolver::resolveMinMax($childStyle, $measured, true)));
                                $child->visualW = PercentResolver::resolveVisualW($childStyle, $child->w);
                            }
                        }
                        // Text height = line-height if no explicit height
                        if (!array_key_exists('height', $childStyle)) {
                            $lineH = PercentResolver::resolveLineHeight($childStyle, $fs);
                            if ($child->h === 0 || $child->h < $lineH) {
                                $child->h = $lineH;
                                $child->visualH = PercentResolver::resolveVisualH($childStyle, $child->h);
                            }
                        }
                    } else {
                        $child->w = max(0, (int)PercentResolver::resolveMinMax($childStyle, $child->w, true));
                        $child->visualW = PercentResolver::resolveVisualW($childStyle, $child->w);
                    }


                    // ── Two-pass: re-resolve internal children of sized items ──

                    $childDisplay = $childStyle['display'] ?? 'block';


                    if ($childDisplay === 'flex' || $childDisplay === 'grid') {
                        if (count($child->children) > 0) {
                            $child->layoutDirty = true;

                            foreach ($child->children as $gc) {
                                $gc->layoutDirty = true;
                            }

                            $childCtx = new LayoutContext($node->x + $paddingLeft, $stackY, $node);
                            $this->resolver->resolveNode($child, $childCtx);
                        }
                    }


                    // ── Auto-width/height for block containers (CSS content-based sizing) ──

                    $childML = $childStyle['marginLeftAuto'] ?? false;

                    $childMR = $childStyle['marginRightAuto'] ?? false;


                    if ($childML || $childMR) {
                        $this->resolver->getAbsolutePositioning()->resolveMarginAuto($child, $childStyle, $containerW, 0);
                    }


                    // ── CSS 2.2 §8.3.1: 外边距折叠 ──
                    // 仅在相同 BFC 内的 block 兄弟之间发生
                    $childOverflow = $childStyle['overflow'] ?? $childStyle['overflowY'] ?? 'visible';
                    $createsBFC = ($childDisplay !== 'block')
                        || ($childOverflow !== 'visible')
                        || ($childStyle['float'] ?? 'none') !== 'none';
                    $isCollapsible = !$createsBFC;

                    $oldY = $child->y;

                    if ($isCollapsible && $prevCollapsible && $mTop * $prevMarginBottom >= 0) {
                        // 对于同号边距：折叠结果 = max(positives) + min(negatives)
                        $positiveMax = max($prevMarginBottom > 0 ? $prevMarginBottom : 0, $mTop > 0 ? $mTop : 0);
                        $negativeMin = min($prevMarginBottom < 0 ? $prevMarginBottom : 0, $mTop < 0 ? $mTop : 0);
                        $collapsed = $positiveMax + $negativeMin;
                        $child->y = $stackY - $prevMarginBottom + $collapsed;
                    } elseif ($isCollapsible && $prevCollapsible) {
                        // 异号边距（一正一负）：折叠结果 = 直接相加
                        $collapsed = $prevMarginBottom + $mTop;
                        $child->y = $stackY - $prevMarginBottom + $collapsed;
                    } else {
                        $child->y = $stackY + $mTop;
                    }

                    // 保存 stack 推进位置（不受 position:relative 偏移影响）
                    $stackAdvanceY = $child->y;

                    // position:relative 额外偏移（不推进 stack）
                    if ($childPosition === 'relative') {
                        $child->y += ($childStyle['top'] ?? 0);
                    }

                    // Shift descendants

                    $dy = $child->y - $oldY;

                    if ($dy !== 0) {
                        foreach ($child->children as $grandchild) {
                            ScrollHelper::shiftDescendantsY($grandchild, $dy);
                        }
                    }

                    $stackY = $stackAdvanceY + $child->visualH + $mBottom;

                    if ($isCollapsible) {
                        $prevMarginBottom = $mBottom;
                        $prevCollapsible = true;
                    } else {
                        // 创建新 BFC 的元素阻止外边距折叠穿透
                        $prevMarginBottom = 0;
                        $prevCollapsible = false;
                    }
                }
            }
        }


        // ── Auto-width/height for block containers (CSS content-based sizing) ──

        $hasExplicitWidth = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);

        $hasExplicitHeight = array_key_exists('height', $style) || array_key_exists('heightPercent', $style);


        if (!$hasExplicitWidth && $display === 'block') {
            $maxRight = 0;

            foreach ($node->children as $child) {
                $childRight = (int)($child->x + $child->visualW);

                if ($childRight > $maxRight) $maxRight = $childRight;
            }

            $computedW = max(0, $maxRight - $node->x);

            if ($computedW > $node->w) {
                $node->w = (int)max(0, (int)PercentResolver::resolveMinMax($style, $computedW, true));

                $padL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);

                $padR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);

                $contentW = PercentResolver::resolveContentWidth($style, $node->w);

                if ($contentW > 0) {
                    foreach ($node->children as $child) {
                        $cs = $child->style;

                        if (!array_key_exists('width', $cs)) {
                            $child->w = (int)max(0, $contentW);

                            $child->w = (int)max(0, (int)PercentResolver::resolveMinMax($cs, $child->w, true));

                            $child->visualW = PercentResolver::resolveVisualW($cs, $child->w);
                        }
                    }
                }
            }
        }


        $overflowY = $style['overflowY'] ?? $style['overflow'] ?? 'visible';

        $isAutoHeight = (!$hasExplicitHeight) ||
            ($hasExplicitHeight && $node->h === 0 && $overflowY !== 'hidden' && $overflowY !== 'scroll');

        if ($isAutoHeight && $display === 'block') {
            $maxBottom = 0;

            foreach ($node->children as $child) {
                // CSS 2.2 §10.6.3: absolute/fixed 子节点不参与 auto-height 计算
                $childPosition = $child->style['position'] ?? 'static';
                if ($childPosition === 'absolute' || $childPosition === 'fixed') {
                    continue;
                }
                $childBottom = (int)($child->y + $child->visualH);

                if ($childBottom > $maxBottom) $maxBottom = $childBottom;
            }

            // CSS 2.2 §10.6.3: auto-height = distance from content edge top to last child bottom
            // $node->y includes paddingTop offset — content area starts at $node->y + $paddingTop
            $ahPaddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
            $computedH = max(0, $maxBottom - ($node->y + $ahPaddingTop));

            if ($computedH > $node->h) {
                $node->h = (int)max(0, (int)PercentResolver::resolveMinMax($style, $computedH, false));
            }
        }

        // ── Second pass: resolve absolute/fixed children now that container height is final ──
        $absPadLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
        $absPadTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);

        foreach ($node->children as $child) {
            $childPosition = $child->style['position'] ?? 'static';

            if ($childPosition === 'absolute' || $childPosition === 'fixed') {
                $child->layoutDirty = true;

                $childCtx = new LayoutContext($node->x + $absPadLeft, $node->y + $absPadTop, $node);
                $this->resolver->resolveNode($child, $childCtx);
            }
        }
        error_log('[DIAG_BLOCK] exit resolveBlockLayout type=' . $node->type);

        // Set container's own visualW/visualH
        $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
        $node->visualH = PercentResolver::resolveVisualH($style, $node->h);
    }

    /**
     * Normal flow positioning (static/relative).
     *
     * static: 完全忽略 left/top/right/bottom，不推进 stack。
     * relative: left/top 作为附加偏移量（不影响兄弟节点的 stack 位置）。
     */
    private function resolveNormalFlow(
        RenderNode    $node,
        LayoutContext $ctx,
        string        $position,
        array         $style,
        int           $left,
        int           $top
    ): void
    {
        $cbWidth = $ctx->parent ? PercentResolver::resolveContentWidth($ctx->parent->style, $ctx->parent->w) : 0;
        $marginLeft = PercentResolver::resolveMarginPaddingPercent($style, 'marginLeft', 'marginLeftPercent', $cbWidth);

        $marginTop = PercentResolver::resolveMarginPaddingPercent($style, 'marginTop', 'marginTopPercent', $cbWidth);

        $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);

        $paddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);

        // Base position = parent content area

        $node->x = $ctx->parentX + $marginLeft;

        $node->y = $ctx->parentY + $marginTop;

        // relative: left/top 作为额外偏移（不改变 stack 推进位置）
        if ($position === 'relative') {
            $node->x += $left;

            $node->y += $top;
        }

        // static: left/top/right/bottom 完全忽略


        // Apply translate from animatedStyle

        $translateX = (int)($style['translateX'] ?? 0);

        $translateY = (int)($style['translateY'] ?? 0);

        $node->x += $translateX;

        $node->y += $translateY;

        // Resolve children recursively (skip absolute/fixed — resolved in second pass after container height is known)

        $childOffsetX = $node->x + $paddingLeft;

        $childOffsetY = $node->y + $paddingTop;

        foreach ($node->children as $child) {
            $childPosition = $child->style['position'] ?? 'static';

            if ($childPosition === 'absolute' || $childPosition === 'fixed') {
                continue;
            }

            $childCtx = new LayoutContext($childOffsetX, $childOffsetY, $node);
            $this->resolver->resolveNode($child, $childCtx);
        }
    }

    /**
     * Scroll container post-processing: auto-stack + contentHeight + scroll clamps.
     *
     * Extracted from resolveBlockLayout to keep method focused.
     * Preserves all original scroll container behaviors.
     */
    public function finalizeScrollContainer(
        RenderNode    $node,
        LayoutContext $ctx,
        array         $style,
        int           $childOffsetY,
        int           $paddingLeft,
        int           $paddingRight
    ): void
    {
        $containerW = PercentResolver::resolveContentWidth($style, $node->w);

        // ── Auto-stack: for scroll containers, position children vertically ──
        $this->autoStackChildren($node, $childOffsetY, $containerW);

        // ── Calculate initial contentHeight ──
        $node->contentHeight = $this->calcContentHeight($node, $childOffsetY);

        // ── CSS Overflow Module Level 3 §2.3: 滚动条占用内容区宽度 ──
        // 检测是否需要垂直滚动条，若需要则从容器宽度中减去 scrollbar 宽度
        // 并重新布局子节点
        $overflowY = $node->style['overflowY'] ?? $node->style['overflow'] ?? 'visible';
        $needsVScroll = ($overflowY === 'auto' || $overflowY === 'scroll')
            && $node->contentHeight > $node->h;

        if ($needsVScroll) {
            $scrollbarWidth = 15; // 标准滚动条宽度
            $newContainerW = max(20, $containerW - $scrollbarWidth);
            if ($newContainerW < $containerW) {
                // 重新布局子节点（使用缩短后的宽度）
                $this->autoStackChildren($node, $childOffsetY, $newContainerW);
                // 重新计算 contentHeight
                $node->contentHeight = $this->calcContentHeight($node, $childOffsetY);
            }
        }

        // ── Clamp scrollTop when content shrinks ────
        $maxScroll = max($node->contentHeight - $node->h, 0);
        if ($node->scrollTop > $maxScroll) {
            $node->scrollTop = $maxScroll;
        }

        // ── Content width for horizontal scroll ────
        $overflowX = $node->style['overflowX'] ?? $node->style['overflow'] ?? 'visible';
        $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll');

        if ($hasHScroll) {
            $maxRight = 0;
            foreach ($node->children as $child) {
                $cLeft = $child->style['left'] ?? 0;
                $cWidth = $child->style['width'] ?? $child->visualW;
                $right = (int)($cLeft + $cWidth);
                if ($right > $maxRight) $maxRight = $right;
            }
            $node->contentWidth = max($maxRight, $node->w);

            $maxScrollX = max($node->contentWidth - $node->w, 0);
            if ($node->scrollLeft > $maxScrollX) {
                $node->scrollLeft = $maxScrollX;
            }
        } else {
            $node->contentWidth = $node->w;
        }
    }

    /**
     * Auto-stack children vertically in a scroll container.
     * Manages margin collapsing, relative positioning, and child offset shifting.
     */
    private function autoStackChildren(RenderNode $node, int $childOffsetY, int $containerW): void
    {
        $stackY = $childOffsetY;
        $prevMarginBottom = 0;
        $prevCollapsible = false;

        foreach ($node->children as $child) {
            $childStyle = $child->style;
            $childPosition = $childStyle['position'] ?? 'static';

            if ($childPosition === 'absolute' || $childPosition === 'fixed') {
                continue;
            }

            $mTop = PercentResolver::resolveMarginPaddingPercent($childStyle, 'marginTop', 'marginTopPercent', $containerW);
            $mBottom = PercentResolver::resolveMarginPaddingPercent($childStyle, 'marginBottom', 'marginBottomPercent', $containerW);

            // Auto-width: inherit from container
            $hasExplicitWidth = array_key_exists('width', $child->style) || array_key_exists('widthPercent', $child->style);
            if (!$hasExplicitWidth || $child->w === 0) {
                $child->w = max(0, (int)$containerW);
                $child->style['width'] = $containerW;
                $child->visualW = PercentResolver::resolveVisualW($childStyle, $child->w);
            }

            $child->w = max(0, (int)PercentResolver::resolveMinMax($childStyle, $child->w, true));
            $child->visualW = PercentResolver::resolveVisualW($childStyle, $child->w);

            $oldY = $child->y;

            // CSS 2.2 §8.3.1: 外边距折叠
            $childDisplay = $childStyle['display'] ?? 'block';
            $childOverflow = $childStyle['overflow'] ?? $childStyle['overflowY'] ?? 'visible';
            $createsBFC = ($childDisplay !== 'block')
                || ($childOverflow !== 'visible')
                || ($childStyle['float'] ?? 'none') !== 'none';
            $isCollapsible = !$createsBFC;

            if ($isCollapsible && $prevCollapsible && $mTop * $prevMarginBottom >= 0) {
                $positiveMax = max($prevMarginBottom > 0 ? $prevMarginBottom : 0, $mTop > 0 ? $mTop : 0);
                $negativeMin = min($prevMarginBottom < 0 ? $prevMarginBottom : 0, $mTop < 0 ? $mTop : 0);
                $collapsed = $positiveMax + $negativeMin;
                $child->y = $stackY - $prevMarginBottom + $collapsed;
            } elseif ($isCollapsible && $prevCollapsible) {
                $collapsed = $prevMarginBottom + $mTop;
                $child->y = $stackY - $prevMarginBottom + $collapsed;
            } else {
                $child->y = $stackY + $mTop;
            }

            $stackAdvanceY = $child->y;

            $relTop = $childStyle['top'] ?? 0;
            if (($childStyle['position'] ?? 'static') === 'relative' && $relTop !== 0) {
                $child->y += $relTop;
            }

            $dy = $child->y - $oldY;
            if ($dy !== 0) {
                foreach ($child->children as $grandchild) {
                    ScrollHelper::shiftDescendantsY($grandchild, $dy);
                }
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

    /**
     * Calculate contentHeight for a scroll container.
     */
    private function calcContentHeight(RenderNode $node, int $childOffsetY): int
    {
        $maxBottom = $childOffsetY;
        foreach ($node->children as $child) {
            $bottom = (int)($child->y + $child->visualH);
            if ($bottom > $maxBottom) $maxBottom = $bottom;
        }
        return $maxBottom - $node->y;
    }
}
