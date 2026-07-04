<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;

/**
 * LayoutResult — 不可变布局输出（唯一可信源）
 *
 * 对标 Blink PhysicalFragment。
 * 由布局策略的 layout() 方法直接构造并返回。
 * 所有字段 readonly，构造后不可变。
 *
 * AOT 兼容：
 * - 所有属性 public readonly
 * - 不含闭包、引用、回调
 * - 构造函数纯标量赋值
 */
class LayoutResult
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

    /** @var LayoutResult[] 子节点结果，顺序 = 输入子节点顺序 */
    public readonly array $children;

    // ── 多阶段布局增强字段 ──

    /** 是否需要额外迭代（Grid/Table 收敛使用） */
    public readonly bool $needsAnotherPass;

    /** 内在最小宽度 */
    public readonly int $minContentWidth;
    /** 内在最大宽度 */
    public readonly int $maxContentWidth;
    /** 首选宽度 */
    public readonly int $preferredContentWidth;
    /** 内在最小高度 */
    public readonly int $minContentHeight;
    /** 内在最大高度 */
    public readonly int $maxContentHeight;
    /** 首选高度 */
    public readonly int $preferredContentHeight;

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
        bool $needsAnotherPass = false,
        int $minContentWidth = 0,
        int $maxContentWidth = 0,
        int $preferredContentWidth = 0,
        int $minContentHeight = 0,
        int $maxContentHeight = 0,
        int $preferredContentHeight = 0,
    ) {
        // Cast all int fields to ensure AOT php::Var→int conversion
        $this->x             = (int)$x;
        $this->y             = (int)$y;
        $this->w             = (int)$w;
        $this->h             = (int)$h;
        $this->visualW       = (int)($visualW > 0 ? $visualW : $w);
        $this->visualH       = (int)($visualH > 0 ? $visualH : $h);
        $this->layer         = (int)$layer;
        $this->contentWidth  = (int)$contentWidth;
        $this->contentHeight = (int)$contentHeight;
        $this->style         = $style;
        $this->children      = $children;
        $this->needsAnotherPass      = $needsAnotherPass;
        $this->minContentWidth       = (int)$minContentWidth;
        $this->maxContentWidth       = (int)$maxContentWidth;
        $this->preferredContentWidth = (int)$preferredContentWidth;
        $this->minContentHeight      = (int)$minContentHeight;
        $this->maxContentHeight      = (int)$maxContentHeight;
        $this->preferredContentHeight = (int)$preferredContentHeight;
    }

    /**
     * 从现有 RenderNode 构造 LayoutResult（洁净路径/快照）。
     */
    public static function fromNode(RenderNode $node): self
    {
        $children = [];
        foreach ($node->children as $ch) {
            $children[] = self::fromNode($ch);
        }
        return new self(
            x: $node->x,
            y: $node->y,
            w: $node->w,
            h: $node->h,
            visualW: $node->visualW,
            visualH: $node->visualH,
            layer: $node->layer,
            contentWidth: $node->contentWidth,
            contentHeight: $node->contentHeight,
            style: $node->computedStyle,
            children: $children,
        );
    }

    /**
     * 序列化为数组（用于测试快照断言）。
     * @return array<string, mixed>
     */
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
        $childArr = [];
        foreach ($this->children as $child) {
            $childArr[] = $child->toArray();
        }
        $arr['children'] = $childArr;
        return $arr;
    }

    /**
     * 对子 fragment 按主轴排序/偏移。
     * 用于 flex justify-content / grid placement 后处理。
     *
     * @param callable(LayoutResult): LayoutResult $fn
     * @return self
     */
    public function mapChildren(callable $fn): self
    {
        $newChildren = [];
        foreach ($this->children as $child) {
            $newChildren[] = $fn($child);
        }
        return new self(
            x: $this->x,
            y: $this->y,
            w: $this->w,
            h: $this->h,
            visualW: $this->visualW,
            visualH: $this->visualH,
            layer: $this->layer,
            contentWidth: $this->contentWidth,
            contentHeight: $this->contentHeight,
            style: $this->style,
            children: $newChildren,
        );
    }

    /**
     * 原子回写 RenderNode。
     * RenderNode.x/y/w/h 的唯一写入通道。
     * 逐字段比较，仅写入变化的字段。
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
}
