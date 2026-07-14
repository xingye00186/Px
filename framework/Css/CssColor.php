<?php

namespace Px\Css;

use native_types;

class CssColor extends CssValue
{
    /** ARGB 格式（0xAARRGGBB），已转为 GDI BGR 字节序 */
    public readonly int $argb;
    public readonly bool $isTransparent;
    public readonly bool $isCurrentColor;

    private function __construct(int $argb, bool $isTransparent = false, bool $isCurrentColor = false)
    {
        $this->argb           = $argb;
        $this->isTransparent  = $isTransparent;
        $this->isCurrentColor = $isCurrentColor;
    }

    public static function fromArgb(int $argb): self { return new self($argb, false, false); }
    public static function transparent(): self { return new self(0, true, false); }
    public static function currentColor(): self { return new self(0, false, true); }

    public static function fromString(string $value): self
    {
        return new self(CssValueParser::parseHexColor($value));
    }

    public function toBgr(): int { return $this->argb & 0xFFFFFF; }
    public function alpha(): int { return ($this->argb >> 24) & 0xFF; }
}
