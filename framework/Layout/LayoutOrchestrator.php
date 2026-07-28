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
 * Phase 2 产物。多阶段管线：
 *   1. mainLayout(): 正常流布局 → Fragment 树
 *   2. oofLayout(): OOF 独立通行证 → 补充 Fragment 坐标
 *   3. postProcess():
 *      - 文本截断（不可变重建）
 *      - 滚动 clamp（scrollTop <= max(0, contentHeight - h)，防内容变短后滚动位置溢出）
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
        // 使用 ConstraintSpaceBuilder (§12.3)：取代 21 位置参数构造函数，新增字段不需改调用点
        $space = ConstraintSpaceBuilder::create()
            ->setContainerSize($rootW, $rootH)
            ->setContentSize($rootW, $rootH)
            ->build();

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

        // Step 3: postProcess — 文本截断处理（不可变重建）
        $rootFragment = $this->postProcess($rootFragment);

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
     * menulist 子树零盒递归（option/optgroup 及其后代）：全部 (0,0,0,0)，
     * 保留 type/dataset/content 供导出层元素集合同构（px-id 匹配）。
     */
    private function zeroBoxSubtree(RenderNode $node): PhysicalFragment
    {
        $kids = [];
        foreach ($node->children as $ch) {
            if ($ch instanceof RenderNode) $kids[] = $this->zeroBoxSubtree($ch);
        }
        return new PhysicalFragment(0, 0, 0, 0, 0, 0, 0, 0, 0,
            $node->computedStyle, $kids, $node,
            0, 0, false,
            $node->type, $node->content, $this->extractDataset($node), $node->pseudoStyles);
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
    private function mainLayout(RenderNode $node, ConstraintSpace $space, int $inheritedLayer = 0, int $relayoutDepth = 0): PhysicalFragment
    {
        \Px\Core\PerfCounter::inc('layout_enter');
        // ── menulist 子树零盒（对标 Blink：<select> 为替换控件，<option>/
        // <optgroup> 在 style tree 但不入 layout tree，getBoundingClientRect
        // 全 0；true-真值实锤 case-046 B 侧 option/内 span 全 (0,0 0x0)）。
        // 零盒递归保留子树结构（dataset 导出同构），不走常规算法。
        if ($node->type === 'option' || $node->type === 'optgroup') {
            return $this->zeroBoxSubtree($node);
        }
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
            // ── 槽 2 命中（对标 Blink measure/layout 双结果缓存）：flex/grid 两阶段
            // 交替约束下，另一类约束的结果住槽 2——命中则与槽 1 互换（MRU）。
            if (!$node->styleDirty && $node->cachedConstraintSpace2 !== null
                && $node->cachedFragment2 !== null
                && $space->equals($node->cachedConstraintSpace2)) {
                $hitFrag = $node->cachedFragment2;
                $hitSpace = $node->cachedConstraintSpace2;
                $node->cachedFragment2 = $node->cachedFragment;
                $node->cachedConstraintSpace2 = $node->cachedConstraintSpace;
                $node->cachedFragment = $hitFrag;
                $node->cachedConstraintSpace = $hitSpace;
                \Px\Core\PerfCounter::inc('layout_hit_clean');
                return $hitFrag;
            }
        }
        // 不满足早退条件：走正常布局

        // ── 第二级早退：布局等价但 parentContent 位置变化 → 平移子树 ──
        // Blink NGLayoutResult 重用的关键优化：仅坐标变化时 O(N) 平移，
        // 避免重新展开 layout 算法。
        if ($node->cachedFragment !== null && !$node->layoutDirty && !$node->styleDirty) {
            if ($node->cachedConstraintSpace !== null
                && $space->layoutEquals($node->cachedConstraintSpace)
            ) {
                // 仅 parentContent 位置变化（上层堆叠重置）：平移整棵子树
                $dx = $space->getParentContentX() - $node->cachedConstraintSpace->getParentContentX();
                $dy = $space->getParentContentY() - $node->cachedConstraintSpace->getParentContentY();
                if ($dx !== 0 || $dy !== 0) {
                    $translated = $this->translateFragment($node->cachedFragment, $dx, $dy);
                    $node->cachedFragment = $translated;
                    $node->cachedConstraintSpace = $space;
                    Diag::log(2, 'fragment:offset-translate', ['type' => $node->type, 'dx' => $dx, 'dy' => $dy]);
                    \Px\Core\PerfCounter::inc('layout_hit_translate');
                    return $translated;
                }
                // layoutEquals 为 true 且 parentContent 也相同 → 完全等价（应已被 equals 捕获）
                \Px\Core\PerfCounter::inc('layout_hit_clean');
                return $node->cachedFragment;
            }
        }

        \Px\Core\PerfCounter::inc('layout_miss');
        $style = $node->computedStyle;
        $display = $style?->display?->value ?? 'block';
        $position = $style?->position?->value ?? 'static';

        // 检测滚动容器（对标 CSS：overflow 简写设置两轴，除非 overflow-x/y 显式覆盖）
        $rawOX = $style?->getRaw('overflowX');
        $rawOY = $style?->getRaw('overflowY');
        $rawO = $style?->getRaw('overflow');
        $ovXVal = $rawOX !== null ? (is_object($rawOX) ? $rawOX->value : $rawOX) : null;
        $ovYVal = $rawOY !== null ? (is_object($rawOY) ? $rawOY->value : $rawOY) : null;
        $ovVal = $rawO !== null ? (is_object($rawO) ? $rawO->value : $rawO) : null;
        $overflowX = $ovXVal ?? $ovVal ?? 'visible';
        $overflowY = $ovYVal ?? $ovVal ?? 'visible';
        $hasHScroll = ($overflowX === 'auto' || $overflowX === 'scroll');
        $hasVScroll = ($overflowY === 'auto' || $overflowY === 'scroll');
        $nodeIsScrollContainer = ($hasHScroll || $hasVScroll);

        // Layer 继承
        $nodeLayer = $inheritedLayer;
        $zIndex = $style?->zIndex ?? 0;
        if ($zIndex > $nodeLayer) {
            $nodeLayer = $zIndex;
        }

        // OOF 元素：内容照常走自身 display 对应算法布局（对标 Blink：OOF
        // 先按 static-position 约束预布局内容，定位由 OOF 通行证差分平移）。
        // 此前只产占位 Fragment（子逐个 mainLayout 后原样塞入，不跑算法）
        // → flex/IFC 内部排列从未发生，OOF flex 盒内 spans 全重叠盒原点
        //（case-013 x 阶梯族 84 实锤：E 恒 44 vs B 行内步进 138..242）。
        $isOOF = ($position === 'absolute' || $position === 'fixed');

        if ($display === 'none') {
            return new PhysicalFragment(0, 0, 0, 0, 0, 0, 0, 0, 0, $style, array(), $node, 0, 0, false,
                $node->type, $node->content, $this->extractDataset($node), $node->pseudoStyles);
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
        // 调 layoutResult() 获得完整 LayoutResult；当前仅消费 fragment，
        // endMarginStrut/oofDescendants/intrinsicBlockSize 副产物消费链待打通（清单 5.3）。
        $algoResult = $algo->layoutResult($space, $style, $textContent, $node->children, $cached);
        $algoFrag = $algoResult->fragment;
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

        // 表单控件 UA 内在尺寸兑底（对标 Blink LayoutTheme control metrics，
        // 真值反演 case-046：text input 默认 size≈20ch → 179×25；select 空盒
        // UA 最小 18×10）：仅当无显式尺寸且算法产出塌缩/铺满时修正——
        // 控件是替换元素，尺寸由 UA 而非内容流决定（HTML §15.3）。
        $ntType = (string)$node->type;
        if ($ntType === 'input' || $ntType === 'select' || $ntType === 'textarea'
            || $ntType === 'progress' || $ntType === 'meter') {
            $uaW = 0; $uaH = 0;
            if ($ntType === 'input') { $uaW = 179; $uaH = 25; }
            elseif ($ntType === 'select') { $uaW = 18; $uaH = 10; }
            elseif ($ntType === 'textarea') { $uaW = 179; $uaH = 50; }
            elseif ($ntType === 'progress' || $ntType === 'meter') { $uaW = 160; $uaH = 16; }
            $hasExplW = $style !== null && $style->hasExplicitLength('width');
            $hasExplH = $style !== null && $style->hasExplicitLength('height');
            $fw = (int)$algoFrag->getW(); $fh = (int)$algoFrag->getH();
            // 无显式宽 → 恒 UA 宽（替换元素尺寸由 UA 而非内容流决定，
            // HTML §15.3；此前仅塌 0/铺满才兜底，内容宽 10 漏网——
            // 046 input E w=10 vs B 179 实锤）；高同理保持下限兜底。
            $newW = !$hasExplW ? $uaW : $fw;
            $newH = (!$hasExplH && $fh < $uaH) ? $uaH : $fh;
            if (($newW !== $fw || $newH !== $fh) && $uaW > 0) {
                $algoFrag = new \Px\Layout\PhysicalFragment(
                    $algoFrag->x, $algoFrag->y, (int)$newW, (int)$newH,
                    (int)$newW, (int)$newH, $algoFrag->layer,
                    (int)$newW, (int)$newH,
                    $algoFrag->style, $algoFrag->children, $algoFrag->sourceNode,
                    $algoFrag->scrollTop, $algoFrag->scrollLeft, $algoFrag->isScrollContainer,
                    $algoFrag->type !== '' ? $algoFrag->type : $ntType, $algoFrag->content, $algoFrag->dataset, $algoFrag->pseudoStyles
                );
            }
        }

        // 应用 layer 继承 + 元数据打标 + 滚动容器同步
        $isScroll = $nodeIsScrollContainer || $algoFrag->getIsScrollContainer();
        // 对标 Blink NGPhysicalBoxFragment.scrollable_overflow_rect_：
        // scrollable overflow = union(padding-box, 子项块 bounding box)
        // → contentWidth/Height 至少为容器 border-box（padding-box 包含在内），
        // 子项溢出时才取 max(容器尺寸, 子项边界)。
        $containerW = (int)$algoFrag->getW();
        $containerH = (int)$algoFrag->getH();
        $contentW = (int)$algoFrag->getContentWidth();
        $contentH = (int)$algoFrag->getContentHeight();
        if ($isScroll && count($algoFrag->children) > 0) {
            $maxRight = 0; $maxBottom = 0;
            foreach ($algoFrag->children as $ch) {
                $chRight = (int)$ch->getX() + (int)$ch->getW();
                $chBottom = (int)$ch->getY() + (int)$ch->getH();
                if ($chRight > $maxRight) $maxRight = $chRight;
                if ($chBottom > $maxBottom) $maxBottom = $chBottom;
            }
            // 对标 Blink NGPhysicalBoxFragment.scrollable_overflow_rect_：
            //   contentWidth = max(容器宽, 子项最大右边界) — 子项未溢出时为容器宽（非 0）
            //   contentHeight = maxBottom — y 方向保持子项实际高度以支持滚动需求判断
            //   overflow-x/y = hidden|clip 时对应方向溢出被裁切，不计入 scrollable overflow
            $overflowX = $node->computedStyle?->overflowX?->value
                ?? $node->computedStyle?->overflow?->value
                ?? 'visible';
            $overflowY = $node->computedStyle?->overflowY?->value
                ?? $node->computedStyle?->overflow?->value
                ?? 'visible';
            $xClips = ($overflowX === 'hidden' || $overflowX === 'clip');
            $yClips = ($overflowY === 'hidden' || $overflowY === 'clip');
            $contentW = $xClips ? $containerW : max($containerW, $maxRight);
            if (!$yClips && $maxBottom > 0) $contentH = $maxBottom;
            else if ($yClips) $contentH = $containerH;
        }
        if ($nodeLayer > $algoFrag->getLayer()) {
            $algoFrag = new \Px\Layout\PhysicalFragment(
                (int)$algoFrag->getX(), (int)$algoFrag->getY(), (int)$algoFrag->getW(), (int)$algoFrag->getH(),
                (int)$algoFrag->getVisualW(), (int)$algoFrag->getVisualH(), (int)$nodeLayer,
                $contentW, $contentH,
                $algoFrag->style, $algoFrag->children, $algoFrag->sourceNode,
                (int)$algoFrag->getScrollTop(), (int)$algoFrag->getScrollLeft(), $isScroll,
                $node->type, $node->content, $this->extractDataset($node), $node->pseudoStyles
            );
        } else {
            // 无 layover 变化时，仍需要打标元数据
            $algoFrag = new \Px\Layout\PhysicalFragment(
                (int)$algoFrag->getX(), (int)$algoFrag->getY(), (int)$algoFrag->getW(), (int)$algoFrag->getH(),
                (int)$algoFrag->getVisualW(), (int)$algoFrag->getVisualH(), (int)$algoFrag->getLayer(),
                $contentW, $contentH,
                $algoFrag->style, $algoFrag->children, $algoFrag->sourceNode,
                (int)$algoFrag->getScrollTop(), (int)$algoFrag->getScrollLeft(), $isScroll,
                $node->type, $node->content, $this->extractDataset($node), $node->pseudoStyles
            );
        }
        // 缓存完整 Fragment 树 + 约束空间（对标 Blink NGBlockNode，双槽 MRU：
        // 旧主槽下沉槽 2，新结果入主槽——两阶段交替约束不再互相驱逐）
        $node->cachedFragment2 = $node->cachedFragment;
        $node->cachedConstraintSpace2 = $node->cachedConstraintSpace;
        $node->cachedFragment = $algoFrag;
        $node->cachedConstraintSpace = $space;

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
        // 使用 PhysicalFragmentBuilder：从原 fragment 拷贝字段，仅重写偏移后的 x/y + 新子树
        return (new PhysicalFragmentBuilder())
            ->from($frag)
            ->x($frag->x + $dx)
            ->y($frag->y + $dy)
            ->children($translatedChildren)
            ->build();
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
        } else {
            // 父 auto 宽（块级 auto-fill，CSS 2.2 §10.3.3）：父 used content 宽
            // = 约束宽 − 父自身 margins——此前子约束直传祖先宽，父被
            // margin 收窄后子仍携宽约束（038 ul margin-left:24 → li
            // 预布局 716 vs 应 692 实锤；Blink 先定自身尺寸再造子约束）。
            $pml = (int)($parentStyle?->margin?->left->toPx() ?? 0);
            $pmr = (int)($parentStyle?->margin?->right->toPx() ?? 0);
            if ($pml !== 0 || $pmr !== 0) {
                $cbW = max(0, $cbW - $pml - $pmr);
            }
        }
        // 同理：父元素有显式 CSS height 时，用它计算子约束空间高度
        $parentExplicitH = $parentStyle?->height?->toPx();
        $isPctH = $parentStyle?->height?->isPercent() ?? false;
        if ($parentExplicitH !== null && $parentExplicitH > 0 && !$isPctH) {
            $cbH = max(0, (int)$parentExplicitH - $deductH);
        }

        $childStyle = $child->computedStyle;
        $percW = $childStyle?->width?->isPercent() ? $cbW : null;
        $percH = $childStyle?->height?->isPercent() ? $cbH : null;

        // 使用 ConstraintSpaceBuilder (§12.3)：从父继承常见字段，重写子需要的字段
        return ConstraintSpaceBuilder::from($parentSpace)
            ->setContainerSize(max(0, $cbW), max(0, $cbH))
            ->setContentSize(max(0, $cbW), max(0, $cbH))
            ->setParentContentOrigin($offX, $offY)
            ->setPercentageBase($percW, $percH)
            ->setDeterminedPercentageBase(null, null)
            ->setSpaceType('block')
            ->setForceRelayoutChildren(false)
            ->setIntrinsicMeasurement(false)
            // 普通 block 子约束不继承父的 formatting-context-root 位（该位仅描述直接持有者）
            ->setFormattingContextRoot(false)
            ->build();
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
                return $this->tableAlgo;
            // table-caption 是普通 block 容器（Blink：caption 内容走常规 block/IFC
            // 布局，仅定位归父 table 的 caption-side 处理）；此前路由 tableAlgo
            // 落入无 table-row 的 else 堆叠分支，内容不排且高度堆叠膨胀
            //（case-048 caption E 714×150 vs B 716×33 实锤）。
            // table-cell/row/row-group 同理走 block 容器语义
            //（对标 Blink NGTableCellLayoutAlgorithm 内部复用 block 布局；
            // 行/组/格的表格几何由父 table 的 TableAlgorithm 重排）。
            // 若路由到 tableAlgo 会落入无内容布局的 else 分支→ cell h=0 塌陷。
            case 'table-caption':
            case 'table-row':
            case 'table-cell':
            case 'table-row-group':
            case 'table-header-group':
            case 'table-footer-group':
                return $this->blockAlgo;
            default:
                return $this->blockAlgo;
        }
    }

    // ── Phase 3: postProcess — 文本截断处理（不可变重建） ──

    /**
     * 遍历 Fragment 树，对文本节点执行溢出截断。
     * 对标 Blink：Fragment 不可变，截断后重建新 Fragment。
     */
    private function postProcess(PhysicalFragment $rootFrag): PhysicalFragment
    {
        \Px\Core\PerfCounter::start('algo:postProcess');
        $result = $this->postProcessRecursive($rootFrag);
        \Px\Core\PerfCounter::end('algo:postProcess');
        return $result;
    }

    private function postProcessRecursive(PhysicalFragment $frag): PhysicalFragment
    {
        // 先处理子节点（自底向上）
        $newChildren = [];
        $childrenChanged = false;
        foreach ($frag->children as $i => $child) {
            $newChild = $this->postProcessRecursive($child);
            $newChildren[$i] = $newChild;
            if ($newChild !== $child) $childrenChanged = true;
        }

        // ── 滚动 clamp（对标 Blink post-layout scroll clamp）──
        // CSS Overflow §2.4：内容变短后 scrollTop 应 clamp 到 [0, max(0, contentH - h)]，
        // scrollLeft 同理。避免删除子项后滚动位置溢出、露出空区域。
        $newScrollTop = (int)$frag->scrollTop;
        $newScrollLeft = (int)$frag->scrollLeft;
        if ($frag->isScrollContainer) {
            $maxScrollY = max(0, (int)$frag->contentHeight - (int)$frag->h);
            $maxScrollX = max(0, (int)$frag->contentWidth - (int)$frag->w);
            if ($newScrollTop > $maxScrollY) $newScrollTop = $maxScrollY;
            if ($newScrollTop < 0) $newScrollTop = 0;
            if ($newScrollLeft > $maxScrollX) $newScrollLeft = $maxScrollX;
            if ($newScrollLeft < 0) $newScrollLeft = 0;
        }
        $scrollChanged = ($newScrollTop !== (int)$frag->scrollTop) || ($newScrollLeft !== (int)$frag->scrollLeft);

        // 如果子节点或滚动位置有变化，重建父节点（使用 Builder）
        if ($childrenChanged || $scrollChanged) {
            $frag = (new PhysicalFragmentBuilder())
                ->from($frag)
                ->children($newChildren)
                ->scrollTop($newScrollTop)
                ->scrollLeft($newScrollLeft)
                ->build();
        }

        // 仅处理有文本内容且非空容器
        $content = $frag->content;
        if ($content === null || (is_string($content) && $content === '')) {
            return $frag;
        }
        $textContent = (string)$content;
        if ($textContent === '') return $frag;

        $style = $frag->style;
        if ($style === null) return $frag;

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
            return $frag->withDisplayText($textContent);
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
        return $frag->withDisplayText($result['text']);
    }

    /**
     * 将 Fragment 树回写到 RenderNode（旧消费者兼容）。
     */





}
