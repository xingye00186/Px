<?php

namespace Px\Rendering\Layout;

use native_types;

class GridAlgorithm extends LayoutAlgorithm
{
    private GridLayoutStrategy $strategy;
    public function __construct() { $this->strategy = new GridLayoutStrategy(); }
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
