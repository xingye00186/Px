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
                $shadowBlur = $el['shadowBlur'] ?? 0;
                if ($shadowX !== 0 || $shadowY !== 0) {
                    $shadowAlpha = 0.5 * (1.0 / (1.0 + $shadowBlur * 0.05));
                    $shadowAlpha = max(0.05, min(0.5, $shadowAlpha));
                    vue_alpha_fill_rect(
                        $this->hdc,
                        ($el['x'] ?? 0) + $shadowX,
                        ($el['y'] ?? 0) + $shadowY,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $shadowColor, $shadowAlpha
                    );
                }
                $radius = $el['borderRadius'] ?? 0;
                $opacity = $el['opacity'] ?? 1.0;
                $color = $el['color'] ?? 0;
                $noFill = $el['noFill'] ?? false;

                // 预读边框信息
                $borderWidth = $el['borderWidth'] ?? 0;
                $borderColor = $el['borderColor'] ?? 0;
                $bt = $el['borderTopWidth'] ?? $borderWidth;
                $bb = $el['borderBottomWidth'] ?? $borderWidth;
                $bl = $el['borderLeftWidth'] ?? $borderWidth;
                $br = $el['borderRightWidth'] ?? $borderWidth;
                // CSS 标准：border color 超出作用域前统一初始化
                $btc = $el['borderTopColor'] ?? $borderColor;
                $bbc = $el['borderBottomColor'] ?? $borderColor;
                $blc = $el['borderLeftColor'] ?? $borderColor;
                $brc = $el['borderRightColor'] ?? $borderColor;

                if ($radius > 0 && $opacity >= 1.0) {
                    if ($bt > 0 || $bb > 0 || $bl > 0 || $br > 0) {
                        // 圆角 + 边框：外层=边框色（不依赖背景，始终绘制）
                        // CSS Backgrounds and Borders §5.1: 内层圆角 = max(0, R - borderWidth)
                        $bwMax = max($bt, $bb, $bl, $br);
                        $innerRadius = max(0, $radius - $bwMax);
                        // 外层：边框色填充（全圆角矩形）
                        vue_draw_round_rect(
                            $this->hdc,
                            $el['x'] ?? 0, $el['y'] ?? 0,
                            $el['w'] ?? 0, $el['h'] ?? 0,
                            $radius, $btc
                        );
                        // 内层：背景色填充（缩进 borderWidth）—— 仅在有背景时绘制
                        if (!$noFill) {
                            vue_draw_round_rect(
                                $this->hdc,
                                ($el['x'] ?? 0) + $bl,
                                ($el['y'] ?? 0) + $bt,
                                ($el['w'] ?? 0) - $bl - $br,
                                ($el['h'] ?? 0) - $bt - $bb,
                                $innerRadius, $color
                            );
                        }
                    } else {
                        // 无边框 + 圆角：仅在背景存在时画
                        if (!$noFill) {
                            vue_draw_round_rect(
                                $this->hdc,
                                $el['x'] ?? 0, $el['y'] ?? 0,
                                $el['w'] ?? 0, $el['h'] ?? 0,
                                $radius, $color
                            );
                        }
                    }
                } elseif (!$noFill && $opacity < 1.0) {
                    vue_alpha_fill_rect(
                        $this->hdc,
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
                // Draw border outline (only when not already drawn by two-round-rect above)
                // css-test: 若 radius > 0 且有 border，已在双层圆角中完成边框绘制
                if ($radius === 0 && ($bt > 0 || $bb > 0 || $bl > 0 || $br > 0)) {
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
                // 防御：负坐标或超出窗口边界的文本会损坏 GDI 状态
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
                $shadowBlur = $el['shadowBlur'] ?? 0;
                if ($shadowX !== 0 || $shadowY !== 0) {
                    $shadowAlpha = 0.5 * (1.0 / (1.0 + $shadowBlur * 0.05));
                    $shadowAlpha = max(0.05, min(0.5, $shadowAlpha));
                    vue_alpha_fill_rect(
                        $this->hdc,
                        ($el['x'] ?? 0) + $shadowX,
                        ($el['y'] ?? 0) + $shadowY,
                        $el['w'] ?? 0, $el['h'] ?? 0,
                        $shadowColor, $shadowAlpha
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
                    $sbW = $el['sbWidth'] ?? 12;
                    $trackColor = $el['trackColor'] ?? 0x4A4A4A;
                    $thumbColor = $el['thumbColor'] ?? 0x888888;
                    $sbRadius = $el['sbRadius'] ?? 0;
                    $sbX = $x + $w - $sbW;
                    // 轨道
                    $this->fillRect($sbX, $y, $sbW, $h, $trackColor);
                    // 滑块
                    $ratio = min($h / max($contentH, 1), 1.0);
                    $thumbH = max((int)($h * $ratio), 20);
                    $maxScroll = max($contentH - $h, 0);
                    $scrollRatio = $maxScroll > 0 ? $scrollTop / $maxScroll : 0.0;
                    $thumbY = $y + (int)(($h - $thumbH) * $scrollRatio);
                    if ($sbRadius > 0) {
                        vue_draw_round_rect($this->hdc, $sbX + 2, $thumbY, $sbW - 4, $thumbH, $sbRadius, $thumbColor);
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
                    // 轨道
                    $this->fillRect($x, $sbY, $w, $sbH, $trackColor);
                    // 滑块
                    $ratioH = min($w / max($contentW, 1), 1.0);
                    $thumbW = max((int)($w * $ratioH), 20);
                    $maxScrollX = max($contentW - $w, 0);
                    $scrollRatioX = $maxScrollX > 0 ? $scrollLeft / $maxScrollX : 0.0;
                    $thumbX = $x + (int)(($w - $thumbW) * $scrollRatioX);
                    if ($sbRadius > 0) {
                        vue_draw_round_rect($this->hdc, $thumbX, $sbY + 2, $thumbW, $sbH - 4, $sbRadius, $thumbColor);
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
                vue_draw_image($this->hdc, $handle, $el['x'] ?? 0, $el['y'] ?? 0, $el['w'] ?? 0, $el['h'] ?? 0);
                break;
        }
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): void
    {
        vue_fill_rect($this->hdc, $x, $y, $w, $h, $color);
    }

    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void
    {
        if ($x < 0 || $y < 0) return;
        if (strlen($text) === 0) return;
        if ($fontSize <= 0) return;

        // 如有 fontFamily 指定且 C++ 端支持，传递到 C++ 层
        if ($fontFamily !== '' && function_exists('vue_set_default_font')) {
            vue_set_default_font($fontFamily);
        }

        // 文本截断由 C++ php_vue_draw_text 层通过 GetTextExtentPoint32W
        // 精确测量后自动处理，PHP 层不做估算。
        vue_draw_text($this->hdc, $x, $y, $text, $fontSize, $color, $bold);
    }

    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void
    {
        vue_draw_button($this->hdc, $x, $y, $w, $h, $bg, $border);
    }

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
