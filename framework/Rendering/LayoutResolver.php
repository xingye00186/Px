<?php


namespace Px\Rendering;


use native_types;

use Px\Core\Config;
use Px\Rendering\Layout\AbsolutePositioning;
use Px\Rendering\Layout\LayoutStrategyInterface;
use Px\Rendering\Layout\BlockLayoutStrategy;
use Px\Rendering\Layout\FlexLayoutStrategy;
use Px\Rendering\Layout\GridLayoutStrategy;
use Px\Rendering\Layout\PercentResolver;
use Px\Rendering\Layout\ScrollHelper;


/**
 * LayoutResolver 鈥?杩愯�鏃?CSS 甯冨眬寮曟搸锛圧enderNode 鐗堬級
 *
 * 閬嶅巻 RenderNode 鏍戯紝鏍规嵁 style 灞炴€ц�绠楁瘡涓�妭鐐圭殑 x/y/w/h 浣嶇疆銆?
 * 鑷?B1 閲嶆瀯鍚庝负璋冨害鍣�紝鎸?display 绫诲瀷鍒嗘淳鍒扮浉搴旂瓥鐣ョ被锛?
 * - display:flex/inline-flex 鈫?FlexLayoutStrategy
 * - display:grid 鈫?GridLayoutStrategy
 * - display:block 鍙婂叾浠?鈫?BlockLayoutStrategy
 *
 * resolveNode 淇濈暀涓烘牳蹇冭皟搴﹀叆鍙ｏ紝璐熻矗锛?
 * 1. 鑴忔爣璁版�鏌ヤ笌鍔ㄧ敾鏍峰紡鍚堝苟
 * 2. Layer 缁ф壙
 * 3. 婊氬姩瀹瑰櫒妫€娴?
 * 4. Flex/grid 婊氬姩瀹瑰櫒鍚庡�鐞?
 * 5. Sticky 瀹氫綅
 * 6. 娲佸噣璺�緞鍧愭爣浼犳挱
 */
class LayoutResolver


{

    private int $resolveDepth = 0;

    private ?RenderNode $rootNode = null;

    private AbsolutePositioning $absolutePositioning;
    private LayoutStrategyInterface $blockStrategy;
    private LayoutStrategyInterface $flexStrategy;
    private LayoutStrategyInterface $gridStrategy;

    /** @var array<string, array> Per-scroll-container sticky stack (vertical) */
    private array $stickyStack = [];

    /** @var array<string, array> Per-scroll-container sticky stack (horizontal) */
    private array $stickyStackX = [];


    public function __construct()
    {
        $this->absolutePositioning = new AbsolutePositioning($this);
        $this->blockStrategy = new BlockLayoutStrategy($this);
        $this->flexStrategy = new FlexLayoutStrategy($this);
        $this->gridStrategy = new GridLayoutStrategy($this);
    }

    public function getAbsolutePositioning(): AbsolutePositioning
    {
        return $this->absolutePositioning;
    }

    public function getBlockStrategy(): BlockLayoutStrategy
    {
        return $this->blockStrategy;
    }

    public function getFlexStrategy(): FlexLayoutStrategy
    {
        return $this->flexStrategy;
    }

    public function getGridStrategy(): GridLayoutStrategy
    {
        return $this->gridStrategy;
    }

    public function getRootNode(): ?RenderNode
    {
        return $this->rootNode;
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


        $this->resolveNode($root, 0, 0, null, refval($scrollContainers));


        // Debug: final span dimensions after full layout (guarded by diag_enabled)
        if (Config::get('diag_enabled', false)) {
            $this->debugCheckSpanDims($root);
        }


        return ['scrollContainers' => $scrollContainers];


    }

    // Debug: check span dimensions after full layout
    private function debugCheckSpanDims(RenderNode $node): void
    {
        // Debug removed
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
    public function resolveNode(


        RenderNode  $node,


        int         $parentX,


        int         $parentY,


        ?RenderNode $parent,


        array       &$scrollContainers


    ): void
    {
        $this->resolveDepth++;
        if ($this->resolveDepth > 500) {
            error_log('[DIAG_LAYOUT] INFINITE RECURSION? depth=' . $this->resolveDepth . ' type=' . $node->type . ' x=' . $node->x . ' y=' . $node->y . ' w=' . $node->w . ' h=' . $node->h . ' layoutDirty=' . ($node->layoutDirty ? '1' : '0'));
            if ($this->resolveDepth > 520) {
                error_log('[DIAG_LAYOUT] HALTING - depth exceeded 520');
                $this->resolveDepth--;
                return;
            }
        }

        if ($node->layoutDirty) {


            // 鈹€鈹€ 鑴忔爣璁版�鏌ワ細杩涘叆瀹屾暣甯冨眬璁＄畻 鈹€鈹€
            // 缁熶竴鍏ュ彛锛氬湪 style 瑙ｆ瀽澶勫悎骞?animatedStyle
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
                $scrollContainers[] = $node;

            }


            // Determine display mode


            $display = $effectiveStyle['display'] ?? 'block';


            $position = $effectiveStyle['position'] ?? 'static';



            switch ($display) {


                case 'flex':


                case 'inline-flex':

                    $this->flexStrategy->resolve($node, $parentX, $parentY, $parent, refval($scrollContainers), $effectiveStyle);

                    break;


                case 'grid':


                    $this->gridStrategy->resolve($node, $parentX, $parentY, $parent, refval($scrollContainers), $effectiveStyle);

                    break;


                default: // block, scroll-container, etc.

                    $this->blockStrategy->resolve($node, $parentX, $parentY, $parent, refval($scrollContainers), $effectiveStyle);

                    break;


            }




            // 鈹€鈹€ Scroll container post-processing for flex/grid display modes 鈹€鈹€

            // (block layout handles this internally in resolveBlockLayout)


            if ($node->isScrollContainer && ($display === 'flex' || $display === 'inline-flex' || $display === 'grid')) {


                $padT = (int)($effectiveStyle['paddingTop'] ?? $effectiveStyle['padding'] ?? 0);


                $padL = (int)($effectiveStyle['paddingLeft'] ?? $effectiveStyle['padding'] ?? 0);


                $padR = (int)($effectiveStyle['paddingRight'] ?? $effectiveStyle['padding'] ?? 0);


                $padB = (int)($effectiveStyle['paddingBottom'] ?? $effectiveStyle['padding'] ?? 0);


                $childBaseY = $node->y + $padT;


                // Calculate contentHeight: max bottom edge of all children


                $maxBottom = $childBaseY;


                foreach ($node->children as $child) {


                    $bottom = (int)($child->y + $child->h);


                    if ($bottom > $maxBottom) $maxBottom = $bottom;


                }


                $node->contentHeight = (int)max(0, $maxBottom - $childBaseY);


                // Clamp scrollTop when content shrinks


                $maxScroll = (int)max($node->contentHeight - $node->h, 0);


                if ($node->scrollTop > $maxScroll) {


                    $node->scrollTop = $maxScroll;


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
                    $node->contentWidth = (int)max($maxRight, $node->w);


                    $maxScrollX = (int)max($node->contentWidth - $node->w, 0);

                    if ($node->scrollLeft > $maxScrollX) {

                        $node->scrollLeft = $maxScrollX;

                    }

                } else {
                    // No horizontal scroll 鈥?content width equals container width
                    $node->contentWidth = $node->w;
                }


            }


            // 鈹€鈹€ position:sticky 澶勭悊锛圕SS 搂4.3 鍫嗗彔 + A1 visual 鍧愭爣瀵归綈锛夆攢鈹€
            if ($position === 'sticky') {

                $stickyTop = (int)($effectiveStyle['top'] ?? 0);

                // Save base Y for stacking calculations
                $node->style['_stickyBaseY'] = $node->y;

                // Find nearest scroll container that contains this node
                for ($i = count($scrollContainers) - 1; $i >= 0; $i--) {
                    $sc = $scrollContainers[$i];

                    // Check if node is within this scroll container's bounds
                    if ($node->x >= $sc->x && $node->x < $sc->x + $sc->w &&
                        $node->y >= $sc->y && $node->y < $sc->y + $sc->h) {

                        $scKey = $sc->groupId . ':' . $i;

                        // 鈹€鈹€ Vertical sticky (top) with stacking 鈹€鈹€
                        $visualY = $node->y - $sc->scrollTop;

                        if (!isset($this->stickyStack[$scKey])) {
                            $this->stickyStack[$scKey] = [];
                        }

                        // Adjust stuckY for previous sticky elements in this container
                        $baseStuckY = $sc->y + $stickyTop;
                        $adjustedStuckY = $baseStuckY;
                        foreach ($this->stickyStack[$scKey] as $prev) {
                            $adjustedStuckY = (int)max($adjustedStuckY, $prev['stuckY'] + $prev['height']);
                        }

                        if ($visualY < $adjustedStuckY) {
                            // Element scrolls above sticky threshold 鈫?clamp
                            $dy = $adjustedStuckY - $visualY;
                            $node->y = $adjustedStuckY + $sc->scrollTop;

                            // Shift descendants to maintain layout integrity
                            foreach ($node->children as $child) {
                                ScrollHelper::shiftDescendantsY($child, $dy);
                            }

                            // Register in sticky stack for subsequent elements
                            $this->stickyStack[$scKey][] = [
                                'stuckY' => $adjustedStuckY,
                                'height' => $node->h,
                            ];
                        }

                        // 鈹€鈹€ Horizontal sticky (left) with stacking 鈹€鈹€
                        $stickyLeft = (int)($effectiveStyle['left'] ?? 0);
                        if ($stickyLeft !== 0) {
                            $visualX = $node->x - $sc->scrollLeft;

                            if (!isset($this->stickyStackX[$scKey])) {
                                $this->stickyStackX[$scKey] = [];
                            }

                            $baseStuckX = $sc->x + $stickyLeft;
                            $adjustedStuckX = $baseStuckX;
                            foreach ($this->stickyStackX[$scKey] as $prev) {
                                $adjustedStuckX = (int)max($adjustedStuckX, $prev['stuckX'] + $prev['width']);
                            }

                            if ($visualX < $adjustedStuckX) {
                                $dx = $adjustedStuckX - $visualX;
                                $node->x = $adjustedStuckX + $sc->scrollLeft;
                                foreach ($node->children as $child) {
                                    ScrollHelper::shiftDescendantsX($child, $dx);
                                }

                                $this->stickyStackX[$scKey][] = [
                                    'stuckX' => $adjustedStuckX,
                                    'width' => $node->w,
                                ];
                            }
                        }

                        break;
                    }
                }
            }


            // 鈹€鈹€ 娓呴櫎鑴忔爣璁帮細甯冨眬瀹屾垚鍚庢爣璁颁负娲佸噣 鈹€鈹€
            $node->layoutDirty = false;


        } else {


            // 鈹€鈹€ Clean path: not layoutDirty, just propagate parent coords 鈹€鈹€


            $style = $node->style;


            $marginLeft = (int)($style['marginLeft'] ?? $style['margin'] ?? 0);


            $marginTop = (int)($style['marginTop'] ?? $style['margin'] ?? 0);


            // For static flex/grid items, their positions are determined by the parent's
            // layout algorithm (flex/grid), not by 'left'/'top' style values.


            $cleanPos = $style['position'] ?? 'static';


            if ($cleanPos !== 'static') {


                if (array_key_exists('left', $style)) {


                    $node->x = (int)($style['left'] + $parentX + $marginLeft);


                }


                if (array_key_exists('top', $style)) {


                    $node->y = (int)($style['top'] + $parentY + $marginTop);


                }


            }


            // 鈹€鈹€ 婊氬姩鍋忕Щ鐢?VNodeRenderer 鍦ㄧ粯鍒跺眰澶勭悊锛圓1 閲嶆瀯锛夆攢鈹€


            // 鈹€鈹€ 瀛愯妭鐐硅剰鏍囪�澶勭悊 鈹€鈹€
            // Flex/grid container with dirty children: re-run full layout


            $display = $style['display'] ?? 'block';


            if (($display === 'flex' || $display === 'grid') && !empty($node->children)) {


                foreach ($node->children as $ch) {


                    if ($ch->layoutDirty) {


                        $node->layoutDirty = true;


                        $this->resolveNode($node, $parentX, $parentY, $parent, refval($scrollContainers));


                        $this->resolveDepth--;


                        return;


                    }


                }


            }


            $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);


            $paddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);


            $childOffsetX = $node->x + $paddingLeft;


            $childOffsetY = $node->y + $paddingTop;


            foreach ($node->children as $child) {


                $this->resolveNode($child, $childOffsetX, $childOffsetY, $node, refval($scrollContainers));


            }


        }


        $this->resolveDepth--;


    }


}
