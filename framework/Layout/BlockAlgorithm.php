<?php
namespace Px\Layout;
use Px\Render\RenderNode;
use native_types;
use Px\Css\ComputedStyle;
use Px\Css\CssLength;

/**
 * BlockAlgorithm — Block 布局算法（完全实现）
 *
 * 取代 BlockLayoutStrategy，直接计算 Block 布局，
 * 不再依赖旧策略层和旧 DTO。
 */
class BlockAlgorithm extends LayoutAlgorithm
{
    // UA inline 元素集单源化：权威在 ComputedStyle::INLINE_TYPES（对标 Blink UA
    // stylesheet）——此前三处各自维护短长不一致（q/kbd/mark 等误判 block）。
    private const INLINE_TYPES = \Px\Css\ComputedStyle::INLINE_TYPES;

    /**
     * 最近一次 stackBlockChildren 的 IFC 流末端（行盒下沿绝对 y）。
     * 对标 Blink：行盒本身是 fragment 参与 intrinsic block size；Px 行盒非
     * fragment（strut 擑高的空间不在 item 子 fragment 几何内），auto-height
     * 需额外消费此流末端，否则 strut 撑高被丢弃（_gt_linestrut T2/T3）。
     */
    private int $lastInlineFlowEnd = 0;

    private static function isInlineType(string $type): bool
    {
        return in_array($type, self::INLINE_TYPES, true);
    }

    /** multicol 内层单列流标志：防 layoutMultiColumn → layout 无限递归 */
    private bool $inMulticolFlow = false;

    /**
     * multi-column 容器布局（对标 Blink NGColumnLayoutAlgorithm）。
     *
     * 两阶段：① CSS Multicol §3.4 伪算法定列数 N/列宽 colW（整数确定性
     * 算术）；② 以 colW 为约束做单列流布局（复用本算法 block 路径，对标
     * Blink fragmentainer 内容流），再按列平衡目标高 targetH=ceil(H/N)
     *（Blink balanced 初始猜测）greedy 分桶：顶层子不可分割（行盒/块盒
     * 原子），超出目标高换列，子树整体平移 (k*(colW+gap), -列首偏移)。
     * column-rule 仅影响绘制不影响几何（§4.3），本层不处理。
     */
    private function layoutMultiColumn(ConstraintSpace $space, ComputedStyle $s, string $textContent, array $childNodes): PhysicalFragment
    {
        $parentW = $space->getContentWidth();
        $w = $this->computeBlockWidth($parentW, $s, $textContent, $space->getDeterminedPercentageWidth() ?? $parentW);
        $padL = (int)($s->padding?->left->toPx() ?? 0);
        $padR = (int)($s->padding?->right->toPx() ?? 0);
        $padT = (int)($s->padding?->top->toPx() ?? 0);
        $padB = (int)($s->padding?->bottom->toPx() ?? 0);
        $bL = (int)($s->getBorderLeftWidth() ?? 0);
        $bR = (int)($s->getBorderRightWidth() ?? 0);
        $bT = (int)($s->getBorderTopWidth() ?? 0);
        $bB = (int)($s->getBorderBottomWidth() ?? 0);
        $innerW = max(1, $w - $padL - $padR - $bL - $bR);

        // 列间距：column-gap 未声明时 normal = 1em（CSS Multicol §4.1/Align §8.3；
        // 真值：浏览器 'normal 16px'）。引擎 columnGap 默认 px(0) 无法区分
        // 显式 0，用 getRaw 区分声明（A 类默认值陷阱同源防范）。
        $gapRaw = $s->getRaw('columnGap');
        if ($gapRaw !== null) {
            $colGap = (int)($s->columnGap?->toPx() ?? 0);
        } else {
            $colGap = (int)($s->getFontSize() > 0 ? $s->getFontSize() : 16);
        }

        // CSS Multicol §3.4 伪算法（整数确定性）
        $specCount = (int)($s->getColumnCount() ?? 0);
        $specWidth = (int)($s->getColumnWidth() ?? 0);
        if ($specWidth > 0 && $specCount > 0) {
            $n = min($specCount, max(1, intdiv($innerW + $colGap, $specWidth + $colGap)));
        } elseif ($specWidth > 0) {
            $n = max(1, intdiv($innerW + $colGap, $specWidth + $colGap));
        } else {
            $n = max(1, $specCount);
        }
        $colW = intdiv($innerW - ($n - 1) * $colGap, $n);
        if ($colW < 1) { $n = 1; $colW = $innerW; }

        // ② 窄约束单列流：流宽 = colW + 自身水平边缘（内层 layout 会再施加 $s
        // 盒模型，补偿后列**内容**可用宽精确 = colW，对标 fragmentainer
        // 无自身盒模型的抽象；不补偿时列内容宽被二次扣边缘）。
        $flowW = $colW + $padL + $padR + $bL + $bR;
        $flowSpace = ConstraintSpaceBuilder::from($space)
            ->setContainerSize($flowW, 0)
            ->setContentSize($flowW, 0)
            ->setParentContentOrigin(0, 0)
            ->setPercentageBase(null, null)
            ->setDeterminedPercentageBase($flowW, null)
            ->setSpaceType('block')
            ->setFormattingContextRoot(true)
            ->build();
        $this->inMulticolFlow = true;
        // 单列流复用容器 style（IFC strut/textAlign/字体上下文必须保留；
        // 空 style 匿名流实验导致行盒上下文丢失劣化 275→325 已回退），
        // 容器边缘在分桶平移时从流坐标中扣除（见 flowOriginX/Y）。
        $flowStyleFrag = $this->layout($flowSpace, $s, $textContent, $childNodes, null);
        $this->inMulticolFlow = false;
        $flowKids = $flowStyleFrag->children;
        // 内容总高（单列流 content 高，去容器自身边缘）
        $flowH = max(0, (int)$flowStyleFrag->getH() - $padT - $padB - $bT - $bB);
        // 单列流内容基点（子坐标含流内容器边缘与 margin，分桶时扣除后叠加本层基点）
        $flowOriginX = (int)$flowStyleFrag->getX() + $padL + $bL;
        $flowOriginY = (int)$flowStyleFrag->getY() + $padT + $bT;
        // 列平衡目标高（Blink balanced 初始猜测：ceil 纯整数）
        $targetH = $n > 0 ? intdiv($flowH + $n - 1, $n) : $flowH;
        if ($targetH < 1) $targetH = 1;

        // 排序顶层子按流序（y 升序；同 y 保持原序），OOF 不参与分列（§2）
        $flowChildren = [];
        $oofChildren = [];
        foreach ($flowKids as $fk) {
            $fkPos = $fk->style?->position?->value ?? 'static';
            if ($fkPos === 'absolute' || $fkPos === 'fixed') { $oofChildren[] = $fk; continue; }
            $flowChildren[] = $fk;
        }

        // greedy 分桶：分片单元 = **行盒**（对标 Blink：fragmentainer 断点在行盒
        // 间，§5.2 行盒原子）。IFC flush 产物是 span 级平铺，按 fkY 聚行组
        //（同行 span 同 y）；若按 span 分桶会每 span 独立换列且 dy 重置→
        // 多行折列同 y（探针实锤 64 span 单 y 平铺）。块级子自成单组。
        $x = (int)($s->margin?->left->resolveBoxPercent($parentW) ?? 0);
        $y = (int)($s->margin?->top->resolveBoxPercent($parentW) ?? 0);
        $contentX = $x + $padL + $bL;
        $contentY = $y + $padT + $bT;
        // 按 y 聚行组（保持流序：ksort 后逐组）
        $lineGroups = [];
        foreach ($flowChildren as $fk) {
            $lineGroups[(int)$fk->getY()][] = $fk;
        }
        ksort($lineGroups);
        $col = 0;
        $colStartFlowY = PHP_INT_MIN;
        $colLines = 0;
        $maxColUsedH = 0;
        $newChildren = [];
        foreach ($lineGroups as $gY => $group) {
            $gH = 0;
            foreach ($group as $fk) { $fkH2 = (int)$fk->getH(); if ($fkH2 > $gH) $gH = $fkH2; }
            if ($colStartFlowY === PHP_INT_MIN) $colStartFlowY = $gY;
            // 断列约束（对标 Blink NGColumnLayoutAlgorithm break 规则）：
            // 行盒组受 orphans/widows 初始值 2 约束（CSS 2.2 §13.3.3，
            // 定义域为**段落内行盒**；列断点适用）：断点前段尾至少
            // 2 行；块级盒间断点不受此限（L24 块子分列 1+1+1 真值）。
            // 真值实锤 case-049：3 行内容 column-count:3 → B 分布 2+1+0
            //（非均衡 1+1+1），末列不足 widows 豁免（Chrome 实测）。
            $isLineGroup = false;
            foreach ($group as $fk) {
                $fkDisp = $fk->style?->display?->value ?? 'block';
                if ($fkDisp === 'inline' || $fkDisp === 'inline-block' || self::isInlineType((string)$fk->type)) { $isLineGroup = true; break; }
            }
            $widowsOk = !$isLineGroup || $colLines >= 2;
            if ($col < $n - 1 && $widowsOk && ($gY + $gH - $colStartFlowY) > $targetH && $gY > $colStartFlowY) {
                $col++;
                $colStartFlowY = $gY;
                $colLines = 0;
            }
            $dx = $contentX + $col * ($colW + $colGap) - $flowOriginX;
            $dy = $contentY - $colStartFlowY;
            foreach ($group as $fk) {
                $newChildren[] = FlexAlgorithm::translateFragmentTree($fk, $dx, $dy);
            }
            $colLines++;
            $usedH = $gY + $gH - $colStartFlowY;
            if ($usedH > $maxColUsedH) $maxColUsedH = $usedH;
        }
        foreach ($oofChildren as $ok) { $newChildren[] = $ok; }

        // 容器高：显式高优先，auto = 最高列 + 边缘（CSS Multicol §3.3）
        $h = $this->computeBlockHeight($space->getContentHeight(), $s, $textContent, $space->getDeterminedPercentageHeight() ?? 0);
        if ($h <= 0 && !$s->hasExplicitLength('height')) {
            $h = $maxColUsedH + $padT + $padB + $bT + $bB;
        }

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h,
            $s->visualWidth($w), $s->visualHeight($h), 0, (int)max(0, $w - $padL - $padR - $bL - $bR), (int)max(0, $h - $padT - $padB - $bT - $bB),
            $s, $newChildren, null);
    }

    /**
     * 计算 Block 元素的内在尺寸（对标 Blink NGBlockNode::ComputeMinMaxSizes）。
     *
     * min-content: 所有 in-flow block 子项的 min-content 的最大值（或文本测量）
     * max-content: 文本不换行宽度 / 子项 max-content 的最大值
     */
    public function computeMinMaxSizes(
        ConstraintSpace $space,
        ?\Px\Css\ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
    ): MinMaxSizes {
        $s = $style ?? \Px\Css\StylePool::empty();
        $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
        $bold = (bool)($s->getBold() ?? false);

        // 文本节点：min-content = max-content = 文本测量宽度
        if (strlen($textContent) > 0) {
            $textW = TextMeasureCache::measure($textContent, $fs, $bold);
            $padL = (int)($s->padding?->left->toPx() ?? 0);
            $padR = (int)($s->padding?->right->toPx() ?? 0);
            $bwLR = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
            $total = $textW + $padL + $padR + $bwLR;
            return new MinMaxSizes($total, $total);
        }

        // 容器节点：遍历子项的 min/max-content
        $minC = 0;
        $maxC = 0;
        foreach ($childNodes as $child) {
            // §11.3: 缓存命中栩——避免复杂嵌套下 O(n²) 重复计算
            if ($child instanceof \Px\Render\RenderNode && $child->cachedMinMaxSizes !== null && !$child->layoutDirty) {
                $childMin = $child->cachedMinMaxSizes->minContent;
                $childMax = $child->cachedMinMaxSizes->maxContent;
                if ($childMin > $minC) $minC = $childMin;
                if ($childMax > $maxC) $maxC = $childMax;
                continue;
            }
            $childStyle = $child->computedStyle;
            $childDisplay = $childStyle?->display?->value ?? 'block';
            $childPos = $childStyle?->position?->value ?? 'static';
            // OOF 不参与内在尺寸计算
            if ($childPos === 'absolute' || $childPos === 'fixed') continue;
            if ($childDisplay === 'none') continue;

            // 若子项有显式 width，直接用其值
            $childW = $childStyle?->width?->toPx() ?? 0;
            if ($childW > 0 && !($childStyle?->width?->isPercent() ?? false) && !($childStyle?->width?->isAuto() ?? true)) {
                $childMin = (int)$childW;
                $childMax = (int)$childW;
            } else {
                // 递归计算子项 min/max-content
                $childContent = (string)($child->content ?? '');
                $algo = $this->selectChildAlgorithm($childDisplay);
                $childSizes = $algo->computeMinMaxSizes($space, $childStyle, $childContent, $child->children);
                $childMin = $childSizes->minContent;
                $childMax = $childSizes->maxContent;
                // §11.3: 存入缓存
                if ($child instanceof \Px\Render\RenderNode) {
                    $child->cachedMinMaxSizes = $childSizes;
                }
            }

            // 加 margin
            $ml = (int)($childStyle?->margin?->left->toPx() ?? 0);
            $mr = (int)($childStyle?->margin?->right->toPx() ?? 0);
            $childMin += $ml + $mr;
            $childMax += $ml + $mr;

            // Block 容器：min-content 取子项 min 的最大值
            if ($childMin > $minC) $minC = $childMin;
            if ($childMax > $maxC) $maxC = $childMax;
        }

        // 加自身 padding+border
        $padL = (int)($s->padding?->left->toPx() ?? 0);
        $padR = (int)($s->padding?->right->toPx() ?? 0);
        $bwLR = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
        $minC += $padL + $padR + $bwLR;
        $maxC += $padL + $padR + $bwLR;

        return new MinMaxSizes($minC, $maxC);
    }

    /** 根据 display 选择子项算法（用于递归 computeMinMaxSizes） */
    private function selectChildAlgorithm(string $display): LayoutAlgorithm
    {
        // 当前简化：所有子项用 BlockAlgorithm 自身递归
        // 未来可扩展为根据 display 选择不同算法
        return $this;
    }

    /**
     * 启用了 endMarginStrut 上传的 LayoutResult。
     *
     * 对标 Blink NGBlockLayoutAlgorithm::Layout()：末尾未消耗的 margin strut 作为 end_margin_strut_
     * 上传给父，以支持 CSS 2.2 §8.3.1 第 3 种场景：父与末子 margin-bottom 折叠。
     *
     * 条件（保守）：
     *   1. 自身不创建新 BFC（overflow visible + 非 float/OOF + 非 flex/grid 容器...）
     *   2. 自身无 padding-bottom + border-bottom（否则不能穿透）
     *   3. 自身无确定高度（否则 margin 不能逸出）
     *   4. 末子为 collapsible block（非 OOF、非创新 BFC）
     *
     * 未满足则 endMarginStrut 为 null，消费方需回退到“不折叠”行为。
     */
    public function layoutResult(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        ?PhysicalFragment $inputFragment = null,
    ): LayoutResult {
        $frag = $this->layout($space, $style, $textContent, $childNodes, $inputFragment);
        $endStrut = $this->extractEndMarginStrut($frag, $style);
        // intrinsicBlockSize 尚无独立追踪，暂与 fragment.h 一致
        return new LayoutResult($frag, $endStrut, (int)$frag->getH());
    }

    /**
     * 从 Fragment 末子提取 endMarginStrut，仅父子边界可穿透时非 null。
     * 对标 Blink 处理 "unresolved bottom margin"：父 padding-bottom/border-bottom/height 均为 0 且未创建新 BFC。
     */
    private function extractEndMarginStrut(PhysicalFragment $frag, ?ComputedStyle $selfStyle): ?MarginStrut
    {
        if ($selfStyle === null) return null;
        // 1. 自身创建新 BFC 则阻断穿透
        if ($this->createsBlockFormattingContext($selfStyle)) return null;

        // 2. 自身有 padding-bottom 或 border-bottom 则不可穿透
        $padB = (int)($selfStyle->padding?->bottom->toPx() ?? 0);
        $bwB = (int)($selfStyle->getBorderBottomWidth() ?? 0);
        if ($padB !== 0 || $bwB !== 0) return null;

        // 3. 自身有确定高度则不可穿透（min-height 也拦截）
        $selfH = $selfStyle->height;
        if ($selfH !== null && !$selfH->isAuto() && !$selfH->isIntrinsic() && $selfH->toPx() > 0) return null;
        $selfMinH = $selfStyle->minHeight;
        if ($selfMinH !== null && !$selfMinH->isAuto() && $selfMinH->toPx() > 0) return null;

        // 4. 逐个向后扫描末尾非 OOF collapsible block 子，提取其 margin-bottom
        for ($i = count($frag->children) - 1; $i >= 0; $i--) {
            $child = $frag->children[$i];
            $chStyle = $child->style;
            if ($chStyle === null) continue;
            $chDisp = $chStyle->display?->value ?? 'block';
            if ($chDisp === 'none') continue;
            $chPos = $chStyle->position?->value ?? 'static';
            if ($chPos === 'absolute' || $chPos === 'fixed') continue;
            // 遇到创建新 BFC 的子：阻断穿透（overflow 简写陷阱：用 effectiveOverflowY）
            $chOverflowY = self::effectiveOverflowY($chStyle);
            $chFloat = $chStyle->getRaw('float') ?? 'none';
            $chFloatVal = is_object($chFloat) ? ($chFloat->value ?? 'none') : (string)$chFloat;
            $chCreatesBFC = ($chOverflowY !== 'visible')
                || ($chFloatVal !== 'none')
                || ($chDisp === 'inline-block' || $chDisp === 'table-cell'
                    || $chDisp === 'flex' || $chDisp === 'grid'
                    || $chDisp === 'flow-root');
            if ($chCreatesBFC) return null;
            if ($chDisp !== 'block') return null;

            $chMBottom = (int)($chStyle->margin?->bottom->toPx() ?? 0);
            if ($chMBottom === 0) return null;
            $strut = new MarginStrut();
            $strut->append($chMBottom);
            return $strut;
        }
        return null;
    }

    /**
     * 有效 overflow-y（对标 CSS Overflow §3：简写设两轴，除非 overflow-y 显式覆盖）。
     * 陷阱：typed overflowY 默认 'visible' 非 null，`overflowY?->value ?? overflow?->value`
     * 链恒取 overflowY 默认值——简写 overflow:hidden 被绕过（A 类默认值语义陷阱）。
     * 必须用 getRaw 区分声明（与 LayoutOrchestrator 滚动容器检测同源语义）。
     * public：FlexAlgorithm automatic-minimum-size 判定复用（单一判定通道）。
     */
    public static function effectiveOverflowY(ComputedStyle $s): string
    {
        $rawOY = $s->getRaw('overflowY');
        if ($rawOY !== null) return is_object($rawOY) ? (string)($rawOY->value ?? 'visible') : (string)$rawOY;
        $rawO = $s->getRaw('overflow');
        if ($rawO !== null) return is_object($rawO) ? (string)($rawO->value ?? 'visible') : (string)$rawO;
        return 'visible';
    }

    /**
     * 自身是否创建新 BFC（CSS 2.2 §9.4.1）——pre/end margin 穿透的共享阻断判定。
     */
    private function createsBlockFormattingContext(ComputedStyle $s): bool
    {
        $selfOverflowY = self::effectiveOverflowY($s);
        $selfDisplay = $s->display?->value ?? 'block';
        $selfPosition = $s->position?->value ?? 'static';
        $selfFloat = $s->getRaw('float') ?? 'none';
        $selfFloatVal = is_object($selfFloat) ? ($selfFloat->value ?? 'none') : (string)$selfFloat;
        return ($selfOverflowY !== 'visible')
            || ($selfPosition === 'absolute' || $selfPosition === 'fixed')
            || ($selfFloatVal !== 'none')
            || ($selfDisplay === 'inline-block' || $selfDisplay === 'table-cell'
                || $selfDisplay === 'flex' || $selfDisplay === 'grid'
                || $selfDisplay === 'flow-root');
    }

    /**
     * 自身顶部边界是否可被首子 margin-top 穿透（CSS 2.2 §8.3.1 父-首子折叠）。
     * 与 end 版不同：显式 height 不阻断 top 穿透（Blink-measured：父 height:50 时
     * 首子 margin-top 仍穿出，父盒整体下移）；仅 padding-top/border-top/BFC 阻断。
     */
    private function isSelfTopPenetrable(ComputedStyle $s): bool
    {
        if ((int)($s->padding?->top->toPx() ?? 0) !== 0) return false;
        if ((int)($s->getBorderTopWidth() ?? 0) !== 0) return false;
        return !$this->createsBlockFormattingContext($s);
    }

    /**
     * 从子 Fragment 列表提取首个 in-flow collapsible 子的顶部 margin strut
     * （含其内部继续穿透的孙辈 strut，递归）。无可穿透首子时返回 null。
     */
    private function firstChildTopStrut(array $childFragments): ?MarginStrut
    {
        foreach ($childFragments as $cr) {
            $chStyle = $cr->style;
            if ($chStyle === null) return null;
            $chDisp = $chStyle->display?->value ?? 'block';
            if ($chDisp === 'none') continue;
            $chPos = $chStyle->position?->value ?? 'static';
            if ($chPos === 'absolute' || $chPos === 'fixed') continue;
            // 首个 in-flow 子：必须为 collapsible block（非 BFC、非 inline/float）才可穿透
            if ($chDisp !== 'block') return null;
            if ($this->createsBlockFormattingContext($chStyle)) return null;
            // 百分比 margin-top 依赖包含块宽解析，提取端无容器宽——宁窄勿宽：不穿透
            if ($chStyle->margin?->top->isPercent() ?? false) return null;
            $chMTop = (int)($chStyle->margin?->top->toPx() ?? 0);
            $strut = new MarginStrut();
            $strut->append($chMTop);
            // 递归：首子自身内部的首孙穿透链（两级以上穿透，Blink-measured P3）
            $sub = $this->extractPreMarginStrut($cr, $chStyle);
            if ($sub !== null) $strut->appendStrut($sub);
            if ($strut->isEmpty()) return null;
            return $strut;
        }
        return null;
    }

    /**
     * 从子容器 Fragment 提取 preMarginStrut（对标 Blink margin strut 穿透，
     * 与 extractEndMarginStrut 对称的消费端重提取模式）。
     * 非 null 时：该容器首子的 margin-top（含递归链）穿出到容器外，
     * 需由父端与容器自身 margin-top / 前兄弟 margin-bottom 折叠。
     */
    private function extractPreMarginStrut(PhysicalFragment $frag, ?ComputedStyle $selfStyle): ?MarginStrut
    {
        // 性能：廉价条件前置（叶子 div 最常见，直接短路）
        if (count($frag->children) === 0) return null;
        if ($selfStyle === null) return null;
        // 容器自身含 inline 内容（IFC）：首子前有行盒，阻断穿透
        $selfContent = (string)($frag->content ?? '');
        if ($selfContent !== '') return null;
        if (!$this->isSelfTopPenetrable($selfStyle)) return null;
        return $this->firstChildTopStrut($frag->children);
    }

    public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        ?PhysicalFragment $inputFragment = null,
    ): PhysicalFragment {
        $s = $style ?? \Px\Css\StylePool::empty();
        // ── multi-column 容器（对标 Blink NGColumnLayoutAlgorithm）──
        // column-count/column-width 声明时走分列路径：窄约束单列流 +
        // 列平衡分桶平移（intrinsic 测量模式除外）。前置于子预布局：
        // 子需以列宽为约束重新布局（非容器宽）。
        if (!$space->getIsIntrinsicMeasurement() && !$this->inMulticolFlow
            && ((int)($s->getColumnCount() ?? 0) > 0 || (int)($s->getColumnWidth() ?? 0) > 0)) {
            return $this->layoutMultiColumn($space, $s, $textContent, $childNodes);
        }
        // P2: 按需布局子项（对标 Blink：算法通过 LayoutChild 布局子项）
        $children = [];
        for ($ci = 0, $clen = count($childNodes); $ci < $clen; $ci++) {
            $children[] = $this->layoutChild($childNodes[$ci]);
        }
        $c = $space;

        // Intrinsic measurement mode
        if ($c->getIsIntrinsicMeasurement()) {
            $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
            $w = strlen($textContent) > 0 ? TextMeasureCache::measure($textContent, $fs, (bool)($s->getBold() ?? false)) : 0;
            $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
            return new PhysicalFragment((int)max(0, $w), (int)max(0, $h), 0, 0, 0, 0, 0, 0, 0, $s, [], null, 0, 0, false, '', null, [], [], (int)max(0, $w));
        }

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        // CSS 2.2 §8.3：margin 百分比基于**包含块的宽度**（inline-size），不论方向。
        // 使用 resolveBoxPercent($c->getContentWidth()) 而非直接 toPx() 以保证百分比正确解析。
        $cbW = $c->getContentWidth();
        $marginLeft = $s->margin?->left->resolveBoxPercent($cbW) ?? 0;
        $marginTop = $s->margin?->top->resolveBoxPercent($cbW) ?? 0;
        // CSS 两阶段布局：优先使用 determinedPercentageWidth 作为百分比基准
        $parentW = $c->getContentWidth();
        $parentH = $c->getContentHeight();
        $percBaseW = $c->getDeterminedPercentageWidth() ?? $parentW;
        $percBaseH = $c->getDeterminedPercentageHeight() ?? $parentH;

        $w = $this->computeBlockWidth($parentW, $s, $textContent, $percBaseW);
        // 对标 Blink：flex/grid item 的 auto 宽不做 block auto-fill（尺寸由 flex/grid 算法
        // 在交叉轴 stretch/fit-content 阶段决定）——消费 ChildLayoutProvider 的 spaceType='flex-item'。
        if ($c->getSpaceType() === 'flex-item' && $s->getRaw('width') === null
            && ($s->width === null || $s->width->isAuto() || $s->width->toPx() <= 0)) {
            $w = 0;
        }
        $h = $this->computeBlockHeight($parentH, $s, $textContent, $percBaseH);
        // CSS-Sizing-4 §5 aspect-ratio 反向推导（H 显式 + W auto）：
        //   仅当宽度由默认 auto-fill 得到 (== parentW - margins) 且 height 显式声明时，
        //   尝试使用 aspect-ratio 推导 宽度 = 高度 * ratio。
        //   需避免与显式 width 冲突—仅当 raw width 未声明（getRaw('width') === null）时生效。
        $rawW = $s->getRaw('width');
        $arVal = $s->getAspectRatio() ?? 0;
        $hExplicit = ($s->height !== null && !$s->height->isAuto() && !$s->height->isIntrinsic() && $s->height->toPx() > 0);
        if ($arVal > 0 && $rawW === null && $hExplicit && $h > 0) {
            $derivedW = (int)($h * $arVal);
            if ($derivedW > 0) {
                // clamp 到 min/max width（同 computeBlockWidth）
                $minWc = $s->minWidth?->toPx() ?? 0;
                $maxWc = $s->maxWidth?->toPx() ?? 0;
                if ($minWc > 0 && $maxWc > 0 && $minWc > $maxWc) $maxWc = $minWc;
                if ($maxWc > 0 && $derivedW > $maxWc) $derivedW = $maxWc;
                if ($minWc > 0 && $derivedW < $minWc) $derivedW = $minWc;
                $w = $derivedW;
            }
        }

        $positionVal = $s->position?->value ?? 'static';
        // 对标 Blink/CSS 规范：left/top 仅对定位元素（relative）生效，static 元素忽略
        $isRelative = ($positionVal === 'relative');
        // 坐标为相对坐标（约束根）。Blink NGConstraintSpace 的 bfc_offset 在 Px 中未启用，此处仅使用 margin+left/top。
        $x = (int)($marginLeft ?? 0) + ($isRelative ? (int)($left ?? 0) : 0);
        $y = (int)($marginTop ?? 0) + ($isRelative ? (int)($top ?? 0) : 0);

        $displayVal = $s->display?->value ?? 'block';
        // ── CSS 2.2 §8.3.1 父-首子 margin-top 穿透（生产端，对标 Blink margin strut 穿透）──
        // 自身可穿透且首子有可折叠 margin-top 链时：该 strut 逸出父外，与自身 margin-top
        // 折叠后并入自身 y（等效 margin），首子在父内不再施加（stackBlockChildren 消费 $escapedTop）。
        // 守卫：flex/grid item（formatting context root）不穿透（CSS Flexbox §4 / Grid §6.1）。
        $escapedTop = false;
        if (count($children) > 0 && $displayVal === 'block' && strlen($textContent) === 0
            && !$c->getIsFormattingContextRoot() && $c->getSpaceType() !== 'flex-item'
            && $this->isSelfTopPenetrable($s)) {
            $escapedStrut = $this->firstChildTopStrut($children);
            if ($escapedStrut !== null) {
                // 自身 margin-top 与逸出的首子 strut 相邻折叠（CSS 2.2 §8.3.1）
                $penStrut = new MarginStrut();
                $penStrut->append((int)($marginTop ?? 0));
                $penStrut->appendStrut($escapedStrut);
                $y = $penStrut->resolve() + ($isRelative ? (int)($top ?? 0) : 0);
                $escapedTop = true;
            }
        }
        $stackedChildren = [];
        // 重置 IFC 流末端（子项预布局已在上方完成，此后 stack/flush 均属本层；
        // 防共享算法实例上次布局的残留值污染本容器 auto-height）。
        $this->lastInlineFlowEnd = 0;
        if (count($children) > 0 && ($displayVal === 'block' || $displayVal === 'flow-root')) {
            // Check percent-height children
            $hasPercentChild = false;
            foreach ($childNodes as $ch) {
                $chH = $ch->computedStyle?->height;
                if ($chH !== null && $chH->isPercent() && $h <= 0) {
                    $hasPercentChild = true; break;
                }
            }

            if ($hasPercentChild) {
                $pass1 = $this->stackBlockChildren($x, $y, $w, $s, $children, $textContent, $parentW, $escapedTop);
                $computedH = $y;
                foreach ($pass1 as $cr) { $bottom = $cr->getY() + $cr->getH(); if ($bottom > $computedH) $computedH = $bottom; }
                $computedH = max(0, $computedH - $y);

                $reResolved = [];
                foreach ($childNodes as $i => $ch) {
                    $chH = $ch->computedStyle?->height;
                    if ($chH !== null && $chH->isPercent() && $computedH > 0) {
                        $newC = new ConstraintSpace($c->getContentWidth(), $computedH, $c->getParentContentX(), $c->getParentContentY(), 0, 0, $c->getPercentageWidth(), $computedH);
                        $reResolved[] = $this->reResolveChild($newC, $ch, $children[$i] ?? null);
                    } else {
                        $reResolved[] = $i < count($children) ? $children[$i] : null;
                    }
                }
                $children = [];
                foreach ($reResolved as $cr) { if ($cr !== null) $children[] = $cr; }
            }

            $stackedChildren = $this->stackBlockChildren($x, $y, $w, $s, $children, $textContent, $parentW, $escapedTop);
        } else {
            // Handle inline + block children
            // 注：flushInlineBuffer 的 $stackY 为 by-ref 推进——必须用局部游标，
            // 若直传 $y 会被推到行末，下方 auto-height 的 maxBottom-$y 恒 0
            //（table-cell 等非 block 容器含 inline 子高度塌陷至纯边缘，
            // case-048 td h=7 插桩实锤）。内容流起点含 padding/border-top
            //（CSS 2.2 §8.1 content edge；auto-height 尾部只补 bottom 边缘）。
            $flowY = $y + (int)($s->padding?->top->toPx() ?? 0) + (int)($s->getBorderTopWidth() ?? 0);
            $inlineBuffer = [];
            foreach ($children as $cr) {
                $cDisplay = $cr->style?->display?->value ?? 'block';
                if ($cDisplay === 'inline' || $cDisplay === 'inline-block') {
                    $inlineBuffer[] = $cr;
                } else {
                    if (!empty($inlineBuffer)) { $this->flushInlineBuffer($inlineBuffer, $x, 0, $w, $flowY, $stackedChildren, $parentW, $s); }
                    $stackedChildren[] = new PhysicalFragment((int)$cr->getX(), (int)$cr->getY(), (int)$cr->getW(), (int)$cr->getH(), 0, 0, (int)($cr->getLayer() ?? 0), (int)($cr->getContentWidth() ?? 0), (int)($cr->getContentHeight() ?? 0), $cr->style, $cr->children, $cr->sourceNode,
                    $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                    $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles);
                }
            }
            if (!empty($inlineBuffer)) { $this->flushInlineBuffer($inlineBuffer, $x, 0, $w, $flowY, $stackedChildren, $parentW, $s); }
        }

        // CSS 2.2 §10.6.3：仅 height:auto 时从子项累加；显式 height:0 应尊重（getRaw 区分，与 width/height 同源陷阱）
        // 性能：短路顺序——先廉价 h<=0 && 有子，再 getRaw（bench 验证前置 getRaw 每容器执行致 -2.7%）
        if ($h <= 0 && count($stackedChildren) > 0 && !$s->hasExplicitLength('height')) {
            $maxBottom = $y;
            foreach ($stackedChildren as $cr) {
                // CSS 2.2 §10.6.3: auto-height 仅基于**正常流**子元素计算 — OOF (position:absolute/fixed) 不参与
                $chPos = $cr->style?->position?->value ?? 'static';
                if ($chPos === 'absolute' || $chPos === 'fixed') continue;
                // CSS 2.2 §10.6.3：IFC 内容以**行盒下沿**计高（lastInlineFlowEnd
                // 通道）——跳过 union 的 inline 盒两类（rect 超行底部分是溢出
                // 不扩容器，case-039 B 真值：sub/text-bottom 盒超行底 +2/+5
                // 均不计高）：① va 位移盒（vertical-align≠baseline）；② 无文本
                // 盒（字体盒 desc 下探，035/046 实锤）。含文本 baseline 盒保留
                // union（行高低估场景的既有补偿道，050 y=4 族回归实锤：
                // 文本盒底超行底恰补 strut descent 低估，遗留债待行高
                // 模型对齐后再收）。
                $chDispAh = $cr->style?->display?->value ?? 'block';
                if ($this->lastInlineFlowEnd > 0 && $chDispAh === 'inline') {
                    $chVaAh = $cr->style?->verticalAlign?->value ?? 'baseline';
                    if ($chVaAh !== 'baseline' || !self::subtreeHasText($cr)) continue;
                }
                $bottom = $cr->getY() + $cr->getH();
                // CSS 2.2 §10.6.3: auto-height 应包括最后一个正常流子元素的底边距
                if ($cr->style !== null) {
                    $bottom += (int)($cr->style->margin?->bottom->toPx() ?? 0);
                }
                if ($bottom > $maxBottom) $maxBottom = $bottom;
            }
            // IFC 流末端（行盒下沿）参与：行盒 strut 擑高的空间不在 item 子
            // fragment 内（Blink 行盒是 fragment，Px 的等价消费通道）。
            if ($this->lastInlineFlowEnd > $maxBottom) $maxBottom = $this->lastInlineFlowEnd;
            $h = max(0, $maxBottom - $y);
            // CSS 2.2 $10.6.3: auto-height 应包含 padding-bottom + border-bottom
            // 子元素 stack 到 maxBottom，下方 padding 和 border 应当计入高度
            $h += (int)($s->padding?->bottom->toPx() ?? 0);
            $h += (int)($s->getBorderBottomWidth() ?? 0);
        }

        // ── Baseline 计算（对标 Blink NGPhysicalFragment::FirstBaseline）──
        // First baseline = 第一个 in-flow block child 的 baseline（递归）——
        // 若自身含文本（textContent 非空），则 baseline = ascent ≈ fontSize * 0.8
        // 若子项有 baseline，取第一个子项的 (child.y - y) + child.baseline
        $fragBaseline = 0;
        $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
        if (strlen($textContent) > 0) {
            // 文本节点自身有基线
            $fragBaseline = (int)($fs * 0.8);
        } else if (count($stackedChildren) > 0) {
            // 从第一个 in-flow child 查找 baseline（对标 Blink first-baseline 算法）
            foreach ($stackedChildren as $child) {
                $chPos = $child->style?->position?->value ?? 'static';
                if ($chPos === 'absolute' || $chPos === 'fixed') continue;
                $chBaseline = $child->getBaseline();
                if ($chBaseline > 0) {
                    $fragBaseline = (int)($child->getY() - $y) + $chBaseline;
                } else {
                    // 子项无 baseline：估算为子项底边（保守估算）
                    $fragBaseline = (int)($child->getY() - $y) + (int)$child->getH();
                }
                break;
            }
        }

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h, $s->visualWidth($w), $s->visualHeight($h), 0, (int)$w, (int)$h, $s, $stackedChildren, null,
            0, 0, false, '', null, [], [], 0, '', $fragBaseline);
    }

    private function computeBlockWidth(int $parentW, ComputedStyle $s, string $textContent, int $percBaseW = 0): int
    {
        $width = $s->width?->toPx() ?? 0;
        $sizing = $s->boxSizing?->value ?? 'content-box';
        // 百分比或 calc() 均需基于包含块解析
        if ($s->width !== null && ($s->width->isPercent() || $s->width->isCalc())) {
            $pw = $percBaseW > 0 ? $percBaseW : $parentW;
            $width = $s->width->resolveInContext($pw);
            // CSS2.1 §10.2 + CSS-UI-3 §4.5: box-sizing:border-box时百分比width包含padding+border
            if ($sizing === 'border-box') {
                $padL = $s->padding?->left->toPx() ?? 0;
                $padR = $s->padding?->right->toPx() ?? 0;
                $bw = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
                $width = max(0, $width - $padL - $padR - $bw);
            }
        }
        if ($s->width !== null && $s->width->isIntrinsic() && strlen($textContent) > 0) {
            $fs = $s->getFontSize(); $bd = $s->getBold();
            $width = TextMeasureCache::measure($textContent, $fs, (bool)$bd);
        }

        // CSS 2.2 §10.2: 仅当 width 为 auto 时才用可用空间填充，显式 width:0 应尊重。
        // 对标 Blink：default width = px(0)（非 auto），须用 getRaw('width') 区分
        // “显式声明 width:0”与“未声明（默认 px0）”——与 computeBlockHeight 同源修复。
        $hasExplicitWidth = $s->hasExplicitLength('width');
        if ($width <= 0 && !$hasExplicitWidth) {
            $ml = $s->margin?->left->toPx() ?? 0; $mr = $s->margin?->right->toPx() ?? 0;
            // CSS 2.2 §10.3.3：auto 宽的 used 值满足 margin+border+padding+width
            // = 包含块宽 → border-box 尺寸恒 = parentW - margins。
            // box-sizing（CSS-UI-3 §4.5）只影响**显式 width 声明**的解释，
            // 不影响 auto 的 used 值——此前 border-box 分支另扣 padding+border
            // 属声明解释规则错嫁接（引擎 Fragment.w 事实语义全程 border-box，
            // computeBlockHeight 注释自证）：padding:8+border:1 容器双扣 18px，
            // 跨 case 共性 698 vs Blink 716（049/046/039 真值实锤）。
            $width = max(0, $parentW - $ml - $mr);
        }
        $minW = $s->minWidth?->toPx() ?? 0; $maxW = $s->maxWidth?->toPx() ?? 0;
        // CSS-UI-3 §4.5: box-sizing:border-box 时 min-width/max-width 也按 border-box 解释
        // → 需减 padding+border 得到 content-box 下的可比较值（与 $width 尺度坐标一致）
        if ($sizing === 'border-box') {
            $padL = $s->padding?->left->toPx() ?? 0;
            $padR = $s->padding?->right->toPx() ?? 0;
            $bw = (int)($s->getBorderLeftWidth() ?? 0) + (int)($s->getBorderRightWidth() ?? 0);
            $deduct = $padL + $padR + $bw;
            if ($minW > 0) $minW = max(0, $minW - $deduct);
            if ($maxW > 0) $maxW = max(0, $maxW - $deduct);
        }
        // CSS 2.2 §10.4：若 min > max，则先令 max := min（征集中优先保障 min）
        if ($minW > 0 && $maxW > 0 && $minW > $maxW) $maxW = $minW;
        if ($maxW > 0 && $width > $maxW) $width = $maxW;
        if ($minW > 0 && $width < $minW) $width = $minW;
        return (int)max(0, $width);
    }

    private function computeBlockHeight(int $parentH, ComputedStyle $s, string $textContent, int $percBaseH = 0): int
    {
        $height = $s->height?->toPx() ?? 0;
        $sizing = $s->boxSizing?->value ?? 'content-box';
        if ($s->height !== null && ($s->height->isPercent() || $s->height->isCalc())) {
            $ph = $percBaseH > 0 ? $percBaseH : $parentH;
            $height = $s->height->resolveInContext($ph);
            // CSS-UI-3 §4.5: border-box 时百分比 height 包含 padding+border
            if ($sizing === 'border-box') {
                $padT = $s->padding?->top->toPx() ?? 0;
                $padB = $s->padding?->bottom->toPx() ?? 0;
                $bwv = (int)($s->getBorderTopWidth() ?? 0) + (int)($s->getBorderBottomWidth() ?? 0);
                $height = max(0, $height - $padT - $padB - $bwv);
            }
        }
        if ($s->height !== null && $s->height->isIntrinsic() && strlen($textContent) > 0) { $height = $s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($s->getFontSize() * 1.2); }
        // CSS 2.2 §10.6: 仅当 height 为 auto 时才用内容高度，显式 height:0 应尊重。
        // 对标 Blink：default height = px(0)（非 auto），须用 getRaw('height') 区分
        // “显式声明 height:0”与“未声明（默认 px0）”——含文本的 div 未声明高度时 = line-height。
        $hasExplicitHeight = $s->hasExplicitLength('height');
        if ($height <= 0 && !$hasExplicitHeight && strlen($textContent) > 0) {
            $height = $s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($s->getFontSize() * 1.2);
            // border-box 高 = 行高 + padding + border（与 FlexAlgorithm 文本快速路径同源语义，杜绝双路径分叉）
            $height += (int)($s->padding?->top->toPx() ?? 0) + (int)($s->padding?->bottom->toPx() ?? 0)
                     + (int)($s->getBorderTopWidth() ?? 0) + (int)($s->getBorderBottomWidth() ?? 0);
        }
        $ar = $s->getAspectRatio() ?? 0;
        if ($ar > 0 && $height <= 0) { $height = (int)(($s->width?->toPx() ?? 0) / $ar); }
        $minH = $s->minHeight?->toPx() ?? 0; $maxH = $s->maxHeight?->toPx() ?? 0;
        // 引擎事实语义：$height 全程为 border-box（文本分支=行高+padding+border；显式 height 直接作为
        // border-box 使用，快照基线均如此）。对标 Blink：min/max-height 与 used height 在同一 box 语义下
        // clamp（CSS 2.2 §10.7）——此前 border-box 分支对 minH 扣 padding 属语义嫁接错误：
        // clamp 后的 content 值被直接当 border-box 输出，导致 min-height:123+padding:20 → 83。
        // CSS 2.2 §10.4：若 min > max，则先令 max := min（优先保障 min）
        if ($minH > 0 && $maxH > 0 && $minH > $maxH) $maxH = $minH;
        if ($maxH > 0 && $height > $maxH) $height = $maxH;
        if ($minH > 0 && $height < $minH) $height = $minH;
        return (int)max(0, $height);
    }

    private function stackBlockChildren(int $parentX, int $parentY, int $containerW, ComputedStyle $s, array $childResults, string $textContent, int $parentW, bool $escapedTop = false): array
    {
        // CSS 2.2 §8.3：padding/margin 百分比均基于包含块的宽度（inline-size）。
        $padTop = $s->padding?->top->resolveBoxPercent($parentW) ?? 0;
        $padLeft = $s->padding?->left->resolveBoxPercent($parentW) ?? 0;
        $borderTop = (int)($s->getBorderTopWidth() ?? 0);
        $borderLeft = (int)($s->getBorderLeftWidth() ?? 0);
        $stackY = $parentY + $borderTop + $padTop;
        $result = [];
        $prevMarginBottom = 0; $prevCollapsible = false;
        // 首个 in-flow 子追踪（用于 $escapedTop：首子 margin-top 已逸出到父自身 y，不再施加）
        $isFirstInFlow = true;
        $inlineBuffer = [];

        // Phase 4C: ExclusionSpace 用于管理浮动元素排除区域
        $exclusionSpace = new ExclusionSpace($containerW);
        // 收集浮动元素片段（从正常流抽出，单独放置）
        $floatFragments = [];

        foreach ($childResults as $cr) {
            $childStyle = $cr->style;
            $childDisplay = $childStyle?->display?->value ?? 'block';
            $childPosition = $childStyle?->position?->value ?? 'static';
            if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') { $result[] = $cr; continue; }
            $isInline = ($childDisplay === 'inline' || $childDisplay === 'inline-block');
            if ($isInline) { $inlineBuffer[] = $cr; $isFirstInFlow = false; continue; }
            if (!empty($inlineBuffer)) {
                $this->flushInlineBuffer($inlineBuffer, $parentX, $padLeft, $containerW, $stackY, $result, $parentW, $s);
                // 行盒隔断兄弟折叠（§8.3.1 adjoining 要求相邻盒间无 line box）
                $prevCollapsible = false; $prevMarginBottom = 0;
            }

            // 子的 margin/padding 百分比基准 = 父的 content-width ($containerW)
            $mTop = $childStyle?->margin?->top->resolveBoxPercent($containerW) ?? 0;
            $mBottom = $childStyle?->margin?->bottom->resolveBoxPercent($containerW) ?? 0;
            $mLeft = $childStyle?->margin?->left->resolveBoxPercent($containerW) ?? 0;
            $mRight = $childStyle?->margin?->right->resolveBoxPercent($containerW) ?? 0;
            $chW = (int)($cr->getW() ?? 0);
            if ($chW <= 0) {
                $autoPadL = $childStyle?->padding?->left->resolveBoxPercent($containerW) ?? 0;
                $autoPadR = $childStyle?->padding?->right->resolveBoxPercent($containerW) ?? 0;
                $autoBw = (int)($childStyle?->getBorderLeftWidth() ?? 0) + (int)($childStyle?->getBorderRightWidth() ?? 0);
                $cs = $childStyle?->boxSizing?->value ?? 'content-box';
                $chW = ($cs === 'border-box') ? max(0, $containerW - $mLeft - $mRight) : max(0, $containerW - $mLeft - $mRight - $autoPadL - $autoPadR - $autoBw);
            }
            $chH = (int)($cr->getH() ?? 0);
            // 对标 Blink：从 Fragment 直接读取 type/content（非从 ComputedStyle 逗逸口取）
            // 之前使用 $childStyle->getRaw('_type' / '_content') 从 ComputedStyle 逗逸靠样式传递非样式数据，弱化不可变契约。
            $childType = (string)$cr->type;
            $childContent = (string)($cr->content ?? '');
            if (self::isInlineType($childType) && strlen($childContent) > 0 && $childStyle !== null) {
                $fs = $childStyle->getFontSize(); $bd = $childStyle->getBold();
                $measured = TextMeasureCache::measure($childContent, $fs, (bool)$bd);
                if ($measured > 0) $chW = $measured;
                if ($chH <= 0) $chH = $childStyle->getLineHeight() > 0 ? $childStyle->getLineHeight() : (int)($fs * 1.2);
            }
            $overflowY = $childStyle !== null ? self::effectiveOverflowY($childStyle) : 'visible';
            // CSS 2.2 §9.4.1: BFC 边界检测——以下情况创建新 BFC，阻断 margin 折叠
            $childFloat = $childStyle?->getRaw('float') ?? 'none';
            $childFloatVal = is_object($childFloat) ? ($childFloat->value ?? 'none') : (string)$childFloat;
            // Phase 4C: float 子项从正常流抽出，通过 ExclusionSpace 放置
            if ($childFloatVal === 'left' || $childFloatVal === 'right') {
                $floatW = (int)($cr->getW() ?? 0);
                $floatH = (int)($cr->getH() ?? 0);
                if ($floatW <= 0) $floatW = (int)$chW;
                if ($floatH <= 0) $floatH = 20; // fallback
                $pos = $exclusionSpace->placeFloat($childFloatVal, $floatW, $floatH, $stackY - ($parentY + $borderTop + $padTop));
                $exclusionSpace->addFloat($childFloatVal, $pos['x'], $pos['y'], $floatW, $floatH);
                // 将浮动元素放置在绝对坐标
                $floatX = $parentX + $borderLeft + $padLeft + $pos['x'];
                $floatY = $parentY + $borderTop + $padTop + $pos['y'];
                $floatFragments[] = new PhysicalFragment(
                    (int)$floatX, (int)$floatY, (int)$floatW, (int)$floatH,
                    0, 0, (int)($cr->getLayer() ?? 0),
                    (int)$floatW, (int)$floatH,
                    $childStyle, $cr->children, $cr->sourceNode,
                    $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                    $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles
                );
                $isFirstInFlow = false;
                continue; // float 不占据正常流空间
            }
            // Phase 4C: clear 处理
            $clearVal = $childStyle?->getRaw('clear');
            $clearStr = is_object($clearVal) ? ($clearVal->value ?? 'none') : (string)($clearVal ?? 'none');
            if ($clearStr !== 'none' && $clearStr !== '' && !$exclusionSpace->isEmpty()) {
                $relStackY = $stackY - ($parentY + $borderTop + $padTop);
                $clearedY = $exclusionSpace->getClearY($clearStr, $relStackY);
                $stackY = $parentY + $borderTop + $padTop + $clearedY;
            }
            $createsBFC = ($overflowY !== 'visible')
                || ($childPosition === 'absolute' || $childPosition === 'fixed')
                || ($childFloatVal !== 'none')
                || ($childDisplay === 'inline-block' || $childDisplay === 'table-cell'
                    || $childDisplay === 'flex' || $childDisplay === 'grid'
                    || $childDisplay === 'flow-root');
            $isCollapsible = ($childDisplay === 'block') && !$createsBFC;
            // 兄弟折叠资格（CSS 2.2 §8.3.1 adjoining，与穿透资格 $isCollapsible 是
            // 两个独立概念）：in-flow block-level 盒自身 margin 均与兄弟折叠——
            // 自身建 BFC（flex/grid/overflow≠visible）只禁止**内部子穿透**，
            // 不禁止自身与兄弟折叠（Flexbox §4 亦仅禁内容侧；case-037 B 真值
            // footer 499=483+16 折叠实锤）。能到达此处的子均已排除
            // inline/inline-block/float/absolute，均为 block-level in-flow。
            $selfCollapses = true;
            // 子容器内部首孙穿透出来的 strut（CSS 2.2 §8.3.1，消费端重提取，与 endMarginStrut 对称）
            $childPre = $isCollapsible ? $this->extractPreMarginStrut($cr, $childStyle) : null;
            // ── CSS 2.2 §8.3.1 margin 折叠 (对标 Blink NGMarginStrut) ──
            // 相邻兄弟 block（均 in-flow block-level）前章 mBottom 与当前 mTop 折叠
            if ($isFirstInFlow && $isCollapsible && $escapedTop) {
                // 首子 margin-top（含内部穿透链）已逸出并入父自身 y，父内不再施加
                $childY = $stackY;
            } elseif ($selfCollapses && $prevCollapsible) {
                $strut = new MarginStrut();
                $strut->append($prevMarginBottom);
                $strut->append($mTop);
                if ($childPre !== null) $strut->appendStrut($childPre);
                $childY = $stackY - $prevMarginBottom + $strut->resolve();
            } else {
                $topAdj = $mTop;
                if ($childPre !== null) {
                    // 子内部穿透 strut 与子自身 margin-top 相邻折叠（Blink-measured P5：max(5,20)=20）
                    $strut = new MarginStrut();
                    $strut->append($mTop);
                    $strut->appendStrut($childPre);
                    $topAdj = $strut->resolve();
                }
                $childY = $stackY + $topAdj;
            }
            $relTop = $childStyle?->top?->toPx() ?? 0;
            $relLeft = $childStyle?->left?->toPx() ?? 0;
            if ($childPosition === 'relative') { $childY += $relTop; }
            // CSS 2.2 §10.3.3: margin auto 水平居中
            // margin auto 检测：从 getRaw 读取（typed 属性的 isAuto() 在默认值上不可靠）
            $rawML = $childStyle?->getRaw('marginLeft');
            $rawMR = $childStyle?->getRaw('marginRight');
            $marginLeftAuto = ($rawML !== null && (is_object($rawML) ? ($rawML->isAuto ?? false) : ($rawML === 'auto')));
            $marginRightAuto = ($rawMR !== null && (is_object($rawMR) ? ($rawMR->isAuto ?? false) : ($rawMR === 'auto')));
            // 也检查 StyleResolver 计算的 auto 标志
            if (!$marginLeftAuto) { $marginLeftAuto = (bool)($childStyle?->getRaw('marginLeftAuto') ?? false); }
            if (!$marginRightAuto) { $marginRightAuto = (bool)($childStyle?->getRaw('marginRightAuto') ?? false); }
            // 简写回落（'10px auto 0' 等：per-side raw 为 NULL，插桩实锤 106 条）：
            // margin CssRect 由简写展开，显式 auto 的 unit='auto' 可靠；
            // defaults 为 px(0) 不误报（A 类陷阱免疫：仅在简写声明存在时回落）。
            if (!$marginLeftAuto && !$marginRightAuto && $childStyle?->getRaw('margin') !== null) {
                $marginLeftAuto = (bool)($childStyle->margin?->left->isAuto() ?? false);
                $marginRightAuto = (bool)($childStyle->margin?->right->isAuto() ?? false);
            }
            // CSS 2.2 §10.3.3：`margin: 0 auto` 仅当 width 不为 auto 且有剩余空间时生效
            // 若 width 为 auto（chW 已满 containerW），auto margin 均作 0 处理
            $chHasExplicitW = ($childStyle?->width !== null
                && !$childStyle->width->isAuto()
                && $childStyle->width->toPx() > 0);
            $xOffset = $mLeft;
            // Phase 4C: Normal flow 避让浮动排除区域
            // CSS 2.2 §9.5: 正常流 block 子项宽度应缩窄以避开同行的浮动元素
            if (!$exclusionSpace->isEmpty() && !$chHasExplicitW) {
                $relY = (int)$childY - ($parentY + $borderTop + $padTop);
                $avail = $exclusionSpace->findAvailableSpace($relY, $chH);
                $availLeft = $avail['left'];
                $availRight = $avail['right'];
                $effectiveW = $availRight - $availLeft;
                if ($effectiveW < $containerW && $effectiveW > 0) {
                    $chW = min($chW, $effectiveW - $mLeft - $mRight);
                    if ($chW < 0) $chW = 0;
                    $xOffset = $availLeft + $mLeft;
                }
            }
            if ($marginLeftAuto && $marginRightAuto && $chHasExplicitW && $chW < $containerW) {
                // CSS 2.2 §10.3.3：居中基准 = 包含块 **content 宽**（非 border-box；
                // 007 真值：testroot 800 → B margin 225=(750-300)/2，E 误用 800
                // → 250 实锤）；纯整数 intdiv。
                $ctW = $containerW - $padLeft - (int)($s->padding?->right->resolveBoxPercent($parentW) ?? 0)
                    - $borderLeft - (int)($s->getBorderRightWidth() ?? 0);
                $xOffset = max(0, intdiv($ctW - $chW, 2));
            } elseif ($marginLeftAuto && $chHasExplicitW && $chW < $containerW) {
                $ctW = $containerW - $padLeft - (int)($s->padding?->right->resolveBoxPercent($parentW) ?? 0)
                    - $borderLeft - (int)($s->getBorderRightWidth() ?? 0);
                $xOffset = max(0, $ctW - $chW - $mRight);
            }
            // CSS 2.2 §10.6.3：子项从父的 padding-box 左上角开始（= parent origin + border-left + padding-left）
            // 之前 childX 缺少 borderLeft 导致与 childY 不对称 bug
            $stkX = (int)($parentX + $borderLeft + $padLeft + $xOffset + ($childPosition === 'relative' ? $relLeft : 0));
            // 对标 Blink：Fragment 子树坐标随父堆叠偏移平移（此前仅平移子本身，孙子树丢失偏移）
            $stkChildren = $cr->children;
            $stkDx = $stkX - (int)$cr->getX();
            $stkDy = (int)$childY - (int)$cr->getY();
            if (($stkDx !== 0 || $stkDy !== 0) && is_array($stkChildren) && count($stkChildren) > 0) {
                $stkTranslated = [];
                foreach ($stkChildren as $stkCh) {
                    $stkTranslated[] = FlexAlgorithm::translateFragmentTree($stkCh, $stkDx, $stkDy);
                }
                $stkChildren = $stkTranslated;
            }
            $result[] = new PhysicalFragment($stkX, (int)$childY, (int)$chW, (int)$chH, 0, 0, (int)($cr->getLayer() ?? 0),
                    // 保留子的 contentWidth/Height（滚动数据要素）：此前硬编码为 chW/chH
                    // 会丢弃嵌套 scroll 容器的 scrollable overflow（ch 200→150 断裂根因）
                    (int)($cr->getContentWidth() > 0 ? $cr->getContentWidth() : $chW),
                    (int)($cr->getContentHeight() > 0 ? $cr->getContentHeight() : $chH),
                    $childStyle, $stkChildren, $cr->sourceNode,
                    $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                    $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles);
            // ── endMarginStrut 消费（CSS 2.2 §8.3.1 场景 3）──
            // 若子允许 endMarginStrut 上传（子无 padding-bottom/border-bottom/height + 末孙为 collapsible），
            // 则将子自己的 margin-bottom 与子的 endMarginStrut 折叠，作为 effectiveMBottom。
            // 代替 $prevMarginBottom，使得相邻兄弟折叠与后续 stackY 基于折叠后的值。
            $childEndStrut = $isCollapsible ? $this->extractEndMarginStrut($cr, $childStyle) : null;
            $effectiveMBottom = $mBottom;
            $absorbedBottom = 0;
            if ($childEndStrut !== null) {
                $strut = new MarginStrut();
                $strut->append($mBottom);
                $strut->appendStrut($childEndStrut);
                $effectiveMBottom = $strut->resolve();
                // 子 fragment 的 h 已包含末孙 mBottom（auto-height 下），需从 stackY 中减去。
                $absorbedBottom = $childEndStrut->resolve();
            }
            $stackY = ($childY - ($childPosition === 'relative' ? $relTop : 0)) + $chH - $absorbedBottom + $effectiveMBottom;
            $prevMarginBottom = $effectiveMBottom;
            // prev 侧兄弟折叠资格 = selfCollapses（block-level in-flow 均参与，
            // 非旧 isCollapsible 穿透资格——flex/grid/overflow 容器后的兄弟照常折叠）
            $prevCollapsible = $selfCollapses;
            $isFirstInFlow = false;
        }
        if (!empty($inlineBuffer)) { $this->flushInlineBuffer($inlineBuffer, $parentX, $padLeft, $containerW, $stackY, $result, $parentW, $s); }
        // Phase 4C: 追加浮动元素到结果（在正常流子项之后）
        foreach ($floatFragments as $ff) { $result[] = $ff; }
        return $result;
    }

    /** 子树含文本探测（auto-height 跳过判据：递归，文本可在孙层） */
    private static function subtreeHasText(PhysicalFragment $f): bool
    {
        if ((string)$f->displayText !== '' || strlen((string)($f->content ?? '')) > 0) return true;
        foreach ($f->children as $k) {
            if (self::subtreeHasText($k)) return true;
        }
        return false;
    }

    private function flushInlineBuffer(array &$inlineBuffer, int $parentX, int $padLeft, int $containerW, int &$stackY, array &$result, int $parentW, ?ComputedStyle $s = null): void
    {
        // P4: 委派给 InlineAlgorithm（对标 Blink：块算法将 IFC 委派给内联算法）
        // IFC 可用宽 = 容器 content 宽（CSS 2.2 §10.1）：此前两调用点传
        // border-box 宽且仅扣左 padding（右 padding+双 border 全漏，且 else
        // 分支连 padLeft 都传 0）——折行点每行多放 1 span（case-040 实锤
        // E 行宽 192 vs B 186）。边缘计算单源化于此（传入 padLeft 弃用）。
        $padL = (int)($s?->padding?->left->toPx() ?? 0);
        $padR = (int)($s?->padding?->right->toPx() ?? 0);
        $bL = (int)($s?->getBorderLeftWidth() ?? 0);
        $bR = (int)($s?->getBorderRightWidth() ?? 0);
        $availW = $containerW - $padL - $padR - $bL - $bR;
        if ($availW <= 0) $availW = $containerW;
        if ($availW <= 0) $availW = $parentW;
        if ($availW <= 0) $availW = 10000;
        $startX = $parentX + $padL + $bL;
        // text-align 取 IFC 容器样式（继承属性，ComputedStyle 已层叠），
        // 传入 InlineAlgorithm 行级 ApplyTextAlign（CSS 2.2 §16.2 含 inline-block）；
        // 容器样式同时供行盒 root strut 字体 metrics（§10.8.1）。
        $ta = $s?->textAlign?->value ?? 'start';
        // direction 继承链已层叠（CssMappings 'direction'，默认 ltr）：
        // 无 typed property，getRaw 通道取值（CSS 2.2 §9.10 IFC 基方向）。
        $dirRaw = $s?->getRaw('direction');
        $dir = is_string($dirRaw) ? strtolower(trim($dirRaw)) : 'ltr';
        $ir = InlineAlgorithm::layoutInlineRun($inlineBuffer, $availW, $startX, $stackY, 0, $ta, $s, $dir);
        foreach ($ir['items'] as $item) $result[] = $item;
        $stackY = $ir['nextY'];
        // 记录 IFC 流末端（行盒下沿）供 auto-height 消费：strut 擑高的行盒空间
        // 不在 item 子 fragment 几何内（Blink 行盒是 fragment，此为 Px 等价通道）。
        // AOT：by-ref int 参数赋 typed 属性必须显式 (int) 强转。
        $flowEnd = (int)$stackY;
        if ($flowEnd > $this->lastInlineFlowEnd) $this->lastInlineFlowEnd = $flowEnd;
        $inlineBuffer = [];
    }

    private function reResolveChild(ConstraintSpace $space, \Px\Render\RenderNode $child, ?PhysicalFragment $oldFrag): ?PhysicalFragment
    {
        if ($oldFrag === null) return null;
        $childStyle = $child->computedStyle;
        if ($childStyle === null) return $oldFrag;
        $h = $childStyle->height?->toPx() ?? 0;
        if ($childStyle->height !== null && $childStyle->height->isPercent()) { $h = $childStyle->height->resolveInContext($space->getContentHeight()); }
        $minH = $childStyle->minHeight?->toPx() ?? 0; $maxH = $childStyle->maxHeight?->toPx() ?? 0;
        if ($minH > 0 && $h < $minH) $h = $minH;
        if ($maxH > 0 && $h > $maxH) $h = $maxH;
        return new PhysicalFragment((int)$oldFrag->x, (int)$oldFrag->y, (int)$oldFrag->w, (int)max(0, $h), (int)$oldFrag->visualW, (int)$oldFrag->visualH, (int)$oldFrag->layer, (int)$oldFrag->contentWidth, (int)max(0, $h), $childStyle, $oldFrag->children, $oldFrag->sourceNode,
            $oldFrag->scrollTop, $oldFrag->scrollLeft, $oldFrag->isScrollContainer,
            $oldFrag->type, $oldFrag->content, $oldFrag->dataset, $oldFrag->pseudoStyles);
    }
}
