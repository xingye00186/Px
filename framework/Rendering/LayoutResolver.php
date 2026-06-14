<?php


namespace Px\Rendering;

use native_types;

use Px\Core\Config;
use Px\Rendering\Layout\AbsolutePositioning;
use Px\Rendering\Layout\AbsoluteStrategy;
use Px\Rendering\Layout\LayoutStrategyInterface;
use Px\Rendering\Layout\BlockLayoutStrategy;
use Px\Rendering\Layout\FlexLayoutStrategy;
use Px\Rendering\Layout\GridLayoutStrategy;
use Px\Rendering\Layout\InlineLayoutStrategy;
use Px\Rendering\Layout\TableLayoutStrategy;
use Px\Rendering\Layout\MultiColumnLayoutStrategy;
use Px\Rendering\Layout\Tools\PercentResolver;
use Px\Rendering\Layout\Tools\ScrollHelper;
use Px\Rendering\Layout\LayoutContext;


/**
 * LayoutResolver — 运行时 CSS 布局引擎（RenderNode 版）
 *
 * 遍历 RenderNode 树，根据 style 属性计算每个节点的 x/y/w/h 位置。
 * 自 B1 重构后为调度器，按 display 类型分派到相应策略类，
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
    private int $resolveDepth = 0;
    private ?RenderNode $rootNode = null;

    private AbsoluteStrategy $absolutePositioning;
    private LayoutStrategyInterface $blockStrategy;
    private LayoutStrategyInterface $flexStrategy;
    private LayoutStrategyInterface $gridStrategy;
    private LayoutStrategyInterface $inlineStrategy;
    private LayoutStrategyInterface $tableStrategy;
    private LayoutStrategyInterface $multiColumnStrategy;

    /** @var array<string, array> Per-scroll-container sticky stack (vertical) */
    private array $stickyStack = [];

    /** @var array<string, array> Per-scroll-container sticky stack (horizontal) */
    private array $stickyStackX = [];

    /** 滚动容器收集数组（布局过程按需追加） */
    private array $scrollContainers = [];


    public function __construct()
    {
        $this->absolutePositioning = new AbsolutePositioning($this);
        $this->blockStrategy = new BlockLayoutStrategy($this);
        $this->flexStrategy = new FlexLayoutStrategy($this);
        $this->gridStrategy = new GridLayoutStrategy($this);
        $this->inlineStrategy = new InlineLayoutStrategy($this);
        $this->tableStrategy = new TableLayoutStrategy($this);
        $this->multiColumnStrategy = new MultiColumnLayoutStrategy($this);
    }

    public function getAbsolutePositioning(): AbsoluteStrategy
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

    public function getInlineStrategy(): InlineLayoutStrategy
    {
        return $this->inlineStrategy;
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
        $this->scrollContainers = [];

        $ctx = new LayoutContext(0, 0, null);
        $this->resolveNode($root, $ctx);

        // Debug: final span dimensions after full layout (guarded by diag_enabled)
        if (Config::get('diag_enabled', false)) {
            $this->debugCheckSpanDims($root);
        }

        return ['scrollContainers' => $this->scrollContainers];

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
     * @param LayoutContext $ctx Layout context (parent coords, parent ref, scroll containers)
     */
    public function resolveNode(
        RenderNode $node,
        LayoutContext $ctx
    ): void {
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

            // ──┬── 脏标记检查：进入完整布局计算 ──┬──
            // 统一入口：在 style 解析处合并 animatedStyle
            $style = $node->style;

            if ($node->isAnimating && ! empty($node->animatedStyle)) {

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

            if ($ctx->parent !== null && $ctx->parent->layer > 0) {

                $node->layer = $ctx->parent->layer;
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
                $this->scrollContainers[] = $node;
            }

            // Determine display mode

            $display = $effectiveStyle['display'] ?? 'block';
            $position = $effectiveStyle['position'] ?? 'static';

            switch ($display) {
                case 'none':
                    // CSS 2.2 §9.2.4: display:none → element generates no box
                    // No layout needed, set dimensions to 0 and skip children
                    $node->w = 0;
                    $node->h = 0;
                    $node->visualW = 0;
                    $node->visualH = 0;
                    // Mark layout as resolved for parent auto-stack calculation
                    break;

                case 'flex':
                case 'inline-flex':
                    $this->flexStrategy->resolve($node, $ctx, $effectiveStyle);
                    break;

                case 'grid':
                    $this->gridStrategy->resolve($node, $ctx, $effectiveStyle);
                    break;

                case 'inline':
                case 'inline-block':
                    if ($position === 'absolute' || $position === 'fixed') {
                        $this->absolutePositioning->resolveAbsolutePositioning($node, $ctx, $effectiveStyle);
                    } else {
                        $this->inlineStrategy->resolve($node, $ctx, $effectiveStyle);
                    }
                    break;

                case 'table':
                case 'table-row':
                case 'table-cell':
                case 'table-caption':
                    if ($position === 'absolute' || $position === 'fixed') {
                        $this->absolutePositioning->resolveAbsolutePositioning($node, $ctx, $effectiveStyle);
                    } else {
                        $this->tableStrategy->resolve($node, $ctx, $effectiveStyle);
                    }
                    break;

                default: // block, scroll-container, etc.
                    // Check for multi-column layout
                    if (($effectiveStyle['columnCount'] ?? 0) > 0 || ($effectiveStyle['columnWidth'] ?? 0) > 0) {
                        $this->multiColumnStrategy->resolve($node, $ctx, $effectiveStyle);
                    } elseif ($position === 'absolute' || $position === 'fixed') {
                        $this->absolutePositioning->resolveAbsolutePositioning($node, $ctx, $effectiveStyle);
                    } else {
                        $this->blockStrategy->resolve($node, $ctx, $effectiveStyle);
                    }
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

                    $bottom = (int)($child->y + $child->visualH);

                    if ($bottom > $maxBottom) {
                        $maxBottom = $bottom;
                    }
                }

                // CSS Overflow: scrollable content area includes paddingBottom
                $node->contentHeight = (int)max(0, $maxBottom - $childBaseY) + $padB;

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
                        $right = (int)($cLeft + $child->visualW);

                        if ($right > $maxRight) {
                            $maxRight = $right;
                        }
                    }
                    $node->contentWidth = (int)max($maxRight, $node->visualW);

                    $maxScrollX = (int)max($node->contentWidth - $node->w, 0);

                    if ($node->scrollLeft > $maxScrollX) {

                        $node->scrollLeft = $maxScrollX;
                    }

                } else {
                    // No horizontal scroll — content width equals container width
                    $node->contentWidth = $node->visualW;
                }
            }


            // ── position:sticky 处理（CSS §4.3 堆叠 + A1 visual 坐标对齐）──
            if ($position === 'sticky') {

                $stickyTop = (int)($effectiveStyle['top'] ?? 0);

                // Save base Y for stacking calculations
                $node->style['_stickyBaseY'] = $node->y;

                // Find nearest scroll container that contains this node
                for ($i = count($this->scrollContainers) - 1; $i >= 0; $i--) {
                    $sc = $this->scrollContainers[$i];

                    // Check if node is within this scroll container's bounds
                    if ($node->x >= $sc->x && $node->x < $sc->x + $sc->w &&
                        $node->y >= $sc->y && $node->y < $sc->y + $sc->h) {

                        $scKey = $sc->groupId . ':' . $i;

                        // ── Vertical sticky (top) with stacking ──
                        $visualY = $node->y - $sc->scrollTop;

                        if ( ! isset($this->stickyStack[$scKey])) {
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

                        // ── Horizontal sticky (left) with stacking ──
                        $stickyLeft = (int)($effectiveStyle['left'] ?? 0);
                        if ($stickyLeft !== 0) {
                            $visualX = $node->x - $sc->scrollLeft;

                            if ( ! isset($this->stickyStackX[$scKey])) {
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

            $cbWidth = $ctx->parent ? PercentResolver::resolveContentWidth($ctx->parent->style, $ctx->parent->w) : 0;
            $marginLeft = PercentResolver::resolveMarginPaddingPercent($style, 'marginLeft', 'marginLeftPercent',
                $cbWidth);

            $marginTop = PercentResolver::resolveMarginPaddingPercent($style, 'marginTop', 'marginTopPercent',
                $cbWidth);

            // For static flex/grid items, their positions are determined by the parent's
            // layout algorithm (flex/grid), not by 'left'/'top' style values.

            $cleanPos = $style['position'] ?? 'static';

            if ($cleanPos !== 'static') {
                if (array_key_exists('left', $style)) {
                    $node->x = (int)($style['left'] + $ctx->parentX + $marginLeft);
                }

                if (array_key_exists('top', $style)) {

                    $node->y = (int)($style['top'] + $ctx->parentY + $marginTop);
                }
            }

            // ──┬── 滚动偏移由 VNodeRenderer 在绘制层处理（A1 重构）──┬──

            // ──┬── 子节点脏标记处理 ──┬──
            // Flex/grid container with dirty children: re-run full layout

            $display = $style['display'] ?? 'block';

            // CSS 2.2 §10.5: auto-height 的块级容器遇到脏子节点需重算
            $hasExplicitH = array_key_exists('height', $style) || array_key_exists('heightPercent', $style);

            if (! empty($node->children)) {
                $needsReLayout = false;
                if ($display === 'flex' || $display === 'grid') {
                    $needsReLayout = true;
                } elseif ($display === 'block' && !$hasExplicitH) {
                    $needsReLayout = true;
                }

                if ($needsReLayout) {
                    foreach ($node->children as $ch) {
                        if ($ch->layoutDirty) {
                            $node->layoutDirty = true;
                            $this->resolveNode($node, $ctx);
                            $this->resolveDepth--;

                            return;
                        }
                    }
                }
            }


            $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
            $paddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
            $childOffsetX = $node->x + $paddingLeft;
            $childOffsetY = $node->y + $paddingTop;
            foreach ($node->children as $child) {
                $childCtx = new LayoutContext($childOffsetX, $childOffsetY, $node);
                $this->resolveNode($child, $childCtx);
            }
        }

        $this->resolveDepth--;
    }


}
