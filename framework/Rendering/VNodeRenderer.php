<?php

namespace Px\Rendering;

use native_types;

use Px\Core\Config;
use Px\Interfaces\ReactiveComponentInterface;
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
    private ReactiveComponentInterface $component;
    private RenderContext $render_ctx;

    /** @var int 当前绘制帧号，递增以避免全量重置 */
    private int $currentPaintFrame = 0;

    /** @var array Scroll context for offsetting children */
    private array $scrollCtxStack = [];

    /** @var array<ReactiveComponentInterface> Stack for correct bind value context */
    private array $componentStack = [];

    public function __construct(ReactiveComponentInterface $component, RenderContext $render_ctx)
    {
        $this->component = $component;
        $this->render_ctx = $render_ctx;
    }

    /**
     * 渲染 RenderNode 树
     */
    public function render(RenderNode $root): void
    {
        \Px\Core\PerfCounter::start('render_collect');
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

        if (Config::get('diag_enabled', false)) {
            $totalElements = 0;
            for ($l = 0; $l <= $maxLayer; $l++) {
                $totalElements += count($elementsByLayer[$l] ?? []);
            }
            error_log('[DIAG] VNodeRenderer: collected ' . $totalElements . ' elements across ' . ($maxLayer + 1) . ' layers');
        }

        for ($l = 0; $l <= $maxLayer; $l++) {
            $layerElements = $elementsByLayer[$l] ?? [];
            foreach ($layerElements as $el) {
                $this->render_ctx->drawElement($el);
            }
        }

        $this->render_ctx->endFrame();
        \Px\Core\PerfCounter::end('render_collect');
    }

    /**
     * 递归收集需要绘制的元素。
     * 使用 needsPaint + markPainted 实现增量绘制。
     *
     * @param RenderNode $node 当前节点
     * @param array &$elementsByLayer 按 layer 分组的元素
     * @param int &$maxLayer 最大 layer
     * @param int $accumOffsetX 祖先级累计滚动偏移 X（A1 重构：不在布局层改坐标）
     * @param int $accumOffsetY 祖先级累计滚动偏移 Y
     */
    private function collectElements(RenderNode $node, array &$elementsByLayer, int &$maxLayer, int $accumOffsetX = 0, int $accumOffsetY = 0): void
    {
        // ── A1 重构: 设置节点的滚动偏移（用于 renderNodeToElement）──
        // position:fixed 元素不受任何祖先滚动影响
        $isFixed = ($node->style['position'] ?? '') === 'fixed';
        $node->renderOffsetX = $isFixed ? 0 : $accumOffsetX;
        $node->renderOffsetY = $isFixed ? 0 : $accumOffsetY;

        // 增量绘制：如果节点不需要绘制，跳过但继续处理子节点
        if (!$node->needsPaint($this->currentPaintFrame)) {
            // 但子节点仍需传递正确的累计偏移
            $childOffsetX = $isFixed ? 0 : $accumOffsetX;
            $childOffsetY = $isFixed ? 0 : $accumOffsetY;
            if (!$isFixed && $node->isScrollContainer) {
                $childOffsetX -= $node->scrollLeft;
                $childOffsetY -= $node->scrollTop;
            }
            foreach ($node->children as $child) {
                $this->collectElements($child, $elementsByLayer, $maxLayer, $childOffsetX, $childOffsetY);
            }
            return;
        }
        // debug: 
        // collectElements trace removed
        // #root 不产生渲染元素，直接处理子节点
        if ($node->type === '#root') {
            $childOffsetX = $isFixed ? 0 : $accumOffsetX;
            $childOffsetY = $isFixed ? 0 : $accumOffsetY;
            if (!$isFixed && $node->isScrollContainer) {
                $childOffsetX -= $node->scrollLeft;
                $childOffsetY -= $node->scrollTop;
            }
            foreach ($node->children as $child) {
                $this->collectElements($child, $elementsByLayer, $maxLayer, $childOffsetX, $childOffsetY);
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
            // CSS Overflow Module L3 §3.2: clip region = padding box (excludes border)
            $bw = (int)($node->style['borderWidth'] ?? 0);
            $ns = $node->style;
            $bl = (int)($ns['borderLeftWidth'] ?? $bw);
            $br = (int)($ns['borderRightWidth'] ?? $bw);
            $bt = (int)($ns['borderTopWidth'] ?? $bw);
            $bb = (int)($ns['borderBottomWidth'] ?? $bw);
            $this->scrollCtxStack[] = [
                'x' => $node->x + $bl, 'y' => $node->y + $bt,
                'w' => max(0, $node->visualW - $bl - $br),
                'h' => max(0, $node->visualH - $bt - $bb),
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
            // CSS Overflow Module L3 §3.2: clip region = padding box (excludes border)
            $bw = (int)($node->style['borderWidth'] ?? 0);
            $ns = $node->style;
            $bl = (int)($ns['borderLeftWidth'] ?? $bw);
            $br = (int)($ns['borderRightWidth'] ?? $bw);
            $bt = (int)($ns['borderTopWidth'] ?? $bw);
            $bb = (int)($ns['borderBottomWidth'] ?? $bw);
            $clipX = $node->x + $bl;
            $clipY = $node->y + $bt;
            $clipW = max(0, ($node->visualW > 0 ? $node->visualW : $node->w) - $bl - $br);
            $clipH = max(0, ($node->visualH > 0 ? $node->visualH : $node->h) - $bt - $bb);
            $elementsByLayer[$layer][] = [
                'type' => 'clip-push',
                'x' => $clipX, 'y' => $clipY,
                'w' => $clipW, 'h' => $clipH,
                'layer' => $layer,
            ];
        }

        // ── 计算子节点的累计滚动偏移 ──
        // 当前节点的 scroll 偏移对子节点生效
        $childOffsetX = $isFixed ? 0 : $accumOffsetX;
        $childOffsetY = $isFixed ? 0 : $accumOffsetY;
        if (!$isFixed && $node->isScrollContainer) {
            $childOffsetX -= $node->scrollLeft;
            $childOffsetY -= $node->scrollTop;
        }

        // 递归处理子节点（button 类型不展开，由 GDI 层绘制）
        if ($node->type !== 'button') {
            foreach ($node->children as $child) {
                $this->collectElements($child, $elementsByLayer, $maxLayer, $childOffsetX, $childOffsetY);
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
                ScrollbarEmitter::emit($node, $scrollCtx, $elementsByLayer, $maxLayer);
            }
        }

        // 标记节点为已绘制
        $node->markPainted($this->currentPaintFrame);
    }

    /**
     * 文本宽度测量（优先使用 C++ 精确测量，退化使用估算）。
     */
    private static function measureTextWidth(string $text, int $fontSize, bool $bold): int
    {
        static $hasNative = null;
        if ($hasNative === null) {
            $hasNative = function_exists('\\sk_measure_text_width')
                && !getenv('PX_LAYOUT_TEST_FORCE_ESTIMATE');
        }
        if ($hasNative) {
            return (int)\sk_measure_text_width($text, $fontSize, $bold);
        }
        $boldFactor = $bold ? 1.35 : 1.0;
        $charW = (int)($fontSize * 0.6 * $boldFactor);
        $cjkW  = (int)($fontSize * $boldFactor);
        $len   = strlen($text);
        $total = 0;
        for ($i = 0; $i < $len;) {
            $b = ord($text[$i]);
            if ($b < 0x80) {
                $total += $charW; $i++;
            } elseif ($b < 0xC0) {
                $i++;
            } elseif ($b < 0xE0) {
                $total += $cjkW; $i += 2;
            } elseif ($b < 0xF0) {
                $total += $cjkW; $i += 3;
            } else {
                $total += $cjkW; $i += 4;
            }
        }
        return $total;
    }

    /**
     * 测量文本总高度（ascent + descent），用于垂直居中。
     * 优先使用 C++ sk_measure_text_height 精确测量，退化使用 fontSize + 2 估算。
     */
    private static function measureTextHeight(int $fontSize, bool $bold): int
    {
        static $hasNative = null;
        if ($hasNative === null) {
            $hasNative = function_exists('\\sk_measure_text_height');
        }
        if ($hasNative) {
            $h = (int)\sk_measure_text_height($fontSize, $bold ? 1 : 0);
            if ($h > 0) return $h;
        }
        return $fontSize + 2;
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

        // ── 伪类样式合并（:hover/:focus/:active）──
        // 根据节点交互状态应用预解析的伪类样式，优先级：active > focus > hover
        if ($node->hovered && isset($style['__hoverStyle'])) {
            foreach ($style['__hoverStyle'] as $hk => $hv) {
                $style[$hk] = $hv;
            }
        }
        if ($node->focused && isset($style['__focusStyle'])) {
            foreach ($style['__focusStyle'] as $fk => $fv) {
                $style[$fk] = $fv;
            }
        }
        if ($node->active && isset($style['__activeStyle'])) {
            foreach ($style['__activeStyle'] as $ak => $av) {
                $style[$ak] = $av;
            }
        }
        // A1 重构: 布局坐标 + 绘制时滚动偏移（不在布局层修改坐标）
        $x = $node->x + $node->renderOffsetX;
        $y = $node->y + $node->renderOffsetY;
        $w = $node->visualW;
        $h = $node->visualH;

        // ── 解析 border-radius 百分比（CSS Backgrounds & Borders §5.1）──
        // 百分比基于元素 min(w, h)：例如 50% 在 180x180 元素上 = 90px
        if (isset($style['borderRadiusPercent'])) {
            $minDim = min($w, $h);
            $style['borderRadius'] = (int)($minDim * $style['borderRadiusPercent'] / 100.0);
        }

        $layer = $node->layer;

        // 滚动裁切（position:fixed 元素不受祖先滚动容器影响）
        if (count($this->scrollCtxStack) > 0) {
            $isFixed = ($node->style['position'] ?? '') === 'fixed';
            if (!$isFixed) {
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
            }  // end if (!$isFixed)
        }  // end if (count($this->scrollCtxStack) > 0)

        // 通过 sourceVNode 访问 props（bind 值、事件处理器等）
        $props = [];
        if ($node->sourceVNode !== null && $node->sourceVNode->props !== null) {
            $props = $node->sourceVNode->props;
        }

        switch ($node->type) {
            case 'button':
                return $this->makeButtonElement($node, $style, $props, $x, $y, $w, $h, $layer);
            case 'input':   return $this->makeInputElement($node, $style, $props, $x, $y, $w, $h, $layer);
            case 'img':     return $this->makeImgElement($node, $style, $props, $x, $y, $w, $h, $layer);
            case 'span':
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
        $hasBorder = ($style['borderWidth'] ?? 0) > 0
            || ($style['borderTopWidth'] ?? 0) > 0
            || ($style['borderRightWidth'] ?? 0) > 0
            || ($style['borderBottomWidth'] ?? 0) > 0
            || ($style['borderLeftWidth'] ?? 0) > 0;
        $hasBg = $bg !== null;

        // ── background-image 支持 ──
        $bgImage = $style['backgroundImage'] ?? '';
        $bgImageHandle = 0;
        if ($bgImage !== '' && $w > 0 && $h > 0) {
            $bgImageHandle = ImageManager::loadImage($bgImage);
        }

        // Check for content (text or children)
        $hasTextChild = is_string($node->content) && $node->content !== '';
        if ($bg === null && !$hasBorder && !$hasTextChild && $bgImageHandle === 0) {
            return null;
        }

        $noFill = ($bg === null);
        $drawColor = ($bg !== null) ? $bg : 0;
        $borderRadius = $style['borderRadius'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;
        $offsets = CssMappings::parseBoxShadowOffsets($style['boxShadow'] ?? '');
        $shadowX = $offsets['h']; $shadowY = $offsets['v']; $shadowColor = $offsets['color'];
        $borderWidth = $style['borderWidth'] ?? 0;
        $borderTopWidth = $style['borderTopWidth'] ?? $borderWidth;
        $borderRightWidth = $style['borderRightWidth'] ?? $borderWidth;
        $borderBottomWidth = $style['borderBottomWidth'] ?? $borderWidth;
        $borderLeftWidth = $style['borderLeftWidth'] ?? $borderWidth;
        $borderColor = $style['borderColor'] ?? 0;
        $borderTopColor = $style['borderTopColor'] ?? $borderColor;
        $borderRightColor = $style['borderRightColor'] ?? $borderColor;
        $borderBottomColor = $style['borderBottomColor'] ?? $borderColor;
        $borderLeftColor = $style['borderLeftColor'] ?? $borderColor;

        // ── background-image 图片层（如果有）──
        $bgImageEl = null;
        if ($bgImageHandle !== 0) {
            $bgImageEl = [
                'type' => 'image',
                'handle' => $bgImageHandle,
                'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'layer' => $layer,
            ];
        }

        if ($hasTextChild) {
            $fontSize = $style['fontSize'] ?? 14;
            $textColor = $style['fg'] ?? ($style['color'] ?? 0xFFFFFF);
            $bold = $style['bold'] ?? 0;
            $align = $props['align'] ?? ($style['textAlign'] ?? 'start');
            // CSS Text Module Level 3 §7: text-align is inherited
            if ($align === 'start' && !isset($style['textAlign']) && $node->parent !== null) {
                $parentAlign = $node->parent->style['textAlign'] ?? null;
                if ($parentAlign !== null && $parentAlign !== 'start' && $parentAlign !== '') {
                    $align = $parentAlign;
                }
            }
            // CSS Text Module Level 3 §7: start=LTR→left, end=LTR→right, justify≈left(无justify渲染)
            if ($align === 'start' || $align === 'match-parent') $align = 'left';
            if ($align === 'end') $align = 'right';
            if ($align === 'justify' || $align === 'justify-all') $align = 'left';

            $text = $node->content;
            $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);

            // CSS 2.2 §17.5: 文本内容位于 content area (border + padding 内部)
            $contentX = $x + $borderLeftWidth + ($style['paddingLeft'] ?? 0);
            $contentY = $y + $borderTopWidth + ($style['paddingTop'] ?? 0);
            $contentW = max(0, $w - $borderLeftWidth - $borderRightWidth - ($style['paddingLeft'] ?? 0) - ($style['paddingRight'] ?? 0));

            $textX = $contentX + 4;
            if ($align === 'right') {
                $textX = $contentX + $contentW - 12 - $textWidth;
                if ($textX < $contentX + 4) $textX = $contentX + 4;
            } elseif ($align === 'center') {
                $textX = $contentX + (int)(($contentW - $textWidth) / 2);
                if ($textX < $contentX + 4) $textX = $contentX + 4;
            }
            if ($textX < $contentX + 4) $textX = $contentX + 4;

            // CSS Flexible Box Layout §8.2: align-items:center → 交叉轴居中文本
            // 当元素是 flex 容器且 alignItems=center 时，文本在 content area 内垂直居中
            $textY = $contentY;
            $display = $style['display'] ?? 'block';
            $alignItems = $style['alignItems'] ?? 'stretch';
            if (($display === 'flex' || $display === 'inline-flex') && $alignItems === 'center') {
                $contentH = max(0, $h - $borderTopWidth - $borderBottomWidth - ($style['paddingTop'] ?? 0) - ($style['paddingBottom'] ?? 0));
                // 精确测量文本总高度（ascent + descent），确保视觉居中
                $textHeight = self::measureTextHeight($fontSize, (bool)$bold);
                if ($contentH > $textHeight) {
                    $textY = $contentY + (int)(($contentH - $textHeight) / 2);
                }
            }

            $elements = [];
            if ($hasBg || $hasBorder) {
                $elements[] = ['type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'color' => $drawColor, 'borderRadius' => $borderRadius, 'opacity' => $opacity, 'layer' => $layer, 'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowColor' => $shadowColor, 'borderWidth' => $borderWidth, 'borderColor' => $borderColor, 'borderTopColor' => $borderTopColor, 'borderRightColor' => $borderRightColor, 'borderBottomColor' => $borderBottomColor, 'borderLeftColor' => $borderLeftColor, 'borderTopWidth' => $borderTopWidth, 'borderRightWidth' => $borderRightWidth, 'borderBottomWidth' => $borderBottomWidth, 'borderLeftWidth' => $borderLeftWidth, 'noFill' => $noFill, 'cursor' => $cursor];
            }
            if ($bgImageEl !== null) {
                $elements[] = $bgImageEl;
            }
            $elements[] = ['type' => 'text', 'text' => $text, 'x' => $textX, 'y' => $textY,
                'fontSize' => $fontSize, 'color' => $textColor, 'bold' => $bold,
                'fontFamily' => $style['fontFamily'] ?? '',
                'align' => $align, 'layer' => $layer + 1, 'cursor' => $cursor,
                'decorationLine' => $style['textDecorationLine'] ?? 'none',
                'decorationColor' => $style['textDecorationColor'] ?? $textColor,
                'decorationStyle' => $style['textDecorationStyle'] ?? 'solid',
                'decorationThickness' => $style['textDecorationThickness'] ?? 0,
                'underlineOffset' => $style['textUnderlineOffset'] ?? 0,
                'textWidth' => self::measureTextWidth($text, $fontSize, (bool)$bold)];

            // 存储文本渲染位置信息（用于 layout dump 验证垂直居中）
            $node->textRenderInfo = [
                'x' => $textX,
                'y' => $textY,
                'textHeight' => $textHeight,
                'textWidth' => $textWidth,
            ];

            if (count($elements) === 1) {
                return $elements[0];
            }
            return [
                'type' => 'group', 'layer' => $layer, 'cursor' => $cursor,
                'elements' => $elements,
            ];
        }

        // 无文本：返回 rect + 可选的 background-image
        $elements = [];
        if ($hasBg || $hasBorder) {
            $elements[] = [
                'type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'color' => $drawColor, 'borderRadius' => $borderRadius, 'opacity' => $opacity, 'layer' => $layer,
                'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowColor' => $shadowColor,
                'borderWidth' => $borderWidth, 'borderColor' => $borderColor,
                'borderTopColor' => $borderTopColor, 'borderRightColor' => $borderRightColor,
                'borderBottomColor' => $borderBottomColor, 'borderLeftColor' => $borderLeftColor,
                'borderTopWidth' => $borderTopWidth, 'borderRightWidth' => $borderRightWidth,
                'borderBottomWidth' => $borderBottomWidth, 'borderLeftWidth' => $borderLeftWidth,
                'noFill' => $noFill,
                'cursor' => $cursor,
            ];
        }
        if ($bgImageEl !== null) {
            $elements[] = $bgImageEl;
        }

        if (count($elements) === 0) {
            return null;
        }
        if (count($elements) === 1) {
            return $elements[0];
        }
        return [
            'type' => 'group', 'layer' => $layer, 'cursor' => $cursor,
            'elements' => $elements,
        ];
    }

    private function makeSpanElement(RenderNode $node, array $style, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $fontSize = $style['fontSize'] ?? 16;
        // CSS 继承：若当前节点无 fg，沿父链查找
        $color = $style['fg'] ?? ($style['color'] ?? null);
        if ($color === null) {
            $p = $node->parent;
            while ($p !== null) {
                $pc = $p->style['fg'] ?? null;
                if ($pc !== null) { $color = $pc; break; }
                $p = $p->parent;
            }
        }
        if ($color === null) $color = 0xFFFFFF;
        $bold     = $style['bold'] ?? 0;
        $align    = $props['align'] ?? ($style['textAlign'] ?? 'start');
        // CSS Text Module Level 3 §7: text-align is inherited
        if ($align === 'start' && !isset($style['textAlign']) && $node->parent !== null) {
            $parentAlign = $node->parent->style['textAlign'] ?? null;
            if ($parentAlign !== null && $parentAlign !== 'start' && $parentAlign !== '') {
                $align = $parentAlign;
            }
        }
        // CSS Text Module Level 3 §7: start=LTR→left, end=LTR→right, justify≈left
        if ($align === 'start' || $align === 'match-parent') $align = 'left';
        if ($align === 'end') $align = 'right';
        if ($align === 'justify' || $align === 'justify-all') $align = 'left';
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

        $diagLogPath = Config::get('diag_log_path', '');
        if ($diagLogPath !== '') {
            file_put_contents($diagLogPath, "makeSpanElement: node.type={$node->type} content_is_null=" . (int)($node->content===null) . " text='$text' bindKey='$bindKey' x={$node->x} y={$node->y} w={$node->w} h={$node->h}\n", FILE_APPEND);
        }

        if ($text === '') return null;

        // container-w/h 百分比值 → 从父容器 ScrollContext 解析实际宽度
        $rawContainerW = $props['container-w'] ?? null;
        $containerW = $w;
        if ($rawContainerW !== null) {
            if (!str_contains($rawContainerW, '%')) {
                $containerW = (int)$rawContainerW;
            } elseif (count($this->scrollCtxStack) > 0) {
                $scrollCtx = $this->scrollCtxStack[count($this->scrollCtxStack) - 1];
                $pct = (float)str_replace('%', '', $rawContainerW) / 100.0;
                $containerW = (int)($scrollCtx['w'] * $pct);
            }
        }
        $rawContainerH = $props['container-h'] ?? null;
        $containerH = $h;
        if ($rawContainerH !== null && !str_contains($rawContainerH, '%')) {
            $containerH = (int)$rawContainerH;
        }
        $containerX = (int)($props['container-x'] ?? $x);

        // ── 文本溢出/省略处理（委派 TextOverflowProcessor）──
        $overflowResult = TextOverflowProcessor::process($text, $containerW, $fontSize, $bold, $style);
        $text = $overflowResult['text'];
        
        // 多行 clamp：构建 group 元素直接返回
        if ($overflowResult['lines'] !== null && count($overflowResult['lines']) > 1) {
            $elements = [];
            $lineIdx = 0;
            $lineHeight = $overflowResult['lineHeight'];
            foreach ($overflowResult['lines'] as $seg) {
                $lineY = $y + $lineIdx * $lineHeight;
                $segX = $x;
                if ($align === 'right' || $align === 'center') {
                    $segW = self::measureTextWidth($seg, $fontSize, $bold);
                    if ($align === 'right') {
                        $segX = $containerX + $containerW - 12 - $segW;
                        if ($segX < $containerX + 4) $segX = $containerX + 4;
                    } else {
                        $segX = $containerX + (int)(($containerW - $segW) / 2);
                        if ($segX < $containerX) $segX = (int)$containerX;
                    }
                }
                $elements[] = [
                    'type' => 'text', 'text' => $seg,
                    'x' => $segX, 'y' => $lineY,
                    'fontSize' => $fontSize, 'color' => $color, 'bold' => $bold,
                    'align' => 'left', 'layer' => $layer,
                    'decorationLine' => $style['textDecorationLine'] ?? 'none',
                    'decorationColor' => $style['textDecorationColor'] ?? $color,
                    'decorationStyle' => $style['textDecorationStyle'] ?? 'solid',
                    'decorationThickness' => $style['textDecorationThickness'] ?? 0,
                    'underlineOffset' => $style['textUnderlineOffset'] ?? 0,
                    'textWidth' => self::measureTextWidth($seg, $fontSize, (bool)$bold),];
                $lineIdx++;
            }
            return ['type' => 'group', 'layer' => $layer, 'elements' => $elements];
        }
        
        if ($align === 'right' || $align === 'center') {
            $textWidth = self::measureTextWidth($text, $fontSize, $bold);
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

        // 存储文本渲染位置信息（用于 layout dump 验证垂直居中）
        if (!isset($textWidth)) {
            $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);
        }
        $textHeight = self::measureTextHeight($fontSize, (bool)$bold);
        $node->textRenderInfo = [
            'x' => $x,
            'y' => $y,
            'textHeight' => $textHeight,
            'textWidth' => $textWidth,
        ];

        return [
            'type' => 'text', 'text' => $text,
            'x' => $x, 'y' => $y,
            'fontSize' => $fontSize, 'color' => $color, 'bold' => $bold,
            'align' => $align, 'layer' => $layer,
            'decorationLine' => $style['textDecorationLine'] ?? 'none',
            'decorationColor' => $style['textDecorationColor'] ?? $color,
            'decorationStyle' => $style['textDecorationStyle'] ?? 'solid',
            'decorationThickness' => $style['textDecorationThickness'] ?? 0,
            'underlineOffset' => $style['textUnderlineOffset'] ?? 0,
            'textWidth' => self::measureTextWidth($text, $fontSize, (bool)$bold),];
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
        $borderTopWidth = $style['borderTopWidth'] ?? $borderWidth;
        $borderRightWidth = $style['borderRightWidth'] ?? $borderWidth;
        $borderBottomWidth = $style['borderBottomWidth'] ?? $borderWidth;
        $borderLeftWidth = $style['borderLeftWidth'] ?? $borderWidth;
        $borderColor = 0;
        if ($borderWidth > 0 || $borderTopWidth > 0 || $borderRightWidth > 0 || $borderBottomWidth > 0 || $borderLeftWidth > 0) {
            $borderColor = (int)($style['borderColor'] ?? ($bg !== 0 ? ($bg & 0xFFFFFF) >> 1 : 0));
        }
        $borderTopColor = $style['borderTopColor'] ?? $borderColor;
        $borderRightColor = $style['borderRightColor'] ?? $borderColor;
        $borderBottomColor = $style['borderBottomColor'] ?? $borderColor;
        $borderLeftColor = $style['borderLeftColor'] ?? $borderColor;
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
            'bg' => $bg, 'fg' => $fg, 'border' => $borderColor, 'borderWidth' => $borderWidth,
            'borderTopWidth' => $borderTopWidth, 'borderRightWidth' => $borderRightWidth,
            'borderBottomWidth' => $borderBottomWidth, 'borderLeftWidth' => $borderLeftWidth,
            'borderTopColor' => $borderTopColor, 'borderRightColor' => $borderRightColor,
            'borderBottomColor' => $borderBottomColor, 'borderLeftColor' => $borderLeftColor,
            'borderRadius' => $borderRadius,
            'label' => $label, 'labelX' => $labelX, 'labelY' => $labelY,
            'labelFontSize' => $labelFontSize, 'opacity' => $opacity, 'layer' => $layer,
            'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowColor' => $shadowColor, 'cursor' => $cursor,
        ];
    }

    private function makeImgElement(RenderNode $node, array $style, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        // CSS 标准 §10.3.2: <img> 是替换元素，宽度由布局层决定
        $noSize = ($w <= 0 || $h <= 0);

        // ── 尝试加载真实图片 ──
        $src = $props['src'] ?? $props[':src'] ?? '';
        $imageHandle = 0;
        if ($src !== '' && !$noSize) {
            $imageHandle = ImageManager::loadImage($src);
        }

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
        $borderTopWidth = $style['borderTopWidth'] ?? $borderWidth;
        $borderRightWidth = $style['borderRightWidth'] ?? $borderWidth;
        $borderBottomWidth = $style['borderBottomWidth'] ?? $borderWidth;
        $borderLeftWidth = $style['borderLeftWidth'] ?? $borderWidth;
        $borderColor = $style['borderColor'] ?? 0;
        $borderTopColor = $style['borderTopColor'] ?? $borderColor;
        $borderRightColor = $style['borderRightColor'] ?? $borderColor;
        $borderBottomColor = $style['borderBottomColor'] ?? $borderColor;
        $borderLeftColor = $style['borderLeftColor'] ?? $borderColor;

        // object-fit: CSS Images §4.5 控制替换内容如何适应容器
        $objectFit = $style['objectFit'] ?? 'fill';

        // alt 属性：图片加载失败时的回退文本（HTML 标准）
        $alt = $props['alt'] ?? '';

        // ── 尺寸为 0 时降级显示 ──
        if ($noSize) {
            if ($alt !== '') {
                $fontSize = $style['fontSize'] ?? 14;
                $textColor = $style['fg'] ?? ($style['color'] ?? 0xFFFFFF);
                return [
                    'type' => 'text', 'text' => '🖼 ' . $alt,
                    'x' => $x, 'y' => $y,
                    'fontSize' => $fontSize, 'color' => $textColor, 'bold' => 1,
                    'align' => 'left', 'layer' => $layer,
                ];
            }
            return null;
        }

        $elements = [];

        // ── 真实图片 ──
        if ($imageHandle !== 0) {
            // 有圆角时用 clip-push/clip-pop 裁切图片
            if ($borderRadius > 0) {
                $elements[] = ['type' => 'clip-push', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'layer' => $layer];
                $elements[] = ['type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                    'color' => 0xFFFFFF, 'borderRadius' => $borderRadius, 'layer' => $layer];
            }
            $elements[] = [
                'type' => 'image',
                'handle' => $imageHandle,
                'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'layer' => $layer,
            ];
            if ($borderRadius > 0) {
                $elements[] = ['type' => 'clip-pop', 'layer' => $layer];
            }
        } else {
            // ── 降级为占位矩形（用背景色模拟图像）──
            $elements[] = [
                'type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'color' => $bg, 'borderRadius' => $borderRadius, 'opacity' => $opacity, 'layer' => $layer,
                'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowColor' => $shadowColor,
                'borderWidth' => $borderWidth, 'borderColor' => $borderColor,
                'borderTopColor' => $borderTopColor, 'borderRightColor' => $borderRightColor,
                'borderBottomColor' => $borderBottomColor, 'borderLeftColor' => $borderLeftColor,
                'borderTopWidth' => $borderTopWidth, 'borderRightWidth' => $borderRightWidth,
                'borderBottomWidth' => $borderBottomWidth, 'borderLeftWidth' => $borderLeftWidth,
            ];
        }

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

        // ::placeholder pseudo-element support
        // When input is empty and placeholder attribute is set, pass placeholder
        // text info so the rendering backend draws it in a dimmed color.
        $placeholder = $props['placeholder'] ?? '';
        $showPlaceholder = ($text === '' && $placeholder !== '');

        return [
            'type' => 'input',
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'bg' => $bg, 'color' => $fg, 'fontSize' => $fontSize,
            'text' => $showPlaceholder ? $placeholder : $text,
            'borderRadius' => $borderRadius, 'opacity' => $opacity, 'layer' => $layer,
            'placeholder' => $showPlaceholder,
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
                $contentH += (int)max($child->h, $itemH);
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
