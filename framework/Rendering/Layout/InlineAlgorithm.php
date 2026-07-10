<?php

namespace Px\Rendering\Layout;

use native_types;
use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\ConstraintSpace;
use Px\Rendering\Layout\PhysicalFragment;
use Px\Rendering\Layout\LayoutInput;
use Px\Rendering\Layout\LayoutResult;
use Px\Rendering\Layout\IntrinsicSizes;

class InlineAlgorithm extends LayoutAlgorithm
{
    private InlineLayoutStrategy $strategy;
    public function __construct() { $this->strategy = new InlineLayoutStrategy(); }
    public function layout(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = '', array $childNodes = [], array $childFragments = [], ?PhysicalFragment $inputFragment = null): PhysicalFragment
    {
        return PhysicalFragment::fromLayoutResult(
            $this->strategy->layout(new LayoutInput(constraints: $space->toLegacy(), style: $style ?? new \Px\Rendering\ComputedStyle([]), textContent: $textContent))
        );
    }
    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        $r = $this->strategy->layout(new LayoutInput(constraints: $space->toLegacy(), style: $style ?? new \Px\Rendering\ComputedStyle([]), textContent: $textContent));
        return new IntrinsicSizes($r->minContentWidth, $r->maxContentWidth, $r->minContentHeight, $r->maxContentHeight);
    }
}
