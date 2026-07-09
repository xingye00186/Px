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

    /** 从旧的 LayoutConstraints + ComputedStyle 构造 */
    public static function fromLegacy(
        LayoutConstraints $legacy,
        ?ComputedStyle $style = null,
        string $spaceType = 'block',
    ): self {
        $percW = null;
        $percH = null;
        if ($style !== null) {
            // 百分比基准 = 父容器 contentWidth/Height（非内在测量时）
            if (!$legacy->isIntrinsicMeasurement) {
                if ($style->width?->isPercent()) {
                    $percW = $legacy->contentWidth;
                }
                if ($style->height?->isPercent()) {
                    $percH = $legacy->contentHeight;
                }
            }
        }
        return new self(
            containerWidth:        $legacy->containerWidth,
            containerHeight:       $legacy->containerHeight,
            parentContentX:        $legacy->parentContentX,
            parentContentY:        $legacy->parentContentY,
            contentWidth:          $legacy->contentWidth,
            contentHeight:         $legacy->contentHeight,
            percentageWidth:       $percW,
            percentageHeight:      $percH,
            paddingTop:            $legacy->paddingTop,
            paddingRight:          $legacy->paddingRight,
            paddingBottom:         $legacy->paddingBottom,
            paddingLeft:           $legacy->paddingLeft,
            borderTop:             $legacy->borderTop,
            borderRight:           $legacy->borderRight,
            borderBottom:          $legacy->borderBottom,
            borderLeft:            $legacy->borderLeft,
            forceRelayoutChildren: $legacy->forceRelayoutChildren,
            isIntrinsicMeasurement: $legacy->isIntrinsicMeasurement,
            spaceType:             $spaceType,
        );
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
            containerWidth:        $contentWidth,
            containerHeight:       $contentHeight,
            parentContentX:        $parentContentX,
            parentContentY:        $parentContentY,
            contentWidth:          $contentWidth,
            contentHeight:         $contentHeight,
            percentageWidth:       $percentageWidth,
            percentageHeight:      $percentageHeight,
            paddingTop:            $paddingTop,
            paddingRight:          $paddingRight,
            paddingBottom:         $paddingBottom,
            paddingLeft:           $paddingLeft,
            borderTop:             $borderTop,
            borderRight:           $borderRight,
            borderBottom:          $borderBottom,
            borderLeft:            $borderLeft,
            forceRelayoutChildren: $force,
            spaceType:             $spaceType,
        );
    }

    /** 使用新的 contentWidth 创建拷贝 */
    public function withContentWidth(int $w): self
    {
        return new self(
            containerWidth:        $this->containerWidth,
            containerHeight:       $this->containerHeight,
            parentContentX:        $this->parentContentX,
            parentContentY:        $this->parentContentY,
            contentWidth:          $w,
            contentHeight:         $this->contentHeight,
            percentageWidth:       $this->percentageWidth,
            percentageHeight:      $this->percentageHeight,
            paddingTop:            $this->paddingTop,
            paddingRight:          $this->paddingRight,
            paddingBottom:         $this->paddingBottom,
            paddingLeft:           $this->paddingLeft,
            borderTop:             $this->borderTop,
            borderRight:           $this->borderRight,
            borderBottom:          $this->borderBottom,
            borderLeft:            $this->borderLeft,
            forceRelayoutChildren: $this->forceRelayoutChildren,
            isIntrinsicMeasurement: $this->isIntrinsicMeasurement,
            bfcOffsetX:            $this->bfcOffsetX,
            bfcOffsetY:            $this->bfcOffsetY,
            spaceType:             $this->spaceType,
        );
    }

    /** 转换为 LayoutConstraints（向后兼容） */
    public function toLegacy(): LayoutConstraints
    {
        return new LayoutConstraints(
            containerWidth:  $this->containerWidth,
            containerHeight: $this->containerHeight,
            parentContentX:  $this->parentContentX,
            parentContentY:  $this->parentContentY,
            contentWidth:    $this->contentWidth,
            contentHeight:   $this->contentHeight,
            paddingTop:      $this->paddingTop,
            paddingRight:    $this->paddingRight,
            paddingBottom:   $this->paddingBottom,
            paddingLeft:     $this->paddingLeft,
            borderTop:       $this->borderTop,
            borderRight:     $this->borderRight,
            borderBottom:    $this->borderBottom,
            borderLeft:      $this->borderLeft,
            forceRelayoutChildren: $this->forceRelayoutChildren,
            isIntrinsicMeasurement: $this->isIntrinsicMeasurement,
        );
    }
}
