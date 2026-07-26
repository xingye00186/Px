<?php

namespace Px\Layout;

use native_types;

/**
 * InlineItem — 内联格式化项（对标 Blink NGInlineItem）
 *
 * Blink NGInlineLayoutAlgorithm 将内联内容拆分为 InlineItem 序列：
 *   - text: 文本片段
 *   - atomic: 替换元素（img/inline-block/input 等有固有尺寸的内联元素）
 *   - open-tag: 内联格式化标签开始（span/b/em 等，带有样式影响）
 *   - close-tag: 内联格式化标签结束
 *
 * 每个 InlineItem 携带度量信息（ascent/descent/width），
 * LineBreaker 据此进行行断裂，产出 LineBox。
 *
 * AOT 安全：use native_types + int/string 字段。
 */
class InlineItem
{
    public const TYPE_TEXT = 'text';
    public const TYPE_ATOMIC = 'atomic';
    public const TYPE_OPEN_TAG = 'open';
    public const TYPE_CLOSE_TAG = 'close';
    /** 强制断行（<br>，对标 Blink NGInlineItem kControl \n forced break） */
    public const TYPE_FORCED_BREAK = 'br';

    public readonly string $type;
    public readonly int $width;
    public readonly int $ascent;
    public readonly int $descent;
    public readonly int $marginLeft;
    public readonly int $marginRight;

    /** 原始 Fragment（atomic 类型持有，用于最终输出） */
    public readonly ?PhysicalFragment $fragment;

    /** 文本内容（text 类型持有） */
    public readonly string $text;

    /** 样式引用（用于 vertical-align / font-size） */
    public readonly ?\Px\Css\ComputedStyle $style;

    public function __construct(
        string $type,
        int $width,
        int $ascent,
        int $descent,
        ?PhysicalFragment $fragment = null,
        string $text = '',
        ?\Px\Css\ComputedStyle $style = null,
        int $marginLeft = 0,
        int $marginRight = 0,
    ) {
        $this->type = $type;
        $this->width = $width;
        $this->ascent = $ascent;
        $this->descent = $descent;
        $this->fragment = $fragment;
        $this->text = $text;
        $this->style = $style;
        $this->marginLeft = $marginLeft;
        $this->marginRight = $marginRight;
    }

    /** 项总宽度（含 margin） */
    public function totalWidth(): int
    {
        return $this->width + $this->marginLeft + $this->marginRight;
    }

    /** 项总高度 = ascent + descent */
    public function height(): int
    {
        return $this->ascent + $this->descent;
    }
}
