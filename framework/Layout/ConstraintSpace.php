<?php

namespace Px\Layout;
use Px\Render\RenderNode;
use Px\Css\ComputedStyle;

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

    /** 父 flex/grid 分配后的确定基准（用于子项百分比在 flex item 中正确解析） */
    public readonly ?int $determinedPercentageWidth;
    public readonly ?int $determinedPercentageHeight;

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

    // 注：Blink NGConstraintSpace 有 bfc_offset 字段跟踪 BFC 根偏移，
    // Px 从未真正启用该机制（forChild 始终传 0），故不引入死字段。
    // 所有算法输出的 Fragment.x/y 已包含最终坐标，无需 bfcOffset 累加。

    /** 空间类型（block/flex/grid/inline） */
    public readonly string $spaceType;

    /**
     * 块轴尺寸被父强制固定（对标 Blink ConstraintSpace::is_fixed_block_size）。
     * 场景：grid align-items:stretch 拉伸的 item、flex align-items:stretch 交叉轴。
     * 为 true 时，子算法应将 contentHeight 视为 definite 块轴尺寸（即使 style height 为 auto）。
     */
    public readonly bool $isFixedBlockSize;

    /**
     * 元素在此约束下建立新的格式化上下文（对标 Blink ConstraintSpace::is_new_formatting_context）。
     * 场景：flex item、grid item（CSS Flexbox §4 / Grid §6.1：item 建立独立格式化上下文）。
     * 为 true 时，子算法禁止 margin 穿透（CSS 2.2 §8.3.1：新 BFC 阻断父-首子 margin 折叠）。
     */
    public readonly bool $isFormattingContextRoot;

    /** getter 方法 — AOT 跨类 readonly 访问保护 */
    public function getContainerWidth(): int { return $this->containerWidth; }
    public function getContainerHeight(): int { return $this->containerHeight; }
    public function getParentContentX(): int { return $this->parentContentX; }
    public function getParentContentY(): int { return $this->parentContentY; }
    public function getContentWidth(): int { return $this->contentWidth; }
    public function getContentHeight(): int { return $this->contentHeight; }

    /**
     * E4: 几何动画尺寸覆写——返回一个新 ConstraintSpace，
     * contentWidth/Height 被动画值替换，其余不变。
     */
    public function withOverrideSize(?int $w, ?int $h): self
    {
        return new self(
            $w ?? $this->containerWidth,
            $h ?? $this->containerHeight,
            $this->parentContentX,
            $this->parentContentY,
            $w ?? $this->contentWidth,
            $h ?? $this->contentHeight,
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
            $this->isFixedBlockSize,
            $this->isFormattingContextRoot,
        );
    }
    public function getPercentageWidth(): ?int { return $this->percentageWidth; }
    public function getPercentageHeight(): ?int { return $this->percentageHeight; }
    public function getDeterminedPercentageWidth(): ?int { return $this->determinedPercentageWidth; }
    public function getDeterminedPercentageHeight(): ?int { return $this->determinedPercentageHeight; }
    public function getPaddingTop(): int { return $this->paddingTop; }
    public function getPaddingRight(): int { return $this->paddingRight; }
    public function getPaddingBottom(): int { return $this->paddingBottom; }
    public function getPaddingLeft(): int { return $this->paddingLeft; }
    public function getSpaceType(): string { return $this->spaceType; }
    public function getForceRelayoutChildren(): bool { return $this->forceRelayoutChildren; }
    public function getIsIntrinsicMeasurement(): bool { return $this->isIntrinsicMeasurement; }
    public function getIsFixedBlockSize(): bool { return $this->isFixedBlockSize; }
    public function getIsFormattingContextRoot(): bool { return $this->isFormattingContextRoot; }

    /**
     * 快速字段比较（替代字符串签名，避免 O(N) 序列化开销）。
     * 仅比较影响布局输出的关键几何属性。
     */
    public function equals(ConstraintSpace $other): bool
    {
        return $this->contentWidth === $other->contentWidth
            && $this->contentHeight === $other->contentHeight
            && $this->percentageWidth === $other->percentageWidth
            && $this->percentageHeight === $other->percentageHeight
            && $this->determinedPercentageWidth === $other->determinedPercentageWidth
            && $this->determinedPercentageHeight === $other->determinedPercentageHeight
            && $this->isIntrinsicMeasurement === $other->isIntrinsicMeasurement
            && $this->isFixedBlockSize === $other->isFixedBlockSize
            && $this->isFormattingContextRoot === $other->isFormattingContextRoot
            && $this->spaceType === $other->spaceType
            && $this->paddingTop === $other->paddingTop
            && $this->paddingRight === $other->paddingRight
            && $this->paddingBottom === $other->paddingBottom
            && $this->paddingLeft === $other->paddingLeft
            && $this->borderTop === $other->borderTop
            && $this->borderRight === $other->borderRight
            && $this->borderBottom === $other->borderBottom
            && $this->borderLeft === $other->borderLeft
            && $this->containerWidth === $other->containerWidth
            && $this->containerHeight === $other->containerHeight
        ;
    }

    /**
     * 布局等价比较（对标 Blink ConstraintSpace 核心字段）。
     *
     * 排除不影响布局结构结果的字段：
     *   - parentContentX/Y：绝对坐标，不影响布局计算
     *   - forceRelayoutChildren：外部控制标志，非约束本身
     *
     * 当 layoutEquals() 为 true 时，Fragment 缓存可安全命中。
     */
    public function layoutEquals(ConstraintSpace $other): bool
    {
        return $this->contentWidth === $other->contentWidth
            && $this->contentHeight === $other->contentHeight
            && $this->percentageWidth === $other->percentageWidth
            && $this->percentageHeight === $other->percentageHeight
            && $this->determinedPercentageWidth === $other->determinedPercentageWidth
            && $this->determinedPercentageHeight === $other->determinedPercentageHeight
            && $this->isIntrinsicMeasurement === $other->isIntrinsicMeasurement
            && $this->isFixedBlockSize === $other->isFixedBlockSize
            && $this->isFormattingContextRoot === $other->isFormattingContextRoot
            && $this->spaceType === $other->spaceType
            && $this->paddingTop === $other->paddingTop
            && $this->paddingRight === $other->paddingRight
            && $this->paddingBottom === $other->paddingBottom
            && $this->paddingLeft === $other->paddingLeft
            && $this->borderTop === $other->borderTop
            && $this->borderRight === $other->borderRight
            && $this->borderBottom === $other->borderBottom
            && $this->borderLeft === $other->borderLeft
            && $this->containerWidth === $other->containerWidth
            && $this->containerHeight === $other->containerHeight
        ;
    }

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
        string $spaceType = 'block',
        ?int $determinedPercentageWidth = null,
        ?int $determinedPercentageHeight = null,
        bool $isFixedBlockSize = false,
        bool $isFormattingContextRoot = false,
    ) {
        $this->containerWidth        = $containerWidth;
        $this->containerHeight       = $containerHeight;
        $this->parentContentX        = $parentContentX;
        $this->parentContentY        = $parentContentY;
        $this->contentWidth          = $contentWidth > 0 ? $contentWidth : $containerWidth;
        $this->contentHeight         = $contentHeight > 0 ? $contentHeight : $containerHeight;
        $this->percentageWidth       = $percentageWidth;
        $this->percentageHeight      = $percentageHeight;
        $this->determinedPercentageWidth  = $determinedPercentageWidth;
        $this->determinedPercentageHeight = $determinedPercentageHeight;
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
        $this->spaceType             = $spaceType;
        $this->isFixedBlockSize      = $isFixedBlockSize;
        $this->isFormattingContextRoot = $isFormattingContextRoot;
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
        bool $isFormattingContextRoot = false,
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
            null,
            null,
            false,
            $isFormattingContextRoot,
        );
    }




}
