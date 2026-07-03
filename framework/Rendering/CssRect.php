<?php

namespace Px\Rendering;

use native_types;

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

    public static function fromShorthand(array $values, CssLength $default): self
    {
        $top    = $values[0] ?? $default;
        $right  = $values[1] ?? $top;
        $bottom = $values[2] ?? $top;
        $left   = $values[3] ?? $right;
        return new self($top, $right, $bottom, $left);
    }

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

    public function horizontalTotal(): int
    {
        return $this->left->toPx() + $this->right->toPx();
    }

    public function verticalTotal(): int
    {
        return $this->top->toPx() + $this->bottom->toPx();
    }

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
