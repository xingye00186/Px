<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;

/**
 * PhysicalFragment — 不可变几何输出（对标 Blink NGPhysicalBoxFragment）
 *
 * 布局结果的唯一权威源。VNodeRenderer 消费此对象而非 RenderNode。
 * 所有字段 readonly，构造后不可变。
 *
 * 自包含：每个 Fragment 持有自己的 ComputedStyle 快照，
 * 回引用 sourceNode 仅用于事件路由。
 */
class PhysicalFragment
{
    public readonly int $x;
    public readonly int $y;
    public readonly int $w;
    public readonly int $h;
    public readonly int $visualW;
    public readonly int $visualH;
    public readonly int $layer;

    /** 可滚动内容尺寸 */
    public readonly int $contentWidth;
    public readonly int $contentHeight;

    /** 自带样式快照（不回 sourceNode 读取） */
    public readonly ?ComputedStyle $style;

    /** 子 Fragment 数组 */
    public readonly array $children;

    /** 回引用 RenderNode（仅用于事件路由取 groupId） */
    public readonly ?RenderNode $sourceNode;

    /** 滚动状态 */
    public readonly int $scrollTop;
    public readonly int $scrollLeft;
    public readonly bool $isScrollContainer;

    public function __construct(
        int $x,
        int $y,
        int $w,
        int $h,
        int $visualW = 0,
        int $visualH = 0,
        int $layer = 0,
        int $contentWidth = 0,
        int $contentHeight = 0,
        ?ComputedStyle $style = null,
        array $children = [],
        ?RenderNode $sourceNode = null,
        int $scrollTop = 0,
        int $scrollLeft = 0,
        bool $isScrollContainer = false,
    ) {
        $this->x               = (int)$x;
        $this->y               = (int)$y;
        $this->w               = (int)$w;
        $this->h               = (int)$h;
        $this->visualW         = (int)($visualW > 0 ? $visualW : $w);
        $this->visualH         = (int)($visualH > 0 ? $visualH : $h);
        $this->layer           = (int)$layer;
        $this->contentWidth    = (int)$contentWidth;
        $this->contentHeight   = (int)$contentHeight;
        $this->style           = $style;
        $this->children        = $children;
        $this->sourceNode      = $sourceNode;
        $this->scrollTop       = (int)$scrollTop;
        $this->scrollLeft      = (int)$scrollLeft;
        $this->isScrollContainer = $isScrollContainer;
    }

    /** 从 LayoutResult 构造（适配器用） */
        /** 序列化为数组（测试/导出用） */
    public function toArray(): array
    {
        $arr = [
            'x' => $this->x,
            'y' => $this->y,
            'w' => $this->w,
            'h' => $this->h,
            'visualW' => $this->visualW,
            'visualH' => $this->visualH,
            'layer' => $this->layer,
            'contentWidth' => $this->contentWidth,
            'contentHeight' => $this->contentHeight,
        ];
        if ($this->isScrollContainer) {
            $arr['scrollTop'] = $this->scrollTop;
            $arr['scrollLeft'] = $this->scrollLeft;
        }
        $childArr = [];
        foreach ($this->children as $child) {
            $childArr[] = $child->toArray();
        }
        if (!empty($childArr)) {
            $arr['children'] = $childArr;
        }
        return $arr;
    }
}
