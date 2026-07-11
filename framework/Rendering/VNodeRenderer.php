<?php

namespace Px\Rendering;

use native_types;
use Px\Core\Config;
use Px\Rendering\Layout\PhysicalFragment;
use Px\Interfaces\ReactiveComponentInterface;
use Px\ReactiveComponent;

class VNodeRenderer
{
    private ReactiveComponentInterface $component;
    private RenderContext $render_ctx;
    private int $currentPaintFrame = 0;
    private array $paintFlags = []; // spl_object_id → lastPaintFrame
    private array $scrollCtxStack = [];
    private array $componentStack = [];
    private array $renderOffsetsX = [];
    private array $renderOffsetsY = [];

    private function setRenderOffsetX(RenderNode $node, int $value): void
    {
        $this->renderOffsetsX[spl_object_id($node)] = $value;
    }

    private function setRenderOffsetY(RenderNode $node, int $value): void
    {
        $this->renderOffsetsY[spl_object_id($node)] = $value;
    }

    private function getRenderOffsetX(RenderNode $node): int
    {
        return $this->renderOffsetsX[spl_object_id($node)] ?? 0;
    }

    private function getRenderOffsetY(RenderNode $node): int
    {
        return $this->renderOffsetsY[spl_object_id($node)] ?? 0;
    }

    /** Paint frame tracking (替代 RenderNode.lastPaintFrame 外置) */
    private function needsPaint(RenderNode $node, int $frame): bool
    {
        return ($this->paintFlags[spl_object_id($node)] ?? 0) !== $frame;
    }

    private function markPainted(RenderNode $node, int $frame): void
    {
        $this->paintFlags[spl_object_id($node)] = $frame;
    }

    public function __construct(ReactiveComponentInterface $component, RenderContext $render_ctx)
    {
        $this->component = $component;
        $this->render_ctx = $render_ctx;
    }

    public function getRenderContext(): RenderContext
    {
        return $this->render_ctx;
    }

    private function computePaddingBoxClip(RenderNode $node): array
    {
        $cs = $node->computedStyle;
        $bl = (int)($cs?->borderLeftWidth ?? 0);
        $br = (int)($cs?->borderRightWidth ?? 0);
        $bt = (int)($cs?->borderTopWidth ?? 0);
        $bb = (int)($cs?->borderBottomWidth ?? 0);
        $vw = ($node->visualW > 0 ? $node->visualW : $node->w);
        $vh = ($node->visualH > 0 ? $node->visualH : $node->h);
        return [
            'x' => (int)($node->x ?? 0) + $this->getRenderOffsetX($node) + (int)($bl ?? 0),
            'y' => $node->y + $this->getRenderOffsetY($node) + $bt,
            'w' => max(0, $vw - $bl - $br),
            'h' => max(0, $vh - $bt - $bb),
        ];
    }

    public function render(RenderNode $root): void
    {
        \Px\Core\PerfCounter::start('render_collect');
        $this->render_ctx->beginFrame();
        if ($this->currentPaintFrame === PHP_INT_MAX) {
            $this->currentPaintFrame = 1;
            $this->resetAllPaintFlags($root);
        } else {
            $this->currentPaintFrame++;
        }
        $elementsByLayer = [];
        $maxLayer = 0;
        $this->collectElements($root, $elementsByLayer, $maxLayer);
        Diag::log(1, 'vnode:collect', ['elements' => array_sum(array_map('count', $elementsByLayer)), 'layers' => $maxLayer + 1]);
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
     * Phase 3.5: 从 Fragment 树渲染（替代 RenderNode 树）。
     * Fragment 自带 ComputedStyle 快照和绝对坐标，不需要 renderOffsetX/Y。
     */
    public function renderFromFragment(\Px\Rendering\Layout\PhysicalFragment $root): void
    {
        \Px\Core\PerfCounter::start('render_collect');
        $this->render_ctx->beginFrame();
        if ($this->currentPaintFrame === PHP_INT_MAX) {
            $this->currentPaintFrame = 1;
        } else {
            $this->currentPaintFrame++;
        }
        $elementsByLayer = [];
        $maxLayer = 0;
        $this->collectElementsFromFragment($root, $elementsByLayer, $maxLayer);
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
     * Phase 3.5: 从 Fragment 树收集元素（替代 collectElements）。
     * Fragment 坐标是绝对的，无需 accumOffsetX/Y。
     */
    private function collectElementsFromFragment(
        \Px\Rendering\Layout\PhysicalFragment $frag,
        array &$elementsByLayer,
        int &$maxLayer,
    ): void {
        $node = $frag->sourceNode;
        if ($node === null) return;

        $el = $this->fragmentToElement($frag);
        if ($el !== null) {
            $layer = $frag->layer;
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
            $elementsByLayer[$layer][] = $el;
        }

        // Scroll clip
        $isScrollNode = $frag->isScrollContainer;
        if ($isScrollNode && $frag->style !== null) {
            $clip = [
                'x' => $frag->x,
                'y' => $frag->y,
                'w' => $frag->w,
                'h' => $frag->h,
            ];
            $this->scrollCtxStack[] = [
                'x' => $clip['x'], 'y' => $clip['y'],
                'w' => $clip['w'], 'h' => $clip['h'],
                'scrollTop' => $frag->scrollTop,
                'scrollLeft' => $frag->scrollLeft,
                'overflowX' => $frag->style->overflowX?->value ?? $frag->style->overflow?->value ?? 'visible',
                'overflowY' => $frag->style->overflowY?->value ?? $frag->style->overflow?->value ?? 'visible',
                'layer' => $frag->layer,
            ];
        }

        // 递归子 Fragment
        foreach ($frag->children as $childFrag) {
            $this->collectElementsFromFragment($childFrag, $elementsByLayer, $maxLayer);
        }

        if ($isScrollNode) {
            array_pop($this->scrollCtxStack);
        }
    }

    /**
     * Phase 3.5: 将 Fragment 转为 drawElement 数组。
     * 替代 renderNodeToElement，但使用 Fragment 自带的几何和样式。
     */
    private function fragmentToElement(\Px\Rendering\Layout\PhysicalFragment $frag): ?array
    {
        // 简化实现：通过 sourceNode 委托到现有 renderNodeToElement
        // 后续可改为直接从 Fragment 构造元素数组
        $node = $frag->sourceNode;
        if ($node === null) return null;

        $el = $this->renderNodeToElement($node);
        if ($el === null) return null;

        // 用 Fragment 的绝对坐标覆盖
        $el['x'] = $frag->x;
        $el['y'] = $frag->y;
        $el['w'] = $frag->w;
        $el['h'] = $frag->h;
        $el['visualW'] = $frag->visualW;
        $el['visualH'] = $frag->visualH;

        // 移除 renderOffset（Fragment 坐标已经是绝对的）
        unset($el['renderOffsetX'], $el['renderOffsetY']);

        return $el;
    }

    private function collectElements(RenderNode $node, array &$elementsByLayer, int &$maxLayer, int $accumOffsetX = 0, int $accumOffsetY = 0): void
    {
        $isFixed = ($node->computedStyle?->position?->value ?? '') === 'fixed';
        $this->setRenderOffsetX($node, $isFixed ? 0 : $accumOffsetX);
        $this->setRenderOffsetY($node, $isFixed ? 0 : $accumOffsetY);
        if (!$this->needsPaint($node, $this->currentPaintFrame)) {
            $childOffsetX = $isFixed ? 0 : $accumOffsetX;
            $childOffsetY = $isFixed ? 0 : $accumOffsetY;
            if (!$isFixed && (bool)($node->isScrollContainer ?? false)) {
                $childOffsetX -= (int)($node->scrollLeft ?? 0);
                $childOffsetY -= (int)($node->scrollTop ?? 0);
            }
            foreach ($node->children as $child) {
                $this->collectElements($child, $elementsByLayer, $maxLayer, $childOffsetX, $childOffsetY);
            }
            return;
        }
        if ($node->type === '#root') {
            $childOffsetX = $isFixed ? 0 : $accumOffsetX;
            $childOffsetY = $isFixed ? 0 : $accumOffsetY;
            if (!$isFixed && (bool)($node->isScrollContainer ?? false)) {
                $childOffsetX -= (int)($node->scrollLeft ?? 0);
                $childOffsetY -= (int)($node->scrollTop ?? 0);
            }
            foreach ($node->children as $child) {
                $this->collectElements($child, $elementsByLayer, $maxLayer, $childOffsetX, $childOffsetY);
            }
            return;
        }
        $el = $this->renderNodeToElement($node);
        if ($el !== null) {
            $layer = (int)($node->layer ?? 0);
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
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
        $pushedClip = false;
        $isScrollNode = (bool)($node->isScrollContainer ?? false);
        if ($isScrollNode) {
            $clip = self::computePaddingBoxClip($node);
            $this->scrollCtxStack[] = [
                'x' => $clip['x'], 'y' => $clip['y'],
                'w' => $clip['w'], 'h' => $clip['h'],
                'scrollTop' => (int)($node->scrollTop ?? 0),
                'scrollLeft' => (int)($node->scrollLeft ?? 0),
                'overflowX' => $node->computedStyle?->overflowX?->value ?? $node->computedStyle?->overflow?->value ?? 'visible',
                'overflowY' => $node->computedStyle?->overflowY?->value ?? $node->computedStyle?->overflow?->value ?? 'visible',
                'layer' => $node->layer,
            ];
            $pushedClip = true;
        } else {
            $noX = $node->computedStyle?->overflowX?->value ?? $node->computedStyle?->overflow?->value ?? 'visible';
            $noY = $node->computedStyle?->overflowY?->value ?? $node->computedStyle?->overflow?->value ?? 'visible';
            if ($noX === 'hidden' || $noY === 'hidden') {
                $pushedClip = true;
            }
        }
        if ($pushedClip) {
            $layer = (int)($node->layer ?? 0);
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
            $clip = self::computePaddingBoxClip($node);
            $elementsByLayer[$layer][] = [
                'type' => 'clip-push',
                'x' => $clip['x'], 'y' => $clip['y'],
                'w' => $clip['w'], 'h' => $clip['h'],
                'layer' => $layer,
            ];
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
        $childOffsetX = $isFixed ? 0 : $accumOffsetX;
        $childOffsetY = $isFixed ? 0 : $accumOffsetY;
        if (!$isFixed && (bool)($node->isScrollContainer ?? false)) {
            $childOffsetX -= (int)($node->scrollLeft ?? 0);
            $childOffsetY -= (int)($node->scrollTop ?? 0);

        }
        if ($node->type !== 'button') {
            foreach ($node->children as $child) {
                $this->collectElements($child, $elementsByLayer, $maxLayer, $childOffsetX, $childOffsetY);
            }
        }
        if ($pushedClip) {
            if ($isScrollNode) {
                array_pop($this->scrollCtxStack);
            }
            $layer = (int)($node->layer ?? 0);
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
            $elementsByLayer[$layer][] = [
                'type' => 'clip-pop',
                'layer' => $layer,
            ];
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
            if ($isScrollNode) {
                $scrollCtx = ['layer' => $node->layer];
                // TODO: ScrollbarEmitter 尚未实现，暂不发出滚动条元素
                // ScrollbarEmitter::emit($node, $scrollCtx, $elementsByLayer, $maxLayer);
            }
        }
        $this->markPainted($node, $this->currentPaintFrame);
    }

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

    private static function applyFontVariant(string $text, int $fontSize, string $variant): array
    {
        if ($variant === 'normal') {
            return ['text' => $text, 'fontSize' => $fontSize];
        }
        $result = ['text' => $text, 'fontSize' => $fontSize];
        if ($variant === 'small-caps' || $variant === 'all-small-caps') {
            if (function_exists('mb_strtoupper')) {
                $result['text'] = mb_strtoupper($text, 'UTF-8');
            } else {
                $result['text'] = strtoupper($text);
            }
            $result['fontSize'] = max(6, (int)($fontSize * 0.7));
        }
        return $result;
    }

    private static function applyFontStretch(string $stretch): int
    {
        switch ($stretch) {
            case 'condensed':
            case 'semi-condensed':
            case 'ultra-condensed':
            case 'extra-condensed':
                return -1;
            case 'expanded':
            case 'semi-expanded':
            case 'ultra-expanded':
            case 'extra-expanded':
                return 1;
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

    private static function resolveObjectPosition(string $value, int $containerSize, int $imageSize): int
    {
        $value = trim(strtolower($value));
        if ($value === 'left' || $value === 'top') return 0;
        if ($value === 'right' || $value === 'bottom') return $containerSize - $imageSize;
        if ($value === 'center') return (int)(($containerSize - $imageSize) / 2);
        if (str_ends_with($value, '%')) {
            return (int)(($containerSize - $imageSize) * (float)$value / 100.0);
        }
        if (preg_match('/^-?\d+/', $value, $m)) return (int)$m[0];
        return (int)(($containerSize - $imageSize) / 2);
    }

    private function currentComponent(): ReactiveComponent
    {
        $n = count($this->componentStack);
        return $n > 0 ? $this->componentStack[$n - 1] : $this->component;
    }

    private function renderNodeToElement(RenderNode $node): ?array
    {
        $pseudoKeys = self::extractPseudoOverrides($node);
        if (($node->computedStyle?->display?->value ?? '') === 'none') {
            return null;
        }
        $x = (int)($node->x ?? 0) + $this->getRenderOffsetX($node);
        $y = (int)($node->y ?? 0) + $this->getRenderOffsetY($node);
        $w = (int)($node->visualW ?? 0);
        $h = (int)($node->visualH ?? 0);
        $layer = (int)($node->layer ?? 0);
        if (count($this->scrollCtxStack) > 0) {
            $isFixed = ($node->computedStyle?->position?->value ?? '') === 'fixed';
            if (!$isFixed) {
                $scrollCtx = $this->scrollCtxStack[count($this->scrollCtxStack) - 1];
                $containerX = $scrollCtx['x'];
                $containerY = $scrollCtx['y'];
                $containerW = $scrollCtx['w'];
                $containerH = $scrollCtx['h'];
                $overflowX = $scrollCtx['overflowX'];
                $overflowY = $scrollCtx['overflowY'];
                if ($overflowY !== 'visible') {
                    if ($y + $h <= $containerY || $y >= $containerY + $containerH) {
                        return null;
                    }
                }
                if ($overflowX !== 'visible') {
                    if ($x + $w <= $containerX || $x >= $containerX + $containerW) {
                        return null;
                    }
                }
            }
        }
        $props = [];
        if ($node->sourceVNode !== null && $node->sourceVNode->props !== null) {
            $props = $node->sourceVNode->props;
        }
        switch ($node->type) {
            case 'button':
                return $this->makeButtonElement($node, $pseudoKeys, $props, $x, $y, $w, $h, $layer);
            case 'input':
                return $this->makeInputElement($node, $props, $x, $y, $w, $h, $layer);
            case 'img':
                return $this->makeImgElement($node, $pseudoKeys, $props, $x, $y, $w, $h, $layer);
            case 'span':
            case '#text':
            case 'b':
            case 'strong':
            case 'em':
            case 'i':
            case 'code':
                return $this->makeSpanElement($node, $props, $x, $y, $w, $h, $layer);
            case 'br':
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
                return $this->makeSpanElement($node, $props, $x, $y, $w, $h, $layer);
            case 'p':
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                return $this->makeSpanElement($node, $props, $x, $y, $w, $h, $layer);
            case 'div':
            default:
                return $this->makeDivElement($node, $pseudoKeys, $props, $x, $y, $w, $h, $layer);
        }
    }

    private function makeDivElement(RenderNode $node, array $pseudoOverrides, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $cs = $node->computedStyle;
        $cursor = $pseudoOverrides['cursor'] ?? $cs?->cursor?->value ?? '';
        if ((bool)($node->isScrollContainer ?? false)) {
            return $this->makeScrollContainerElement($node, $pseudoOverrides, $x, $y, $w, $h, $layer);
        }
        if ($w <= 0) $w = 80;
        if ($h <= 0) $h = 32;
        $rawBg = $pseudoOverrides['bg'] ?? $cs?->backgroundColor?->toBgr();
        $bg = $rawBg !== null ? $rawBg : null;
        $bwVal = $pseudoOverrides['borderWidth'] ?? ($cs?->borderWidth?->top?->toPx() ?? 0);
        $hasBorder = ($bwVal > 0)
            || ($pseudoOverrides['borderTopWidth'] ?? $cs?->borderTopWidth ?? 0) > 0
            || ($pseudoOverrides['borderRightWidth'] ?? $cs?->borderRightWidth ?? 0) > 0
            || ($pseudoOverrides['borderBottomWidth'] ?? $cs?->borderBottomWidth ?? 0) > 0
            || ($pseudoOverrides['borderLeftWidth'] ?? $cs?->borderLeftWidth ?? 0) > 0;
        $hasBg = $bg !== null;
        $bgImage = $pseudoOverrides['backgroundImage'] ?? $cs?->backgroundImage ?? '';
        $bgImageHandle = 0;
        if ($bgImage !== '' && $w > 0 && $h > 0) {
            $bgImageHandle = ImageManager::loadImage($bgImage);
        }
        $hasTextChild = is_string($node->content) && $node->content !== '';
        if ($bg === null && !$hasBorder && !$hasTextChild && $bgImageHandle === 0) {
            return null;
        }
        $noFill = ($bg === null);
        $drawColor = ($bg !== null) ? $bg : 0;
        $borderRadius = $pseudoOverrides['borderRadius'] ?? $cs?->borderRadius ?? 0;
        $borderRadiusX = $pseudoOverrides['borderRadiusX'] ?? 0;
        $borderRadiusY = $pseudoOverrides['borderRadiusY'] ?? 0;
        $opacity = $pseudoOverrides['opacity'] ?? $cs?->opacity ?? 1.0;
        $boxShadowRaw = $pseudoOverrides['boxShadow'] ?? $cs?->boxShadow ?? '';
        $offsets = CssValueParser::parseBoxShadowOffsets($boxShadowRaw);
        $shadowX = $offsets['h']; $shadowY = $offsets['v']; $shadowBlur = $offsets['blur']; $shadowColor = $offsets['color']; $shadowAlpha = $offsets['alpha']; $shadowInset = $offsets['inset'];
        $backgroundClip = $pseudoOverrides['backgroundClip'] ?? $cs?->backgroundClip ?? 'border-box';
        $backgroundAttachment = $pseudoOverrides['backgroundAttachment'] ?? $cs?->backgroundAttachment ?? 'scroll';
        $tableLayout = $cs?->tableLayout ?? 'auto';
        $borderCollapse = $cs?->borderCollapse?->value ?? 'separate';
        $borderSpacing = $cs?->borderSpacing ?? 0;
        $listMarker = '';
        if ($node->type === 'li') {
            $parent = $node->parent;
            $lst = 'disc';
            if ($parent !== null) {
                $lst = $parent->computedStyle?->listStyleType ?? 'disc';
                $liIndex = 0;
                foreach ($parent->children as $sibling) {
                    if ($sibling === $node) break;
                    if ($sibling->type === 'li') $liIndex++;
                }
                switch ($lst) {
                    case 'decimal': $listMarker = ($liIndex + 1) . '. '; break;
                    case 'lower-alpha': $listMarker = chr(97 + ($liIndex % 26)) . '. '; break;
                    case 'upper-alpha': $listMarker = chr(65 + ($liIndex % 26)) . '. '; break;
                    case 'square': $listMarker = "\xE2\x96\xAA "; break;
                    case 'circle': $listMarker = "\xE2\x97\x8B "; break;
                    case 'none': $listMarker = ''; break;
                    default: $listMarker = "\xE2\x80\xA2 "; break;
                }
            }
        }
        $gradientAngle = $pseudoOverrides['gradientAngle'] ?? $cs?->getRaw('gradientAngle');
        $gradientColors = $pseudoOverrides['gradientColors'] ?? $cs?->getRaw('gradientColors');
        $textShadowRaw = $pseudoOverrides['textShadow'] ?? $cs?->textShadow ?? '';
        $tsOffsets = CssValueParser::parseBoxShadowOffsets($textShadowRaw);
        $tsX = $tsOffsets['h']; $tsY = $tsOffsets['v']; $tsBlur = $tsOffsets['blur']; $tsColor = $tsOffsets['color']; $tsAlpha = $tsOffsets['alpha'];
        $borderWidth = $pseudoOverrides['borderWidth'] ?? ($cs?->borderWidth?->top?->toPx() ?? 0);
        $borderTopWidth = $pseudoOverrides['borderTopWidth'] ?? $cs?->borderTopWidth ?? $borderWidth;
        $borderRightWidth = $pseudoOverrides['borderRightWidth'] ?? $cs?->borderRightWidth ?? $borderWidth;
        $borderBottomWidth = $pseudoOverrides['borderBottomWidth'] ?? $cs?->borderBottomWidth ?? $borderWidth;
        $borderLeftWidth = $pseudoOverrides['borderLeftWidth'] ?? $cs?->borderLeftWidth ?? $borderWidth;
        $borderStyle = $pseudoOverrides['borderStyle'] ?? $cs?->borderStyle ?? 'solid';
        $outlineOffset = $pseudoOverrides['outlineOffset'] ?? $cs?->outlineOffset ?? 0;
        $borderColor = $pseudoOverrides['borderColor'] ?? $cs?->borderColor ?? 0;
        $borderTopColor = $pseudoOverrides['borderTopColor'] ?? $cs?->borderTopColor ?? $borderColor;
        $borderRightColor = $pseudoOverrides['borderRightColor'] ?? $cs?->borderRightColor ?? $borderColor;
        $borderBottomColor = $pseudoOverrides['borderBottomColor'] ?? $cs?->borderBottomColor ?? $borderColor;
        $borderLeftColor = $pseudoOverrides['borderLeftColor'] ?? $cs?->borderLeftColor ?? $borderColor;
        $bgImageEl = null;
        if ($bgImageHandle !== 0) {
            $imgX = $backgroundAttachment === 'fixed' ? (int)($node->x ?? 0) : $x;
            $imgY = $backgroundAttachment === 'fixed' ? $node->y : $y;
            $bgImageEl = [
                'type' => 'image',
                'handle' => $bgImageHandle,
                'x' => $imgX, 'y' => $imgY, 'w' => $w, 'h' => $h,
                'layer' => $layer,
                'backgroundRepeat' => $pseudoOverrides['backgroundRepeat'] ?? $cs?->backgroundRepeat ?? 'repeat',
            ];
        }
        if ($hasTextChild) {
            $fontSize = $pseudoOverrides['fontSize'] ?? $cs?->fontSize ?? 14;
            $rawTextColor = $pseudoOverrides['fg'] ?? $pseudoOverrides['color'] ?? ($cs?->color?->toBgr() ?? null);
            $textColor = $rawTextColor !== null ? $rawTextColor : 0xFFFFFF;
            $bold = $pseudoOverrides['bold'] ?? $cs?->bold ?? false;
            $rawTextAlign = $cs?->getRaw('textAlign');
            $align = $props['align'] ?? ($pseudoOverrides['textAlign'] ?? ($rawTextAlign ? (is_string($rawTextAlign) ? $rawTextAlign : ($cs?->textAlign?->value ?? 'start')) : 'start'));
            if ($align === 'start' && $rawTextAlign === null && $node->parent !== null) {
                $parentAlign = $node->parent->computedStyle?->textAlign?->value ?? null;
                if ($parentAlign !== null && $parentAlign !== 'start' && $parentAlign !== '') {
                    $align = $parentAlign;
                }
            }
            if ($align === 'start' || $align === 'match-parent') $align = 'left';
            if ($align === 'end') $align = 'right';
            if ($align === 'justify' || $align === 'justify-all') $align = 'left';
            $text = $node->content;
            if ($listMarker !== '') {
                $text = $listMarker . $text;
            }
            $textTransform = $pseudoOverrides['textTransform'] ?? $cs?->textTransform ?? 'none';
            if ($textTransform !== 'none') {
                $text = self::applyTextTransform($text, $textTransform);
            }
            $rawFontVariant = $cs?->getRaw('fontVariant');
            $fontVariant = $pseudoOverrides['fontVariant'] ?? ($rawFontVariant ? (is_string($rawFontVariant) ? $rawFontVariant : ($cs?->fontVariant ?? 'normal')) : 'normal');
            if ($fontVariant !== 'normal') {
                $fvRet = self::applyFontVariant($text, $fontSize, $fontVariant);
                $text = $fvRet['text'];
                $fontSize = $fvRet['fontSize'];
            }
            $rawFontStretch = $cs?->getRaw('fontStretch');
            $fontStretchVal = $pseudoOverrides['fontStretch'] ?? ($rawFontStretch ? (is_string($rawFontStretch) ? $rawFontStretch : ($cs?->fontStretch ?? 'normal')) : 'normal');
            $fontStretchExtra = self::applyFontStretch($fontStretchVal);
            $rawLetterSpacing = $cs?->getRaw('letterSpacing');
            $letterSpacing = $pseudoOverrides['letterSpacing'] ?? ($rawLetterSpacing ? (is_numeric($rawLetterSpacing) ? (int)$rawLetterSpacing : 0) : 0);
            if ($fontStretchExtra !== 0) {
                $letterSpacing += $fontStretchExtra;
            }
            $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);
            $selfY = (int)($node->y ?? 0) + $this->getRenderOffsetY($node);
            $selfH = (int)($node->visualH ?? 0);
            $selfX = (int)($node->x ?? 0) + $this->getRenderOffsetX($node);
            $selfW = (int)($node->visualW ?? 0);
            $pdL = $pseudoOverrides['paddingLeft'] ?? $cs?->padding?->left?->toPx() ?? 0;
            $pdT = $pseudoOverrides['paddingTop'] ?? $cs?->padding?->top?->toPx() ?? 0;
            $pdR = $pseudoOverrides['paddingRight'] ?? $cs?->padding?->right?->toPx() ?? 0;
            $pdB = $pseudoOverrides['paddingBottom'] ?? $cs?->padding?->bottom?->toPx() ?? 0;
            $contentX = $selfX + $borderLeftWidth + $pdL;
            $contentY = $selfY + $borderTopWidth + $pdT;
            $contentW = max(0, $selfW - $borderLeftWidth - $borderRightWidth - $pdL - $pdR);
            $rawOverflow = $cs?->overflow?->value ?? 'visible';
            $elOverflow = $pseudoOverrides['overflow'] ?? $rawOverflow;
            $hasOverflow = ($elOverflow === 'hidden' || $elOverflow === 'clip');
            $rawOverflowWrap = $cs?->overflowWrap ?? 'normal';
            $overflowWrap = $pseudoOverrides['overflowWrap'] ?? ($rawOverflowWrap !== '' ? $rawOverflowWrap : 'normal');
            if ($overflowWrap === 'normal' && $node->sourceVNode !== null && $node->sourceVNode->props !== null) {
                $rawStyleVNode = $node->sourceVNode->props['style'] ?? '';
                if ($rawStyleVNode !== '' && (stripos($rawStyleVNode, 'overflow-wrap:break-word') !== false || stripos($rawStyleVNode, 'word-wrap:break-word') !== false)) {
                    $overflowWrap = 'break-word';
                }
            }
            $isBreakWord = ($overflowWrap === 'break-word' || $overflowWrap === 'anywhere');
            $rawTextOverflow = $cs?->getRaw('textOverflow') ?? 'clip';
            $textOverflow = $pseudoOverrides['textOverflow'] ?? (is_string($rawTextOverflow) ? $rawTextOverflow : 'clip');
            $rawLineClamp = $cs?->getRaw('webkitLineClamp') ?? 0;
            $lineClampVal = is_numeric($rawLineClamp) ? (int)$rawLineClamp : 0;
            $overflowStyle = [
                'textOverflow' => $textOverflow,
                'overflowWrap' => $overflowWrap,
                'lineHeight' => $cs?->lineHeight ?? 0,
                'WebkitLineClamp' => $lineClampVal,
            ];
            if ($textOverflow === 'ellipsis' && $hasOverflow && $contentW > 0) {
                $overflowResult = TextOverflowProcessor::process($text, $contentW, $fontSize, (bool)$bold, $overflowStyle);
                $text = $overflowResult['text'];
                $overflowLines = $overflowResult['lines'];
                $overflowLineHeight = $overflowResult['lineHeight'];
                $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);
                $isWrappable = false;
            } elseif ($isBreakWord && $contentW > 0 && self::measureTextWidth($text, $fontSize, (bool)$bold) > $contentW) {
                $overflowResult = TextOverflowProcessor::process($text, $contentW, $fontSize, (bool)$bold, $overflowStyle);
                $text = $overflowResult['text'];
                $overflowLines = $overflowResult['lines'];
                $overflowLineHeight = $overflowResult['lineHeight'];
                $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);
                $isWrappable = false;
            } else {
                $overflowLines = null;
                $overflowLineHeight = 0;
                $isWrappable = true;
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
            $textIndent = (int)($pseudoOverrides['textIndent'] ?? $cs?->textIndent ?? 0);
            if ($textIndent > 0 && $align !== 'right' && $align !== 'center') {
                $textX += $textIndent;
            }
            $display = $pseudoOverrides['display'] ?? $cs?->display?->value ?? 'block';
            $justifyContent = $pseudoOverrides['justifyContent'] ?? $cs?->justifyContent?->value ?? 'flex-start';
            if (($display === 'flex' || $display === 'inline-flex') && $justifyContent === 'center' && $textWidth > 0 && $contentW > $textWidth) {
                $textX = $contentX + (int)(($contentW - $textWidth) / 2);
            }
            $textY = $contentY;
            $alignItems = $pseudoOverrides['alignItems'] ?? $cs?->alignItems?->value ?? 'stretch';
            $contentH = max(0, $selfH - $borderTopWidth - $borderBottomWidth - $pdT - $pdB);
            $textHeight = self::measureTextHeight($fontSize, (bool)$bold);
            if (($display === 'flex' || $display === 'inline-flex') && $alignItems === 'center') {
                if ($contentH > $textHeight) {
                    $textY = $contentY + (int)(($contentH - $textHeight) / 2);
                }
            }
            $isBold = (bool)$bold;
            $whitespace = $pseudoOverrides['whiteSpace'] ?? $cs?->whiteSpace?->value ?? 'normal';
            if ($whitespace === 'nowrap' || $whitespace === 'pre') {
                $isWrappable = false;
            }
            $lineH = 0;
            if ($isWrappable && $textWidth > $contentW && $contentW > 20) {
                $lineH = $pseudoOverrides['lineHeight'] ?? $cs?->lineHeight ?? 0;
                if ($lineH <= 0) {
                    $lineH = (int)($fontSize * 1.2);
                }
            }
            $elements = [];
            if ($hasBg || $hasBorder) {
                $bgX = $backgroundAttachment === 'fixed' ? (int)($node->x ?? 0) : $x;
                $bgY = $backgroundAttachment === 'fixed' ? $node->y : $y;
                $clipX = $bgX; $clipY = $bgY; $clipW = $w; $clipH = $h;
                if ($backgroundClip === 'padding-box' && ($borderLeftWidth > 0 || $borderTopWidth > 0 || $borderRightWidth > 0 || $borderBottomWidth > 0)) {
                    $clipX += $borderLeftWidth; $clipY += $borderTopWidth;
                    $clipW -= ($borderLeftWidth + $borderRightWidth);
                    $clipH -= ($borderTopWidth + $borderBottomWidth);
                } elseif ($backgroundClip === 'content-box') {
                    $pl = $cs?->padding?->left?->toPx() ?? 0;
                    $pt = $cs?->padding?->top?->toPx() ?? 0;
                    $pr = $cs?->padding?->right?->toPx() ?? 0;
                    $pb = $cs?->padding?->bottom?->toPx() ?? 0;
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
                    if ($textIndent > 0 && $lineIdx === 0 && $align !== 'right' && $align !== 'center') {
                        $segX += $textIndent;
                    }
                    $segY = $contentY + $lineIdx * $lineHeight;
                    $elements[] = ['type' => 'text', 'text' => $seg, 'x' => $segX, 'y' => $segY,
                        'fontSize' => $fontSize, 'color' => $textColor, 'bold' => $isBold,
                        'fontFamily' => $cs?->fontFamily ?? '',
                        'align' => $align, 'layer' => $layer + 1, 'cursor' => $cursor,
                        'decorationLine' => $cs?->textDecorationLine ?? 'none',
                        'decorationColor' => $cs?->textDecorationColor ?: (string)$textColor,
                        'decorationStyle' => $cs?->textDecorationStyle ?? 'solid',
                        'decorationThickness' => $cs?->textDecorationThickness ?? 0,
                        'underlineOffset' => $cs?->getRaw('underlineOffset') ?? 0,
                        'textWidth' => $segW,
                        'textShadowX' => $tsX, 'textShadowY' => $tsY, 'textShadowBlur' => $tsBlur,
                        'textShadowColor' => $tsColor, 'textShadowAlpha' => $tsAlpha,
                        'letterSpacing' => $letterSpacing,
                        'textEmphasisStyle' => 'none',
                        'textEmphasisColor' => 0xFF0000,
                        'textEmphasisPosition' => 'over'];
                    $lineIdx++;
                }
                /* textRenderInfo stored locally */
            } elseif ($isWrappable && $textWidth > $contentW && $contentW > 20 && $lineH > 0) {
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
                        'fontFamily' => $cs?->fontFamily ?? '',
                        'align' => $align, 'layer' => $layer + 1, 'cursor' => $cursor,
                        'decorationLine' => $cs?->textDecorationLine ?? 'none',
                        'decorationColor' => $cs?->textDecorationColor ?: (string)$textColor,
                        'decorationStyle' => $cs?->textDecorationStyle ?? 'solid',
                        'decorationThickness' => $cs?->textDecorationThickness ?? 0,
                        'underlineOffset' => $cs?->getRaw('textUnderlineOffset') ?? 0,
                        'textWidth' => self::measureTextWidth($seg, $fontSize, $isBold),
                        'textShadowX' => $tsX, 'textShadowY' => $tsY, 'textShadowBlur' => $tsBlur,
                        'textShadowColor' => $tsColor, 'textShadowAlpha' => $tsAlpha,
                        'letterSpacing' => $letterSpacing];
                    $lineIdx++;
                }
                /* textRenderInfo stored locally */
            } else {
                $elements[] = ['type' => 'text', 'text' => $text, 'x' => $textX, 'y' => $textY,
                        'fontSize' => $fontSize, 'color' => $textColor, 'bold' => $isBold,
                        'fontFamily' => $cs?->fontFamily ?? '',
                        'align' => $align, 'layer' => $layer + 1, 'cursor' => $cursor,
                        'decorationLine' => $cs?->textDecorationLine ?? 'none',
                        'decorationColor' => $cs?->textDecorationColor ?: (string)$textColor,
                        'decorationStyle' => $cs?->textDecorationStyle ?? 'solid',
                        'decorationThickness' => $cs?->textDecorationThickness ?? 0,
                        'underlineOffset' => $cs?->getRaw('underlineOffset') ?? 0,
                        'textWidth' => self::measureTextWidth($text, $fontSize, $isBold),
                        'textShadowX' => $tsX, 'textShadowY' => $tsY, 'textShadowBlur' => $tsBlur,
                        'textShadowColor' => $tsColor, 'textShadowAlpha' => $tsAlpha,
                        'letterSpacing' => $letterSpacing];
                /* textRenderInfo stored locally */
            }
            if (count($elements) === 1) {
                return $elements[0];
            }
            $elOverflowHidden = ($cs?->overflow?->value ?? 'visible') === 'hidden';
            if ($elOverflowHidden && !(bool)($node->isScrollContainer ?? false) && $selfW > 0 && $selfH > 0) {
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

    private function makeSpanElement(RenderNode $node, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $cs = $node->computedStyle;
        $fontSize = $cs?->fontSize ?? 14;
        $rawColor = $cs?->getRaw('fg') ?? $cs?->getRaw('color') ?? null;
        $color = null;
        if ($rawColor !== null) {
            $color = $rawColor instanceof CssColor ? $rawColor->toBgr() : (is_int($rawColor) ? $rawColor : null);
        }
        if ($color === null) {
            $p = $node->parent;
            while ($p !== null) {
                $pc = $p->computedStyle?->getRaw('fg') ?? null;
                if ($pc !== null) {
                    $color = $pc instanceof CssColor ? $pc->toBgr() : (is_int($pc) ? $pc : null);
                    break;
                }
                $p = $p->parent;
            }
        }
        if ($color === null) $color = 0x000000;
        $bold     = $cs?->bold ?? false;
        $rawTextAlign = $cs?->getRaw('textAlign');
        $align    = $props['align'] ?? ($rawTextAlign ? (is_string($rawTextAlign) ? $rawTextAlign : ($cs?->textAlign?->value ?? 'start')) : 'start');
        if ($align === 'start' && $rawTextAlign === null && $node->parent !== null) {
            $parentAlign = $node->parent->computedStyle?->textAlign?->value ?? null;
            if ($parentAlign !== null && $parentAlign !== 'start' && $parentAlign !== '') {
                $align = $parentAlign;
            }
        }
        if ($align === 'start' || $align === 'match-parent') $align = 'left';
        if ($align === 'end') $align = 'right';
        if ($align === 'justify' || $align === 'justify-all') $align = 'left';
        $text = '';
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

        if ($text === '') return null;
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
        $rawFontVariant = $cs?->getRaw('fontVariant') ?? null;
        $fontVariant = $rawFontVariant ? (is_string($rawFontVariant) ? $rawFontVariant : ($cs?->fontVariant ?? 'normal')) : 'normal';
        if ($fontVariant !== 'normal') {
            $fvRet = self::applyFontVariant($text, $fontSize, $fontVariant);
            $text = $fvRet['text'];
            $fontSize = $fvRet['fontSize'];
        }
        $rawFontStretch = $cs?->getRaw('fontStretch') ?? null;
        $fontStretchVal = $rawFontStretch ? (is_string($rawFontStretch) ? $rawFontStretch : ($cs?->fontStretch ?? 'normal')) : 'normal';
        $fontStretchExtra = self::applyFontStretch($fontStretchVal);
        $rawLetterSpacing = $cs?->getRaw('letterSpacing') ?? null;
        $letterSpacing = $rawLetterSpacing ? (is_numeric($rawLetterSpacing) ? (int)$rawLetterSpacing : 0) : 0;
        if ($fontStretchExtra !== 0) {
            $letterSpacing += $fontStretchExtra;
        }
        $rawTextShadow = $cs?->textShadow ?? '';
        $tsOffsets = CssValueParser::parseBoxShadowOffsets($rawTextShadow);
        $tsX = $tsOffsets['h']; $tsY = $tsOffsets['v']; $tsBlur = $tsOffsets['blur']; $tsColor = $tsOffsets['color']; $tsAlpha = $tsOffsets['alpha'];
        $verticalAlign = $cs?->verticalAlign?->value ?? 'baseline';
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
        $rawOverflowWrap = $cs?->overflowWrap ?? 'normal';
        $overflowWrap = $rawOverflowWrap !== '' ? $rawOverflowWrap : 'normal';
        if ($overflowWrap === 'normal' && $node->sourceVNode !== null && $node->sourceVNode->props !== null) {
            $rawStyle = $node->sourceVNode->props['style'] ?? '';
            if ($rawStyle !== '' && (stripos($rawStyle, 'overflow-wrap:break-word') !== false || stripos($rawStyle, 'word-wrap:break-word') !== false)) {
                $overflowWrap = 'break-word';
            }
        }
        $rawTextOverflow = $cs?->getRaw('textOverflow') ?? 'clip';
        $textOverflow = is_string($rawTextOverflow) ? $rawTextOverflow : 'clip';
        $rawLineClamp = $cs?->getRaw('webkitLineClamp') ?? 0;
        $lineClamp = is_numeric($rawLineClamp) ? (int)$rawLineClamp : 0;
        $overflowStyle = [
            'textOverflow' => $textOverflow,
            'overflowWrap' => $overflowWrap,
            'lineHeight' => $cs?->lineHeight ?? 0,
            'WebkitLineClamp' => $lineClamp,
        ];
        $overflowResult = TextOverflowProcessor::process($text, $containerW, $fontSize, (bool)$bold, $overflowStyle);
        $text = $overflowResult['text'];
        if ($overflowResult['lines'] !== null && count($overflowResult['lines']) > 1) {
            $elements = [];
            $lineIdx = 0;
            $lineHeight = $overflowResult['lineHeight'];
            foreach ($overflowResult['lines'] as $seg) {
                $lineY = $y + $lineIdx * $lineHeight;
                $segX = $x;
                if ($align === 'right' || $align === 'center') {
                    $segW = self::measureTextWidth($seg, $fontSize, (bool)$bold);
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
                    'decorationLine' => $cs?->textDecorationLine ?? 'none',
                    'decorationColor' => $cs?->textDecorationColor ?: (string)$color,
                    'decorationStyle' => $cs?->textDecorationStyle ?? 'solid',
                    'decorationThickness' => $cs?->textDecorationThickness ?? 0,
                    'underlineOffset' => $cs?->getRaw('textUnderlineOffset') ?? 0,
                    'textWidth' => self::measureTextWidth($seg, $fontSize, (bool)$bold),
                    'textShadowX' => $tsX, 'textShadowY' => $tsY, 'textShadowBlur' => $tsBlur,
                    'textShadowColor' => $tsColor, 'textShadowAlpha' => $tsAlpha,
                    'letterSpacing' => $letterSpacing];
                $lineIdx++;
            }
            return ['type' => 'group', 'layer' => $layer, 'elements' => $elements];
        }
        if ($align === 'right' || $align === 'center') {
            $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);
            if ($align === 'right') {
                $x = $containerX + $containerW - 12 - $textWidth;
                if ($x < $containerX + 4) $x = $containerX + 4;
            } else {
                $x = $containerX + (int)(($containerW - $textWidth) / 2);
                if ($x < $containerX) $x = (int)$containerX;
            }
        }
        if (!isset($textWidth)) {
            $textWidth = self::measureTextWidth($text, $fontSize, (bool)$bold);
        }
        $textHeight = self::measureTextHeight($fontSize, (bool)$bold);
        /* textRenderInfo stored locally */
        $decorationLine = $cs?->textDecorationLine ?? 'none';
        $decorationColor = $cs?->textDecorationColor ?: (string)$color;
        $decorationStyle = $cs?->textDecorationStyle ?? 'solid';
        $decorationThickness = $cs?->textDecorationThickness ?? 0;
        $underlineOffset = $cs?->getRaw('textUnderlineOffset') ?? 0;
        return [
            'type' => 'text', 'text' => $text,
            'x' => $x, 'y' => $y + $vaY,
            'fontSize' => $fontSize, 'color' => $color, 'bold' => $bold,
            'align' => $align, 'layer' => $layer,
            'decorationLine' => $decorationLine,
            'decorationColor' => $decorationColor,
            'decorationStyle' => $decorationStyle,
            'decorationThickness' => $decorationThickness,
            'underlineOffset' => $underlineOffset,
            'textWidth' => self::measureTextWidth($text, $fontSize, (bool)$bold),
            'textShadowX' => $tsX, 'textShadowY' => $tsY, 'textShadowBlur' => $tsBlur,
            'textShadowColor' => $tsColor, 'textShadowAlpha' => $tsAlpha,
            'letterSpacing' => $letterSpacing];
    }

    private function makeButtonElement(RenderNode $node, array $pseudoOverrides, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $cs = $node->computedStyle;
        $cursor = $cs?->cursor?->value ?? '';
        if ($w <= 0 || $h <= 0) {
            $w = $w <= 0 ? 80 : $w;
            $h = $h <= 0 ? 32 : $h;
        }
        $bg     = $cs?->backgroundColor?->toBgr() ?? 0x4488CC;
        $fg     = $cs?->color?->toBgr() ?? 0xFFFFFF;
        $borderWidth = $cs?->borderWidth?->top?->toPx() ?? 0;
        $borderTopWidth = $cs?->borderTopWidth ?? $borderWidth;
        $borderRightWidth = $cs?->borderRightWidth ?? $borderWidth;
        $borderBottomWidth = $cs?->borderBottomWidth ?? $borderWidth;
        $borderLeftWidth = $cs?->borderLeftWidth ?? $borderWidth;
        $borderStyle = $cs?->borderStyle ?? 'solid';
        $borderColor = 0;
        if ($borderWidth > 0 || $borderTopWidth > 0 || $borderRightWidth > 0 || $borderBottomWidth > 0 || $borderLeftWidth > 0) {
            $borderColor = $cs?->borderColor ?? ($bg !== 0 ? ($bg & 0xFFFFFF) >> 1 : 0);
        }
        $borderTopColor = $cs?->borderTopColor ?? $borderColor;
        $borderRightColor = $cs?->borderRightColor ?? $borderColor;
        $borderBottomColor = $cs?->borderBottomColor ?? $borderColor;
        $borderLeftColor = $cs?->borderLeftColor ?? $borderColor;
        $borderRadius = $cs?->borderRadius ?? 0;
        $borderRadiusX = 0;
        $borderRadiusY = 0;
        $opacity = $cs?->opacity ?? 1.0;
        $shadowOffsets = CssValueParser::parseBoxShadowOffsets($cs?->boxShadow ?? '');
        $shadowX = $shadowOffsets['h']; $shadowY = $shadowOffsets['v']; $shadowBlur = $shadowOffsets['blur']; $shadowColor = $shadowOffsets['color']; $shadowAlpha = $shadowOffsets['alpha']; $shadowInset = $shadowOffsets['inset'];
        $label = '';
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
        if ($label === '') {
            foreach ($node->children as $child) {
                if ($child->content !== null && (string)$child->content !== '') {
                    $label = (string)$child->content;
                    break;
                }
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

    private function makeImgElement(RenderNode $node, array $pseudoOverrides, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $cs = $node->computedStyle;
        $noSize = ($w <= 0 || $h <= 0);
        $src = $props['src'] ?? $props[':src'] ?? '';
        $imageHandle = 0;
        if ($src !== '' && !$noSize) {
            $imageHandle = ImageManager::loadImage($src);
        }
        $bg = $pseudoOverrides['bg'] ?? $cs?->backgroundColor?->toBgr() ?? 0xCCCCCC;
        $borderRadius = $pseudoOverrides['borderRadius'] ?? $cs?->borderRadius ?? 0;
        $borderRadiusX = 0;
        $borderRadiusY = 0;
        $opacity = $pseudoOverrides['opacity'] ?? $cs?->opacity ?? 1.0;
        $boxShadowRaw = $pseudoOverrides['boxShadow'] ?? $cs?->boxShadow ?? '';
        $shadowOffsets = CssValueParser::parseBoxShadowOffsets($boxShadowRaw);
        $shadowX = $shadowOffsets['h']; $shadowY = $shadowOffsets['v']; $shadowBlur = $shadowOffsets['blur']; $shadowColor = $shadowOffsets['color']; $shadowAlpha = $shadowOffsets['alpha']; $shadowInset = $shadowOffsets['inset'];
        $borderWidth = $pseudoOverrides['borderWidth'] ?? ($cs?->borderWidth?->top?->toPx() ?? 0);
        $borderTopWidth = $pseudoOverrides['borderTopWidth'] ?? $cs?->borderTopWidth ?? $borderWidth;
        $borderRightWidth = $pseudoOverrides['borderRightWidth'] ?? $cs?->borderRightWidth ?? $borderWidth;
        $borderBottomWidth = $pseudoOverrides['borderBottomWidth'] ?? $cs?->borderBottomWidth ?? $borderWidth;
        $borderLeftWidth = $pseudoOverrides['borderLeftWidth'] ?? $cs?->borderLeftWidth ?? $borderWidth;
        $borderStyle = $cs?->borderStyle ?? 'solid';
        $borderColor = $pseudoOverrides['borderColor'] ?? $cs?->borderColor ?? 0;
        $borderTopColor = $pseudoOverrides['borderTopColor'] ?? $cs?->borderTopColor ?? $borderColor;
        $borderRightColor = $pseudoOverrides['borderRightColor'] ?? $cs?->borderRightColor ?? $borderColor;
        $borderBottomColor = $pseudoOverrides['borderBottomColor'] ?? $cs?->borderBottomColor ?? $borderColor;
        $borderLeftColor = $pseudoOverrides['borderLeftColor'] ?? $cs?->borderLeftColor ?? $borderColor;
        $objectFit = $pseudoOverrides['objectFit'] ?? $cs?->objectFit ?? 'fill';
        $objectPosition = $pseudoOverrides['objectPosition'] ?? $cs?->objectPosition ?? '50% 50%';
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
                $opX = 0; $opY = 0;
                if ($objectPosition !== '50% 50%') {
                    $parts = preg_split('/\s+/', trim($objectPosition));
                    $opX = self::resolveObjectPosition($parts[0] ?? '50%', $w, $imgW);
                    $opY = self::resolveObjectPosition($parts[1] ?? '50%', $h, $imgH);
                    if ($opX !== 0 || $opY !== 0) {
                        $baseX = $x + (int)(($w - $imgW) / 2);
                        $baseY = $y + (int)(($h - $imgH) / 2);
                        $imgX = $baseX + $opX - (int)(($w - $imgW) / 2);
                        $imgY = $baseY + $opY - (int)(($h - $imgH) / 2);
                    }
                }
            }
        }
        $alt = $props['alt'] ?? '';
        if ($noSize) {
            if ($alt !== '') {
                $fontSize = $cs?->fontSize ?? 14;
                $textColor = $cs?->color?->toBgr() ?? 0xFFFFFF;
                return [
                    'type' => 'text', 'text' => '[img] ' . $alt,
                    'x' => $x, 'y' => $y,
                    'fontSize' => $fontSize, 'color' => $textColor, 'bold' => 1,
                    'align' => 'left', 'layer' => $layer,
                ];
            }
            return null;
        }
        $elements = [];
        if ($imageHandle !== 0) {
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
        if ($alt !== '') {
            $fontSize = $cs?->fontSize ?? 14;
            $textColor = $cs?->color?->toBgr() ?? 0xFFFFFF;
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

    private function makeInputElement(RenderNode $node, array $props, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $cs = $node->computedStyle;
        $bg       = $cs?->backgroundColor?->toBgr() ?? 0x1E1E1E;
        $fg       = $cs?->color?->toBgr() ?? 0xFFFFFF;
        $fontSize = $cs?->fontSize ?? 14;
        $borderRadius = $cs?->borderRadius ?? 0;
        $borderRadiusX = 0;
        $borderRadiusY = 0;
        $opacity = $cs?->opacity ?? 1.0;
        $bindKey = $props['v-model'] ?? '';
        $text = '';
        if ($bindKey !== '') {
            $text = $this->currentComponent()->getBindValue($bindKey);
        }
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

    private function makeScrollContainerElement(RenderNode $node, array $pseudoOverrides, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $cs = $node->computedStyle;
        $bg = $pseudoOverrides['bg'] ?? $cs?->backgroundColor?->toBgr() ?? 0x2D2D2D;
        $borderRadius = $pseudoOverrides['borderRadius'] ?? $cs?->borderRadius ?? 0;
        $borderRadiusX = $pseudoOverrides['borderRadiusX'] ?? 0;
        $borderRadiusY = $pseudoOverrides['borderRadiusY'] ?? 0;
        $opacity = $pseudoOverrides['opacity'] ?? $cs?->opacity ?? 1.0;
        $contentH = (int)($node->contentHeight ?? 0);
        if ($contentH === 0) {
            foreach ($node->children as $child) {
                $itemH = (int)($child->computedStyle?->height->toPx() ?? 0);
                $contentH += (int)max($child->h, $itemH);
            }
        }
        return [
            'type' => 'scroll-container',
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'bg' => $bg, 'borderRadius' => $borderRadius,
            'contentHeight' => $contentH,
            'contentWidth' => (int)($node->contentWidth ?? 0),
            'scrollTop' => (int)($node->scrollTop ?? 0),
            'scrollLeft' => (int)($node->scrollLeft ?? 0),
            'opacity' => $opacity,
            'layer' => $layer,
        ];
    }

    private function resetAllPaintFlags(RenderNode $node): void
    {
        $this->paintFlags[spl_object_id($node)] = 0;
        $node->layoutDirty = true;
        foreach ($node->children as $child) {
            $this->resetAllPaintFlags($child);
        }
    }

    private static function cssValueToRaw(mixed $v): mixed
    {
        if ($v instanceof CssLength || $v instanceof CssRect) {
            return $v->toPx();
        }
        if ($v instanceof CssKeyword) {
            return $v->value;
        }
        if ($v instanceof CssColor) {
            return $v->toBgr();
        }
        if ($v instanceof CssFlex) {
            return $v->grow . ' ' . $v->shrink . ' ' . $v->basis->toPx();
        }
        return $v;
    }

    private static function extractPseudoOverrides(RenderNode $node): array
    {
        $cs = $node->computedStyle;
        if ($cs === null) return [];
        $overrides = [];
        $states = [];
        if ($node->hovered) $states[] = '__hoverStyle';
        if ($node->focused) $states[] = '__focusStyle';
        if ($node->active) $states[] = '__activeStyle';
        foreach ($states as $key) {
            $raw = $cs->getRaw($key);
            if ($raw !== null && is_array($raw)) {
                foreach ($raw as $k => $v) {
                    $overrides[$k] = self::cssValueToRaw($v);
                }
            }
        }
        return $overrides;
    }
}