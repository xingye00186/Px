<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\ComputedStyle;

/**
 * InlineLayoutStrategy — 内联格式化上下文 (IFC) 布局策略
 *
 * 处理 display:inline 元素的布局，严格遵循 CSS 2.2 §9.4.2 (IFC) 和 §10.8.1 (vertical-align)。
 *
 * 核心功能：
 * 1. 行框模型：收集行内子元素，按行分组形成 line boxes
 * 2. 自动换行：当行内内容宽度超过容器宽度时自动折行
 * 3. vertical-align：支持 baseline|top|middle|bottom 对齐
 * 4. 行高计算：每行高度由最高子元素决定
 */
class InlineLayoutStrategy implements LayoutStrategyInterface
{
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function resolve(
        RenderNode    $node,
        object        $ctx,
        array         $style
    ): void {
        // Fallback: create stub LayoutConstraints and FragmentBuilder
        $constraints = new LayoutConstraints(
            $ctx->parentX, $ctx->parentY,
            $node->w, $node->h,
            $ctx->parentX, $ctx->parentY,
            $node->parent !== null ? $node->parent->w : $node->w,
            $node->parent !== null ? $node->parent->h : $node->h
        );
        $builder = new FragmentBuilder();
        $this->resolveWithBuilder($node, $constraints, $node->computedStyle, $builder);
    }

    /**
     * Pure FragmentBuilder 布局入口。
     * 直接使用 LayoutConstraints + ComputedStyle。
     */
    public function resolveWithBuilder(
        RenderNode         $node,
        LayoutConstraints  $constraints,
        ?ComputedStyle     $style,
        FragmentBuilder    $builder
    ): void
    {
        // 直接使用 constraints 获取容器信息
        $parentX = $constraints->parentContentX;
        $parentY = $constraints->parentContentY;
        $parentW = $constraints->contentWidth;
        $parentH = $constraints->contentHeight;

        $left = $style?->left ?? 0;
        $top = $style?->top ?? 0;
        $fs = $style?->fontSize ?? 16;

        // 计算尺寸
        $w = 0;
        $h = 0;
        if ($style !== null) {
            $w = $style->width->toPx();
            $h = $style->height->toPx();
        }

        // ── Text content measurement ──
        if ($node->content !== null && is_string($node->content) && strlen($node->content) > 0) {
            $bd = $style?->bold ?? false;
            $measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width($node->content, $fs, $bd) : 0);
            if ($measured > 0 && $w <= 0) {
                $w = (int)$measured;
            }

            // Line height: use computedStyle->lineHeight if set, else font-size * 1.2
            $lh = $style?->lineHeight ?? 0;
            if ($lh <= 0) $lh = (int)($fs * 1.2);
            if ($h < $lh) $h = $lh;
        }

        // ── Layout inline children into line boxes ──
        if (count($node->children) > 0) {
            $this->layoutLineBoxes($node, $style, $parentW);
            // After line box layout, children positions are written to node
            // Read back for our dimensions
            $cursorY = 0;
            $maxLineW = 0;
            foreach ($node->children as $child) {
                $bottom = $child->y + $child->h - $parentY;
                if ($bottom > $cursorY) $cursorY = $bottom;
                $right = $child->x + $child->w - $parentX;
                if ($right > $maxLineW) $maxLineW = $right;
            }
            if ($cursorY > $h) $h = (int)$cursorY;
            if ($maxLineW > $w) $w = (int)$maxLineW;
        }

        // ── Position ──
        $pos = $style?->position?->value ?? 'static';
        $x = ($pos === 'static' || $pos === 'relative') ? $parentX + $left : 0;
        $y = ($pos === 'static' || $pos === 'relative') ? $parentY + $top : 0;

        $builder
            ->setPosition((int)$x, (int)$y)
            ->setSize((int)max(0, $w), (int)max(0, $h), $style)
            ->setLayer($node->layer);
    }

    /**
     * Layout children into line boxes (IFC line box model).
     *
     * CSS 2.2 §9.4.2:
     *   Inline elements are laid out in line boxes. When the total width of
     *   inline elements on a line exceeds the container width, they wrap to
     *   a new line. Each line box has a baseline for vertical alignment.
     */
    private function layoutLineBoxes(RenderNode $node, ?ComputedStyle $style, int $containerW): void
    {
        if ($containerW <= 0) {
            $containerW = 640;
        }

        $lines = [];
        $currentLine = [];
        $currentLineW = 0;

        $padL = $style?->padding?->left->toPx() ?? 0;
        $padR = $style?->padding?->right->toPx() ?? 0;
        $availW = $containerW - $padL - $padR;

        foreach ($node->children as $child) {
            $childW = (int)($child->visualW > 0 ? $child->visualW : $child->w);
            if ($childW <= 0) $childW = 1;

            if ($currentLineW + $childW > $availW && count($currentLine) > 0) {
                $lineH = 0;
                $lineBaseline = 0;
                foreach ($currentLine as $clChild) {
                    $ch = (int)($clChild->visualH > 0 ? $clChild->visualH : $clChild->h);
                    if ($ch > $lineH) $lineH = (int)$ch;
                    $cb = (int)($ch > 0 ? (int)($ch * 0.8) : 0);
                    if ($cb > $lineBaseline) $lineBaseline = (int)$cb;
                }
                $lines[] = ['children' => $currentLine, 'width' => $currentLineW, 'height' => $lineH, 'baseline' => $lineBaseline];
                $currentLine = [];
                $currentLineW = 0;
            }

            $currentLine[] = $child;
            $currentLineW += (int)$childW;
        }

        if (count($currentLine) > 0) {
            $lineH = 0;
            $lineBaseline = 0;
            foreach ($currentLine as $clChild) {
                $ch = (int)($clChild->visualH > 0 ? $clChild->visualH : $clChild->h);
                if ($ch > $lineH) $lineH = (int)$ch;
                $cb = (int)($ch > 0 ? (int)($ch * 0.8) : 0);
                if ($cb > $lineBaseline) $lineBaseline = (int)$cb;
            }
            $lines[] = ['children' => $currentLine, 'width' => $currentLineW, 'height' => $lineH, 'baseline' => $lineBaseline];
        }

        $cursorY = 0;
        $maxLineW = 0;

        foreach ($lines as $line) {
            $cursorX = $padL;
            $lineH = (int)($line['height']);
            $lineBaseline = (int)($line['baseline']);

            foreach ($line['children'] as $child) {
                $child->x = $node->x + $cursorX;
                $child->y = $node->y + $cursorY;

                $vaStyle = $child->computedStyle?->verticalAlign?->value ?? 'baseline';
                $childH = $child->visualH > 0 ? $child->visualH : $child->h;

                switch ($vaStyle) {
                    case 'top':
                        $child->y = $node->y + $cursorY;
                        break;
                    case 'bottom':
                        $child->y = $node->y + $cursorY + $lineH - $childH;
                        break;
                    case 'middle':
                        $child->y = $node->y + $cursorY + (int)(($lineH - $childH) / 2);
                        break;
                    default:
                        $childBaseline = $childH > 0 ? (int)($childH * 0.8) : 0;
                        $child->y = $node->y + $cursorY + ($lineBaseline - $childBaseline);
                        break;
                }

                $cursorX += (int)($child->visualW > 0 ? $child->visualW : $child->w);
            }

            $maxLineW = (int)max($maxLineW, $cursorX);
            $cursorY += (int)$lineH;
        }
    }
}
