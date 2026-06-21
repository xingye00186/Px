<?php

namespace Px\Rendering;

use native_types;

/**
 * SkiaRenderContext — Skia 渲染上下文（阶段二：GDI 兼容层）
 *
 * drawElement() 接收一个元素描述，对复杂类型内部多次调用
 * fillRect / drawText / drawButton 完成绘制。
 *
 * 支持的元素类型（与 GdiRenderContext 1:1 对齐）：
 *   rect, text, button, input, scroll-container, scrollbar-v, scrollbar-h,
 *   clip-push, clip-pop, line-h, line-v, progress, group
 *
 * 阶段一：仅 rect+group 走 sk_fill_rect，其余 throw
 * 阶段二（当前）：12 路 switch 1:1 移植 GDI 版本，底层 sk_* 用 GDI 实现
 * 阶段三：sk_* 底层 USE_SKIA 切换为真 Skia 调用
 *
 * R6 对策：构造函数 trigger_error("SKIA PATH") 防止"看起来工作但实际走 GDI"的误判
 *         （POC/阶段二验证期保留）
 *
 * 与 GdiRenderContext 关键差异：
 *  - sk_* 原生函数不收 HDC 参数（C++ 端用 g_skHdc 静态变量）
 *  - beginFrame/endFrame 直接委派 sk_begin_frame/sk_end_frame（不再需要 vue_begin_paint）
 *  - PHP 端不再持有 hdc 字段
 */
class SkiaRenderContext extends RenderContext
{
    private int $hWnd;
    private int $width;
    private int $height;

    /** @var array 当前激活的 clip 区域栈 */
    private array $clipStack = [];

    public function __construct(int $hWnd, int $width, int $height)
    {
        $this->hWnd   = $hWnd;
        $this->width  = $width;
        $this->height = $height;
        sk_create_window_context($hWnd, $width, $height);
        // R6 风险对策：明确标识 Skia 路径已激活（POC/阶段二验证期保留）
        // Notice removed: no longer trigger E_USER_NOTICE on every backend init
    }

    public function __destruct()
    {
        sk_destroy_context();
    }

    public function beginFrame(): void
    {
        sk_begin_frame();
    }

    public function endFrame(): void
    {
        sk_end_frame();
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
                $shadowBlur = $el['shadowBlur'] ?? 0;
                $shadowAlpha = $el['shadowAlpha'] ?? 0.5;
                if ($shadowX !== 0 || $shadowY !== 0) {
                    sk_shadow_round_rect(
                        ($el['x'] ?? 0) + $shadowX,
                        ($el['y'] ?? 0) + $shadowY,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $el['borderRadius'] ?? 0,
                        $shadowBlur,
                        $shadowColor,
                        $shadowAlpha
                    );
                }
                $radius = $el['borderRadius'] ?? 0;
                $opacity = $el['opacity'] ?? 1.0;
                $color = $el['color'] ?? 0;
                $noFill = $el['noFill'] ?? false;
                if ($radius > 0 && $opacity >= 1.0) {
                    if (!$noFill) {
                        sk_draw_round_rect(
                            $el['x'] ?? 0, $el['y'] ?? 0,
                            $el['w'] ?? 0, $el['h'] ?? 0,
                            $radius, $color
                        );
                    }
                } elseif (!$noFill && $opacity < 1.0) {
                    sk_alpha_fill_rect(
                        $el['x'] ?? 0, $el['y'] ?? 0,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $color, $opacity
                    );
                } elseif (!$noFill) {
                    $this->fillRect(
                        $el['x'] ?? 0, $el['y'] ?? 0,
                        $el['w'] ?? 0, $el['h'] ?? 0, $color
                    );
                }
                // Draw border outline (also when rounded corners — simpler rects)
                // CSS 2.2 §8.6: per-side border widths and colors
                $borderWidth = $el['borderWidth'] ?? 0;
                $borderColor = $el['borderColor'] ?? 0;
                $bt = $el['borderTopWidth'] ?? $borderWidth;
                $bb = $el['borderBottomWidth'] ?? $borderWidth;
                $bl = $el['borderLeftWidth'] ?? $borderWidth;
                $br = $el['borderRightWidth'] ?? $borderWidth;
                $btc = $el['borderTopColor'] ?? $borderColor;
                $bbc = $el['borderBottomColor'] ?? $borderColor;
                $blc = $el['borderLeftColor'] ?? $borderColor;
                $brc = $el['borderRightColor'] ?? $borderColor;
                if ($bt > 0 || $bb > 0 || $bl > 0 || $br > 0) {
                    $bx = $el['x'] ?? 0;
                    $by = $el['y'] ?? 0;
                    $bw = $el['w'] ?? 0;
                    $bh = $el['h'] ?? 0;
                    $bs = $el['borderStyle'] ?? 'solid';
                    if ($bt > 0) $this->drawBorderLine($bx, $by, $bw, $bt, $btc, $bs, true);
                    if ($bb > 0) $this->drawBorderLine($bx, $by + $bh - $bb, $bw, $bb, $bbc, $bs, true);
                    if ($bl > 0) $this->drawBorderLine($bx, $by, $bh, $bl, $blc, $bs, false);
                    if ($br > 0) $this->drawBorderLine($bx + $bw - $br, $by, $bh, $br, $brc, $bs, false);
                }
                break;

            case 'text':
                $tx = $el['x'] ?? 0;
                $ty = $el['y'] ?? 0;
                $tt = $el['text'] ?? '';
                // Debug fprintf(STDERR, "[SK] drawElement TEXT text='%s' x=%d y=%d fontSize=%d\n", ...) — removed for production
                if ($tx < 0 || $ty < 0) break;
                $this->drawText(
                    $tx, $ty,
                    $el['text'] ?? '',
                    $el['fontSize'] ?? 16,
                    $el['color'] ?? 0xFFFFFF,
                    $el['bold'] ?? 0,
                    $el['fontFamily'] ?? ''
                );
                // 绘制 text-decoration 装饰线
                $this->drawTextDecoration($el);
                break;

            // ── 复合类型 (多次 GDI 调用) ──────
            case 'group':
                if (isset($el['elements']) && is_array($el['elements'])) {
                    foreach ($el['elements'] as $childEl) {
                        $this->drawElement($childEl);
                    }
                }
                break;

            case 'button':
                if (($el['w'] ?? 0) <= 0 || ($el['h'] ?? 0) <= 0) return;
                $shadowX = $el['shadowX'] ?? 0;
                $shadowY = $el['shadowY'] ?? 0;
                $shadowColor = $el['shadowColor'] ?? 0;
                $shadowBlur = $el['shadowBlur'] ?? 0;
                $shadowAlpha = $el['shadowAlpha'] ?? 0.5;
                if ($shadowX !== 0 || $shadowY !== 0) {
                    sk_shadow_round_rect(
                        ($el['x'] ?? 0) + $shadowX,
                        ($el['y'] ?? 0) + $shadowY,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $el['borderRadius'] ?? 0,
                        $shadowBlur,
                        $shadowColor,
                        $shadowAlpha
                    );
                }
                $radius = $el['borderRadius'] ?? 0;
                $opacity = $el['opacity'] ?? 1.0;
                $bg = $el['bg'] ?? 0x4488CC;
                if ($opacity < 1.0) {
                    sk_alpha_fill_rect(
                        $el['x'] ?? 0, $el['y'] ?? 0,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $bg, $opacity
                    );
                } elseif ($radius > 0) {
                    sk_draw_round_rect(
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
                if (isset($el['label']) && $el['label'] !== '') {
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
                $radius = $el['borderRadius'] ?? 0;
                if ($radius > 0) {
                    sk_draw_round_rect(
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
                if (isset($el['text']) && $el['text'] !== '') {
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
                    sk_alpha_fill_rect($x, $y, $w, $h, $bg, $opacity);
                } elseif ($radius > 0) {
                    sk_draw_round_rect($x, $y, $w, $h, $radius, $bg);
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
                    $sbW = $el['sbWidth'] ?? 12;
                    $trackColor = $el['trackColor'] ?? 0x4A4A4A;
                    $thumbColor = $el['thumbColor'] ?? 0x888888;
                    $sbRadius = $el['sbRadius'] ?? 0;
                    $sbX = $x + $w - $sbW;
                    $this->fillRect($sbX, $y, $sbW, $h, $trackColor);
                    $ratio = min($h / max($contentH, 1), 1.0);
                    $thumbH = max((int)($h * $ratio), 20);
                    $maxScroll = max($contentH - $h, 0);
                    $scrollRatio = $maxScroll > 0 ? $scrollTop / $maxScroll : 0.0;
                    $thumbY = $y + (int)(($h - $thumbH) * $scrollRatio);
                    if ($sbRadius > 0) {
                        sk_draw_round_rect($sbX + 2, $thumbY, $sbW - 4, $thumbH, $sbRadius, $thumbColor);
                    } else {
                        $this->fillRect($sbX + 2, $thumbY, $sbW - 4, $thumbH, $thumbColor);
                    }
                }
                break;

            case 'scrollbar-h':
                $x = $el['x'] ?? 0; $y = $el['y'] ?? 0;
                $w = $el['w'] ?? 0; $h = $el['h'] ?? 0;
                if ($w <= 0 || $h <= 0) return;
                $contentW = $el['contentWidth'] ?? 0;
                if ($contentW > $w) {
                    $scrollLeft = $el['scrollLeft'] ?? 0;
                    $sbH = $el['sbWidth'] ?? 12;
                    $trackColor = $el['trackColor'] ?? 0x4A4A4A;
                    $thumbColor = $el['thumbColor'] ?? 0x888888;
                    $sbRadius = $el['sbRadius'] ?? 0;
                    $sbY = $y + $h - $sbH;
                    $this->fillRect($x, $sbY, $w, $sbH, $trackColor);
                    $ratioH = min($w / max($contentW, 1), 1.0);
                    $thumbW = max((int)($w * $ratioH), 20);
                    $maxScrollX = max($contentW - $w, 0);
                    $scrollRatioX = $maxScrollX > 0 ? $scrollLeft / $maxScrollX : 0.0;
                    $thumbX = $x + (int)(($w - $thumbW) * $scrollRatioX);
                    if ($sbRadius > 0) {
                        sk_draw_round_rect($thumbX, $sbY + 2, $thumbW, $sbH - 4, $sbRadius, $thumbColor);
                    } else {
                        $this->fillRect($thumbX, $sbY + 2, $thumbW, $sbH - 4, $thumbColor);
                    }
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
                sk_push_clip(
                    $el['x'] ?? 0, $el['y'] ?? 0,
                    $el['w'] ?? 0, $el['h'] ?? 0);
                break;

            case 'clip-pop':
                array_pop($this->clipStack);
                sk_pop_clip();
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
                $this->fillRect($x, $y, $w, $h, $el['trackColor'] ?? 0x333333);
                $value = max(0, min($el['value'] ?? 0, $el['max'] ?? 100));
                $maxVal = max($el['max'] ?? 100, 1);
                $fillW = (int)($w * $value / $maxVal);
                if ($fillW > 0) {
                    $this->fillRect($x, $y, $fillW, $h, $el['fillColor'] ?? 0x4488CC);
                }
                if (isset($el['label']) && $el['label'] !== '') {
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

            // ── 图片 ──────────────────────
            case 'image':
                $handle = $el['handle'] ?? 0;
                if ($handle === 0 || ($el['w'] ?? 0) <= 0 || ($el['h'] ?? 0) <= 0) break;
                sk_draw_image($handle, $el['x'] ?? 0, $el['y'] ?? 0, $el['w'] ?? 0, $el['h'] ?? 0);
                break;
        }
    }

    /**
     * 将当前渲染缓冲区保存为 PNG 截图（支持 headless 模式）
     */
    public function saveScreenshot(string $path): void
    {
        sk_save_screenshot($path);
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): void
    {
        sk_fill_rect($x, $y, $w, $h, $color);
    }

    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void
    {
        if ($x < 0 || $y < 0) return;
        if (strlen($text) === 0) return;
        if ($fontSize <= 0) return;

        // 如有 fontFamily 指定且 C++ 端支持，传递到 C++ 层
        if ($fontFamily !== '' && function_exists('sk_set_default_font')) {
            sk_set_default_font($fontFamily);
        }

        // 文本截断由 C++ php_sk_draw_text 层通过 GetTextExtentPoint32W 精确处理
        sk_draw_text($x, $y, $text, $fontSize, $color, $bold);
    }

    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void
    {
        sk_draw_button($x, $y, $w, $h, $bg, $border);
    }

    // ──────────────────────────────────────────────
    //  Text decoration: CSS text-decoration 完整实现
    // ──────────────────────────────────────────────

    /**
     * 绘制 text-decoration 装饰线（underline / overline / line-through）。
     *
     * 支持样式：solid, double, dotted, dashed, wavy。
     * 多个 line 类型可组合（如 "underline overline"）。
     */
    private function drawTextDecoration(array $el): void
    {
        $decorationLine = $el['decorationLine'] ?? 'none';
        if ($decorationLine === '' || $decorationLine === 'none') return;

        $x = (int)($el['x'] ?? 0);
        $y = (int)($el['y'] ?? 0);
        $fontSize = (int)($el['fontSize'] ?? 16);
        $textWidth = (int)($el['textWidth'] ?? 80);
        if ($textWidth <= 0) return;

        $color = (int)($el['decorationColor'] ?? ($el['color'] ?? 0xFFFFFF));
        $style = $el['decorationStyle'] ?? 'solid';
        $thickness = (int)($el['decorationThickness'] ?? 0);
        $underlineOffset = (int)($el['underlineOffset'] ?? 0);

        // Auto thickness: ~5% of font size, minimum 1px
        if ($thickness <= 0) {
            $thickness = (int)max(1, (int)($fontSize / 20));
        }

        // Auto underline gap from bottom of text
        $autoGap = (int)max(1, (int)($fontSize / 12));

        $lineType = $decorationLine;
        $lines = explode(' ', $lineType);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'none' || $line === 'blink' || $line === '') continue;

            $lineY = 0;
            $validLineType = true;
            switch ($line) {
                case 'underline':
                    $offset = $underlineOffset > 0 ? $underlineOffset : $autoGap;
                    $lineY = $y + $fontSize + $offset;
                    break;
                case 'overline':
                    $lineY = $y + 1;
                    break;
                case 'line-through':
                    $lineY = $y + (int)($fontSize * 0.4);
                    break;
                default:
                    $validLineType = false;
                    break;
            }

            if ($validLineType) {
                $this->drawDecorationLine($x, $lineY, $textWidth, $thickness, $color, $style);
            }
        }
    }

    /**
     * 根据样式绘制一条装饰线。
     */
    private function drawDecorationLine(int $x, int $y, int $w, int $thickness, int $color, string $style): void
    {
        switch ($style) {
            case 'solid':
                $this->fillRect($x, $y, $w, $thickness, $color);
                break;

            case 'double':
                $gap = (int)max(1, $thickness);
                $this->fillRect($x, $y, $w, $thickness, $color);
                $this->fillRect($x, $y + $thickness + $gap, $w, $thickness, $color);
                break;

            case 'dotted':
                $dotLen = (int)max($thickness, 2);
                $spacing = $dotLen * 3;
                for ($dx = $x; $dx < $x + $w; $dx += $spacing) {
                    $segW = (int)min($dotLen, $x + $w - $dx);
                    if ($segW <= 0) break;
                    $this->fillRect($dx, $y, $segW, $thickness, $color);
                }
                break;

            case 'dashed':
                $dashLen = (int)max($thickness * 4, 4);
                $gap = (int)max($thickness * 2, 2);
                for ($dx = $x; $dx < $x + $w; $dx += $dashLen + $gap) {
                    $segW = (int)min($dashLen, $x + $w - $dx);
                    if ($segW <= 0) break;
                    $this->fillRect($dx, $y, $segW, $thickness, $color);
                }
                break;

            case 'wavy':
                // Visual approximation: alternating short segments with Y offset
                $waveLen = (int)max($thickness * 3, 6);
                $amplitude = (int)max(1, $thickness);
                $phase = 0;
                for ($dx = $x; $dx < $x + $w; $dx += $waveLen) {
                    $segW = (int)min($waveLen, $x + $w - $dx);
                    if ($segW <= 0) break;
                    $waveY = $y + ($phase === 0 ? 0 : $amplitude);
                    $this->fillRect($dx, $waveY, $segW, $thickness, $color);
                    $phase = 1 - $phase;
                }
                break;
        }
    }

    /**
     * Draw a border line segment with style support (solid/dashed/dotted/double).
     * Used for element borders (horizontal = top/bottom, vertical = left/right).
     */
    private function drawBorderLine(int $x, int $y, int $length, int $thickness, int $color, string $style, bool $horizontal): void
    {
        switch ($style) {
            case 'dashed':
                $dashLen = (int)max($thickness * 4, 4);
                $gap = (int)max($thickness * 2, 2);
                for ($pos = 0; $pos < $length; $pos += $dashLen + $gap) {
                    $seg = (int)min($dashLen, $length - $pos);
                    if ($seg <= 0) break;
                    if ($horizontal) {
                        $this->fillRect($x + $pos, $y, $seg, $thickness, $color);
                    } else {
                        $this->fillRect($x, $y + $pos, $thickness, $seg, $color);
                    }
                }
                break;
            case 'dotted':
                $dotLen = (int)max($thickness, 2);
                $spacing = $dotLen * 3;
                for ($pos = 0; $pos < $length; $pos += $spacing) {
                    $seg = (int)min($dotLen, $length - $pos);
                    if ($seg <= 0) break;
                    if ($horizontal) {
                        $this->fillRect($x + $pos, $y, $seg, $thickness, $color);
                    } else {
                        $this->fillRect($x, $y + $pos, $thickness, $seg, $color);
                    }
                }
                break;
            case 'double':
                $gap = (int)max(1, $thickness);
                if ($horizontal) {
                    $this->fillRect($x, $y, $length, $thickness, $color);
                    $this->fillRect($x, $y + $thickness + $gap, $length, $thickness, $color);
                } else {
                    $this->fillRect($x, $y, $thickness, $length, $color);
                    $this->fillRect($x + $thickness + $gap, $y, $thickness, $length, $color);
                }
                break;
            default: // solid
                if ($horizontal) {
                    $this->fillRect($x, $y, $length, $thickness, $color);
                } else {
                    $this->fillRect($x, $y, $thickness, $length, $color);
                }
                break;
        }
    }
}
