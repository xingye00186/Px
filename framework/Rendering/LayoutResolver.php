<?php

namespace Px\Rendering;

use native_types;

/**
 * LayoutResolver 鈥?杩愯�鏃?CSS 甯冨眬寮曟搸锛圧enderNode 鐗堬級
 *
 * 閬嶅巻 RenderNode 鏍戯紝鏍规嵁 style 灞炴€ц�绠楁瘡涓�妭鐐圭殑 x/y/w/h 浣嶇疆銆?
 * 鏀�寔鍥涚�甯冨眬妯″紡: block (absolute), flex, grid, scroll銆?
 *
 * 涓庢棫鐗?VNode 鐗堢殑鍏抽敭鍖哄埆锛?
 *   1. 鎺ュ彈 RenderNode 鑰岄潪 VNode锛坰tyle 宸查�璁＄畻锛屾棤闇€璋冪敤 StyleResolver锛?
 *   2. 瀹炵幇鑴忔爣璁版�鏌ワ細layoutDirty=false 鏃惰烦杩囧畬鏁村竷灞€锛屼粎浼犻€掔埗鍧愭爣锛堝惈 margin锛?
 *   3. 闆嗘垚蹇�€熸粴鍔ㄨ矾寰勶細scrollTop 鍙樺寲鏃朵粎骞崇Щ瀛愯妭鐐癸紝涓嶆敼鍙樺�鍣ㄦ湰韬?y
 *   4. 瀛愯妭鐐归亶鍘嗙畝鍖栵紙RenderNode.children 濮嬬粓涓烘暟缁勶級
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
            // 鈹€鈹€ 鑴忚矾寰勶細瀹屾暣甯冨眬璁＄畻 鈹€鈹€
            // 缁熶竴鍏ュ彛锛氬湪 style 瑙ｆ瀽澶勫悎骞?animatedStyle
            // animatedStyle 浼樺厛绾ч珮浜?style锛屼絾涓嶆薄鏌撳師濮?style
            $style = $node->style;
            if ($node->isAnimating && !empty($node->animatedStyle)) {
                // 娣卞害鎷疯礉锛氶伩鍏嶄慨鏀瑰師濮?$node->style
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

            // Apply own z-index 鈫?RenderNode layer
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

            // 鈹€鈹€ Scroll container post-processing for flex/grid display modes 鈹€鈹€
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

            // 鈹€鈹€ position:sticky 澶勭悊 鈹€鈹€
            if ($position === 'sticky') {
                $stickyTop = (int)($effectiveStyle['top'] ?? 0);
                // 淇濆瓨鍘熷� Y (鏈皟鏁村墠)
                $node->style['_stickyBaseY'] = $node->y;

                // 鏌ユ壘鏈€杩戠殑婊氬姩瀹瑰櫒
                for ($i = count($scrollContainers) - 1; $i >= 0; $i--) {
                    $sc = $scrollContainers[$i];
                    // 妫€鏌ヨ妭鐐规槸鍚﹀湪姝ゆ粴鍔ㄥ鍣ㄥ唴
                    if ($node->x >= $sc->x && $node->x < $sc->x + $sc->w &&
                        $node->y >= $sc->y && $node->y < $sc->y + $sc->h) {
                        // 鎭㈠閫昏緫浣嶇疆锛氬姞鍥瀕crollTop
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
            // 鈹€鈹€ 娲佸噣璺�緞锛氫粎浼犻€掔埗鍧愭爣锛堝惈鑷�韩 margin锛?鈹€鈹€
            $style = $node->style;
            $marginLeft = $style['marginLeft'] ?? $style['margin'] ?? 0;
            $marginTop = $style['marginTop'] ?? $style['margin'] ?? 0;
            // 浠呭綋鑺傜偣鏈夋樉寮忓畾浣嶆椂鎵嶉噸绠?x/y锛堝惁鍒欎繚鐣?auto-stack 鎴栧揩閫熸粴鍔ㄨ矾寰勮�瀹氱殑浣嶇疆锛?
            if (array_key_exists('left', $style)) {
                $node->x = $style['left'] + $parentX + $marginLeft;
            }
            if (array_key_exists('top', $style)) {
                $node->y = $style['top'] + $parentY + $marginTop;
            }

            // 鈹€鈹€ 蹇�€熸粴鍔ㄨ矾寰?鈹€鈹€
            // 浠呮粴鍔ㄥ�鍣ㄤ笖 scrollTop 鍙戠敓鍙樺寲鏃舵墽琛?
            if ($node->isScrollContainer && $node->scrollTop !== $node->lastScrollTop) {
                $deltaY = $node->lastScrollTop - $node->scrollTop;
                foreach ($node->children as $child) {
                    $this->shiftChildrenY($child, $deltaY, true);
                }
                $node->lastScrollTop = $node->scrollTop;
            }

            // 鈹€鈹€ 瀛愯妭鐐归€掑綊澶勭悊 鈹€鈹€
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
     * 缁熶竴灏哄�瑙ｆ瀽锛堢櫨鍒嗘瘮 + min/max锛夛紝鐒跺悗鏍规嵁 position 鍒嗗彂鍒?
     * - resolveNormalFlow锛坰tatic/relative锛?
     * - resolveAbsolutePositioning锛坅bsolute/fixed锛?
     * 鏈€鍚庡�鐞?scroll container post-processing + auto-width/height銆?
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

        // 鈹€鈹€ 鐧惧垎姣斿昂瀵歌В鏋?鈹€鈹€
        $parentW = ($parent !== null) ? $parent->w : 0;
        $parentH = ($parent !== null) ? $parent->h : 0;
        $width  = $this->resolvePercent($style, 'width', 'widthPercent', $parentW);
        $height = $this->resolvePercent($style, 'height', 'heightPercent', $parentH);

        // flex:1 宸茬Щ鑷?flex 甯冨眬涓撶敤璺�緞 (Task D)

        // 鈹€鈹€ 搴旂敤 min/max 绾︽潫鍒板昂瀵革紙鍦ㄥ瓙鑺傜偣閫掑綊涔嬪墠锛岀‘淇?parent->w/h 绔嬪嵆鍙�敤锛夆攢鈹€
        $node->w = max(0, (int)$this->applyMinMax($style, $width, true));
        $node->h = max(0, (int)$this->applyMinMax($style, $height, false));

        // 鈹€鈹€ 鏍规嵁 position 鍒嗗彂 鈹€鈹€
        // B.4: position:fixed v1 閫€鍖栦负 absolute锛堝悓鍒嗘敮锛?
        $isAbsolute = ($position === 'absolute' || $position === 'fixed');

        if ($isAbsolute) {
            $this->resolveAbsolutePositioning($node, $parent, $style, $left, $top, $right, $bottom, $width, $height, $scrollContainers);
        } else {
            // static / relative
            $this->resolveNormalFlow($node, $parentX, $parentY, $parent, $position, $style, $left, $top, $scrollContainers);
        }

        // 鈹€鈹€ Scroll container post-processing 鈹€鈹€
        if ($node->isScrollContainer) {
            $paddingTop = $style['paddingTop'] ?? $style['padding'] ?? 0;
            $paddingRight = $style['paddingRight'] ?? $style['padding'] ?? 0;
            $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;
            $childOffsetY = $node->y + $paddingTop - $node->scrollTop;
            $this->finalizeScrollContainer($node, $style, $childOffsetY, $paddingLeft, $paddingRight, $scrollContainers);
        }

        // 鈹€鈹€ Task C: Normal Flow auto-stack for block containers 鈹€鈹€
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

                    // Skip absolute/fixed children 鈥?they don't participate in normal flow
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

                    // Resolve auto margins for horizontal centering/right-alignment (CSS 2.2 §10.3.3)
                    $childML = $childStyle['marginLeftAuto'] ?? false;
                    $childMR = $childStyle['marginRightAuto'] ?? false;
                    if ($childML || $childMR) {
                        $this->resolveMarginAuto($child, $childStyle, $containerW, 0);
                    }

                    // Stack vertically with margin
                    $oldY = $child->y;
                    $child->y = $stackY + $mTop;

                    // position:relative 棰濆�鍋忕Щ锛堜笉鎺ㄨ繘 stack锛?
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

        // 鈹€鈹€ Auto-width/height for block containers (CSS content-based sizing) 鈹€鈹€
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
     * static: 瀹屽叏蹇界暐 left/top/right/bottom锛屼笉鎺ㄨ繘 stack銆?
     * relative: left/top 浣滀负闄勫姞鍋忕Щ閲忥紙涓嶅奖鍝嶅厔寮熻妭鐐圭殑 stack 浣嶇疆锛夈€?
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

        // relative: left/top 浣滀负棰濆�鍋忕Щ锛堜笉鏀瑰彉 stack 鎺ㄨ繘浣嶇疆锛?
        if ($position === 'relative') {
            $node->x += $left;
            $node->y += $top;
        }
        // static: left/top/right/bottom 瀹屽叏蹇界暐

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
     * 浣跨敤 positioningAncestor锛坧osition != static 鐨勬渶杩戠�鍏堬級浣滀负鍙傝€冪郴銆?
     * left/top 鐩稿�瀹氫綅绁栧厛鐨?padding box 鍋忕Щ銆?
     * right/bottom 鏇夸唬锛堝綋 left/top 鏈��鏃讹級銆?
     * position:fixed v1 閫€鍖栦负 absolute锛圱ODO v2: viewport 鍙傝€冪郴锛夈€?
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
        // 鍒ゆ柇瀹氫綅妯″紡锛歠ixed vs absolute
        $pos = $style['position'] ?? 'absolute';
        $isFixed = ($pos === 'fixed');

        if ($isFixed && $this->rootNode !== null) {
            // position:fixed 鈥?浣跨敤鏍硅妭鐐癸紙瑙嗗彛锛変綔涓哄弬鑰冪郴
            // fixed 鍏冪礌鐩稿�浜庤�鍙ｅ畾浣嶏紝涓庢粴鍔ㄦ棤鍏?
            $ancestor = $this->rootNode;
        } else {
            // position:absolute 鈥?鏌ユ壘骞剁紦瀛樺畾浣嶇�鍏?
            $this->resolvePositioningAncestor($node);
            $ancestor = $node->positioningAncestor;
        }

        // 鍙傝€冪郴锛氬畾浣嶇�鍏堢殑 padding box锛岄€€鍖栨椂鐢?(0,0)
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

        // left/top 鐩稿�瀹氫綅绁栧厛鍋忕Щ
        $node->x = $ancestorX + $left + $marginLeft;
        $node->y = $ancestorY + $top + $marginTop;

        // right/bottom 鏇夸唬锛堝綋 width/height 宸茶�鏃剁敤灏哄�鎺ㄧ畻锛屽惁鍒欎粎閿氬畾杈圭紭锛?
        if ($right !== null && $ancestor !== null) {
            if ($width > 0) {
                $node->x = $ancestorX + $ancestorW - $width - $right;
            } else {
                // No explicit width 鈥?anchor from right edge
                $node->x = $ancestorX + $ancestorW - $right;
            }
        }
        if ($bottom !== null && $ancestor !== null) {
            if ($height > 0) {
                $node->y = $ancestorY + $ancestorH - $height - $bottom;
            } else {
                // No explicit height 鈥?anchor from bottom edge
                $node->y = $ancestorY + $ancestorH - $bottom;
            }
        }

        // 鈹€鈹€ margin:auto 姘村钩 + 鍨傜洿灞呬腑 鈹€鈹€
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

        // 鈹€鈹€ 鐧惧垎姣斿昂瀵歌В鏋?鈹€鈹€
        $parentW = ($parent !== null) ? $parent->w : 0;
        $parentH = ($parent !== null) ? $parent->h : 0;
        $width  = $this->resolvePercent($style, 'width', 'widthPercent', $parentW);
        $height = $this->resolvePercent($style, 'height', 'heightPercent', $parentH);

        $node->w = max(0, (int)$width);
        $node->h = max(0, (int)$height);

        // 鈹€鈹€ 鏂瑰悜妫€娴嬶紙鍦ㄧ埗绾у～鍏呭墠锛屼互鍖哄垎涓?浜ゅ弶杞达級鈹€鈹€
        $direction = $style['flexDirection'] ?? 'row';
        $isRow = ($direction === 'row' || $direction === 'row-reverse');

        // Note: Cross-axis fill is handled by the parent's align-items: stretch
        // in Steps 10-11 below. Do NOT fill cross-axis from parent here, as this
        // incorrectly sets the container's dimension when it's a flex item whose
        // cross-axis direction differs from the parent's.
        // Example: a row flex-container child of a column flex-container should
        // NOT have its height filled from parent height 鈥?only width should stretch.

        $gap       = $style['gap'] ?? 0;
        $justify   = $style['justifyContent'] ?? 'flex-start';
        $align     = $style['alignItems'] ?? 'stretch';
        $wrap      = $style['flexWrap'] ?? 'nowrap';

        $reversed = ($direction === 'row-reverse' || $direction === 'column-reverse');

        // 鈹€鈹€ Padding 鈹€鈹€
        $paddingTop    = $style['paddingTop'] ?? $style['padding'] ?? 0;
        $paddingRight  = $style['paddingRight'] ?? $style['padding'] ?? 0;
        $paddingBottom = $style['paddingBottom'] ?? $style['padding'] ?? 0;
        $paddingLeft   = $style['paddingLeft'] ?? $style['padding'] ?? 0;

        $containerMain = max(0, $isRow ? ($width - $paddingLeft - $paddingRight) : ($height - $paddingTop - $paddingBottom));
        $containerCross = max(0, $isRow ? ($height - $paddingTop - $paddingBottom) : ($width - $paddingLeft - $paddingRight));

        // 鈹€鈹€ Step 1: Collect children and resolve 鈹€鈹€
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

        // 鈹€鈹€ Step 2: Order sort (AOT 鍏煎�鐨勫啋娉℃帓搴忥紝绋冲畾鎺掑簭) 鈹€鈹€
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

        // 鈹€鈹€ Step 3: 鏀堕泦 flex item 鍏冩暟鎹?(grow/shrink/basis) 鈹€鈹€
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

        // 鈹€鈹€ Step 3.5: Flex-wrap 鎸夎�鍒嗗壊 鈹€鈹€
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

        // 鈹€鈹€ Per-line flex layout 鈹€鈹€
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
                    // Match by tracking index offset 鈥?simpler: rebuild
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

            // 鈹€鈹€ Step 4: Apply flex-basis 鈹€鈹€
            $this->applyFlexBasis($lineChildren, $lineFlexData, $isRow);

            // 鈹€鈹€ Step 5: Flex-grow 鈹€鈹€
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

            // 鈹€鈹€ Step 6: Calculate line totalMain 鈹€鈹€
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

            // ═══════ Step 7: Flex-shrink ═══════
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

            // 鈹€鈹€ Step 8: Min/max constraints 鈹€鈹€
            foreach ($lineChildren as $ch) {
                $ch->w = max(0, (int)$this->applyMinMax($ch->style, $ch->w, true));
                $ch->h = max(0, (int)$this->applyMinMax($ch->style, $ch->h, false));
            }

            // 鈹€鈹€ Step 9: Recalculate totalMain after shrink 鈹€鈹€
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

            // 鈹€鈹€ Step 9.5: Resolve auto margins in main axis (CSS Flexbox §8.1) 鈹€鈹€
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

            // 鈹€鈹€ Step 10: Justify-content for this line 鈹€鈹€
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

            // 鈹€鈹€ Step 11: Position children in this line 鈹€鈹€
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

            // 鈹€鈹€ Two-pass: re-resolve internal children of sized items 鈹€鈹€
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

        // 鈹€鈹€ Flex container auto-sizing from children (CSS standard) 鈹€鈹€
        // CSS standard: a flex container with auto main-axis size computes it
        // from children (main-axis extension). With auto cross-axis size, it also
        // computes from children (cross-axis extension). The original code only
        // handled main-axis extension (width for rows, height for columns), which
        // is incomplete per CSS spec xA74.5.
        if ($isRow) {
            if (!array_key_exists('width', $style) && !array_key_exists('widthPercent', $style)) {
                $maxRight = $node->x + $paddingLeft;
                foreach ($children as $ch) {
                    $chRight = $ch->x + $ch->w;
                    $mR = $ch->style['marginRight'] ?? $ch->style['margin'] ?? 0;
                    if ($chRight + $mR > $maxRight) $maxRight = (int)($chRight + $mR);
                }
                $node->w = max($node->w, $maxRight - $node->x + $paddingRight);
            }
            // Cross-axis: auto-height from children (CSS xA74.5 missing feature)
            if (!array_key_exists('height', $style) && !array_key_exists('heightPercent', $style)) {
                $maxBottom = $node->y + $paddingTop;
                foreach ($children as $ch) {
                    $chBottom = $ch->y + $ch->h;
                    $mB = $ch->style['marginBottom'] ?? $ch->style['margin'] ?? 0;
                    if ($chBottom + $mB > $maxBottom) $maxBottom = (int)($chBottom + $mB);
                }
                $node->h = max($node->h, $maxBottom - $node->y + $paddingBottom);
            }
        } else {
            // Cross-axis: auto-width from children (CSS xA74.5 missing feature)
            if (!array_key_exists('width', $style) && !array_key_exists('widthPercent', $style)) {
                $maxRight = $node->x + $paddingLeft;
                foreach ($children as $ch) {
                    $chRight = $ch->x + $ch->w;
                    $mR = $ch->style['marginRight'] ?? $ch->style['margin'] ?? 0;
                    if ($chRight + $mR > $maxRight) $maxRight = (int)($chRight + $mR);
                }
                $node->w = max($node->w, $maxRight - $node->x + $paddingRight);
            }
            if (!array_key_exists('height', $style) && !array_key_exists('heightPercent', $style)) {
                $maxBottom = $node->y + $paddingTop;
                foreach ($children as $ch) {
                    $chBottom = $ch->y + $ch->h;
                    $mB = $ch->style['marginBottom'] ?? $ch->style['margin'] ?? 0;
                    if ($chBottom + $mB > $maxBottom) $maxBottom = (int)($chBottom + $mB);
                }
                $node->h = max($node->h, $maxBottom - $node->y + $paddingBottom);
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

        // 鈹€鈹€ 鐧惧垎姣斿昂瀵歌В鏋?鈹€鈹€
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

        // 鈹€鈹€ 搴旂敤 min/max 绾︽潫鍒板�鍣?鈹€鈹€
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

        // 鈹€鈹€ auto-fill/auto-fit: 鏍规嵁瀹瑰櫒瀹藉害鑷�姩璁＄畻鍒楁暟 鈹€鈹€
        if ($colRepeat === 'auto-fill' || $colRepeat === 'auto-fit') {
            $minColW = (int)($colSpec['min'] ?? 245);
            // 璁＄畻鍦ㄥ�鍣ㄥ唴鑳藉绾崇殑鏈€澶у垪鏁�
            $availableW = $node->w - $colGap; // 鍓嶉櫎绗竴鍒楃殑鍓峣ap
            $cols = (int)max(1, floor(($availableW) / ($minColW + $colGap)));
            // 璁＄畻瀹為檯鍗曞厓鏍煎�搴�
            $totalGaps = $colGap * ($cols - 1);
            $cellW = (int)max(0, ($node->w - $totalGaps) / $cols);
        }

        if ($cols === null) {
            $cols = $colSpec['count'] ?? 4;
        }
        if ($cellW === null) {
            $cellW = $colSpec['size'] ?? 80;
        }

        // 1fr 鏀�寔锛氭牴鎹��鍣ㄥ�搴︽寜姣斾緥鍒嗛厤
        if (($colSpec['unit'] ?? '') === 'fr' && $node->w > 0 && $colRepeat !== 'auto-fill' && $colRepeat !== 'auto-fit') {
            $totalGaps = $colGap * ($cols - 1);
            $cellW = (int)max(0, ($node->w - $totalGaps) / $cols);
        }
        $rows = $rowSpec['count'] ?? 5;
        $cellH = $rowSpec['size'] ?? 60;
        // 1fr 鏀�寔锛堣�楂橈級
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

            // 鍩虹�鍗曞厓鏍间綅缃?
            $cellX = $node->x + $col * $cellW + $colGap;
            $cellY = $node->y + $row * $cellH + $rowGap;
            $cellWFinal = max(0, (int)($cellW - $colGap * 2));
            $cellHFinal = max(0, (int)($cellH - $rowGap * 2));

            // 鈹€鈹€ align-self: 鍨傜洿鏂瑰悜瀵归綈 鈹€鈹€
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

            // 鈹€鈹€ justify-self: 姘村钩鏂瑰悜瀵归綈 鈹€鈹€
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

            // 鈹€鈹€ 瀵规瘡涓?grid item 搴旂敤 min/max 绾︽潫 鈹€鈹€
            $ch->w = max(0, (int)$this->applyMinMax($ch->style, $ch->w, true));
            $ch->h = max(0, (int)$this->applyMinMax($ch->style, $ch->h, false));

            $col++;
            if ($col >= $cols) {
                $col = 0;
                $row++;
            }
        }
    }

    // 鈹€鈹€ CSS min/max 绾︽潫杈呭姪鏂规硶 鈹€鈹€

    /**
     * 搴旂敤 CSS min-width/max-width 鎴?min-height/max-height 绾︽潫銆?
     * CSS 瑙勮寖: 濡傛灉 min > max锛屽垯 max 琚�拷鐣ャ€?
     */
    private function applyMinMax(array $style, int $size, bool $isWidth): int
    {
        $min = $isWidth ? (int)($style['minWidth'] ?? 0) : (int)($style['minHeight'] ?? 0);
        $max = $isWidth ? (int)($style['maxWidth'] ?? 0) : (int)($style['maxHeight'] ?? 0);

        // CSS 瑙勮寖: 濡傛灉 min > max锛宮ax 琚�拷鐣?
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
     * 瑙ｆ瀽鐧惧垎姣斿昂瀵稿€笺€?
     * 濡傛灉 percentKey 瀛樺湪锛堝� 'widthPercent'锛夛紝浠?parentSize 璁＄畻瀹為檯鍍忕礌鍊笺€?
     * 鍚﹀垯鍥為€€鍒?pixel key锛堝� 'width'锛夈€?
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
     * 鏌ユ壘骞剁紦瀛樿妭鐐圭殑瀹氫綅绁栧厛锛坧osition != static 鐨勬渶杩戠�鍏堬級銆?
     *
     * 涓?position:absolute/fixed 鎻愪緵 containing block 鍙傝€冪郴銆?
     * 濡傛灉缂撳瓨鏈夋晥锛坧ositioningAncestorValid === true锛夊垯璺宠繃銆?
     * 浠?parent 閾惧悜涓婇亶鍘嗭紝鎵剧�涓€涓?position !== static 鐨勭�鍏堛€?
     * 鎵句笉鍒版椂 positioningAncestor = null锛堥€€鍖栦负鏍硅妭鐐?(0,0) 鍙傝€冪郴锛夈€?
     *
     * AOT 鍏煎�: 绾�睘鎬ц�闂?+ while 寰�幆锛岀�鍚?native_types銆?
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

        // 鎵句笉鍒板畾浣嶇�鍏?鈫?閫€鍖栦负 null锛堟牴鑺傜偣 (0,0) 鍙傝€冪郴锛?
        $node->positioningAncestor = null;
        $node->positioningAncestorValid = true;
    }

    /**
     * 瑙ｆ瀽 margin:auto 姘村钩灞呬腑鍙婂瀭鐩村眳涓�€?
     * CSS 瑙勮寖 10.6.2: margin-top/bottom:auto 鍦?normal flow 涓�娇鐢ㄥ€?0銆?
     * CSS 瑙勮寖 10.6.4: 缁濆�瀹氫綅鍏冪礌 top+bottom+height 闈?auto 鏃讹紝
     *                 auto margin 鍚告敹鍓╀綑绌洪棿鍧囧垎锛堝瀭鐩村眳涓�級銆?
     * 绠楁硶: 鍓╀綑绌洪棿 = (鐖?content size - 瀛?size) / 2锛屽悇鍒嗕竴鍗娿€?
     * AOT 鍏煎�: 绾�畻鏈�搷浣滐紝绗﹀悎 native_types銆?
     */
    private function resolveMarginAuto(RenderNode $node, array $style, int $parentContentW, int $parentContentH = 0): void
    {
        $isMarginLeftAuto = $style['marginLeftAuto'] ?? false;
        $isMarginRightAuto = $style['marginRightAuto'] ?? false;

        if ($isMarginLeftAuto && $isMarginRightAuto && $node->w > 0 && $parentContentW > $node->w) {
            $remaining = $parentContentW - $node->w;
            $half = (int)($remaining / 2);
            $node->x += $half;
        } elseif ($isMarginLeftAuto && !$isMarginRightAuto && $parentContentW > $node->w) {
            $remaining = $parentContentW - $node->w;
            $node->x += $remaining;
        }

        // Vertical auto margins: only when both auto (centering)
        $isMarginTopAuto = $style['marginTopAuto'] ?? false;
        $isMarginBottomAuto = $style['marginBottomAuto'] ?? false;
        if ($isMarginTopAuto && $isMarginBottomAuto && $node->h > 0 && $parentContentH > $node->h) {
            $remaining = $parentContentH - $node->h;
            $half = (int)($remaining / 2);
            $node->y += $half;
        }
    }
    
    // 鈹€鈹€ 閫掑綊骞崇Щ鏂规硶锛堢敤浜? auto-stack / clamp锛?鈹€鈹€

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

    // 鈹€鈹€ 蹇�€熸粴鍔ㄨ矾寰勫钩绉绘柟娉?鈹€鈹€

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
        // 鈹€鈹€ Auto-stack: for scroll containers, position children vertically 鈹€鈹€
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

                // position:relative 棰濆�鍋忕Щ 鈥?鍙�� y 鐢熸晥
                $relTop = $childStyle['top'] ?? 0;
                if (($childStyle['position'] ?? 'static') === 'relative' && $relTop !== 0) {
                    $child->y += $relTop;
                }

                // 浠呭钩绉诲瓙鑺傜偣鐨勫悗浠ｏ紙child 鏈�韩宸插湪涓婃柟琚��纭��缃�綅缃�級
                $dy = $child->y - $oldY;
                if ($dy !== 0) {
                    foreach ($child->children as $grandchild) {
                        $this->shiftDescendantsY($grandchild, $dy);
                    }
                }
                $stackY += $child->h + $mBottom;
            }
        }

        // 鈹€鈹€ Calculate contentHeight (always, not just for autoStack) 鈹€鈹€鈹€鈹€
        $maxBottom = $childOffsetY;
        foreach ($node->children as $child) {
            $bottom = (int)($child->y + $child->h);
            if ($bottom > $maxBottom) $maxBottom = $bottom;
        }
        $node->contentHeight = $maxBottom - $childOffsetY;

        // 鈹€鈹€ Clamp scrollTop when content shrinks 鈹€鈹€鈹€鈹€
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

        // 鈹€鈹€ Content width for horizontal scroll 鈹€鈹€鈹€鈹€
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
