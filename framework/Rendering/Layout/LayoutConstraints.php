<?php

namespace Px\Rendering\Layout;

use native_types;

/**
 * LayoutConstraints — 布局约束（输入）
 *
 * Phase 3 新增，替代 LayoutContext。
 * 合并后者 $parentX/$parentY 后删除 LayoutContext。
 */
class LayoutConstraints
{
    /** 容器 content box 尺寸 */
    public readonly int $containerWidth;
    public readonly int $containerHeight;

    /** 容器 content box 左上角坐标（相对于根） */
    public readonly int $parentContentX;
    public readonly int $parentContentY;

    /** 容器 content box 尺寸 */
    public readonly int $contentWidth;
    public readonly int $contentHeight;

    /** 容器 padding */
    public readonly int $paddingTop;
    public readonly int $paddingRight;
    public readonly int $paddingBottom;
    public readonly int $paddingLeft;

    /** 容器 border */
    public readonly int $borderTop;
    public readonly int $borderRight;
    public readonly int $borderBottom;
    public readonly int $borderLeft;

    public function __construct(
        int $containerWidth = 0,
        int $containerHeight = 0,
        int $parentContentX = 0,
        int $parentContentY = 0,
        int $contentWidth = 0,
        int $contentHeight = 0,
        int $paddingTop = 0,
        int $paddingRight = 0,
        int $paddingBottom = 0,
        int $paddingLeft = 0,
        int $borderTop = 0,
        int $borderRight = 0,
        int $borderBottom = 0,
        int $borderLeft = 0,
    ) {
        $this->containerWidth  = $containerWidth;
        $this->containerHeight = $containerHeight;
        $this->parentContentX  = $parentContentX;
        $this->parentContentY  = $parentContentY;
        $this->contentWidth    = $contentWidth > 0 ? $contentWidth : $containerWidth;
        $this->contentHeight   = $contentHeight > 0 ? $contentHeight : $containerHeight;
        $this->paddingTop      = $paddingTop;
        $this->paddingRight    = $paddingRight;
        $this->paddingBottom   = $paddingBottom;
        $this->paddingLeft     = $paddingLeft;
        $this->borderTop       = $borderTop;
        $this->borderRight     = $borderRight;
        $this->borderBottom    = $borderBottom;
        $this->borderLeft      = $borderLeft;
    }

    /**
     * 从 RenderNode 和 ComputedStyle 创建子节点约束。
     * 子节点的容器 = 当前节点的 content box。
     */
    public static function forChild(
        int $parentContentX,
        int $parentContentY,
        int $contentWidth,
        int $contentHeight,
        int $paddingTop = 0,
        int $paddingRight = 0,
        int $paddingBottom = 0,
        int $paddingLeft = 0,
        int $borderTop = 0,
        int $borderRight = 0,
        int $borderBottom = 0,
        int $borderLeft = 0,
    ): self {
        return new self(
            containerWidth: $contentWidth,
            containerHeight: $contentHeight,
            parentContentX: $parentContentX,
            parentContentY: $parentContentY,
            contentWidth: $contentWidth,
            contentHeight: $contentHeight,
            paddingTop: $paddingTop,
            paddingRight: $paddingRight,
            paddingBottom: $paddingBottom,
            paddingLeft: $paddingLeft,
            borderTop: $borderTop,
            borderRight: $borderRight,
            borderBottom: $borderBottom,
            borderLeft: $borderLeft,
        );
    }

    /** 使用新的 contentWidth 创建拷贝 */
    public function withContentWidth(int $w): self
    {
        $copy = clone $this;
        // Cannot modify readonly directly, recreate
        return new self(
            containerWidth: $this->containerWidth,
            containerHeight: $this->containerHeight,
            parentContentX: $this->parentContentX,
            parentContentY: $this->parentContentY,
            contentWidth: $w,
            contentHeight: $this->contentHeight,
            paddingTop: $this->paddingTop,
            paddingRight: $this->paddingRight,
            paddingBottom: $this->paddingBottom,
            paddingLeft: $this->paddingLeft,
            borderTop: $this->borderTop,
            borderRight: $this->borderRight,
            borderBottom: $this->borderBottom,
            borderLeft: $this->borderLeft,
        );
    }
}
