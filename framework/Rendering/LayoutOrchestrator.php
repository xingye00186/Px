<?php

namespace Px\Rendering;

use native_types;

use Px\Rendering\Layout\ConstraintSpace;
use Px\Rendering\Layout\ConstraintSpaceBuilder;
use Px\Rendering\Layout\PhysicalFragment;
use Px\Rendering\Layout\LayoutResult;
use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;
use Px\Rendering\Layout\LayoutAlgorithm;
use Px\Rendering\Layout\BlockAlgorithm;
use Px\Rendering\Layout\FlexAlgorithm;
use Px\Rendering\Layout\GridAlgorithm;
use Px\Rendering\Layout\InlineAlgorithm;
use Px\Rendering\Layout\TableAlgorithm;
use Px\Rendering\Layout\MultiColumnLayoutStrategy;
use Px\Rendering\Layout\LayoutCache;
use Px\Rendering\Layout\OOFLayoutAlgorithm;
use Px\Rendering\Layout\LayoutCacheKey;

use Px\Core\Config;

/**
 * LayoutOrchestrator — 布局编排器（替代 LayoutResolver）
 *
 * Phase 2 产物。三阶段管线：
 *   1. mainLayout(): 正常流布局 → Fragment 树
 *   2. oofLayout(): OOF 独立通行证 → 补充 Fragment 坐标
 *   3. postProcess(): 滚动 clamp / sticky
 *
 * 对标 Blink LayoutNG 的 LayoutOrchestrator。
 */
class LayoutOrchestrator
{
    private OOFLayoutAlgorithm $oofAlgorithm;
    private LayoutAlgorithm $blockAlgo;
    private LayoutAlgorithm $flexAlgo;
    private LayoutAlgorithm $gridAlgo;
    private LayoutAlgorithm $inlineAlgo;
    private LayoutAlgorithm $tableAlgo;
    private LayoutAlgorithm $multiColumnAlgo;
    private LayoutCache $cache;

    /** @var RenderNode[] 当前帧的滚动容器 */
    private array $scrollContainers = [];

    public function __construct()
    {
        $this->oofAlgorithm = new OOFLayoutAlgorithm();
        $this->blockAlgo = new BlockAlgorithm();
        $this->flexAlgo = new FlexAlgorithm();
        $this->gridAlgo = new GridAlgorithm();
        $this->inlineAlgo = new InlineAlgorithm();
        $this->tableAlgo = new TableAlgorithm();
        $this->multiColumnAlgo = new MultiColumnLayoutStrategy();
        $this->cache = new LayoutCache();
    }

    /**
     * 布局入口。
     *
     * @param RenderNode $root 根 RenderNode（原地回写 + 读 computedStyle）
     * @return PhysicalFragment 不可变 Fragment 树（几何权威源）
     */
    public function layout(RenderNode $root): PhysicalFragment
    {
        $this->scrollContainers = [];

        // 构建根约束空间
        $rootStyle = $root->computedStyle;
        $rootW = (int)($root->w ?: ($rootStyle?->width?->toPx() ?: 0));
        $rootH = (int)($root->h ?: ($rootStyle?->height?->toPx() ?: 0));
        $space = new ConstraintSpace(
            containerWidth:  $rootW,
            containerHeight: $rootH,
            contentWidth:    $rootW,
            contentHeight:   $rootH,
        );

        // Step 1: mainLayout — 正常流布局
        $rootFragment = $this->mainLayout($root, $space);

        // Step 2: oofLayout — OOF 独立通行证
        $viewportW = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0;
        $viewportH = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0;
        $rootFragment = $this->oofAlgorithm->processOutOfFlow(
            $rootFragment, $root, $viewportW, $viewportH
        );

        // Step 3: Phase B — 回写 RenderNode（旧消费者兼容）
        $this->applyFragmentToNode($rootFragment, $root);

        // Step 4: postProcess — 滚动 clamp / sticky
        $this->postProcessScrollContainers($root);

        return $rootFragment;
    }

    /**
     * 正常流布局（mainLayout）。
     * 递归遍历 RenderNode 树，委托 Algorithm 执行布局。
     */
    private function mainLayout(RenderNode $node, ConstraintSpace $space, int $inheritedLayer = 0): PhysicalFragment
    {
        $style = $node->computedStyle;
        $display = $style?->display?->value ?? 'block';
        $position = $style?->position?->value ?? 'static';

        // 检测滚动容器
        $overflowX = $style?->overflowX?->value ?? $style?->overflow?->value ?? 'visible';
        $overflowY = $style?->overflowY?->value ?? $style?->overflow?->value ?? 'visible';
        $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll');
        $hasVScroll = ($overflowY === 'auto' || $overflowY === 'scroll');
        $isScroll = $hasHScroll || $hasVScroll;
        if ($isScroll) {
            $this->scrollContainers[] = $node;
        }

        // Layer 继承
        $nodeLayer = $inheritedLayer;
        $zIndex = $style?->zIndex ?? 0;
        if ($zIndex > $nodeLayer) {
            $nodeLayer = $zIndex;
        }

        // OOF 元素在 mainLayout 中产生占位 Fragment（不含几何）
        $isOOF = ($position === 'absolute' || $position === 'fixed');

        if ($display === 'none') {
            return new PhysicalFragment(0, 0, 0, 0, 0, 0, 0, 0, 0, $style, array(), $node);
        }

        // 递归处理子节点（OOF 子节点也递归，但会生成占位 Fragment）
        $childFragments = [];
        foreach ($node->children as $child) {
            $childStyle = $child->computedStyle;
            $childSpace = $this->buildChildSpace($child, $space, $style);
            $childFragments[] = $this->mainLayout($child, $childSpace, $nodeLayer);
        }

        if ($isOOF) {
            // OOF 节点：由 oofLayout 通行证计算位置，此处仅返回引用
            $cs = $style;
            $w = $cs?->width?->toPx() ?? 0;
            $h = $cs?->height?->toPx() ?? 0;
            if ($cs?->width?->isPercent()) $w = $cs->width->resolveInContext($space->contentWidth);
            if ($cs?->height?->isPercent()) $h = $cs->height->resolveInContext($space->contentHeight);
            return new PhysicalFragment(
                x: 0, y: 0, w: max(0, $w), h: max(0, $h),
                style: $style, children: $childFragments,
                sourceNode: $node,
                layer: $nodeLayer,
            );
        }

        // 正常流：选择 Algorithm 执行布局
        $algo = $this->selectAlgorithm($display, $style);
        $inputSpace = $space;

        // 通过旧策略接口获取 LayoutResult，再转为 Fragment
        // Phase 1 适配器模式：旧 Strategy 实现 Algorithm 接口
        if ($algo instanceof \Px\Rendering\Layout\BlockLayoutStrategy
            || $algo instanceof \Px\Rendering\Layout\FlexLayoutStrategy
            || $algo instanceof \Px\Rendering\Layout\GridLayoutStrategy
            || $algo instanceof \Px\Rendering\Layout\InlineLayoutStrategy
            || $algo instanceof \Px\Rendering\Layout\TableLayoutStrategy
            || $algo instanceof \Px\Rendering\Layout\MultiColumnLayoutStrategy) {
            // 旧策略路径：通过 LayoutInput 适配
            $textContent = is_string($node->content) ? $node->content : '';

            // 从 childFragments 还原 LayoutResult 数组
            $childResults = [];
            foreach ($childFragments as $cf) {
                $childResults[] = new LayoutResult(
                    x: $cf->x, y: $cf->y, w: $cf->w, h: $cf->h,
                    visualW: $cf->visualW, visualH: $cf->visualH,
                    layer: $cf->layer,
                    contentWidth: $cf->contentWidth, contentHeight: $cf->contentHeight,
                    style: $cf->style,
                );
            }

            $input = new \Px\Rendering\Layout\LayoutInput(
                constraints: $space->toLegacy(),
                style: $style,
                textContent: $textContent,
                childResults: $childResults,
                childNodes: $node->children,
                position: $position,
            );
            // Phase 4: 查 LayoutCache — 约束空间不变时跳过算法
            $nodeId = spl_object_id($node);
            $styleVer = spl_object_id($style ?? new \Px\Rendering\ComputedStyle([]));
            $ckey = \Px\Rendering\Layout\LayoutCacheKey::fromSpace($space, $nodeId, $styleVer);
            $cached = $this->cache->find($ckey);
            if ($cached !== null) {
                $resultChildren = []; foreach ($cached->children as $i => $ch) { $resultChildren[] = $ch; }
                return new \Px\Rendering\Layout\PhysicalFragment($cached->x, $cached->y, $cached->w, $cached->h, $cached->visualW, $cached->visualH, $cached->layer, $cached->contentWidth, $cached->contentHeight, $cached->style, $resultChildren, $node);
            }
            $result = $algo->layout($input);
            $this->cache->set($ckey, \Px\Rendering\Layout\PhysicalFragment::buildFromLayoutResult($result, $node));
            $resultChildren = []; foreach ($result->children as $i => $child) { $childRN = $node->children[$i] ?? null; $resultChildren[] = new \Px\Rendering\Layout\PhysicalFragment($child->x, $child->y, $child->w, $child->h, $child->visualW, $child->visualH, $child->layer, $child->contentWidth, $child->contentHeight, $child->style, [], $childRN); } return new \Px\Rendering\Layout\PhysicalFragment($result->x, $result->y, $result->w, $result->h, $result->visualW, $result->visualH, $result->layer, $result->contentWidth, $result->contentHeight, $result->style, $resultChildren, $node);
        }

        // Fallback: should not reach here
        return new PhysicalFragment(0, 0, 0, 0, 0, 0, 0, 0, 0, $style, array(), $node);
    }

    /**
     * 构建子节点约束空间。
     */
    private function buildChildSpace(
        RenderNode $child,
        ConstraintSpace $parentSpace,
        ?ComputedStyle $parentStyle,
    ): ConstraintSpace {
        $padL = (int)($parentStyle?->padding?->left->toPx() ?? 0);
        $padR = (int)($parentStyle?->padding?->right->toPx() ?? 0);
        $padT = (int)($parentStyle?->padding?->top->toPx() ?? 0);
        $padB = (int)($parentStyle?->padding?->bottom->toPx() ?? 0);
        $bL = (int)($parentStyle?->borderLeftWidth ?? 0);
        $bR = (int)($parentStyle?->borderRightWidth ?? 0);
        $bT = (int)($parentStyle?->borderTopWidth ?? 0);
        $bB = (int)($parentStyle?->borderBottomWidth ?? 0);

        $cbW = max(0, (int)($parentSpace->contentWidth ?? 0) - $padL - $padR - $bL - $bR);
        $cbH = max(0, (int)($parentSpace->contentHeight ?? 0) - $padT - $padB - $bT - $bB);
        $offX = (int)((int)($parentSpace->parentContentX ?? 0) + $padL + $bL);
        $offY = (int)((int)($parentSpace->parentContentY ?? 0) + $padT + $bT);

        $childStyle = $child->computedStyle;
        $percW = $childStyle?->width?->isPercent() ? $cbW : null;
        $percH = $childStyle?->height?->isPercent() ? $cbH : null;

        return ConstraintSpace::forChild(
            $offX, $offY, max(0, $cbW), max(0, $cbH),
            percentageWidth: $percW, percentageHeight: $percH,
        );
    }

    /**
     * 选择布局算法。
     */
    private function selectAlgorithm(string $display, ?ComputedStyle $style): LayoutAlgorithm
    {
        switch ($display) {
            case 'flex':
            case 'inline-flex':
                return $this->flexAlgo;
            case 'grid':
                return $this->gridAlgo;
            case 'inline':
            case 'inline-block':
                return $this->inlineAlgo;
            case 'table':
            case 'table-row':
            case 'table-cell':
            case 'table-caption':
                return $this->tableAlgo;
            default:
                $isMultiCol = ($style !== null
                    && ((int)$style->columnCount > 0 || (int)($style->columnWidth ?? 0) > 0));
                if ($isMultiCol) {
                    return $this->multiColumnAlgo;
                }
                return $this->blockAlgo;
        }
    }

    /**
     * 将 Fragment 树回写到 RenderNode（旧消费者兼容）。
     */
    private function applyFragmentToNode(PhysicalFragment $frag, RenderNode $node): void
    {
        $node->x = $frag->x;
        $node->y = $frag->y;
        $node->w = $frag->w;
        $node->h = $frag->h;
        $node->visualW = $frag->visualW;
        $node->visualH = $frag->visualH;
        $node->layer = $frag->layer;
        if ($frag->contentWidth > 0)  $node->contentWidth = $frag->contentWidth;
        if ($frag->contentHeight > 0) $node->contentHeight = $frag->contentHeight;
        if ($frag->isScrollContainer) {
            $node->isScrollContainer = true;
        }

        $childCount = min(count($frag->children), count($node->children));
        for ($i = 0; $i < $childCount; $i++) {
            $this->applyFragmentToNode($frag->children[$i], $node->children[$i]);
        }
    }

    /**
     * 滚动容器后处理。
     */
    private function postProcessScrollContainers(RenderNode $root): void
    {
        foreach ($this->scrollContainers as $node) {
            $cs = $node->computedStyle;
            $padT = (int)($cs?->padding?->top?->toPx() ?? 0);
            $padB = (int)($cs?->padding?->bottom?->toPx() ?? 0);

            $childBaseY = $node->y + $padT;
            $maxBottom = $childBaseY;
            foreach ($node->children as $child) {
                $bottom = (int)($child->y + $child->visualH);
                if ($bottom > $maxBottom) $maxBottom = $bottom;
            }
            $node->contentHeight = (int)max(0, $maxBottom - $childBaseY) + $padB;

            // Clamp scrollTop
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


}
