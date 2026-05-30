<?php

namespace Px\Rendering;

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
            $style = $node->style;

            // Inherit parent's layer (CSS stacking context)
            if ($parent !== null && $parent->layer > 0) {
                $node->layer = $parent->layer;
            }

            // Apply own z-index → RenderNode layer
            $zIndex = (int)($style['zIndex'] ?? $style['zindex'] ?? 0);
            if ($zIndex > $node->layer) {
                $node->layer = $zIndex;
            }

            // Check for scroll container
            $overflowX = $style['overflowX'] ?? $style['overflow'] ?? 'visible';
            $overflowY = $style['overflowY'] ?? $style['overflow'] ?? 'visible';
            $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll');
            $hasVScroll = ($overflowY === 'auto' || $overflowY === 'scroll');

            if ($hasHScroll || $hasVScroll) {
                $node->isScrollContainer = true;
            }

            // Determine display mode
            $display = $style['display'] ?? 'block';
            $position = $style['position'] ?? 'static';

            switch ($display) {
                case 'flex':
                    $this->resolveFlexLayout($node, $parentX, $parentY, $parent, $scrollContainers);
                    break;
                case 'grid':
                    $this->resolveGridLayout($node, $parentX, $parentY, $parent, $scrollContainers);
                    break;
                default: // block, scroll-container, etc.
                    $this->resolveBlockLayout($node, $parentX, $parentY, $parent, $position, $scrollContainers);
                    break;
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
     * Block layout: absolute or static positioning.
     * Children are positioned relative to the parent.
     */
    private function resolveBlockLayout(
        RenderNode $node,
        int $parentX,
        int $parentY,
        ?RenderNode $parent,
        string $position,
        array &$scrollContainers
    ): void {
        $style = $node->style;

        // Read position from style
        $left = $style['left'] ?? 0;
        $top  = $style['top'] ?? 0;
        $right = $style['right'] ?? null;
        $bottom = $style['bottom'] ?? null;
        $width  = $style['width'] ?? 0;
        $height = $style['height'] ?? 0;

        // Handle flex:1 / flex:2 etc. → fill parent's remaining space
        $flex = $style['flex'] ?? '';
        if ($flex !== '' && $parent !== null && $parent->w > 0 && $width === 0) {
            $width = $parent->w - $left;
        }

        // Apply parent offset
        $node->x = $left + $parentX;
        $node->y = $top + $parentY;

        // Handle right/bottom as alternatives
        if ($right !== null && $parent !== null) {
            if ($width > 0) {
                $node->x = $parent->w - $width - $right + $parentX;
            }
        }
        if ($bottom !== null && $parent !== null) {
            if ($height > 0) {
                $node->y = $parent->h - $height - $bottom + $parentY;
            }
        }

        // Apply child's own margin
        $marginLeft = $style['marginLeft'] ?? $style['margin'] ?? 0;
        $marginTop = $style['marginTop'] ?? $style['margin'] ?? 0;
        $node->x += $marginLeft;
        $node->y += $marginTop;

        $node->w = max(0, (int)$width);
        $node->h = max(0, (int)$height);

        // Scroll container special handling
        $isScroll = $node->isScrollContainer;
        $scrollTop = 0;
        $scrollLeft = 0;
        if ($isScroll) {
            $scrollTop = $node->scrollTop;
            $scrollLeft = $node->scrollLeft;
        }

        // Resolve children
        $paddingTop = $style['paddingTop'] ?? $style['padding'] ?? 0;
        $paddingRight = $style['paddingRight'] ?? $style['padding'] ?? 0;
        $paddingBottom = $style['paddingBottom'] ?? $style['padding'] ?? 0;
        $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;

        $childOffsetX = $node->x + $paddingLeft;
        $childOffsetY = $node->y + $paddingTop;
        if ($isScroll) {
            $childOffsetY -= $scrollTop;
            $childOffsetX -= $scrollLeft;
        }

        // Children are always RenderNode[] array
        foreach ($node->children as $child) {
            $this->resolveNode($child, $childOffsetX, $childOffsetY, $node, $scrollContainers);
        }

        // Auto-stack: for scroll containers, position children vertically
        // and auto-fill width when no explicit left/top/width is set
        if ($isScroll) {
            $stackY = $childOffsetY;  // accounts for scroll offset + padding
            $containerW = max($node->w - 14 - $paddingLeft - $paddingRight, 0);
            $autoStack = true;

            // Only auto-stack if NO child has explicit top/bottom
            foreach ($node->children as $child) {
                $cs = $child->style;
                if (array_key_exists('top', $cs) || array_key_exists('bottom', $cs)) {
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
                    // Auto-position: stack vertically with margin, shift all descendants
                    $dy = ($stackY + $mTop) - $child->y;
                    $child->y = $stackY + $mTop;
                    if ($dy !== 0) {
                        $this->shiftDescendantsY($child, $dy);
                    }
                    $stackY += $child->h + $mBottom;
                }
            }

            // ── Calculate contentHeight (always, not just for autoStack) ────
            $maxBottom = $childOffsetY;
            foreach ($node->children as $child) {
                $bottom = $child->y + $child->h;
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
                // Use raw style values (independent of scroll offset) for content width
                $maxRight = 0;
                foreach ($node->children as $child) {
                    $cLeft = $child->style['left'] ?? 0;
                    $cWidth = $child->style['width'] ?? $child->w;
                    $right = $cLeft + $cWidth;
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

    /**
     * Flex layout: compute child positions using flex algorithm.
     */
    private function resolveFlexLayout(
        RenderNode $node,
        int $parentX,
        int $parentY,
        ?RenderNode $parent,
        array &$scrollContainers
    ): void {
        $style = $node->style;

        // Container position
        $left   = $style['left'] ?? 0;
        $top    = $style['top'] ?? 0;
        $width  = $style['width'] ?? 0;
        $height = $style['height'] ?? 0;

        $node->x = $left + $parentX;
        $node->y = $top + $parentY;
        $node->w = max(0, (int)$width);
        $node->h = max(0, (int)$height);

        // If width/height is 0, use parent dimensions
        if ($width === 0 && $parent !== null && $parent->w > 0) {
            $width = $parent->w - $left;
            $node->w = max(0, (int)$width);
        }
        if ($height === 0 && $parent !== null && $parent->h > 0) {
            $height = $parent->h - $top;
            $node->h = max(0, (int)$height);
        }

        // Handle flex:1 / flex:2 etc. → implicit width from parent for flex items
        $flex = $style['flex'] ?? '';
        if ($flex !== '' && $parent !== null) {
            if ($width === 0 && $parent->w > 0) {
                $node->w = max(0, (int)($parent->w - $left));
            }
        }

        $direction = $style['flexDirection'] ?? 'row';
        $gap       = $style['gap'] ?? 0;
        $justify   = $style['justifyContent'] ?? 'flex-start';
        $align     = $style['alignItems'] ?? 'stretch';
        $wrap      = $style['flexWrap'] ?? 'nowrap';

        // ── Padding ──
        $paddingTop    = $style['paddingTop'] ?? $style['padding'] ?? 0;
        $paddingRight  = $style['paddingRight'] ?? $style['padding'] ?? 0;
        $paddingBottom = $style['paddingBottom'] ?? $style['padding'] ?? 0;
        $paddingLeft   = $style['paddingLeft'] ?? $style['padding'] ?? 0;

        // Collect children and resolve their styles FIRST
        $children = [];
        foreach ($node->children as $child) {
            $this->resolveNode($child, $node->x + $paddingLeft, $node->y + $paddingTop, $node, $scrollContainers);
            $children[] = $child;
        }

        // ── Calculate flex item widths for flex:1 / flex:2 ──
        $flexGrowItems = [];
        $fixedTotalMain = 0;
        $hasFlexGrow = false;

        foreach ($children as $ch) {
            $childStyle = $ch->style;
            $childFlex = $childStyle['flex'] ?? '';
            $childMarginLeft = $childStyle['marginLeft'] ?? $childStyle['margin'] ?? 0;
            $childMarginRight = $childStyle['marginRight'] ?? $childStyle['margin'] ?? 0;
            $childMarginTop = $childStyle['marginTop'] ?? $childStyle['margin'] ?? 0;
            $childMarginBottom = $childStyle['marginBottom'] ?? $childStyle['margin'] ?? 0;

            if ($childFlex !== '') {
                $flexGrowItems[] = $ch;
                $hasFlexGrow = true;
                if ($direction === 'row' || $direction === 'row-reverse') {
                    $fixedTotalMain += $childMarginLeft + $childMarginRight;
                } else {
                    $fixedTotalMain += $childMarginTop + $childMarginBottom;
                }
            } else {
                $childW = $ch->w;
                $childH = $ch->h;
                if ($direction === 'row' || $direction === 'row-reverse') {
                    $fixedTotalMain += $childW + $childMarginLeft + $childMarginRight;
                } else {
                    $fixedTotalMain += $childH + $childMarginTop + $childMarginBottom;
                }
            }
        }

        // Distribute remaining space to flex grow items
        if ($hasFlexGrow) {
            $isRow = ($direction === 'row' || $direction === 'row-reverse');
            $containerMain = $isRow ? ($width - $paddingLeft - $paddingRight) : ($height - $paddingTop - $paddingBottom);
            $gapTotal = $gap * (count($children) - 1);
            $remainingSpace = max($containerMain - $fixedTotalMain - $gapTotal, 0);

            $totalFlexGrow = 0;
            foreach ($flexGrowItems as $ch) {
                $flexVal = (float)($ch->style['flex'] ?? '1');
                $totalFlexGrow += $flexVal;
            }

            foreach ($flexGrowItems as $ch) {
                $flexVal = (float)($ch->style['flex'] ?? '1');
                if ($isRow) {
                    $ch->w = max(0, (int)(($flexVal / max($totalFlexGrow, 1)) * $remainingSpace));
                    $ch->style['width'] = $ch->w;
                } else {
                    $ch->h = max(0, (int)(($flexVal / max($totalFlexGrow, 1)) * $remainingSpace));
                    $ch->style['height'] = $ch->h;
                }
            }
        }

        if (count($children) === 0) return;

        // Calculate flex layout
        $isRow = ($direction === 'row' || $direction === 'row-reverse');
        $reversed = ($direction === 'row-reverse' || $direction === 'column-reverse');

        // Total children size along main axis
        $totalMain = 0;
        $maxCross = 0;
        foreach ($children as $ch) {
            $childStyle = $ch->style;
            $childW = $ch->w;
            $childH = $ch->h;
            $childMarginLeft = $childStyle['marginLeft'] ?? $childStyle['margin'] ?? 0;
            $childMarginRight = $childStyle['marginRight'] ?? $childStyle['margin'] ?? 0;
            $childMarginTop = $childStyle['marginTop'] ?? $childStyle['margin'] ?? 0;
            $childMarginBottom = $childStyle['marginBottom'] ?? $childStyle['margin'] ?? 0;
            if ($isRow) {
                $totalMain += $childW + $childMarginLeft + $childMarginRight;
                $maxCross = max($maxCross, $childH);
            } else {
                $totalMain += $childH + $childMarginTop + $childMarginBottom;
                $maxCross = max($maxCross, $childW);
            }
        }
        $totalMain += $gap * (count($children) - 1);

        $containerMain = $isRow ? ($width - $paddingLeft - $paddingRight) : ($height - $paddingTop - $paddingBottom);
        $containerCross = $isRow ? ($height - $paddingTop - $paddingBottom) : ($width - $paddingLeft - $paddingRight);

        // Justify-content
        $mainStart = match ($justify) {
            'center'        => ($containerMain - $totalMain) / 2,
            'flex-end'      => $containerMain - $totalMain,
            'space-between' => 0,
            'space-around'  => 0,
            'space-evenly'  => 0,
            default         => 0,
        };

        $spaceBetween = 0;
        if ($justify === 'space-between' && count($children) > 1) {
            $spaceBetween = ($containerMain - $totalMain) / (count($children) - 1);
        } elseif ($justify === 'space-around' && count($children) > 0) {
            $spaceBetween = ($containerMain - $totalMain) / count($children);
            $mainStart = $spaceBetween / 2;
        } elseif ($justify === 'space-evenly' && count($children) > 0) {
            $spaceBetween = ($containerMain - $totalMain) / (count($children) + 1);
            $mainStart = $spaceBetween;
        }

        // Position children
        $currentMain = $mainStart;
        $indices = range(0, count($children) - 1);
        if ($reversed) {
            $indices = array_reverse($indices);
        }

        foreach ($indices as $i) {
            $ch = $children[$i];
            $childStyle = $ch->style;
            $childMarginLeft = $childStyle['marginLeft'] ?? $childStyle['margin'] ?? 0;
            $childMarginRight = $childStyle['marginRight'] ?? $childStyle['margin'] ?? 0;
            $childMarginTop = $childStyle['marginTop'] ?? $childStyle['margin'] ?? 0;
            $childMarginBottom = $childStyle['marginBottom'] ?? $childStyle['margin'] ?? 0;

            // Main axis position (inside padding + margin offset)
            if ($isRow) {
                $ch->x = $node->x + $paddingLeft + (int)$currentMain + $childMarginLeft;
            } else {
                $ch->y = $node->y + $paddingTop + (int)$currentMain + $childMarginTop;
            }

            // Cross axis: align-items stretch defaults to container size
            if ($align === 'stretch') {
                if ($isRow) {
                    if ($ch->h === 0) {
                        $ch->h = max(0, (int)$containerCross);
                        $ch->style['height'] = $ch->h;
                    }
                } else {
                    if ($ch->w === 0) {
                        $ch->w = max(0, (int)$containerCross);
                        $ch->style['width'] = $ch->w;
                    }
                }
            }

            // Cross axis alignment (inside padding)
            $crossSize = $isRow ? $ch->h : $ch->w;
            $crossOffset = match ($align) {
                'center'     => (int)(($containerCross - $crossSize) / 2),
                'flex-end'   => $containerCross - $crossSize,
                'stretch'    => 0,
                'flex-start' => 0,
                default      => 0,
            };

            if ($isRow) {
                $ch->y = $node->y + $paddingTop + $crossOffset;
            } else {
                $ch->x = $node->x + $paddingLeft + $crossOffset;
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
    }

    /**
     * Grid layout: position children in a CSS grid.
     */
    private function resolveGridLayout(
        RenderNode $node,
        int $parentX,
        int $parentY,
        ?RenderNode $parent,
        array &$scrollContainers
    ): void {
        $style = $node->style;

        $left   = $style['left'] ?? 0;
        $top    = $style['top'] ?? 0;
        $width  = $style['width'] ?? 0;
        $height = $style['height'] ?? 0;

        $node->x = $left + $parentX;
        $node->y = $top + $parentY;
        $node->w = max(0, (int)$width);
        $node->h = max(0, (int)$height);

        // Parse grid template
        $gridCols = $style['gridTemplateColumns'] ?? '';
        $gridRows = $style['gridTemplateRows'] ?? '';

        $colSpec = CssMappings::parseGridTemplateValue($gridCols);
        $rowSpec = CssMappings::parseGridTemplateValue($gridRows);

        $cols = $colSpec['count'] ?? 4;
        $cellW = $colSpec['size'] ?? 80;
        $rows = $rowSpec['count'] ?? 5;
        $cellH = $rowSpec['size'] ?? 60;

        $colGap = $style['gridColumnGap'] ?? $style['gap'] ?? 0;
        $rowGap = $style['gridRowGap'] ?? $style['gap'] ?? 0;

        // Collect children and resolve their styles
        $children = [];
        foreach ($node->children as $child) {
            $this->resolveNode($child, $node->x, $node->y, $node, $scrollContainers);
            $children[] = $child;
        }

        // Position children in grid
        $col = 0;
        $row = 0;
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

            $ch->x = $node->x + $col * $cellW + $colGap;
            $ch->y = $node->y + $row * $cellH + $rowGap;
            $ch->w = max(0, (int)($cellW - $colGap * 2));
            $ch->h = max(0, (int)($cellH - $rowGap * 2));

            $col++;
            if ($col >= $cols) {
                $col = 0;
                $row++;
            }
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
}
