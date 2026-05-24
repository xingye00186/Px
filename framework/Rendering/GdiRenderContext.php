<?php

namespace Px\Rendering;

class GdiRenderContext extends RenderContext
{
    private int $hWnd;
    private int $hdc = 0;

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

        if ($type === 'rect') {
            $this->fillRect(
                $el['x'] ?? 0, $el['y'] ?? 0,
                $el['w'] ?? 0, $el['h'] ?? 0,
                $el['color'] ?? 0
            );
        } elseif ($type === 'text') {
            $this->drawText(
                $el['x'] ?? 0, $el['y'] ?? 0,
                $el['text'] ?? '',
                $el['fontSize'] ?? 16,
                $el['color'] ?? 0xFFFFFF,
                $el['bold'] ?? 0
            );
        } elseif ($type === 'button') {
            $this->drawButton(
                $el['x'] ?? 0, $el['y'] ?? 0,
                $el['w'] ?? 0, $el['h'] ?? 0,
                $el['bg'] ?? 0xC0C0C0,
                $el['border'] ?? 0
            );
            if (isset($el['label'])) {
                $this->drawText(
                    $el['labelX'] ?? 0,
                    $el['labelY'] ?? 0,
                    $el['label'],
                    $el['labelFontSize'] ?? 22,
                    $el['fg'] ?? 0x000000,
                    1
                );
            }
        }
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): void
    {
        vue_fill_rect($this->hdc, $x, $y, $w, $h, $color);
    }

    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold): void
    {
        vue_draw_text($this->hdc, $x, $y, $text, $fontSize, $color, $bold);
    }

    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void
    {
        vue_draw_button($this->hdc, $x, $y, $w, $h, $bg, $border);
    }
}