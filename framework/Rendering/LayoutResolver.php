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

        $constraints = new LayoutConstraints(
            $root->w,
            $root->h,
            0,
            0,
            $root->w,
            $root->h,
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
    private function resolveFragment(RenderNode $node, LayoutConstraints $constraints, int $inheritedLayer = 0, ?int $parentResultX = null, ?int $parentResultY = null, int $iteration = 0): LayoutResult
    {
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
        // override with own z-index. Uses inheritedLayer parameter instead of reading
        // node->parent->layer (which is stale during recursive descent).
        $nodeLayer = $inheritedLayer;
        $zIndex = $style?->zIndex ?? 0;
        if ($zIndex > $nodeLayer) {
            $nodeLayer = $zIndex;
        }

        // Recursively resolve children (pure)
        $childResults = [];
        foreach ($node->children as $child) {
            $padL = $style?->padding?->left->toPx() ?? 0;
            $padR = $style?->padding?->right->toPx() ?? 0;
            $padT = $style?->padding?->top->toPx() ?? 0;
            $padB = $style?->padding?->bottom->toPx() ?? 0;
            $bL = $style?->borderLeftWidth ?? 0;
            $bR = $style?->borderRightWidth ?? 0;
            $bT = $style?->borderTopWidth ?? 0;
            $bB = $style?->borderBottomWidth ?? 0;

            $cbW = $node->w > 0 ? max(0, $node->w - $padL - $padR - $bL - $bR) : $constraints->contentWidth;
            $cbH = $node->h > 0 ? max(0, $node->h - $padT - $padB - $bT - $bB) : $constraints->contentHeight;
            $childOffX = $node->x + $padL + $bL;
            $childOffY = $node->y + $padT + $bT;

            $childConstraints = new LayoutConstraints(
                (int)max(0, $cbW),
                (int)max(0, $cbH),
                (int)($childOffX),
                (int)($childOffY),
                (int)max(0, $cbW),
                (int)max(0, $cbH),
            );
            $childResults[] = $this->resolveFragment($child, $childConstraints, $nodeLayer, $node->x, $node->y);
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

        $posVal = $style?->position?->value ?? 'static';
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
            // Absolute positioning: prepare ancestor info
            $input = new LayoutInput(
                constraints: $constraints,
                style: $style,
                textContent: $textContent,
                childResults: $childResults,
                childNodes: $node->children,
                reResolveChild: \Closure::fromCallable([$this, 'reResolveChild']),
                measureIntrinsic: \Closure::fromCallable([$this, 'measureIntrinsic']),
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
            constraints: $constraints,
            style: $style,
            textContent: $textContent,
            childResults: $childResults,
            childNodes: $node->children,
            reResolveChild: \Closure::fromCallable([$this, 'reResolveChild']),
            measureIntrinsic: \Closure::fromCallable([$this, 'measureIntrinsic']),
            iteration: $iteration,
            position: $position,
        );
        $result = $strategy->layout($input);
        $result = $this->wrapWithLayer($result, $nodeLayer);

        // Iteration loop: if strategy requests another pass and we haven't exceeded max
        if ($result->needsAnotherPass && $iteration < 5) {
            return $this->resolveFragment($node, $constraints, $inheritedLayer, $parentResultX, $parentResultY, $iteration + 1);
        }

        return $result;
    }

    public function reResolveChild(RenderNode $child, LayoutConstraints $newConstraints): LayoutResult
    {
        return $this->resolveFragment($child, $newConstraints, iteration: 1);
    }

    public function measureIntrinsic(RenderNode $node): LayoutResult
    {
        $constraints = new LayoutConstraints(
            containerWidth: PHP_INT_MAX,
            containerHeight: PHP_INT_MAX,
            isIntrinsicMeasurement: true,
        );
        return $this->resolveFragment($node, $constraints, iteration: 1);
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
            $display = $cs?->display?->value ?? 'block';
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
