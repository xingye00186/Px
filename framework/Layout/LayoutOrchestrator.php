<?php

namespace Px\Layout;

use native_types;

use Px\Core\PerfCounter;
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
use Px\Layout\OOFLayoutAlgorithm;

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
 * 通过 ChildLayoutProvider 使算法能自主调子项布局。
 */
class LayoutOrchestrator
{
    private OOFLayoutAlgorithm $oofAlgorithm;
    private LayoutAlgorithm $blockAlgo;
    private LayoutAlgorithm $flexAlgo;
    private LayoutAlgorithm $gridAlgo;
    private LayoutAlgorithm $inlineAlgo;
    private LayoutAlgorithm $tableAlgo;

    public function __construct()
    {
        $this->oofAlgorithm = new OOFLayoutAlgorithm();
        $this->blockAlgo = new BlockAlgorithm();
        $this->flexAlgo = new FlexAlgorithm();
        $this->gridAlgo = new GridAlgorithm();
        $this->inlineAlgo = new InlineAlgorithm();
        $this->tableAlgo = new TableAlgorithm();
        // P2: ChildLayoutProvider 现在在 mainLayout 中按节点创建并注入（每个节点有自己的 parentSpace/parentStyle）
    }

    /** ChildLayoutProvider: 以指定约束布局子项 */
    public function layoutChild(RenderNode $child, ConstraintSpace $space, int $layer = 0): PhysicalFragment
    {
        return $this->mainLayout($child, $space, $layer);
    }

    /**
     * 布局入口。
     *
     * @param RenderNode $root 根 RenderNode（原地回写 + 读 computedStyle）
     * @return PhysicalFragment 不可变 Fragment 树（几何权威源）
     */
    public function layout(RenderNode $root): PhysicalFragment
    {
        \Px\Core\PerfCounter::start('stage:layout');
        // 根容器尺寸来自视口（WINDOW_WIDTH/WINDOW_HEIGHT），而非 RenderNode 属性
        Diag::log(1, 'layout:enter', ['rootType' => $root->type, 'children' => count($root->children)]);
        $rootW = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 1600;
        $rootH = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 800;
        $space = new ConstraintSpace($rootW, $rootH, 0, 0, $rootW, $rootH);

        // Step 1: mainLayout — 正常流布局
        $rootFragment = $this->mainLayout($root, $space);
        // Step 2: oofLayout — OOF 独立通行证
        \Px\Core\PerfCounter::start('algo:OOF');
        $viewportW = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0;
        $viewportH = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0;
        $rootFragment = $this->oofAlgorithm->processOutOfFlow(
            $rootFragment, $root, $viewportW, $viewportH
        );
        \Px\Core\PerfCounter::end('algo:OOF');

        // Step 3: postProcess — 文本截断处理
        $this->postProcess($rootFragment);

        Diag::log(1, 'layout:exit', ['rootW' => $rootFragment->getW(), 'rootH' => $rootFragment->getH(), 'children' => count($rootFragment->children)]);
        \Px\Core\PerfCounter::end('stage:layout');
        return $rootFragment;
    }

    /**
     * ChildLayoutProvider 专用：公开的 mainLayout 入口。
     * 对标 Blink：算法通过 LayoutChild() 按需布局子项。
     */
    public function mainLayoutPublic(RenderNode $node, ConstraintSpace $space, int $inheritedLayer = 0, int $relayoutDepth = 0): PhysicalFragment
    {
        return $this->mainLayout($node, $space, $inheritedLayer, $relayoutDepth);
    }

    /**
     * ChildLayoutProvider 专用：公开的 buildChildSpace 入口。
     */
    public function buildChildSpacePublic(RenderNode $child, ConstraintSpace $parentSpace, ?\Px\Css\ComputedStyle $parentStyle): ConstraintSpace
    {
        return $this->buildChildSpace($child, $parentSpace, $parentStyle);
    }

    /**
     * 正常流布局（mainLayout）— 两阶段：先内在尺寸测量，再确定约束下布局。
     * 对标 Blink LayoutNG 的 LayoutInput → LayoutResult 两阶段模型。
     */
    /** 重布局迭代上限 */
    private const MAX_RELAYOUT_ITERATIONS = 3;

    private function mainLayout(RenderNode $node, ConstraintSpace $space, int $inheritedLayer = 0, int $relayoutDepth = 0): PhysicalFragment
    {
        \Px\Core\PerfCounter::inc('layout_enter');
        // ── 洁净早退（基于完整 cachedFragment + 约束空间字段比较）──
        if (!$node->layoutDirty && $node->cachedFragment !== null) {
            if ($node->cachedConstraintSpace !== null && $space->equals($node->cachedConstraintSpace)) {
                if ($node->styleDirty) {
                    // 仅样式变化：复用缓存的 Fragment 子树，替换根节点样式快照
                    $old = $node->cachedFragment;
                    $newFrag = new \Px\Layout\PhysicalFragment(
                        $old->x, $old->y, $old->w, $old->h,
                        $old->visualW, $old->visualH, $old->layer,
                        $old->contentWidth, $old->contentHeight,
                        $node->computedStyle,          // 新样式快照
                        $old->children,                // 复用完整子 Fragment 树
                        $node,
                        $old->scrollTop, $old->scrollLeft, $old->isScrollContainer,
                        $node->type, $node->content, $this->extractDataset($node), $node->pseudoStyles
                    );
                    $node->cachedFragment = $newFrag;   // 更新缓存为新 Fragment
                    $node->styleDirty = false;           // 消费脏位
                    \Px\Core\PerfCounter::inc('layout_hit_style');
                    return $newFrag;
                }
                Diag::log(2, 'fragment:cache-hit', ['type' => $node->type, 'w' => $node->cachedFragment->w]);
                \Px\Core\PerfCounter::inc('layout_hit_clean');
                return $node->cachedFragment;  // 完全洁净：零分配
            }
        }
        // 不满足早退条件：走正常布局

        // ── 第二级早退：布局等价但 BFC/位置偏移变化 → 平移子树 ──
        // 使用 layoutEquals() 排除 bfcOffset/parentContent 噪音，
        // 比旧版 contentWidth/Height 比较覆盖更广（含 padding/border 一致的情况）
        if ($node->cachedFragment !== null && !$node->layoutDirty && !$node->styleDirty) {
            if ($node->cachedConstraintSpace !== null
                && $space->layoutEquals($node->cachedConstraintSpace)
            ) {
                // 仅 BFC 偏移或 parentContent 位置变化：平移整棵子树
                $dx = $space->getBfcOffsetX() - $node->cachedConstraintSpace->getBfcOffsetX();
                $dy = $space->getBfcOffsetY() - $node->cachedConstraintSpace->getBfcOffsetY();
                if ($dx !== 0 || $dy !== 0) {
                    $translated = $this->translateFragment($node->cachedFragment, $dx, $dy);
                    $node->cachedFragment = $translated;
                    $node->cachedConstraintSpace = $space;
                    Diag::log(2, 'fragment:bfc-translate', ['type' => $node->type, 'dx' => $dx, 'dy' => $dy]);
                    \Px\Core\PerfCounter::inc('layout_hit_translate');
                    return $translated;
                }
                // layoutEquals 为 true 且 BFC 也相同 → 完全等价（应已被 equals 捕获）
                \Px\Core\PerfCounter::inc('layout_hit_clean');
                return $node->cachedFragment;
            }
        }

        \Px\Core\PerfCounter::inc('layout_miss');
        $style = $node->computedStyle;
        $display = $style?->display?->value ?? 'block';
        $position = $style?->position?->value ?? 'static';

        // 检测滚动容器
        $overflowX = $style?->overflowX?->value ?? $style?->overflow?->value ?? 'visible';
        $overflowY = $style?->overflowY?->value ?? $style?->overflow?->value ?? 'visible';
        $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll');
        $hasVScroll = ($overflowY === 'auto' || $overflowY === 'scroll');
        if ($hasHScroll || $hasVScroll) {
            $node->isScrollContainer = true;
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
            return new PhysicalFragment(0, 0, 0, 0, 0, 0, 0, 0, 0, $style, array(), $node, 0, 0, false,
                $node->type, $node->content, $this->extractDataset($node), $node->pseudoStyles);
        }

        // P2: Phase B 已删除——算法通过 ChildLayoutProvider.layoutChild() 按需布局子项
        // OOF 元素仍需预布局子项（OOF 路径不经过算法）
        $childFragments = [];
        if ($isOOF) {
            foreach ($node->children as $child) {
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
            $frag = new PhysicalFragment(
                0, 0, max(0, $w), max(0, $h),
                0, 0, $nodeLayer, 0, 0,
                $style, $childFragments, $node, 0, 0, false,
                $node->type, $node->content, $this->extractDataset($node), $node->pseudoStyles
            );
            return $frag;
        }

        // 正常流：选择 Algorithm 执行布局
        $algo = $this->selectAlgorithm($display, $style);
        Diag::log(2, 'process:node', ['type' => $node->type, 'display' => $display, 'pos' => $position, 'algo' => $algo !== null ? get_class($algo) : 'none']);
        $algoName = $algo !== null ? (new \ReflectionClass($algo))->getShortName() : 'none';

        // P2: 注入 ChildLayoutProvider（对标 Blink：算法通过 LayoutChild 按需布局子项）
        // P0 修复：save/restore 防止嵌套布局时算法单例的 provider 被覆盖
        $provider = new ChildLayoutProvider($this, $node, $space, $style, $nodeLayer);
        $savedProvider = $algo->getChildLayoutProvider();
        $algo->setChildLayoutProvider($provider);

        \Px\Core\PerfCounter::start('algo:setup');
        $textContent = (string)($node->content ?? '');
        $cached = $node->cachedFragment;
        \Px\Core\PerfCounter::end('algo:setup');

        \Px\Core\PerfCounter::start('algo:' . $algoName);
        $algoFrag = $algo->layout($space, $style, $textContent, $node->children, $cached);
        \Px\Core\PerfCounter::end('algo:' . $algoName);
        // P0: 恢复父级 provider（防止嵌套布局状态污染）
        $algo->setChildLayoutProvider($savedProvider);

        \Px\Core\PerfCounter::start('algo:teardown');
        Diag::log(2, 'algo:result', ['type' => $node->type, 'x' => $algoFrag->getX(), 'y' => $algoFrag->getY(), 'w' => $algoFrag->getW(), 'h' => $algoFrag->getH(), 'algo' => get_class($algo)]);

        // 修复算法（BlockAlgorithm 等）未传递 type/content 到 Fragment 的问题
        // 当节点有文本内容但 Fragment 丢弃了文本时，重建 Fragment 带上内容
        if ($textContent !== '' && ($algoFrag->getW() > 0 || $algoFrag->getH() > 0)
            && ($algoFrag->type ?? '') === '' && ($algoFrag->content ?? null) === null
        ) {
            $algoFrag = new \Px\Layout\PhysicalFragment(
                $algoFrag->x, $algoFrag->y, $algoFrag->w, $algoFrag->h,
                $algoFrag->visualW, $algoFrag->visualH, $algoFrag->layer,
                $algoFrag->contentWidth, $algoFrag->contentHeight,
                $algoFrag->style, $algoFrag->children, $algoFrag->sourceNode,
                $algoFrag->scrollTop, $algoFrag->scrollLeft, $algoFrag->isScrollContainer,
                $node->type, $textContent, $algoFrag->dataset, $algoFrag->pseudoStyles
            );
        }

        // P2/P3: Phase C 已删除——算法通过 layoutChild() 按需布局，无需外部重布局补丁
        // 对标 Blink：算法内部处理两阶段布局（measure → distribute → re-layout）

        // 应用 layer 继承 + 元数据打标
        if ($nodeLayer > $algoFrag->getLayer()) {
            $algoFrag = new \Px\Layout\PhysicalFragment(
                (int)$algoFrag->getX(), (int)$algoFrag->getY(), (int)$algoFrag->getW(), (int)$algoFrag->getH(),
                (int)$algoFrag->getVisualW(), (int)$algoFrag->getVisualH(), (int)$nodeLayer,
                (int)$algoFrag->getContentWidth(), (int)$algoFrag->getContentHeight(),
                $algoFrag->style, $algoFrag->children, $algoFrag->sourceNode,
                (int)$algoFrag->getScrollTop(), (int)$algoFrag->getScrollLeft(), $algoFrag->getIsScrollContainer(),
                $node->type, $node->content, $this->extractDataset($node), $node->pseudoStyles
            );
        } else {
            // 无 layover 变化时，仍需要打标元数据
            $algoFrag = new \Px\Layout\PhysicalFragment(
                (int)$algoFrag->getX(), (int)$algoFrag->getY(), (int)$algoFrag->getW(), (int)$algoFrag->getH(),
                (int)$algoFrag->getVisualW(), (int)$algoFrag->getVisualH(), (int)$algoFrag->getLayer(),
                (int)$algoFrag->getContentWidth(), (int)$algoFrag->getContentHeight(),
                $algoFrag->style, $algoFrag->children, $algoFrag->sourceNode,
                (int)$algoFrag->getScrollTop(), (int)$algoFrag->getScrollLeft(), $algoFrag->getIsScrollContainer(),
                $node->type, $node->content, $this->extractDataset($node), $node->pseudoStyles
            );
        }
        // 缓存完整 Fragment 树 + 约束空间（对标 Blink NGBlockNode）
        $node->cachedFragment = $algoFrag;
        $node->cachedConstraintSpace = $space;
        $node->layoutCacheVersion++;

        \Px\Core\PerfCounter::end('algo:teardown');

        return $algoFrag;
    }

    /**
     * 计算 ConstraintSpace 签名，用于判断缓存有效性。
     */
    
    private function extractDataset(RenderNode $node): array
    {
        $dataset = [];
        $vnode = $node->sourceVNode;
        if ($vnode === null || $vnode->props === null) return $dataset;
        foreach ($vnode->props as $k => $v) {
            if (str_starts_with((string)$k, 'data-')) {
                $dsKey = substr((string)$k, 5);
                $camelKey = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $dsKey))));
                $dataset[$camelKey] = (string)$v;
            }
        }
        return $dataset;
    }

    /**
     * 平移 Fragment 树的绝对坐标（offsetOnly 路径）。
     */
    private function translateFragment(PhysicalFragment $frag, int $dx, int $dy): PhysicalFragment
    {
        $translatedChildren = [];
        foreach ($frag->children as $child) {
            $translatedChildren[] = $this->translateFragment($child, $dx, $dy);
        }
        return new PhysicalFragment(
            $frag->x + $dx, $frag->y + $dy,
            $frag->w, $frag->h,
            $frag->visualW, $frag->visualH, $frag->layer,
            $frag->contentWidth, $frag->contentHeight,
            $frag->style, $translatedChildren, $frag->sourceNode,
            $frag->scrollTop, $frag->scrollLeft, $frag->isScrollContainer,
            $frag->type, $frag->content, $frag->dataset, $frag->pseudoStyles,
            $frag->availableWidth
        );
    }

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

        // CSS-UI-3 §4.5: box-sizing 决定 padding+border 是否占用约束空间
        // content-box: contentWidth 是内容宽度，不能减 padding+border
        // border-box:  contentWidth 包含 padding+border，需减掉
        $boxSizing = $parentStyle?->boxSizing?->value ?? 'content-box';
        $deductW = ($boxSizing === 'border-box') ? $padL + $padR + $bL + $bR : 0;
        $deductH = ($boxSizing === 'border-box') ? $padT + $padB + $bT + $bB : 0;

        $cbW = max(0, (int)($parentSpace->getContentWidth() ?? 0) - $deductW);
        $cbH = max(0, (int)($parentSpace->getContentHeight() ?? 0) - $deductH);
        $offX = (int)((int)($parentSpace->getParentContentX() ?? 0) + $padL + $bL);
        $offY = (int)((int)($parentSpace->getParentContentY() ?? 0) + $padT + $bT);

        // 当父元素有显式 CSS width 时，用它计算子约束空间（优先于约束空间传递的值）
        // 测试卡片 width:800px 的场景：ConstraintSpace contentWidth=1510（来自祖父容器），
        // 但卡片实际仅 800px，子元素应该用 800px 而非 1510 作为约束。
        $parentExplicitW = $parentStyle?->width?->toPx();
        // toPx() returns raw value for percent too (e.g. 100% -> 100). Exclude percent.
        $isPct = $parentStyle?->width?->isPercent() ?? false;
        if ($parentExplicitW !== null && $parentExplicitW > 0 && !$isPct) {
            $cbW = max(0, (int)$parentExplicitW - $deductW);
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

    // ── Phase 3: postProcess — 文本截断处理 ──

    /**
     * 遍历 Fragment 树，对文本节点执行溢出截断。
     * 将截断结果写入 frag->displayText，paint 直接消费。
     */
    private function postProcess(PhysicalFragment $rootFrag): void
    {
        \Px\Core\PerfCounter::start('algo:postProcess');
        $this->postProcessRecursive($rootFrag);
        \Px\Core\PerfCounter::end('algo:postProcess');
    }

    private function postProcessRecursive(PhysicalFragment $frag): void
    {
        // 先处理子节点（自底向上，子节点截断后父节点 layout 已完成不受影响）
        foreach ($frag->children as $child) {
            $this->postProcessRecursive($child);
        }

        // 仅处理有文本内容且非空容器
        $content = $frag->content;
        if ($content === null || (is_string($content) && $content === '')) {
            return;
        }
        $textContent = (string)$content;
        if ($textContent === '') return;

        $style = $frag->style;
        if ($style === null) return;

        // 计算 content box 宽度
        $bl = (int)($style->borderLeftWidth ?? 0);
        $br = (int)($style->borderRightWidth ?? 0);
        $pl = (int)($style->padding?->left->toPx() ?? 0);
        $pr = (int)($style->padding?->right->toPx() ?? 0);
        $containerW = max(0, $frag->w - $bl - $br - $pl - $pr);

        // 读取溢出样式配置
        $rawOverflow = $style->overflow?->value ?? 'visible';
        $textOverflow = $style->getRaw('textOverflow') ?: 'clip';
        if (!is_string($textOverflow)) { $textOverflow = 'clip'; }
        $overflowWrap = $style->overflowWrap ?? 'normal';
        if ($overflowWrap === '') { $overflowWrap = 'normal'; }
        $lineClampVal = $style->webkitLineClamp ?? 0;
        if (!is_numeric($lineClampVal)) { $lineClampVal = 0; }
        $lineClamp = (int)$lineClampVal;
        $fontSize = $style->fontSize ?? 16;
        if ($fontSize <= 0) { $fontSize = 16; }
        $bold = (bool)($style->bold ?? false);

        // 只有 overflow=hidden/scroll 且 text-overflow=ellipsis 才需要截断
        $needsTruncation = ($rawOverflow === 'hidden' || $rawOverflow === 'clip')
            && ($textOverflow === 'ellipsis' || $lineClamp > 0)
            && $containerW > 0;
        if (!$needsTruncation) {
            $frag->displayText = $textContent;
            return;
        }

        $overflowStyle = [
            'textOverflow' => $textOverflow,
            'overflowWrap' => $overflowWrap,
            'lineHeight' => $style->lineHeight ?? 0,
            'WebkitLineClamp' => $lineClamp,
        ];

        $result = TextOverflowProcessor::process(
            $textContent, $containerW, $fontSize, $bold, $overflowStyle
        );
        $frag->displayText = $result['text'];
    }

    /**
     * 将 Fragment 树回写到 RenderNode（旧消费者兼容）。
     */





}
