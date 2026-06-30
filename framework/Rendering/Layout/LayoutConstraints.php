<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;

/**
 * LayoutConstraints — 布局约束（输入）
 *
 * Phase 3 新增，已替代旧的 LayoutContext。
 * 合并了后者的 $parentX/$parentY 字段。
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

    /** 强制重算所有子节点（父容器尺寸变化时 Flex/Grid 必须置 true） */
    public readonly bool $forceRelayoutChildren;

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
        bool $forceRelayoutChildren = false,
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
        $this->forceRelayoutChildren = $forceRelayoutChildren;
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
        bool $force = false,
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
            forceRelayoutChildren: $force,
        );
    }

    /**
     * 从包含块创建约束，支持 CSS Containment 隔离。
     * CSS Containment L1 §3.1: contain:layout / contain:size 时子节点使用冻结约束。
     */
    public static function fromContainingBlock(
        RenderNode       $parent,
        ?ComputedStyle   $parentStyle,
    ): self {
        $contain = $parentStyle?->getRaw('contain') ?? '';
        $isIsolated = (str_contains($contain, 'layout') || str_contains($contain, 'size'));

        // 如果父容器有 contain，子节点使用"冻结约束"
        if ($isIsolated) {
            return new self(
                containerWidth: $parent->w,
                containerHeight: $parent->h,
                parentContentX: $parent->x,
                parentContentY: $parent->y,
                contentWidth: $parent->w,
                contentHeight: $parent->h,
                paddingTop: $parentStyle?->padding?->top->toPx() ?? 0,
                paddingRight: $parentStyle?->padding?->right->toPx() ?? 0,
                paddingBottom: $parentStyle?->padding?->bottom->toPx() ?? 0,
                paddingLeft: $parentStyle?->padding?->left->toPx() ?? 0,
                borderTop: $parentStyle?->borderTopWidth ?? 0,
                borderRight: $parentStyle?->borderRightWidth ?? 0,
                borderBottom: $parentStyle?->borderBottomWidth ?? 0,
                borderLeft: $parentStyle?->borderLeftWidth ?? 0,
                forceRelayoutChildren: false,
            );
        }

        // 正常情况：使用父容器 content box
        $padL = $parentStyle?->padding?->left->toPx() ?? 0;
        $padR = $parentStyle?->padding?->right->toPx() ?? 0;
        $padT = $parentStyle?->padding?->top->toPx() ?? 0;
        $padB = $parentStyle?->padding?->bottom->toPx() ?? 0;
        $bL = $parentStyle?->borderLeftWidth ?? 0;
        $bR = $parentStyle?->borderRightWidth ?? 0;
        $bT = $parentStyle?->borderTopWidth ?? 0;
        $bB = $parentStyle?->borderBottomWidth ?? 0;

        return new self(
            containerWidth: $parent->w - $padL - $padR - $bL - $bR,
            containerHeight: $parent->h - $padT - $padB - $bT - $bB,
            parentContentX: $parent->x + $padL + $bL,
            parentContentY: $parent->y + $padT + $bT,
            contentWidth: $parent->w - $padL - $padR - $bL - $bR,
            contentHeight: $parent->h - $padT - $padB - $bT - $bB,
            paddingTop: $padT,
            paddingRight: $padR,
            paddingBottom: $padB,
            paddingLeft: $padL,
            borderTop: $bT,
            borderRight: $bR,
            borderBottom: $bB,
            borderLeft: $bL,
            forceRelayoutChildren: false,
        );
    }

    /** 使用新的 contentWidth 创建拷贝 */
    public function withContentWidth(int $w): self
    {
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
