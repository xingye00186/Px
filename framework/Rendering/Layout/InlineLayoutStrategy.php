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


