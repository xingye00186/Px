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

    public function layout(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = '', array $childNodes = [], array $childFragments = [], ?PhysicalFragment $inputFragment = null, ?array $childConstraints = null, ?array $childIntrinsicSizes = null): PhysicalFragment
    {
        // OOF 算法不在 mainLayout 路径中直接使用
        // 它通过 Orchestrator 的 oofLayout() 遍历 Fragment 树调用
        throw new \RuntimeException('OOFLayoutAlgorithm::layout() should not be called directly. Use Orchestrator::oofLayout().');
    }

    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        // OOF 元素不影响内在尺寸
        return new IntrinsicSizes();
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
        $leftVal = (int)($cs->left?->toPx() ?? 0);
        $topVal = (int)($cs->top?->toPx() ?? 0);
        $rightVal = (int)($cs->right?->toPx() ?? 0);
        $bottomVal = (int)($cs->bottom?->toPx() ?? 0);

        $ancW = $ancestorW;
        $ancH = $ancestorH;
        $ancX = $ancestorX;
        $ancY = $ancestorY;
        $bL = $ancestorBorderLeft;
        $bT = $ancestorBorderTop;

        $width = (int)($cs->width?->toPx() ?? 0);
        $height = (int)($cs->height?->toPx() ?? 0);
        if ($cs->width !== null && $cs->width->isPercent()) $width = $cs->width->resolveInContext($ancW);
        if ($cs->height !== null && $cs->height->isPercent()) $height = $cs->height->resolveInContext($ancH);

        $marginLeft = (int)($cs->margin?->left->toPx() ?? 0);
        $marginTop = (int)($cs->margin?->top->toPx() ?? 0);
        $marginRight = (int)($cs->margin?->right->toPx() ?? 0);
        $marginBottom = (int)($cs->margin?->bottom->toPx() ?? 0);

        if ($leftVal !== 0 && $rightVal !== 0 && $width <= 0) {
            $width = max(0, $ancW - $leftVal - $rightVal - $marginLeft - $marginRight);
        }
        if ($topVal !== 0 && $bottomVal !== 0 && $height <= 0) {
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

        // Margin auto (simplified: only X-axis)
        $autoOffsetX = 0;
        if ($cs->margin !== null) {
            $mLAuto = $cs->margin->left->isAuto();
            $mRAuto = $cs->margin->right->isAuto();
            if ($mLAuto && $mRAuto) {
                $autoOffsetX = (int)(($ancW - $width) / 2);
            } elseif ($mRAuto) {
                $autoOffsetX = $ancW - $calcX - $width + $ancX;
            }
        }
        $calcX += $autoOffsetX;

        return new PhysicalFragment(
            (int)($calcX - $ancX), (int)($calcY - $ancY), (int)max(0, $width), (int)max(0, $height),
            (int)$cs->visualWidth($width), (int)$cs->visualHeight($height),
            1, 0, 0, $cs, $frag->children, $sourceRN,
            0, 0, false,
            $frag->type, $frag->content, $frag->dataset, $frag->pseudoStyles
        );
    }}
