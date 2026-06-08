<?php


namespace Px\Rendering;


use native_types;

use Px\Rendering\Layout\AbsolutePositioning;
use Px\Rendering\Layout\BlockLayoutStrategy;
use Px\Rendering\Layout\FlexLayoutStrategy;
use Px\Rendering\Layout\GridLayoutStrategy;
use Px\Rendering\Layout\PercentResolver;
use Px\Rendering\Layout\ScrollHelper;


/**
 * LayoutResolver — 运行时 CSS 布局引擎（RenderNode 版）
 *
 * 遍历 RenderNode 树，根据 style 属性计算每个节点的 x/y/w/h 位置。
 * 自 B1 重构后为调度器，按 display 类型分派到相应策略类：
 * - display:flex/inline-flex → FlexLayoutStrategy
 * - display:grid → GridLayoutStrategy
 * - display:block 及其他 → BlockLayoutStrategy
 *
 * resolveNode 保留为核心调度入口，负责：
 * 1. 脏标记检查与动画样式合并
 * 2. Layer 继承
 * 3. 滚动容器检测
 * 4. Flex/grid 滚动容器后处理
 * 5. Sticky 定位
 * 6. 洁净路径坐标传播
 */
class LayoutResolver


{


    private ?RenderNode $rootNode = null;

    private AbsolutePositioning $absolutePositioning;
    private BlockLayoutStrategy $blockStrategy;
    private FlexLayoutStrategy $flexStrategy;
    private GridLayoutStrategy $gridStrategy;

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


        // Debug: final span dimensions after full layout
        $this->debugCheckSpanDims($root);


        return ['scrollContainers' => $scrollContainers];


    }

    // Debug: check span dimensions after full layout
    private function debugCheckSpanDims(RenderNode $node): void
    {
        if ($node->type === 'span' && $node->content !== null && is_string($node->content) && strlen($node->content) > 0) {
            if ($node->h !== 18 && $node->h !== 24) {
                file_put_contents('d:\Px\_debug_out.txt', sprintf("DBG_FINAL_SPAN: content='%s' x=%d y=%d w=%d h=%d parent=%s\n", $node->content, $node->x, $node->y, $node->w, $node->h, ($node->parent !== null) ? $node->parent->type : 'null'), FILE_APPEND);
            }
        }
        if (!empty($node->children)) {
            foreach ($node->children as $child) {
                $this->debugCheckSpanDims($child);
            }
        }
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


        if ($node->layoutDirty) {


            // ── 脏标记检查：进入完整布局计算 ──
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
                $scrollContainers[] = $node;

            }


            // Determine display mode


            $display = $effectiveStyle['display'] ?? 'block';


            $position = $effectiveStyle['position'] ?? 'static';


            switch ($display) {


                case 'flex':


                case 'inline-flex':


                    $this->flexStrategy->resolveFlexLayout($node, $parentX, $parentY, $parent, $scrollContainers, $effectiveStyle);


                    break;


                case 'grid':


                    $this->gridStrategy->resolveGridLayout($node, $parentX, $parentY, $parent, $scrollContainers, $effectiveStyle);


                    break;


                default: // block, scroll-container, etc.


                    $this->blockStrategy->resolveBlockLayout($node, $parentX, $parentY, $parent, $position, $scrollContainers, $effectiveStyle);


                    break;


            }


            // ── Scroll container post-processing for flex/grid display modes ──

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
                    // No horizontal scroll — content width equals container width
                    $node->contentWidth = $node->w;
                }


            }


            // ── position:sticky 处理（CSS §4.3 堆叠 + A1 visual 坐标对齐）──
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

                        // ── Vertical sticky (top) with stacking ──
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
                            // Element scrolls above sticky threshold → clamp
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

                        // ── Horizontal sticky (left) with stacking ──
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


            // ── 清除脏标记：布局完成后标记为洁净 ──
            $node->layoutDirty = false;


        } else {


            // ── Clean path: not layoutDirty, just propagate parent coords ──


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


            // ── 滚动偏移由 VNodeRenderer 在绘制层处理（A1 重构）──


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


            $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);


            $paddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);


            $childOffsetX = $node->x + $paddingLeft;


            $childOffsetY = $node->y + $paddingTop;


            foreach ($node->children as $child) {


                $this->resolveNode($child, $childOffsetX, $childOffsetY, $node, $scrollContainers);


            }


        }


    }


}
