<?php

namespace Px\Rendering;

use Px\ReactiveComponent;

/**
 * VNodeRenderer — VNode 树遍历渲染器
 *
 * 两阶段渲染:
 *   1. Walk: 收集所有需要绘制的元素 (按 layer 分组)
 *   2. Draw: 按 layer 顺序调用 ctx->drawElement()
 *
 * 设计原则:
 *   每个 VNode 生成一个元素描述。复杂类型 (button, input,
 *   scroll-container) 由 GdiRenderContext::drawElement 内部
 *   多次调用 GDI 原语完成绘制 — 不在此层分解为多个兄弟图元。
 *
 *   命中测试完全基于 VNode 树，不依赖元素列表。
 */
class VNodeRenderer
{
    private ReactiveComponent $component;
    private RenderContext $render_ctx;

    /** @var array Scroll context for offsetting children */
    private array $scrollCtxStack = [];

    /** @var array<ReactiveComponent> Stack for correct bind value context */
    private array $componentStack = [];

    public function __construct(ReactiveComponent $component, RenderContext $render_ctx)
    {
        $this->component = $component;
        $this->render_ctx = $render_ctx;
    }

    /**
     * 渲染 VNode 树
     */
    public function render(VNode $root): void
    {
        $this->render_ctx->beginFrame();

        $elementsByLayer = [];
        $maxLayer = 0;
        $this->collectElements($root, $elementsByLayer, $maxLayer);

        for ($l = 0; $l <= $maxLayer; $l++) {
            $layerElements = $elementsByLayer[$l] ?? [];
            foreach ($layerElements as $el) {
                $this->render_ctx->drawElement($el);
            }
        }

        $this->render_ctx->endFrame();
    }

    private function collectElements(VNode $node, array &$elementsByLayer, int &$maxLayer): void
    {
        // Push component context when entering a component boundary
        $pushedComponent = false;
        if ($node->isComponent() && $node->componentInstance !== null) {
            $this->componentStack[] = $node->componentInstance;
            $pushedComponent = true;
        }

        if (!$node->isRoot() && !$node->isComponent()) {
            $el = $this->vnodeToElement($node);
            if ($el !== null) {
                $layer = $node->layer;
                if ($layer > $maxLayer) $maxLayer = $layer;
                if (!isset($elementsByLayer[$layer])) {
                    $elementsByLayer[$layer] = [];
                }
                // Handle group type: expand children into their own layers
                if (($el['type'] ?? '') === 'group' && isset($el['elements'])) {
                    foreach ($el['elements'] as $childEl) {
                        $childLayer = $childEl['layer'] ?? $layer;
                        if ($childLayer > $maxLayer) $maxLayer = $childLayer;
                        if (!isset($elementsByLayer[$childLayer])) {
                            $elementsByLayer[$childLayer] = [];
                        }
                        $elementsByLayer[$childLayer][] = $childEl;
                    }
                } else {
                    $elementsByLayer[$layer][] = $el;
                }
            }
        }

        $wasScrollPush = false;
        if ($node->isScrollContainer) {
            $this->scrollCtxStack[] = [
                'x' => $node->x, 'y' => $node->y,
                'w' => $node->w, 'h' => $node->h,
                'scrollTop' => $node->scrollTop,
                'scrollLeft' => $node->scrollLeft,
                'overflowX' => $node->computedStyle['overflowX'] ?? $node->computedStyle['overflow'] ?? 'visible',
                'overflowY' => $node->computedStyle['overflowY'] ?? $node->computedStyle['overflow'] ?? 'visible',
                'layer' => $node->layer,
            ];
            $wasScrollPush = true;

            // Emit clip-push so children are clipped to the container bounds
            $layer = $node->layer;
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
            $elementsByLayer[$layer][] = [
                'type' => 'clip-push',
                'x' => $node->x, 'y' => $node->y, 'w' => $node->w, 'h' => $node->h,
                'layer' => $layer,
            ];
        }

        if ($node->type !== 'button') {
            if ($node->children instanceof VNode) {
                $this->collectElements($node->children, $elementsByLayer, $maxLayer);
            } elseif (is_array($node->children)) {
                foreach ($node->children as $child) {
                    if ($child instanceof VNode) {
                        $child = objval($child, VNode::class);
                        $this->collectElements($child, $elementsByLayer, $maxLayer);
                    }
                }
            }
        }

        if ($wasScrollPush) {
            $scrollCtx = array_pop($this->scrollCtxStack);

            // Emit clip-pop after children
            $layer = $scrollCtx['layer'];
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
            $elementsByLayer[$layer][] = [
                'type' => 'clip-pop',
                'layer' => $layer,
            ];

            // Emit scrollbar elements AFTER children + clip-pop so they draw on top
            $this->emitScrollbarElements($node, $scrollCtx, $elementsByLayer, $maxLayer);
        }

        // Pop component context when leaving a component boundary
        if ($pushedComponent) {
            array_pop($this->componentStack);
        }
    }

    /**
     * Emit scrollbar elements for a scroll container, after its children.
     * This ensures scrollbars are drawn on top of child elements.
     */
    private function emitScrollbarElements(VNode $node, array $scrollCtx, array &$elementsByLayer, int &$maxLayer): void
    {
        $layer = $scrollCtx['layer'];

        // ── 竖滚动条 ──
        $contentH = $node->contentHeight;
        if ($contentH > $node->h) {
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
            $elementsByLayer[$layer][] = [
                'type' => 'scrollbar-v',
                'x' => $node->x, 'y' => $node->y,
                'w' => $node->w, 'h' => $node->h,
                'contentHeight' => $contentH,
                'scrollTop' => $node->scrollTop,
                'layer' => $layer,
            ];
        }

        // ── 横滚动条 ──
        $contentW = $node->contentWidth;
        if ($contentW > $node->w) {
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
            $elementsByLayer[$layer][] = [
                'type' => 'scrollbar-h',
                'x' => $node->x, 'y' => $node->y,
                'w' => $node->w, 'h' => $node->h,
                'contentWidth' => $contentW,
                'scrollLeft' => $node->scrollLeft,
                'layer' => $layer,
            ];
        }
    }

    /**
     * 获取当前活跃的组件实例（用于解析 bind 值）。
     * 如果当前在子组件边界内，返回子组件实例；否则返回根组件。
     */
    private function currentComponent(): ReactiveComponent
    {
        $n = count($this->componentStack);
        return $n > 0 ? $this->componentStack[$n - 1] : $this->component;
    }

    /**
     * Convert a VNode to a single draw element descriptor.
     *
     * Complex types carry all data needed for GdiRenderContext
     * to make multiple GDI calls internally.
     *
     * @return ?array  element descriptor, or null if invisible
     */
    private function vnodeToElement(VNode $node): ?array
    {
        $style = $node->computedStyle;
        $x = $node->x;
        $y = $node->y;
        $w = $node->w;
        $h = $node->h;
        $layer = $node->layer;

        if (count($this->scrollCtxStack) > 0) {
            $scrollCtx = $this->scrollCtxStack[count($this->scrollCtxStack) - 1];
            // LayoutResolver 已将 scrollTop/scrollLeft 计入子节点位置，此处做裁切 + 坐标裁剪

            $containerX = $scrollCtx['x'];
            $containerY = $scrollCtx['y'];
            $containerW = $scrollCtx['w'];
            $containerH = $scrollCtx['h'];
            $overflowX = $scrollCtx['overflowX'];
            $overflowY = $scrollCtx['overflowY'];

            // Y-axis: cull if completely outside, clip if partially outside
            if ($overflowY !== 'visible') {
                if ($y + $h < $containerY || $y >= $containerY + $containerH) {
                    return null; // completely outside — skip
                }
                // Clip top — element starts above container
                if ($y < $containerY) {
                    $h -= ($containerY - $y);
                    $y = $containerY;
                }
                // Clip bottom — element ends below container
                if ($y + $h > $containerY + $containerH) {
                    $h = ($containerY + $containerH) - $y;
                }
            }

            // X-axis: cull if completely outside, clip if partially outside
            if ($overflowX !== 'visible') {
                if ($x + $w < $containerX || $x >= $containerX + $containerW) {
                    return null; // completely outside — skip
                }
                if ($x < $containerX) {
                    $w -= ($containerX - $x);
                    $x = $containerX;
                }
                if ($x + $w > $containerX + $containerW) {
                    $w = ($containerX + $containerW) - $x;
                }
            }
        }

        switch ($node->type) {
            case 'button':  return $this->makeButtonElement($node, $style, $x, $y, $w, $h, $layer);
            case 'input':   return $this->makeInputElement($node, $style, $x, $y, $w, $h, $layer);
            case 'span':    return $this->makeSpanElement($node, $style, $x, $y, $w, $h, $layer);
            case 'p':
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                            return $this->makeSpanElement($node, $style, $x, $y, $w, $h, $layer);
            case 'div':
            default:        return $this->makeDivElement($node, $style, $x, $y, $w, $h, $layer);
        }
    }

    // ──────────────────────────────────────────────
    //  Element builders — each returns ?array
    //  null → invisible (nothing to draw)
    //  array → one element descriptor per VNode
    // ──────────────────────────────────────────────

    private function makeDivElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        if ($node->isScrollContainer) {
            return $this->makeScrollContainerElement($node, $style, $x, $y, $w, $h, $layer);
        }
        // Workaround: if w/h is 0, try to get reasonable defaults
        if ($w <= 0) $w = 80;
        if ($h <= 0) $h = 32;

        $bg = $style['bg'] ?? null;
        $hasBorder = ($style['borderWidth'] ?? 0) > 0;
        $hasBg = $bg !== null;

        // Check for string children (text content)
        $hasTextChild = is_string($node->children) && $node->children !== '';
        if ($bg === null && !$hasBorder && !$hasTextChild) {
            return null;
        }

        $drawColor = ($bg !== null) ? $bg : 0;
        $borderRadius = $style['borderRadius'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;

        // If has text content, render text element
        if ($hasTextChild) {
            $fontSize = $style['fontSize'] ?? 14;
            $textColor = $style['fg'] ?? ($style['color'] ?? 0xFFFFFF);
            $bold = $style['bold'] ?? 0;
            $align = $node->props['align'] ?? ($style['textAlign'] ?? 'center');

            $text = $node->children;
            $textWidth = strlen($text) * (int)($fontSize * 0.6);

            // Center text horizontally and vertically
            $textX = $x + (int)(($w - $textWidth) / 2);
            if ($textX < $x + 4) $textX = $x + 4;
            $textY = $y + (int)(($h - $fontSize) / 2);

            // If has background, return both rect and text as elements
            if ($hasBg || $hasBorder) {
                return [
                    'type' => 'group', 'layer' => $layer,
                    'elements' => [
                        ['type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'color' => $drawColor, 'borderRadius' => $borderRadius, 'opacity' => $opacity, 'layer' => $layer],
                        ['type' => 'text', 'text' => $text, 'x' => $textX, 'y' => $textY,
                         'fontSize' => $fontSize, 'color' => $textColor, 'bold' => $bold, 'align' => $align, 'layer' => $layer + 1],
                    ],
                ];
            }

            // Text only, no background
            return [
                'type' => 'text', 'text' => $text, 'x' => $textX, 'y' => $textY,
                'fontSize' => $fontSize, 'color' => $textColor, 'bold' => $bold, 'align' => $align, 'layer' => $layer,
            ];
        }

        return [
            'type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'color' => $drawColor, 'borderRadius' => $borderRadius, 'opacity' => $opacity, 'layer' => $layer,
        ];
    }

    private function makeSpanElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $fontSize = $style['fontSize'] ?? 16;
        $color    = $style['fg'] ?? ($style['color'] ?? 0xFFFFFF);
        $bold     = $style['bold'] ?? 0;
        $align    = $node->props['align'] ?? ($style['textAlign'] ?? 'left');
        $text = '';

        if (is_string($node->children)) {
            $text = $node->children;
        }
        $bindKey = $node->props[':bind'] ?? '';
        if ($bindKey !== '') {
            $text = $this->currentComponent()->getBindValue($bindKey);
        }
        $vModel = $node->props['v-model'] ?? '';
        if ($vModel !== '') {
            $text = $this->currentComponent()->getBindValue($vModel);
        }

        if ($text === '') return null;

        $containerW = (int)($node->props['container-w'] ?? $w);
        $containerH = (int)($node->props['container-h'] ?? $h);
        $containerX = (int)($node->props['container-x'] ?? $x);

        if ($align === 'right' || $align === 'center') {
            $textWidth = strlen($text) * (int)($fontSize * 0.6);
            if ($align === 'right') {
                $x = $containerX + $containerW - 12 - $textWidth;
                if ($x < $containerX + 4) $x = $containerX + 4;
            } else {
                $x = $containerX + (int)(($containerW - $textWidth) / 2);
                if ($x < $containerX) $x = $containerX;
            }
            if ($containerH > $fontSize * 2) {
                $y = $y + (int)(($containerH - $fontSize) / 2);
            }
        }

        return [
            'type' => 'text', 'text' => $text,
            'x' => $x, 'y' => $y,
            'fontSize' => $fontSize, 'color' => $color, 'bold' => $bold,
            'align' => $align, 'layer' => $layer,
        ];
    }

    private function makeButtonElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        // Button must have valid dimensions
        if ($w <= 0 || $h <= 0) {
            // Auto-size: minimum 80x32 for buttons without explicit dimensions
            $w = $w <= 0 ? 80 : $w;
            $h = $h <= 0 ? 32 : $h;
        }

        $bg     = $style['bg'] ?? 0x4488CC;
        $fg     = $style['fg'] ?? 0xFFFFFF;
        $border = $style['border'] ?? ($bg !== 0 ? ($bg & 0xFFFFFF) >> 1 : 0);
        $borderRadius = $style['borderRadius'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;

        $label = '';
        if (is_string($node->children)) {
            $label = $node->children;
        } elseif ($node->children instanceof VNode) {
            if ($node->children->type === 'span' || $node->children->type === '#text') {
                if (is_string($node->children->children)) {
                    $label = $node->children->children;
                }
                $spanBind = $node->children->props[':bind'] ?? $node->children->props['bind'] ?? '';
                if ($spanBind !== '') {
                    $label = $this->currentComponent()->getBindValue($spanBind);
                }
            }
        }
        $bindKey = $node->props[':bind'] ?? '';
        if ($bindKey !== '') {
            $label = $this->currentComponent()->getBindValue($bindKey);
        }
        if ($label === '' && isset($node->props['@click'])) {
            $label = $node->props['label'] ?? '';
        }

        $labelFontSize = 22;
        $labelLen = strlen($label);
        $labelCharW = (int)($labelFontSize * 0.6);
        $labelX = $x + (int)(($w - $labelLen * $labelCharW) / 2);
        $labelY = $y + (int)(($h - $labelFontSize) / 2);

        return [
            'type' => 'button', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'bg' => $bg, 'fg' => $fg, 'border' => $border, 'borderRadius' => $borderRadius,
            'label' => $label, 'labelX' => $labelX, 'labelY' => $labelY,
            'labelFontSize' => $labelFontSize, 'opacity' => $opacity, 'layer' => $layer,
        ];
    }

    /**
     * Input element — 由 drawElement 内部绘制背景和文本。
     */
    private function makeInputElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $bg       = $style['bg'] ?? 0x1E1E1E;
        $fg       = $style['fg'] ?? 0xFFFFFF;
        $fontSize = $style['fontSize'] ?? 16;
        $borderRadius = $style['borderRadius'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;

        $bindKey = $node->props['v-model'] ?? '';
        $text = '';
        if ($bindKey !== '') {
            $text = $this->currentComponent()->getBindValue($bindKey);
        }

        return [
            'type' => 'input',
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'bg' => $bg, 'color' => $fg, 'fontSize' => $fontSize,
            'text' => $text, 'borderRadius' => $borderRadius, 'opacity' => $opacity, 'layer' => $layer,
        ];
    }

    /**
     * Scroll container — 由 drawElement 内部绘制背景和滚动条。
     *
     * 子节点已由 collectElements() 单独收集；
     * scrollCtxStack 在此提供裁切偏移。
     */
    private function makeScrollContainerElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $bg = $style['bg'] ?? 0x2D2D2D;
        $borderRadius = $style['borderRadius'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;

        $contentH = $node->contentHeight;
        if ($contentH === 0) {
            $childList = [];
            if ($node->children instanceof VNode) {
                $childList = [$node->children];
            } elseif (is_array($node->children)) {
                $childList = $node->children;
            }
            foreach ($childList as $child) {
                if ($child instanceof VNode) {
                    $child = objval($child, VNode::class);
                    $itemH = (int)($child->props['item-height'] ?? 0);
                    $contentH += max($child->h, $itemH);
                }
            }
        }

        return [
            'type' => 'scroll-container',
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'bg' => $bg, 'borderRadius' => $borderRadius,
            'contentHeight' => $contentH,
            'contentWidth' => $node->contentWidth,
            'scrollTop' => $node->scrollTop,
            'scrollLeft' => $node->scrollLeft,
            'opacity' => $opacity,
            'layer' => $layer,
        ];
    }
}
