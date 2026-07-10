<?php
namespace Px\Rendering\Layout;
use native_types;
use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\ConstraintSpace;
use Px\Rendering\Layout\PhysicalFragment;
use Px\Rendering\Layout\LayoutInput;
use Px\Rendering\Layout\LayoutResult;
use Px\Rendering\Layout\IntrinsicSizes;

class TableAlgorithm extends LayoutAlgorithm
{
    private TableLayoutStrategy $strategy;
    public function __construct() { $this->strategy = new TableLayoutStrategy(); }

    public function layout(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = '', array $childNodes = [], array $childFragments = [], ?PhysicalFragment $inputFragment = null): PhysicalFragment
    {
        $childResults = [];
        foreach ($childFragments as $cf) {
            $childResults[] = new LayoutResult((int)$cf->x, (int)$cf->y, (int)$cf->w, (int)$cf->h, (int)$cf->visualW, (int)$cf->visualH, (int)$cf->layer, (int)$cf->contentWidth, (int)$cf->contentHeight, $cf->style);
        }
        $result = $this->strategy->layout(new LayoutInput(constraints: $space->toLegacy(), style: $style ?? new ComputedStyle([]), textContent: $textContent, childResults: $childResults, childNodes: $childNodes));
        return new PhysicalFragment((int)$result->x, (int)$result->y, (int)$result->w, (int)$result->h, (int)$result->visualW, (int)$result->visualH, (int)$result->layer, (int)$result->contentWidth, (int)$result->contentHeight, $result->style, $childResults, null);
    }

    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        $r = $this->strategy->layout(new LayoutInput(constraints: $space->toLegacy(), style: $style ?? new ComputedStyle([]), textContent: $textContent));
        return new IntrinsicSizes((int)$r->minContentWidth, (int)$r->maxContentWidth, (int)$r->minContentHeight, (int)$r->maxContentHeight);
    }
}
