<?php

namespace Px\Rendering;

use native_types;

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

    public function isFlexStart(): bool { return $this->value === 'flex-start'; }
    public function isCenter(): bool { return $this->value === 'center'; }
    public function isStretch(): bool { return $this->value === 'stretch'; }
    public function isAuto(): bool { return $this->value === 'auto'; }
    public function isNone(): bool { return $this->value === 'none'; }
}
