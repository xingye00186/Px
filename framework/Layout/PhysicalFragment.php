<?php

namespace Px\Layout;

use native_types;

use Px\Css\ComputedStyle;
use Px\Render\RenderNode;

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

    /** 自包含元素元数据（paint 不依赖 sourceNode 读取） */
    public readonly string $type;
    public readonly mixed $content;
    public readonly array $dataset;
    public readonly array $pseudoStyles;

    /** 容器可用宽度（供 span/inline 文本换行使用） */
    public readonly int $availableWidth;

    /**
     * 文本像素宽度（layout 阶段预计算，paint 阶段零测量）
     * 0 = 未设置/非文本节点，paint 应 fallback 到 measureTextWidth
     */
    public readonly int $textWidth;

    /** getter 方法 — AOT 跨类 readonly 访问保护 */
    public function getX(): int { return $this->x; }
    public function getY(): int { return $this->y; }
    public function getW(): int { return $this->w; }
    public function getH(): int { return $this->h; }
    public function getVisualW(): int { return $this->visualW; }
    public function getVisualH(): int { return $this->visualH; }
    public function getLayer(): int { return $this->layer; }
    public function getContentWidth(): int { return $this->contentWidth; }
    public function getContentHeight(): int { return $this->contentHeight; }
    public function getScrollTop(): int { return $this->scrollTop; }
    public function getScrollLeft(): int { return $this->scrollLeft; }
    public function getIsScrollContainer(): bool { return $this->isScrollContainer; }
    public function getAvailableWidth(): int { return $this->availableWidth; }
    public function getTextWidth(): int { return $this->textWidth; }

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
        string $type = '',
        mixed $content = null,
        array $dataset = [],
        array $pseudoStyles = [],
        int $availableWidth = 0,
        int $textWidth = 0,
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
        $this->type              = $type;
        $this->content           = $content;
        $this->dataset           = $dataset;
        $this->pseudoStyles      = $pseudoStyles;
        $this->availableWidth    = (int)$availableWidth;
        $this->textWidth          = (int)$textWidth;
    }

    /** 从 LayoutResult 构造（适配器用） */
        /** 导出几何数组（AOT 下 toArray 方法名触发编译器 bug，改用 fragToArray） */
    public function fragToArray(): array
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
            'type' => $this->type,
            'content' => $this->content,
        ];
        if ($this->isScrollContainer) {
            $arr['scrollTop'] = $this->scrollTop;
            $arr['scrollLeft'] = $this->scrollLeft;
        }
        $childArr = [];
        foreach ($this->children as $child) {
            $childArr[] = $child->fragToArray();
        }
        if (!empty($childArr)) {
            $arr['children'] = $childArr;
        }
        return $arr;
    }
}
