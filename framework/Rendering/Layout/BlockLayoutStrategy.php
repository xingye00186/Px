<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;

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
class BlockLayoutStrategy
{
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Block layout dispatcher.
     *
     * 统一尺寸解析（百分比 + min/max），然后根据 position 分发到:
     * - resolveNormalFlow（static/relative）
     * - AbsolutePositioning::resolveAbsolutePositioning（absolute/fixed）
     * 最后处理 scroll container post-processing + auto-width/height。
     */
    public function resolveBlockLayout(
        RenderNode  $node,
        int         $parentX,
        int         $parentY,
        ?RenderNode $parent,
        string      $position,
        array       &$scrollContainers,
        array       $style
    ): void
    {
        // Read position from style

        $left = $style['left'] ?? 0;

        $top = $style['top'] ?? 0;

        $right = $style['right'] ?? null;

        $bottom = $style['bottom'] ?? null;

        // CSS: percentage width resolves against content width
        // absolute/fixed: containing block is padding box (parent->w includes padding)
        // static/relative: containing block is content area (w minus padding)
        // When parent is null (top-level element under #root), use window viewport as containing block
        $parentW_raw = ($parent !== null) ? $parent->w : (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0);
        $parentH = ($parent !== null) ? $parent->h : (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0);
        $isAbsForW = ($position === 'absolute' || $position === 'fixed');
        if ($parent !== null && !$isAbsForW) {
            $padL = (int)($parent->style['paddingLeft'] ?? $parent->style['padding'] ?? 0);
            $padR = (int)($parent->style['paddingRight'] ?? $parent->style['padding'] ?? 0);
            $parentW = PercentResolver::computeContentWidth($parent->style, $parentW_raw);
        } else {
            $parentW = (int)$parentW_raw;
        }

        $width = PercentResolver::resolvePercent($style, 'width', 'widthPercent', $parentW);

        $height = PercentResolver::resolvePercent($style, 'height', 'heightPercent', $parentH);

        // Debug: log span dimensions before min/max
        if ($node->type === 'span' && $node->content !== null) {
            file_put_contents('d:\Px\_debug_out.txt', sprintf("DBG_SPAN_ENTER: content='%s' parentY=%d parentH=%d w=%d h=%d height=%d parent->h=%d\n", $node->content, $parentY, $parentH, $node->w, $node->h, $height, ($parent !== null) ? $parent->h : -1), FILE_APPEND);
        }

        // flex:1 已移至 flex 布局专用路径 (Task D)


        // ── 应用 min/max 约束到尺寸（在子节点递归之前，确保 parent->w/h 立即可用）──
        $node->w = max(0, (int)PercentResolver::applyMinMax($style, $width, true));

        // Debug: span h before min/max
        $dbg_span_h_before = $node->h;

        $node->h = max(0, (int)PercentResolver::applyMinMax($style, $height, false));

        // Debug: span h after min/max constraint
        if ($node->type === 'span' && $node->content !== null) {
            file_put_contents('d:\Px\_debug_out.txt', sprintf("DBG_SPAN_MINMAX: content='%s' p->h=%d bef=%d af=%d ht=%d\n", $node->content, ($parent !== null) ? $parent->h : -1, $dbg_span_h_before, $node->h, $height), FILE_APPEND);
        }


        // ── CSS 规范: 正常流块级元素未显式设置宽度时，应填充包含块内容宽度 ──
        $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);
        if (!$hasExplicitW && $width === 0 && !$isAbsForW && $parent !== null) {
            $node->w = max(0, (int)PercentResolver::applyMinMax($style, $parentW, true));
        }


        // -- Nodes with text content: measure text width instead of filling parent --
        if ($node->content !== null && is_string($node->content) && strlen($node->content) > 0) {
            $fs = (int)($style['fontSize'] ?? 14);
            $bd = ($style['fontWeight'] ?? 'normal') === 'bold' || ($style['fontWeight'] ?? 'normal') === '700';
            $measured = PercentResolver::measureTextWidth($node->content, $fs, $bd);
            if ($measured > 0) {
                // Text-measured width: only for text/span types (block layout width is auto-filled)
                // Flex items get their text-measured width in applyFlexBasis
                if ($node->type === 'text' || $node->type === 'span') {
                    $node->w = min($measured, max(0, (int)PercentResolver::applyMinMax($style, $measured, true)));
                }
            }
            // Text height = line-height if no explicit height
            if (!array_key_exists('height', $style) && !array_key_exists('heightPercent', $style)) {
                $lineH = PercentResolver::resolveLineHeight($style, $fs);
                if ($node->type === 'span' && $node->content !== null) {
                    file_put_contents('d:\Px\_debug_out.txt', sprintf("DBG_SPAN_AUTOH: content='%s' h=%d lineH=%d -> new_h=%d\n", $node->content, $node->h, $lineH, ($node->h === 0 || $node->h < $lineH) ? $lineH : $node->h), FILE_APPEND);
                }
                if ($node->h === 0 || $node->h < $lineH) {
                    $node->h = $lineH;
                }
            }
        }


        // ── 根据 position 分发 ──
        // B.4: position:fixed v1 退化为 absolute（同分支）
        $isAbsolute = ($position === 'absolute' || $position === 'fixed');


        if ($isAbsolute) {
            $this->resolver->getAbsolutePositioning()->resolveAbsolutePositioning($node, $parent, $style, $left, $top, $right, $bottom, $width, $height, $scrollContainers);

        } else {
            // static / relative
            $this->resolveNormalFlow($node, $parentX, $parentY, $parent, $position, $style, $left, $top, $scrollContainers);
        }


        // ── Scroll container post-processing for flex/grid display modes ──

        if ($node->isScrollContainer) {
            $paddingTop = $style['paddingTop'] ?? $style['padding'] ?? 0;

            $paddingRight = $style['paddingRight'] ?? $style['padding'] ?? 0;

            $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;

            // A1 重构: childOffsetY 不再减 scrollTop，偏移由 VNodeRenderer 在绘制层处理
            $childOffsetY = $node->y + $paddingTop;

            $this->finalizeScrollContainer($node, $style, $childOffsetY, $paddingLeft, $paddingRight, $scrollContainers);
        }


        // ── Task C: Normal Flow auto-stack for block containers ──

        $display = $style['display'] ?? 'block';


        if (!$isAbsolute && $display === 'block' && !$node->isScrollContainer) {
            // Static/relative children in block containers auto-stack vertically (CSS normal flow).

            // Scroll containers have their own auto-stack in finalizeScrollContainer.


            $paddingTop = $style['paddingTop'] ?? $style['padding'] ?? 0;

            $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;

            $paddingRight = $style['paddingRight'] ?? $style['padding'] ?? 0;


            if (count($node->children) > 0) {
                $stackY = $node->y + $paddingTop;

                $containerW = PercentResolver::computeContentWidth($node->style, $node->w);


                foreach ($node->children as $child) {
                    $childStyle = $child->style;

                    $childPosition = $childStyle['position'] ?? 'static';

                    // Skip absolute/fixed children — they don't participate in normal flow
                    if ($childPosition === 'absolute' || $childPosition === 'fixed') {
                        continue;
                    }

                    $mTop = $childStyle['marginTop'] ?? $childStyle['margin'] ?? 0;

                    $mBottom = $childStyle['marginBottom'] ?? $childStyle['margin'] ?? 0;

                    // Auto-width: inherit from container padding area (skip if percentage width)

                    $hasExplicitWidth = array_key_exists('width', $child->style) || array_key_exists('widthPercent', $child->style);

                    if (!$hasExplicitWidth || $child->w === 0) {
                        $child->w = max(0, (int)$containerW);

                        $child->style['width'] = $containerW;
                    }

                    // -- Children with text content: measure text width (only for content-sized children) --
                    if ($child->content !== null && is_string($child->content) && strlen($child->content) > 0) {
                        $fs = (int)($childStyle['fontSize'] ?? 14);
                        $bd = ($childStyle['fontWeight'] ?? 'normal') === 'bold' || ($childStyle['fontWeight'] ?? 'normal') === '700';
                        $measured = PercentResolver::measureTextWidth($child->content, $fs, $bd);
                        if ($measured > 0) {
                            // Block auto-stack children fill parent width — text-measured width only for span/text
                            // Flex items get text-measured width in applyFlexBasis via explicit width check
                            if ($child->type === 'text' || $child->type === 'span') {
                                $child->w = min($measured, max(0, (int)PercentResolver::applyMinMax($childStyle, $measured, true)));
                            }
                        }
                        // Text height = line-height if no explicit height
                        if (!array_key_exists('height', $childStyle)) {
                            $lineH = PercentResolver::resolveLineHeight($childStyle, $fs);
                            if ($child->h === 0 || $child->h < $lineH) {
                                $child->h = $lineH;
                            }
                        }
                    } else {
                        $child->w = max(0, (int)PercentResolver::applyMinMax($childStyle, $child->w, true));
                    }


                    // ── Two-pass: re-resolve internal children of sized items ──

                    $childDisplay = $childStyle['display'] ?? 'block';


                    if ($childDisplay === 'flex' || $childDisplay === 'grid') {
                        if (count($child->children) > 0) {
                            $child->layoutDirty = true;

                            foreach ($child->children as $gc) {
                                $gc->layoutDirty = true;
                            }

                            $this->resolver->resolveNode($child, $node->x + $paddingLeft, $stackY, $node, $scrollContainers);
                        }
                    }


                    // ── Auto-width/height for block containers (CSS content-based sizing) ──

                    $childML = $childStyle['marginLeftAuto'] ?? false;

                    $childMR = $childStyle['marginRightAuto'] ?? false;


                    if ($childML || $childMR) {
                        $this->resolver->getAbsolutePositioning()->resolveMarginAuto($child, $childStyle, $containerW, 0);
                    }


                    // Stack vertically with margin

                    $oldY = $child->y;

                    $child->y = $stackY + $mTop;

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

                    $stackY += $child->h + $mBottom;
                }
            }
        }


        // ── Auto-width/height for block containers (CSS content-based sizing) ──

        $hasExplicitWidth = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);

        $hasExplicitHeight = array_key_exists('height', $style) || array_key_exists('heightPercent', $style);


        if (!$hasExplicitWidth && $display === 'block') {
            $maxRight = 0;

            foreach ($node->children as $child) {
                $childRight = (int)($child->x + $child->w);

                if ($childRight > $maxRight) $maxRight = $childRight;
            }

            $computedW = max(0, $maxRight - $node->x);

            if ($computedW > $node->w) {
                $node->w = max(0, (int)PercentResolver::applyMinMax($style, $computedW, true));

                $padL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);

                $padR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);

                $contentW = PercentResolver::computeContentWidth($style, $node->w);

                if ($contentW > 0) {
                    foreach ($node->children as $child) {
                        $cs = $child->style;

                        if (!array_key_exists('width', $cs)) {
                            $child->w = max(0, $contentW);

                            $child->w = max(0, (int)PercentResolver::applyMinMax($cs, $child->w, true));
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
                $childBottom = (int)($child->y + $child->h);

                if ($childBottom > $maxBottom) $maxBottom = $childBottom;
            }

            $computedH = max(0, $maxBottom - $node->y);

            if ($computedH > $node->h) {
                $node->h = max(0, (int)PercentResolver::applyMinMax($style, $computedH, false));
            }
        }
    }

    /**
     * Normal flow positioning (static/relative).
     *
     * static: 完全忽略 left/top/right/bottom，不推进 stack。
     * relative: left/top 作为附加偏移量（不影响兄弟节点的 stack 位置）。
     */
    private function resolveNormalFlow(
        RenderNode  $node,
        int         $parentX,
        int         $parentY,
        ?RenderNode $parent,
        string      $position,
        array       $style,
        int         $left,
        int         $top,
        array       &$scrollContainers
    ): void
    {
        $marginLeft = $style['marginLeft'] ?? $style['margin'] ?? 0;

        $marginTop = $style['marginTop'] ?? $style['margin'] ?? 0;

        $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;

        $paddingTop = $style['paddingTop'] ?? $style['padding'] ?? 0;

        // Base position = parent content area

        $node->x = $parentX + $marginLeft;

        $node->y = $parentY + $marginTop;

        // relative: left/top 作为额外偏移（不改变 stack 推进位置）
        if ($position === 'relative') {
            $node->x += $left;

            $node->y += $top;
        }

        // static: left/top/right/bottom 完全忽略


        // Apply translate from animatedStyle

        $translateX = $style['translateX'] ?? 0;

        $translateY = $style['translateY'] ?? 0;

        $node->x += $translateX;

        $node->y += $translateY;

        // Resolve children recursively

        $childOffsetX = $node->x + $paddingLeft;

        $childOffsetY = $node->y + $paddingTop;

        foreach ($node->children as $child) {
            $this->resolver->resolveNode($child, $childOffsetX, $childOffsetY, $node, $scrollContainers);
        }
    }

    /**
     * Scroll container post-processing: auto-stack + contentHeight + scroll clamps.
     *
     * Extracted from resolveBlockLayout to keep method focused.
     * Preserves all original scroll container behaviors.
     */
    public function finalizeScrollContainer(
        RenderNode $node,
        array      $style,
        int        $childOffsetY,
        int        $paddingLeft,
        int        $paddingRight,
        array      &$scrollContainers
    ): void
    {
        // ── Auto-stack: for scroll containers, position children vertically ──

        $stackY = $childOffsetY;
        file_put_contents('d:\\Px\\_debug_out.txt', sprintf("DBG_FINALIZE_SCROLL: node=%s y=%d h=%d scrollTop=%d childOffsetY=%d children=%d\n", $node->type, $node->y, $node->h, $node->scrollTop, $childOffsetY, count($node->children)), FILE_APPEND);

        $containerW = PercentResolver::computeContentWidth($style, $node->w);

        $autoStack = true;

        if ($autoStack) {
            foreach ($node->children as $child) {
                $childStyle = $child->style;

                $childPosition = $childStyle['position'] ?? 'static';

                if ($childPosition === 'absolute' || $childPosition === 'fixed') {
                    continue;
                }

                $mTop = $childStyle['marginTop'] ?? $childStyle['margin'] ?? 0;

                $mBottom = $childStyle['marginBottom'] ?? $childStyle['margin'] ?? 0;

                // Auto-width: inherit from container (skip if percentage width)
                $hasExplicitWidth = array_key_exists('width', $child->style) || array_key_exists('widthPercent', $child->style);

                if (!$hasExplicitWidth || $child->w === 0) {
                    $child->w = max(0, (int)$containerW);

                    $child->style['width'] = $containerW;
                }

                // Apply min/max to child width

                $child->w = max(0, (int)PercentResolver::applyMinMax($childStyle, $child->w, true));

                // Auto-position: stack vertically with margin

                $oldY = $child->y;

                $child->y = $stackY + $mTop;

                // position:relative 额外偏移 — 只对 y 生效
                $relTop = $childStyle['top'] ?? 0;

                if (($childStyle['position'] ?? 'static') === 'relative' && $relTop !== 0) {
                    $child->y += $relTop;
                }

                // 仅平移子节点的后代（child 本身已在上方被正确设置位置）
                $dy = $child->y - $oldY;

                if ($dy !== 0) {
                    foreach ($child->children as $grandchild) {
                        ScrollHelper::shiftDescendantsY($grandchild, $dy);
                    }
                }

                $stackY += $child->h + $mBottom;
            }
        }

        // ── Calculate contentHeight (always, not just for autoStack) ────

        $maxBottom = $childOffsetY;

        foreach ($node->children as $child) {
            $bottom = (int)($child->y + $child->h);

            if ($bottom > $maxBottom) $maxBottom = $bottom;
        }

        // ── contentHeight: total scrollable content height (CSS scrollHeight) ──
        // A1 重构: children 布局坐标不再包含 scrollTop 偏移
        // contentHeight = children 底部最大值 - 容器顶部坐标
        $node->contentHeight = $maxBottom - $node->y;

        // ── Clamp scrollTop when content shrinks ────
        // A1 重构: 仅 clamp scrollTop，不需平移子节点坐标

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

                $cWidth = $child->style['width'] ?? $child->w;

                $right = (int)($cLeft + $cWidth);

                if ($right > $maxRight) $maxRight = $right;
            }

            $node->contentWidth = max($maxRight, $node->w);

            // Clamp scrollLeft when content shrinks

            $maxScrollX = max($node->contentWidth - $node->w, 0);

            if ($node->scrollLeft > $maxScrollX) {
                $oldScrollLeft = $node->scrollLeft;

                $node->scrollLeft = $maxScrollX;

                $shiftRight = $oldScrollLeft - $node->scrollLeft;

                if ($shiftRight > 0) {
                    // A1 重构: 仅 clamp scrollLeft，不需平移子节点坐标
                }
            }

        } else {
            // No horizontal scroll — content width equals container width
            $node->contentWidth = $node->w;
        }
    }
}
