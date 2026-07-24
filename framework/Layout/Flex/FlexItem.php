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
    /** §9.7.4 clamp rerun: 已被 min/max 限制的 item 标记为 frozen，不再参与下一轮分配 */
    public bool $frozen = false;
    /** 布局源 RenderNode（computeMinMaxSizes 需要读取子节点） */
    public ?\Px\Render\RenderNode $node = null;
    /** 缓存的 min-width:auto 值（-1=未设置，避免 clamp loop 内重复计算） */
    public int $cachedMinW = -1;
}
