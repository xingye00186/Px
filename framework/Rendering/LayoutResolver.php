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
use Px\Rendering\Layout\LayoutConstraints;
use Px\Rendering\Layout\ConstraintSpace;
use Px\Rendering\Layout\ConstraintSpaceBuilder;
use Px\Rendering\Layout\LayoutResult;
use Px\Rendering\Layout\LayoutInput;
use Px\Rendering\Layout\LayoutApplicator;
use Px\Rendering\Layout\StickyPostProcessor;

/**
 * LayoutResolver — 运行时 CSS 布局引擎
 *
 * 三阶段分解：
 *   Phase A — 纯计算：resolveFragment() → LayoutResult（不碰 RenderNode）
 *   Phase B — 回写：applicator.apply(result, node)
 *   Phase C — 后处理：滚动 clamp / sticky
 *
 * 策略均以纯函数接口调用：strategy->layout(LayoutInput): LayoutResult。
 * 无 FragmentBuilder，无 resolveWithBuilder。
 */
class LayoutResolver
{
    private LayoutApplicator $applicator;
    private AbsoluteStrategy $absolutePositioning;
    private LayoutStrategyInterface $blockStrategy;
    private LayoutStrategyInterface $flexStrategy;
    private LayoutStrategyInterface $gridStrategy;
    private LayoutStrategyInterface $inlineStrategy;
    private LayoutStrategyInterface $tableStrategy;
    private LayoutStrategyInterface $multiColumnStrategy;

    /** @var array<string, array> Per-scroll-container sticky stack */
    private array $stickyStack = [];
    private array $stickyStackX = [];
    private array $scrollContainers = [];
    private StickyPostProcessor $stickyProcessor;

    public function __construct()
    {
        $this->applicator = new LayoutApplicator();
        $this->absolutePositioning = new AbsolutePositioning();
        $this->blockStrategy = new BlockLayoutStrategy();
        $this->flexStrategy = new FlexLayoutStrategy();
        $this->gridStrategy = new GridLayoutStrategy();
        $this->inlineStrategy = new InlineLayoutStrategy();
        $this->tableStrategy = new TableLayoutStrategy();
        $this->multiColumnStrategy = new MultiColumnLayoutStrategy();
        $this->stickyProcessor = new StickyPostProcessor();
    }

    /**
     * 布局入口：纯计算 → 回写 → 后处理。
     *
     * @param RenderNode $root 根 RenderNode（原地回写）
     * @return LayoutResult 不可变布局结果
     */
    public function resolve(RenderNode $root): LayoutResult
    {
        $this->scrollContainers = [];
        $this->stickyProcessor->reset();
        $this->stickyStack = [];
        $this->stickyStackX = [];

        $rootStyle = $root->computedStyle;
        $rootW = (int)($root->w ?: ($rootStyle?->width?->toPx() ?: 0));
        $rootH = (int)($root->h ?: ($rootStyle?->height?->toPx() ?: 0));
        $constraints = new ConstraintSpace(
            containerWidth:  $rootW,
            containerHeight: $rootH,
            contentWidth:    $rootW,
            contentHeight:   $rootH,
        );

        // 脏标记判断在入口，不在递归内部
        if (!$root->layoutDirty) {
            // 洁净路径：仅从现有值构造 LayoutResult
            return LayoutResult::fromNode($root);
        }

        // Phase A: 纯计算
        $result = $this->resolveFragment($root, $constraints);

        // Phase B: 回写
        $this->applicator->apply($result, $root);

        // Phase C: 后处理
        $this->postProcess($root, $result);

        $root->layoutDirty = false;

        return $result;
    }

    /**
     * 纯函数递归布局。
     * 不修改任何 RenderNode 字段，只读其 computedStyle/content/children。
     */
    private function resolveFragment(RenderNode $node, ConstraintSpace $space, int $inheritedLayer = 0, ?int $parentResultX = null, ?int $parentResultY = null, int $iteration = 0): LayoutResult
    {
        // DEBUG helper
        $nodeName = $node->type;

        $style = $node->computedStyle;
        $display = $style?->display?->value ?? 'block';
        $position = $style?->position?->value ?? 'static';

        // Detect scroll container
        $overflowX = $style?->overflowX?->value ?? $style?->overflow?->value ?? 'visible';
        $overflowY = $style?->overflowY?->value ?? $style?->overflow?->value ?? 'visible';
        $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll');
        $hasVScroll = ($overflowY === 'auto' || $overflowY === 'scroll');
        if ($hasHScroll || $hasVScroll) {
            $this->scrollContainers[] = $node;
            $node->isScrollContainer = true;
        }

        // Layer inheritance: inherit from parent's computed layer (passed as parameter),
        // override with own z-index.
        $nodeLayer = $inheritedLayer;
        $zIndex = $style?->zIndex ?? 0;
        if ($zIndex > $nodeLayer) {
            $nodeLayer = $zIndex;
        }

        // Recursively resolve children (pure)
        $childResults = [];
        foreach ($node->children as $child) {
            $padL = 0; $padR = 0; $padT = 0; $padB = 0;
            $bL = 0; $bR = 0; $bT = 0; $bB = 0;
            if ($style !== null) {
                $padL = (int)$style->padding->left->toPx();
                $padR = (int)$style->padding->right->toPx();
                $padT = (int)$style->padding->top->toPx();
                $padB = (int)$style->padding->bottom->toPx();
                $bL = (int)$style->borderLeftWidth;
                $bR = (int)$style->borderRightWidth;
                $bT = (int)$style->borderTopWidth;
                $bB = (int)$style->borderBottomWidth;
            }

            // AOT-safe: $node->computedStyle single ?-> check
            $parentDisplay = $node->computedStyle !== null ? $node->computedStyle->display->value : 'block';
            $isFlexGridItem = ($parentDisplay === 'flex' || $parentDisplay === 'inline-flex'
                || $parentDisplay === 'grid' || $parentDisplay === 'inline-grid');
            $nodeW = (int)($node->w);
            if ($nodeW <= 0 && !$isFlexGridItem && $style !== null) {
                $cssW = $style->width->toPx();
                if ($cssW > 0) {
                    $nodeW = $cssW;
                } elseif ($style->width->isPercent()) {
                    // P3 修复：使用 ConstraintSpace.percentageWidth 而非回退到祖父
                    $percW = $space->percentageWidth;
                    if ($percW !== null) {
                        $nodeW = $style->width->resolveInContext($percW);
                    } else {
                        // percentageWidth = null (Indefinite)：标记待定，用 intrinsic
                        $nodeW = 0;
                    }
                }
            }
            $nodeH = $node->h;
            if ($nodeH <= 0 && $style !== null) {
                $cssH = $style->height->toPx();
                if ($cssH > 0) {
                    $nodeH = $cssH;
                } elseif ($style->height->isPercent()) {
                    $percH = $space->percentageHeight;
                    if ($percH !== null) {
                        $nodeH = $style->height->resolveInContext($percH);
                    } else {
                        $nodeH = 0;
                    }
                }
            }

            // AOT-safe: ($nodeW as int) then subtract
            $cbW = 0;
            $cbH = 0;
            $nodeWInt = (int)($nodeW ?? 0);
            $nodeHInt = (int)($nodeH ?? 0);
            if ($nodeWInt > 0) {
                $cbW = max(0, $nodeWInt - (int)($padL ?? 0) - (int)($padR ?? 0) - (int)($bL ?? 0) - (int)($bR ?? 0));
            } else {
                $cbW = max(0, (int)($space->contentWidth ?? 0) - (int)($padL ?? 0) - (int)($padR ?? 0) - (int)($bL ?? 0) - (int)($bR ?? 0));
            }
            if ($nodeHInt > 0) {
                $cbH = max(0, $nodeHInt - (int)($padT ?? 0) - (int)($padB ?? 0) - (int)($bT ?? 0) - (int)($bB ?? 0));
            } else {
                $cbH = max(0, (int)($space->contentHeight ?? 0) - (int)($padT ?? 0) - (int)($padB ?? 0) - (int)($bT ?? 0) - (int)($bB ?? 0));
            }
            // Use ComputedStyle-based x/y for child constraints (not $node->x which is 0 in Phase A)
            $childLeft = 0; $childTop = 0; $childML = 0; $childMT = 0;
            if ($style !== null) {
                $childLeft = (int)$style->left->toPx();
                $childTop = (int)$style->top->toPx();
                $childML = (int)$style->margin->left->toPx();
                $childMT = (int)$style->margin->top->toPx();
            }
            $selfX = (int)($space->parentContentX ?? 0) + (int)($childLeft ?? 0) + (int)($childML ?? 0);
            $selfY = (int)($space->parentContentY ?? 0) + (int)($childTop ?? 0) + (int)($childMT ?? 0);
            $childOffX = (int)($selfX ?? 0) + (int)($padL ?? 0) + (int)($bL ?? 0);
            $childOffY = (int)($selfY ?? 0) + (int)($padT ?? 0) + (int)($bT ?? 0);

            // 子节点百分比基准：使用 ConstraintSpace 的 percentageWidth/Height
            $childPercW = $child->computedStyle?->width?->isPercent() ? $cbW : null;
            $childPercH = $child->computedStyle?->height?->isPercent() ? $cbH : null;

            $childSpace = ConstraintSpace::forChild(
                (int)($childOffX),
                (int)($childOffY),
                (int)max(0, $cbW),
                (int)max(0, $cbH),
                percentageWidth: $childPercW,
                percentageHeight: $childPercH,
            );
            $childResults[] = $this->resolveFragment($child, $childSpace, $nodeLayer, $node->x, $node->y);
        }

        // Build positioning ancestor info for absolute children
        $ancestorX = null;
        $ancestorY = null;
        $ancestorW = null;
        $ancestorH = null;
        $ancestorBL = 0;
        $ancestorBT = 0;
        $ancestorPL = 0;
        $ancestorPT = 0;
        $viewportW = 0;
        $viewportH = 0;

        if ($position === 'absolute' || $position === 'fixed') {
            if ($position === 'fixed') {
                // CSS §9.3: fixed 包含块 = 视口，不查找定位祖先
                $ancestorW = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0;
                $ancestorH = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0;
                $ancestorX = 0;
                $ancestorY = 0;
                $viewportW = $ancestorW;
                $viewportH = $ancestorH;
            } else {
                // absolute: 找最近定位祖先
                $parentNode = $node->parent;
                while ($parentNode !== null) {
                    $pPos = $parentNode->computedStyle?->position?->value ?? 'static';
                    if ($pPos !== 'static') {
                        $pCS = $parentNode->computedStyle;
                        if ($parentNode === $node->parent && $parentResultX !== null) {
                            $ancestorX = $parentResultX;
                            $ancestorY = $parentResultY;
                        } else {
                            $ancestorX = $parentNode->x;
                            $ancestorY = $parentNode->y;
                        }
                        // Use ComputedStyle for parent dimensions (RenderNode.w is 0 during Phase A)
                        $parentW = $pCS?->width?->toPx() ?? $parentNode->w;
                        $parentH = $pCS?->height?->toPx() ?? $parentNode->h;
                        if ($parentW <= 0) $parentW = $parentNode->w;
                        if ($parentH <= 0) $parentH = $parentNode->h;
                        $ancestorW = $parentW;
                        $ancestorH = $parentH;
                        $ancestorBL = $pCS?->borderLeftWidth ?? 0;
                        $ancestorBT = $pCS?->borderTopWidth ?? 0;
                        $ancestorPL = $pCS?->padding?->left->toPx() ?? 0;
                        $ancestorPT = $pCS?->padding?->top->toPx() ?? 0;
                        break;
                    }
                    $parentNode = $parentNode->parent;
                }
            }
        }

        // Select strategy and call layout()
        $textContent = is_string($node->content) ? $node->content : '';
        $isAbsolute = ($position === 'absolute' || $position === 'fixed');

        if ($display === 'none') {
            return $this->wrapWithLayer(new LayoutResult(0, 0, 0, 0, style: $style), $nodeLayer);
        }

        if ($isAbsolute) {
            $input = new LayoutInput(
                constraints: $space->toLegacy(),
                style: $style,
                textContent: $textContent,
                childResults: $childResults,
                childNodes: $node->children,
                layoutCallback: $this,
                iteration: $iteration,
                position: $position,
                ancestorX: $ancestorX,
                ancestorY: $ancestorY,
                ancestorW: $ancestorW,
                ancestorH: $ancestorH,
                ancestorBorderLeft: $ancestorBL,
                ancestorBorderTop: $ancestorBT,
                ancestorPaddingLeft: $ancestorPL,
                ancestorPaddingTop: $ancestorPT,
                viewportW: $viewportW,
                viewportH: $viewportH,
            );
            return $this->wrapWithLayer($this->absolutePositioning->absoluteLayout($input), $nodeLayer);
        }

        // Select strategy based on display
        $strategy = $this->selectStrategy($display, $style);
        $input = new LayoutInput(
            constraints: $space->toLegacy(),
            style: $style,
            textContent: $textContent,
            childResults: $childResults,
            childNodes: $node->children,
            layoutCallback: $this,
            iteration: $iteration,
            position: $position,
        );
        $result = $strategy->layout($input);
        $result = $this->wrapWithLayer($result, $nodeLayer);

        // Iteration loop: if strategy requests another pass and we haven't exceeded max
        if ($result->needsAnotherPass && $iteration < 5) {
            return $this->resolveFragment($node, $space, $inheritedLayer, $parentResultX, $parentResultY, $iteration + 1);
        }

       return $result;
    }

    public function reResolveChild(RenderNode $child, LayoutConstraints $legacyConstraints): LayoutResult
    {
        $space = ConstraintSpaceBuilder::fromLegacyConstraints($legacyConstraints)->build();
        return $this->resolveFragment($child, $space, iteration: 1);
    }

    public function measureIntrinsic(RenderNode $node): LayoutResult
    {
        $space = new ConstraintSpace(
            containerWidth: PHP_INT_MAX,
            containerHeight: PHP_INT_MAX,
            contentWidth: PHP_INT_MAX,
            contentHeight: PHP_INT_MAX,
            isIntrinsicMeasurement: true,
        );
        return $this->resolveFragment($node, $space, iteration: 1);
    }

    /**
     * Override layer on LayoutResult, preserving all other fields.
     */
    private function wrapWithLayer(LayoutResult $result, int $layer): LayoutResult
    {
        if ($result->layer >= $layer) {
            return $result;
        }
        return new LayoutResult(
            x: $result->x, y: $result->y,
            w: $result->w, h: $result->h,
            visualW: $result->visualW, visualH: $result->visualH,
            layer: $layer,
            contentWidth: $result->contentWidth,
            contentHeight: $result->contentHeight,
            style: $result->style,
            children: $result->children,
        );
    }

    /**
     * 按 display/position 选择布局策略。
     */
    private function selectStrategy(string $display, ?ComputedStyle $style): LayoutStrategyInterface
    {
        switch ($display) {
            case 'flex':
            case 'inline-flex':
                return $this->flexStrategy;
            case 'grid':
                return $this->gridStrategy;
            case 'inline':
            case 'inline-block':
                return $this->inlineStrategy;
            case 'table':
            case 'table-row':
            case 'table-cell':
            case 'table-caption':
                return $this->tableStrategy;
            default:
                // Multi-column detection
                $isMultiCol = ($style !== null
                    && ($style->columnCount > 0 || ($style->columnWidth ?? 0) > 0));
                if ($isMultiCol) {
                    return $this->multiColumnStrategy;
                }
                return $this->blockStrategy;
        }
    }

    /**
     * Phase C: 后处理。
     */
    private function postProcess(RenderNode $root, LayoutResult $result): void
    {
        // Scroll container contentHeight clamp
        $this->postProcessScrollContainers();

        // Sticky position processing
        foreach ($root->children as $child) {
            $sticky = $child->computedStyle?->position?->value ?? '';
            if ($sticky === 'sticky') {
                $this->stickyProcessor->process($child, $child->computedStyle, $this->scrollContainers);
            }
        }
    }

    /**
     * 滚动容器后处理：clamp scrollTop/scrollLeft。
     */
    private function postProcessScrollContainers(): void
    {
        foreach ($this->scrollContainers as $node) {
            $cs = $node->computedStyle;

            // Compute contentHeight from children for all scroll containers, not just flex/grid
            $padT = (int)($cs?->padding?->top?->toPx() ?? 0);
            $padB = (int)($cs?->padding?->bottom?->toPx() ?? 0);
            $padL = (int)($cs?->padding?->left?->toPx() ?? 0);
            $padR = (int)($cs?->padding?->right?->toPx() ?? 0);

            $childBaseY = $node->y + $padT;
            $maxBottom = $childBaseY;
            foreach ($node->children as $child) {
                $bottom = (int)($child->y + $child->visualH);
                if ($bottom > $maxBottom) $maxBottom = $bottom;
            }
            $node->contentHeight = (int)max(0, $maxBottom - $childBaseY) + $padB;

            // Clamp scrollTop (scrollLeft moved to ScrollState)
            if (property_exists($node, 'scrollTop')) {
                $maxScroll = (int)max($node->contentHeight - $node->h, 0);
                if ($node->scrollTop > $maxScroll) $node->scrollTop = $maxScroll;
            }

            // Horizontal scroll
            $overflowX2 = $cs?->overflowX?->value ?? $cs?->overflow?->value ?? 'visible';
            $hasHScroll2 = ($overflowX2 === 'auto' || $overflowX2 === 'scroll');
            if ($hasHScroll2) {
                $maxRight = 0;
                foreach ($node->children as $child) {
                    $cLeft = $child->computedStyle?->left ?? 0;
                    $right = (int)($cLeft + $child->visualW);
                    if ($right > $maxRight) $maxRight = $right;
                }
                $node->contentWidth = (int)max($maxRight, $node->visualW);
                if (property_exists($node, 'scrollLeft')) {
                    $maxScrollX = (int)max($node->contentWidth - $node->w, 0);
                    if ($node->scrollLeft > $maxScrollX) $node->scrollLeft = $maxScrollX;
                }
            } else {
                $node->contentWidth = $node->visualW;
            }
        }
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
}
