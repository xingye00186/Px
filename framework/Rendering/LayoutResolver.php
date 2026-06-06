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



    private ?RenderNode $rootNode = null;







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



        $this->rootNode = $root;



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



            // ── 脏标记检查：进入完整布局计算 ──
            // 统一入口：在 style 解析处合并 animatedStyle
            // 统一入口：在 style 解析处合并 animatedStyle
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



                case 'inline-flex':



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



            if ($node->isScrollContainer && ($display === 'flex' || $display === 'inline-flex' || $display === 'grid')) {



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

                } else {
                    // No horizontal scroll — content width equals container width
                    $node->contentWidth = $node->w;
                }



            }







            // ── position:sticky 处理 ──
            if ($position === 'sticky') {



                $stickyTop = (int)($effectiveStyle['top'] ?? 0);



                // ?????? Y (???????)
                $node->style['_stickyBaseY'] = $node->y;







                // ????????????????
                for ($i = count($scrollContainers) - 1; $i >= 0; $i--) {



                    $sc = $scrollContainers[$i];



                    // ?????????????????????
                    if ($node->x >= $sc->x && $node->x < $sc->x + $sc->w &&



                        $node->y >= $sc->y && $node->y < $sc->y + $sc->h) {



                        // ── 脏路径：完整布局计算 ──
                        $logicalY = $node->y + $sc->scrollTop;



                        $stuckY = $sc->y + $stickyTop;



                        $currentY = $logicalY - $sc->scrollTop;



                        if ($currentY < $stuckY) {



                            $dy = $stuckY - $currentY;



                            $node->y = $stuckY;



                            foreach ($node->children as $child) {



                                $this->shiftDescendantsY($child, $dy);



                            }



                        }



                        break;



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



            // ???? ????????????????????????? margin??????
            $style = $node->style;



            $marginLeft = $style['marginLeft'] ?? $style['margin'] ?? 0;



            $marginTop = $style['marginTop'] ?? $style['margin'] ?? 0;



            // ── CSS min/max 约束辅助方法 ──

            // For static flex/grid items, their positions are determined by the parent's



            // layout algorithm (flex/grid), not by 'left'/'top' style values.



            $cleanPos = $style['position'] ?? 'static';



            if ($cleanPos !== 'static') {



                if (array_key_exists('left', $style)) {



                    $node->x = $style['left'] + $parentX + $marginLeft;



                }



                if (array_key_exists('top', $style)) {



                    $node->y = $style['top'] + $parentY + $marginTop;



                }



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







            // ── 子节点脏标记处理 ──
            // Flex/grid container with dirty children: re-run full layout



            $display = $style['display'] ?? 'block';



            if (($display === 'flex' || $display === 'grid') && !empty($node->children)) {



                foreach ($node->children as $ch) {



                    if ($ch->layoutDirty) {



                        $node->layoutDirty = true;



                        $this->resolveNode($node, $parentX, $parentY, $parent, $scrollContainers);



                        return;



                    }



                }



            }



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
     * - resolveNormalFlow（static/relative）
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







        // ???? ????????????
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







        // ── Scroll container post-processing for flex/grid display modes ──

        if ($node->isScrollContainer) {



            $paddingTop = $style['paddingTop'] ?? $style['padding'] ?? 0;



            $paddingRight = $style['paddingRight'] ?? $style['padding'] ?? 0;



            $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;



            $childOffsetY = $node->y + $paddingTop - $node->scrollTop;



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



                $containerW = max($node->w - $paddingLeft - $paddingRight, 0);







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



                    $child->w = max(0, (int)$this->applyMinMax($childStyle, $child->w, true));







                    // ── Two-pass: re-resolve internal children of sized items ──

                    $childDisplay = $childStyle['display'] ?? 'block';



                    if ($childDisplay === 'flex' || $childDisplay === 'grid') {



                        if (count($child->children) > 0) {



                            $child->layoutDirty = true;



                            foreach ($child->children as $gc) {



                                $gc->layoutDirty = true;



                            }



                            $this->resolveNode($child, $node->x + $paddingLeft, $stackY, $node, $scrollContainers);



                        }



                    }







                    // ── Auto-width/height for block containers (CSS content-based sizing) ──

                    $childML = $childStyle['marginLeftAuto'] ?? false;



                    $childMR = $childStyle['marginRightAuto'] ?? false;



                    if ($childML || $childMR) {



                        $this->resolveMarginAuto($child, $childStyle, $containerW, 0);



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



                            $this->shiftDescendantsY($grandchild, $dy);



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



        // 判断定位模式：fixed vs absolute
        $pos = $style['position'] ?? 'absolute';



        $isFixed = ($pos === 'fixed');







        if ($isFixed && $this->rootNode !== null) {



            // B.4: position:fixed v1 退化为 absolute（同分支）
            // 判断定位模式：fixed vs absolute
            $ancestor = $this->rootNode;



        } else {



            // position:absolute — 查找并缓存定位祖先
            $this->resolvePositioningAncestor($node);



            $ancestor = $node->positioningAncestor;



        }







        // 参考系：定位祖先的 padding box，退化时用 (0,0)
        $ancestorX = ($ancestor !== null) ? $ancestor->x : 0;



        $ancestorY = ($ancestor !== null) ? $ancestor->y : 0;



        $ancestorW = ($ancestor !== null) ? $ancestor->w : 0;



        $ancestorH = ($ancestor !== null) ? $ancestor->h : 0;







        $marginLeft = $style['marginLeft'] ?? $style['margin'] ?? 0;



        $marginTop = $style['marginTop'] ?? $style['margin'] ?? 0;



        // Guard: margin:auto resolved later in resolveMarginAuto; treat as 0 here



        if ($marginLeft === 'auto') $marginLeft = 0;



        if ($marginTop === 'auto') $marginTop = 0;



        $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;



        $paddingRight = $style['paddingRight'] ?? $style['padding'] ?? 0;



        $paddingTop = $style['paddingTop'] ?? $style['padding'] ?? 0;







        // relative: left/top 作为额外偏移（不改变 stack 推进位置）
        $node->x = $ancestorX + $left + $marginLeft;



        $node->y = $ancestorY + $top + $marginTop;







        // right/bottom 替代（当 width/height 已设时用尺寸推算，否则仅锚定边缘）
        if ($right !== null && $ancestor !== null) {

            if ($width > 0) {

                $node->x = $ancestorX + $ancestorW - $width - $right;

            } else {

                // No explicit width — use element's actual width from layout
                $node->x = $ancestorX + $ancestorW - $node->w - $right;

            }



        }



        if ($bottom !== null && $ancestor !== null) {

            if ($height > 0) {

                $node->y = $ancestorY + $ancestorH - $height - $bottom;

            } else {

                // No explicit height — use element's actual height from layout
                $node->y = $ancestorY + $ancestorH - $node->h - $bottom;

            }



        }







        // ── margin:auto 水平 + 垂直居中 ──
        $parentContentW = ($ancestor !== null) ? max(0, $ancestorW - $paddingLeft - $paddingRight) : 0;



        $paddingBottom = $style['paddingBottom'] ?? $style['padding'] ?? 0;



        $parentContentH = ($ancestor !== null) ? max(0, $ancestorH - $paddingTop - $paddingBottom) : 0;



        $this->resolveMarginAuto($node, $style, $parentContentW, $parentContentH);







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







        // ???? ????????????
        $parentW = ($parent !== null) ? $parent->w : 0;



        $parentH = ($parent !== null) ? $parent->h : 0;



        $width  = $this->resolvePercent($style, 'width', 'widthPercent', $parentW);



        $height = $this->resolvePercent($style, 'height', 'heightPercent', $parentH);







        $node->w = max(0, (int)$width);



        $node->h = max(0, (int)$height);







        // ── Scroll container post-processing for flex/grid display modes ──

        $parentDisplay = ($parent !== null) ? ($parent->style['display'] ?? '') : '';



        $isFlexOrGridItem = ($parentDisplay === 'flex' || $parentDisplay === 'grid');



        if (!$isFlexOrGridItem) {



            $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);



            if (!$hasExplicitW && $width === 0 && $parent !== null) {



                $width = $parent->w;



                $node->w = max(0, (int)$width);



            }



        } elseif ($parent !== null && $parentDisplay === 'flex') {



            // ── Scroll container post-processing for flex/grid display modes ──

            // cross-axis (width) size for correct first-pass internal layout.



            // Without this, flex items with display:flex/grid get width=0, causing



            // their internal grid to compute 1 column with inflated height, which



            // then triggers flex-shrink and damages sibling items' explicit sizes.



            $parentDirection = $parent->style['flexDirection'] ?? 'row';



            $parentIsColumn = ($parentDirection === 'column' || $parentDirection === 'column-reverse');



            $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);



            if ($parentIsColumn && !$hasExplicitW && $width === 0) {



                $parentPadL = $parent->style['paddingLeft'] ?? $parent->style['padding'] ?? 0;



                $parentPadR = $parent->style['paddingRight'] ?? $parent->style['padding'] ?? 0;



                $parentContentW = max(0, $parent->w - $parentPadL - $parentPadR);



                $width = $parentContentW;



                $node->w = max(0, (int)$width);



            }



        }







        // ── 脏路径：完整布局计算 ──
        $direction = $style['flexDirection'] ?? 'row';



        $isRow = ($direction === 'row' || $direction === 'row-reverse');







        // Note: Cross-axis fill is handled by the parent's align-items: stretch



        // in Steps 10-11 below. Do NOT fill cross-axis from parent here, as this



        // incorrectly sets the container's dimension when it's a flex item whose



        // cross-axis direction differs from the parent's.



        // Example: a row flex-container child of a column flex-container should



        // NOT have its height filled from parent height — only width should stretch.





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



                // 捕获独立的 flex-basis 属性（仅数值，'auto' 由默认 -1 处理）
                if (isset($ch->style['flexBasis']) && $ch->style['flexBasis'] !== 'auto') {
                    $data['basis'] = (int)$ch->style['flexBasis'];
                }



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



                $data = ['grow' => 0.0, 'shrink' => 1.0, 'basis' => -1, 'isFlexGrow' => false, 'hasExplicitCrossSize' => false, 'crossAxisSized' => false];



                $flexRaw = $ch->style['flex'] ?? '';



                if ($flexRaw !== '') {



                    $fv = CssMappings::parseFlexValue($flexRaw);



                    $data['grow'] = $fv['grow'];



                    $data['shrink'] = $fv['shrink'];



                    $data['basis'] = $fv['basis'];



                } else {



                    $data['grow'] = (float)($ch->style['flexGrow'] ?? 0);



                    $data['shrink'] = (float)($ch->style['flexShrink'] ?? 1);



                    // 捕获独立的 flex-basis 属性（仅数值，'auto' 由默认 -1 处理）
                    if (isset($ch->style['flexBasis']) && $ch->style['flexBasis'] !== 'auto') {
                        $data['basis'] = (int)$ch->style['flexBasis'];
                    }



                }



                if ($data['grow'] > 0) {



                    $data['isFlexGrow'] = true;



                    $lineHasFlexGrow = true;



                }



                $data['hasExplicitCrossSize'] = $isRow



                    ? array_key_exists('height', $ch->style)



                    : array_key_exists('width', $ch->style);



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

            // CSS spec: when flex container's main-axis size is auto (not explicitly



            // set), content determines size, so no overflow can occur.



            // Skip shrink when containerMain == 0 (auto main-axis size).



            if ($lineContainerMain > 0 && $lineTotalMain > $lineContainerMain) {



                $overflow = $lineTotalMain - $lineContainerMain;



                $totalShrinkWeight = 0;



                foreach ($lineChildren as $idx => $ch) {



                    $data = $lineFlexData[$idx];



                    if ($data['shrink'] > 0) {



                        $mainSize = $isRow ? $ch->w : $ch->h;



                        // CSS §9.7: shrink weight = flex-basis × flex-shrink
                        $shrinkBasis = $mainSize;
                        if ($data['basis'] >= 0) {
                            $shrinkBasis = (int)$data['basis'];
                        }



                        $totalShrinkWeight += $shrinkBasis * $data['shrink'];



                    }



                }



                if ($totalShrinkWeight > 0) {



                    foreach ($lineChildren as $idx => $ch) {



                        $data = $lineFlexData[$idx];



                        if ($data['shrink'] > 0) {



                            $mainSize = $isRow ? $ch->w : $ch->h;



                            // CSS §9.7: shrink reduction = overflow × (basis × shrink) / totalWeight
                            $shrinkBasis = $mainSize;
                            if ($data['basis'] >= 0) {
                                $shrinkBasis = (int)$data['basis'];
                            }



                            $reduction = (int)($overflow * ($shrinkBasis * $data['shrink']) / $totalShrinkWeight);



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



                } else {
                    // totalShrinkWeight == 0: CSS spec §9.7 says distribute equally
                    $equalShare = $lineCount > 0 ? (int)($overflow / $lineCount) : 0;
                    foreach ($lineChildren as $idx => $ch) {
                        $data = $lineFlexData[$idx];
                        if ($data['shrink'] > 0) {
                            $mainSize = $isRow ? $ch->w : $ch->h;
                            $newSize = max(0, $mainSize - $equalShare);
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







            // ── Step 11: Position children in this line ──

            // Auto margins absorb positive free space BEFORE justify-content.



            $hasAutoMainMargin = false;



            $autoMarginCount = 0;



            foreach ($lineChildren as $ch) {



                $cs = $ch->style;



                $mL = $cs['marginLeftAuto'] ?? false;



                $mR = $cs['marginRightAuto'] ?? false;



                if ($mL || $mR) $hasAutoMainMargin = true;



                if ($mL) $autoMarginCount++;



                if ($mR) $autoMarginCount++;



            }



            $resolvedAutoMargins = null;



            if ($hasAutoMainMargin) {



                $remainingForAuto = $lineContainerMain - $lineTotalMain;



                if ($remainingForAuto > 0 && $autoMarginCount > 0) {



                    $spacePerAuto = (int)($remainingForAuto / $autoMarginCount);



                    $resolvedAutoMargins = [];



                    foreach ($lineChildren as $idx => $ch) {



                        $cs = $ch->style;



                        $resolvedAutoMargins[$idx] = [



                            'left'  => ($cs['marginLeftAuto'] ?? false) ? $spacePerAuto : 0,



                            'right' => ($cs['marginRightAuto'] ?? false) ? $spacePerAuto : 0,



                        ];



                    }



                    // Recalculate lineTotalMain with resolved auto margins



                    $lineTotalMain = 0;



                    foreach ($lineChildren as $idx => $ch) {



                        $mL = $resolvedAutoMargins[$idx]['left'];



                        $mR = $resolvedAutoMargins[$idx]['right'];



                        $mT = $ch->style['marginTop'] ?? $ch->style['margin'] ?? 0;



                        $mB = $ch->style['marginBottom'] ?? $ch->style['margin'] ?? 0;



                        if ($isRow) {



                            $lineTotalMain += $ch->w + $mL + $mR;



                        } else {



                            $lineTotalMain += $ch->h + $mT + $mB;



                        }



                    }



                    $lineTotalMain += $gap * ($lineCount - 1);



                }



            }







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



                $childMarginLeft = ($resolvedAutoMargins !== null && isset($resolvedAutoMargins[$i]['left']) ? $resolvedAutoMargins[$i]['left'] : null)



                    ?? $childStyle['marginLeft'] ?? $childStyle['margin'] ?? 0;



                $childMarginRight = ($resolvedAutoMargins !== null && isset($resolvedAutoMargins[$i]['right']) ? $resolvedAutoMargins[$i]['right'] : null)



                    ?? $childStyle['marginRight'] ?? $childStyle['margin'] ?? 0;



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



                            if (!$lineFlexData[$i]['hasExplicitCrossSize']) {



                                $crossBefore = $ch->h;



                                $stretchedH = max(0, (int)($lineMaxCross - $childMarginTop - $childMarginBottom));



                                if ($stretchedH > 0) {



                                    $ch->h = $stretchedH;



                                    $lineFlexData[$i]['crossAxisSized'] = ($ch->h !== $crossBefore);



                                }



                            }



                            $ch->y = $node->y + $paddingTop + $lineCrossBase + $childMarginTop;



                        } else {



                            if (!$lineFlexData[$i]['hasExplicitCrossSize']) {



                                $crossBefore = $ch->w;



                                $stretchedW = max(0, (int)($lineMaxCross - $childMarginLeft - $childMarginRight));



                                if ($stretchedW > 0) {



                                    $ch->w = $stretchedW;



                                    $lineFlexData[$i]['crossAxisSized'] = ($ch->w !== $crossBefore);



                                }



                            }



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



                    // Non-wrapping: full containerCross with margin adjustment



                    if ($effectiveAlign === 'stretch') {



                        if ($isRow && !$lineFlexData[$i]['hasExplicitCrossSize']) {



                            $crossBefore = $ch->h;



                            $stretchedH = max(0, (int)($containerCross - $childMarginTop - $childMarginBottom));



                            if ($stretchedH > 0) {



                                $ch->h = $stretchedH;



                                $lineFlexData[$i]['crossAxisSized'] = ($ch->h !== $crossBefore);



                            }



                        } elseif (!$isRow && !$lineFlexData[$i]['hasExplicitCrossSize']) {



                            $crossBefore = $ch->w;



                            $stretchedW = max(0, (int)($containerCross - $childMarginLeft - $childMarginRight));



                            if ($stretchedW > 0) {



                                $ch->w = $stretchedW;



                                $lineFlexData[$i]['crossAxisSized'] = ($ch->w !== $crossBefore);



                            }



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



                } else {



                    $ch->x += $childMarginLeft;



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







            // ── Two-pass: re-resolve internal children of sized items ──

            // After flex-grow (Step 5) or cross-axis stretch (Step 11), flex items'



            // main-axis or cross-axis size may have changed. Their internal children



            // were laid out in Step 1 using preliminary sizes.



            // This re-resolves grandchildren with the flex item's final size.



            //



            // CSS standard: when a flex item's cross-axis size changes due to



            // align-items:stretch, browsers re-flow the interior. For block/scroll



            // containers, re-resolving children individually suffices. For flex/grid



            // containers, the full layout must re-run so that justify-content,



            // align-items, flex-wrap etc. are calculated with the new size.



            foreach ($lineChildren as $idxTp => $chTp) {



                $dataTp = $lineFlexData[$idxTp];



                $needsTwoPass = $dataTp['isFlexGrow'] || $dataTp['crossAxisSized'];



                if ($needsTwoPass && count($chTp->children) > 0) {



                    $display = $chTp->style['display'] ?? 'block';







                    if ($display === 'flex' || $display === 'grid') {



                        // Full re-layout for flex/grid containers.



                        // Derive parent coords from current position minus offset.



                        $leftOff = $chTp->style['left'] ?? 0;



                        $topOff  = $chTp->style['top'] ?? 0;



                        $prX = $chTp->x - $leftOff;



                        $prY = $chTp->y - $topOff;







                        // Temporarily set style width/height to current external



                        // size so the re-run layout uses correct dimensions.



                        $hasOrigW = array_key_exists('width', $chTp->style);



                        $hasOrigH = array_key_exists('height', $chTp->style);



                        $origW = $chTp->style['width'] ?? null;



                        $origH = $chTp->style['height'] ?? null;



                        $chTp->style['width'] = $chTp->w;



                        $chTp->style['height'] = $chTp->h;







                        $chTp->layoutDirty = true;



                        foreach ($chTp->children as $gc) {



                            $gc->layoutDirty = true;



                        }



                        $this->resolveNode($chTp, $prX, $prY, $parent, $scrollContainers);







                        if ($hasOrigW) {



                            $chTp->style['width'] = $origW;



                        } else {



                            unset($chTp->style['width']);



                        }



                        if ($hasOrigH) {



                            $chTp->style['height'] = $origH;



                        } else {



                            unset($chTp->style['height']);



                        }



                    } else {



                        // Current behavior for block/scroll containers



                        $chPadLtp = $chTp->style['paddingLeft'] ?? $chTp->style['padding'] ?? 0;



                        $chPadTtp = $chTp->style['paddingTop'] ?? $chTp->style['padding'] ?? 0;



                        $gcOffsetX = $chTp->x + $chPadLtp;



                        $gcOffsetY = $chTp->y + $chPadTtp;



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



                if ($needsTwoPass && $chTp->isScrollContainer) {



                    $padTsp = $chTp->style['paddingTop'] ?? $chTp->style['padding'] ?? 0;



                    $padLsp = $chTp->style['paddingLeft'] ?? $chTp->style['padding'] ?? 0;



                    $padRsp = $chTp->style['paddingRight'] ?? $chTp->style['padding'] ?? 0;



                    $coffY = $chTp->y + $padTsp - $chTp->scrollTop;



                    $this->finalizeScrollContainer($chTp, $chTp->style, $coffY, $padLsp, $padRsp, $scrollContainers);



                }



            }







            // Advance cross axis offset for next wrapping line



            $accumulatedCrossOffset += $lineMaxCross + $gap;



        }







        // ── Scroll container post-processing for flex/grid display modes ──

        // CSS standard: a flex container with auto main-axis size computes it



        // from children. With auto cross-axis size, it also computes from children.



        //



        // CRITICAL: array_key_exists('height', $style) returns TRUE when



        // height:auto is set, which caused auto-height to be SKIPPED. We must



        // explicitly check that the value is not 'auto' or empty.



        $hasExplicitW = array_key_exists('width', $style) && $style['width'] !== 'auto' && $style['width'] !== '';



        $hasExplicitH = array_key_exists('height', $style) && $style['height'] !== 'auto' && $style['height'] !== '';



        $hasWPct = array_key_exists('widthPercent', $style);



        $hasHPct = array_key_exists('heightPercent', $style);







        if ($isRow) {



            // Main-axis: auto-width from children



            if (!$hasExplicitW && !$hasWPct) {



                $maxRight = $node->x + $paddingLeft;



                foreach ($children as $ch) {



                    $chRight = $ch->x + $ch->w;



                    $mR = $ch->style['marginRight'] ?? $ch->style['margin'] ?? 0;



                    if ($chRight + $mR > $maxRight) $maxRight = (int)($chRight + $mR);



                }



                $node->w = (int)max($node->w, $maxRight - $node->x + $paddingRight);



            }



            // Cross-axis: auto-height from children



            if (!$hasExplicitH && !$hasHPct) {



                $maxBottom = $node->y + $paddingTop;



                foreach ($children as $ch) {



                    $chBottom = $ch->y + $ch->h;



                    $mB = $ch->style['marginBottom'] ?? $ch->style['margin'] ?? 0;



                    if ($chBottom + $mB > $maxBottom) $maxBottom = (int)($chBottom + $mB);



                }



                $node->h = (int)max($node->h, $maxBottom - $node->y + $paddingBottom);



            }



        } else {



            // Cross-axis: auto-width from children



            if (!$hasExplicitW && !$hasWPct) {



                $maxRight = $node->x + $paddingLeft;



                foreach ($children as $ch) {



                    $chRight = $ch->x + $ch->w;



                    $mR = $ch->style['marginRight'] ?? $ch->style['margin'] ?? 0;



                    if ($chRight + $mR > $maxRight) $maxRight = (int)($chRight + $mR);



                }



                $node->w = (int)max($node->w, $maxRight - $node->x + $paddingRight);



            }



            // Main-axis: auto-height from children



            if (!$hasExplicitH && !$hasHPct) {



                $maxBottom = $node->y + $paddingTop;



                foreach ($children as $ch) {



                    $chBottom = $ch->y + $ch->h;



                    $mB = $ch->style['marginBottom'] ?? $ch->style['margin'] ?? 0;



                    if ($chBottom + $mB > $maxBottom) $maxBottom = (int)($chBottom + $mB);



                }



                $node->h = (int)max($node->h, $maxBottom - $node->y + $paddingBottom);



            }



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







        // ???? ????????????
        $parentW = ($parent !== null) ? $parent->w : 0;



        $parentH = ($parent !== null) ? $parent->h : 0;



        $width  = $this->resolvePercent($style, 'width', 'widthPercent', $parentW);



        $height = $this->resolvePercent($style, 'height', 'heightPercent', $parentH);







        // CSS Grid Level 1: block-level grid container with auto width fills containing block



        $hasExplicitW = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);



        if (!$hasExplicitW && $width === 0 && $parent !== null) {



            $width = $parent->w;



        }



        // Note: height:auto for grid containers is content-based (computed below)







        $node->x = $left + $parentX;



        $node->y = $top + $parentY;







        // Apply translate from animatedStyle



        $translateX = $style['translateX'] ?? 0;



        $translateY = $style['translateY'] ?? 0;



        $node->x += $translateX;



        $node->y += $translateY;







        // ── 应用 min/max 约束到尺寸（在子节点递归之前，确保 parent->w/h 立即可用）──
        $node->w = max(0, (int)$this->applyMinMax($style, $width, true));



        $node->h = max(0, (int)$this->applyMinMax($style, $height, false));







        // Parse grid template



        $gridCols = $style['gridTemplateColumns'] ?? '';



        $gridRows = $style['gridTemplateRows'] ?? '';







        $colSpec = CssMappings::parseGridTemplateValue($gridCols);



        $rowSpec = CssMappings::parseGridTemplateValue($gridRows);







        // Gap values (must be defined before 1fr calculation)



        $colGap = $style['gridColumnGap'] ?? $style['gap'] ?? 0;



        $rowGap = $style['gridRowGap'] ?? $style['gap'] ?? 0;







        $cols = null;



        $cellW = null;



        $colRepeat = $colSpec['repeat'] ?? null;







        // ???? auto-fill/auto-fit: ??????????????????????? ????
        if ($colRepeat === 'auto-fill' || $colRepeat === 'auto-fit') {



            $minColW = (int)($colSpec['min'] ?? 245);



            // CSS Grid 规范 §7.1: cols = floor((availableW + gap) / (min + gap))
            $cols = (int)max(1, floor(($node->w + $colGap) / ($minColW + $colGap)));



            // ── 计算列宽与单元格数 ──
            $totalGaps = $colGap * ($cols - 1);



            $cellW = (int)max(0, ($node->w - $totalGaps) / $cols);



        }







        if ($cols === null) {



            $cols = $colSpec['count'] ?? 4;



        }



        if ($cellW === null) {



            $cellW = $colSpec['size'] ?? 80;



        }







        // 1fr 支持：根据容器宽度按比例分配
        if (($colSpec['unit'] ?? '') === 'fr' && $node->w > 0 && $colRepeat !== 'auto-fill' && $colRepeat !== 'auto-fit') {



            $totalGaps = $colGap * ($cols - 1);



            $cellW = (int)max(0, ($node->w - $totalGaps) / $cols);



        }



        $rows = $rowSpec['count'] ?? 5;



        $cellH = $rowSpec['size'] ?? 60;



        // 1fr 支持：根据容器宽度按比例分配
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







            // 计算网格单元位置（gap 只在列之间，不在最左侧）
            $cellX = $node->x + $col * ($cellW + $colGap);



            $cellY = $node->y + $row * ($cellH + $rowGap);



            $cellWFinal = max(0, (int)$cellW);



            $cellHFinal = max(0, (int)$cellH);







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







        // No explicit height — anchor from bottom edge
        // CSS Grid spec: auto-height grid container computes height from content.



        // Note: avoid max() returning php::Variant (C2440 in native_types)



        $hasExplicitH = array_key_exists('height', $style) && $style['height'] !== 'auto' && $style['height'] !== '';



        $hasHPct = array_key_exists('heightPercent', $style);



        if (!$hasExplicitH && !$hasHPct) {



            $maxBottom = (int)$node->y;



            foreach ($children as $ch) {



                $chBottom = (int)($ch->y + $ch->h);



                if ($chBottom > $maxBottom) $maxBottom = $chBottom;



            }



            $contentH = (int)($maxBottom - $node->y);



            if ($contentH > $node->h) {



                $computedH = (int)$this->applyMinMax($style, $contentH, false);



                if ($computedH > $node->h) {



                    $node->h = $computedH;



                }



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



      * 解析百分比尺寸并计算
     * 如果 percentKey 存在（如 'widthPercent'），从 parentSize 计算实际像素值。
     * 否则回退到 pixel key（如 'width'）。
     */



    private function resolvePercent(array $style, string $key, string $percentKey, int $parentSize): int



    {



        $pct = $style[$percentKey] ?? null;



        if ($pct !== null && $parentSize > 0) {



            return (int)($parentSize * $pct / 100.0);



        }



        $raw = $style[$key] ?? null;



        if ($raw === null || $raw === 'auto' || $raw === '' || is_string($raw)) {



            return 0;



        }



        return (int)$raw;



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



     * 解析 margin:auto 水平居中及垂直居中。
     * CSS 规范 10.6.2: margin-top/bottom:auto 在 normal flow 中使用值 0。
     * CSS 规范 10.6.4: 绝对定位元素 top+bottom+height 非 auto 时，
     *                 auto margin 吸收剩余空间均分（垂直居中）。
     * 算法: 剩余空间 = (父 content size - 子 size) / 2，各分一半。
     * AOT 兼容: 纯属性访问 + while 循环，符合 native_types。
     */



    private function resolveMarginAuto(RenderNode $node, array $style, int $parentContentW, int $parentContentH = 0): void



    {



        // Fallback: if raw 'margin'=>'auto' is set but flags aren't parsed (direct style array), treat all as auto
        $marginIsAuto = ($style['margin'] ?? '') === 'auto';

        $isMarginLeftAuto = $style['marginLeftAuto'] ?? $marginIsAuto;



        $isMarginRightAuto = $style['marginRightAuto'] ?? $marginIsAuto;







        if ($isMarginLeftAuto && $isMarginRightAuto && $node->w > 0 && $parentContentW > $node->w) {



            $remaining = $parentContentW - $node->w;



            $half = (int)($remaining / 2);



            $node->x += $half;



        } elseif ($isMarginLeftAuto && !$isMarginRightAuto && $parentContentW > $node->w) {



            $remaining = $parentContentW - $node->w;



            $node->x += $remaining;



        }







        // Vertical auto margins: only when both auto (centering)



        $isMarginTopAuto = $style['marginTopAuto'] ?? $marginIsAuto;



        $isMarginBottomAuto = $style['marginBottomAuto'] ?? $marginIsAuto;



        if ($isMarginTopAuto && $isMarginBottomAuto && $node->h > 0 && $parentContentH > $node->h) {



            $remaining = $parentContentH - $node->h;



            $half = (int)($remaining / 2);



            $node->y += $half;



        }



    }



    



    // ── 递归平移方法（用于 auto-stack / clamp） ──




    /**



     * Recursively shift Y coordinate of a node and all its descendants.



     */



    private function shiftDescendantsY(RenderNode $node, int $dy): void



    {



        $node->y += $dy;



        // CRITICAL: Do NOT shift $child->y here AND in the recursive call.



        // The recursive call shiftDescendantsY($child, $dy) already increments



        // the child's y on its first line ($node->y += $dy). Shifting here



        // would DOUBLE the offset for the child (the "CategoryTabs y-doubling" bug).



        foreach ($node->children as $child) {



            $this->shiftDescendantsY($child, $dy);



        }



    }







    /**



     * Recursively shift X coordinate of a node and all its descendants.



     */



    private function shiftDescendantsX(RenderNode $node, int $dx): void



    {



        $node->x += $dx;



        // Same fix as shiftDescendantsY: recursive call already shifts children.



        foreach ($node->children as $child) {



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



        $containerW = max($node->w - $paddingLeft - $paddingRight, 0);



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



        // ── contentHeight: total scrollable content height (CSS scrollHeight) ──
        // Must account for paddingTop: scrollHeight = children height + vertical padding.
        // Using (node.y - scrollTop) instead of childOffsetY ensures padding is included.
        $node->contentHeight = $maxBottom - ($node->y - $node->scrollTop);

        // ── Clamp scrollTop when content shrinks ────

        $maxScroll = max($node->contentHeight - $node->h, 0);



        if ($node->scrollTop > $maxScroll) {

            $oldScrollTop = $node->scrollTop;

            $node->scrollTop = $maxScroll;

            $shiftDown = $oldScrollTop - $node->scrollTop;

            if ($shiftDown > 0) {

                foreach ($node->children as $child) {
                    // shiftDescendantsY adds $dy to the node AND all its descendants.
                    // Do NOT also do $child->y += $shiftDown here (would double-apply).
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
                        // shiftDescendantsX adds $dx to the node AND all its descendants.
                        // Do NOT also do $child->x += $shiftRight here (double-apply).
                        $this->shiftDescendantsX($child, $shiftRight);



                    }



                }



            }



        } else {
            // No horizontal scroll — content width equals container width
            $node->contentWidth = $node->w;
        }



    }



}



