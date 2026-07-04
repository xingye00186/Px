<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;

/**
 * InlineLayoutStrategy — 内联格式化上下文 (IFC) 布局策略
 *
 * Pure function 实现：layout(LayoutInput) → LayoutResult。
 * 不接收 RenderNode，不产生副作用。
 */
class InlineLayoutStrategy implements LayoutStrategyInterface
{
    public function layout(LayoutInput $input): LayoutResult
    {
        // Intrinsic measurement mode
        if ($input->constraints->isIntrinsicMeasurement) {
            $text = $input->textContent;
            $fs = $input->style->fontSize > 0 ? $input->style->fontSize : 16;
            $w = strlen($text) > 0 ? (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($text, $fs, $input->style->bold) : (int)(strlen($text) * $fs * 0.6)) : 0;
            $h = strlen($text) > 0 ? ($input->style->lineHeight > 0 ? $input->style->lineHeight : (int)($fs * 1.2)) : 0;
            return new LayoutResult(w: max(0, $w), h: max(0, $h), minContentWidth: max(0, $w), maxContentWidth: max(0, $w), preferredContentWidth: max(0, $w), minContentHeight: max(0, $h), maxContentHeight: max(0, $h), preferredContentHeight: max(0, $h));
        }

        $c = $input->constraints;
        $s = $input->style;
        $children = $input->childResults;
        $textContent = $input->textContent;

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        $parentW = $c->contentWidth;

        $x = $c->parentContentX + $left;
        $y = $c->parentContentY + $top;

        $w = $s->width->toPx();
        if ($w <= 0) $w = $parentW;
        $h = $s->height->toPx();
        if ($h <= 0 && strlen($textContent) > 0) {
            $h = $s->lineHeight > 0 ? $s->lineHeight : (int)($s->fontSize * 1.2);
        }

        // IFC: arrange children in a single line
        $stackedChildren = [];
        $cursorX = $x;
        foreach ($children as $cr) {
            $stackedChildren[] = new LayoutResult(
                x: $cursorX, y: $y,
                w: $cr->w, h: $cr->h,
                visualW: $cr->visualW, visualH: $cr->visualH,
                layer: $cr->layer,
                style: $cr->style,
                children: $cr->children,
            );
            $cursorX += $cr->w;
        }

        return new LayoutResult(
            x: $x, y: $y, w: $w, h: $h,
            visualW: $s->visualWidth($w),
            visualH: $s->visualHeight($h),
            style: $s,
            children: $stackedChildren,
        );
    }
}


