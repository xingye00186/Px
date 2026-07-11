<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;

/**
 * ConstraintSpaceBuilder — 约束空间构建器
 *
 * 提供 fluent 接口从 RenderNode/ComputedStyle 构建 ConstraintSpace。
 */
class ConstraintSpaceBuilder
{
    private int $containerWidth = 0;
    private int $containerHeight = 0;
    private int $parentContentX = 0;
    private int $parentContentY = 0;
    private int $contentWidth = 0;
    private int $contentHeight = 0;
    private ?int $percentageWidth = null;
    private ?int $percentageHeight = null;
    private int $paddingTop = 0;
    private int $paddingRight = 0;
    private int $paddingBottom = 0;
    private int $paddingLeft = 0;
    private int $borderTop = 0;
    private int $borderRight = 0;
    private int $borderBottom = 0;
    private int $borderLeft = 0;
    private bool $forceRelayoutChildren = false;
    private bool $isIntrinsicMeasurement = false;
    private int $bfcOffsetX = 0;
    private int $bfcOffsetY = 0;
    private string $spaceType = 'block';

    public function container(int $w, int $h): self
    {
        $this->containerWidth = $w;
        $this->containerHeight = $h;
        return $this;
    }

    public function content(int $w, int $h): self
    {
        $this->contentWidth = $w;
        $this->contentHeight = $h;
        return $this;
    }

    public function parentOrigin(int $x, int $y): self
    {
        $this->parentContentX = $x;
        $this->parentContentY = $y;
        return $this;
    }

    public function percentage(int $w, int $h): self
    {
        $this->percentageWidth = $w;
        $this->percentageHeight = $h;
        return $this;
    }

    public function indefinite(): self
    {
        $this->percentageWidth = null;
        $this->percentageHeight = null;
        return $this;
    }

    public function padding(int $t, int $r, int $b, int $l): self
    {
        $this->paddingTop = $t;
        $this->paddingRight = $r;
        $this->paddingBottom = $b;
        $this->paddingLeft = $l;
        return $this;
    }

    public function border(int $t, int $r, int $b, int $l): self
    {
        $this->borderTop = $t;
        $this->borderRight = $r;
        $this->borderBottom = $b;
        $this->borderLeft = $l;
        return $this;
    }

    public function forceRelayout(bool $force = true): self
    {
        $this->forceRelayoutChildren = $force;
        return $this;
    }

    public function intrinsic(): self
    {
        $this->isIntrinsicMeasurement = true;
        $this->percentageWidth = null;
        $this->percentageHeight = null;
        return $this;
    }

    public function spaceType(string $type): self
    {
        $this->spaceType = $type;
        return $this;
    }

    public function build(): ConstraintSpace
    {
        return new ConstraintSpace(
            $this->containerWidth,
            $this->containerHeight,
            $this->parentContentX,
            $this->parentContentY,
            $this->contentWidth,
            $this->contentHeight,
            $this->percentageWidth,
            $this->percentageHeight,
            $this->paddingTop,
            $this->paddingRight,
            $this->paddingBottom,
            $this->paddingLeft,
            $this->borderTop,
            $this->borderRight,
            $this->borderBottom,
            $this->borderLeft,
            $this->forceRelayoutChildren,
            $this->isIntrinsicMeasurement,
            $this->bfcOffsetX,
            $this->bfcOffsetY,
            $this->spaceType,
        );
    }

    /** 从 RenderNode + ComputedStyle 便捷构建 */
    public static function fromNode(
        RenderNode $node,
        ?ComputedStyle $style = null,
        bool $isIntrinsic = false,
    ): ConstraintSpace {
        $b = new self();

        $w = (int)($node->w ?: $style?->width?->toPx() ?: 0);
        $h = (int)($node->h ?: $style?->height?->toPx() ?: 0);

        $b->container($w, $h)
          ->content($w, $h)
          ->parentOrigin(0, 0);

        if ($style !== null) {
            $b->padding(
                (int)$style->padding->top->toPx(),
                (int)$style->padding->right->toPx(),
                (int)$style->padding->bottom->toPx(),
                (int)$style->padding->left->toPx(),
            )->border(
                (int)$style->borderTopWidth,
                (int)$style->borderRightWidth,
                (int)$style->borderBottomWidth,
                (int)$style->borderLeftWidth,
            );
        }

        if ($isIntrinsic) {
            $b->intrinsic();
        } else {
            // 设置百分比基准
            $pW = $style?->width?->isPercent() ? $w : null;
            $pH = $style?->height?->isPercent() ? $h : null;
            if ($pW !== null || $pH !== null) {
                $b->percentage($pW ?? -1, $pH ?? -1);
            }
        }

        return $b->build();
    }

    /** 从 LayoutConstraints 构建（迁移铺平路径） */
    public static function fromLegacyConstraints(
        LayoutConstraints $lc,
        ?int $percentageW = null,
        ?int $percentageH = null,
    ): ConstraintSpace {
        return new ConstraintSpace(
            $lc->containerWidth,
            $lc->containerHeight,
            $lc->parentContentX,
            $lc->parentContentY,
            $lc->contentWidth,
            $lc->contentHeight,
            $percentageW,
            $percentageH,
            $lc->paddingTop,
            $lc->paddingRight,
            $lc->paddingBottom,
            $lc->paddingLeft,
            $lc->borderTop,
            $lc->borderRight,
            $lc->borderBottom,
            $lc->borderLeft,
            $lc->forceRelayoutChildren,
            $lc->isIntrinsicMeasurement,
        );
    }
}
