<?php

namespace Px\Rendering;

use native_types;

/**
 * LayoutResolver — 运行时 CSS 布局引擎（RenderNode 版）
 *
 * 遍历 RenderNode 树，根据 style 属性计算每个节点的 x/y/w/h 位置。
 * 支持四种布局模式: block (absolute), flex, grid, scroll。
 *
 * 与旧版 VNode 版的关键区别：
 *   1. 接受 RenderNode 而非 VNode（style 已预计算，无需调用 StyleResolver）
 *   2. 实现脏标记检查：layoutDirty=false 时跳过完整布局，仅传递父坐标（含 margin）
 *   3. 集成快速滚动路径：scrollTop 变化时仅平移子节点，不改变容器本身 y
 *   4. 子节点遍历简化（RenderNode.children 始终为数组）
 */
class LayoutResolver
{
    public function __construct()
    {
    }

    /**
     * Resolve layout for the entire RenderNode tree.
     *
     * @param RenderNode $root Root RenderNode (mutated in-place)
     * @return array List of scroll containers: ['scrollContainers' => RenderNode[]]
     */
    public function resolve(RenderNode $root): array
    {
        $scrollContainers = [];
        $this->resolveNode($root, 0, 0, null, $scrollContainers);
        return ['scrollContainers' => $scrollContainers];
    }

    /**
     * Recursively resolve layout for a single node and its children.
     *
     * @param RenderNode $node Current node
     * @param int $parentX Accumulated parent X offset
     * @param int $parentY Accumulated parent Y offset
     * @param RenderNode|null $parent Parent RenderNode
     * @param array &$scrollContainers Accumulator for scroll container nodes
     */
    private function resolveNode(
        RenderNode $node,
        int $parentX,
        int $parentY,
        ?RenderNode $parent,
        array &$scrollContainers
    ): void {
        if ($node->layoutDirty) {
            // ── 脏路径：完整布局计算 ──
            // 统一入口：在 style 解析处合并 animatedStyle
            // animatedStyle 优先级高于 style，但不污染原始 style
            $style = $node->style;
            if ($node->isAnimating && !empty($node->animatedStyle)) {
                // 深度拷贝：避免修改原始 $node->style
                $effectiveStyle = [];
                foreach ($style as $k => $v) {
                    $effectiveStyle[$k] = $v;
                }
                foreach ($node->animatedStyle as $k => $v) {
                    $effectiveStyle[$k] = $v;
                }
            } else {
                $effectiveStyle = $style;
            }

            // Inherit parent's layer (CSS stacking context)
            if ($parent !== null && $parent->layer > 0) {
                $node->layer = $parent->layer;
            }

            // Apply own z-index → RenderNode layer
            $zIndex = (int)($effectiveStyle['zIndex'] ?? $effectiveStyle['zindex'] ?? 0);
            if ($zIndex > $node->layer) {
                $node->layer = $zIndex;
            }

            // Check for scroll container
            $overflowX = $effectiveStyle['overflowX'] ?? $effectiveStyle['overflow'] ?? 'visible';
            $overflowY = $effectiveStyle['overflowY'] ?? $effectiveStyle['overflow'] ?? 'visible';
            $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll');
            $hasVScroll = ($overflowY === 'auto' || $overflowY === 'scroll');

            if ($hasHScroll || $hasVScroll) {
                $node->isScrollContainer = true;
            }

            // Determine display mode
            $display = $effectiveStyle['display'] ?? 'block';
            $position = $effectiveStyle['position'] ?? 'static';

            switch ($display) {
                case 'flex':
                    $this->resolveFlexLayout($node, $parentX, $parentY, $parent, $scrollContainers, $effectiveStyle);
                    break;
                case 'grid':
                    $this->resolveGridLayout($node, $parentX, $parentY, $parent, $scrollContainers, $effectiveStyle);
                    break;
                default: // block, scroll-container, etc.
                    $this->resolveBlockLayout($node, $parentX, $parentY, $parent, $position, $scrollContainers, $effectiveStyle);
                    break;
            }

            // ── Scroll container post-processing for flex/grid display modes ──
            // (block layout handles this internally in resolveBlockLayout)
            if ($node->isScrollContainer && ($display === 'flex' || $display === 'grid')) {
                $padT = $effectiveStyle['paddingTop'] ?? $effectiveStyle['padding'] ?? 0;
                $padL = $effectiveStyle['paddingLeft'] ?? $effectiveStyle['padding'] ?? 0;
                $padR = $effectiveStyle['paddingRight'] ?? $effectiveStyle['padding'] ?? 0;
                $padB = $effectiveStyle['paddingBottom'] ?? $effectiveStyle['padding'] ?? 0;

                $childBaseY = $node->y + $padT - $node->scrollTop;

                // Calculate contentHeight: max bottom edge of all children
                $maxBottom = $childBaseY;
                foreach ($node->children as $child) {
                    $bottom = (int)($child->y + $child->h);
                    if ($bottom > $maxBottom) $maxBottom = $bottom;
                }
                $node->contentHeight = max(0, $maxBottom - $childBaseY);

                // Clamp scrollTop when content shrinks
                $maxScroll = max($node->contentHeight - $node->h, 0);
                if ($node->scrollTop > $maxScroll) {
                    $oldScrollTop = $node->scrollTop;
                    $node->scrollTop = $maxScroll;
                    $shiftDown = $oldScrollTop - $node->scrollTop;
                    if ($shiftDown > 0) {
                        foreach ($node->children as $child) {
                            $child->y += $shiftDown;
                            $this->shiftDescendantsY($child, $shiftDown);
                        }
                    }
                }

                // ContentWidth for horizontal scroll
                $overflowX = $effectiveStyle['overflowX'] ?? $effectiveStyle['overflow'] ?? 'visible';
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

                    $maxScrollX = max($node->contentWidth - $node->w, 0);
                    if ($node->scrollLeft > $maxScrollX) {
                        $oldScrollLeft = $node->scrollLeft;
                        $node->scrollLeft = $maxScrollX;
                        $shiftRight = $oldScrollLeft - $node->scrollLeft;
                        if ($shiftRight > 0) {
                            foreach ($node->children as $child) {
                                $child->x += $shiftRight;
                                $this->shiftDescendantsX($child, $shiftRight);
                            }
                        }
                    }
                }
            }

            // Track scroll containers
            if ($node->isScrollContainer) {
                $scrollContainers[] = $node;
                $node->lastScrollTop = $node->scrollTop;
            }

            $node->layoutDirty = false;
        } else {
            // ── 洁净路径：仅传递父坐标（含自身 margin） ──
            $style = $node->style;
            $marginLeft = $style['marginLeft'] ?? $style['margin'] ?? 0;
            $marginTop = $style['marginTop'] ?? $style['margin'] ?? 0;
            // 仅当节点有显式定位时才重算 x/y（否则保留 auto-stack 或快速滚动路径设定的位置）
            if (array_key_exists('left', $style)) {
                $node->x = $style['left'] + $parentX + $marginLeft;
            }
            if (array_key_exists('top', $style)) {
                $node->y = $style['top'] + $parentY + $marginTop;
            }

            // ── 快速滚动路径 ──
            // 仅滚动容器且 scrollTop 发生变化时执行
            if ($node->isScrollContainer && $node->scrollTop !== $node->lastScrollTop) {
                $deltaY = $node->lastScrollTop - $node->scrollTop;
                foreach ($node->children as $child) {
                    $this->shiftChildrenY($child, $deltaY, true);
                }
                $node->lastScrollTop = $node->scrollTop;
            }

            // ── 子节点递归处理 ──
            $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;
            $paddingTop = $style['paddingTop'] ?? $style['padding'] ?? 0;

            $childOffsetX = $node->x + $paddingLeft;
            $childOffsetY = $node->y + $paddingTop;
            if ($node->isScrollContainer) {
                $childOffsetY -= $node->scrollTop;
                $childOffsetX -= $node->scrollLeft;
            }

            foreach ($node->children as $child) {
                $this->resolveNode($child, $childOffsetX, $childOffsetY, $node, $scrollContainers);
            }
        }
    }

    /**
     * Block layout dispatcher.
     *
     * 统一尺寸解析（百分比 + min/max），然后根据 position 分发到:
     * - resolveNormalFlow（static/relative）
     * - resolveAbsolutePositioning（absolute/fixed）
     * 最后处理 scroll container post-processing + auto-width/height。
     */
    private function resolveBlockLayout(
        RenderNode $node,
        int $parentX,
        int $parentY,
        ?RenderNode $parent,
        string $position,
        array &$scrollContainers,
        array $style
    ): void {
        // Read position from style
        $left = $style['left'] ?? 0;
        $top  = $style['top'] ?? 0;
        $right = $style['right'] ?? null;
        $bottom = $style['bottom'] ?? null;

        // ── 自动注入 position:absolute（向后兼容）──
        // 检测 left/top/right/bottom 出现但无 position → 自动注入
        // 同时写入 node->style 以便子节点在定位祖先查找时能识别此节点
        if ($position === 'static') {
            $hasLTRB = array_key_exists('left', $style)
                || array_key_exists('top', $style)
                || array_key_exists('right', $style)
                || array_key_exists('bottom', $style);
            if ($hasLTRB) {
                $position = 'absolute';
                $node->style['position'] = 'absolute';
            }
        }

        // ── 百分比尺寸解析 ──
        $parentW = ($parent !== null) ? $parent->w : 0;
        $parentH = ($parent !== null) ? $parent->h : 0;
        $width  = $this->resolvePercent($style, 'width', 'widthPercent', $parentW);
        $height = $this->resolvePercent($style, 'height', 'heightPercent', $parentH);

        // flex:1 已移至 flex 布局专用路径 (Task D)

        // ── 应用 min/max 约束到尺寸（在子节点递归之前，确保 parent->w/h 立即可用）──
        $node->w = max(0, (int)$this->applyMinMax($style, $width, true));
        $node->h = max(0, (int)$this->applyMinMax($style, $height, false));

        // ── 根据 position 分发 ──
        // B.4: position:fixed v1 退化为 absolute（同分支）
        $isAbsolute = ($position === 'absolute' || $position === 'fixed');

        if ($isAbsolute) {
            $this->resolveAbsolutePositioning($node, $parent, $style, $left, $top, $right, $bottom, $width, $height, $scrollContainers);
        } else {
            // static / relative
            $this->resolveNormalFlow($node, $parentX, $parentY, $parent, $position, $style, $left, $top, $scrollContainers);
        }

        // ── Scroll container post-processing ──
        if ($node->isScrollContainer) {
            $paddingTop = $style['paddingTop'] ?? $style['padding'] ?? 0;
            $paddingRight = $style['paddingRight'] ?? $style['padding'] ?? 0;
            $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;
            $childOffsetY = $node->y + $paddingTop - $node->scrollTop;
            $this->finalizeScrollContainer($node, $style, $childOffsetY, $paddingLeft, $paddingRight, $scrollContainers);
        }

        // ── Auto-width/height for block containers (CSS content-based sizing) ──
        $hasExplicitWidth = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);
        $hasExplicitHeight = array_key_exists('height', $style) || array_key_exists('heightPercent', $style);
        $display = $style['display'] ?? 'block';

        if (!$hasExplicitWidth && $display === 'block') {
            $maxRight = 0;
            foreach ($node->children as $child) {
                $childRight = (int)($child->x + $child->w);
                if ($childRight > $maxRight) $maxRight = $childRight;
            }
            $computedW = max(0, $maxRight - $node->x);
            if ($computedW > $node->w) {
                $node->w = max(0, (int)$this->applyMinMax($style, $computedW, true));
                $padL = $style['paddingLeft'] ?? $style['padding'] ?? 0;
                $padR = $style['paddingRight'] ?? $style['padding'] ?? 0;
                $contentW = $node->w - $padL - $padR;
                if ($contentW > 0) {
                    foreach ($node->children as $child) {
                        $cs = $child->style;
                        if (!array_key_exists('width', $cs)) {
                            $child->w = max(0, $contentW);
                            $child->w = max(0, (int)$this->applyMinMax($cs, $child->w, true));
                        }
                    }
                }
            }
        }

        if (!$hasExplicitHeight && $display === 'block') {
            $maxBottom = 0;
            foreach ($node->children as $child) {
                $childBottom = (int)($child->y + $child->h);
                if ($childBottom > $maxBottom) $maxBottom = $childBottom;
            }
            $computedH = max(0, $maxBottom - $node->y);
            if ($computedH > $node->h) {
                $node->h = max(0, (int)$this->applyMinMax($style, $computedH, false));
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
        RenderNode $node,
        int $parentX,
        int $parentY,
        ?RenderNode $parent,
        string $position,
        array $style,
        int $left,
        int $top,
        array &$scrollContainers
    ): void {
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
        $isScroll = $node->isScrollContainer;
        $childOffsetX = $node->x + $paddingLeft;
        $childOffsetY = $node->y + $paddingTop;
        if ($isScroll) {
            $childOffsetX -= $node->scrollLeft;
            $childOffsetY -= $node->scrollTop;
        }
        foreach ($node->children as $child) {
            $this->resolveNode($child, $childOffsetX, $childOffsetY, $node, $scrollContainers);
        }
    }

    /**
     * Absolute/fixed positioning.
     *
     * 使用 positioningAncestor（position != static 的最近祖先）作为参考系。
     * left/top 相对定位祖先的 padding box 偏移。
     * right/bottom 替代（当 left/top 未设时）。
     * position:fixed v1 退化为 absolute（TODO v2: viewport 参考系）。
     */
    private function resolveAbsolutePositioning(
        RenderNode $node,
        ?RenderNode $parent,
        array $style,
        int $left,
        int $top,
        ?int $right,
        ?int $bottom,
        int $width,
        int $height,
        array &$scrollContainers
    ): void {
        // 查找并缓存定位祖先
        $this->resolvePositioningAncestor($node);
        $ancestor = $node->positioningAncestor;

        // 参考系：定位祖先的 padding box，退化时用 (0,0)
        $ancestorX = ($ancestor !== null) ? $ancestor->x : 0;
        $ancestorY = ($ancestor !== null) ? $ancestor->y : 0;
        $ancestorW = ($ancestor !== null) ? $ancestor->w : 0;
        $ancestorH = ($ancestor !== null) ? $ancestor->h : 0;

        $marginLeft = $style['marginLeft'] ?? $style['margin'] ?? 0;
        $marginTop = $style['marginTop'] ?? $style['margin'] ?? 0;
        $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;
        $paddingRight = $style['paddingRight'] ?? $style['padding'] ?? 0;
        $paddingTop = $style['paddingTop'] ?? $style['padding'] ?? 0;

        // left/top 相对定位祖先偏移
        $node->x = $ancestorX + $left + $marginLeft;
        $node->y = $ancestorY + $top + $marginTop;

        // right/bottom 替代（当 width/height 已设时用尺寸推算，否则仅锚定边缘）
        if ($right !== null && $ancestor !== null) {
            if ($width > 0) {
                $node->x = $ancestorX + $ancestorW - $width - $right;
            } else {
                // No explicit width — anchor from right edge
                $node->x = $ancestorX + $ancestorW - $right;
            }
        }
        if ($bottom !== null && $ancestor !== null) {
            if ($height > 0) {
                $node->y = $ancestorY + $ancestorH - $height - $bottom;
            } else {
                // No explicit height — anchor from bottom edge
                $node->y = $ancestorY + $ancestorH - $bottom;
            }
        }

        // ── margin:auto 水平居中（相对定位祖先） ──
        $parentContentW = ($ancestor !== null) ? max(0, $ancestorW - $paddingLeft - $paddingRight) : 0;
        $this->resolveMarginAuto($node, $style, $parentContentW);

        // Apply translate from animatedStyle
        $translateX = $style['translateX'] ?? 0;
        $translateY = $style['translateY'] ?? 0;
        $node->x += $translateX;
        $node->y += $translateY;

        // Resolve children recursively
        $isScroll = $node->isScrollContainer;
        $childOffsetX = $node->x + $paddingLeft;
        $childOffsetY = $node->y + $paddingTop;
        if ($isScroll) {
            $childOffsetX -= $node->scrollLeft;
            $childOffsetY -= $node->scrollTop;
        }
        foreach ($node->children as $child) {
            $this->resolveNode($child, $childOffsetX, $childOffsetY, $node, $scrollContainers);
        }
    }

    /**
     * Flex layout: compute child positions using flex algorithm.
     */
    private function resolveFlexLayout(
        RenderNode $node,
        int $parentX,
        int $parentY,
        ?RenderNode $parent,
        array &$scrollContainers,
        array $style
    ): void {
        // Container position
        $left   = $style['left'] ?? 0;
        $top    = $style['top'] ?? 0;
        $width  = $style['width'] ?? 0;
        $height = $style['height'] ?? 0;

        $node->x = $left + $parentX;
        $node->y = $top + $parentY;

        // Apply translate from animatedStyle
        $translateX = $style['translateX'] ?? 0;
        $translateY = $style['translateY'] ?? 0;
        $node->x += $translateX;
        $node->y += $translateY;

        // ── 百分比尺寸解析 ──
        $parentW = ($parent !== null) ? $parent->w : 0;
        $parentH = ($parent !== null) ? $parent->h : 0;
        $width  = $this->resolvePercent($style, 'width', 'widthPercent', $parentW);
        $height = $this->resolvePercent($style, 'height', 'heightPercent', $parentH);

        $node->w = max(0, (int)$width);
        $node->h = max(0, (int)$height);

        // ── 方向检测（在父级填充前，以区分主/交叉轴）──
        $direction = $style['flexDirection'] ?? 'row';
        $isRow = ($direction === 'row' || $direction === 'row-reverse');

        // Cross-axis fill — account for parent padding
        $parentPadL = ($parent !== null) ? ($parent->style['paddingLeft'] ?? $parent->style['padding'] ?? 0) : 0;
        $parentPadR = ($parent !== null) ? ($parent->style['paddingRight'] ?? $parent->style['padding'] ?? 0) : 0;
        $parentPadT = ($parent !== null) ? ($parent->style['paddingTop'] ?? $parent->style['padding'] ?? 0) : 0;
        $parentPadB = ($parent !== null) ? ($parent->style['paddingBottom'] ?? $parent->style['padding'] ?? 0) : 0;

        // Only fill cross axis dimension from parent (not main axis)
        // Flex column: cross axis = width, fill it
        // Flex row: cross axis = height, fill it
        if ($isRow) {
            // Row: cross axis = height — subtract parent vertical padding
            if ($height === 0 && $parent !== null && $parent->h > 0) {
                $availH = $parent->h - $parentPadT - $parentPadB;
                $height = max(0, $availH - $top);
                $node->h = max(0, (int)$height);
            }
        } else {
            // Column: cross axis = width — subtract parent horizontal padding
            if ($width === 0 && $parent !== null && $parent->w > 0) {
                $availW = $parent->w - $parentPadL - $parentPadR;
                $width = max(0, $availW - $left);
                $node->w = max(0, (int)$width);
            }
        }

        // Handle flex:1 / flex:2 etc. → initial hint for flex-grow distribution
        $flex = $style['flex'] ?? '';
        if ($flex !== '' && $parent !== null) {
            if ($isRow && $width === 0 && $parent->w > 0) {
                $availW = $parent->w - $parentPadL - $parentPadR;
                $node->w = max(0, (int)($availW - $left));
            }
            if (!$isRow && $height === 0 && $parent->h > 0) {
                $availH = $parent->h - $parentPadT - $parentPadB;
                $node->h = max(0, (int)($availH - $top));
            }
        }

        $gap       = $style['gap'] ?? 0;
        $justify   = $style['justifyContent'] ?? 'flex-start';
        $align     = $style['alignItems'] ?? 'stretch';
        $wrap      = $style['flexWrap'] ?? 'nowrap';

        $reversed = ($direction === 'row-reverse' || $direction === 'column-reverse');

        // ── Padding ──
        $paddingTop    = $style['paddingTop'] ?? $style['padding'] ?? 0;
        $paddingRight  = $style['paddingRight'] ?? $style['padding'] ?? 0;
        $paddingBottom = $style['paddingBottom'] ?? $style['padding'] ?? 0;
        $paddingLeft   = $style['paddingLeft'] ?? $style['padding'] ?? 0;

        $containerMain = max(0, $isRow ? ($width - $paddingLeft - $paddingRight) : ($height - $paddingTop - $paddingBottom));
        $containerCross = max(0, $isRow ? ($height - $paddingTop - $paddingBottom) : ($width - $paddingLeft - $paddingRight));

        // ── Step 1: Collect children and resolve ──
        // Apply scroll offset to child parent coordinates for scroll containers
        $scrollOffsetX = 0;
        $scrollOffsetY = 0;
        if ($node->isScrollContainer) {
            $scrollOffsetX = $node->scrollLeft;
            $scrollOffsetY = $node->scrollTop;
        }

        $children = [];
        foreach ($node->children as $child) {
            $childPosition = $child->style['position'] ?? 'static';
            $this->resolveNode($child, $node->x + $paddingLeft - $scrollOffsetX, $node->y + $paddingTop - $scrollOffsetY, $node, $scrollContainers);
            // position:absolute children are removed from flex flow but positioned relative to container
            if ($childPosition !== 'absolute') {
                $children[] = $child;
            }
        }

        if (count($children) === 0) {
            // Still need to finalize scroll container contentHeight if applicable
            if ($node->isScrollContainer) {
                $node->contentHeight = 0;
            }
            return;
        }

        // ── Step 2: Order sort (AOT 兼容的冒泡排序，稳定排序) ──
        $n = count($children);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n - $i - 1; $j++) {
                $orderA = (int)($children[$j]->style['order'] ?? 0);
                $orderB = (int)($children[$j + 1]->style['order'] ?? 0);
                if ($orderA > $orderB) {
                    $tmp = $children[$j];
                    $children[$j] = $children[$j + 1];
                    $children[$j + 1] = $tmp;
                }
            }
        }

        // ── Step 3: 收集 flex item 元数据 (grow/shrink/basis) ──
        $flexItemData = [];
        foreach ($children as $ch) {
            $data = ['grow' => 0.0, 'shrink' => 1.0, 'basis' => -1, 'isFlexGrow' => false];
            $flexRaw = $ch->style['flex'] ?? '';
            if ($flexRaw !== '') {
                $fv = CssMappings::parseFlexValue($flexRaw);
                $data['grow'] = $fv['grow'];
                $data['shrink'] = $fv['shrink'];
                $data['basis'] = $fv['basis'];
            } else {
                $data['grow'] = (float)($ch->style['flexGrow'] ?? 0);
                $data['shrink'] = (float)($ch->style['flexShrink'] ?? 1);
            }
            if ($data['grow'] > 0) {
                $data['isFlexGrow'] = true;
            }
            $flexItemData[] = $data;
        }

        // ── Step 3.5: Flex-wrap 按行分割 ──
        $isWrapping = ($wrap === 'wrap');
        $lines = [$children];
        if ($isWrapping) {
            $lines = [];
            $currentLine = [];
            $currentLineMain = 0;
            foreach ($children as $idx => $ch) {
                $chMain = $isRow ? $ch->w : $ch->h;
                // Include margins in size calculation
                $cs = $ch->style;
                $mL = $cs['marginLeft'] ?? $cs['margin'] ?? 0;
                $mR = $cs['marginRight'] ?? $cs['margin'] ?? 0;
                $mT = $cs['marginTop'] ?? $cs['margin'] ?? 0;
                $mB = $cs['marginBottom'] ?? $cs['margin'] ?? 0;
                $chSizeWithMargin = $chMain + ($isRow ? $mL + $mR : $mT + $mB);

                // If item alone exceeds container, it goes on its own line
                $needsNewLine = !empty($currentLine) && ($currentLineMain + $chSizeWithMargin + $gap > $containerMain);
                if ($needsNewLine) {
                    $lines[] = $currentLine;
                    $currentLine = [];
                    $currentLineMain = 0;
                }
                $currentLine[] = $ch;
                $currentLineMain += $chSizeWithMargin + (count($currentLine) > 1 ? $gap : 0);
            }
            if (!empty($currentLine)) {
                $lines[] = $currentLine;
            }
        }

        // ── Per-line flex layout ──
        $accumulatedCrossOffset = 0;
        foreach ($lines as $lineChildren) {
            $lineContainerMain = $containerMain;
            $lineCount = count($lineChildren);
            if ($lineCount === 0) continue;

            // Build per-line flexItemData
            $lineFlexData = [];
            $lineHasFlexGrow = false;
            foreach ($lineChildren as $ch) {
                foreach ($flexItemData as $origData) {
                    // Match by tracking index offset — simpler: rebuild
                }
            }
            // Rebuild flex data for this line
            $lineFlexData = [];
            $lineHasFlexGrow = false;
            foreach ($lineChildren as $ch) {
                $data = ['grow' => 0.0, 'shrink' => 1.0, 'basis' => -1, 'isFlexGrow' => false];
                $flexRaw = $ch->style['flex'] ?? '';
                if ($flexRaw !== '') {
                    $fv = CssMappings::parseFlexValue($flexRaw);
                    $data['grow'] = $fv['grow'];
                    $data['shrink'] = $fv['shrink'];
                    $data['basis'] = $fv['basis'];
                } else {
                    $data['grow'] = (float)($ch->style['flexGrow'] ?? 0);
                    $data['shrink'] = (float)($ch->style['flexShrink'] ?? 1);
                }
                if ($data['grow'] > 0) {
                    $data['isFlexGrow'] = true;
                    $lineHasFlexGrow = true;
                }
                $lineFlexData[] = $data;
            }

            // ── Step 4: Apply flex-basis ──
            $this->applyFlexBasis($lineChildren, $lineFlexData, $isRow);

            // ── Step 5: Flex-grow ──
            if ($lineHasFlexGrow) {
                $fixedTotalMain = 0;
                foreach ($lineChildren as $idx => $ch) {
                    $data = $lineFlexData[$idx];
                    $cs = $ch->style;
                    $mL = $cs['marginLeft'] ?? $cs['margin'] ?? 0;
                    $mR = $cs['marginRight'] ?? $cs['margin'] ?? 0;
                    $mT = $cs['marginTop'] ?? $cs['margin'] ?? 0;
                    $mB = $cs['marginBottom'] ?? $cs['margin'] ?? 0;
                    if ($data['isFlexGrow']) {
                        $fixedTotalMain += $isRow ? $mL + $mR : $mT + $mB;
                    } else {
                        $sz = $isRow ? $ch->w : $ch->h;
                        $fixedTotalMain += $sz + ($isRow ? $mL + $mR : $mT + $mB);
                    }
                }
                $gapTotal = $gap * ($lineCount - 1);
                $remainingSpace = max($lineContainerMain - $fixedTotalMain - $gapTotal, 0);
                $totalFlexGrow = 0;
                foreach ($lineFlexData as $entry) {
                    $totalFlexGrow += $entry['grow'];
                }
                $totalFlexGrow = (int)max($totalFlexGrow, 1);
                foreach ($lineChildren as $idx => $ch) {
                    $data = $lineFlexData[$idx];
                    if ($data['isFlexGrow']) {
                        $allocated = (int)(($data['grow'] / $totalFlexGrow) * $remainingSpace);
                        if ($isRow) {
                            $ch->w = max(0, $allocated);
                        } else {
                            $ch->h = max(0, $allocated);
                        }
                    }
                }
            }

            // ── Step 6: Calculate line totalMain ──
            $lineTotalMain = 0;
            $lineMaxCross = 0;
            foreach ($lineChildren as $ch) {
                $cs = $ch->style;
                $mL = $cs['marginLeft'] ?? $cs['margin'] ?? 0;
                $mR = $cs['marginRight'] ?? $cs['margin'] ?? 0;
                $mT = $cs['marginTop'] ?? $cs['margin'] ?? 0;
                $mB = $cs['marginBottom'] ?? $cs['margin'] ?? 0;
                if ($isRow) {
                    $lineTotalMain += $ch->w + $mL + $mR;
                    $lineMaxCross = (int)max($lineMaxCross, $ch->h);
                } else {
                    $lineTotalMain += $ch->h + $mT + $mB;
                    $lineMaxCross = (int)max($lineMaxCross, $ch->w);
                }
            }
            $lineTotalMain += $gap * ($lineCount - 1);

            // ── Step 7: Flex-shrink ──
            if ($lineTotalMain > $lineContainerMain) {
                $overflow = $lineTotalMain - $lineContainerMain;
                $totalShrinkWeight = 0;
                foreach ($lineChildren as $idx => $ch) {
                    $data = $lineFlexData[$idx];
                    if ($data['shrink'] > 0) {
                        $mainSize = $isRow ? $ch->w : $ch->h;
                        $totalShrinkWeight += $mainSize * $data['shrink'];
                    }
                }
                if ($totalShrinkWeight > 0) {
                    foreach ($lineChildren as $idx => $ch) {
                        $data = $lineFlexData[$idx];
                        if ($data['shrink'] > 0) {
                            $mainSize = $isRow ? $ch->w : $ch->h;
                            $reduction = (int)($overflow * ($mainSize * $data['shrink']) / $totalShrinkWeight);
                            $newSize = max(0, $mainSize - $reduction);
                            $minVal = $isRow ? (int)($ch->style['minWidth'] ?? 0) : (int)($ch->style['minHeight'] ?? 0);
                            if ($minVal > 0 && $newSize < $minVal) {
                                $newSize = $minVal;
                            }
                            if ($isRow) {
                                $ch->w = $newSize;
                            } else {
                                $ch->h = $newSize;
                            }
                        }
                    }
                }
            }

            // ── Step 8: Min/max constraints ──
            foreach ($lineChildren as $ch) {
                $ch->w = max(0, (int)$this->applyMinMax($ch->style, $ch->w, true));
                $ch->h = max(0, (int)$this->applyMinMax($ch->style, $ch->h, false));
            }

            // ── Step 9: Recalculate totalMain after shrink ──
            $lineTotalMain = 0;
            $lineMaxCross = 0;
            foreach ($lineChildren as $ch) {
                $cs = $ch->style;
                $mL = $cs['marginLeft'] ?? $cs['margin'] ?? 0;
                $mR = $cs['marginRight'] ?? $cs['margin'] ?? 0;
                $mT = $cs['marginTop'] ?? $cs['margin'] ?? 0;
                $mB = $cs['marginBottom'] ?? $cs['margin'] ?? 0;
                if ($isRow) {
                    $lineTotalMain += $ch->w + $mL + $mR;
                    $lineMaxCross = (int)max($lineMaxCross, $ch->h);
                } else {
                    $lineTotalMain += $ch->h + $mT + $mB;
                    $lineMaxCross = (int)max($lineMaxCross, $ch->w);
                }
            }
            $lineTotalMain += $gap * ($lineCount - 1);

            // ── Step 10: Justify-content for this line ──
            $mainStart = match ($justify) {
                'center'        => ($lineContainerMain - $lineTotalMain) / 2,
                'flex-end'      => $lineContainerMain - $lineTotalMain,
                'space-between' => 0,
                'space-around'  => 0,
                'space-evenly'  => 0,
                default         => 0,
            };
            $spaceBetween = 0;
            if ($justify === 'space-between' && $lineCount > 1) {
                $spaceBetween = ($lineContainerMain - $lineTotalMain) / ($lineCount - 1);
            } elseif ($justify === 'space-around' && $lineCount > 0) {
                $spaceBetween = ($lineContainerMain - $lineTotalMain) / $lineCount;
                $mainStart = $spaceBetween / 2;
            } elseif ($justify === 'space-evenly' && $lineCount > 0) {
                $spaceBetween = ($lineContainerMain - $lineTotalMain) / ($lineCount + 1);
                $mainStart = $spaceBetween;
            }

            // ── Step 11: Position children in this line ──
            $currentMain = $mainStart;
            $indices = range(0, $lineCount - 1);
            if ($reversed) {
                $indices = array_reverse($indices);
            }

            // This line's cross-axis position
            $lineCrossBase = $accumulatedCrossOffset;

            foreach ($indices as $idx) {
                $i = (int)$idx;
                $ch = $lineChildren[$i];
                $childStyle = $ch->style;
                $childMarginLeft = $childStyle['marginLeft'] ?? $childStyle['margin'] ?? 0;
                $childMarginRight = $childStyle['marginRight'] ?? $childStyle['margin'] ?? 0;
                $childMarginTop = $childStyle['marginTop'] ?? $childStyle['margin'] ?? 0;
                $childMarginBottom = $childStyle['marginBottom'] ?? $childStyle['margin'] ?? 0;

                // Main axis position
                $oldX = $ch->x;
                $oldY = $ch->y;
                if ($isRow) {
                    $ch->x = $node->x + $paddingLeft + (int)$currentMain + $childMarginLeft;
                } else {
                    $ch->y = $node->y + $paddingTop + (int)$currentMain + $childMarginTop;
                }

                // Cross axis alignment (use line cross offset instead of full containerCross)
                $effectiveAlign = $childStyle['alignSelf'] ?? 'auto';
                if ($effectiveAlign === 'auto') {
                    $effectiveAlign = $align;
                }

                if ($isWrapping) {
                    // In wrapping mode, cross axis is per-line
                    if ($effectiveAlign === 'stretch') {
                        if ($isRow) {
                            if ($ch->h === 0) $ch->h = max(0, $lineMaxCross);
                            $ch->y = $node->y + $paddingTop + $lineCrossBase + $childMarginTop;
                        } else {
                            if ($ch->w === 0) $ch->w = max(0, $lineMaxCross);
                            $ch->x = $node->x + $paddingLeft + $lineCrossBase + $childMarginLeft;
                        }
                    } else {
                        $crossSize = $isRow ? $ch->h : $ch->w;
                        $crossOffset = match ($effectiveAlign) {
                            'center' => (int)(($lineMaxCross - $crossSize) / 2),
                            'flex-end' => $lineMaxCross - $crossSize,
                            default => 0,
                        };
                        if ($isRow) {
                            $ch->y = $node->y + $paddingTop + $lineCrossBase + $crossOffset + $childMarginTop;
                        } else {
                            $ch->x = $node->x + $paddingLeft + $lineCrossBase + $crossOffset + $childMarginLeft;
                        }
                    }
                } else {
                    // Non-wrapping: original behavior with full containerCross
                    if ($effectiveAlign === 'stretch') {
                        if ($isRow) {
                            if ($ch->h === 0) $ch->h = max(0, (int)$containerCross);
                        } else {
                            if ($ch->w === 0) $ch->w = max(0, (int)$containerCross);
                        }
                    }
                    $crossSize = $isRow ? $ch->h : $ch->w;
                    $crossOffset = match ($effectiveAlign) {
                        'center' => (int)(($containerCross - $crossSize) / 2),
                        'flex-end' => $containerCross - $crossSize,
                        'stretch' => 0,
                        default => 0,
                    };
                    if ($isRow) {
                        $ch->y = $node->y + $paddingTop + $crossOffset;
                    } else {
                        $ch->x = $node->x + $paddingLeft + $crossOffset;
                    }
                }

                // Cross axis margin
                if ($isRow) {
                    $ch->y += $childMarginTop;
                    if ($effectiveAlign === 'stretch' && $ch->h === 0 && !$isWrapping) {
                        $stretchedH = max(0, (int)($containerCross - $childMarginTop - $childMarginBottom));
                        if ($stretchedH > 0) $ch->h = $stretchedH;
                    }
                } else {
                    $ch->x += $childMarginLeft;
                    if ($effectiveAlign === 'stretch' && $ch->w === 0 && !$isWrapping) {
                        $stretchedW = max(0, (int)($containerCross - $childMarginLeft - $childMarginRight));
                        if ($stretchedW > 0) $ch->w = $stretchedW;
                    }
                }

                // Shift descendants if position changed
                $dx = $ch->x - $oldX;
                $dy = $ch->y - $oldY;
                if ($dy !== 0) {
                    foreach ($ch->children as $grandchild) {
                        $this->shiftDescendantsY($grandchild, $dy);
                    }
                }
                if ($dx !== 0) {
                    foreach ($ch->children as $grandchild) {
                        $this->shiftDescendantsX($grandchild, $dx);
                    }
                }

                // Advance main position
                $chMainSize = $isRow ? $ch->w : $ch->h;
                $currentMain += $chMainSize + $gap + $spaceBetween;
                if ($isRow) {
                    $currentMain += $childMarginLeft + $childMarginRight;
                } else {
                    $currentMain += $childMarginTop + $childMarginBottom;
                }
            }

            // ── Two-pass: re-resolve internal children of flex-grow items ──
            // After flex-grow (Step 5), flex items' main-axis size may have changed.
            // Their internal children were laid out in Step 1 using preliminary sizes.
            // This re-resolves grandchildren with the flex-grow item's final size.
            foreach ($lineChildren as $idxTp => $chTp) {
                $dataTp = $lineFlexData[$idxTp];
                if ($dataTp['isFlexGrow'] && count($chTp->children) > 0) {
                    $chPadLtp = $chTp->style['paddingLeft'] ?? $chTp->style['padding'] ?? 0;
                    $chPadTtp = $chTp->style['paddingTop'] ?? $chTp->style['padding'] ?? 0;
                    $gcOffsetX = $chTp->x + $chPadLtp;
                    $gcOffsetY = $chTp->y + $chPadTtp;
                    // Account for scroll offset if the flex item is a scroll container
                    if ($chTp->isScrollContainer) {
                        $gcOffsetX -= $chTp->scrollLeft;
                        $gcOffsetY -= $chTp->scrollTop;
                    }
                    foreach ($chTp->children as $grandchild) {
                        $grandchild->layoutDirty = true;
                        $this->resolveNode($grandchild, $gcOffsetX, $gcOffsetY, $chTp, $scrollContainers);
                    }
                }
            }

            // Advance cross axis offset for next wrapping line
            $accumulatedCrossOffset += $lineMaxCross + $gap;
        }
    }

    /**
     * Apply flex-basis to children in a flex line.
     */
    private function applyFlexBasis(array $children, array $flexItemData, bool $isRow): void
    {
        foreach ($children as $idx => $ch) {
            $data = $flexItemData[$idx];
            $basis = $data['basis'];
            if ($basis >= 0) {
                if ($basis > 0) {
                    if ($isRow) {
                        $ch->w = max(0, $basis);
                    } else {
                        $ch->h = max(0, $basis);
                    }
                }
            } else {
                $flexBasis = $ch->style['flexBasis'] ?? 'auto';
                if ($flexBasis !== 'auto') {
                    $basisVal = (int)$flexBasis;
                    if ($basisVal > 0) {
                        if ($isRow) {
                            $ch->w = max(0, $basisVal);
                        } else {
                            $ch->h = max(0, $basisVal);
                        }
                    }
                }
            }
        }
    }

    /**
     * Grid layout: position children in a CSS grid.
     */
    private function resolveGridLayout(
        RenderNode $node,
        int $parentX,
        int $parentY,
        ?RenderNode $parent,
        array &$scrollContainers,
        array $style
    ): void {
        $left   = $style['left'] ?? 0;
        $top    = $style['top'] ?? 0;
        $width  = $style['width'] ?? 0;
        $height = $style['height'] ?? 0;

        // ── 百分比尺寸解析 ──
        $parentW = ($parent !== null) ? $parent->w : 0;
        $parentH = ($parent !== null) ? $parent->h : 0;
        $width  = $this->resolvePercent($style, 'width', 'widthPercent', $parentW);
        $height = $this->resolvePercent($style, 'height', 'heightPercent', $parentH);

        $node->x = $left + $parentX;
        $node->y = $top + $parentY;

        // Apply translate from animatedStyle
        $translateX = $style['translateX'] ?? 0;
        $translateY = $style['translateY'] ?? 0;
        $node->x += $translateX;
        $node->y += $translateY;

        // ── 应用 min/max 约束到容器 ──
        $node->w = max(0, (int)$this->applyMinMax($style, $width, true));
        $node->h = max(0, (int)$this->applyMinMax($style, $height, false));

        // Parse grid template
        $gridCols = $style['gridTemplateColumns'] ?? '';
        $gridRows = $style['gridTemplateRows'] ?? '';

        $colSpec = CssMappings::parseGridTemplateValue($gridCols);
        $rowSpec = CssMappings::parseGridTemplateValue($gridRows);

        $cols = $colSpec['count'] ?? 4;
        $cellW = $colSpec['size'] ?? 80;

        // Gap values (must be defined before 1fr calculation)
        $colGap = $style['gridColumnGap'] ?? $style['gap'] ?? 0;
        $rowGap = $style['gridRowGap'] ?? $style['gap'] ?? 0;

        // 1fr 支持：根据容器宽度按比例分配
        if (($colSpec['unit'] ?? '') === 'fr' && $node->w > 0) {
            $totalGaps = $colGap * ($cols - 1);
            $cellW = max(0, (int)(($node->w - $totalGaps) / $cols));
        }
        $rows = $rowSpec['count'] ?? 5;
        $cellH = $rowSpec['size'] ?? 60;
        // 1fr 支持（行高）
        if (($rowSpec['unit'] ?? '') === 'fr' && $node->h > 0) {
            $totalGaps = $rowGap * ($rows - 1);
            $cellH = max(0, (int)(($node->h - $totalGaps) / $rows));
        }

        // Collect children and resolve their styles
        $children = [];
        foreach ($node->children as $child) {
            $this->resolveNode($child, $node->x, $node->y, $node, $scrollContainers);
            $children[] = $child;
        }

        // Position children in grid
        $col = 0;
        $row = 0;
        $cellPaddingCol = $colGap;
        $cellPaddingRow = $rowGap;
        foreach ($children as $ch) {
            // Use explicit grid-column/grid-row from style (CSS 1-based)
            $childStyle = $ch->style;
            $explicitCol = $childStyle['gridColumn'] ?? null;
            $explicitRow = $childStyle['gridRow'] ?? null;

            if ($explicitCol !== null && $explicitCol !== '') {
                $col = (int)$explicitCol - 1;
            }
            if ($explicitRow !== null && $explicitRow !== '') {
                $row = (int)$explicitRow - 1;
            }

            // 基础单元格位置
            $cellX = $node->x + $col * $cellW + $colGap;
            $cellY = $node->y + $row * $cellH + $rowGap;
            $cellWFinal = max(0, (int)($cellW - $colGap * 2));
            $cellHFinal = max(0, (int)($cellH - $rowGap * 2));

            // ── align-self: 垂直方向对齐 ──
            $alignSelf = $childStyle['alignSelf'] ?? 'auto';
            if ($alignSelf === 'auto') {
                $alignSelf = 'stretch';
            }

            switch ($alignSelf) {
                case 'center':
                    $ch->y = $cellY + (int)(($cellHFinal - $ch->h) / 2);
                    break;
                case 'end':
                case 'flex-end':
                    $ch->y = $cellY + $cellHFinal - $ch->h;
                    break;
                case 'start':
                case 'flex-start':
                    $ch->y = $cellY;
                    break;
                default: // stretch
                    $ch->y = $cellY;
                    $ch->h = $cellHFinal;
                    break;
            }

            // ── justify-self: 水平方向对齐 ──
            $justifySelf = $childStyle['justifySelf'] ?? 'auto';
            if ($justifySelf === 'auto') {
                $justifySelf = 'stretch';
            }

            switch ($justifySelf) {
                case 'center':
                    $ch->x = $cellX + (int)(($cellWFinal - $ch->w) / 2);
                    break;
                case 'end':
                case 'flex-end':
                    $ch->x = $cellX + $cellWFinal - $ch->w;
                    break;
                case 'start':
                case 'flex-start':
                    $ch->x = $cellX;
                    break;
                default: // stretch
                    $ch->x = $cellX;
                    $ch->w = $cellWFinal;
                    break;
            }

            // ── 对每个 grid item 应用 min/max 约束 ──
            $ch->w = max(0, (int)$this->applyMinMax($ch->style, $ch->w, true));
            $ch->h = max(0, (int)$this->applyMinMax($ch->style, $ch->h, false));

            $col++;
            if ($col >= $cols) {
                $col = 0;
                $row++;
            }
        }
    }

    // ── CSS min/max 约束辅助方法 ──

    /**
     * 应用 CSS min-width/max-width 或 min-height/max-height 约束。
     * CSS 规范: 如果 min > max，则 max 被忽略。
     */
    private function applyMinMax(array $style, int $size, bool $isWidth): int
    {
        $min = $isWidth ? (int)($style['minWidth'] ?? 0) : (int)($style['minHeight'] ?? 0);
        $max = $isWidth ? (int)($style['maxWidth'] ?? 0) : (int)($style['maxHeight'] ?? 0);

        // CSS 规范: 如果 min > max，max 被忽略
        if ($min > 0 && $max > 0 && $min > $max) {
            $max = 0;
        }

        if ($min > 0 && $size < $min) {
            $size = (int)$min;
        }
        if ($max > 0 && $size > $max) {
            $size = (int)$max;
        }
        return max(0, $size);
    }

    /**
     * 解析百分比尺寸值。
     * 如果 percentKey 存在（如 'widthPercent'），从 parentSize 计算实际像素值。
     * 否则回退到 pixel key（如 'width'）。
     */
    private function resolvePercent(array $style, string $key, string $percentKey, int $parentSize): int
    {
        $pct = $style[$percentKey] ?? null;
        if ($pct !== null && $parentSize > 0) {
            return (int)($parentSize * $pct / 100.0);
        }
        return $style[$key] ?? 0;
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
     * 解析 margin:auto 水平居中。
     * CSS 规范: margin-left:auto + margin-right:auto 在固定宽度子节点上水平居中。
     * 算法: 剩余空间 = (父 content width - 子 width) / 2，各分一半。
     * 垂直方向 v1 不实现。
     * AOT 兼容: 纯算术操作，符合 native_types。
     */
    private function resolveMarginAuto(RenderNode $node, array $style, int $parentContentW): void
    {
        $marginLeft = $style['marginLeft'] ?? null;
        $marginRight = $style['marginRight'] ?? null;
        $margin = $style['margin'] ?? null;

        $isMarginLeftAuto = ($marginLeft === 'auto');
        $isMarginRightAuto = ($marginRight === 'auto');
        // Handle margin:auto shorthand
        if ($margin === 'auto') {
            $isMarginLeftAuto = true;
            $isMarginRightAuto = true;
        }

        if ($isMarginLeftAuto && $isMarginRightAuto && $node->w > 0 && $parentContentW > $node->w) {
            $remaining = $parentContentW - $node->w;
            $half = (int)($remaining / 2);
            $node->x += $half;
        }
    }

    // ── 递归平移方法（用于 auto-stack / clamp） ──

    /**
     * Recursively shift Y coordinate of a node and all its descendants.
     */
    private function shiftDescendantsY(RenderNode $node, int $dy): void
    {
        $node->y += $dy;
        foreach ($node->children as $child) {
            $child->y += $dy;
            $this->shiftDescendantsY($child, $dy);
        }
    }

    /**
     * Recursively shift X coordinate of a node and all its descendants.
     */
    private function shiftDescendantsX(RenderNode $node, int $dx): void
    {
        $node->x += $dx;
        foreach ($node->children as $child) {
            $child->x += $dx;
            $this->shiftDescendantsX($child, $dx);
        }
    }

    // ── 快速滚动路径平移方法 ──

    /**
     * Recursively shift Y coordinate of a node and its descendants.
     * Used by the fast scroll path.
     *
     * @param bool $skipAbsolute If true, skip children with position:absolute
     */
    private function shiftChildrenY(RenderNode $node, int $deltaY, bool $skipAbsolute = false): void
    {
        $node->y += $deltaY;
        foreach ($node->children as $child) {
            if ($skipAbsolute && ($child->style['position'] ?? '') === 'absolute') {
                continue;
            }
            $this->shiftChildrenY($child, $deltaY, $skipAbsolute);
        }
    }

    /**
     * Scroll container post-processing: auto-stack + contentHeight + scroll clamps.
     *
     * Extracted from resolveBlockLayout to keep method focused.
     * Preserves all original scroll container behaviors.
     */
    private function finalizeScrollContainer(
        RenderNode $node,
        array $style,
        int $childOffsetY,
        int $paddingLeft,
        int $paddingRight,
        array &$scrollContainers
    ): void {
        // ── Auto-stack: for scroll containers, position children vertically ──
        $stackY = $childOffsetY;
        $containerW = max($node->w - 14 - $paddingLeft - $paddingRight, 0);
        $autoStack = true;

        // Only auto-stack if NO child has explicit top/bottom
        // EXCEPTION: position:relative children with top/left only add offset, not position
        foreach ($node->children as $child) {
            $cs = $child->style;
            $childPos = $cs['position'] ?? 'static';
            if ($childPos !== 'relative' && (array_key_exists('top', $cs) || array_key_exists('bottom', $cs))) {
                $autoStack = false;
                break;
            }
        }

        if ($autoStack) {
            foreach ($node->children as $child) {
                $childStyle = $child->style;
                $mTop = $childStyle['marginTop'] ?? $childStyle['margin'] ?? 0;
                $mBottom = $childStyle['marginBottom'] ?? $childStyle['margin'] ?? 0;

                // Auto-width: inherit from container
                if (!array_key_exists('width', $child->style) || $child->w === 0) {
                    $child->w = max(0, (int)$containerW);
                    $child->style['width'] = $containerW;
                }
                // Apply min/max to child width
                $child->w = max(0, (int)$this->applyMinMax($childStyle, $child->w, true));

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
                        $this->shiftDescendantsY($grandchild, $dy);
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
        $node->contentHeight = $maxBottom - $childOffsetY;

        // ── Clamp scrollTop when content shrinks ────
        $maxScroll = max($node->contentHeight - $node->h, 0);
        if ($node->scrollTop > $maxScroll) {
            $oldScrollTop = $node->scrollTop;
            $node->scrollTop = $maxScroll;
            $shiftDown = $oldScrollTop - $node->scrollTop;
            if ($shiftDown > 0) {
                foreach ($node->children as $child) {
                    $child->y += $shiftDown;
                    $this->shiftDescendantsY($child, $shiftDown);
                }
            }
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
                    foreach ($node->children as $child) {
                        $child->x += $shiftRight;
                        $this->shiftDescendantsX($child, $shiftRight);
                    }
                }
            }
        }
    }
}
