<?php

namespace Px\Css;

use native_types;

class CssColor extends CssValue
{
    /** ARGB 格式（0xAARRGGBB），已转为 GDI BGR 字节序 */
    public readonly int $argb;
    public readonly bool $isTransparent;
    public readonly bool $isCurrentColor;

    // currentColor 哨兵（CSS Color L4 §6.2）：高字节 0xFF 在 Px alpha 模型下
    // 永不出现（opaque→高字节 0，semi→round(a*255)∈[1,254]），故 0xFF------
    // 是无碰撞哨兵命名空间。parseHexColor('currentColor') 返此值，
    // fromArgb 识别后转为 currentColor sentinel CssColor。
    public const CURRENT_COLOR_SENTINEL = 0xFF0C0C0C;

    private function __construct(int $argb, bool $isTransparent = false, bool $isCurrentColor = false)
    {
        $this->argb           = $argb;
        $this->isTransparent  = $isTransparent;
        $this->isCurrentColor = $isCurrentColor;
    }

    public static function fromArgb(int $argb): self
    {
        if ($argb === self::CURRENT_COLOR_SENTINEL) return new self(0, false, true);
        return new self($argb, false, false);
    }
    public static function transparent(): self { return new self(0, true, false); }
    public static function currentColor(): self { return new self(0, false, true); }

    public static function fromString(string $value): self
    {
        // 运行时字符串路径：直接识别 currentColor 关键字。
        if (strtolower(trim($value)) === 'currentcolor') return new self(0, false, true);
        return new self(CssValueParser::parseHexColor($value));
    }

    public function toBgr(): int { return $this->argb & 0xFFFFFF; }
    public function alpha(): int { return ($this->argb >> 24) & 0xFF; }
}
