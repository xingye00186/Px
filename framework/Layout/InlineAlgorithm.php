<?php
namespace Px\Layout;
use native_types;
use Px\Css\ComputedStyle;

/**
 * InlineAlgorithm — 内联格式化上下文 (IFC) 布局算法
 *
 * 取代 InlineLayoutStrategy，完全自包含。
 * 将子节点单行水平排列（CSS §9.4.2 Inline formatting context）。
 */
class InlineAlgorithm extends LayoutAlgorithm
{
    public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        array $childFragments = [],
        ?PhysicalFragment $inputFragment = null,
        ?array $childConstraints = null,
        ?array $childIntrinsicSizes = null,
    ): PhysicalFragment {
        $s = $style ?? \Px\Css\StylePool::empty();
        $children = $childFragments;

        // Intrinsic measurement mode
        if ($space->getIsIntrinsicMeasurement()) {
            $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
            $w = strlen($textContent) > 0 ? TextMeasureCache::measure($textContent, $fs, (bool)($s->getBold() ?? false)) : 0;
            $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
            return new PhysicalFragment((int)max(0, $w), (int)max(0, $h), 0, 0, null, null, 0, 0, 0, $s, [], null, 0, 0, false, '', null, [], [], 0, (int)max(0, $w));
        }

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $x = ($space->bfcOffsetX ?? 0) + $left;
        $y = ($space->bfcOffsetY ?? 0) + $top;

        $w = $s->width?->toPx() ?? 0;
        if ($w <= 0) $w = (int)($space->getContentWidth() ?? 0);
        $h = $s->height?->toPx() ?? 0;
        if (strlen($textContent) > 0 && (int)($h ?? 0) <= 0) {
            $h = ((int)($s->getLineHeight() ?? 0) > 0) ? (int)$s->getLineHeight() : (int)($s->getFontSize() * 1.2);
        }

        // IFC: arrange children in a single line
        $stackedChildren = [];
        $cursorX = $x;
        foreach ($children as $cr) {
            $stackedChildren[] = new PhysicalFragment(
                (int)$cursorX, (int)$y,
                (int)($cr->getW() ?? 0), (int)($cr->getH() ?? 0),
                (int)($cr->getVisualW() ?? $cr->getW() ?? 0),
                (int)($cr->getVisualH() ?? $cr->getH() ?? 0),
                (int)($cr->getLayer() ?? 0),
                (int)($cr->getContentWidth() ?? 0),
                (int)($cr->getContentHeight() ?? 0),
                $cr->style, $cr->children, $cr->sourceNode,
                $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles
            );
            $cursorX += (int)($cr->w ?? 0);
        }

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h, $s->visualWidth($w), $s->visualHeight($h), 0, (int)$w, (int)$h, $s, $stackedChildren, null);
    }

    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        $s = $style ?? \Px\Css\StylePool::empty();
        $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
        $w = strlen($textContent) > 0 ? TextMeasureCache::measure($textContent, $fs, (bool)($s->getBold() ?? false)) : 0;
        $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
        return new IntrinsicSizes((int)max(0, $w), (int)max(0, $w), (int)max(0, $h), (int)max(0, $h));
    }
}
