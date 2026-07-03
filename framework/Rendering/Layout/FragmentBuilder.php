<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;

/**
 * FragmentBuilder — 布局计算过程中的可变构建器。
 * build() 后冻结为 LayoutFragment。
 */
class FragmentBuilder
{
    private bool $built = false;
    private int $x = 0;
    private int $y = 0;
    private int $w = 0;
    private int $h = 0;
    private int $visualW = 0;
    private int $visualH = 0;
    private int $layer = 0;
    private int $contentWidth = 0;
    private int $contentHeight = 0;
    private ?ComputedStyle $style = null;

    /** @var LayoutFragment[] */
    private array $children = [];

    public function setPosition(int $x, int $y): self
    {
        $this->x = $x;
        $this->y = $y;
        return $this;
    }

    public function setSize(int $w, int $h, ?ComputedStyle $style = null): self
    {
        $this->w = $w;
        $this->h = $h;
        if ($style !== null) {
            $this->visualW = (int)$style->visualWidth($w);
            $this->visualH = (int)$style->visualHeight($h);
        }
        return $this;
    }

    public function setVisualSize(int $visualW, int $visualH): self
    {
        $this->visualW = $visualW;
        $this->visualH = $visualH;
        return $this;
    }

    public function setLayer(int $layer): self
    {
        $this->layer = $layer;
        return $this;
    }

    public function setContentSize(int $cw, int $ch): self
    {
        $this->contentWidth = $cw;
        $this->contentHeight = $ch;
        return $this;
    }

    public function addChild(LayoutFragment $child): self
    {
        $this->children[] = $child;
        return $this;
    }

    /**
     * 替换全部子 Fragment（用于策略后处理完全重排 children）。
     */
    public function replaceChildren(array $children): self
    {
        $this->children = $children;
        return $this;
    }

    /**
     * 同步子节点坐标：将 RenderNode.children 的当前位置写回 Fragment，
     * 解决 auto-stack 被 applyTo(stale) 覆盖的架构问题。
     * 单阶段策略在算法运行后、build() 前调用此方法。
     */
    public function syncChildrenFromNode(RenderNode $node): self
    {
        $original = $this->children;
        $synced = [];
        foreach ($node->children as $i => $ch) {
            $orig = $original[$i] ?? null;
            $synced[] = new LayoutFragment(
                x: $ch->x,
                y: $ch->y,
                w: $ch->w,
                h: $ch->h,
                visualW: $ch->visualW,
                visualH: $ch->visualH,
                layer: $orig?->layer ?? 0,
                contentWidth: $orig?->contentWidth ?? 0,
                contentHeight: $orig?->contentHeight ?? 0,
                style: $ch->computedStyle,
                children: $orig?->children ?? [],
            );
        }
        $this->children = $synced;
        return $this;
    }

    /**
     * 当前子 Fragment 数量。
     */
    public function childCount(): int
    {
        return count($this->children);
    }

    /**
     * 获取当前子 Fragment 列表。
     * @return LayoutFragment[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function setStyle(?ComputedStyle $style): self
    {
        $this->style = $style;
        return $this;
    }

    public function build(?ComputedStyle $style = null): LayoutFragment
    {
        if ($this->built) {
            return new LayoutFragment(
                x: 0, y: 0, w: 0, h: 0,
                style: $style ?? $this->style,
            );
        }
        $this->built = true;
        return new LayoutFragment(
            x: $this->x,
            y: $this->y,
            w: $this->w,
            h: $this->h,
            visualW: $this->visualW,
            visualH: $this->visualH,
            layer: $this->layer,
            contentWidth: $this->contentWidth,
            contentHeight: $this->contentHeight,
            style: $style ?? $this->style,
            children: $this->children,
        );
    }
}
