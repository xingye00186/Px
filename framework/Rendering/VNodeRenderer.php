<?php

namespace Px\Rendering;

use native_types;

use Px\ReactiveComponent;

/**
 * VNodeRenderer — RenderNode 树遍历渲染器
 *
 * 两阶段渲染:
 *   1. Walk: 收集所有需要绘制的元素 (按 layer 分组)
 *   2. Draw: 按 layer 顺序调用 ctx->drawElement()
 *
 * 设计原则:
 *   每个 RenderNode 生成一个元素描述。复杂类型 (button, input,
 *   scroll-container) 由 GdiRenderContext::drawElement 内部
 *   多次调用 GDI 原语完成绘制 — 不在此层分解为多个兄弟图元。
 *
 *   命中测试基于 RenderNode 树（由 RenderTreeManager 提供），
 *   不依赖元素列表。
 *
 * 增量绘制:
 *   使用 $currentPaintFrame 帧号 + RenderNode::needsPaint/markPainted
 *   判断节点是否需要重新生成元素描述。
 */
class VNodeRenderer
{
    private ReactiveComponent $component;
    private RenderContext $render_ctx;

    /** @var int 当前绘制帧号，递增以避免全量重置 */
    private int $currentPaintFrame = 0;

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
     * 渲染 RenderNode 树
     */
    public function render(RenderNode $root): void
    {
        \PerfCounter::start('render_collect');
        $this->render_ctx->beginFrame();

        // 帧号溢出保护
        if ($this->currentPaintFrame === PHP_INT_MAX) {
            $this->currentPaintFrame = 1;
            $this->resetAllPaintFlags($root);
        } else {
            $this->currentPaintFrame++;
        }

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
        \PerfCounter::end('render_collect');
    }

    /**
     * 递归收集需要绘制的元素。
     * 使用 needsPaint + markPainted 实现增量绘制。
     */
    private function collectElements(RenderNode $node, array &$elementsByLayer, int &$maxLayer): void
    {
        // 增量绘制：如果节点不需要绘制，跳过但继续处理子节点
        if (!$node->needsPaint($this->currentPaintFrame)) {
            foreach ($node->children as $child) {
                $this->collectElements($child, $elementsByLayer, $maxLayer);
            }
            return;
        }

        file_put_contents('D:\\Px\\_debug_out.txt', "collectElements: type={$node->type} needsPaint=true x={$node->x} y={$node->y} w={$node->w} h={$node->h}\n", FILE_APPEND);

        // #root 不产生渲染元素，直接处理子节点
        if ($node->type === '#root') {
            foreach ($node->children as $child) {
                $this->collectElements($child, $elementsByLayer, $maxLayer);
            }
            return;
        }

        // 普通元素节点：生成元素描述
        $el = $this->renderNodeToElement($node);
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

        // ── 裁切区域处理（clip-push / clip-pop）──
        // 滚动容器（overflow:auto/scroll）和 overflow:hidden 都需要裁切
        $pushedClip = false;
        $isScrollNode = $node->isScrollContainer;

        if ($isScrollNode) {
            $this->scrollCtxStack[] = [
                'x' => $node->x, 'y' => $node->y,
                'w' => $node->w, 'h' => $node->h,
                'scrollTop' => $node->scrollTop,
                'scrollLeft' => $node->scrollLeft,
                'overflowX' => $node->style['overflowX'] ?? $node->style['overflow'] ?? 'visible',
                'overflowY' => $node->style['overflowY'] ?? $node->style['overflow'] ?? 'visible',
                'layer' => $node->layer,
            ];
            $pushedClip = true;
        } else {
            // 非滚动容器：overflow:hidden 也需要裁切子元素
            $noX = $node->style['overflowX'] ?? $node->style['overflow'] ?? 'visible';
            $noY = $node->style['overflowY'] ?? $node->style['overflow'] ?? 'visible';
            if ($noX === 'hidden' || $noY === 'hidden') {
                $pushedClip = true;
            }
        }

        if ($pushedClip) {
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

        // 递归处理子节点（button 类型不展开，由 GDI 层绘制）
        if ($node->type !== 'button') {
            foreach ($node->children as $child) {
                $this->collectElements($child, $elementsByLayer, $maxLayer);
            }
        }

        if ($pushedClip) {
            if ($isScrollNode) {
                array_pop($this->scrollCtxStack);
            }

            $layer = $node->layer;
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
            $elementsByLayer[$layer][] = [
                'type' => 'clip-pop',
                'layer' => $layer,
            ];

            // 滚动容器还需在 clip-pop 之后绘制滚动条（确保在顶层）
            if ($isScrollNode) {
                $scrollCtx = ['layer' => $node->layer];
                $this->emitScrollbarElements($node, $scrollCtx, $elementsByLayer, $maxLayer);
            }
        }

        // 标记节点为已绘制
        $node->markPainted($this->currentPaintFrame);
    }

    /**
     * Emit scrollbar elements for a scroll container, after its children.
     */
    private function emitScrollbarElements(RenderNode $node, array $scrollCtx, array &$elementsByLayer, int &$maxLayer): void
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
     * RenderNode 树无 #component 节点，故始终返回根组件。
     */
    private function currentComponent(): ReactiveComponent
    {
        $n = count($this->componentStack);
        return $n > 0 ? $this->componentStack[$n - 1] : $this->component;
    }

    /**
     * Convert a RenderNode to a single draw element descriptor.
     *
     * @return ?array element descriptor, or null if invisible
     */
    private function renderNodeToElement(RenderNode $node): ?array
    {
        $style = $node->style;
        $x = $node->x;
        $y = $node->y;
        $w = $node->w;
        $h = $node->h;
        $layer = $node->layer;

        // 滚动裁切
        if (count($this->scrollCtxStack) > 0) {
            $scrollCtx = $this->scrollCtxStack[count($this->scrollCtxStack) - 1];
            $containerX = $scrollCtx['x'];
            $containerY = $scrollCtx['y'];
            $containerW = $scrollCtx['w'];
            $containerH = $scrollCtx['h'];
            $overflowX = $scrollCtx['overflowX'];
            $overflowY = $scrollCtx['overflowY'];

            // Y-axis: cull if completely outside, clip if partially outside
            if ($overflowY !== 'visible') {
                if ($y + $h < $containerY || $y >= $containerY + $containerH) {
                    return null;
                }
                if ($y < $containerY) {
                    $h -= ($containerY - $y);
                    $y = (int)$containerY;
                }
                if ($y + $h > $containerY + $containerH) {
                    $h = ($containerY + $containerH) - $y;
                }
            }

            // X-axis
            if ($overflowX !== 'visible') {
                if ($x + $w < $containerX || $x >= $containerX + $containerW) {
                    return null;
                }
                if ($x < $containerX) {
                    $w -= ($containerX - $x);
                    $x = (int)$containerX;
                }
                if ($x + $w > $containerX + $containerW) {
                    $w = ($containerX + $containerW) - $x;
                }
            }
        }

        // 通过 sourceVNode 访问 props（bind 值、事件处理器等）
        $props = [];
        if ($node->sourceVNode !== null && $node->sourceVNode->props !== null) {
            $props = $node->sourceVNode->props;
        }

        switch ($node->type) {
            case 'button':
                file_put_contents('D:\\Px\\_debug_out.txt', "renderNodeToElement: type=button x={$x} y={$y} w={$w} h={$h}\n", FILE_APPEND);
                return $this->makeButtonElement($node, $style, $props, $x, $y, $w, $h, $layer);
            case 'input':   return $this->makeInputElement($node, $style, $props, $x, $y, $w, $h, $layer);
            case 'img':     return $this->makeImgElement($node, $style, $props, $x, $y, $w, $h, $layer);
            case 'span':
                file_put_contents('D:\\Px\\_debug_out.txt', "renderNodeToElement: type=span x={$x} y={$y} w={$w} h={$h} content_null=" . (int)($node->content===null) . "\n", FILE_APPEND);
                return $this->makeSpanElement($node, $style, $props, $x, $y, $w, $h, $layer);
            case 'p':
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                            return $this->makeSpanElement($node, $style, $props, $x, $y, $w, $h, $layer);
            case 'div':
            default:        return $this->makeDivElement($node, $style, $props, $x, $y, $w, $h, $layer);
        }
    }

    // ──────────────────────────────────────────────
    //  Element builders — each returns ?array
    //  null → invisible (nothing to draw)
    // ──────────────────────────────────────────────

    private function makeDivElement(RenderNode $node, array $style, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $cursor = $style['cursor'] ?? '';
        if ($node->isScrollContainer) {
            return $this->makeScrollContainerElement($node, $style, $x, $y, $w, $h, $layer);
        }
        if ($w <= 0) $w = 80;
        if ($h <= 0) $h = 32;

        $bg = $style['bg'] ?? null;
        $hasBorder = ($style['borderWidth'] ?? 0) > 0;
        $hasBg = $bg !== null;

        // Check for content (text or children)
        $hasTextChild = is_string($node->content) && $node->content !== '';
        if ($bg === null && !$hasBorder && !$hasTextChild) {
            return null;
        }

        $drawColor = ($bg !== null) ? $bg : 0;
        $borderRadius = $style['borderRadius'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;
        $boxShadow = $style['boxShadow'] ?? '';
        $shadowX = 0; $shadowY = 0; $shadowColor = 0;
        if ($boxShadow !== '') {
            $parts = explode('|', $boxShadow);
            $shadowX = (int)($parts[0] ?? 0);
            $shadowY = (int)($parts[1] ?? 0);
            $shadowColor = CssMappings::hexToBgr($parts[4] ?? '#000000');
        }
        $borderWidth = $style['borderWidth'] ?? 0;
        $borderColor = $style['borderColor'] ?? 0;

        if ($hasTextChild) {
            $fontSize = $style['fontSize'] ?? 14;
            $textColor = $style['fg'] ?? ($style['color'] ?? 0xFFFFFF);
            $bold = $style['bold'] ?? 0;
            $align = $props['align'] ?? ($style['textAlign'] ?? 'center');

            $text = $node->content;
            $textWidth = strlen($text) * (int)($fontSize * 0.6);

            $textX = $x + (int)(($w - $textWidth) / 2);
            if ($textX < $x + 4) $textX = $x + 4;
            $textY = $y + (int)(($h - $fontSize) / 2);

            if ($hasBg || $hasBorder) {
                return [
                    'type' => 'group', 'layer' => $layer, 'cursor' => $cursor,
                    'elements' => [
                        ['type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'color' => $drawColor, 'borderRadius' => $borderRadius, 'opacity' => $opacity, 'layer' => $layer, 'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowColor' => $shadowColor, 'borderWidth' => $borderWidth, 'borderColor' => $borderColor, 'cursor' => $cursor],
                        ['type' => 'text', 'text' => $text, 'x' => $textX, 'y' => $textY,
                         'fontSize' => $fontSize, 'color' => $textColor, 'bold' => $bold, 'align' => $align, 'layer' => $layer + 1, 'cursor' => $cursor],
                    ],
                ];
            }

            return [
                'type' => 'text', 'text' => $text, 'x' => $textX, 'y' => $textY,
                'fontSize' => $fontSize, 'color' => $textColor, 'bold' => $bold, 'align' => $align, 'layer' => $layer, 'cursor' => $cursor,
            ];
        }

        return [
            'type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'color' => $drawColor, 'borderRadius' => $borderRadius, 'opacity' => $opacity, 'layer' => $layer,
            'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowColor' => $shadowColor,
            'borderWidth' => $borderWidth, 'borderColor' => $borderColor, 'cursor' => $cursor,
        ];
    }

    private function makeSpanElement(RenderNode $node, array $style, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $fontSize = $style['fontSize'] ?? 16;
        $color    = $style['fg'] ?? ($style['color'] ?? 0xFFFFFF);
        $bold     = $style['bold'] ?? 0;
        $align    = $props['align'] ?? ($style['textAlign'] ?? 'left');
        $text = '';

        // AOT 兼容: php::Variant 在 use native_types 模式下 is_string() 可能返回 false
        if ($node->content !== null) {
            $text = (string)$node->content;
        }
        $bindKey = $props[':bind'] ?? $props['bind'] ?? '';
        if ($bindKey !== '') {
            $text = $this->currentComponent()->getBindValue($bindKey);
        }
        $vModel = $props['v-model'] ?? '';
        if ($vModel !== '') {
            $text = $this->currentComponent()->getBindValue($vModel);
        }

        file_put_contents('D:\\Px\\_debug_out.txt', "makeSpanElement: node.type={$node->type} content_is_null=" . (int)($node->content===null) . " text='$text' bindKey='$bindKey' x={$node->x} y={$node->y} w={$node->w} h={$node->h}\n", FILE_APPEND);

        if ($text === '') return null;

        // container-w/h may be percentage strings like "100%" → fall back to actual node size
        $rawContainerW = $props['container-w'] ?? null;
        $containerW = $w;
        if ($rawContainerW !== null && !str_contains($rawContainerW, '%')) {
            $containerW = (int)$rawContainerW;
        }
        $rawContainerH = $props['container-h'] ?? null;
        $containerH = $h;
        if ($rawContainerH !== null && !str_contains($rawContainerH, '%')) {
            $containerH = (int)$rawContainerH;
        }
        $containerX = (int)($props['container-x'] ?? $x);

        // ── text-overflow: ellipsis 文本截断（含多行支持 -webkit-line-clamp）──
        $textOverflow = $style['textOverflow'] ?? 'clip';
        if ($textOverflow === 'ellipsis' && $containerW > 0) {
            $boldFactor = $bold ? 1.35 : 1.0;
            $charWidth = (int)($fontSize * 0.6 * $boldFactor);
            $maxCharsPerLine = max(1, (int)(($containerW - 4) / max($charWidth, 1)));

            // 查询 -webkit-line-clamp 多行截断
            $lineClamp = (int)($style['webkitLineClamp'] ?? 0);
            if ($lineClamp > 0) {
                // 多行模式：按行数截断
                $lineHeight = (int)($style['lineHeight'] ?? 0);
                if ($lineHeight <= 0) {
                    $lineHeight = (int)($fontSize * 1.4);
                }
                $maxLines = $lineClamp;
                $maxTotalChars = $maxCharsPerLine * $maxLines;
                if (strlen($text) > $maxTotalChars) {
                    // 最后一行添加…
                    $text = substr($text, 0, max(0, $maxTotalChars - 1)) . '…';
                }
            } else {
                // 单行模式（原有逻辑）
                if (strlen($text) > $maxCharsPerLine) {
                    $text = substr($text, 0, max(0, $maxCharsPerLine - 1)) . '…';
                }
            }
        }

        if ($align === 'right' || $align === 'center') {
            $boldFactor = $bold ? 1.35 : 1.0;
            $textWidth = strlen($text) * (int)($fontSize * 0.6 * $boldFactor);
            if ($align === 'right') {
                $x = $containerX + $containerW - 12 - $textWidth;
                if ($x < $containerX + 4) $x = $containerX + 4;
            } else {
                $x = $containerX + (int)(($containerW - $textWidth) / 2);
                if ($x < $containerX) $x = (int)$containerX;
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

    private function makeButtonElement(RenderNode $node, array $style, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $cursor = $style['cursor'] ?? '';
        if ($w <= 0 || $h <= 0) {
            $w = $w <= 0 ? 80 : $w;
            $h = $h <= 0 ? 32 : $h;
        }

        $bg     = $style['bg'] ?? 0x4488CC;
        $fg     = $style['fg'] ?? 0xFFFFFF;
        $borderWidth = $style['borderWidth'] ?? 0;
        $borderColor = 0;
        if ($borderWidth > 0) {
            $borderColor = (int)($style['borderColor'] ?? ($bg !== 0 ? ($bg & 0xFFFFFF) >> 1 : 0));
        }
        $borderRadius = $style['borderRadius'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;
        $boxShadow = $style['boxShadow'] ?? '';
        $shadowX = 0; $shadowY = 0; $shadowColor = 0;
        if ($boxShadow !== '') {
            $parts = explode('|', $boxShadow);
            $shadowX = (int)($parts[0] ?? 0);
            $shadowY = (int)($parts[1] ?? 0);
            $shadowColor = CssMappings::hexToBgr($parts[4] ?? '#000000');
        }

        $label = '';
        // AOT 兼容: php::Variant 在 use native_types 模式下 is_string() 可能返回 false
        if ($node->content !== null) {
            $label = (string)$node->content;
        }
        $bindKey = $props[':bind'] ?? $props['bind'] ?? '';
        if ($bindKey !== '') {
            $label = $this->currentComponent()->getBindValue($bindKey);
        }
        if ($label === '' && isset($props['@click'])) {
            $label = $props['label'] ?? '';
        }

        // 若标签仍为空，遍历子 RenderNode 提取文本（处理 <button><span :bind="x">{{ x }}</span></button> 模式）
        if ($label === '') {
            foreach ($node->children as $child) {
                if ($child->content !== null && (string)$child->content !== '') {
                    $label = (string)$child->content;
                    break;
                }
                // 检查子节点的 bind 引用
                if ($child->sourceVNode !== null && $child->sourceVNode->props !== null) {
                    $childBindKey = $child->sourceVNode->props[':bind'] ?? $child->sourceVNode->props['bind'] ?? '';
                    if ($childBindKey !== '') {
                        $childLabel = $this->currentComponent()->getBindValue($childBindKey);
                        if ($childLabel !== '') {
                            $label = $childLabel;
                            break;
                        }
                    }
                }
            }
        }

        $labelFontSize = 22;
        $labelLen = strlen($label);
        $labelCharW = (int)($labelFontSize * 0.6);
        $labelX = $x + (int)(($w - $labelLen * $labelCharW) / 2);
        $labelY = $y + (int)(($h - $labelFontSize) / 2);

        return [
            'type' => 'button', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'bg' => $bg, 'fg' => $fg, 'border' => $borderColor, 'borderWidth' => $borderWidth, 'borderRadius' => $borderRadius,
            'label' => $label, 'labelX' => $labelX, 'labelY' => $labelY,
            'labelFontSize' => $labelFontSize, 'opacity' => $opacity, 'layer' => $layer,
            'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowColor' => $shadowColor, 'cursor' => $cursor,
        ];
    }

    private function makeImgElement(RenderNode $node, array $style, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        // CSS 标准 §10.3.2: <img> 是替换元素，宽度由布局层决定
        // 如果 w/h 为 0，无法渲染，但若有 alt 文本可降级显示
        $noSize = ($w <= 0 || $h <= 0);

        // 背景色：CSS background 属性（CssMappings 已映射为 style['bg']）
        $bg = $style['bg'] ?? 0xCCCCCC;
        $borderRadius = $style['borderRadius'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;

        // box-shadow
        $boxShadow = $style['boxShadow'] ?? '';
        $shadowX = 0; $shadowY = 0; $shadowColor = 0;
        if ($boxShadow !== '') {
            $parts = explode('|', $boxShadow);
            $shadowX = (int)($parts[0] ?? 0);
            $shadowY = (int)($parts[1] ?? 0);
            $shadowColor = CssMappings::hexToBgr($parts[4] ?? '#000000');
        }

        // border
        $borderWidth = $style['borderWidth'] ?? 0;
        $borderColor = $style['borderColor'] ?? 0;

        // object-fit: CSS Images §4.5 控制替换内容如何适应容器
        // 目前 fill 拉伸填充；contain/cover 待后续支持真实图像
        $objectFit = $style['objectFit'] ?? 'fill';

        // alt 属性：图片加载失败时的回退文本（HTML 标准）
        $alt = $props['alt'] ?? '';

        // ── 尺寸为 0 时降级显示 ──
        if ($noSize) {
            if ($alt !== '') {
                // 降级为 broken image 图标 + alt 文本（类似浏览器行为）
                $fontSize = $style['fontSize'] ?? 14;
                $textColor = $style['fg'] ?? ($style['color'] ?? 0xFFFFFF);
                return [
                    'type' => 'text', 'text' => '🖼 ' . $alt,
                    'x' => $x, 'y' => $y,
                    'fontSize' => $fontSize, 'color' => $textColor, 'bold' => 1,
                    'align' => 'left', 'layer' => $layer,
                ];
            }
            return null; // 无尺寸无 alt → 不可见
        }

        // ── 渲染为占位矩形（用背景色模拟图像）──
        $elements = [];
        $elements[] = [
            'type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'color' => $bg, 'borderRadius' => $borderRadius, 'opacity' => $opacity, 'layer' => $layer,
            'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowColor' => $shadowColor,
            'borderWidth' => $borderWidth, 'borderColor' => $borderColor,
        ];

        // 若有 alt 文本，在图片上叠加显示
        if ($alt !== '') {
            $fontSize = $style['fontSize'] ?? 14;
            $textColor = $style['fg'] ?? ($style['color'] ?? 0xFFFFFF);
            $altX = $x + 4;
            $altY = $y + (int)(($h - $fontSize) / 2);
            if ($altY < $y) $altY = $y;
            $elements[] = [
                'type' => 'text', 'text' => $alt,
                'x' => $altX, 'y' => $altY,
                'fontSize' => $fontSize, 'color' => $textColor, 'bold' => 0,
                'align' => 'left', 'layer' => $layer + 1,
            ];
        }

        if (count($elements) === 1) {
            return $elements[0];
        }
        return ['type' => 'group', 'layer' => $layer, 'elements' => $elements];
    }

    private function makeInputElement(RenderNode $node, array $style, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $bg       = $style['bg'] ?? 0x1E1E1E;
        $fg       = $style['fg'] ?? 0xFFFFFF;
        $fontSize = $style['fontSize'] ?? 16;
        $borderRadius = $style['borderRadius'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;

        $bindKey = $props['v-model'] ?? '';
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

    private function makeScrollContainerElement(RenderNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $bg = $style['bg'] ?? 0x2D2D2D;
        $borderRadius = $style['borderRadius'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;

        $contentH = $node->contentHeight;
        if ($contentH === 0) {
            foreach ($node->children as $child) {
                $itemH = (int)($child->style['height'] ?? 0);
                $contentH += max($child->h, $itemH);
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

    /**
     * 帧号溢出时重置所有节点的绘制标记。
     */
    private function resetAllPaintFlags(RenderNode $node): void
    {
        $node->lastPaintFrame = 0;
        $node->layoutDirty = true;
        foreach ($node->children as $child) {
            $this->resetAllPaintFlags($child);
        }
    }
}
