<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;

/**
 * LayoutFragment — 不可变布局结果（输出）
 *
 * 对标 Blink PhysicalFragment。
 * 由 FragmentBuilder::build() 创建，通过 applyTo() 原子回写 RenderNode。
 */
class LayoutFragment
{
    public readonly int $x;
    public readonly int $y;
    public readonly int $w;
    public readonly int $h;
    public readonly int $visualW;
    public readonly int $visualH;
    public readonly int $layer;
    public readonly int $contentWidth;
    public readonly int $contentHeight;
    public readonly ?ComputedStyle $style;

    /** @var LayoutFragment[] 子 fragment */
    public readonly array $children;

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
        array $children = []
    ) {
        $this->x             = $x;
        $this->y             = $y;
        $this->w             = $w;
        $this->h             = $h;
        $this->visualW       = $visualW > 0 ? $visualW : $w;
        $this->visualH       = $visualH > 0 ? $visualH : $h;
        $this->layer         = $layer;
        $this->contentWidth  = $contentWidth;
        $this->contentHeight = $contentHeight;
        $this->style         = $style;
        $this->children      = $children;
    }

    /**
     * 原子回写 RenderNode。
     * RenderNode.x/y/w/h 的唯一写入通道。
     */
    public function applyTo(RenderNode $node): void
    {
        $node->x        = $this->x;
        $node->y        = $this->y;
        $node->w        = $this->w;
        $node->h        = $this->h;
        $node->visualW  = $this->visualW;
        $node->visualH  = $this->visualH;
        $node->layer    = $this->layer;

        if ($this->contentWidth > 0)  $node->contentWidth  = $this->contentWidth;
        if ($this->contentHeight > 0) $node->contentHeight = $this->contentHeight;

        // 递归回写子节点
        if (count($this->children) > 0) {
            $childCount = min(count($this->children), count($node->children));
            for ($i = 0; $i < $childCount; $i++) {
                $this->children[$i]->applyTo($node->children[$i]);
            }
        }
    }

    /**
     * 对子 fragment 按主轴排序/偏移。
     * 用于 flex justify-content / grid placement 后处理。
     */
    public function mapChildren(callable $fn): LayoutFragment
    {
        $newChildren = [];
        foreach ($this->children as $child) {
            $newChildren[] = $fn($child);
        }
        return new LayoutFragment(
            x: $this->x, y: $this->y,
            w: $this->w, h: $this->h,
            visualW: $this->visualW, visualH: $this->visualH,
            layer: $this->layer,
            contentWidth: $this->contentWidth, contentHeight: $this->contentHeight,
            style: $this->style,
            children: $newChildren,
        );
    }
}
