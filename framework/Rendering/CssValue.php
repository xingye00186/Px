<?php

namespace Px\Rendering;

use native_types;

/**
 * CssValue — CSS 值类型基类
 *
 * CSS 值的类型信息从解析到布局全程保留。
 * 子类: CssLength, CssColor, CssKeyword, CssRect, CssFlex
 */
abstract class CssValue
{
}

// =========================================================================
// CssLength — CSS 长度值
// =========================================================================

/**
 * CSS 长度值，携带 unit 信息。
 *
 * unit 取值:
 *   'px'      — 像素（绝对长度）
 *   '%'       — 百分比（解析时需 containerSize）
 *   'em'      — 相对父元素 font-size
 *   'rem'     — 相对根元素 font-size
 *   'vw'      — 视口宽度百分比
 *   'vh'      — 视口高度百分比
 *   'auto'    — CSS auto 关键字
 *   'content' — CSS content 关键字（flex-basis）
 *   'none'    — CSS none 关键字
 */
class CssLength extends CssValue
{
    public readonly float $value;
    public readonly string $unit; // 'px'|'%'|'em'|'rem'|'vw'|'vh'|'auto'|'content'|'none'

    private function __construct(float $value, string $unit)
    {
        $this->value = $value;
        $this->unit  = $unit;
    }

    // ── 工厂方法 ──

    public static function px(float $value): self
    {
        return new self($value, 'px');
    }

    public static function percent(float $value): self
    {
        return new self($value, '%');
    }

    public static function em(float $value): self
    {
        return new self($value, 'em');
    }

    public static function rem(float $value): self
    {
        return new self($value, 'rem');
    }

    public static function vw(float $value): self
    {
        return new self($value, 'vw');
    }

    public static function vh(float $value): self
    {
        return new self($value, 'vh');
    }

    public static function auto(): self
    {
        return new self(0, 'auto');
    }

    public static function content(): self
    {
        return new self(0, 'content');
    }

    public static function none(): self
    {
        return new self(0, 'none');
    }

    /** 从 CSS 字符串值解析（内部使用） */
    public static function fromString(string $raw): self
    {
        $lower = strtolower(trim($raw));
        if ($lower === 'auto') return self::auto();
        if ($lower === 'content') return self::content();
        if ($lower === 'none' || $lower === '0') return self::px(0);

        if (str_ends_with($lower, '%')) {
            $num = (float)substr($lower, 0, -1);
            return self::percent($num);
        }
        if (str_ends_with($lower, 'em')) {
            $num = (float)substr($lower, 0, -2);
            // 'rem' is longer, so check it first
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
        // vmin / vmax
        if (str_ends_with($lower, 'vmin')) {
            $num = (float)substr($lower, 0, -4);
            return new self($num, 'vmin');
        }
        if (str_ends_with($lower, 'vmax')) {
            $num = (float)substr($lower, 0, -4);
            return new self($num, 'vmax');
        }
        // Default: px
        $num = (float)preg_replace('/[^-\d.]/', '', $raw);
        return self::px($num);
    }

    // ── 判断 ──

    public function isAuto(): bool { return $this->unit === 'auto'; }
    public function isPercent(): bool { return $this->unit === '%'; }
    public function isContent(): bool { return $this->unit === 'content'; }
    public function isNone(): bool { return $this->unit === 'none'; }
    public function isRelative(): bool
    {
        return in_array($this->unit, ['%', 'em', 'rem', 'vw', 'vh', 'vmin', 'vmax'], true);
    }

    /**
     * 在给定上下文中解析为绝对像素值。
     *
     * @param int $containerSize  容器尺寸（百分比基准）
     * @param int $parentFontSize 父元素 font-size（em 基准）
     * @param int $rootFontSize   根元素 font-size（rem 基准）
     * @param int $viewportW      视口宽度（vw/vmin 基准）
     * @param int $viewportH      视口高度（vh/vmin/vmax 基准）
     * @return int 解析后的像素值
     */
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
            'auto',
            'content',
            'none'    => 0,
            default   => (int)$this->value,
        };
    }

    /**
     * 解析 margin/padding 百分比值（CSS Box Model §7）。
     * 所有方向（top/right/bottom/left）的百分比均基于包含块宽度计算。
     */
    public function resolveBoxPercent(int $containingBlockWidth): int
    {
        if ($this->unit === '%' && $containingBlockWidth > 0) {
            return (int)($containingBlockWidth * $this->value / 100.0);
        }
        return (int)$this->value;
    }

    /** 返回像素整数值（仅对 px unit 有意义） */
    public function toPx(): int
    {
        return (int)$this->value;
    }
}

// =========================================================================
// CssColor — CSS 颜色值
// =========================================================================

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

    public static function fromArgb(int $argb): self
    {
        return new self($argb, false, false);
    }

    public static function transparent(): self
    {
        return new self(0, true, false);
    }

    public static function currentColor(): self
    {
        return new self(0, false, true);
    }

    /**
     * 从 CSS 颜色字符串解析。
     * 支持: #RRGGBB, #RGB, #RRGGBBAA, rgba(), rgb(), named colors
     */
    public static function fromString(string $value): self
    {
        return new self(CssValueParser::parseHexColor($value));
    }

    /** 获取 GDI COLORREF 值（BGR 格式） */
    public function toBgr(): int
    {
        return $this->argb & 0xFFFFFF;
    }

    /** 获取 Alpha 通道（0-255） */
    public function alpha(): int
    {
        return ($this->argb >> 24) & 0xFF;
    }
}

// =========================================================================
// CssKeyword — CSS 标识符值
// =========================================================================

class CssKeyword extends CssValue
{
    public readonly string $value;

    public function __construct(string $value)
    {
        $this->value = strtolower(trim($value));
    }

    public function equals(string $keyword): bool
    {
        return $this->value === strtolower(trim($keyword));
    }

    /** CSS flex 关键字快捷判断 */
    public function isFlexStart(): bool { return $this->value === 'flex-start'; }
    public function isCenter(): bool { return $this->value === 'center'; }
    public function isStretch(): bool { return $this->value === 'stretch'; }
    public function isAuto(): bool { return $this->value === 'auto'; }
    public function isNone(): bool { return $this->value === 'none'; }
}

// =========================================================================
// CssRect — CSS 四边值（padding, margin, border-width 等）
// =========================================================================

class CssRect extends CssValue
{
    public readonly CssLength $top;
    public readonly CssLength $right;
    public readonly CssLength $bottom;
    public readonly CssLength $left;

    public function __construct(
        CssLength $top,
        CssLength $right,
        CssLength $bottom,
        CssLength $left
    ) {
        $this->top    = $top;
        $this->right  = $right;
        $this->bottom = $bottom;
        $this->left   = $left;
    }

    /** 从 1-4 值数组构造（CSS shorthand 规则） */
    public static function fromShorthand(array $values, CssLength $default): self
    {
        $top    = $values[0] ?? $default;
        $right  = $values[1] ?? $top;
        $bottom = $values[2] ?? $top;
        $left   = $values[3] ?? $right;
        return new self($top, $right, $bottom, $left);
    }

    /** 从 CSS 字符串值（如 "10px 20px"）解析 */
    public static function fromString(string $value, string $defaultUnit = 'px'): self
    {
        $parts = preg_split('/\s+/', trim($value));
        $lengths = [];
        foreach ($parts as $p) {
            $lengths[] = CssLength::fromString($p);
        }
        if (count($lengths) === 0) {
            $lengths = [CssLength::px(0)];
        }
        return self::fromShorthand($lengths, CssLength::px(0));
    }

    /** 水平总和（left + right） */
    public function horizontalTotal(): int
    {
        return $this->left->toPx() + $this->right->toPx();
    }

    /** 垂直总和（top + bottom） */
    public function verticalTotal(): int
    {
        return $this->top->toPx() + $this->bottom->toPx();
    }

    /** 在包含块宽度下解析所有百分比值为像素 */
    public function resolveInContext(int $containingBlockWidth): array
    {
        return [
            'top'    => $this->top->resolveBoxPercent($containingBlockWidth),
            'right'  => $this->right->resolveBoxPercent($containingBlockWidth),
            'bottom' => $this->bottom->resolveBoxPercent($containingBlockWidth),
            'left'   => $this->left->resolveBoxPercent($containingBlockWidth),
        ];
    }
}

// =========================================================================
// CssFlex — CSS flex 简写展开值
// =========================================================================

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

    public static function initial(): self
    {
        return new self(0.0, 1.0, CssLength::auto());
    }

    public static function auto(): self
    {
        return new self(1.0, 1.0, CssLength::auto());
    }

    public static function none(): self
    {
        return new self(0.0, 0.0, CssLength::auto());
    }

    /** 从 CSS flex 字符串值解析 */
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

        $basis = CssLength::px(0);
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
