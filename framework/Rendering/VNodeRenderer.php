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

    public function getRenderContext(): RenderContext
    {
        return $this->render_ctx;
    }

    /**
     * 计算节点的 padding-box 裁剪矩形（统一方法）。
     *
     * CSS Overflow Module L3 §3.2: clip region = padding box (excludes border).
     * 使用渲染坐标（layout + renderOffset），与子元素文本/item-clip
     * 处于同一坐标空间，保证嵌套 clip 相交计算一致。
     *
     * @return array{x: int, y: int, w: int, h: int}
     */
    private static function computePaddingBoxClip(RenderNode $node): array
    {
        $bw = (int)($node->style['borderWidth'] ?? 0);
        $ns = $node->style;
        $bl = (int)($ns['borderLeftWidth'] ?? $bw);
        $br = (int)($ns['borderRightWidth'] ?? $bw);
        $bt = (int)($ns['borderTopWidth'] ?? $bw);
        $bb = (int)($ns['borderBottomWidth'] ?? $bw);
        // visualW/visualH 优先，未设置时回退到 layout w/h
        $vw = ($node->visualW > 0 ? $node->visualW : $node->w);
        $vh = ($node->visualH > 0 ? $node->visualH : $node->h);
        return [
            'x' => $node->x + $node->renderOffsetX + $bl,
            'y' => $node->y + $node->renderOffsetY + $bt,
            'w' => max(0, $vw - $bl - $br),
            'h' => max(0, $vh - $bt - $bb),
        ];
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

        if (Config::get('debug_diag_enabled', false)) {
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
            $clip = self::computePaddingBoxClip($node);
            $this->scrollCtxStack[] = [
                'x' => $clip['x'], 'y' => $clip['y'],
                'w' => $clip['w'], 'h' => $clip['h'],
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
            // 使用统一方法计算 padding-box clip，坐标系与 scrollCtxStack 一致
            $clip = self::computePaddingBoxClip($node);
            $elementsByLayer[$layer][] = [
                'type' => 'clip-push',
                'x' => $clip['x'], 'y' => $clip['y'],
                'w' => $clip['w'], 'h' => $clip['h'],
                'layer' => $layer,
            ];
            // 滚动容器还需在 layer+1 推 clip 以裁切文字 (CSS Overflow L3 §3.2)
            if ($isScrollNode) {
                $textLayer = $layer + 1;
                if ($textLayer > $maxLayer) $maxLayer = $textLayer;
                if (!isset($elementsByLayer[$textLayer])) {
                    $elementsByLayer[$textLayer] = [];
                }
                $elementsByLayer[$textLayer][] = [
                    'type' => 'clip-push',
                    'x' => $clip['x'], 'y' => $clip['y'],
                    'w' => $clip['w'], 'h' => $clip['h'],
                    'layer' => $textLayer,
                ];
            }
        }

        // ── 计算子节点的累计滚动偏移 ──
        // 当前节点的 scroll 偏移对子节点生效
        $childOffsetX = $isFixed ? 0 : $accumOffsetX;
        $childOffsetY = $isFixed ? 0 : $accumOffsetY;
        if (!$isFixed && $node->isScrollContainer) {
            $childOffsetX -= $node->scrollLeft;
            $childOffsetY -= $node->scrollTop;
            error_log('[SCROLL_DBG] collect scrollContainer x=' . $node->x . ' y=' . $node->y . ' w=' . $node->w . ' h=' . $node->h . ' visualH=' . $node->visualH . ' scrollTop=' . $node->scrollTop . ' scrollLeft=' . $node->scrollLeft . ' childOffY=' . $childOffsetY . ' children=' . count($node->children));
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
            // 滚动容器在 layer+1 弹对应 clip (与上方的 textLayer push 配对)
            if ($isScrollNode) {
                $textLayer = $layer + 1;
                if ($textLayer > $maxLayer) $maxLayer = $textLayer;
                if (!isset($elementsByLayer[$textLayer])) {
                    $elementsByLayer[$textLayer] = [];
                }
                $elementsByLayer[$textLayer][] = [
                    'type' => 'clip-pop',
                    'layer' => $textLayer,
                ];
            }

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
    /**
     * CSS Text Module Level 3 §2: text-transform
     * uppercase / lowercase / capitalize / none
     */
    private static function applyTextTransform(string $text, string $transform): string
    {
        switch ($transform) {
            case 'uppercase':
                return mb_strtoupper($text, 'UTF-8');
            case 'lowercase':
                return mb_strtolower($text, 'UTF-8');
            case 'capitalize':
                $words = explode(' ', $text);
                foreach ($words as &$w) {
                    if ($w !== '') {
                        $w = mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8')
                           . mb_substr($w, 1, null, 'UTF-8');
                    }
                }
                return implode(' ', $words);
            default:
                return $text;
        }
    }

    /**
     * 测量文本总高度（ascent + descent），用于垂直居中。
     * 优先使用 C++ sk_measure_text_height 精确测量，退化使用 fontSize + 2 估算。
     */
    /**
     * CSS Fonts Module Level 3 §5: font-variant — small-caps 小大写
     * 将小写字母转为大写，使用缩小比例(0.7×)的字号渲染
     * @return array{text:string, fontSize:int} 转换后的文本和字号
     */
    private static function applyFontVariant(string $text, int $fontSize, string $variant): array
    {
        if ($variant === 'normal') {
            return ['text' => $text, 'fontSize' => $fontSize];
        }
        // small-caps: lowercase→uppercase, font size→0.7×
        // all-small-caps: all→uppercase, font size→0.7×
        $result = ['text' => $text, 'fontSize' => $fontSize];
        if ($variant === 'small-caps' || $variant === 'all-small-caps') {
            // Use mb_strtoupper for proper Unicode uppercase conversion
            if (function_exists('mb_strtoupper')) {
                $result['text'] = mb_strtoupper($text, 'UTF-8');
            } else {
                $result['text'] = strtoupper($text);
            }
            // Reduce font size for small-caps rendering
            $result['fontSize'] = max(6, (int)($fontSize * 0.7));
        }
        return $result;
    }

    /**
     * CSS Fonts Module Level §4: font-stretch — 字体宽度模拟
     * 通过调整字符间距近似 condensed(紧缩)/expanded(扩展)
     */
    private static function applyFontStretch(string $stretch): int
    {
        switch ($stretch) {
            case 'condensed':
            case 'semi-condensed':
            case 'ultra-condensed':
            case 'extra-condensed':
                return -1;  // slight negative spacing
            case 'expanded':
            case 'semi-expanded':
            case 'ultra-expanded':
            case 'extra-expanded':
                return 1;   // slight positive spacing
            default:
                return 0;
        }
    }

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

        // CSS 2.2 §9.2.4: display:none 元素不生成盒子，不参与渲染
        if (($style['display'] ?? '') === 'none') {
            return null;
        }

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
        // CSS规范：百分比基于对应边尺寸，水平半径用元素宽度，垂直半径用元素高度
        // 例如 160x100 盒子 + border-radius:50% → rx=80, ry=50（椭圆）
        if (isset($style['borderRadiusPercent'])) {
            $pct = $style['borderRadiusPercent'];
            $elemW = max(1, $w);
            $elemH = max(1, $h);
            $style['borderRadiusX'] = (int)($elemW * $pct / 100.0);
            $style['borderRadiusY'] = (int)($elemH * $pct / 100.0);
            $style['borderRadius'] = min($style['borderRadiusX'], $style['borderRadiusY']);
        }

        $layer = $node->layer;

        // 滚动裁切（position:fixed 元素不受祖先滚动容器影响）
        // 仅 CULL 完全不可见元素，不做坐标截断调整。
        // 坐标截断会导致 rect 与 text/item-clip 坐标不一致：
        // rect 被吸附到容器边界，而 text（在 makeDivElement/makeSpanElement 中
        // 使用 selfX/selfY = node->x/y + renderOffset 定位）保持原始位置，
        // 破坏"视觉随动"原则——列表项整体（rect+text+clip）应同步位移，
        // 统一由 clip-push/clip-pop 在渲染层做裁剪。
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

            // Y-axis: cull if completely outside the container
            if ($overflowY !== 'visible') {
                if ($y + $h <= $containerY || $y >= $containerY + $containerH) {
                    return null;
                }
            }

            // X-axis: cull if completely outside the container
            if ($overflowX !== 'visible') {
                if ($x + $w <= $containerX || $x >= $containerX + $containerW) {
                    return null;
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
            // Inline elements: #text has actual content to render, others create
            // span elements if they have content. <br> is the only zero-size line break.
            case 'span':
            case '#text':
            case 'b':
            case 'strong':
            case 'em':
            case 'i':
            case 'code':
                return $this->makeSpanElement($node, $style, $props, $x, $y, $w, $h, $layer);
            case 'br':
                // CSS: <br> generates a line break — render as zero-size placeholder
                // to maintain element tree structure alignment with browser DOM.
                $elements[] = ['type' => 'rect', 'x' => $x, 'y' => $y, 'w' => 0, 'h' => 0, 'color' => 0, 'borderRadius' => 0, 'borderRadiusX' => 0, 'borderRadiusY' => 0, 'opacity' => 1.0, 'layer' => $layer, 'noFill' => true, 'shadowX' => 0, 'shadowY' => 0, 'shadowBlur' => 0, 'shadowAlpha' => 0, 'shadowColor' => 0, 'shadowInset' => false, 'borderWidth' => 0, 'borderColor' => 0, 'borderTopColor' => 0, 'borderRightColor' => 0, 'borderBottomColor' => 0, 'borderLeftColor' => 0, 'borderTopWidth' => 0, 'borderRightWidth' => 0, 'borderBottomWidth' => 0, 'borderLeftWidth' => 0, 'borderStyle' => 'none', 'cursor' => ''];
                return $elements;
            case 'a':
            case 'label':
            case 'abbr':
            case 'cite':
            case 'dfn':
            case 'kbd':
            case 'mark':
            case 'q':
            case 'samp':
            case 'small':
            case 'sub':
            case 'sup':
            case 'time':
            case 'var':
                return $this->makeSpanElement($node, $style, $props, $x, $y, $w, $h, $layer);
            // Heading elements → span (inline semantic, not block)
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
        $borderRadiusX = $style['borderRadiusX'] ?? 0;
        $borderRadiusY = $style['borderRadiusY'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;
        $offsets = CssMappings::parseBoxShadowOffsets($style['boxShadow'] ?? '');
        $shadowX = $offsets['h']; $shadowY = $offsets['v']; $shadowBlur = $offsets['blur']; $shadowColor = $offsets['color']; $shadowAlpha = $offsets['alpha']; $shadowInset = $offsets['inset'];
        $backgroundClip = $style['backgroundClip'] ?? 'border-box';
        $backgroundAttachment = $style['backgroundAttachment'] ?? 'scroll';
        $gradientAngle = $style['gradientAngle'] ?? null;
        $gradientColors = $style['gradientColors'] ?? null;
        // Parse text-shadow (CSS Text Decoration Module L3 §7)
        $tsOffsets = CssMappings::parseBoxShadowOffsets($style['textShadow'] ?? '');
        $tsX = $tsOffsets['h']; $tsY = $tsOffsets['v']; $tsBlur = $tsOffsets['blur']; $tsColor = $tsOffsets['color']; $tsAlpha = $tsOffsets['alpha'];
        $borderWidth = $style['borderWidth'] ?? 0;
        $borderTopWidth = $style['borderTopWidth'] ?? $borderWidth;
        $borderRightWidth = $style['borderRightWidth'] ?? $borderWidth;
        $borderBottomWidth = $style['borderBottomWidth'] ?? $borderWidth;
        $borderLeftWidth = $style['borderLeftWidth'] ?? $borderWidth;
        $borderStyle = $style['borderStyle'] ?? 'solid';
        $borderStyle = $style['borderStyle'] ?? 'solid';
        $outlineOffset = $style['outlineOffset'] ?? 0;
        $borderColor = $style['borderColor'] ?? 0;
        $borderTopColor = $style['borderTopColor'] ?? $borderColor;
        $borderRightColor = $style['borderRightColor'] ?? $borderColor;
        $borderBottomColor = $style['borderBottomColor'] ?? $borderColor;
        $borderLeftColor = $style['borderLeftColor'] ?? $borderColor;

        // ── background-image 图片层（如果有）──
        $bgImageEl = null;
        if ($bgImageHandle !== 0) {
            $imgX = $backgroundAttachment === 'fixed' ? $node->x : $x;
            $imgY = $backgroundAttachment === 'fixed' ? $node->y : $y;
            $bgImageEl = [
                'type' => 'image',
                'handle' => $bgImageHandle,
                'x' => $imgX, 'y' => $imgY, 'w' => $w, 'h' => $h,
                'layer' => $layer,
                'backgroundRepeat' => $style['backgroundRepeat'] ?? 'repeat',
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

            // CSS Text Module Level 3 §2: text-transform
            $textTransform = $style['textTransform'] ?? 'none';
            if ($textTransform !== 'none') {
                $text = self::applyTextTransform($text, $textTransform);
            }

            // CSS Fonts Module L3 §5: font-variant small-caps
            $fontVariant = $style['fontVariant'] ?? 'normal';
            if ($fontVariant !== 'normal') {
                $fvRet = self::applyFontVariant($text, $fontSize, $fontVariant);
                $text = $fvRet['text'];
                $fontSize = $fvRet['fontSize'];
            }

            // CSS Fonts Module L3 §4: font-stretch (approximate via spacing)
            $fontStretchExtra = self::applyFontStretch($style['fontStretch'] ?? 'normal');
            if ($fontStretchExtra !== 0) {
                $style['letterSpacing'] = ($style['letterSpacing'] ?? 0) + $fontStretchExtra;
            }

            $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);

            // ── 元素自身坐标（scroll 偏移后的位置，不受 CULL 影响）──
            // 用于文本定位和 item clip，确保两者在同一坐标空间
            $selfY = $node->y + $node->renderOffsetY;
            $selfH = $node->visualH;
            $selfX = $node->x + $node->renderOffsetX;
            $selfW = $node->visualW;

            // CSS 2.2 §17.5: 文本内容位于 content area (border + padding 内部)
            $contentX = $selfX + $borderLeftWidth + ($style['paddingLeft'] ?? 0);
            $contentY = $selfY + $borderTopWidth + ($style['paddingTop'] ?? 0);
            $contentW = max(0, $selfW - $borderLeftWidth - $borderRightWidth - ($style['paddingLeft'] ?? 0) - ($style['paddingRight'] ?? 0));

            // ── text-overflow: ellipsis 文本溢出省略（CSS Text Module Level 3 §5.3）──
            // 标准 CSS 要求 overflow:hidden + white-space:nowrap 才生效，
            // 但 Px 文本在 layer+1 (不受 overflow:hidden 裁剪)，所以直接按 text-overflow 处理
            $textOverflow = $style['textOverflow'] ?? 'clip';
            // 标准 CSS 要求 overflow:hidden/clip 才生效
            $elOverflow = $style['overflow'] ?? 'visible';
            $hasOverflow = ($elOverflow === 'hidden' || $elOverflow === 'clip');
            $overflowWrap = $style['overflowWrap'] ?? 'normal';
            // Fallback: also check raw VNode props for overflow-wrap/word-wrap
            if ($overflowWrap === 'normal' && $node->sourceVNode !== null && $node->sourceVNode->props !== null) {
                $rawStyle = $node->sourceVNode->props['style'] ?? '';
                if ($rawStyle !== '' && (stripos($rawStyle, 'overflow-wrap:break-word') !== false || stripos($rawStyle, 'word-wrap:break-word') !== false)) {
                    $overflowWrap = 'break-word';
                }
            }
            $isBreakWord = ($overflowWrap === 'break-word' || $overflowWrap === 'anywhere');
            // Sync back to style array for TextOverflowProcessor
            if ($isBreakWord) {
                $style['overflowWrap'] = $overflowWrap;
            }
            if ($textOverflow === 'ellipsis' && $hasOverflow && $contentW > 0) {
                $overflowResult = TextOverflowProcessor::process($text, $contentW, $fontSize, (bool)$bold, $style);
                $text = $overflowResult['text'];
                // 多行 clamp 支持
                $overflowLines = $overflowResult['lines'];
                $overflowLineHeight = $overflowResult['lineHeight'];
                // 重新测量截断后的文本宽度
                $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);
                // ellipsis 时禁止自动换行
                $isWrappable = false;
            } elseif ($isBreakWord && $contentW > 0 && self::measureTextWidth($text, $fontSize, (bool)$bold) > $contentW) {
                // CSS Text Module Level 3 §6: overflow-wrap:break-word — 长单词强制换行
                $overflowResult = TextOverflowProcessor::process($text, $contentW, $fontSize, (bool)$bold, $style);
                $text = $overflowResult['text'];
                $overflowLines = $overflowResult['lines'];
                $overflowLineHeight = $overflowResult['lineHeight'];
                $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);
                $isWrappable = false;  // break-word 已处理换行
            } else {
                $overflowLines = null;
                $overflowLineHeight = 0;
                $isWrappable = true;  // 默认允许换行，稍后根据 whitespace 覆盖
            }

            $textX = $contentX + 4;
            if ($align === 'right') {
                $textX = $contentX + $contentW - 12 - $textWidth;
                if ($textX < $contentX + 4) $textX = $contentX + 4;
            } elseif ($align === 'center') {
                $textX = $contentX + (int)(($contentW - $textWidth) / 2);
                if ($textX < $contentX + 4) $textX = $contentX + 4;
            }
            if ($textX < $contentX + 4) $textX = $contentX + 4;

            // CSS Text Module Level 3 §2.1: text-indent — 首行缩进
            // 仅对块容器生效，缩进从 start edge 算起。正数缩进首行向起始边方向移动。
            // 对于左对齐 LTR 文本，首行向右缩进。不影响居中和右对齐。
            $textIndent = (int)($style['textIndent'] ?? 0);
            if ($textIndent > 0 && $align !== 'right' && $align !== 'center') {
                $textX += $textIndent;
            }

            // CSS Flexible Box Layout §8.2: justify-content:center → 主轴居中文本
            // 当元素是 flex 容器且 justifyContent=center 时，文本在 content area 内水平居中
            $display = $style['display'] ?? 'block';
            $justifyContent = $style['justifyContent'] ?? 'flex-start';
            if (($display === 'flex' || $display === 'inline-flex') && $justifyContent === 'center' && $textWidth > 0 && $contentW > $textWidth) {
                $textX = $contentX + (int)(($contentW - $textWidth) / 2);
            }

            // CSS Flexible Box Layout §8.2: align-items:center → 交叉轴居中文本
            // 当元素是 flex 容器且 alignItems=center 时，文本在 content area 内垂直居中
            $textY = $contentY;
            $alignItems = $style['alignItems'] ?? 'stretch';
            $contentH = max(0, $selfH - $borderTopWidth - $borderBottomWidth - ($style['paddingTop'] ?? 0) - ($style['paddingBottom'] ?? 0));
            // 精确测量文本总高度（ascent + descent），确保视觉居中
            $textHeight = self::measureTextHeight($fontSize, (bool)$bold);
            if (($display === 'flex' || $display === 'inline-flex') && $alignItems === 'center') {
                if ($contentH > $textHeight) {
                    $textY = $contentY + (int)(($contentH - $textHeight) / 2);
                }
            }

            // ── Auto-wrap text when exceeds content width ──
            // CSS Text Module Level 3 §7: white-space:normal 允许自动换行
            $isBold = (bool)$bold;
            $whitespace = $style['whiteSpace'] ?? 'normal';
            // ellipsis 分支已设为 false，非 ellipsis 分支设为 true 后在这里根据 whitespace 修正
            if ($whitespace === 'nowrap' || $whitespace === 'pre') {
                $isWrappable = false;
            }
            $lineH = 0;
            if ($isWrappable && $textWidth > $contentW && $contentW > 20) {
                // Compute line-height for multi-line rendering
                $lhVal = $style['lineHeight'] ?? 'normal';
                if (is_string($lhVal) && $lhVal !== 'normal' && $lhVal !== '') {
                    if (str_contains($lhVal, 'px')) {
                        $lineH = (int)$lhVal;
                    } else {
                        $lineH = (int)($fontSize * (float)$lhVal);
                    }
                }
                if ($lineH <= 0) {
                    $lineH = (int)($fontSize * 1.2);
                }
            }

            $elements = [];
            if ($hasBg || $hasBorder) {
                // CSS Backgrounds §3.7: background-clip — 背景裁剪区域
                $bgX = $backgroundAttachment === 'fixed' ? $node->x : $x;
                $bgY = $backgroundAttachment === 'fixed' ? $node->y : $y;
                $clipX = $bgX; $clipY = $bgY; $clipW = $w; $clipH = $h;
                if ($backgroundClip === 'padding-box' && ($borderLeftWidth > 0 || $borderTopWidth > 0 || $borderRightWidth > 0 || $borderBottomWidth > 0)) {
                    $clipX += $borderLeftWidth; $clipY += $borderTopWidth;
                    $clipW -= ($borderLeftWidth + $borderRightWidth);
                    $clipH -= ($borderTopWidth + $borderBottomWidth);
                } elseif ($backgroundClip === 'content-box') {
                    $pl = $style['paddingLeft'] ?? 0; $pt = $style['paddingTop'] ?? 0;
                    $pr = $style['paddingRight'] ?? 0; $pb = $style['paddingBottom'] ?? 0;
                    $clipX += ($borderLeftWidth + $pl); $clipY += ($borderTopWidth + $pt);
                    $clipW -= ($borderLeftWidth + $borderRightWidth + $pl + $pr);
                    $clipH -= ($borderTopWidth + $borderBottomWidth + $pt + $pb);
                }
                $elements[] = ['type' => 'rect', 'x' => $clipX, 'y' => $clipY, 'w' => max(0,$clipW), 'h' => max(0,$clipH), 'color' => $drawColor, 'borderRadius' => $borderRadius, 'borderRadiusX' => $borderRadiusX, 'borderRadiusY' => $borderRadiusY, 'opacity' => $opacity, 'layer' => $layer, 'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowBlur' => $shadowBlur, 'shadowAlpha' => $shadowAlpha, 'shadowColor' => $shadowColor, 'shadowInset' => $shadowInset, 'borderWidth' => $borderWidth, 'borderColor' => $borderColor, 'borderTopColor' => $borderTopColor, 'borderRightColor' => $borderRightColor, 'borderBottomColor' => $borderBottomColor, 'borderLeftColor' => $borderLeftColor, 'borderTopWidth' => $borderTopWidth, 'borderRightWidth' => $borderRightWidth, 'borderBottomWidth' => $borderBottomWidth, 'borderLeftWidth' => $borderLeftWidth, 'borderStyle' => $borderStyle, 'noFill' => $noFill, 'cursor' => $cursor, 'gradientAngle' => $gradientAngle, 'gradientColors' => $gradientColors, 'outlineOffset' => $outlineOffset, 'backgroundClip' => $backgroundClip];
            }
            if ($bgImageEl !== null) {
                $elements[] = $bgImageEl;
            }

            if ($overflowLines !== null && count($overflowLines) > 0) {
                // ── -webkit-line-clamp 多行渲染（TextOverflowProcessor 已处理截断与省略号）──
                $lineIdx = 0;
                $maxLineW = 0;
                $lineHeight = $overflowLineHeight > 0 ? $overflowLineHeight : (int)($fontSize * 1.2);
                foreach ($overflowLines as $seg) {
                    $segW = self::measureTextWidth($seg, $fontSize, $isBold);
                    if ($segW > $maxLineW) $maxLineW = $segW;
                    $segX = $contentX + 4;
                    if ($align === 'right') {
                        $segX = $contentX + $contentW - 12 - $segW;
                        if ($segX < $contentX + 4) $segX = $contentX + 4;
                    } elseif ($align === 'center') {
                        $segX = $contentX + (int)(($contentW - $segW) / 2);
                        if ($segX < $contentX + 4) $segX = $contentX + 4;
                    }
                    // text-indent 仅作用于第一行
                    if ($textIndent > 0 && $lineIdx === 0 && $align !== 'right' && $align !== 'center') {
                        $segX += $textIndent;
                    }
                    $segY = $contentY + $lineIdx * $lineHeight;
                    $elements[] = ['type' => 'text', 'text' => $seg, 'x' => $segX, 'y' => $segY,
                        'fontSize' => $fontSize, 'color' => $textColor, 'bold' => $isBold,
                        'fontFamily' => $style['fontFamily'] ?? '',
                        'align' => $align, 'layer' => $layer + 1, 'cursor' => $cursor,
                        'decorationLine' => $style['textDecorationLine'] ?? 'none',
                        'decorationColor' => $style['textDecorationColor'] ?? $textColor,
                        'decorationStyle' => $style['decorationStyle'] ?? 'solid',
                        'decorationThickness' => $style['decorationThickness'] ?? 0,
                        'underlineOffset' => $style['underlineOffset'] ?? 0,
                        'textWidth' => $segW,
                        'textShadowX' => $tsX, 'textShadowY' => $tsY, 'textShadowBlur' => $tsBlur,
                        'textShadowColor' => $tsColor, 'textShadowAlpha' => $tsAlpha,
                        'letterSpacing' => $style['letterSpacing'] ?? 0];
                    $lineIdx++;
                }
                $node->textRenderInfo = [
                    'x' => ($contentX + 4) - $node->renderOffsetX,
                    'y' => $contentY - $node->renderOffsetY,
                    'textHeight' => $lineHeight * count($overflowLines),
                    'textWidth' => $maxLineW,
                ];
            } elseif ($isWrappable && $textWidth > $contentW && $contentW > 20 && $lineH > 0) {
                // ── Multi-line wrapped rendering ──
                $lines = [];
                $currentLine = '';
                $textLen = strlen($text);
                for ($i = 0; $i < $textLen;) {
                    $charLen = 1;
                    $b = ord($text[$i]);
                    if ($b >= 0xF0) $charLen = 4;
                    elseif ($b >= 0xE0) $charLen = 3;
                    elseif ($b >= 0xC0) $charLen = 2;
                    $chunk = substr($text, $i, $charLen);
                    $candidate = $currentLine . $chunk;
                    $candidateW = self::measureTextWidth($candidate, $fontSize, $isBold);
                    // Available width for text (4px left pad, 12px right pad)
                    $availW = max(1, $contentW - 4);
                    if ($candidateW > $availW && $currentLine !== '') {
                        $lines[] = $currentLine;
                        $currentLine = $chunk;
                    } else {
                        $currentLine = $candidate;
                    }
                    $i += $charLen;
                }
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }

                $lineIdx = 0;
                $maxLineW = 0;
                foreach ($lines as $seg) {
                    $segW = self::measureTextWidth($seg, $fontSize, $isBold);
                    if ($segW > $maxLineW) $maxLineW = $segW;
                    $segX = $contentX + 4;
                    if ($align === 'right') {
                        $segX = $contentX + $contentW - 12 - $segW;
                        if ($segX < $contentX + 4) $segX = $contentX + 4;
                    } elseif ($align === 'center') {
                        $segX = $contentX + (int)(($contentW - $segW) / 2);
                        if ($segX < $contentX + 4) $segX = $contentX + 4;
                    }
                    $segY = $contentY + $lineIdx * $lineH;
                    $elements[] = ['type' => 'text', 'text' => $seg, 'x' => $segX, 'y' => $segY,
                        'fontSize' => $fontSize, 'color' => $textColor, 'bold' => $isBold,
                        'fontFamily' => $style['fontFamily'] ?? '',
                        'align' => $align, 'layer' => $layer + 1, 'cursor' => $cursor,
                        'decorationLine' => $style['textDecorationLine'] ?? 'none',
                        'decorationColor' => $style['textDecorationColor'] ?? $textColor,
                        'decorationStyle' => $style['textDecorationStyle'] ?? 'solid',
                        'decorationThickness' => $style['textDecorationThickness'] ?? 0,
                        'underlineOffset' => $style['textUnderlineOffset'] ?? 0,
                        'textWidth' => self::measureTextWidth($seg, $fontSize, $isBold),
                        'textShadowX' => $tsX, 'textShadowY' => $tsY, 'textShadowBlur' => $tsBlur,
                        'textShadowColor' => $tsColor, 'textShadowAlpha' => $tsAlpha,
                        'letterSpacing' => $style['letterSpacing'] ?? 0];
                    $lineIdx++;
                }

                // 存储文本渲染位置信息（第一行位置）
                $node->textRenderInfo = [
                    'x' => ($contentX + 4) - $node->renderOffsetX,
                    'y' => $contentY - $node->renderOffsetY,
                    'textHeight' => $lineH * count($lines),
                    'textWidth' => $maxLineW,
                ];
            } else {
                // ── Single-line rendering (original) ──
                $elements[] = ['type' => 'text', 'text' => $text, 'x' => $textX, 'y' => $textY,
                        'fontSize' => $fontSize, 'color' => $textColor, 'bold' => $isBold,
                        'fontFamily' => $style['fontFamily'] ?? '',
                        'align' => $align, 'layer' => $layer + 1, 'cursor' => $cursor,
                        'decorationLine' => $style['textDecorationLine'] ?? 'none',
                        'decorationColor' => $style['decorationColor'] ?? $textColor,
                        'decorationStyle' => $style['decorationStyle'] ?? 'solid',
                        'decorationThickness' => $style['decorationThickness'] ?? 0,
                        'underlineOffset' => $style['underlineOffset'] ?? 0,
                        'textWidth' => self::measureTextWidth($text, $fontSize, $isBold),
                        'textShadowX' => $tsX, 'textShadowY' => $tsY, 'textShadowBlur' => $tsBlur,
                        'textShadowColor' => $tsColor, 'textShadowAlpha' => $tsAlpha,
                        'letterSpacing' => $style['letterSpacing'] ?? 0];

                // 存储文本渲染位置信息（用于 layout dump 验证垂直居中）
                $node->textRenderInfo = [
                    'x' => $textX - $node->renderOffsetX,
                    'y' => $textY - $node->renderOffsetY,
                    'textHeight' => max($textHeight, 0),
                    'textWidth' => $textWidth,
                ];
            }

            if (count($elements) === 1) {
                return $elements[0];
            }

            // ── overflow:hidden 文本层 clip ──
            // 使用统一 computePaddingBoxClip 确保与 scroll 容器 clip 同一坐标系
            $elOverflowHidden = ($style['overflow'] ?? 'visible') === 'hidden';
            if ($elOverflowHidden && !$node->isScrollContainer && $selfW > 0 && $selfH > 0) {
                $itemClip = self::computePaddingBoxClip($node);
                $clipX = $itemClip['x'];
                $clipY = $itemClip['y'];
                $clipW = $itemClip['w'];
                $clipH = $itemClip['h'];
                if ($clipW > 0 && $clipH > 0) {
                    $textLayer = $layer + 1;
                    $clipPush = ['type' => 'clip-push', 'x' => $clipX, 'y' => $clipY, 'w' => $clipW, 'h' => $clipH, 'layer' => $textLayer];
                    $clipPop = ['type' => 'clip-pop', 'layer' => $textLayer];
                    $newElements = [];
                    $inserted = false;
                    foreach ($elements as $el) {
                        $elLayer = $el['layer'] ?? $layer;
                        if (!$inserted && $elLayer === $textLayer) {
                            $newElements[] = $clipPush;
                            $inserted = true;
                        }
                        $newElements[] = $el;
                    }
                    if ($inserted) {
                        $newElements[] = $clipPop;
                        $elements = $newElements;
                    }
                }
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
                'color' => $drawColor, 'borderRadius' => $borderRadius, 'borderRadiusX' => $borderRadiusX, 'borderRadiusY' => $borderRadiusY, 'opacity' => $opacity, 'layer' => $layer,
                'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowBlur' => $shadowBlur, 'shadowAlpha' => $shadowAlpha, 'shadowColor' => $shadowColor, 'shadowInset' => $shadowInset,
                'borderWidth' => $borderWidth, 'borderColor' => $borderColor,
                'borderTopColor' => $borderTopColor, 'borderRightColor' => $borderRightColor,
                'borderBottomColor' => $borderBottomColor, 'borderLeftColor' => $borderLeftColor,
                'borderTopWidth' => $borderTopWidth, 'borderRightWidth' => $borderRightWidth,
                'borderBottomWidth' => $borderBottomWidth, 'borderLeftWidth' => $borderLeftWidth,
                'borderStyle' => $borderStyle,
                'noFill' => $noFill,
                'cursor' => $cursor,
                'gradientAngle' => $gradientAngle, 'gradientColors' => $gradientColors,
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
        $fontSize = $style['fontSize'] ?? 14;
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
        // CSS 2.2 §18.2: color 属性的初始值为 black (0x000000)
        if ($color === null) $color = 0x000000;
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

        $diagLogPath = Config::get('debug_diag_log_path', '');
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
        $containerX = (int)($props['container-x'] ?? $x);

        // CSS Fonts Module L3 §5: font-variant small-caps
        $fontVariant = $style['fontVariant'] ?? 'normal';
        if ($fontVariant !== 'normal') {
            $fvRet = self::applyFontVariant($text, $fontSize, $fontVariant);
            $text = $fvRet['text'];
            $fontSize = $fvRet['fontSize'];
        }

        // CSS Fonts Module L3 §4: font-stretch (approximate via spacing)
        $fontStretchExtra = self::applyFontStretch($style['fontStretch'] ?? 'normal');
        if ($fontStretchExtra !== 0) {
            $style['letterSpacing'] = ($style['letterSpacing'] ?? 0) + $fontStretchExtra;
        }

        // Parse text-shadow
        $tsOffsets = CssMappings::parseBoxShadowOffsets($style['textShadow'] ?? '');
        $tsX = $tsOffsets['h']; $tsY = $tsOffsets['v']; $tsBlur = $tsOffsets['blur']; $tsColor = $tsOffsets['color']; $tsAlpha = $tsOffsets['alpha'];

        // CSS Inline Layout L3 §2: vertical-align — 内联元素垂直对齐偏移
        $verticalAlign = $style['verticalAlign'] ?? 'baseline';
        $vaY = 0;
        if ($verticalAlign !== 'baseline' && $verticalAlign !== 'top' && $verticalAlign !== 'bottom') {
            $textHeight = self::measureTextHeight($fontSize, (bool)$bold);
            switch ($verticalAlign) {
                case 'sub':        $vaY = (int)($fontSize * 0.25); break;
                case 'super':      $vaY = -(int)($fontSize * 0.35); break;
                case 'middle':     $vaY = -(int)($fontSize * 0.2); break;
                case 'text-top':   $vaY = 0; break;
                case 'text-bottom':$vaY = $textHeight - $fontSize; break;
            }
        }

        // Check raw VNode props for overflow-wrap/word-wrap fallback
        if (($style['overflowWrap'] ?? 'normal') === 'normal' && $node->sourceVNode !== null && $node->sourceVNode->props !== null) {
            $rawStyle = $node->sourceVNode->props['style'] ?? '';
            if ($rawStyle !== '' && (stripos($rawStyle, 'overflow-wrap:break-word') !== false || stripos($rawStyle, 'word-wrap:break-word') !== false)) {
                $style['overflowWrap'] = 'break-word';
            }
        }

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
                    'x' => $segX, 'y' => $lineY + $vaY,
                    'fontSize' => $fontSize, 'color' => $color, 'bold' => $bold,
                    'align' => 'left', 'layer' => $layer,
                    'decorationLine' => $style['textDecorationLine'] ?? 'none',
                    'decorationColor' => $style['textDecorationColor'] ?? $color,
                    'decorationStyle' => $style['textDecorationStyle'] ?? 'solid',
                    'decorationThickness' => $style['textDecorationThickness'] ?? 0,
                    'underlineOffset' => $style['textUnderlineOffset'] ?? 0,
                    'textWidth' => self::measureTextWidth($seg, $fontSize, (bool)$bold),
                    'textShadowX' => $tsX, 'textShadowY' => $tsY, 'textShadowBlur' => $tsBlur,
                    'textShadowColor' => $tsColor, 'textShadowAlpha' => $tsAlpha,
                    'letterSpacing' => $style['letterSpacing'] ?? 0];
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
        }

        // 存储文本渲染位置信息（用于 layout dump 验证垂直居中）
        if (!isset($textWidth)) {
            $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);
        }
        $textHeight = self::measureTextHeight($fontSize, (bool)$bold);
        $node->textRenderInfo = [
            'x' => $x - $node->renderOffsetX,
            'y' => $y - $node->renderOffsetY,
            'textHeight' => $textHeight,
            'textWidth' => $textWidth,
        ];

        return [
            'type' => 'text', 'text' => $text,
            'x' => $x, 'y' => $y + $vaY,
            'fontSize' => $fontSize, 'color' => $color, 'bold' => $bold,
            'align' => $align, 'layer' => $layer,
            'decorationLine' => $style['textDecorationLine'] ?? 'none',
            'decorationColor' => $style['textDecorationColor'] ?? $color,
            'decorationStyle' => $style['textDecorationStyle'] ?? 'solid',
            'decorationThickness' => $style['textDecorationThickness'] ?? 0,
            'underlineOffset' => $style['textUnderlineOffset'] ?? 0,
            'textWidth' => self::measureTextWidth($text, $fontSize, (bool)$bold),
            'textShadowX' => $tsX, 'textShadowY' => $tsY, 'textShadowBlur' => $tsBlur,
            'textShadowColor' => $tsColor, 'textShadowAlpha' => $tsAlpha,
            'letterSpacing' => $style['letterSpacing'] ?? 0];
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
        $borderStyle = $style['borderStyle'] ?? 'solid';
        $borderColor = 0;
        if ($borderWidth > 0 || $borderTopWidth > 0 || $borderRightWidth > 0 || $borderBottomWidth > 0 || $borderLeftWidth > 0) {
            $borderColor = (int)($style['borderColor'] ?? ($bg !== 0 ? ($bg & 0xFFFFFF) >> 1 : 0));
        }
        $borderTopColor = $style['borderTopColor'] ?? $borderColor;
        $borderRightColor = $style['borderRightColor'] ?? $borderColor;
        $borderBottomColor = $style['borderBottomColor'] ?? $borderColor;
        $borderLeftColor = $style['borderLeftColor'] ?? $borderColor;
        $borderRadius = $style['borderRadius'] ?? 0;
        $borderRadiusX = $style['borderRadiusX'] ?? 0;
        $borderRadiusY = $style['borderRadiusY'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;
        $shadowOffsets = CssMappings::parseBoxShadowOffsets($style['boxShadow'] ?? '');
        $shadowX = $shadowOffsets['h']; $shadowY = $shadowOffsets['v']; $shadowBlur = $shadowOffsets['blur']; $shadowColor = $shadowOffsets['color']; $shadowAlpha = $shadowOffsets['alpha']; $shadowInset = $shadowOffsets['inset'];

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
            'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowBlur' => $shadowBlur, 'shadowAlpha' => $shadowAlpha, 'shadowColor' => $shadowColor, 'shadowInset' => $shadowInset, 'cursor' => $cursor,
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
        $borderRadiusX = $style['borderRadiusX'] ?? 0;
        $borderRadiusY = $style['borderRadiusY'] ?? 0;
        $opacity = $style['opacity'] ?? 1.0;

        // box-shadow
        $shadowOffsets = CssMappings::parseBoxShadowOffsets($style['boxShadow'] ?? '');
        $shadowX = $shadowOffsets['h']; $shadowY = $shadowOffsets['v']; $shadowBlur = $shadowOffsets['blur']; $shadowColor = $shadowOffsets['color']; $shadowAlpha = $shadowOffsets['alpha']; $shadowInset = $shadowOffsets['inset'];

        // border
        $borderWidth = $style['borderWidth'] ?? 0;
        $borderTopWidth = $style['borderTopWidth'] ?? $borderWidth;
        $borderRightWidth = $style['borderRightWidth'] ?? $borderWidth;
        $borderBottomWidth = $style['borderBottomWidth'] ?? $borderWidth;
        $borderLeftWidth = $style['borderLeftWidth'] ?? $borderWidth;
        $borderStyle = $style['borderStyle'] ?? 'solid';
        $borderColor = $style['borderColor'] ?? 0;
        $borderTopColor = $style['borderTopColor'] ?? $borderColor;
        $borderRightColor = $style['borderRightColor'] ?? $borderColor;
        $borderBottomColor = $style['borderBottomColor'] ?? $borderColor;
        $borderLeftColor = $style['borderLeftColor'] ?? $borderColor;

        // object-fit: CSS Images §5.5 控制替换内容如何适应容器
        $objectFit = $style['objectFit'] ?? 'fill';
        $objectPosition = $style['objectPosition'] ?? '50% 50%';
        // Compute image destination rect based on object-fit
        $imgX = $x; $imgY = $y; $imgW = $w; $imgH = $h;
        if ($imageHandle !== 0 && $objectFit !== 'fill') {
            $natW = sk_get_image_width($imageHandle);
            $natH = sk_get_image_height($imageHandle);
            if ($natW > 0 && $natH > 0) {
                $containerRatio = (float)$w / (float)$h;
                $imageRatio = (float)$natW / (float)$natH;
                if ($objectFit === 'contain') {
                    if ($containerRatio > $imageRatio) {
                        $imgH = $h; $imgW = (int)($h * $imageRatio);
                    } else {
                        $imgW = $w; $imgH = (int)($w / $imageRatio);
                    }
                    $imgX = $x + (int)(($w - $imgW) / 2);
                    $imgY = $y + (int)(($h - $imgH) / 2);
                } elseif ($objectFit === 'cover') {
                    if ($containerRatio > $imageRatio) {
                        $imgW = $w; $imgH = (int)($w / $imageRatio);
                    } else {
                        $imgH = $h; $imgW = (int)($h * $imageRatio);
                    }
                    $imgX = $x + (int)(($w - $imgW) / 2);
                    $imgY = $y + (int)(($h - $imgH) / 2);
                } elseif ($objectFit === 'none') {
                    $imgW = $natW; $imgH = $natH;
                    $imgX = $x + (int)(($w - $imgW) / 2);
                    $imgY = $y + (int)(($h - $imgH) / 2);
                } elseif ($objectFit === 'scale-down') {
                    // Smaller of 'none' and 'contain'
                    $noneW = $natW; $noneH = $natH;
                    if ($containerRatio > $imageRatio) {
                        $contH = $h; $contW = (int)($h * $imageRatio);
                    } else {
                        $contW = $w; $contH = (int)($w / $imageRatio);
                    }
                    if ($noneW <= $contW && $noneH <= $contH) {
                        $imgW = $noneW; $imgH = $noneH;
                    } else {
                        $imgW = $contW; $imgH = $contH;
                    }
                    $imgX = $x + (int)(($w - $imgW) / 2);
                    $imgY = $y + (int)(($h - $imgH) / 2);
                }
            }
        }

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
                'x' => $imgX, 'y' => $imgY, 'w' => $imgW, 'h' => $imgH,
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
                'shadowX' => $shadowX, 'shadowY' => $shadowY, 'shadowBlur' => $shadowBlur, 'shadowAlpha' => $shadowAlpha, 'shadowColor' => $shadowColor, 'shadowInset' => $shadowInset,
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
        $fontSize = $style['fontSize'] ?? 14;
        $borderRadius = $style['borderRadius'] ?? 0;
        $borderRadiusX = $style['borderRadiusX'] ?? 0;
        $borderRadiusY = $style['borderRadiusY'] ?? 0;
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
        $borderRadiusX = $style['borderRadiusX'] ?? 0;
        $borderRadiusY = $style['borderRadiusY'] ?? 0;
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
