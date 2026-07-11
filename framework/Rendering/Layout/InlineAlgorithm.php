<?php
namespace Px\Rendering\Layout;
use native_types;
use Px\Rendering\ComputedStyle;

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
        ?PhysicalFragment $inputFragment = null
    ): PhysicalFragment {
        $s = $style ?? new ComputedStyle([]);
        $children = $childFragments;

        // Intrinsic measurement mode
        if ($space->getIsIntrinsicMeasurement()) {
            $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
            $w = strlen($textContent) > 0 ? (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($textContent, $fs, (int)($s->getBold() ?? 0)) : (int)(strlen($textContent) * $fs * 0.6)) : 0;
            $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
            return new PhysicalFragment((int)max(0, $w), (int)max(0, $h), 0, 0, null, null, 0, 0, 0, $s);
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
            $stackedChildren[] = new PhysicalFragment((int)$cursorX, (int)$y, (int)($cr->w ?? 0), (int)($cr->h ?? 0), null, null, (int)($cr->layer ?? 0), (int)($cr->contentWidth ?? 0), (int)($cr->contentHeight ?? 0), $cr->style, $cr->children, null);
            $cursorX += (int)($cr->w ?? 0);
        }

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h, $s->visualWidth($w), $s->visualHeight($h), 0, (int)$w, (int)$h, $s, $stackedChildren, null);
    }

    public function intrinsicSize(ConstraintSpace $space, ?ComputedStyle $style = null, string $textContent = ''): IntrinsicSizes
    {
        $s = $style ?? new ComputedStyle([]);
        $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
        $w = strlen($textContent) > 0 ? (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($textContent, $fs, (int)($s->getBold() ?? 0)) : (int)(strlen($textContent) * $fs * 0.6)) : 0;
        $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
        return new IntrinsicSizes((int)max(0, $w), (int)max(0, $w), (int)max(0, $h), (int)max(0, $h));
    }
}
