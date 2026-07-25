<?php

namespace Px\Css;

use native_types;

class CssFlex extends CssValue
{
    public readonly float $grow;
    public readonly float $shrink;
    public readonly CssLength $basis;

    public function __construct(float $grow, float $shrink, CssLength $basis)
    {
        $this->grow   = $grow;
        $this->shrink = $shrink;
        $this->basis  = $basis;
    }

    public static function initial(): self { return new self(0.0, 1.0, CssLength::auto()); }
    public static function auto(): self { return new self(1.0, 1.0, CssLength::auto()); }
    public static function none(): self { return new self(0.0, 0.0, CssLength::auto()); }

    public static function fromString(string $value): self
    {
        $flex = trim($value);
        if ($flex === '') return self::initial();

        $lower = strtolower($flex);
        if ($lower === 'auto')   return self::auto();
        if ($lower === 'none')   return self::none();
        if ($lower === 'initial') return self::initial();
        if ($lower === 'content') return new self(0.0, 1.0, CssLength::content());

        $parts = preg_split('/\s+/', $flex);
        $grow   = isset($parts[0]) ? (float)$parts[0] : 0.0;
        $shrink = isset($parts[1]) ? (float)$parts[1] : 1.0;

        // CSS Flexbox §7.1.1：`flex: <number> [<number>]` 的 basis 为 **0%**（非 0px）。
        // 对标 Blink StyleBuilderConverter：percent basis 在主轴包含块不定时 used value = content，
        // 而长度 0px 恒为 0——两者在 indefinite 主轴下语义不同，不可混同。
        $basis = CssLength::percent(0);
        if (isset($parts[2]) && $parts[2] !== '') {
            $b = strtolower(trim($parts[2]));
            if ($b === 'auto') {
                $basis = CssLength::auto();
            } elseif ($b === 'content') {
                $basis = CssLength::content();
            } else {
                $basis = CssLength::fromString($parts[2]);
            }
        }

        return new self($grow, $shrink, $basis);
    }
}
