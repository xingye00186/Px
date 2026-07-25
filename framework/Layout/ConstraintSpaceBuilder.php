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

    // 百分比基准（-1 = null/未设置，AOT 兼容：避免 ?int 编译为 php::Variant）
    private int $percentageWidth = -1;
    private int $percentageHeight = -1;

    // 父 flex/grid 已确定的百分比基准
    private int $determinedPercentageWidth = -1;
    private int $determinedPercentageHeight = -1;

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
    private bool $isFixedBlockSize = false;

    /** 工厂：创建全新 Builder */
    public static function create(): ConstraintSpaceBuilder
    {
        return new ConstraintSpaceBuilder();
    }

    /** 从已有 ConstraintSpace 复制字段（用于 `forChild` 场景） */
    public static function from(ConstraintSpace $parent): ConstraintSpaceBuilder
    {
        $b = new ConstraintSpaceBuilder();
        // AOT 兼容：跨对象 readonly 属性访问必须通过 getter（避免 Variant 转换错误）
        $b->containerWidth               = (int)$parent->getContainerWidth();
        $b->containerHeight              = (int)$parent->getContainerHeight();
        $b->contentWidth                 = (int)$parent->getContentWidth();
        $b->contentHeight                = (int)$parent->getContentHeight();
        $b->parentContentX               = (int)$parent->getParentContentX();
        $b->parentContentY               = (int)$parent->getParentContentY();
        $b->percentageWidth              = $parent->getPercentageWidth() !== null ? (int)$parent->getPercentageWidth() : -1;
        $b->percentageHeight             = $parent->getPercentageHeight() !== null ? (int)$parent->getPercentageHeight() : -1;
        $b->determinedPercentageWidth    = $parent->getDeterminedPercentageWidth() !== null ? (int)$parent->getDeterminedPercentageWidth() : -1;
        $b->determinedPercentageHeight   = $parent->getDeterminedPercentageHeight() !== null ? (int)$parent->getDeterminedPercentageHeight() : -1;
        $b->paddingTop                   = (int)$parent->getPaddingTop();
        $b->paddingRight                 = (int)$parent->getPaddingRight();
        $b->paddingBottom                = (int)$parent->getPaddingBottom();
        $b->paddingLeft                  = (int)$parent->getPaddingLeft();
        $b->borderTop                    = (int)$parent->borderTop;
        $b->borderRight                  = (int)$parent->borderRight;
        $b->borderBottom                 = (int)$parent->borderBottom;
        $b->borderLeft                   = (int)$parent->borderLeft;
        $b->forceRelayoutChildren        = (bool)$parent->getForceRelayoutChildren();
        $b->isIntrinsicMeasurement       = (bool)$parent->getIsIntrinsicMeasurement();
        $b->spaceType                    = (string)$parent->getSpaceType();
        $b->isFixedBlockSize             = (bool)$parent->getIsFixedBlockSize();
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
        $this->percentageWidth = $w !== null ? (int)$w : -1;
        $this->percentageHeight = $h !== null ? (int)$h : -1;
        return $this;
    }

    public function setDeterminedPercentageBase(?int $w, ?int $h): ConstraintSpaceBuilder
    {
        $this->determinedPercentageWidth = $w !== null ? (int)$w : -1;
        $this->determinedPercentageHeight = $h !== null ? (int)$h : -1;
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

    /** 块轴尺寸强制固定（对标 Blink is_fixed_block_size） */
    public function setFixedBlockSize(bool $v): ConstraintSpaceBuilder
    {
        $this->isFixedBlockSize = $v;
        return $this;
    }

    /** 构建最终 ConstraintSpace */
    public function build(): ConstraintSpace
    {
        // AOT 兼容：sentinel -1 转回 null（ConstraintSpace 构造器接受 ?int）
        $pw = $this->percentageWidth >= 0 ? $this->percentageWidth : null;
        $ph = $this->percentageHeight >= 0 ? $this->percentageHeight : null;
        $dpw = $this->determinedPercentageWidth >= 0 ? $this->determinedPercentageWidth : null;
        $dph = $this->determinedPercentageHeight >= 0 ? $this->determinedPercentageHeight : null;
        return new ConstraintSpace(
            $this->containerWidth,
            $this->containerHeight,
            $this->parentContentX,
            $this->parentContentY,
            $this->contentWidth,
            $this->contentHeight,
            $pw,
            $ph,
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
            $dpw,
            $dph,
            $this->isFixedBlockSize,
        );
    }
}
