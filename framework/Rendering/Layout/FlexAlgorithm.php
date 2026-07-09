<?php

namespace Px\Rendering\Layout;

use native_types;

/**
 * FlexAlgorithm — Flex 布局算法（Phase 1 适配器）
 */
class FlexAlgorithm extends LayoutAlgorithm
{
    private FlexLayoutStrategy $strategy;
    public function __construct() { $this->strategy = new FlexLayoutStrategy(); }

    public function layout(ConstraintSpace $space, ?PhysicalFragment $inputFragment = null): PhysicalFragment
    {
        return PhysicalFragment::fromLayoutResult(
            $this->strategy->layout(new LayoutInput(constraints: $space->toLegacy(), style: new \Px\Rendering\ComputedStyle([])))
        );
    }

    public function intrinsicSize(ConstraintSpace $space): IntrinsicSizes
    {
        $r = $this->strategy->layout(new LayoutInput(constraints: $space->toLegacy(), style: new \Px\Rendering\ComputedStyle([])));
        return new IntrinsicSizes($r->minContentWidth, $r->maxContentWidth, $r->minContentHeight, $r->maxContentHeight);
    }
}
