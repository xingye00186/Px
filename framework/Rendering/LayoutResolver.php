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
        array &$scrollContainers,
        array $style
    ): void {
        // Read position from style
        $left = $style['left'] ?? 0;
        $top  = $style['top'] ?? 0;
        $right = $style['right'] ?? null;
        $bottom = $style['bottom'] ?? null;

        // ── 百分比尺寸解析 ──
        $parentW = ($parent !== null) ? $parent->w : 0;
        $parentH = ($parent !== null) ? $parent->h : 0;
        $width  = $this->resolvePercent($style, 'width', 'widthPercent', $parentW);
        $height = $this->resolvePercent($style, 'height', 'heightPercent', $parentH);

        // Handle flex:1 / flex:2 etc. → fill parent's remaining space
        $flex = $style['flex'] ?? '';
        if ($flex !== '' && $parent !== null && $parent->w > 0 && $width === 0) {
            $width = $parent->w - $left;
        }

        // ── position:relative 与 static/absolute 分离 ──
        // relative: left/top 是相对父节点的偏移量
        // static/absolute: left/top 是绝对定位
        if ($position === 'relative') {
            $node->x = $parentX + $left;
            $node->y = $parentY + $top;
        } else {
            $node->x = $left + $parentX;
            $node->y = $top + $parentY;
        }

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

        // Apply translate from animatedStyle (AnimationManager writes to animatedStyle)
        $translateX = $style['translateX'] ?? 0;
        $translateY = $style['translateY'] ?? 0;
        $node->x += $translateX;
        $node->y += $translateY;

        // ── 应用 min/max 约束 ──
        $node->w = max(0, (int)$this->applyMinMax($style, $width, true));
        $node->h = max(0, (int)$this->applyMinMax($style, $height, false));

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
                    // 注: x 已在 resolveBlockLayout 中通过 relative 公式正确处理
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

        $isRow = ($direction === 'row' || $direction === 'row-reverse');
        $reversed = ($direction === 'row-reverse' || $direction === 'column-reverse');

        // ── Padding ──
        $paddingTop    = $style['paddingTop'] ?? $style['padding'] ?? 0;
        $paddingRight  = $style['paddingRight'] ?? $style['padding'] ?? 0;
        $paddingBottom = $style['paddingBottom'] ?? $style['padding'] ?? 0;
        $paddingLeft   = $style['paddingLeft'] ?? $style['padding'] ?? 0;

        $containerMain = $isRow ? ($width - $paddingLeft - $paddingRight) : ($height - $paddingTop - $paddingBottom);
        $containerCross = $isRow ? ($height - $paddingTop - $paddingBottom) : ($width - $paddingLeft - $paddingRight);

        // ── Step 1: Collect children and resolve ──
        $children = [];
        foreach ($node->children as $child) {
            $this->resolveNode($child, $node->x + $paddingLeft, $node->y + $paddingTop, $node, $scrollContainers);
            $children[] = $child;
        }

        if (count($children) === 0) return;

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
        // flexItemData[i] = ['grow'=>float, 'shrink'=>float, 'basis'=>int, 'isFlexGrow'=>bool]
        $flexItemData = [];
        $hasFlexGrow = false;

        // 先确定 initial main size（考虑 flex-basis）
        foreach ($children as $ch) {
            $data = ['grow' => 0.0, 'shrink' => 1.0, 'basis' => -1, 'isFlexGrow' => false];
            $flexRaw = $ch->style['flex'] ?? '';
            if ($flexRaw !== '') {
                $fv = CssMappings::parseFlexValue($flexRaw);
                $data['grow'] = $fv['grow'];
                $data['shrink'] = $fv['shrink'];
                $data['basis'] = $fv['basis'];
            } else {
                // 单独属性
                $data['grow'] = (float)($ch->style['flexGrow'] ?? 0);
                $data['shrink'] = (float)($ch->style['flexShrink'] ?? 1);
            }
            if ($data['grow'] > 0) {
                $data['isFlexGrow'] = true;
                $hasFlexGrow = true;
            }
            $flexItemData[] = $data;
        }

        // ── Step 4: 应用 flex-basis 到 initial main size ──
        foreach ($children as $idx => $ch) {
            $data = $flexItemData[$idx];
            $basis = $data['basis'];
            if ($basis >= 0) {
                // flex shorthand 提供了明确的 basis 值
                if ($basis > 0) {
                    if ($isRow) {
                        $ch->w = max(0, $basis);
                    } else {
                        $ch->h = max(0, $basis);
                    }
                }
                // basis == 0 → content-based sizing (暂用当前 w/h)
            } else {
                // 无 flex shorthand basis, 检查独立 flex-basis 属性
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
                // 'auto' → 保持当前 width/height 值
            }
        }

        // ── Step 5: Flex-grow 分配 ──
        if ($hasFlexGrow) {
            // 计算固定尺寸项的总 main size
            $fixedTotalMain = 0;
            foreach ($children as $idx => $ch) {
                $data = $flexItemData[$idx];
                $cs = $ch->style;
                $mL = $cs['marginLeft'] ?? $cs['margin'] ?? 0;
                $mR = $cs['marginRight'] ?? $cs['margin'] ?? 0;
                $mT = $cs['marginTop'] ?? $cs['margin'] ?? 0;
                $mB = $cs['marginBottom'] ?? $cs['margin'] ?? 0;

                if ($data['isFlexGrow']) {
                    if ($isRow) {
                        $fixedTotalMain += $mL + $mR;
                    } else {
                        $fixedTotalMain += $mT + $mB;
                    }
                } else {
                    $sz = $isRow ? $ch->w : $ch->h;
                    if ($isRow) {
                        $fixedTotalMain += $sz + $mL + $mR;
                    } else {
                        $fixedTotalMain += $sz + $mT + $mB;
                    }
                }
            }

            $gapTotal = $gap * (count($children) - 1);
            $remainingSpace = max($containerMain - $fixedTotalMain - $gapTotal, 0);

            $totalFlexGrow = 0;
            foreach ($flexItemData as $entry) {
                $totalFlexGrow += $entry['grow'];
            }
            $totalFlexGrow = max($totalFlexGrow, 1);

            foreach ($children as $idx => $ch) {
                $data = $flexItemData[$idx];
                if ($data['isFlexGrow']) {
                    $allocated = (int)(($data['grow'] / $totalFlexGrow) * $remainingSpace);
                    if ($isRow) {
                        $ch->w = max(0, $allocated);
                        $ch->style['width'] = $ch->w;
                    } else {
                        $ch->h = max(0, $allocated);
                        $ch->style['height'] = $ch->h;
                    }
                }
            }
        }

        // ── Step 6: 计算初始 totalMain ──
        $totalMain = 0;
        $maxCross = 0;
        foreach ($children as $ch) {
            $cs = $ch->style;
            $mL = $cs['marginLeft'] ?? $cs['margin'] ?? 0;
            $mR = $cs['marginRight'] ?? $cs['margin'] ?? 0;
            $mT = $cs['marginTop'] ?? $cs['margin'] ?? 0;
            $mB = $cs['marginBottom'] ?? $cs['margin'] ?? 0;
            if ($isRow) {
                $totalMain += $ch->w + $mL + $mR;
                $maxCross = max($maxCross, $ch->h);
            } else {
                $totalMain += $ch->h + $mT + $mB;
                $maxCross = max($maxCross, $ch->w);
            }
        }
        $totalMain += $gap * (count($children) - 1);

        // ── Step 7: Flex-shrink (溢出收缩) ──
        if ($totalMain > $containerMain) {
            $overflow = $totalMain - $containerMain;

            // 计算总收缩权重: Σ(item.mainSize * item.shrink)
            $totalShrinkWeight = 0;
            foreach ($children as $idx => $ch) {
                $data = $flexItemData[$idx];
                if ($data['shrink'] > 0) {
                    $mainSize = $isRow ? $ch->w : $ch->h;
                    $totalShrinkWeight += $mainSize * $data['shrink'];
                }
            }

            if ($totalShrinkWeight > 0) {
                foreach ($children as $idx => $ch) {
                    $data = $flexItemData[$idx];
                    if ($data['shrink'] > 0) {
                        $mainSize = $isRow ? $ch->w : $ch->h;
                        $reduction = (int)($overflow * ($mainSize * $data['shrink']) / $totalShrinkWeight);
                        $newSize = max(0, $mainSize - $reduction);

                        // 应用 min-width/min-height 约束
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

        // ── Step 8: 对每个 flex item 应用 min/max 约束 ──
        foreach ($children as $ch) {
            $ch->w = max(0, (int)$this->applyMinMax($ch->style, $ch->w, true));
            $ch->h = max(0, (int)$this->applyMinMax($ch->style, $ch->h, false));
        }

        // ── Step 9: 重新计算总尺寸（收缩后尺寸已变） ──
        $totalMain = 0;
        $maxCross = 0;
        foreach ($children as $ch) {
            $cs = $ch->style;
            $mL = $cs['marginLeft'] ?? $cs['margin'] ?? 0;
            $mR = $cs['marginRight'] ?? $cs['margin'] ?? 0;
            $mT = $cs['marginTop'] ?? $cs['margin'] ?? 0;
            $mB = $cs['marginBottom'] ?? $cs['margin'] ?? 0;
            if ($isRow) {
                $totalMain += $ch->w + $mL + $mR;
                $maxCross = max($maxCross, $ch->h);
            } else {
                $totalMain += $ch->h + $mT + $mB;
                $maxCross = max($maxCross, $ch->w);
            }
        }
        $totalMain += $gap * (count($children) - 1);

        // ── Step 10: Justify-content ──
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

        // ── Step 11: Position children ──
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
            $oldX = $ch->x;
            $oldY = $ch->y;
            if ($isRow) {
                $ch->x = $node->x + $paddingLeft + (int)$currentMain + $childMarginLeft;
            } else {
                $ch->y = $node->y + $paddingTop + (int)$currentMain + $childMarginTop;
            }

            // ── align-self 覆盖 align-items ──
            $effectiveAlign = $childStyle['alignSelf'] ?? 'auto';
            if ($effectiveAlign === 'auto') {
                $effectiveAlign = $align;
            }

            // Cross axis: stretch defaults to container size
            if ($effectiveAlign === 'stretch') {
                if ($isRow) {
                    if ($ch->h === 0) {
                        $ch->h = max(0, (int)$containerCross);
                    }
                } else {
                    if ($ch->w === 0) {
                        $ch->w = max(0, (int)$containerCross);
                    }
                }
            }

            // Cross axis alignment
            $crossSize = $isRow ? $ch->h : $ch->w;
            $crossOffset = match ($effectiveAlign) {
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

            // Shift descendants if position changed from initial resolve
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
