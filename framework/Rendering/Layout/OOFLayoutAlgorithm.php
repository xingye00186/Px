<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;

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
    private AbsolutePositioning $absolutePositioning;

    public function __construct()
    {
        $this->absolutePositioning = new AbsolutePositioning();
    }

    public function layout(ConstraintSpace $space, ?PhysicalFragment $inputFragment = null): PhysicalFragment
    {
        // OOF 算法不在 mainLayout 路径中直接使用
        // 它通过 Orchestrator 的 oofLayout() 遍历 Fragment 树调用
        throw new \RuntimeException('OOFLayoutAlgorithm::layout() should not be called directly. Use Orchestrator::oofLayout().');
    }

    public function intrinsicSize(ConstraintSpace $space): IntrinsicSizes
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
        $isPositioned = ($position !== 'static' && $position !== 'relative');

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
            // 包含块 = padding box = content box - padding
            $cbX = $frag->x;
            $cbY = $frag->y;
            $cbW = $frag->w;
            $cbH = $frag->h;
            // padding box 需要加 padding/border
            $cbBL = $cs?->borderLeftWidth ?? 0;
            $cbBT = $cs?->borderTopWidth ?? 0;
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
        return new PhysicalFragment(
            x: $frag->x, y: $frag->y,
            w: $frag->w, h: $frag->h,
            visualW: $frag->visualW, visualH: $frag->visualH,
            layer: $frag->layer,
            contentWidth: $frag->contentWidth,
            contentHeight: $frag->contentHeight,
            style: $frag->style,
            children: $newChildren,
            sourceNode: $sourceRN,
            scrollTop: $frag->scrollTop,
            scrollLeft: $frag->scrollLeft,
            isScrollContainer: $frag->isScrollContainer,
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

        // 构造 LayoutInput 以复用现有 AbsolutePositioning
        $input = new LayoutInput(
            constraints: new LayoutConstraints(
                containerWidth: $ancestorW,
                containerHeight: $ancestorH,
                contentWidth: $ancestorW,
                contentHeight: $ancestorH,
            ),
            style: $cs,
            textContent: $sourceRN->content ?? '',
            position: $cs->position?->value ?? 'absolute',
            ancestorX: $ancestorX,
            ancestorY: $ancestorY,
            ancestorW: $ancestorW,
            ancestorH: $ancestorH,
            ancestorBorderLeft: $ancestorBorderLeft,
            ancestorBorderTop: $ancestorBorderTop,
            ancestorPaddingLeft: $ancestorPaddingLeft,
            ancestorPaddingTop: $ancestorPaddingTop,
            viewportW: $viewportW,
            viewportH: $viewportH,
            childResults: [],
            childNodes: $sourceRN->children,
        );

        $result = $this->absolutePositioning->absoluteLayout($input);

        return new PhysicalFragment(
            x: $result->x, y: $result->y,
            w: $result->w, h: $result->h,
            visualW: $result->visualW, visualH: $result->visualH,
            layer: $result->layer,
            contentWidth: $result->contentWidth,
            contentHeight: $result->contentHeight,
            style: $cs,
            children: $frag->children,
            sourceNode: $sourceRN,
        );
    }
}
