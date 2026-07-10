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
 * FlexAlgorithm — Flex 布局算法（Phase 1 适配器）
 */
class FlexAlgorithm extends LayoutAlgorithm
{
    private FlexLayoutStrategy $strategy;
    public function __construct() { $this->strategy = new FlexLayoutStrategy(); }

    public function layout(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = '', array $childNodes = [], array $childFragments = [], ?PhysicalFragment $inputFragment = null): PhysicalFragment
    {
        $result = $this->strategy->layout(new LayoutInput(constraints: $space->toLegacy(), style: $style ?? new \Px\Rendering\ComputedStyle([]), textContent: $textContent));
        return new PhysicalFragment((int)$result->x, (int)$result->y, (int)$result->w, (int)$result->h, (int)$result->visualW, (int)$result->visualH, (int)$result->layer, (int)$result->contentWidth, (int)$result->contentHeight, $result->style);
    }

    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        $r = $this->strategy->layout(new LayoutInput(constraints: $space->toLegacy(), style: $style ?? new \Px\Rendering\ComputedStyle([]), textContent: $textContent));
        return new IntrinsicSizes($r->minContentWidth, $r->maxContentWidth, $r->minContentHeight, $r->maxContentHeight);
    }
}
