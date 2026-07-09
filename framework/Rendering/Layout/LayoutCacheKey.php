<?php

namespace Px\Rendering\Layout;

use native_types;

/**
 * LayoutCacheKey — 布局缓存键
 *
 * 由 ConstraintSpace 签名 + nodeId + styleVersion 组成。
 * 比较方式：逐字段 ===，不用 array_map/闭包（AOT 安全）。
 */
class LayoutCacheKey
{
    public readonly int $nodeId;
    public readonly int $contentWidth;
    public readonly int $contentHeight;
    public readonly ?int $percentageWidth;
    public readonly ?int $percentageHeight;
    public readonly bool $isIntrinsicMeasurement;
    public readonly string $spaceType;
    public readonly int $styleVersion;

    public function __construct(
        int $nodeId,
        int $contentWidth,
        int $contentHeight,
        ?int $percentageWidth = null,
        ?int $percentageHeight = null,
        bool $isIntrinsicMeasurement = false,
        string $spaceType = 'block',
        int $styleVersion = 0,
    ) {
        $this->nodeId                = $nodeId;
        $this->contentWidth          = $contentWidth;
        $this->contentHeight         = $contentHeight;
        $this->percentageWidth       = $percentageWidth;
        $this->percentageHeight      = $percentageHeight;
        $this->isIntrinsicMeasurement = $isIntrinsicMeasurement;
        $this->spaceType             = $spaceType;
        $this->styleVersion          = $styleVersion;
    }

    /** 从 ConstraintSpace 和 RenderNode 构建 */
    public static function fromSpace(
        ConstraintSpace $space,
        int $nodeId,
        int $styleVersion = 0,
    ): self {
        return new self(
            nodeId: $nodeId,
            contentWidth: $space->contentWidth,
            contentHeight: $space->contentHeight,
            percentageWidth: $space->percentageWidth,
            percentageHeight: $space->percentageHeight,
            isIntrinsicMeasurement: $space->isIntrinsicMeasurement,
            spaceType: $space->spaceType,
            styleVersion: $styleVersion,
        );
    }

    /** AOT 安全的相等比较 */
    public function equals(LayoutCacheKey $other): bool
    {
        return $this->nodeId === $other->nodeId
            && $this->contentWidth === $other->contentWidth
            && $this->contentHeight === $other->contentHeight
            && $this->percentageWidth === $other->percentageWidth
            && $this->percentageHeight === $other->percentageHeight
            && $this->isIntrinsicMeasurement === $other->isIntrinsicMeasurement
            && $this->spaceType === $other->spaceType
            && $this->styleVersion === $other->styleVersion;
    }
}
