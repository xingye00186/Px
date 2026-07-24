<?php

namespace Px\Layout;

use native_types;

/**
 * MinMaxSizes — 内在尺寸计算结果（对标 Blink NGMinMaxSizes）
 *
 * CSS Sizing Level 3 §4:
 *   - min-content size: 元素在不溢出的前提下能容纳内容的最小宽度
 *   - max-content size: 元素在不换行/不压缩的前提下的自然宽度
 *
 * 用途：
 *   - shrink-to-fit 宽度计算 (CSS 2.2 §10.3.5/§10.3.7)
 *     width = min(max-content, max(min-content, available))
 *   - min-width:auto 在 flex/grid item 上 (CSS-Sizing-3 §5.2)
 *     automatic minimum size = min-content size
 *   - table auto-width (CSS 2.2 §17.5.2)
 *   - flex-basis:content (CSS Flexbox §7.1)
 *
 * 不可变值对象。AOT 安全（use native_types + int 字段）。
 */
class MinMaxSizes
{
    public readonly int $minContent;
    public readonly int $maxContent;

    public function __construct(int $minContent, int $maxContent)
    {
        $this->minContent = $minContent;
        $this->maxContent = $maxContent;
    }

    /** 应用 shrink-to-fit 公式: min(max-content, max(min-content, available)) */
    public function shrinkToFit(int $available): int
    {
        return min($this->maxContent, max($this->minContent, $available));
    }

    /** 零值（默认/fallback） */
    public static function zero(): self
    {
        return new self(0, 0);
    }
}
