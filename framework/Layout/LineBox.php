<?php

namespace Px\Layout;

use native_types;

/**
 * LineBox — 行盒（对标 Blink NGPhysicalLineBoxFragment 的行度量部分）
 *
 * 一个 LineBox 代表一行内联内容。包含：
 *   - items: 该行所有 InlineItem
 *   - ascent: 行内所有项的最大 ascent
 *   - descent: 行内所有项的最大 descent
 *   - width: 行内所有项的总宽度
 *   - lineHeight: 应用 CSS line-height 后的实际行高
 *   - baseline: 行盒基线位置（从行盒顶部算起）
 *
 * CSS 2.2 §10.8: 行盒高度 = max(line-height, ascent+descent)
 * CSS 2.2 §10.8.1: 行距 = line-height - (ascent+descent)，上下各分一半
 */
class LineBox
{
    /** @var InlineItem[] */
    public readonly array $items;
    public readonly int $ascent;
    public readonly int $descent;
    public readonly int $width;
    public readonly int $lineHeight;
    public readonly int $baseline;

    /**
     * @param InlineItem[] $items
     */
    public function __construct(array $items, int $ascent, int $descent, int $width, int $lineHeight)
    {
        $this->items = $items;
        $this->ascent = $ascent;
        $this->descent = $descent;
        $this->width = $width;
        $this->lineHeight = $lineHeight;
        // Baseline = leading/2 + ascent（CSS 2.2 §10.8.1 half-leading 模型）
        $halfLeading = (int)(($lineHeight - ($ascent + $descent)) / 2);
        $this->baseline = max(0, $halfLeading) + $ascent;
    }

    /** 行盒高度（= lineHeight，对标 CSS） */
    public function height(): int
    {
        return $this->lineHeight;
    }
}
