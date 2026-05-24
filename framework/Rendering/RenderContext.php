<?php

namespace Px\Rendering;

abstract class RenderContext
{
    abstract public function beginFrame(): void;
    abstract public function endFrame(): void;
    abstract public function drawElement(array $el): void;
    abstract public function fillRect(int $x, int $y, int $w, int $h, int $color): void;
    abstract public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold): void;
    abstract public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void;
}