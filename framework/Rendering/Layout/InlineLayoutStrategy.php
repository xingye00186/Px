<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;
use Px\Rendering\Layout\Tools\PercentResolver;

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
        LayoutContext $ctx,
        array         $style
    ): void
    {
        $this->resolveInlineLayout($node, $ctx, $style);
    }

    /**
     * Resolve layout for a display:inline element.
     *
     * Inline elements are sized by their content (text measurement or child inline elements).
     * They participate in the parent's line box and do not create new block formatting contexts.
     */
    public function resolveInlineLayout(
        RenderNode    $node,
        LayoutContext $ctx,
        array         $style
    ): void
    {
        $left = (int)($style['left'] ?? 0);
        $top  = (int)($style['top'] ?? 0);

        // Containing block width from parent (for percentage resolution)
        $parentW_raw = (int)(($ctx->parent !== null) ? $ctx->parent->w : (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 0));
        $parentH     = (int)(($ctx->parent !== null) ? $ctx->parent->h : (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 0));
        if ($ctx->parent !== null) {
            $parentW = PercentResolver::resolveContentWidth($ctx->parent->style, $parentW_raw);
        } else {
            $parentW = (int)$parentW_raw;
        }

        $width  = PercentResolver::resolvePercent($style, 'width', 'widthPercent', $parentW);
        $height = PercentResolver::resolvePercent($style, 'height', 'heightPercent', $parentH);

        // Default parentFontSize for em unit resolution
        $parentFontSize = (int)($style['fontSize'] ?? 14);
        $rootFontSize = 16; // default root font-size
        $viewportW = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 1920;
        $viewportH = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 1080;

        // Resolve relative units (em/rem/vw/vh)
        if (isset($style['widthUnit'])) {
            $parts = explode('|', $style['widthUnit']);
            $width = (int)CssValueParser::resolveRelativeLength(
                (float)$parts[0], $parts[1],
                $parentFontSize, $rootFontSize, $viewportW, $viewportH
            );
        }

        $node->w = (int)max(0, PercentResolver::resolveMinMax($style, $width, true));
        $node->h = (int)max(0, PercentResolver::resolveMinMax($style, $height, false));
        $node->visualW = (int)PercentResolver::resolveVisualW($style, $node->w);
        $node->visualH = (int)PercentResolver::resolveVisualH($style, $node->h);

        // ── Text content measurement ──
        if ($node->content !== null && is_string($node->content) && strlen($node->content) > 0) {
            $fs = (int)($style['fontSize'] ?? 14);
            $bd = ($style['bold'] ?? 0) !== 0 || ($style['fontWeight'] ?? 'normal') === 'bold';
            $parentFontSize = $fs;

            $measured = PercentResolver::resolveTextWidth($node->content, $fs, $bd);
            if ($measured > 0) {
                $node->w = (int)min($measured, max(0, PercentResolver::resolveMinMax($style, $measured, true)));
                $node->visualW = (int)PercentResolver::resolveVisualW($style, $node->w);
            }

            // Line height
            $lineH = (int)PercentResolver::resolveLineHeight($style, $fs);
            if ($node->h === 0 || $node->h < $lineH) {
                $node->h = (int)$lineH;
                $node->visualH = (int)PercentResolver::resolveVisualH($style, $node->h);
            }
        }

        // ── Layout inline children into line boxes ──
        if (count($node->children) > 0) {
            $this->layoutLineBoxes($node, $style, $parentW);
        }

        // ── Position in normal flow (stacked by parent) ──
        $position = $style['position'] ?? 'static';
        if ($position === 'static' || $position === 'relative') {
            $node->x = $ctx->parentX + $left;
            $node->y = $ctx->parentY + $top;
        }
    }

    /**
     * Layout children into line boxes (IFC line box model).
     *
     * CSS 2.2 §9.4.2:
     *   Inline elements are laid out in line boxes. When the total width of
     *   inline elements on a line exceeds the container width, they wrap to
     *   a new line. Each line box has a baseline for vertical alignment.
     *
     * @param RenderNode $node       The inline container node
     * @param array      $style      Effective style
     * @param int        $containerW Available content width for line boxes
     */
    private function layoutLineBoxes(RenderNode $node, array $style, int $containerW): void
    {
        if ($containerW <= 0) {
            $containerW = 640; // fallback default
        }

        // Capability: get viewport dimensions for vw/vh resolution
        $viewportW = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 1920;
        $viewportH = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 1080;
        $rootFontSize = 16; // default root font-size

        // Step 1: Resolve all children and collect into line boxes
        $lines = []; // Array of ['children' => RenderNode[], 'width' => int, 'height' => int, 'baseline' => int]

        /** @var RenderNode[] $currentLine */
        $currentLine = [];
        $currentLineW = 0;
        $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
        $paddingRight = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);
        $availW = $containerW - $paddingLeft - $paddingRight;

        foreach ($node->children as $child) {
            // Resolve child layout (recursive)
            $childCtx = new LayoutContext(0, 0, $node);
            $this->resolver->resolveNode($child, $childCtx);

            $childW = (int)($child->visualW);
            if ($childW <= 0) {
                $childW = (int)($child->w);
            }
            if ($childW <= 0) {
                $childW = 1; // Minimum width to make progress
            }

            // Check if wrapping is needed (CSS 2.2 §9.4.2: line breaking)
            if ($currentLineW + $childW > $availW && count($currentLine) > 0) {
                // Finalize current line
                $lineH = 0;
                $lineBaseline = 0;
                foreach ($currentLine as $clChild) {
                    $ch = (int)($clChild->visualH > 0 ? $clChild->visualH : $clChild->h);
                    if ($ch > $lineH) {
                        $lineH = (int)$ch;
                    }
                    // Baseline: approximated as 80% from top for text content
                    $childBaseline = (int)($ch > 0 ? (int)($ch * 0.8) : 0);
                    if ($childBaseline > $lineBaseline) {
                        $lineBaseline = (int)$childBaseline;
                    }
                }

                $lines[] = [
                    'children' => $currentLine,
                    'width'    => $currentLineW,
                    'height'   => $lineH,
                    'baseline' => $lineBaseline,
                ];

                $currentLine = [];
                $currentLineW = 0;
            }

            $currentLine[] = $child;
            $currentLineW += (int)$childW;
        }

        // Finalize last line
        if (count($currentLine) > 0) {
            $lineH = 0;
            $lineBaseline = 0;
            foreach ($currentLine as $clChild) {
                $ch = (int)($clChild->visualH > 0 ? $clChild->visualH : $clChild->h);
                if ($ch > $lineH) {
                    $lineH = (int)$ch;
                }
                $childBaseline = (int)($ch > 0 ? (int)($ch * 0.8) : 0);
                if ($childBaseline > $lineBaseline) {
                    $lineBaseline = (int)$childBaseline;
                }
            }
            $lines[] = [
                'children' => $currentLine,
                'width'    => $currentLineW,
                'height'   => $lineH,
                'baseline' => $lineBaseline,
            ];
        }

        // Step 2: Position children within their line boxes applying vertical-align
        $cursorY = 0;
        $maxLineW = 0;

        foreach ($lines as $line) {
            $cursorX = $paddingLeft;
            $lineH = (int)($line['height']);
            $lineBaseline = (int)($line['baseline']);

            foreach ($line['children'] as $child) {
                $child->x = $node->x + $cursorX;
                $child->y = $node->y + $cursorY;

                // Apply vertical-align (§10.8.1)
                $va = $child->style['verticalAlign'] ?? 'baseline';
                $childH = $child->visualH > 0 ? $child->visualH : $child->h;

                switch ($va) {
                    case 'top':
                        // Align top of child with top of line box
                        $child->y = $node->y + $cursorY;
                        break;

                    case 'bottom':
                        // Align bottom of child with bottom of line box
                        $child->y = $node->y + $cursorY + $lineH - $childH;
                        break;

                    case 'middle':
                        // Align middle of child with middle of line box (approximate)
                        $child->y = $node->y + $cursorY + (int)(($lineH - $childH) / 2);
                        break;

                    case 'baseline':
                    default:
                        // Baseline alignment: child's baseline aligns with line's baseline
                        $childBaseline = $childH > 0 ? (int)($childH * 0.8) : 0;
                        $child->y = $node->y + $cursorY + ($lineBaseline - $childBaseline);
                        break;
                }

                $cursorX += (int)($child->visualW > 0 ? $child->visualW : $child->w);
            }

            $maxLineW = (int)max($maxLineW, $cursorX);
            $cursorY += (int)$lineH;
        }

        // Update node height to encompass all lines
        if ($cursorY > $node->h) {
            $node->h = (int)$cursorY;
            $node->visualH = (int)PercentResolver::resolveVisualH($style, $node->h);
        }

        // Update node width to the maximum line width
        if ($maxLineW > $node->w) {
            $node->w = (int)$maxLineW;
            $node->visualW = (int)PercentResolver::resolveVisualW($style, $node->w);
        }
    }
}
