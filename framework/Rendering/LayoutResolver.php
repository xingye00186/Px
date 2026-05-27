<?php

namespace Px\Rendering;

/**
 * LayoutResolver — 运行时 CSS 布局引擎
 *
 * 遍历 VNode 树，根据 CSS 样式计算每个节点的 x/y/w/h 位置。
 * 支持四种布局模式: block (absolute), flex, grid, scroll。
 *
 * PHP 8.4: 使用 match 表达式分发布局模式。
 *
 * 流程:
 *   1. 遍历 VNode 树 (DFS)
 *   2. 解析 inline style → computedStyle
 *   3. 根据 display 属性选择布局算法
 *   4. 设置 VNode.x, VNode.y, VNode.w, VNode.h
 */
class LayoutResolver
{
    /** CSS class → style properties map */
    private array $classStyles;

    /** Scroll containers tracked for scroll handling */
    private array $scrollContainers = [];

    public function __construct(array $classStyles = [])
    {
        $this->classStyles = $classStyles;
    }

    /**
     * Set CSS class styles at runtime.
     * Called by Application after component mount.
     */
    public function setClassStyles(array $classStyles): void
    {
        $this->classStyles = $classStyles;
    }

    /**
     * Resolve layout for the entire VNode tree.
     *
     * @param VNode $root Root VNode (mutated in-place)
     * @return array List of scroll containers: ['scrollContainers' => VNode[]]
     */
    public function resolve(VNode $root): array
    {
        $this->scrollContainers = [];
        $this->resolveNode($root, 0, 0, null);
        return ['scrollContainers' => $this->scrollContainers];
    }

    /**
     * Recursively resolve layout for a single node and its children.
     *
     * @param VNode $node Current node
     * @param int $parentX Accumulated parent X offset
     * @param int $parentY Accumulated parent Y offset
     * @param VNode|null $parent Parent VNode
     */
    private function resolveNode(VNode $node, int $parentX, int $parentY, ?VNode $parent): void
    {
        // Parse inline style
        $inlineStyle = $node->getInlineStyle();

        // Merge with CSS class styles
        $class = $node->getClass();
        $classStyle = [];
        if ($class !== '' && isset($this->classStyles[$class])) {
            $classStyle = $this->classStyles[$class];
        }

        // Merge: class style + inline style (inline overrides)
        $merged = $classStyle;
        foreach ($inlineStyle as $k => $v) {
            $merged[$k] = $v;
        }

        $node->computedStyle = $merged;

        // Inherit parent's layer (CSS stacking context)
        // Children without own z-index stay at the parent's layer level,
        // ensuring they render above elements behind the parent.
        if ($parent !== null && $parent->layer > 0) {
            $node->layer = $parent->layer;
        }

        // Apply own z-index → VNode layer (higher layer = rendered on top, blocks lower-layer clicks)
        // Supports both 'zIndex' (compile-time mapped key) and 'zindex' (re-parsed from generated code)
        $zIndex = (int)($merged['zIndex'] ?? $merged['zindex'] ?? 0);
        if ($zIndex > $node->layer) {
            $node->layer = $zIndex;
        }

        // Check for scroll container — must be BEFORE layout resolution
        // so resolveBlockLayout can access $node->isScrollContainer for auto-stack
        $overflowX = $merged['overflowX'] ?? $merged['overflow'] ?? 'visible';
        $overflowY = $merged['overflowY'] ?? $merged['overflow'] ?? 'visible';
        $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll') ||
            ($node->props[':scroll-left'] ?? '') !== '';
        $hasVScroll = ($overflowY === 'auto' || $overflowY === 'scroll') ||
            ($node->props[':scroll-top'] ?? '') !== '' ||
            $node->isScrollContainer;

        if ($hasHScroll || $hasVScroll) {
            $node->isScrollContainer = true;
        }

        // Determine display mode
        $display = $merged['display'] ?? ($node->isRoot() ? 'block' : 'block');
        $position = $merged['position'] ?? 'static';

        switch ($display) {
            case 'flex':
                $this->resolveFlexLayout($node, $parentX, $parentY, $parent);
                break;
            case 'grid':
                $this->resolveGridLayout($node, $parentX, $parentY, $parent);
                break;
            default: // block, scroll-container, etc.
                $this->resolveBlockLayout($node, $parentX, $parentY, $parent, $position);
                break;
        }
    }

    /**
     * Block layout: absolute or static positioning.
     * Children are positioned relative to the parent.
     */
    private function resolveBlockLayout(
        VNode $node,
        int $parentX,
        int $parentY,
        ?VNode $parent,
        string $position
    ): void {
        $style = $node->computedStyle;

        // Read position from style
        $left = $style['left'] ?? 0;
        $top  = $style['top'] ?? 0;
        $right = $style['right'] ?? null;
        $bottom = $style['bottom'] ?? null;
        $width  = $style['width'] ?? 0;
        $height = $style['height'] ?? 0;

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

        $node->w = $width;
        $node->h = $height;

        // Scroll container special handling
        $isScroll = $node->isScrollContainer;
        $scrollTop = 0;
        $scrollLeft = 0;
        if ($isScroll) {
            $scrollTop = $node->scrollTop;
            $scrollLeft = $node->scrollLeft;
        }

        // Resolve children
        $childOffsetX = $node->x;
        $childOffsetY = $node->y;
        if ($isScroll) {
            $childOffsetY -= $scrollTop;
            $childOffsetX -= $scrollLeft;
        }

        if ($node->children instanceof VNode) {
            $this->resolveNode($node->children, $childOffsetX, $childOffsetY, $node);
        } elseif (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $this->resolveNode($child, $childOffsetX, $childOffsetY, $node);
                }
            }

            // Auto-stack: for scroll containers, position children vertically
            // and auto-fill width when no explicit left/top/width is set
            if ($isScroll) {
                $stackY = $childOffsetY;  // accounts for scroll offset
                $containerW = max($node->w - 14, 0); // reserve scrollbar area
                $autoStack = true;

                // Only auto-stack if NO child has explicit top/bottom
                foreach ($node->children as $child) {
                    if ($child instanceof VNode) {
                        $cs = $child->computedStyle;
                        if (array_key_exists('top', $cs) || array_key_exists('bottom', $cs)) {
                            $autoStack = false;
                            break;
                        }
                    }
                }

                if ($autoStack) {
                    foreach ($node->children as $child) {
                        if ($child instanceof VNode) {
                            // Auto-width: inherit from container
                            if (!array_key_exists('width', $child->computedStyle) || $child->w === 0) {
                                $child->w = $containerW;
                                $child->computedStyle['width'] = $containerW;
                            }
                            // Auto-position: stack vertically, shift all descendants
                            $dy = $stackY - $child->y;
                            $child->y = $stackY;
                            if ($dy !== 0) {
                                $this->shiftDescendantsY($child, $dy);
                            }
                            $stackY += $child->h;
                        }
                    }
                }

                // ── Calculate contentHeight (always, not just for autoStack) ────
                $maxBottom = $childOffsetY;
                foreach ($node->children as $child) {
                    if ($child instanceof VNode) {
                        $bottom = $child->y + $child->h;
                        if ($bottom > $maxBottom) $maxBottom = $bottom;
                    }
                }
                $node->contentHeight = $maxBottom - $childOffsetY;

                // ── Clamp scrollTop when content shrinks ────
                // If items were deleted / content became shorter,
                // scrollTop may exceed the new maxScroll. Clamp
                // and shift children down to correct position.
                $maxScroll = max($node->contentHeight - $node->h, 0);
                if ($node->scrollTop > $maxScroll) {
                    $oldScrollTop = $node->scrollTop;
                    $node->scrollTop = $maxScroll;
                    $shiftDown = $oldScrollTop - $node->scrollTop;
                    if ($shiftDown > 0) {
                        foreach ($node->children as $child) {
                            if ($child instanceof VNode) {
                                $child = objval($child, VNode::class);
                                $child->y += $shiftDown;
                                $this->shiftDescendantsY($child, $shiftDown);
                            }
                        }
                    }
                }

                // ── Content width for horizontal scroll ────
                $overflowX = $node->computedStyle['overflowX'] ?? $node->computedStyle['overflow'] ?? 'visible';
                $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll') ||
                    ($node->props[':scroll-left'] ?? '') !== '';
                if ($hasHScroll) {
                    // Use raw style values (independent of scroll offset) for content width
                    $maxRight = 0;
                    foreach ($node->children as $child) {
                        if ($child instanceof VNode) {
                            $cLeft = $child->computedStyle['left'] ?? 0;
                            $cWidth = $child->computedStyle['width'] ?? $child->w;
                            $right = $cLeft + $cWidth;
                            if ($right > $maxRight) $maxRight = $right;
                        }
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
                                if ($child instanceof VNode) {
                                    $child->x += $shiftRight;
                                    $this->shiftDescendantsX($child, $shiftRight);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Recursively shift Y coordinate of a node and all its descendants.
     * Used after auto-stack repositions a parent to keep grandchildren aligned.
     */
    private function shiftDescendantsY(VNode $node, int $dy): void
    {
        $children = $node->children;
        if ($children instanceof VNode) {
            $children->y += $dy;
            $this->shiftDescendantsY($children, $dy);
        } elseif (is_array($children)) {
            foreach ($children as $child) {
                if ($child instanceof VNode) {
                    $child->y += $dy;
                    $this->shiftDescendantsY($child, $dy);
                }
            }
        }
    }

    /**
     * Recursively shift X coordinate of a node and all its descendants.
     */
    private function shiftDescendantsX(VNode $node, int $dx): void
    {
        $children = $node->children;
        if ($children instanceof VNode) {
            $children->x += $dx;
            $this->shiftDescendantsX($children, $dx);
        } elseif (is_array($children)) {
            foreach ($children as $child) {
                if ($child instanceof VNode) {
                    $child->x += $dx;
                    $this->shiftDescendantsX($child, $dx);
                }
            }
        }
    }

    /**
     * Flex layout: compute child positions using flex algorithm.
     */
    private function resolveFlexLayout(VNode $node, int $parentX, int $parentY, ?VNode $parent): void
    {
        $style = $node->computedStyle;

        // Container position
        $left   = $style['left'] ?? 0;
        $top    = $style['top'] ?? 0;
        $width  = $style['width'] ?? 0;
        $height = $style['height'] ?? 0;

        $node->x = $left + $parentX;
        $node->y = $top + $parentY;
        $node->w = $width;
        $node->h = $height;

        $direction = $style['flexDirection'] ?? 'row';
        $gap       = $style['gap'] ?? 0;
        $justify   = $style['justifyContent'] ?? 'flex-start';
        $align     = $style['alignItems'] ?? 'stretch';
        $wrap      = $style['flexWrap'] ?? 'nowrap';

        // Collect child dimensions
        $children = [];
        if ($node->children instanceof VNode) {
            $this->resolveNode($node->children, $node->x, $node->y, $node);
            $children[] = $node->children;
        } elseif (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $this->resolveNode($child, $node->x, $node->y, $node);
                    $children[] = $child;
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
            $ch = objval($ch, VNode::class);
            $childW = $ch->w;
            $childH = $ch->h;
            if ($isRow) {
                $totalMain += $childW;
                $maxCross = max($maxCross, $childH);
            } else {
                $totalMain += $childH;
                $maxCross = max($maxCross, $childW);
            }
        }
        $totalMain += $gap * (count($children) - 1);

        // Container main size
        $containerMain = $isRow ? $width : $height;
        $containerCross = $isRow ? $height : $width;

        // Justify-content
        $mainStart = match ($justify) {
            'center'        => ($containerMain - $totalMain) / 2,
            'flex-end'      => $containerMain - $totalMain,
            'space-between' => 0,
            'space-around'  => 0,
            'space-evenly'  => 0,
            default         => 0, // flex-start
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
            $ch = objval($children[$i], VNode::class);

            // Main axis position
            if ($isRow) {
                $ch->x = $node->x + (int)$currentMain;
            } else {
                $ch->y = $node->y + (int)$currentMain;
            }

            // Cross axis alignment
            $crossSize = $isRow ? $ch->h : $ch->w;
            $crossOffset = match ($align) {
                'center'     => (int)(($containerCross - $crossSize) / 2),
                'flex-end'   => $containerCross - $crossSize,
                'stretch'    => 0,
                'flex-start' => 0,
                default      => 0,
            };

            if ($isRow) {
                $ch->y = $node->y + $crossOffset;
            } else {
                $ch->x = $node->x + $crossOffset;
            }

            // Advance main position
            $chMainSize = $isRow ? $ch->w : $ch->h;
            $currentMain += $chMainSize + $gap + $spaceBetween;
        }
    }

    /**
     * Grid layout: position children in a CSS grid.
     */
    private function resolveGridLayout(VNode $node, int $parentX, int $parentY, ?VNode $parent): void
    {
        $style = $node->computedStyle;

        $left   = $style['left'] ?? 0;
        $top    = $style['top'] ?? 0;
        $width  = $style['width'] ?? 0;
        $height = $style['height'] ?? 0;

        $node->x = $left + $parentX;
        $node->y = $top + $parentY;
        $node->w = $width;
        $node->h = $height;

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
        if ($node->children instanceof VNode) {
            $this->resolveNode($node->children, $node->x, $node->y, $node);
            $children[] = $node->children;
        } elseif (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $this->resolveNode($child, $node->x, $node->y, $node);
                    $children[] = $child;
                }
            }
        }

        // Position children in grid
        $col = 0;
        $row = 0;
        foreach ($children as $ch) {
            $ch = objval($ch, VNode::class);
            // Use explicit grid-column/grid-row from inline style (CSS 1-based)
            $childStyle = $ch->computedStyle;
            $explicitCol = $childStyle['gridColumn'] ?? null;
            $explicitRow = $childStyle['gridRow'] ?? null;

            if ($explicitCol !== null && $explicitCol !== '') {
                $col = (int)$explicitCol - 1; // CSS 1-based → internal 0-based
            }
            if ($explicitRow !== null && $explicitRow !== '') {
                $row = (int)$explicitRow - 1; // CSS 1-based → internal 0-based
            }

            $ch->x = $node->x + $col * $cellW + $colGap;
            $ch->y = $node->y + $row * $cellH + $rowGap;
            $ch->w = $cellW - $colGap * 2;
            $ch->h = $cellH - $rowGap * 2;

            $col++;
            if ($col >= $cols) {
                $col = 0;
                $row++;
            }
        }
    }
}
