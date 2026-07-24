<?php

namespace Px\Css;

use native_types;

/**
 * CSS 长度值，携带 unit 信息。
 */
class CssLength extends CssValue
{
    public readonly float $value;
    public readonly string $unit;
    // calc() 支持：unit='calc' 时 value=percent（可为 0 代表纯 px），calcOffset=px 偏移
    // 例：calc(100% - 60px) → unit='calc', value=100, calcOffset=-60
    //       calc(50% + 20px)  → unit='calc', value=50, calcOffset=20
    //       calc(100px + 20px) → unit='px', value=120, calcOffset=0（直接合并）
    public readonly int $calcOffset;

    private function __construct(float $value, string $unit, int $calcOffset = 0)
    {
        $this->value = $value;
        $this->unit  = $unit;
        $this->calcOffset = $calcOffset;
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
    /** CSS calc(<percent>% ± <px>px) — percent 基于上下文解析，再叠加 calcOffset */
    public static function calc(float $percent, int $offsetPx): self { return new self($percent, 'calc', $offsetPx); }

    public static function fromString(string $raw): self
    {
        $lower = strtolower(trim($raw));
        if ($lower === 'auto') return self::auto();
        if ($lower === 'content') return self::content();
        if ($lower === 'none' || $lower === '0') return self::px(0);
        if ($lower === 'min-content') return new self(0, 'min-content');
        if ($lower === 'max-content') return new self(0, 'max-content');
        if ($lower === 'fit-content') return new self(0, 'fit-content');

        // calc() 支持（对标 CSS Values Level 4 §10）
        // 支持常见模式：calc(X% ± Ypx), calc(Ypx ± X%), calc(Xpx ± Ypx), calc(X% ± Y%)
        if (str_starts_with($lower, 'calc(') && str_ends_with($lower, ')')) {
            $inner = substr($lower, 5, -1);
            // Pattern 1: X% ± Ypx
            if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*%\s*([+\-])\s*(\d+(?:\.\d+)?)\s*px\s*$/i', $inner, $m)) {
                $percent = (float)$m[1];
                $offset = ($m[2] === '-' ? -1 : 1) * (int)round((float)$m[3]);
                return self::calc($percent, $offset);
            }
            // Pattern 2: Ypx ± X%
            if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*px\s*([+\-])\s*(\d+(?:\.\d+)?)\s*%\s*$/i', $inner, $m)) {
                $offset = (int)round((float)$m[1]);
                $percent = ($m[2] === '-' ? -1 : 1) * (float)$m[3];
                return self::calc($percent, $offset);
            }
            // Pattern 3: Xpx ± Ypx → 直接合并
            if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*px\s*([+\-])\s*(\d+(?:\.\d+)?)\s*px\s*$/i', $inner, $m)) {
                $a = (float)$m[1];
                $b = (float)$m[3];
                $result = $m[2] === '-' ? $a - $b : $a + $b;
                return self::px($result);
            }
            // Pattern 4: X% ± Y% → 合并为单一 percent
            if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*%\s*([+\-])\s*(\d+(?:\.\d+)?)\s*%\s*$/i', $inner, $m)) {
                $a = (float)$m[1];
                $b = (float)$m[3];
                $result = $m[2] === '-' ? $a - $b : $a + $b;
                return self::percent($result);
            }
            // Fallback: 无法解析 → 提取首个数字作为 px
            $num = (float)preg_replace('/[^-\d.]/', '', $inner);
            return self::px($num);
        }

        // CSS Values Level 3 §10.4: min() / max() / clamp() 简化支持
        // 仅处理纯 px 或纯 % 简单形式（不混合单位、不嵌套 calc）。
        // 完整实现需上下文解析（百分比 vs 像素交互），本处取保守路径。
        if (str_starts_with($lower, 'min(') && str_ends_with($lower, ')')) {
            $inner = substr($lower, 4, -1);
            $parts = explode(',', $inner);
            $values = [];
            $allPx = true;
            $allPct = true;
            foreach ($parts as $p) {
                $t = trim($p);
                if (preg_match('/^(-?\d+(?:\.\d+)?)px$/i', $t, $m)) {
                    $values[] = (float)$m[1];
                    $allPct = false;
                } else if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $t, $m)) {
                    $values[] = (float)$m[1];
                    $allPx = false;
                } else {
                    // 后退：包含未支持单位/嵌套，取首个数字当 px
                    $n = (float)preg_replace('/[^-\d.]/', '', $t);
                    if ($n > 0) return self::px($n);
                    return self::px(0);
                }
            }
            $v = min($values);
            if ($allPx) return self::px($v);
            if ($allPct) return self::percent($v);
            return self::px($v);
        }
        if (str_starts_with($lower, 'max(') && str_ends_with($lower, ')')) {
            $inner = substr($lower, 4, -1);
            $parts = explode(',', $inner);
            $values = [];
            $allPx = true;
            $allPct = true;
            foreach ($parts as $p) {
                $t = trim($p);
                if (preg_match('/^(-?\d+(?:\.\d+)?)px$/i', $t, $m)) {
                    $values[] = (float)$m[1];
                    $allPct = false;
                } else if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $t, $m)) {
                    $values[] = (float)$m[1];
                    $allPx = false;
                } else {
                    $n = (float)preg_replace('/[^-\d.]/', '', $t);
                    if ($n > 0) return self::px($n);
                    return self::px(0);
                }
            }
            $v = max($values);
            if ($allPx) return self::px($v);
            if ($allPct) return self::percent($v);
            return self::px($v);
        }
        // clamp(MIN, VAL, MAX) → min(max(VAL, MIN), MAX)
        if (str_starts_with($lower, 'clamp(') && str_ends_with($lower, ')')) {
            $inner = substr($lower, 6, -1);
            $parts = explode(',', $inner);
            if (count($parts) === 3) {
                $extract = function(string $s): ?array {
                    $t = trim($s);
                    if (preg_match('/^(-?\d+(?:\.\d+)?)px$/i', $t, $m)) return [(float)$m[1], 'px'];
                    if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $t, $m)) return [(float)$m[1], '%'];
                    return null;
                };
                $minP = $extract($parts[0]);
                $valP = $extract($parts[1]);
                $maxP = $extract($parts[2]);
                if ($minP !== null && $valP !== null && $maxP !== null) {
                    // 均同单位时才 clamp（混合单位需上下文）
                    if ($minP[1] === $valP[1] && $valP[1] === $maxP[1]) {
                        $clamped = min($maxP[0], max($valP[0], $minP[0]));
                        return $valP[1] === 'px' ? self::px($clamped) : self::percent($clamped);
                    }
                    // 混合：取中间值（后退）
                    return $valP[1] === 'px' ? self::px($valP[0]) : self::percent($valP[0]);
                }
            }
            $n = (float)preg_replace('/[^-\d.]/', '', $inner);
            return self::px($n);
        }

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
    public function isCalc(): bool { return $this->unit === 'calc'; }
    public function isContent(): bool { return $this->unit === 'content'; }
    public function isNone(): bool { return $this->unit === 'none'; }
    public function isIntrinsic(): bool { return in_array($this->unit, ['min-content', 'max-content', 'fit-content'], true); }
    public function isRelative(): bool
    {
        return in_array($this->unit, ['%', 'em', 'rem', 'vw', 'vh', 'vmin', 'vmax', 'calc'], true);
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
            'calc'    => $containerSize > 0 ? ((int)($containerSize * $this->value / 100.0) + $this->calcOffset) : $this->calcOffset,
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
