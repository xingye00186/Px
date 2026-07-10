<?php

namespace Px\Rendering\Layout;

use native_types;
use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\ConstraintSpace;
use Px\Rendering\Layout\PhysicalFragment;
use Px\Rendering\Layout\LayoutInput;
use Px\Rendering\Layout\LayoutResult;
use Px\Rendering\Layout\IntrinsicSizes;

/**
 * BlockAlgorithm — Block 布局算法（Phase 1 适配器）
 *
 * 内部委托到 BlockLayoutStrategy，通过 LayoutInput 桥接。
 */
class BlockAlgorithm extends LayoutAlgorithm
{
    private BlockLayoutStrategy $strategy;
    public function __construct() { $this->strategy = new BlockLayoutStrategy(); }

    public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        array $childFragments = [],
        ?PhysicalFragment $inputFragment = null
    ): PhysicalFragment {
        $childResults = [];
        foreach ($childFragments as $cf) {
            $childResults[] = new LayoutResult(
                (int)$cf->x, (int)$cf->y, (int)$cf->w, (int)$cf->h,
                (int)$cf->visualW, (int)$cf->visualH, (int)$cf->layer,
                (int)$cf->contentWidth, (int)$cf->contentHeight, $cf->style
            );
        }
        $input = new LayoutInput(
            constraints: $space->toLegacy(),
            style: $style ?? new ComputedStyle([]),
            textContent: $textContent,
            childResults: $childResults,
            childNodes: $childNodes,
        );
        $result = $this->strategy->layout($input);
        $resultChildren = [];
        foreach ($result->children as $i => $ch) {
            $resultChildren[] = new PhysicalFragment(
                (int)$ch->x, (int)$ch->y, (int)$ch->w, (int)$ch->h,
                (int)$ch->visualW, (int)$ch->visualH, (int)$ch->layer,
                (int)$ch->contentWidth, (int)$ch->contentHeight, $ch->style,
                [], null
            );
        }
        return new PhysicalFragment(
            (int)$result->x, (int)$result->y, (int)$result->w, (int)$result->h,
            (int)$result->visualW, (int)$result->visualH, (int)$result->layer,
            (int)$result->contentWidth, (int)$result->contentHeight, $result->style,
            $resultChildren, null
        );
    }

    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        $input = new LayoutInput(
            constraints: $space->toLegacy(),
            style: $style ?? new ComputedStyle([]),
            textContent: $textContent,
        );
        $result = $this->strategy->layout($input);
        return new IntrinsicSizes(
            (int)$result->minContentWidth, (int)$result->maxContentWidth,
            (int)$result->minContentHeight, (int)$result->maxContentHeight
        );
    }
}
