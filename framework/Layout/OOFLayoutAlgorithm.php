<?php

namespace Px\Layout;

use native_types;

use Px\Css\ComputedStyle;
use Px\Css\CssLength;
use Px\Render\RenderNode;

/**
 * OOFLayoutAlgorithm — 脱离文档流布局算法（独立通行证）
 *
 * Phase 2 产物。替代 LayoutResolver 中内联的绝对定位分支。
 * 作为独立通行证在 mainLayout 之后运行，遍历 Fragment 树
 * 为所有 position:absolute/fixed 元素计算位置。
 *
 * 对标 Blink OOFLayoutAlgorithm / Flutter Stack.
 */
class OOFLayoutAlgorithm extends LayoutAlgorithm
{
    
    public function __construct()
    {
        }

    public function layout(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = '', array $childNodes = [], ?PhysicalFragment $inputFragment = null): PhysicalFragment
    {
        // OOF 算法不在 mainLayout 路径中直接使用
        // 它通过 Orchestrator 的 oofLayout() 遍历 Fragment 树调用
        throw new \RuntimeException('OOFLayoutAlgorithm::layout() should not be called directly. Use Orchestrator::oofLayout().');
    }

    /**
     * 对 Fragment 树执行 OOF 通行证。
     * 遍历 Fragment 树，为每个 position:absolute/fixed 的元素
     * 通过 sourceNode 回引用获取定位祖先信息，计算坐标并填入 Fragment。
     *
     * @param PhysicalFragment $root 根 Fragment（mainLayout 产出）
     * @param RenderNode $rootRN 根 RenderNode（用于定位祖先查找）
     * @param int $viewportW 视口宽度（fixed 用）
     * @param int $viewportH 视口高度（fixed 用）
     * @return PhysicalFragment 更新后的 Fragment 树（含 OOF 坐标）
     */
    public function processOutOfFlow(
        PhysicalFragment $root,
        RenderNode $rootRN,
        int $viewportW = 0,
        int $viewportH = 0,
    ): PhysicalFragment {
        // 根本身为 OOF（测试直接 position:fixed/absolute 元素作根、或包装组件直接 unwrap后发现 root 本身 OOF）：
        // 使用视口作为 containing block 先处理根，再递归处理 children。
        // 对标 Blink NGOutOfFlowLayoutPart：root layout box 本身也可能是 OOF。
        $rootCS = $root->style;
        $rootPos = $rootCS?->position?->value ?? 'static';
        if ($rootPos === 'fixed' || $rootPos === 'absolute') {
            $root = $this->calculateOOFPosition(
                $root, $rootRN,
                0, 0, $viewportW, $viewportH,
                0, 0, 0, 0,
                $viewportW, $viewportH
            );
        }
        // OOF 通行证：在当前 Fragment 树上一遍扫描 + 回填
        // 由于 PhysicalFragment 不可变，需要重建树
        return $this->processFragment($root, $rootRN, $viewportW, $viewportH);
    }

    private function processFragment(
        PhysicalFragment $frag,
        RenderNode $sourceRN,
        int $viewportW,
        int $viewportH,
        ?int $containingBlockX = null,
        ?int $containingBlockY = null,
        ?int $containingBlockW = null,
        ?int $containingBlockH = null,
        int $borderLeft = 0,
        int $borderTop = 0,
        int $paddingLeft = 0,
        int $paddingTop = 0,
    ): PhysicalFragment {
        $cs = $frag->style;
        $position = $cs?->position?->value ?? 'static';

        // 该节点自身为定位祖先（非 static）时，传递给子节点
        $isPositioned = ($position !== 'static');

        $cbX = $containingBlockX;
        $cbY = $containingBlockY;
        $cbW = $containingBlockW;
        $cbH = $containingBlockH;
        $cbBL = $borderLeft;
        $cbBT = $borderTop;
        $cbPL = $paddingLeft;
        $cbPT = $paddingTop;

        if ($isPositioned) {
            // 当前节点是定位祖先，子节点 absolute 以此为包含块
            // 包含块 = padding box = border-box - border
            $cbX = $frag->getX();
            $cbY = $frag->getY();
            $cbW = $frag->getVisualW() - ((int)($cs?->getBorderLeftWidth() ?? 0) + (int)($cs?->getBorderRightWidth() ?? 0));
            $cbH = $frag->getVisualH() - ((int)($cs?->getBorderTopWidth() ?? 0) + (int)($cs?->getBorderBottomWidth() ?? 0));
            // padding box 需要加 padding/border
            $cbBL = $cs?->getBorderLeftWidth() ?? 0;
            $cbBT = $cs?->getBorderTopWidth() ?? 0;
            $cbPL = $cs?->padding?->left->toPx() ?? 0;
            $cbPT = $cs?->padding?->top->toPx() ?? 0;
        }

        // 处理子节点
        $newChildren = [];
        $childRNs = $sourceRN->children;

        foreach ($frag->children as $i => $childFrag) {
            $childRN = $childRNs[$i] ?? null;
            if ($childRN === null) {
                $newChildren[] = $childFrag;
                continue;
            }

            $childCS = $childFrag->style;
            $childPos = $childCS?->position?->value ?? 'static';

            if ($childPos === 'absolute' || $childPos === 'fixed') {
                // 此子节点为 OOF：使用包含块 + 绝对定位计算
                $isFixed = ($childPos === 'fixed');
                $oofFrag = $this->calculateOOFPosition(
                    $childFrag, $childRN,
                    $isFixed ? 0 : ($cbX ?? 0),
                    $isFixed ? 0 : ($cbY ?? 0),
                    $isFixed ? $viewportW : ($cbW ?? 0),
                    $isFixed ? $viewportH : ($cbH ?? 0),
                    $isFixed ? 0 : $cbBL,
                    $isFixed ? 0 : $cbBT,
                    $isFixed ? 0 : $cbPL,
                    $isFixed ? 0 : $cbPT,
                    $viewportW, $viewportH,
                );
                $newChildren[] = $oofFrag;
            } else {
                // 正常流子节点：递归处理
                $newChildren[] = $this->processFragment(
                    $childFrag, $childRN,
                    $viewportW, $viewportH,
                    $cbX, $cbY, $cbW, $cbH,
                    $cbBL, $cbBT, $cbPL, $cbPT,
                );
            }
        }

        // 重建 Fragment
        $pfChildren = [];
        foreach ($newChildren as $pfCh) { if ($pfCh !== null) $pfChildren[] = $pfCh; }
        return new PhysicalFragment(
            (int)$frag->getX(), (int)$frag->getY(), (int)$frag->getW(), (int)$frag->getH(),
            (int)$frag->getVisualW(), (int)$frag->getVisualH(), (int)$frag->getLayer(),
            (int)$frag->getContentWidth(), (int)$frag->getContentHeight(),
            $frag->style, $pfChildren, $sourceRN,
            (int)$frag->getScrollTop(), (int)$frag->getScrollLeft(), (bool)$frag->getIsScrollContainer(),
            $frag->type, $frag->content, $frag->dataset, $frag->pseudoStyles
        );
    }

    /**
     * 计算单个 OOF 元素的坐标，复用现有 AbsolutePositioning 逻辑。
     */
    private function calculateOOFPosition(
        PhysicalFragment $frag,
        RenderNode $sourceRN,
        int $ancestorX,
        int $ancestorY,
        int $ancestorW,
        int $ancestorH,
        int $ancestorBorderLeft,
        int $ancestorBorderTop,
        int $ancestorPaddingLeft,
        int $ancestorPaddingTop,
        int $viewportW,
        int $viewportH,
    ): PhysicalFragment {
        $cs = $frag->style;
        if ($cs === null) return $frag;

        // ── Absolute positioning (inlined from AbsolutePositioning::absoluteLayout) ──
        // 百分比 inset 需基于包含块尺寸解析：left/right 基于 ancW，top/bottom 基于 ancH
        // （对标 CSS 2.2 §10.3.7/§10.6.4；此前直接 toPx() 对百分比返回原始数值，如 25% → 25）
        $rawLeft = $cs->getRaw('left');
        $rawTop = $cs->getRaw('top');
        $rawRight = $cs->getRaw('right');
        $rawBottom = $cs->getRaw('bottom');

        $ancW = $ancestorW;
        $ancH = $ancestorH;
        $ancX = $ancestorX;
        $ancY = $ancestorY;
        $bL = $ancestorBorderLeft;
        $bT = $ancestorBorderTop;

        $leftVal = $this->resolveInset($cs->left, $rawLeft, $ancW);
        $topVal = $this->resolveInset($cs->top, $rawTop, $ancH);
        $rightVal = $this->resolveInset($cs->right, $rawRight, $ancW);
        $bottomVal = $this->resolveInset($cs->bottom, $rawBottom, $ancH);

        $width = (int)($cs->width?->toPx() ?? 0);
        $height = (int)($cs->height?->toPx() ?? 0);
        if ($cs->width !== null && $cs->width->isPercent()) $width = $cs->width->resolveInContext($ancW);
        if ($cs->height !== null && $cs->height->isPercent()) $height = $cs->height->resolveInContext($ancH);

        $marginLeft = (int)($cs->margin?->left->toPx() ?? 0);
        $marginTop = (int)($cs->margin?->top->toPx() ?? 0);
        $marginRight = (int)($cs->margin?->right->toPx() ?? 0);
        $marginBottom = (int)($cs->margin?->bottom->toPx() ?? 0);

        // CSS 2.2 §10.3.7/10.6.4：OOF 子项 left+right 同时声明且 width auto 时，推导 width；
        // top+bottom 同时声明且 height auto 时，推导 height。
        // 旧 `!== 0` 判断无法区分 left:0 (声明为 0) 与 left:auto (未声明)，改为完整声明判断。
        $hasLeftDecl = ($rawLeft !== null);
        $hasRightDecl = ($rawRight !== null);
        $hasTopDecl = ($rawTop !== null);
        $hasBottomDecl = ($rawBottom !== null);
        if ($hasLeftDecl && $hasRightDecl && $width <= 0) {
            $width = max(0, $ancW - $leftVal - $rightVal - $marginLeft - $marginRight);
        }
        if ($hasTopDecl && $hasBottomDecl && $height <= 0) {
            $height = max(0, $ancH - $topVal - $bottomVal - $marginTop - $marginBottom);
        }

        $textContent = (string)($sourceRN->content ?? '');
        if (($width <= 0 || $height <= 0) && strlen($textContent) > 0) {
            $fs = (int)($cs->getFontSize() ?? 14);
            $bd = (int)($cs->getBold() ?? 0);
            $measured = TextMeasureCache::measure($textContent, $fs, (bool)$bd);
            if ($measured > 0 && $width <= 0) {
                $width = max(0, $measured + (int)($cs->padding?->left->toPx() ?? 0) + (int)($cs->padding?->right->toPx() ?? 0) + (int)($cs->getBorderLeftWidth() ?? 0) + (int)($cs->getBorderRightWidth() ?? 0));
            }
            if ($height <= 0) {
                $height = max((int)($cs->getLineHeight() ?? (int)($fs * 1.2)), $height);
            }
        }

        // CSS 2.2 §10.3.7 shrink-to-fit：若 OOF width 仍为 0 且无双向声明，使用子项总宽作为 max-content 代理（clamp 到 ancW）。
        // 仅当无文本（上面未命中）且有子项时生效，避免与既有测量逻辑重叠。
        if ($width <= 0 && count($frag->children) > 0) {
            $childrenMaxRight = 0;
            foreach ($frag->children as $ch) {
                $chRight = (int)$ch->getX() + (int)$ch->getW();
                if ($chRight > $childrenMaxRight) $childrenMaxRight = $chRight;
            }
            if ($childrenMaxRight > 0) {
                $padLR = (int)($cs->padding?->left->toPx() ?? 0) + (int)($cs->padding?->right->toPx() ?? 0);
                $bwLR = (int)($cs->getBorderLeftWidth() ?? 0) + (int)($cs->getBorderRightWidth() ?? 0);
                $maxContent = $childrenMaxRight + $padLR + $bwLR;
                $width = min($maxContent, $ancW > 0 ? $ancW : $maxContent);
            }
        }

        $hasLeft = ($cs->getRaw('left') !== null);
        $hasRight = ($cs->getRaw('right') !== null);
        $hasTop = ($cs->getRaw('top') !== null);
        $hasBottom = ($cs->getRaw('bottom') !== null);

        $cbOriginX = $ancX + $bL;
        $cbOriginY = $ancY + $bT;

        $calcX = $cbOriginX + $leftVal + $marginLeft;
        if ($hasRight && !$hasLeft) {
            $calcX = $cbOriginX + $ancW - $rightVal - ($width > 0 ? $width : 0) - $marginRight;
        }

        $calcY = $cbOriginY + $topVal + $marginTop;
        if ($hasBottom && !$hasTop) {
            $calcY = $cbOriginY + $ancH - $bottomVal - ($height > 0 ? $height : 0) - $marginBottom;
        }

        $rawTX = $cs->getRaw('translateX');
        $rawTY = $cs->getRaw('translateY');
        $calcX += $rawTX instanceof CssLength ? $rawTX->toPx() : (int)($rawTX ?? 0);
        $calcY += $rawTY instanceof CssLength ? $rawTY->toPx() : (int)($rawTY ?? 0);

        // CSS 2.2 §10.6.4: margin auto 居中——需同时满足：
        //   1. width 不为 auto（有确定尺寸）
        //   2. 主轴相对两端都有声明（left+right 同时或 top+bottom 同时，且非 auto）
        //   3. margin-* 两端同为 auto
        // → 则把剩余空间均分为两侧。
        $autoOffsetX = 0;
        $autoOffsetY = 0;
        if ($cs->margin !== null) {
            // margin:auto 存储为 marginXxxAuto 标志（StyleResolver 独立路径），
            // CssLength::isAuto() 对 margin 恒为 false（已知陷阱，与 grid auto-margin 同源修复）
            $mLAuto = (bool)($cs->getRaw('marginLeftAuto') ?? false) || $cs->margin->left->isAuto();
            $mRAuto = (bool)($cs->getRaw('marginRightAuto') ?? false) || $cs->margin->right->isAuto();
            $mTAuto = (bool)($cs->getRaw('marginTopAuto') ?? false) || $cs->margin->top->isAuto();
            $mBAuto = (bool)($cs->getRaw('marginBottomAuto') ?? false) || $cs->margin->bottom->isAuto();
            if ($mLAuto && $mRAuto && $hasLeft && $hasRight && $width > 0) {
                // 主轴 X 居中：cbOriginX + left + (ancW - left - right - width) / 2
                $free = $ancW - $leftVal - $rightVal - $width;
                if ($free > 0) $autoOffsetX = (int)($free / 2);
            }
            // CSS 2.2 §10.3.7/10.6.4：仅双向 inset 均声明时 auto margin 才吸收剩余空间；
            // 否则 auto margin 解为 0（旧 fallback 无声明也居中属越权，Blink 不如此）。
            // Y 方向（国际化 lacks writing-mode，仅作普通处理）
            if ($mTAuto && $mBAuto && $hasTop && $hasBottom && $height > 0) {
                $freeV = $ancH - $topVal - $bottomVal - $height;
                if ($freeV > 0) $autoOffsetY = (int)($freeV / 2);
            }
        }
        $calcX += $autoOffsetX;
        $calcY += $autoOffsetY;

        // 对标 Px 中 Fragment.x/y 语义：绝对坐标（相对 layout 根，不累加）。
        // 因此使用 $calcX / $calcY 直接作为 Fragment 坐标（旧代码错误地减去 ancX/ancY 将其变为相对偏移）。
        return new PhysicalFragment(
            (int)$calcX, (int)$calcY, (int)max(0, $width), (int)max(0, $height),
            (int)$cs->visualWidth($width), (int)$cs->visualHeight($height),
            1, 0, 0, $cs, $frag->children, $sourceRN,
            0, 0, false,
            $frag->type, $frag->content, $frag->dataset, $frag->pseudoStyles
        );
    }

    /**
     * 解析 OOF inset 值（left/right/top/bottom）。
     * 百分比基于包含块对应轴尺寸（CSS 2.2 §9.8.4）；其余直接 px。
     */
    private function resolveInset(?\Px\Css\CssLength $len, mixed $raw, int $base): int
    {
        if ($raw === null) return 0;
        if ($len !== null) {
            if ($len->isPercent() || $len->isCalc()) return (int)$len->resolveInContext($base);
            return (int)$len->toPx();
        }
        if (is_object($raw)) {
            if ($raw instanceof \Px\Css\CssLength) {
                return ($raw->isPercent() || $raw->isCalc()) ? (int)$raw->resolveInContext($base) : (int)$raw->toPx();
            }
            return (int)($raw->toPx() ?? 0);
        }
        return (int)$raw;
    }
}
