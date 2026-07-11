<?php

namespace Px\Rendering\Layout;

use native_types;

/**
 * ConstraintSpace — 布局约束空间（对标 Blink ConstraintSpace）
 *
 * 替代 LayoutConstraints，新增 percentageWidth/Height 用于
 * 修复 P3（百分比基准逃逸到祖父）。
 *
 * percentageWidth/Height = null 表示 Indefinite（内在尺寸测量模式），
 * 子项 width:50% 在此模式下应使用 intrinsic 撑开。
 */
class ConstraintSpace
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

    /** 百分比基准（null = Indefinite，解决 P3） */
    public readonly ?int $percentageWidth;
    public readonly ?int $percentageHeight;

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

    /** 强制重算所有子节点 */
    public readonly bool $forceRelayoutChildren;

    /** 是否为内在尺寸测量模式 */
    public readonly bool $isIntrinsicMeasurement;

    /** BFC 偏移 */
    public readonly int $bfcOffsetX;
    public readonly int $bfcOffsetY;

    /** 空间类型（block/flex/grid/inline） */
    public readonly string $spaceType;

    /** getter 方法 — AOT 跨类 readonly 访问保护 */
    public function getContainerWidth(): int { return $this->containerWidth; }
    public function getContainerHeight(): int { return $this->containerHeight; }
    public function getParentContentX(): int { return $this->parentContentX; }
    public function getParentContentY(): int { return $this->parentContentY; }
    public function getContentWidth(): int { return $this->contentWidth; }
    public function getContentHeight(): int { return $this->contentHeight; }
    public function getPercentageWidth(): ?int { return $this->percentageWidth; }
    public function getPercentageHeight(): ?int { return $this->percentageHeight; }
    public function getPaddingTop(): int { return $this->paddingTop; }
    public function getPaddingRight(): int { return $this->paddingRight; }
    public function getPaddingBottom(): int { return $this->paddingBottom; }
    public function getPaddingLeft(): int { return $this->paddingLeft; }
    public function getBfcOffsetX(): int { return $this->bfcOffsetX; }
    public function getBfcOffsetY(): int { return $this->bfcOffsetY; }
    public function getSpaceType(): string { return $this->spaceType; }
    public function getForceRelayoutChildren(): bool { return $this->forceRelayoutChildren; }
    public function getIsIntrinsicMeasurement(): bool { return $this->isIntrinsicMeasurement; }

    public function __construct(
        int $containerWidth = 0,
        int $containerHeight = 0,
        int $parentContentX = 0,
        int $parentContentY = 0,
        int $contentWidth = 0,
        int $contentHeight = 0,
        ?int $percentageWidth = null,
        ?int $percentageHeight = null,
        int $paddingTop = 0,
        int $paddingRight = 0,
        int $paddingBottom = 0,
        int $paddingLeft = 0,
        int $borderTop = 0,
        int $borderRight = 0,
        int $borderBottom = 0,
        int $borderLeft = 0,
        bool $forceRelayoutChildren = false,
        bool $isIntrinsicMeasurement = false,
        int $bfcOffsetX = 0,
        int $bfcOffsetY = 0,
        string $spaceType = 'block',
    ) {
        $this->containerWidth        = $containerWidth;
        $this->containerHeight       = $containerHeight;
        $this->parentContentX        = $parentContentX;
        $this->parentContentY        = $parentContentY;
        $this->contentWidth          = $contentWidth > 0 ? $contentWidth : $containerWidth;
        $this->contentHeight         = $contentHeight > 0 ? $contentHeight : $containerHeight;
        $this->percentageWidth       = $percentageWidth;
        $this->percentageHeight      = $percentageHeight;
        $this->paddingTop            = $paddingTop;
        $this->paddingRight          = $paddingRight;
        $this->paddingBottom         = $paddingBottom;
        $this->paddingLeft           = $paddingLeft;
        $this->borderTop             = $borderTop;
        $this->borderRight           = $borderRight;
        $this->borderBottom          = $borderBottom;
        $this->borderLeft            = $borderLeft;
        $this->forceRelayoutChildren = $forceRelayoutChildren;
        $this->isIntrinsicMeasurement = $isIntrinsicMeasurement;
        $this->bfcOffsetX            = $bfcOffsetX;
        $this->bfcOffsetY            = $bfcOffsetY;
        $this->spaceType             = $spaceType;
    }



    /** 从 RenderNode 和 ComputedStyle 创建子节点约束 */
    public static function forChild(
        int $parentContentX,
        int $parentContentY,
        int $contentWidth,
        int $contentHeight,
        ?int $percentageWidth = null,
        ?int $percentageHeight = null,
        int $paddingTop = 0,
        int $paddingRight = 0,
        int $paddingBottom = 0,
        int $paddingLeft = 0,
        int $borderTop = 0,
        int $borderRight = 0,
        int $borderBottom = 0,
        int $borderLeft = 0,
        bool $force = false,
        string $spaceType = 'block',
    ): self {
        return new self(
            $contentWidth,
            $contentHeight,
            $parentContentX,
            $parentContentY,
            $contentWidth,
            $contentHeight,
            $percentageWidth,
            $percentageHeight,
            $paddingTop,
            $paddingRight,
            $paddingBottom,
            $paddingLeft,
            $borderTop,
            $borderRight,
            $borderBottom,
            $borderLeft,
            $force,
            false,
            $spaceType,
        );
    }




}
