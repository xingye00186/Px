<?php

namespace Px\Layout;

use native_types;

/**
 * IntrinsicSizes — 内在尺寸测量结果
 *
 * 两阶段 IntrinsicSizing 的 Pass 1 输出。
 * Pass 2 使用此结果设置 percentageWidth/Height 以一次收敛。
 */
class IntrinsicSizes
{
    public readonly int $minContentWidth;
    public readonly int $maxContentWidth;
    public readonly int $minContentHeight;
    public readonly int $maxContentHeight;

    public function __construct(
        int $minContentWidth = 0,
        int $maxContentWidth = 0,
        int $minContentHeight = 0,
        int $maxContentHeight = 0,
    ) {
        $this->minContentWidth  = (int)$minContentWidth;
        $this->maxContentWidth  = (int)max($minContentWidth, $maxContentWidth);
        $this->minContentHeight = (int)$minContentHeight;
        $this->maxContentHeight = (int)max($minContentHeight, $maxContentHeight);
    }
}
