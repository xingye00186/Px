<?php

namespace Px\Layout;

use native_types;

/**
 * ConstraintSpaceBuilder — 布局约束空间的 Fluent Builder（对标 Blink NGConstraintSpaceBuilder）
 *
 * 解决 §12.3 审计缺口：ConstraintSpace 的 21 位置参数构造函数（forChild 又是另一套参数顺序）
 * 导致新增字段必须改所有调用点。Builder 允许分步构造，字段独立可选。
 *
 * 用法（对标 Blink 用法）：
 *   $space = ConstraintSpaceBuilder::create()
 *       ->setContainerSize($w, $h)
 *       ->setContentSize($cw, $ch)
 *       ->setParentContentOrigin($px, $py)
 *       ->setPercentageBase($pw, $ph)
 *       ->setDeterminedPercentageBase($dpw, $dph)
 *       ->setPadding($pt, $pr, $pb, $pl)
 *       ->setBorder($bt, $br, $bb, $bl)
 *       ->setSpaceType('flex')
 *       ->setIntrinsicMeasurement(true)
 *       ->build();
 *
 * 注意：与现有 `new ConstraintSpace(...)` 位置参数构造函数完全并行——不迫使既有代码迁移。
 * 建议**新代码使用 Builder**，既有热点路径按需渐进迁移。
 */
class ConstraintSpaceBuilder
{
    // 容器尺寸
    private int $containerWidth = 0;
    private int $containerHeight = 0;

    // 内容尺寸（0 表示继承 containerWidth/Height）
    private int $contentWidth = 0;
    private int $contentHeight = 0;

    // 父 content-box 左上角坐标（绝对）
    private int $parentContentX = 0;
    private int $parentContentY = 0;

    // 百分比基准
    private ?int $percentageWidth = null;
    private ?int $percentageHeight = null;

    // 父 flex/grid 已确定的百分比基准
    private ?int $determinedPercentageWidth = null;
    private ?int $determinedPercentageHeight = null;

    // 容器 padding
    private int $paddingTop = 0;
    private int $paddingRight = 0;
    private int $paddingBottom = 0;
    private int $paddingLeft = 0;

    // 容器 border
    private int $borderTop = 0;
    private int $borderRight = 0;
    private int $borderBottom = 0;
    private int $borderLeft = 0;

    // 行为标志
    private bool $forceRelayoutChildren = false;
    private bool $isIntrinsicMeasurement = false;
    private string $spaceType = 'block';

    /** 工厂：创建全新 Builder */
    public static function create(): ConstraintSpaceBuilder
    {
        return new ConstraintSpaceBuilder();
    }

    /** 从已有 ConstraintSpace 复制字段（用于 `forChild` 场景） */
    public static function from(ConstraintSpace $parent): ConstraintSpaceBuilder
    {
        $b = new ConstraintSpaceBuilder();
        $b->containerWidth               = $parent->containerWidth;
        $b->containerHeight              = $parent->containerHeight;
        $b->contentWidth                 = $parent->contentWidth;
        $b->contentHeight                = $parent->contentHeight;
        $b->parentContentX               = $parent->parentContentX;
        $b->parentContentY               = $parent->parentContentY;
        $b->percentageWidth              = $parent->percentageWidth;
        $b->percentageHeight             = $parent->percentageHeight;
        $b->determinedPercentageWidth    = $parent->determinedPercentageWidth;
        $b->determinedPercentageHeight   = $parent->determinedPercentageHeight;
        $b->paddingTop                   = $parent->paddingTop;
        $b->paddingRight                 = $parent->paddingRight;
        $b->paddingBottom                = $parent->paddingBottom;
        $b->paddingLeft                  = $parent->paddingLeft;
        $b->borderTop                    = $parent->borderTop;
        $b->borderRight                  = $parent->borderRight;
        $b->borderBottom                 = $parent->borderBottom;
        $b->borderLeft                   = $parent->borderLeft;
        $b->forceRelayoutChildren        = $parent->forceRelayoutChildren;
        $b->isIntrinsicMeasurement       = $parent->isIntrinsicMeasurement;
        $b->spaceType                    = $parent->spaceType;
        return $b;
    }

    public function setContainerSize(int $w, int $h): ConstraintSpaceBuilder
    {
        $this->containerWidth = $w;
        $this->containerHeight = $h;
        return $this;
    }

    public function setContentSize(int $w, int $h): ConstraintSpaceBuilder
    {
        $this->contentWidth = $w;
        $this->contentHeight = $h;
        return $this;
    }

    public function setParentContentOrigin(int $x, int $y): ConstraintSpaceBuilder
    {
        $this->parentContentX = $x;
        $this->parentContentY = $y;
        return $this;
    }

    public function setPercentageBase(?int $w, ?int $h): ConstraintSpaceBuilder
    {
        $this->percentageWidth = $w;
        $this->percentageHeight = $h;
        return $this;
    }

    public function setDeterminedPercentageBase(?int $w, ?int $h): ConstraintSpaceBuilder
    {
        $this->determinedPercentageWidth = $w;
        $this->determinedPercentageHeight = $h;
        return $this;
    }

    public function setPadding(int $top, int $right, int $bottom, int $left): ConstraintSpaceBuilder
    {
        $this->paddingTop = $top;
        $this->paddingRight = $right;
        $this->paddingBottom = $bottom;
        $this->paddingLeft = $left;
        return $this;
    }

    public function setBorder(int $top, int $right, int $bottom, int $left): ConstraintSpaceBuilder
    {
        $this->borderTop = $top;
        $this->borderRight = $right;
        $this->borderBottom = $bottom;
        $this->borderLeft = $left;
        return $this;
    }

    public function setForceRelayoutChildren(bool $v): ConstraintSpaceBuilder
    {
        $this->forceRelayoutChildren = $v;
        return $this;
    }

    public function setIntrinsicMeasurement(bool $v): ConstraintSpaceBuilder
    {
        $this->isIntrinsicMeasurement = $v;
        return $this;
    }

    public function setSpaceType(string $type): ConstraintSpaceBuilder
    {
        $this->spaceType = $type;
        return $this;
    }

    /** 构建最终 ConstraintSpace */
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
            $this->spaceType,
            $this->determinedPercentageWidth,
            $this->determinedPercentageHeight,
        );
    }
}
