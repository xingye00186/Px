<?php

namespace Px\Layout;

use native_types;

use Px\Layout\ConstraintSpace;
use Px\Layout\PhysicalFragment;
use Px\Core\Diag;
use Px\Css\ComputedStyle;
use Px\Render\RenderNode;
use Px\Layout\LayoutAlgorithm;
use Px\Layout\BlockAlgorithm;
use Px\Layout\FlexAlgorithm;
use Px\Layout\GridAlgorithm;
use Px\Layout\InlineAlgorithm;
use Px\Layout\TableAlgorithm;
use Px\Layout\LayoutCache;
use Px\Layout\OOFLayoutAlgorithm;
use Px\Layout\LayoutCacheKey;

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
     * 正常流布局（mainLayout）— 两阶段：先内在尺寸测量，再确定约束下布局。
     * 对标 Blink LayoutNG 的 LayoutInput → LayoutResult 两阶段模型。
     */
    /** 重布局迭代上限 */
    private const MAX_RELAYOUT_ITERATIONS = 3;

    private function mainLayout(RenderNode $node, ConstraintSpace $space, int $inheritedLayer = 0, int $relayoutDepth = 0): PhysicalFragment
    {
        $style = $node->computedStyle;
        $display = $style?->display?->value ?? 'block';
        $position = $style?->position?->value ?? 'static';

        // 检测滚动容器
        $overflowX = $style?->overflowX?->value ?? $style?->overflow?->value ?? 'visible';
        $overflowY = $style?->overflowY?->value ?? $style?->overflow?->value ?? 'visible';
        $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll');
        $hasVScroll = ($overflowY === 'auto' || $overflowY === 'scroll');

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

        // ─── Phase A: 子项 intrinsic 收集 ───
        $isFlexOrGrid = ($display === 'flex' || $display === 'grid' || $display === 'inline-flex');
        $childIntrinsics = [];
        if ($isFlexOrGrid && count($node->children) > 0) {
            $intrinsicSpace = new ConstraintSpace(
                $space->getContentWidth(), $space->getContentHeight(),
                0, 0, $space->getContentWidth(), $space->getContentHeight(),
                null, null, 0, 0, 0, 0, 0, 0, 0, 0,
                false, true, 0, 0, 'block'
            );
            foreach ($node->children as $ch) {
                $chAlgo = $this->selectAlgorithm($ch->computedStyle?->display?->value ?? 'block', $ch->computedStyle);
                $chIntrinsic = $chAlgo->intrinsicSize($intrinsicSpace, $ch->computedStyle, is_string($ch->content) ? $ch->content : '');
                $childIntrinsics[] = $chIntrinsic;
            }
        }

        // ─── Phase B: 递归处理子节点 ───
        $childFragments = [];
        foreach ($node->children as $i => $child) {
            $childStyle = $child->computedStyle;
            if ($isFlexOrGrid && isset($childIntrinsics[$i])) {
                // flex/grid 子项：用 intrinsic+分配结果构建约束，确保子项百分比用正确基准
                $chPercW = $childStyle?->width?->isPercent() ? $space->getContentWidth() : null;
                $chPercH = $childStyle?->height?->isPercent() ? $space->getContentHeight() : null;
                $childSpace = $this->buildChildSpace($child, $space, $style);
                // 将 intrinsic 收集信息传递通过 spaceType
                $childSpace = ConstraintSpace::forChild(
                    $childSpace->getParentContentX(), $childSpace->getParentContentY(),
                    $childSpace->getContentWidth(), $childSpace->getContentHeight(),
                    $chPercW, $chPercH,
                    $childSpace->getPaddingTop(), $childSpace->getPaddingRight(),
                    $childSpace->getPaddingBottom(), $childSpace->getPaddingLeft(),
                    $childSpace->borderTop, $childSpace->borderRight,
                    $childSpace->borderBottom, $childSpace->borderLeft,
                    false, 'flex-item'
                );
                // 子节点 Phase C 计数器独立：每个节点有自己的 3 轮上限
                $childFragments[] = $this->mainLayout($child, $childSpace, $nodeLayer, 0);
            } else {
                $childSpace = $this->buildChildSpace($child, $space, $style);
                $childFragments[] = $this->mainLayout($child, $childSpace, $nodeLayer, 0);
            }
        }

        if ($isOOF) {
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

        // 查 LayoutCache — 约束空间不变时跳过算法
        $nodeId = spl_object_id($node);
        $styleVer = spl_object_id($style ?? new \Px\Css\ComputedStyle([]));
        $ckey = LayoutCacheKey::fromSpace($space, $nodeId, $styleVer);
        $cached = $this->cache->find($ckey);
        if ($cached !== null) {
            Diag::log(2, 'cache:hit', ['type' => $node->type, 'w' => $cached->w, 'h' => $cached->h]);
            return new \Px\Layout\PhysicalFragment($cached->x, $cached->y, $cached->w, $cached->h, $cached->visualW, $cached->visualH, $cached->layer, $cached->contentWidth, $cached->contentHeight, $cached->style, $cached->children, $node);
        }

        Diag::log(2, 'algo:layout', ['type' => $node->type, 'algo' => get_class($algo), 'cw' => $space->getContentWidth(), 'ch' => $space->getContentHeight()]);

        if (($GLOBALS["_LL"]??0) < 300) { $GLOBALS["_LL"] = ($GLOBALS["_LL"]??0) + 1; fwrite(STDERR, "ML: type={$node->type} disp={$display} algo=".get_class($algo)." cw=".$space->getContentWidth()." ch=".$space->getContentHeight()." kids=".count($node->children)."\n"); }
        $algoFrag = $algo->layout($space, $style, $textContent, $node->children, $childFragments, $cached);
        Diag::log(2, 'algo:result', ['type' => $node->type, 'x' => $algoFrag->getX(), 'y' => $algoFrag->getY(), 'w' => $algoFrag->getW(), 'h' => $algoFrag->getH(), 'algo' => get_class($algo)]);

        // ─── Phase C: flex/grid 子项重布局（最多 self::MAX_RELAYOUT_ITERATIONS 轮）───
        if ($relayoutDepth < self::MAX_RELAYOUT_ITERATIONS && $isFlexOrGrid && count($childFragments) > 0 && count($algoFrag->children) > 0) {
            $needsRelayout = false;
            $childCount = min(count($childFragments), count($algoFrag->children));
            for ($ri = 0; $ri < $childCount; $ri++) {
                $oldW = (int)$childFragments[$ri]->getW();
                $newW = (int)$algoFrag->children[$ri]->getW();
                if ($oldW > 0 && $newW > 0 && abs($oldW - $newW) > 5) {
                    $needsRelayout = true; break;
                }
            }
            if ($needsRelayout) {
                $newChildFragments = [];
                foreach ($node->children as $ri => $child) {
                    $detW = $ri < count($algoFrag->children) ? (int)$algoFrag->children[$ri]->getW() : 0;
                    if ($detW > 0 && $ri < count($childFragments) && abs((int)$childFragments[$ri]->getW() - $detW) > 5) {
                        // 子项宽度变化：用 flex 确定宽度重新约束，determinedPercentageWidth 用于子项百分比
                        $chBaseSpace = $this->buildChildSpace($child, $space, $style);
                        $detContentW = max(0, $detW - $chBaseSpace->getPaddingLeft() - $chBaseSpace->getPaddingRight() - $chBaseSpace->borderLeft - $chBaseSpace->borderRight);
                        $relayoutSpace = new ConstraintSpace(
                            $detW, $chBaseSpace->getContentHeight(),
                            $chBaseSpace->getParentContentX(), $chBaseSpace->getParentContentY(),
                            max(0, $detW), $chBaseSpace->getContentHeight(),
                            $detContentW, $chBaseSpace->getPercentageHeight(),
                            $chBaseSpace->getPaddingTop(), $chBaseSpace->getPaddingRight(),
                            $chBaseSpace->getPaddingBottom(), $chBaseSpace->getPaddingLeft(),
                            $chBaseSpace->borderTop, $chBaseSpace->borderRight,
                            $chBaseSpace->borderBottom, $chBaseSpace->borderLeft,
                            true, false, 0, 0, 'block',
                            $detContentW, $chBaseSpace->getPercentageHeight(),
                        );
                        // 用 Phase C 确定的约束重布局子项，depth+1 限制当前容器自身迭代
                        $newChildFragments[] = $this->mainLayout($child, $relayoutSpace, $nodeLayer, $relayoutDepth + 1);
                    } else {
                        $newChildFragments[] = $ri < count($childFragments) ? $childFragments[$ri] : $childFragments[0];
                    }
                }
                // 用正确的子 fragment 重新执行父布局
                $childFragments = $newChildFragments;
                $algoFrag = $algo->layout($space, $style, $textContent, $node->children, $childFragments, $cached);
                Diag::log(2, 'relayout:done', ['type' => $node->type, 'w' => $algoFrag->getW(), 'h' => $algoFrag->getH()]);
            }
        }

        // 应用 layer 继承
        if ($nodeLayer > $algoFrag->getLayer()) {
            $algoFrag = new \Px\Layout\PhysicalFragment(
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

        // 当父元素有显式 CSS width 时，用它计算子约束空间（优先于约束空间传递的值）
        // 测试卡片 width:800px 的场景：ConstraintSpace contentWidth=1510（来自祖父容器），
        // 但卡片实际仅 800px，子元素应该用 800px 而非 1510 作为约束。
        $parentExplicitW = $parentStyle?->width?->toPx();
        // toPx() returns raw value for percent too (e.g. 100% -> 100). Exclude percent.
        $isPct = $parentStyle?->width?->isPercent() ?? false;
        if ($parentExplicitW !== null && $parentExplicitW > 0 && !$isPct) {
            $cbW = max(0, (int)$parentExplicitW - $padL - $padR - $bL - $bR);
        }

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
