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
use Px\Rendering\CssStyleHelper;
use Px\Rendering\Layout\LayoutConstraints;
use Px\Rendering\Layout\LayoutFragment;
use Px\Rendering\Layout\FragmentBuilder;


/**
 * LayoutResolver — 运行时 CSS 布局引擎（RenderNode 版）
 *
 * Phase 3: 使用 FragmentBuilder 的新流程。
 *
 * 流程：
 *   resolve(RenderNode) → 创建 LayoutConstraints → resolveNodeInternal()
 *   resolveNodeInternal() 负责递归：
 *     1. 读取 computedStyle
 *     2. 创建 FragmentBuilder
 *     3. 按 display/position 选择策略
 *     4. 新策略：先 resolveChildren() 再调用策略
 *     5. build() → LayoutFragment → applyTo()
 *     6. 后处理（滚动容器、sticky 等）
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
     * Phase 3: 创建初始 LayoutConstraints，进入 resolveNodeInternal 新流程。
     *
     * @param RenderNode $root Root RenderNode (mutated in-place via applyTo)
     * @return LayoutFragment 根 fragment
     */
    public function resolve(RenderNode $root): LayoutFragment
    {
        $this->rootNode = $root;
        $this->scrollContainers = [];
        $this->stickyStack = [];
        $this->stickyStackX = [];

        $constraints = new LayoutConstraints(
            containerWidth: $root->w,
            containerHeight: $root->h,
            parentContentX: 0,
            parentContentY: 0,
        );

        $rootFragment = $this->resolveNodeInternal($root, $constraints);
        $rootFragment->applyTo($root);

        // Debug: final span dimensions after full layout
        if (Config::get('debug_diag_enabled', false)) {
            $this->debugCheckSpanDims($root);
        }

        return $rootFragment;
    }

    /**
     * 供 FlexLayoutStrategy/GridLayoutStrategy 内部算法体使用的子节点解析入口。
     * 替代 resolveNode() 方法，直接使用坐标参数。
     */
    public function resolveChildNode(RenderNode $child, int $parentX, int $parentY, ?RenderNode $parentNode): void
    {
        $parentW = $parentNode !== null ? $parentNode->w : $child->w;
        $parentH = $parentNode !== null ? $parentNode->h : $child->h;
        $constraints = new LayoutConstraints(
            containerWidth: $parentW,
            containerHeight: $parentH,
            parentContentX: $parentX,
            parentContentY: $parentY,
            contentWidth: $parentW,
            contentHeight: $parentH,
        );
        $fragment = $this->resolveNodeInternal($child, $constraints);
        $fragment->applyTo($child);
    }

    /**
     * Phase 3 核心递归布局方法。
     *
     * @param RenderNode         $node            当前节点
     * @param LayoutConstraints  $constraints     布局约束
     * @param LayoutFragment|null $parentFragment 父 fragment（用于层继承等）
     * @return LayoutFragment
     */
    private function resolveNodeInternal(
        RenderNode         $node,
        LayoutConstraints  $constraints,
        ?LayoutFragment    $parentFragment = null
    ): LayoutFragment {
        $this->resolveDepth++;
        if ($this->resolveDepth > 500) {
            error_log('[DIAG_LAYOUT] INFINITE RECURSION? depth=' . $this->resolveDepth . ' type=' . $node->type . ' x=' . $node->x . ' y=' . $node->y . ' w=' . $node->w . ' h=' . $node->h . ' layoutDirty=' . ($node->layoutDirty ? '1' : '0'));
            if ($this->resolveDepth > 520) {
                error_log('[DIAG_LAYOUT] HALTING - depth exceeded 520');
                $this->resolveDepth--;
                return (new FragmentBuilder())->build();
            }
        }

        // ── 读取 computedStyle ──
        $style = $node->computedStyle;
        $effectiveStyle = $style !== null ? $style->toExportArray() : [];
        $display = $effectiveStyle['display'] ?? 'block';
        $position = $effectiveStyle['position'] ?? 'static';

        // ── 脏标记检查 ──
        // 非脏节点：直接构建 Fragment 并递归子节点（无需重新布局计算）
        if (!$node->layoutDirty) {
            $builder = new FragmentBuilder();
            $builder
                ->setPosition($node->x, $node->y)
                ->setSize($node->w, $node->h, $style)
                ->setLayer($node->layer)
                ->setContentSize($node->contentWidth, $node->contentHeight);

            // 递归解析子节点（子节点可能脏）
            $this->resolveCurrentChildren($node, $constraints, $builder);

            $this->resolveDepth--;
            return $builder->build($style);
        }

        // ════════════════════════════════════════════════════════════════
        //  脏路径：完整布局计算
        // ════════════════════════════════════════════════════════════════

        // ── Layer 继承 ──
        if ($node->parent !== null && $node->parent->layer > 0) {
            $node->layer = $node->parent->layer;
        }

        // 应用自身 z-index → RenderNode layer
        if ($style !== null && $style->zIndex > $node->layer) {
            $node->layer = $style->zIndex;
        }

        // ── 滚动容器检测 ──
        $overflowX = $style?->overflowX?->value
            ?? $effectiveStyle['overflowX'] ?? $effectiveStyle['overflow'] ?? 'visible';
        $overflowY = $style?->overflowY?->value
            ?? $effectiveStyle['overflowY'] ?? $effectiveStyle['overflow'] ?? 'visible';
        $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll');
        $hasVScroll = ($overflowY === 'auto' || $overflowY === 'scroll');

        if ($hasHScroll || $hasVScroll) {
            $node->isScrollContainer = true;
            $this->scrollContainers[] = $node;
        }

        // ── 创建 FragmentBuilder ──
        $builder = new FragmentBuilder();

        // ── 按 display/position 策略调度 ──
        switch ($display) {
            case 'none':
                // CSS 2.2 §9.2.4: display:none → element generates no box
                $builder->setSize(0, 0);
                break;

            case 'flex':
            case 'inline-flex':
                if ($position === 'absolute' || $position === 'fixed') {
                    // 新 AbsoluteStrategy：先 resolve 子节点，再调用新签名
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->absolutePositioning->resolveAbsolutePositioning(
                        $node, $constraints, $style, $builder
                    );
                } else {
                    // FlexLayoutStrategy 支持新 resolveWithBuilder
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->flexStrategy->resolveWithBuilder(
                        $node, $constraints, $style, $builder
                    );
                }
                break;

            case 'grid':
                $this->resolveChildren($node, $constraints, $builder);
                $this->gridStrategy->resolveWithBuilder(
                    $node, $constraints, $style, $builder
                );
                break;

            case 'inline':
            case 'inline-block':
                if ($position === 'absolute' || $position === 'fixed') {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->absolutePositioning->resolveAbsolutePositioning(
                        $node, $constraints, $style, $builder
                    );
                } else {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->inlineStrategy->resolveWithBuilder(
                        $node, $constraints, $style, $builder
                    );
                }
                break;

            case 'table':
            case 'table-row':
            case 'table-cell':
            case 'table-caption':
                if ($position === 'absolute' || $position === 'fixed') {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->absolutePositioning->resolveAbsolutePositioning(
                        $node, $constraints, $style, $builder
                    );
                } else {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->tableStrategy->resolveWithBuilder(
                        $node, $constraints, $style, $builder
                    );
                }
                break;

            default: // block, scroll-container, etc.
                // 多列布局检测
                $isMultiCol = ($style !== null
                    && ($style->columnCount > 0 || ($style->columnWidth ?? 0) > 0));
                if ($isMultiCol) {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->multiColumnStrategy->resolveWithBuilder(
                        $node, $constraints, $style, $builder
                    );
                } elseif ($position === 'absolute' || $position === 'fixed') {
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->absolutePositioning->resolveAbsolutePositioning(
                        $node, $constraints, $style, $builder
                    );
                } else {
                    // BlockLayoutStrategy 支持新 resolveWithBuilder
                    /** @var BlockLayoutStrategy $blockStrategy */
                    $this->resolveChildren($node, $constraints, $builder);
                    $this->blockStrategy->resolveWithBuilder(
                        $node, $constraints, $style, $builder
                    );
                }
                break;
        }

        // ── 构建 Fragment 并原子回写 RenderNode ──
        $fragment = $builder->build($style);
        $fragment->applyTo($node);

        // ── 滚动容器后处理（flex/grid display 模式） ──
        if ($node->isScrollContainer
            && ($display === 'flex' || $display === 'inline-flex' || $display === 'grid')
        ) {
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

            $node->contentHeight = (int)max(0, $maxBottom - $childBaseY) + $padB;

            // Clamp scrollTop when content shrinks
            $maxScroll = (int)max($node->contentHeight - $node->h, 0);
            if ($node->scrollTop > $maxScroll) {
                $node->scrollTop = $maxScroll;
            }

            // ContentWidth for horizontal scroll
            $overflowX2 = $style?->overflowX?->value
                ?? $effectiveStyle['overflowX'] ?? $effectiveStyle['overflow'] ?? 'visible';
            $hasHScroll2 = ($overflowX2 === 'auto' || $overflowX2 === 'scroll');
            if ($hasHScroll2) {
                $maxRight = 0;
                foreach ($node->children as $child) {
                    $cLeft = $child->computedStyle?->left ?? 0;
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
                $node->contentWidth = $node->visualW;
            }
        }

        // ── position:sticky 处理 ──
        if ($position === 'sticky') {
            $stickyTop = (int)($effectiveStyle['top'] ?? 0);

            // Find nearest scroll container that contains this node
            for ($i = count($this->scrollContainers) - 1; $i >= 0; $i--) {
                $sc = $this->scrollContainers[$i];

                // Check if node is within this scroll container's bounds
                if ($node->x >= $sc->x && $node->x < $sc->x + $sc->w &&
                    $node->y >= $sc->y && $node->y < $sc->y + $sc->h) {

                    $scKey = $sc->groupId . ':' . $i;

                    // ── Vertical sticky (top) with stacking ──
                    $visualY = $node->y - $sc->scrollTop;

                    if (!isset($this->stickyStack[$scKey])) {
                        $this->stickyStack[$scKey] = [];
                    }

                    $baseStuckY = $sc->y + $stickyTop;
                    $adjustedStuckY = $baseStuckY;
                    foreach ($this->stickyStack[$scKey] as $prev) {
                        $adjustedStuckY = (int)max($adjustedStuckY, $prev['stuckY'] + $prev['height']);
                    }

                    if ($visualY < $adjustedStuckY) {
                        $dy = $adjustedStuckY - $visualY;
                        $node->y = $adjustedStuckY + $sc->scrollTop;

                        foreach ($node->children as $child) {
                            $child->y += $dy;
                        }

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
                                $child->x += $dx;
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

        // ── 清除脏标记 ──
        $node->layoutDirty = false;

        // ── 统一 scrollTop/scrollLeft clamp ──
        if ($node->isScrollContainer) {
            $maxScroll = (int)max($node->contentHeight - $node->h, 0);
            if ($node->scrollTop > $maxScroll) {
                $node->scrollTop = $maxScroll;
            }
            $maxScrollX = (int)max($node->contentWidth - $node->w, 0);
            if ($node->scrollLeft > $maxScrollX) {
                $node->scrollLeft = $maxScrollX;
            }
        }

        $this->resolveDepth--;
        return $fragment;
    }


    // ════════════════════════════════════════════════════════════════
    //  辅助方法
    // ════════════════════════════════════════════════════════════════

    /**
     * 新策略模式：预解析子节点。
     *
     * 在调用新签名策略（如 AbsoluteStrategy::resolveAbsolutePositioning）之前，
     * 先递归 resolve 所有子节点，并加入 builder。
     */
    private function resolveChildren(
        RenderNode        $node,
        LayoutConstraints $constraints,
        FragmentBuilder   $builder
    ): void {
        $childOffX = $node->computedStyle?->childOffsetX() ?? 0;
        $childOffY = $node->computedStyle?->childOffsetY() ?? 0;

        foreach ($node->children as $child) {
            $childConstraints = new LayoutConstraints(
                containerWidth: $constraints->contentWidth,
                containerHeight: $constraints->contentHeight,
                parentContentX: $constraints->parentContentX + $childOffX,
                parentContentY: $constraints->parentContentY + $childOffY,
                contentWidth: $constraints->contentWidth,
                contentHeight: $constraints->contentHeight,
            );
            $childFragment = $this->resolveNodeInternal($child, $childConstraints);
            $builder->addChild($childFragment);
        }
    }

    /**
     * 洁净路径：递归解析子节点（无需策略调度，仅传递约束）。
     */
    private function resolveCurrentChildren(
        RenderNode        $node,
        LayoutConstraints $constraints,
        FragmentBuilder   $builder
    ): void {
        $childOffX = $node->computedStyle?->childOffsetX() ?? 0;
        $childOffY = $node->computedStyle?->childOffsetY() ?? 0;

        foreach ($node->children as $child) {
            $childConstraints = new LayoutConstraints(
                containerWidth: $constraints->contentWidth,
                containerHeight: $constraints->contentHeight,
                parentContentX: $constraints->parentContentX + $childOffX,
                parentContentY: $constraints->parentContentY + $childOffY,
                contentWidth: $constraints->contentWidth,
                contentHeight: $constraints->contentHeight,
            );
            $childFragment = $this->resolveNodeInternal($child, $childConstraints);
            $builder->addChild($childFragment);
        }
    }
}
