<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Core\Config;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\Layout\Tools\PercentResolver;
use Px\Rendering\Layout\Tools\ScrollHelper;

/**
 * BlockLayoutStrategy �?Block 布局策略
 *
 * 处理 display:block（包�?inline-block）和 scroll-container 的布局�?
 * 负责:
 * - 尺寸解析（百分比 + min/max + 文本测量�?
 * - �?position 分发�?normal flow �?absolute/fixed
 * - Scroll container post-processing（auto-stack + contentHeight + clamp�?
 * - Normal flow auto-stack
 */
class BlockLayoutStrategy implements LayoutStrategyInterface
{
    /** HTML inline elements: width should be text-measured, not container-filled */
    private const INLINE_TYPES = ['#text','text','span','b','strong','em','i','code','br','a','label','abbr','cite','dfn','kbd','mark','q','samp','small','sub','sup','time','var'];

    private static function isInlineType(string $type): bool
    {
        return in_array($type, self::INLINE_TYPES, true);
    }

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
     * Block layout �?normal flow (static/relative).
     *
     * 统一尺寸解析（百分比 + min/max + 文本测量），
     * 然后调用 resolveNormalFlow 定位�?
     * 最后处�?scroll container post-processing + auto-width/height�?
     */
    public function resolveBlockLayout(
        RenderNode    $node,
        LayoutContext $ctx,
        array         $style
    ): void
    {
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



        // flex:1 已移�?flex 布局专用路径 (Task D)


        // ── 应用 min/max 约束到尺寸（在子节点递归之前，确�?parent->w/h 立即可用）──
        $node->w = (int)max(0, (int)PercentResolver::resolveMinMax($style, $width, true));

        // Debug: span h before min/max
        $dbg_span_h_before = $node->h;

        // ⚠️ 仅当有显式 height 或当前 h 为 0 时才覆盖 h。
        // 对于 flex/grid 容器子节点，父容器已经设好了正确的 h（如 flex:1 分派的高度），
        // 但这里没有显式 height 时 $height=0 → 覆盖为 0，导致后续 clamp 用 h=0 计算 maxScroll，
        // scrollTop 无法正确限界，列表会无限空滚。
        if ($height > 0 || $node->h === 0) {
            $node->h = (int)max(0, (int)PercentResolver::resolveMinMax($style, $height, false));
        }

        $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
        $node->visualH = PercentResolver::resolveVisualH($style, $node->h);




        // ── CSS 2.1 §10.3.3: 正常流块级元素未显式宽度时，填充包含块内容宽度 ──
        // CSS Box Model: $node->w 统一存储 CSS 'width' 属性的计算值
        //   content-box: CSS 'width' = content width
        //   border-box:  CSS 'width' = total width (含 padding+border)
        $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);
        if (!$hasExplicitW && $width === 0 && $ctx->parent !== null && !self::isInlineType($node->type)) {
            error_log('[DIAG_LOC1_CHECK] node=' . $node->type . ' content_null=' . ($node->content === null ? '1' : '0') . ' content_str=' . (is_string($node->content) ? '1' : '0') . ' content_len=' . (is_string($node->content) ? strlen($node->content) : -1));
            $autoPadL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
            $autoPadR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);
            $autoBw = (int)($style['borderWidth'] ?? 0);
            $boxSizing = $style['boxSizing'] ?? 'content-box';
            if ($boxSizing === 'border-box') {
                $autoW = max(0, $parentW);
            } else {
                $autoW = max(0, $parentW - $autoPadL - $autoPadR - $autoBw * 2);
            }
            error_log('[DIAG_BLKAF] node=' . $node->type . ' parentW=' . $parentW . ' autoW=' . $autoW . ' hasExplicitW=' . ($hasExplicitW ? '1' : '0') . ' parent=' . ($ctx->parent !== null ? $ctx->parent->type : 'null'));
            $node->w = (int)max(0, (int)PercentResolver::resolveMinMax($style, $autoW, true));
            $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
        }

        // [DIAG] Log node final width after auto-fill
        if ($node->x === 300) {
            $hasEW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);
            error_log('[DIAG_PADDING] AFTER auto-fill: node=' . $node->type . ' x=' . $node->x . ' w=' . $node->w . ' parentW=' . $parentW . ' parent=' . ($ctx->parent !== null ? $ctx->parent->type : 'null') . ' hasEW=' . ($hasEW ? '1' : '0') . ' width=' . ($style['width'] ?? 'NULL') . ' widthPct=' . ($style['widthPercent'] ?? 'NULL'));
        }


        // Resolve fontSize from relative unit (rem/em/vw/vh)
        PercentResolver::resolveFontSizeUnit($style);
        // CSS 2.2 §15.1.1: font-size 继承属性，未显式设置时从父容器继承
        // 父容器也未有则使用 CSS 初始值 medium = 16px
        if (isset($style['fontSize'])) {
            $node->style['fontSize'] = $style['fontSize'];
        } elseif ($ctx->parent !== null && isset($ctx->parent->style['fontSize'])) {
            $node->style['fontSize'] = $ctx->parent->style['fontSize'];
        } else {
            $node->style['fontSize'] = 16;
        }

        // -- Nodes with text content: measure text width instead of filling parent --
        error_log('[DIAG_LOC1] node=' . $node->type . ' hasContent=' . ($node->content !== null && is_string($node->content) && strlen($node->content) > 0 ? '1' : '0') . ' isInline=' . (self::isInlineType($node->type) ? '1' : '0'));
        if ($node->content !== null && is_string($node->content) && strlen($node->content) > 0) {
            $fs = (int)($node->style['fontSize']);
            $bd = ($style['bold'] ?? 0) !== 0;
            $measured = PercentResolver::resolveTextWidth($node->content, $fs, $bd);
            if ($measured > 0) {
                // CSS: inline elements use text-measured width, block fills parent
                if (self::isInlineType($node->type)) {
                    $newW = (int)min($measured, (int)max(0, (int)PercentResolver::resolveMinMax($style, $measured, true)));
                    error_log('[DIAG_LOC1_SET] type=' . $node->type . ' measured=' . $measured . ' oldW=' . $node->w . ' newW=' . $newW);
                    $node->w = $newW;
                    $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
                }
            }
        } elseif ($node->type === 'br') {
            // CSS: <br> is a zero-width line break — set w=0, h=line-height
            $node->w = 0;
            $node->visualW = 0;
            $fs = (int)($node->style['fontSize']);
            $parentStyle = $ctx->parent !== null ? $ctx->parent->style : null;
            $lineH = PercentResolver::resolveLineHeight($style, $fs, 16, $parentStyle);
            $node->h = $lineH;
            $node->visualH = $lineH;
            // Text height = line-height if no explicit height
            // CSS 2.2 §10.8.1: 从父容器继承 line-height
            if (!array_key_exists('height', $style) && !array_key_exists('heightPercent', $style)) {
                $parentStyle = $ctx->parent !== null ? $ctx->parent->style : null;
                $lineH = PercentResolver::resolveLineHeight($style, $fs, 16, $parentStyle);

                if ($node->h === 0 || $node->h < $lineH) {
                    $node->h = $lineH;
                    // CSS border-box: resolveVisualH 在 border-box 模式返回 h（假设 h
                    // 已含 padding+border），但 auto-height 时 h 仅内容高度。显式计算 visualH
                    // 并在 border-box 模式下同步更新 h 为总高度（含 padding+border）。
                    $aPadT = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
                    $aPadB = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);
                    $aBtw = (int)($style['borderTopWidth'] ?? $style['borderWidth'] ?? 0);
                    $aBbw = (int)($style['borderBottomWidth'] ?? $style['borderWidth'] ?? 0);
                    $boxSizing = $style['boxSizing'] ?? 'content-box';
                    if ($boxSizing === 'border-box') {
                        // border-box: h=总高度（含 padding+border），content-box: h=内容高度
                        $node->h = max(0, $node->h + $aPadT + $aPadB + $aBtw + $aBbw);
                    }
                    $node->visualH = max(0, $node->h + $aPadT + $aPadB + $aBtw + $aBbw);
                }

                // CSS 2.2 §10.6.3: auto-wrap text when exceeds container content width
                // white-space:nowrap/pre 不换行；默认为 normal 需要换行
                $ws = $style['whiteSpace'] ?? 'normal';
                if ($measured > 0 && $node->w > 0 && $ws !== 'nowrap' && $ws !== 'pre') {
                    $wrapPadL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
                    $wrapPadR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);
                    $wrapBw = (int)($style['borderLeftWidth'] ?? $style['borderWidth'] ?? 0);
                    $wrapBwR = (int)($style['borderRightWidth'] ?? $style['borderWidth'] ?? 0);
                    $containerTextW = max(1, $node->w - $wrapPadL - $wrapPadR - $wrapBw - $wrapBwR);
                    if ($measured > $containerTextW) {
                        $numLines = (int)max(1, (int)ceil($measured / $containerTextW));
                        // 对于特定字符宽度大于行宽的情况，退化为逐字符测量
                        if ($numLines * $containerTextW < $measured) {
                            $numLines = (int)ceil($measured / $containerTextW);
                        }
                        $wrappedH = (int)($numLines * $lineH);
                        if ($wrappedH > $node->h) {
                            $node->h = $wrappedH;
                            // border-box 补偿（同 auto-height 路径）
                            $wPadT = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
                            $wPadB = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);
                            $wBtw = (int)($style['borderTopWidth'] ?? $style['borderWidth'] ?? 0);
                            $wBbw = (int)($style['borderBottomWidth'] ?? $style['borderWidth'] ?? 0);
                            $node->visualH = max(0, $node->h + $wPadT + $wPadB + $wBtw + $wBbw);
                        }
                    }
                }

                // CSS Text Module Level 3 §4.1: white-space:pre preserves newlines
                if ($ws === 'pre' && $node->content !== null && is_string($node->content)) {
                    $newlineCount = substr_count($node->content, "\n");
                    if ($newlineCount > 0) {
                        $preLines = $newlineCount + 1;
                        $preH = (int)($preLines * $lineH);
                        if ($preH > $node->h) {
                            $node->h = $preH;
                            // border-box 补偿（同 auto-height 路径）
                            $pPadT = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
                            $pPadB = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);
                            $pBtw = (int)($style['borderTopWidth'] ?? $style['borderWidth'] ?? 0);
                            $pBbw = (int)($style['borderBottomWidth'] ?? $style['borderWidth'] ?? 0);
                            $node->visualH = max(0, $node->h + $pPadT + $pPadB + $pBtw + $pBbw);
                        }
                    }
                }
            }
        }

        // CSS 2.2 §10.7: Re-apply max-height constraint after inline content
        // processing may have increased h (text wrapping) beyond max-height.
        $maxH = (int)($style['maxHeight'] ?? 0);
        if ($maxH > 0 && $node->h > $maxH) {
            $node->h = $maxH;
            $node->visualH = PercentResolver::resolveVisualH($style, $node->h);
        }


        // ── Normal flow positioning (static/relative) ──
        $position = $style['position'] ?? 'static';
        $this->resolveNormalFlow($node, $ctx, $position, $style, $left, $top);


        // ── Scroll container post-processing ──

        if ($node->isScrollContainer) {
            $paddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);

            $paddingRight = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);

            $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);

            $paddingBottom = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);

            // A1 重构: childOffsetY 不再加上 scrollTop，偏移由 VNodeRenderer 在绘制层处理
            $childOffsetY = $node->y + $paddingTop;

            $this->finalizeScrollContainer($node, $ctx, $style, $childOffsetY, $paddingLeft, $paddingRight, $paddingBottom);
        }


        // ── Task C: Normal Flow auto-stack for block containers ──

        $display = $style['display'] ?? 'block';


        if ($display === 'block' && !$node->isScrollContainer) {
            // Static/relative children in block containers auto-stack vertically (CSS normal flow).

            // Scroll containers have their own auto-stack in finalizeScrollContainer.


            $paddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);

            $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);

            $paddingRight = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);

            // CSS 2.2 §8.4: padding box 起始于 border 内侧
            $borderTop = (int)($style['borderTopWidth'] ?? $style['borderWidth'] ?? 0);


            if (count($node->children) > 0) {
                $stackY = $node->y + $borderTop + $paddingTop;

                $containerW = PercentResolver::resolveContentWidth($node->style, $node->w);

                // CSS 2.2 §8.3.1: 跟踪上一个可折叠兄弟�?margin-bottom
                $prevMarginBottom = 0;
                $prevCollapsible = false;

                foreach ($node->children as $child) {
                    $childStyle = $child->style;

                    $childPosition = $childStyle['position'] ?? 'static';

                    // Skip absolute/fixed children (they don't participate in normal flow)
                    // Skip display:none children (CSS 2.2 §9.2.4: generate no box)
                    $childDisplay = $childStyle['display'] ?? 'block';
                    if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') {
                        continue;
                    }

                    $mTop = PercentResolver::resolveMarginPaddingPercent($childStyle, 'marginTop', 'marginTopPercent', $containerW);

                    $mBottom = PercentResolver::resolveMarginPaddingPercent($childStyle, 'marginBottom', 'marginBottomPercent', $containerW);

                    // CSS 2.1 §10.3.3: Auto-width = containerW - child's own padding - child's own border

                    $hasExplicitWidth = array_key_exists('width', $child->style) || array_key_exists('widthPercent', $child->style);

                    if ((!$hasExplicitWidth || $child->w === 0) && !self::isInlineType($child->type)) {
                        $autoPadL = (int)($childStyle['paddingLeft'] ?? $childStyle['padding'] ?? 0);
                        $autoPadR = (int)($childStyle['paddingRight'] ?? $childStyle['padding'] ?? 0);
                        $autoBw = (int)($childStyle['borderWidth'] ?? 0);
                        $childBoxSizing = $childStyle['boxSizing'] ?? 'content-box';
                        if ($childBoxSizing === 'border-box') {
                            // border-box: CSS 'width' = total width = container content width
                            $autoW = max(0, (int)$containerW);
                        } else {
                            // content-box: CSS 'width' = content width = container content - own padding - own border
                            $autoW = max(0, (int)$containerW - $autoPadL - $autoPadR - $autoBw * 2);
                        }
                        $child->w = $autoW;
                        $child->visualW = PercentResolver::resolveVisualW($childStyle, $child->w);
                    }

                    // Resolve fontSize from relative unit for child
                    PercentResolver::resolveFontSizeUnit($child->style);
                    // CSS 继承：子元素未设 font-size 时从父元素继承
                    if (!isset($child->style['fontSize'])) {
                        $child->style['fontSize'] = $node->style['fontSize'];
                    }
                    $childStyle['fontSize'] = $child->style['fontSize'];

                    // -- Children with text content: measure text width (inline elements use text-width) --
                    if ($child->content !== null && is_string($child->content) && strlen($child->content) > 0) {
                        $fs = (int)($child->style['fontSize']);
                        $bd = ($childStyle['bold'] ?? 0) != 0;
                        $measured = PercentResolver::resolveTextWidth($child->content, $fs, $bd);
                        error_log('[DIAG_INLINE] child=' . $child->type . ' content_len=' . strlen($child->content) . ' measured=' . $measured . ' isInline=' . (self::isInlineType($child->type) ? '1' : '0'));
                        if ($measured > 0) {
                            // CSS: inline children use text-measured width; block children fill parent
                            if (self::isInlineType($child->type)) {
                                $child->w = min($measured, max(0, (int)PercentResolver::resolveMinMax($childStyle, $measured, true)));
                                $child->visualW = PercentResolver::resolveVisualW($childStyle, $child->w);
                            }
                        }
                        // Text height = line-height if no explicit height
                        // CSS 2.2 §10.8.1: 从父容器继承 line-height
                        if (!array_key_exists('height', $childStyle)) {
                            $lineH = PercentResolver::resolveLineHeight($childStyle, $fs, 16, $style);
                            if ($child->h === 0 || $child->h < $lineH) {
                                $child->h = $lineH;
                                // CSS border-box: visualH for auto-height must include padding+border
                                // resolveVisualH in border-box mode returns h unchanged (assumes h
                                // already includes padding+border), but for auto-height h is content
                                // height only. Compute visualH explicitly.
                                $cPadT = (int)($childStyle['paddingTop'] ?? $childStyle['padding'] ?? 0);
                                $cPadB = (int)($childStyle['paddingBottom'] ?? $childStyle['padding'] ?? 0);
                                $cBtw = (int)($childStyle['borderTopWidth'] ?? $childStyle['borderWidth'] ?? 0);
                                $cBbw = (int)($childStyle['borderBottomWidth'] ?? $childStyle['borderWidth'] ?? 0);
                                $child->visualH = max(0, $child->h + $cPadT + $cPadB + $cBtw + $cBbw);
                            }

                            // CSS 2.2 §10.6.3: auto-wrap child text when exceeds container content width
                            $childWs = $childStyle['whiteSpace'] ?? 'normal';
                            if ($measured > 0 && $child->w > 0 && $childWs !== 'nowrap' && $childWs !== 'pre') {
                                $cPadL = (int)($childStyle['paddingLeft'] ?? $childStyle['padding'] ?? 0);
                                $cPadR = (int)($childStyle['paddingRight'] ?? $childStyle['padding'] ?? 0);
                                $cBwL = (int)($childStyle['borderLeftWidth'] ?? $childStyle['borderWidth'] ?? 0);
                                $cBwR = (int)($childStyle['borderRightWidth'] ?? $childStyle['borderWidth'] ?? 0);
                                $childTextW = max(1, $child->w - $cPadL - $cPadR - $cBwL - $cBwR);
                                if ($measured > $childTextW) {
                                    $numLines = (int)max(1, (int)ceil($measured / $childTextW));
                                    $wrappedH = (int)($numLines * $lineH);
                                    if ($wrappedH > $child->h) {
                                        $child->h = $wrappedH;
                                        $cPadT2 = (int)($childStyle['paddingTop'] ?? $childStyle['padding'] ?? 0);
                                        $cPadB2 = (int)($childStyle['paddingBottom'] ?? $childStyle['padding'] ?? 0);
                                        $cBtw2 = (int)($childStyle['borderTopWidth'] ?? $childStyle['borderWidth'] ?? 0);
                                        $cBbw2 = (int)($childStyle['borderBottomWidth'] ?? $childStyle['borderWidth'] ?? 0);
                                        $child->visualH = max(0, $child->h + $cPadT2 + $cPadB2 + $cBtw2 + $cBbw2);
                                    }
                                }
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

                    // CSS 2.2 §10.3.3: 正常流块级子元素的初始 x = 父内容区左边界
                    // 必须在 auto-margin 之前设置，确保 margin:auto 居中基于正确基线
                    // 注意：flex/grid 子节点的 x 已由各自布局策略(FlexLayoutStrategy等)
                    // 在 two-pass (第359行) 中正确设置（含 margin:auto 居中偏移），
                    // 此处不再覆盖，否则居中偏移会被清除。
                    $childDisplayCheck = $childStyle['display'] ?? 'block';
                    if ($childDisplayCheck !== 'flex' && $childDisplayCheck !== 'inline-flex' && $childDisplayCheck !== 'grid') {
                        $child->x = $node->x + $paddingLeft;
                    }

                    $childML = $childStyle['marginLeftAuto'] ?? false;

                    $childMR = $childStyle['marginRightAuto'] ?? false;


                    if (($childML || $childMR)) {
                        // Browser-equivalent: flex-grow items' auto-margin is deferred
                        // to two-pass (when final width is known). Skip first-pass.
                        if (empty($node->style['_deferAutoMargin'])) {
                            // Flex/grid containers handle auto-margin internally in
                            // their own resolve() — skip here to avoid double-apply.
                            $childDisplay = $childStyle['display'] ?? 'block';
                            if ($childDisplay !== 'flex' && $childDisplay !== 'inline-flex' && $childDisplay !== 'grid') {
                                $oldX = $child->x;
                                $this->resolver->getAbsolutePositioning()->resolveMarginAuto($child, $childStyle, $containerW, 0);
                                $dx = $child->x - $oldX;
                                // 仅在首次 auto-margin 时位移子元素
                                // 第二遍（_marginAutoShifted 已置位）已位移过，防止重复
                                $alreadyShifted = $child->style['_marginAutoShifted'] ?? false;
                                if ($dx !== 0 && !$alreadyShifted) {
                                    foreach ($child->children as $grandchild) {
                                        ScrollHelper::shiftDescendantsX($grandchild, $dx);
                                    }
                                }
                                $child->style['_marginAutoShifted'] = true;
                            }
                        }
                    }


                    // ── CSS 2.2 §8.3.1: 外边距折�?──
                    // 仅在相同 BFC 内的 block 兄弟之间发生
                    $childOverflow = $childStyle['overflow'] ?? $childStyle['overflowY'] ?? 'visible';
                    $createsBFC = ($childDisplay !== 'block')
                        || ($childOverflow !== 'visible')
                        || ($childStyle['float'] ?? 'none') !== 'none';
                    $isCollapsible = !$createsBFC;

                    $oldY = $child->y;

                    if ($isCollapsible && $prevCollapsible && $mTop * $prevMarginBottom >= 0) {
                        // 对于同号边距：折叠结�?= max(positives) + min(negatives)
                        $positiveMax = max($prevMarginBottom > 0 ? $prevMarginBottom : 0, $mTop > 0 ? $mTop : 0);
                        $negativeMin = min($prevMarginBottom < 0 ? $prevMarginBottom : 0, $mTop < 0 ? $mTop : 0);
                        $collapsed = $positiveMax + $negativeMin;
                        $child->y = $stackY - $prevMarginBottom + $collapsed;
                    } elseif ($isCollapsible && $prevCollapsible) {
                        // 异号边距（一正一负）：折叠结�?= 直接相加
                        $collapsed = $prevMarginBottom + $mTop;
                        $child->y = $stackY - $prevMarginBottom + $collapsed;
                    } else {
                        $child->y = $stackY + $mTop;
                    }

                    // 保存 stack 推进位置（不�?position:relative 偏移影响�?
                    $stackAdvanceY = $child->y;

                    // position:relative 额外偏移（不推进 stack�?
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
                        // 创建�?BFC 的元素阻止外边距折叠穿�?
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

            if ($node->x === 300) {
                error_log('[DIAG_PADDING] auto-width: node=' . $node->type . ' x=' . $node->x . ' currentW=' . $node->w . ' maxRight=' . $maxRight . ' computedW=' . $computedW);
            }

            if ($computedW > $node->w) {
                $node->w = (int)max(0, (int)PercentResolver::resolveMinMax($style, $computedW, true));

                $padL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);

                $padR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);

                $contentW = PercentResolver::resolveContentWidth($style, $node->w);

                if ($node->x === 300) {
                    error_log('[DIAG_PADDING] auto-width TRIGGERED! node=' . $node->type . ' x=' . $node->x . ' newW=' . $node->w . ' contentW=' . $contentW);
                }

                if ($contentW > 0) {
                    foreach ($node->children as $child) {
                        $cs = $child->style;

                        if (!array_key_exists('width', $cs) && !self::isInlineType($child->type)) {
                            $autoPadL = (int)($cs['paddingLeft'] ?? $cs['padding'] ?? 0);
                            $autoPadR = (int)($cs['paddingRight'] ?? $cs['padding'] ?? 0);
                            $autoBw = (int)($cs['borderWidth'] ?? 0);
                            $childBoxSizing = $cs['boxSizing'] ?? 'content-box';
                            if ($childBoxSizing === 'border-box') {
                                // border-box: CSS 'width' = total width = contentW
                                $autoW = max(0, $contentW);
                            } else {
                                // content-box: CSS 'width' = content width = contentW - own padding - own border
                                $autoW = max(0, $contentW - $autoPadL - $autoPadR - $autoBw * 2);
                            }
                            $child->w = (int)max(0, (int)PercentResolver::resolveMinMax($cs, $autoW, true));
                            $child->visualW = PercentResolver::resolveVisualW($cs, $child->w);
                        }
                    }
                }
            }
        }


        $overflowY = $style['overflowY'] ?? $style['overflow'] ?? 'visible';

        $isAutoHeight = (!$hasExplicitHeight) ||
            ($hasExplicitHeight && $node->h === 0 && $overflowY !== 'hidden' && $overflowY !== 'scroll');

        if ($isAutoHeight && $display === 'block' && !$node->isScrollContainer) {
            $maxBottom = 0;

            foreach ($node->children as $child) {
                // CSS 2.2 §10.6.3: absolute/fixed 子节点不参与 auto-height 计算
                // CSS 2.2 §9.2.4: display:none 子节点也不参与
                $childPosition = $child->style['position'] ?? 'static';
                $childDisplay = $child->style['display'] ?? 'block';
                if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') {
                    continue;
                }
                $childBottom = (int)($child->y + $child->visualH);

                if ($childBottom > $maxBottom) $maxBottom = $childBottom;
            }

            // CSS 2.2 §10.6.3: auto-height = distance from content edge top to last child bottom
            // $node->y includes paddingTop offset �?content area starts at $node->y + $paddingTop
            $ahPaddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
            $contentTop = $node->y + $ahPaddingTop;
            if ($node->content !== null && is_string($node->content) && strlen($node->content) > 0) {
                $textFs = (int)($node->style['fontSize'] ?? 14);
                $parentSt = $ctx->parent !== null ? $ctx->parent->style : null;
                $textLineH = (int)PercentResolver::resolveLineHeight($style, $textFs, 16, $parentSt);
                $textBottom = $contentTop + $textLineH;
                if ($textBottom > $maxBottom) $maxBottom = $textBottom;
            }
            $computedH = max(0, $maxBottom - $contentTop);

            if ($computedH > $node->h) {
                $node->h = (int)max(0, (int)PercentResolver::resolveMinMax($style, $computedH, false));
            }
        }

        // CSS border-box: For auto-height blocks, visualH = contentH + padding + border
        // resolveVisualH in border-box mode returns h unchanged (assumes h already includes
        // padding+border), but auto-height h is content height only. Compute explicitly.
        if ($isAutoHeight) {
            $ahPadT = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
            $ahPadB = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);
            $ahBtw = (int)($style['borderTopWidth'] ?? $style['borderWidth'] ?? 0);
            $ahBbw = (int)($style['borderBottomWidth'] ?? $style['borderWidth'] ?? 0);
            $node->visualH = max(0, $node->h + $ahPadT + $ahPadB + $ahBtw + $ahBbw);
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

        // Set container's own visualW/visualH
        // Note: For auto-height elements, visualH is already set above with padding+border.
        // For explicit-height elements in border-box, resolveVisualW/H correctly return w/h
        // which already include padding+border.
        $node->visualW = PercentResolver::resolveVisualW($style, $node->w);
        if (!$isAutoHeight) {
            $node->visualH = PercentResolver::resolveVisualH($style, $node->h);
        }

        // [DIAG] Log node final width at resolveBlockLayout end
        if ($node->x === 300) {
            error_log('[DIAG_PADDING] FINAL at resolveBlockLayout end: node=' . $node->type . ' x=' . $node->x . ' w=' . $node->w . ' h=' . $node->h . ' hasExplicitW=' . (array_key_exists('width', $style) ? '1' : '0'));
        }
    }

    /**
     * Normal flow positioning (static/relative).
     *
     * static: 完全忽略 left/top/right/bottom，不推进 stack�?
     * relative: left/top 作为附加偏移量（不影响兄弟节点的 stack 位置）�?
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
        
        // CSS 2.2 §8.4: padding box 起始于 border 内侧
        $borderTop = (int)($style['borderTopWidth'] ?? $style['borderWidth'] ?? 0);
        $borderLeft = (int)($style['borderLeftWidth'] ?? $style['borderWidth'] ?? 0);
        
        // Base position = parent content area

        $node->x = $ctx->parentX + $marginLeft;
        // x was recomputed from parent context; auto-margin offset is now stale.
        // resolveMarginAuto will re-apply with correct parent width on next pass.
        $node->style['_marginAutoOffsetX'] = 0;

        $node->y = $ctx->parentY + $marginTop;

        // relative: left/top 作为额外偏移（不改变 stack 推进位置�?
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

        // Resolve children recursively (skip absolute/fixed �?resolved in second pass after container height is known)

        $childOffsetX = $node->x + $borderLeft + $paddingLeft;
        
        $childOffsetY = $node->y + $borderTop + $paddingTop;

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
        int           $paddingRight,
        int           $paddingBottom = 0
    ): void
    {
        $containerW = PercentResolver::resolveContentWidth($style, $node->w);

        // ── Auto-stack: for scroll containers, position children vertically ──
        $this->autoStackChildren($node, $childOffsetY, $containerW);

        // ── Calculate initial contentHeight ──
        // CSS Overflow: scrollable content area includes paddingBottom
        $node->contentHeight = $this->calcContentHeight($node, $childOffsetY) + $paddingBottom;

        // ── CSS Overflow Module Level 3 §2.3: 滚动条占用内容区宽度 ──
        // 检测是否需要垂直滚动条，若需要则从容器宽度中减去 scrollbar 宽度
        // 并重新布局子节�?
        $overflowY = $node->style['overflowY'] ?? $node->style['overflow'] ?? 'visible';
        $needsVScroll = ($overflowY === 'auto' || $overflowY === 'scroll')
            && $node->contentHeight > $node->h;

        if ($needsVScroll) {
            $scrollbarWidth = 15; // 标准滚动条宽�?
            $newContainerW = max(20, $containerW - $scrollbarWidth);
            if ($newContainerW < $containerW) {
                // 重新布局子节点（使用缩短后的宽度�?
                $this->autoStackChildren($node, $childOffsetY, $newContainerW);
                // 重新计算 contentHeight (包含 paddingBottom)
                $node->contentHeight = $this->calcContentHeight($node, $childOffsetY) + $paddingBottom;
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

            // CSS 2.1 §10.3.3: Auto-width = containerW - child's own padding - child's own border
            $hasExplicitWidth = array_key_exists('width', $child->style) || array_key_exists('widthPercent', $child->style);
            if ((!$hasExplicitWidth || $child->w === 0) && !self::isInlineType($child->type)) {
                $autoPadL = (int)($childStyle['paddingLeft'] ?? $childStyle['padding'] ?? 0);
                $autoPadR = (int)($childStyle['paddingRight'] ?? $childStyle['padding'] ?? 0);
                $autoBw = (int)($childStyle['borderWidth'] ?? 0);
                $childBoxSizing = $childStyle['boxSizing'] ?? 'content-box';
                if ($childBoxSizing === 'border-box') {
                    // border-box: CSS 'width' = total width = container content width
                    $autoW = max(0, (int)$containerW);
                } else {
                    // content-box: CSS 'width' = content width = container content - own padding - own border
                    $autoW = max(0, (int)$containerW - $autoPadL - $autoPadR - $autoBw * 2);
                }
                error_log('[DIAG_ASTACK] child=' . $child->type . ' containerW=' . $containerW . ' autoW=' . $autoW . ' padL=' . $autoPadL . ' padR=' . $autoPadR . ' hasExplicitW=' . ($hasExplicitWidth ? '1' : '0') . ' childWbefore=' . $child->w);
                $child->w = $autoW;
                $child->visualW = PercentResolver::resolveVisualW($childStyle, $child->w);
            }

            // CSS: inline elements with text content use text-measured width instead of container fill
            if (self::isInlineType($child->type) && $child->content !== null && is_string($child->content) && strlen($child->content) > 0) {
                $fs = (int)($child->style['fontSize']);
                $bd = ($childStyle['bold'] ?? 0) != 0;
                $measured = PercentResolver::resolveTextWidth($child->content, $fs, $bd);
                error_log('[DIAG_INLINE_ASTACK] child=' . $child->type . ' measured=' . $measured);
                if ($measured > 0) {
                    $child->w = min($measured, max(0, (int)PercentResolver::resolveMinMax($childStyle, $measured, true)));
                    $child->visualW = PercentResolver::resolveVisualW($childStyle, $child->w);
                }
            }

            $child->w = max(0, (int)PercentResolver::resolveMinMax($childStyle, $child->w, true));
            $child->visualW = PercentResolver::resolveVisualW($childStyle, $child->w);

            // ── Auto-margin centering (CSS 2.2 §10.3.3) ──
            $childML = $childStyle['marginLeftAuto'] ?? false;
            $childMR = $childStyle['marginRightAuto'] ?? false;
            if (($childML || $childMR)) {
                // Browser-equivalent: flex-grow items' auto-margin is deferred
                if (empty($node->style['_deferAutoMargin'])) {
                    // Flex/grid containers handle auto-margin internally — skip to avoid double-apply.
                    $childDisp = $childStyle['display'] ?? 'block';
                    if ($childDisp !== 'flex' && $childDisp !== 'inline-flex' && $childDisp !== 'grid') {
                        $oldX = $child->x;
                        $this->resolver->getAbsolutePositioning()->resolveMarginAuto($child, $childStyle, $containerW, 0);
                        $dx = $child->x - $oldX;
                        // _marginAutoShifted 在首次 auto-margin 中已置位，此处跳过重复位移
                        $alreadyShifted = $child->style['_marginAutoShifted'] ?? false;
                        if ($dx !== 0 && !$alreadyShifted) {
                            foreach ($child->children as $grandchild) {
                                ScrollHelper::shiftDescendantsX($grandchild, $dx);
                            }
                        }
                    }
                }
            }

            $oldY = $child->y;

            // CSS 2.2 §8.3.1: 外边距折�?
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
