<?php

namespace Px\Layout\Flex;

use native_types;

use Px\Css\ComputedStyle;

class FlexItem
{
    public float $grow = 0.0;
    public float $shrink = 1.0;
    public int $basis = -1;
    public bool $isFlexGrow = false;
    public int $mainSize = 0;
    public int $crossSize = 0;
    public int $mainOffset = 0;
    public int $crossOffset = 0;
    public int $x = 0;
    public int $y = 0;
    public int $w = 0;
    public int $h = 0;
    public int $visualW = 0;
    public int $visualH = 0;
    public int $marginBefore = 0;
    public int $marginAfter = 0;
    public int $marginCrossBefore = 0;
    public int $marginCrossAfter = 0;
    // CSS Flexbox §8.1: auto margin 分配剩余主轴空间
    public bool $marginAutoBefore = false;
    public bool $marginAutoAfter = false;
    public bool $hasExplicitCrossSize = false;
    public string $alignSelf = 'auto';
    public ?ComputedStyle $computedStyle = null;
    public ?string $content = null;
    public ?array $originalChildren = null;
}
