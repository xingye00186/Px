<?php

namespace Px\Rendering;

use native_types;

/**
 * GdiRenderContext — Win32 GDI 绘制上下文
 *
 * drawElement() 接收一个元素描述，对复杂类型内部多次调用
 * fillRect / drawText / drawButton 完成绘制。
 *
 * 支持的元素类型:
 *   rect, text              — 直接映射到 GDI 原语
 *   button                  — drawButton + drawText (label)
 *   input                   — fillRect (bg) + drawText (value)
 *   scroll-container        — fillRect (bg) + fillRect (track) + fillRect (thumb)
 *   line-h, line-v          — 单像素线条
 *   progress                — fillRect (track) + fillRect (fill)
 */
class GdiRenderContext extends RenderContext
{
    private int $hWnd;
    private int $hdc = 0;

    /** @var array 当前激活的 clip 区域栈，每个元素 ['x'=>, 'y'=>, 'w'=>, 'h'=>] */
    private array $clipStack = [];

    public function __construct(int $hWnd)
    {
        $this->hWnd = $hWnd;
    }

    public function beginFrame(): void
    {
        $this->hdc = vue_begin_paint($this->hWnd);
    }

    public function endFrame(): void
    {
        vue_end_paint($this->hWnd, $this->hdc);
        $this->hdc = 0;
    }

    public function drawElement(array $el): void
    {
        $type = $el['type'] ?? 'rect';

        switch ($type) {
            // ── 原生图元 ──────────────────────
            case 'rect':
                if (($el['w'] ?? 0) <= 0 || ($el['h'] ?? 0) <= 0) return;
                $shadowX = $el['shadowX'] ?? 0;
                $shadowY = $el['shadowY'] ?? 0;
                $shadowColor = $el['shadowColor'] ?? 0;
                if ($shadowX !== 0 || $shadowY !== 0) {
                    vue_alpha_fill_rect(
                        $this->hdc,
                        ($el['x'] ?? 0) + $shadowX,
                        ($el['y'] ?? 0) + $shadowY,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $shadowColor, 0.5
                    );
                }
                $radius = $el['borderRadius'] ?? 0;
                $opacity = $el['opacity'] ?? 1.0;
                $color = $el['color'] ?? 0;
                if ($radius > 0 && $opacity >= 1.0) {
                    vue_draw_round_rect(
                        $this->hdc,
                        $el['x'] ?? 0, $el['y'] ?? 0,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $radius, $color
                    );
                } elseif ($opacity < 1.0) {
                    vue_alpha_fill_rect(
                        $this->hdc,
                        $el['x'] ?? 0, $el['y'] ?? 0,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $color, $opacity
                    );
                } else {
                    $this->fillRect(
                        $el['x'] ?? 0, $el['y'] ?? 0,
                        $el['w'] ?? 0, $el['h'] ?? 0, $color
                    );
                }
                // Draw border outline (skip when rounded corners)
                $borderWidth = $el['borderWidth'] ?? 0;
                $borderColor = $el['borderColor'] ?? 0;
                if ($borderWidth > 0 && $radius === 0) {
                    $bx = $el['x'] ?? 0;
                    $by = $el['y'] ?? 0;
                    $bw = $el['w'] ?? 0;
                    $bh = $el['h'] ?? 0;
                    $this->fillRect($bx, $by, $bw, $borderWidth, $borderColor);
                    $this->fillRect($bx, $by + $bh - $borderWidth, $bw, $borderWidth, $borderColor);
                    $this->fillRect($bx, $by, $borderWidth, $bh, $borderColor);
                    $this->fillRect($bx + $bw - $borderWidth, $by, $borderWidth, $bh, $borderColor);
                }
                break;

            case 'text':
                $tx = $el['x'] ?? 0;
                $ty = $el['y'] ?? 0;
                // 防御：负坐标或超出窗口边界的文本会损坏 GDI 状态
                if ($tx < 0 || $ty < 0) break;
                $this->drawText(
                    $tx, $ty,
                    $el['text'] ?? '',
                    $el['fontSize'] ?? 16,
                    $el['color'] ?? 0xFFFFFF,
                    $el['bold'] ?? 0
                );
                break;

            // ── 复合类型 (多次 GDI 调用) ──────
            case 'group':
                // Process each child element in the group
                if (isset($el['elements']) && is_array($el['elements'])) {
                    foreach ($el['elements'] as $childEl) {
                        $this->drawElement($childEl);
                    }
                }
                break;

            // ── 复合类型 (多次 GDI 调用) ──────
            case 'button':
                if (($el['w'] ?? 0) <= 0 || ($el['h'] ?? 0) <= 0) return;
                $shadowX = $el['shadowX'] ?? 0;
                $shadowY = $el['shadowY'] ?? 0;
                $shadowColor = $el['shadowColor'] ?? 0;
                if ($shadowX !== 0 || $shadowY !== 0) {
                    vue_alpha_fill_rect(
                        $this->hdc,
                        ($el['x'] ?? 0) + $shadowX,
                        ($el['y'] ?? 0) + $shadowY,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $shadowColor, 0.5
                    );
                }
                $radius = $el['borderRadius'] ?? 0;
                $opacity = $el['opacity'] ?? 1.0;
                $bg = $el['bg'] ?? 0x4488CC;
                if ($opacity < 1.0) {
                    vue_alpha_fill_rect(
                        $this->hdc,
                        $el['x'] ?? 0, $el['y'] ?? 0,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $bg, $opacity
                    );
                } elseif ($radius > 0) {
                    vue_draw_round_rect(
                        $this->hdc,
                        $el['x'] ?? 0, $el['y'] ?? 0,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $radius, $bg
                    );
                } else {
                    $borderWidth = $el['borderWidth'] ?? 0;
                    if ($borderWidth > 0) {
                        $this->drawButton(
                            $el['x'] ?? 0, $el['y'] ?? 0,
                            $el['w'] ?? 0, $el['h'] ?? 0,
                            $bg, $el['border'] ?? 0
                        );
                    } else {
                        $this->fillRect(
                            $el['x'] ?? 0, $el['y'] ?? 0,
                            $el['w'] ?? 0, $el['h'] ?? 0,
                            $bg
                        );
                    }
                }
                if (!empty($el['label'])) {
                    $this->drawText(
                        $el['labelX'] ?? 0,
                        $el['labelY'] ?? 0,
                        $el['label'],
                        $el['labelFontSize'] ?? 22,
                        $el['fg'] ?? 0xFFFFFF,
                        1
                    );
                }
                break;

            case 'input':
                if (($el['w'] ?? 0) <= 0 || ($el['h'] ?? 0) <= 0) return;
                // 背景
                $radius = $el['borderRadius'] ?? 0;
                if ($radius > 0) {
                    vue_draw_round_rect(
                        $this->hdc,
                        $el['x'] ?? 0, $el['y'] ?? 0,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $radius,
                        $el['bg'] ?? 0x1E1E1E
                    );
                } else {
                    $this->fillRect(
                        $el['x'] ?? 0, $el['y'] ?? 0,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $el['bg'] ?? 0x1E1E1E
                    );
                }
                // 文本值
                if (!empty($el['text'])) {
                    $fontSize = $el['fontSize'] ?? 16;
                    $padX = 6;
                    $padY = (int)((($el['h'] ?? 0) - $fontSize) / 2);
                    $this->drawText(
                        ($el['x'] ?? 0) + $padX,
                        ($el['y'] ?? 0) + $padY,
                        $el['text'],
                        $fontSize,
                        $el['color'] ?? 0xFFFFFF,
                        0
                    );
                }
                break;

            case 'scroll-container':
                $x = $el['x'] ?? 0; $y = $el['y'] ?? 0;
                $w = $el['w'] ?? 0; $h = $el['h'] ?? 0;
                if ($w <= 0 || $h <= 0) return;
                $bg = $el['bg'] ?? 0x2D2D2D;
                $radius = $el['borderRadius'] ?? 0;
                $opacity = $el['opacity'] ?? 1.0;
                if ($opacity < 1.0) {
                    vue_alpha_fill_rect($this->hdc, $x, $y, $w, $h, $bg, $opacity);
                } elseif ($radius > 0) {
                    vue_draw_round_rect($this->hdc, $x, $y, $w, $h, $radius, $bg);
                } else {
                    $this->fillRect($x, $y, $w, $h, $bg);
                }
                break;

            case 'scrollbar-v':
                $x = $el['x'] ?? 0; $y = $el['y'] ?? 0;
                $w = $el['w'] ?? 0; $h = $el['h'] ?? 0;
                if ($w <= 0 || $h <= 0) return;
                $contentH = $el['contentHeight'] ?? 0;
                if ($contentH > $h) {
                    $scrollTop = $el['scrollTop'] ?? 0;
                    $sbW = 12;
                    $sbX = $x + $w - $sbW;
                    // 轨道
                    $this->fillRect($sbX, $y, $sbW, $h, 0x4A4A4A);
                    // 滑块
                    $ratio = min($h / max($contentH, 1), 1.0);
                    $thumbH = max((int)($h * $ratio), 20);
                    $maxScroll = max($contentH - $h, 0);
                    $scrollRatio = $maxScroll > 0 ? $scrollTop / $maxScroll : 0.0;
                    $thumbY = $y + (int)(($h - $thumbH) * $scrollRatio);
                    $this->fillRect($sbX + 2, $thumbY, $sbW - 4, $thumbH, 0x888888);
                }
                break;

            case 'scrollbar-h':
                $x = $el['x'] ?? 0; $y = $el['y'] ?? 0;
                $w = $el['w'] ?? 0; $h = $el['h'] ?? 0;
                if ($w <= 0 || $h <= 0) return;
                $contentW = $el['contentWidth'] ?? 0;
                if ($contentW > $w) {
                    $scrollLeft = $el['scrollLeft'] ?? 0;
                    $sbH = 12;
                    $sbY = $y + $h - $sbH;
                    // 轨道
                    $this->fillRect($x, $sbY, $w, $sbH, 0x4A4A4A);
                    // 滑块
                    $ratioH = min($w / max($contentW, 1), 1.0);
                    $thumbW = max((int)($w * $ratioH), 20);
                    $maxScrollX = max($contentW - $w, 0);
                    $scrollRatioX = $maxScrollX > 0 ? $scrollLeft / $maxScrollX : 0.0;
                    $thumbX = $x + (int)(($w - $thumbW) * $scrollRatioX);
                    $this->fillRect($thumbX, $sbY + 2, $thumbW, $sbH - 4, 0x888888);
                }
                break;

            case 'clip-push':
                if (($el['w'] ?? 0) <= 0 || ($el['h'] ?? 0) <= 0) return;
                $this->clipStack[] = [
                    'x' => $el['x'] ?? 0,
                    'y' => $el['y'] ?? 0,
                    'w' => $el['w'] ?? 0,
                    'h' => $el['h'] ?? 0,
                ];
                vue_push_clip($this->hdc,
                    $el['x'] ?? 0, $el['y'] ?? 0,
                    $el['w'] ?? 0, $el['h'] ?? 0);
                break;

            case 'clip-pop':
                array_pop($this->clipStack);
                vue_pop_clip($this->hdc);
                break;

            // ── 新增图元类型 ──────────────────
            case 'line-h':
                if (($el['w'] ?? 0) <= 0) return;
                $this->fillRect(
                    $el['x'] ?? 0, $el['y'] ?? 0,
                    $el['w'] ?? 0, max($el['thickness'] ?? 1, 1),
                    $el['color'] ?? 0x666666
                );
                break;

            case 'line-v':
                if (($el['h'] ?? 0) <= 0) return;
                $this->fillRect(
                    $el['x'] ?? 0, $el['y'] ?? 0,
                    max($el['thickness'] ?? 1, 1), $el['h'] ?? 0,
                    $el['color'] ?? 0x666666
                );
                break;

            case 'progress':
                $x = $el['x'] ?? 0; $y = $el['y'] ?? 0;
                $w = $el['w'] ?? 0; $h = $el['h'] ?? 0;
                if ($w <= 0 || $h <= 0) return;
                // 轨道
                $this->fillRect($x, $y, $w, $h, $el['trackColor'] ?? 0x333333);
                // 填充进度
                $value = max(0, min($el['value'] ?? 0, $el['max'] ?? 100));
                $maxVal = max($el['max'] ?? 100, 1);
                $fillW = (int)($w * $value / $maxVal);
                if ($fillW > 0) {
                    $this->fillRect($x, $y, $fillW, $h, $el['fillColor'] ?? 0x4488CC);
                }
                // 进度文本 (可选的标签)
                if (!empty($el['label'])) {
                    $fontSize = $el['fontSize'] ?? 14;
                    $labelW = (int)(strlen($el['label']) * $fontSize * 0.6);
                    $this->drawText(
                        $x + (int)(($w - $labelW) / 2),
                        $y + (int)(($h - $fontSize) / 2),
                        $el['label'],
                        $fontSize,
                        $el['labelColor'] ?? 0xFFFFFF,
                        0
                    );
                }
                break;
        }
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): void
    {
        vue_fill_rect($this->hdc, $x, $y, $w, $h, $color);
    }

    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold): void
    {
        if ($x < 0 || $y < 0) return;
        if (strlen($text) === 0) return;
        if ($fontSize <= 0) return;

        // 文本截断由 C++ php_vue_draw_text 层通过 GetTextExtentPoint32W
        // 精确测量后自动处理，PHP 层不做估算。
        vue_draw_text($this->hdc, $x, $y, $text, $fontSize, $color, $bold);
    }

    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void
    {
        vue_draw_button($this->hdc, $x, $y, $w, $h, $bg, $border);
    }
}
