<?php

namespace Px\Rendering;

use Px\ReactiveComponent;

/**
 * VNodeRenderer — VNode 树遍历渲染器
 *
 * 两阶段渲染:
 *   1. Walk: 收集所有需要绘制的元素 (按 layer 分组)
 *   2. Draw: 按 layer 顺序调用 ctx->drawElement()
 */
class VNodeRenderer
{
    private ReactiveComponent $component;
    private RenderContext $render_ctx;

    /** @var array Scroll context for offsetting children */
    private array $scrollCtxStack = [];

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
        if (!$node->isRoot()) {
            // v-if handled at compile time — false branches never in tree

            $el = $this->vnodeToElement($node);
            if ($el !== null) {
                $layer = $node->layer;
                if ($layer > $maxLayer) $maxLayer = $layer;
                if (!isset($elementsByLayer[$layer])) {
                    $elementsByLayer[$layer] = [];
                }
                $elementsByLayer[$layer][] = $el;
            }
        }

        $wasScrollPush = false;
        if ($node->isScrollContainer) {
            $this->scrollCtxStack[] = [
                'x' => $node->x, 'y' => $node->y,
                'w' => $node->w, 'h' => $node->h,
                'scrollTop' => $node->scrollTop,
            ];
            $wasScrollPush = true;
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
            array_pop($this->scrollCtxStack);
        }
    }

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
            $y = $y - $scrollCtx['scrollTop'];
            $containerY = $scrollCtx['y'];
            $containerH = $scrollCtx['h'];
            if ($y + $h <= $containerY || $y >= $containerY + $containerH) {
                return null;
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

    private function makeDivElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        if ($node->isScrollContainer) {
            return $this->makeScrollContainerElement($node, $style, $x, $y, $w, $h, $layer);
        }
        $bg = $style['bg'] ?? ($style['background'] ?? 0);
        if ($bg === 0 && !($style['borderWidth'] ?? false)) {
            return null;
        }
        return [
            'type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'color' => $bg, 'layer' => $layer, 'group_id' => $node->groupId,
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
        if ($bindKey !== '' && method_exists($this->component, 'getBindValue')) {
            $text = $this->component->getBindValue($bindKey);
        }
        $vModel = $node->props['v-model'] ?? '';
        if ($vModel !== '' && method_exists($this->component, 'getBindValue')) {
            $text = $this->component->getBindValue($vModel);
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
            'align' => $align, 'layer' => $layer, 'group_id' => $node->groupId,
        ];
    }

    private function makeButtonElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $bg     = $style['bg'] ?? 0x4488CC;
        $fg     = $style['fg'] ?? 0xFFFFFF;
        $border = $style['border'] ?? ($bg !== 0 ? ($bg & 0xFFFFFF) >> 1 : 0);

        $label = '';
        if (is_string($node->children)) {
            $label = $node->children;
        } elseif ($node->children instanceof VNode) {
            if ($node->children->type === 'span' || $node->children->type === '#text') {
                if (is_string($node->children->children)) {
                    $label = $node->children->children;
                }
                $spanBind = $node->children->props[':bind'] ?? $node->children->props['bind'] ?? '';
                if ($spanBind !== '' && method_exists($this->component, 'getBindValue')) {
                    $label = $this->component->getBindValue($spanBind);
                }
            }
        }
        $bindKey = $node->props[':bind'] ?? '';
        if ($bindKey !== '' && method_exists($this->component, 'getBindValue')) {
            $label = $this->component->getBindValue($bindKey);
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
            'bg' => $bg, 'fg' => $fg, 'border' => $border,
            'label' => $label, 'labelX' => $labelX, 'labelY' => $labelY,
            'labelFontSize' => $labelFontSize, 'layer' => $layer, 'group_id' => $node->groupId,
        ];
    }

    private function makeInputElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $bg     = $style['bg'] ?? 0x1E1E1E;
        $fg     = $style['fg'] ?? 0xFFFFFF;
        $fontSize = $style['fontSize'] ?? 16;
        $align  = $node->props['align'] ?? 'left';
        $placeholder = $node->props['placeholder'] ?? '';

        $bindKey = $node->props['v-model'] ?? '';
        $text = '';
        if ($bindKey !== '' && method_exists($this->component, 'getBindValue')) {
            $text = $this->component->getBindValue($bindKey);
        }

        return [
            'type' => 'textbox', 'bind' => $bindKey,
            'placeholder' => $placeholder, 'text' => $text,
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'align' => $align, 'fontSize' => $fontSize,
            'color' => $fg, 'bg' => $bg, 'cursor' => true,
            'layer' => $layer, 'group_id' => $node->groupId,
        ];
    }

    private function makeScrollContainerElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): array
    {
        $bg = $style['bg'] ?? 0x2D2D2D;
        $sbW = 12;
        $sbBg = 0x4A4A4A;
        $sbThumb = 0x888888;

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
            'bg' => $bg,
            'scrollbar-w' => $sbW, 'scrollbar-bg' => $sbBg, 'scrollbar-thumb' => $sbThumb,
            'content-height' => $contentH, 'scroll-top' => $node->scrollTop,
            'layer' => $layer, 'group_id' => $node->groupId,
        ];
    }
}