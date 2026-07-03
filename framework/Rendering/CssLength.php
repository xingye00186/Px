<?php

namespace Px\Rendering;

use native_types;

/**
 * CSS 长度值，携带 unit 信息。
 */
class CssLength extends CssValue
{
    public readonly float $value;
    public readonly string $unit;

    private function __construct(float $value, string $unit)
    {
        $this->value = $value;
        $this->unit  = $unit;
    }

    public static function px(float $value): self { return new self($value, 'px'); }
    public static function percent(float $value): self { return new self($value, '%'); }
    public static function em(float $value): self { return new self($value, 'em'); }
    public static function rem(float $value): self { return new self($value, 'rem'); }
    public static function vw(float $value): self { return new self($value, 'vw'); }
    public static function vh(float $value): self { return new self($value, 'vh'); }
    public static function auto(): self { return new self(0, 'auto'); }
    public static function content(): self { return new self(0, 'content'); }
    public static function none(): self { return new self(0, 'none'); }

    public static function fromString(string $raw): self
    {
        $lower = strtolower(trim($raw));
        if ($lower === 'auto') return self::auto();
        if ($lower === 'content') return self::content();
        if ($lower === 'none' || $lower === '0') return self::px(0);
        if ($lower === 'min-content') return new self(0, 'min-content');
        if ($lower === 'max-content') return new self(0, 'max-content');
        if ($lower === 'fit-content') return new self(0, 'fit-content');

        if (str_ends_with($lower, '%')) {
            $num = (float)substr($lower, 0, -1);
            return self::percent($num);
        }
        if (str_ends_with($lower, 'em')) {
            $num = (float)substr($lower, 0, -2);
            if (str_ends_with($lower, 'rem')) {
                $num = (float)substr($lower, 0, -3);
                return self::rem($num);
            }
            return self::em($num);
        }
        if (str_ends_with($lower, 'vw')) {
            $num = (float)substr($lower, 0, -2);
            return self::vw($num);
        }
        if (str_ends_with($lower, 'vh')) {
            $num = (float)substr($lower, 0, -2);
            return self::vh($num);
        }
        if (str_ends_with($lower, 'vmin')) {
            $num = (float)substr($lower, 0, -4);
            return new self($num, 'vmin');
        }
        if (str_ends_with($lower, 'vmax')) {
            $num = (float)substr($lower, 0, -4);
            return new self($num, 'vmax');
        }
        $num = (float)preg_replace('/[^-\d.]/', '', $raw);
        return self::px($num);
    }

    public function isAuto(): bool { return $this->unit === 'auto'; }
    public function isPercent(): bool { return $this->unit === '%'; }
    public function isContent(): bool { return $this->unit === 'content'; }
    public function isNone(): bool { return $this->unit === 'none'; }
    public function isIntrinsic(): bool { return in_array($this->unit, ['min-content', 'max-content', 'fit-content'], true); }
    public function isRelative(): bool
    {
        return in_array($this->unit, ['%', 'em', 'rem', 'vw', 'vh', 'vmin', 'vmax'], true);
    }

    public function resolveInContext(
        int $containerSize = 0,
        int $parentFontSize = 16,
        int $rootFontSize = 16,
        int $viewportW = 1920,
        int $viewportH = 1080
    ): int {
        return match ($this->unit) {
            'px'      => (int)$this->value,
            '%'       => $containerSize > 0 ? (int)($containerSize * $this->value / 100.0) : (int)$this->value,
            'em'      => (int)($this->value * $parentFontSize),
            'rem'     => (int)($this->value * $rootFontSize),
            'vw'      => (int)($this->value * $viewportW / 100.0),
            'vh'      => (int)($this->value * $viewportH / 100.0),
            'vmin'    => (int)($this->value * min($viewportW, $viewportH) / 100.0),
            'vmax'    => (int)($this->value * max($viewportW, $viewportH) / 100.0),
            'auto', 'content', 'none' => 0,
            default   => (int)$this->value,
        };
    }

    public function resolveBoxPercent(int $containingBlockWidth): int
    {
        if ($this->unit === '%' && $containingBlockWidth > 0) {
            return (int)($containingBlockWidth * $this->value / 100.0);
        }
        return (int)$this->value;
    }

    public function toPx(): int { return (int)$this->value; }
}
