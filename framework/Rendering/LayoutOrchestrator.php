<?php

namespace Px\Rendering;

use native_types;

use Px\Rendering\Layout\ConstraintSpace;
use Px\Rendering\Layout\PhysicalFragment;
use Px\Rendering\Diag;
use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;
use Px\Rendering\Layout\LayoutAlgorithm;
use Px\Rendering\Layout\BlockAlgorithm;
use Px\Rendering\Layout\FlexAlgorithm;
use Px\Rendering\Layout\GridAlgorithm;
use Px\Rendering\Layout\InlineAlgorithm;
use Px\Rendering\Layout\TableAlgorithm;
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
    private LayoutCache $cache;

    public function __construct()
    {
        $this->oofAlgorithm = new OOFLayoutAlgorithm();
        $this->blockAlgo = new BlockAlgorithm();
        $this->flexAlgo = new FlexAlgorithm();
        $this->gridAlgo = new GridAlgorithm();
        $this->inlineAlgo = new InlineAlgorithm();
        $this->tableAlgo = new TableAlgorithm();
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
        // 构建根约束空间
        // 根容器尺寸来自视口（WINDOW_WIDTH/WINDOW_HEIGHT），而非 RenderNode 属性
        Diag::log(1, 'layout:enter', ['rootType' => $root->type, 'children' => count($root->children)]);
        $rootW = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 1600;
        $rootH = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 800;
        $space = new ConstraintSpace($rootW, $rootH, 0, 0, $rootW, $rootH);

        // Step 1: mainLayout — 正常流布局
        $rootFragment = $this->mainLayout($root, $space);
        // Step 2: oofLayout — OOF 独立通行证
        $viewportW = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0;
        $viewportH = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0;
        $rootFragment = $this->oofAlgorithm->processOutOfFlow(
            $rootFragment, $root, $viewportW, $viewportH
        );

        Diag::log(1, 'layout:exit', ['rootW' => $rootFragment->getW(), 'rootH' => $rootFragment->getH(), 'children' => count($rootFragment->children)]);
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
            if ($cs?->width?->isPercent()) $w = $cs->width->resolveInContext($space->getContentWidth());
            if ($cs?->height?->isPercent()) $h = $cs->height->resolveInContext($space->getContentHeight());
            return new PhysicalFragment(
                0, 0, max(0, $w), max(0, $h),
                0, 0, $nodeLayer, 0, 0,
                $style, $childFragments, $node,
            );
        }

        // 正常流：选择 Algorithm 执行布局
        $algo = $this->selectAlgorithm($display, $style);
        Diag::log(2, 'process:node', ['type' => $node->type, 'display' => $display, 'pos' => $position, 'algo' => $algo !== null ? get_class($algo) : 'none']);
        $textContent = is_string($node->content) ? $node->content : '';

        // Phase 4: 查 LayoutCache — 约束空间不变时跳过算法
        $nodeId = spl_object_id($node);
        $styleVer = spl_object_id($style ?? new \Px\Rendering\ComputedStyle([]));
        $ckey = LayoutCacheKey::fromSpace($space, $nodeId, $styleVer);
        $cached = $this->cache->find($ckey);
        if ($cached !== null) {
            Diag::log(2, 'cache:hit', ['type' => $node->type, 'w' => $cached->w, 'h' => $cached->h]);
            return new \Px\Rendering\Layout\PhysicalFragment($cached->x, $cached->y, $cached->w, $cached->h, $cached->visualW, $cached->visualH, $cached->layer, $cached->contentWidth, $cached->contentHeight, $cached->style, $cached->children, $node);
        }

        Diag::log(2, 'algo:layout', ['type' => $node->type, 'algo' => get_class($algo), 'cw' => $space->getContentWidth(), 'ch' => $space->getContentHeight()]);

        // 调用 Algorithm::layout() 执行布局
        $algoFrag = $algo->layout($space, $style, $textContent, $node->children, $childFragments);
        Diag::log(2, 'algo:result', ['type' => $node->type, 'x' => $algoFrag->getX(), 'y' => $algoFrag->getY(), 'w' => $algoFrag->getW(), 'h' => $algoFrag->getH(), 'algo' => get_class($algo)]);

        // 应用 layer 继承：Algorithm 返回的 Fragment 不包含 layer 信息，需要覆盖
        if ($nodeLayer > $algoFrag->getLayer()) {
            $algoFrag = new \Px\Rendering\Layout\PhysicalFragment(
                (int)$algoFrag->getX(), (int)$algoFrag->getY(), (int)$algoFrag->getW(), (int)$algoFrag->getH(),
                (int)$algoFrag->getVisualW(), (int)$algoFrag->getVisualH(), (int)$nodeLayer,
                (int)$algoFrag->getContentWidth(), (int)$algoFrag->getContentHeight(),
                $algoFrag->style, $algoFrag->children, $algoFrag->sourceNode
            );
        }
        $this->cache->set($ckey, $algoFrag);
        return $algoFrag;
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

        $cbW = max(0, (int)($parentSpace->getContentWidth() ?? 0) - $padL - $padR - $bL - $bR);
        $cbH = max(0, (int)($parentSpace->getContentHeight() ?? 0) - $padT - $padB - $bT - $bB);
        $offX = (int)((int)($parentSpace->getParentContentX() ?? 0) + $padL + $bL);
        $offY = (int)((int)($parentSpace->getParentContentY() ?? 0) + $padT + $bT);

        $childStyle = $child->computedStyle;
        $percW = $childStyle?->width?->isPercent() ? $cbW : null;
        $percH = $childStyle?->height?->isPercent() ? $cbH : null;

        return ConstraintSpace::forChild(
            $offX, $offY, max(0, $cbW), max(0, $cbH),
            $percW, $percH,
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
                return $this->blockAlgo;
        }
    }

    /**
     * 将 Fragment 树回写到 RenderNode（旧消费者兼容）。
     */





}
