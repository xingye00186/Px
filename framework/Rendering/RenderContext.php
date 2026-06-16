<?php

namespace Px\Rendering;

use native_types;

abstract class RenderContext
{
    abstract public function beginFrame(): void;
    abstract public function endFrame(): void;
    abstract public function drawElement(array $el): void;
    abstract public function fillRect(int $x, int $y, int $w, int $h, int $color): void;
    abstract public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void;
    abstract public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void;

    /**
     * 将当前渲染缓冲区保存为 PNG 截图（headless 模式）。
     * 底层调用 sk_save_screenshot() 从窗口 DC 直接保存。
     */
    public function saveScreenshot(string $path): bool
    {
        if (function_exists('sk_save_screenshot')) {
            return sk_save_screenshot($path);
        }
        return false;
    }
}