<?php

namespace Px\Rendering\Layout\Flex;

use native_types;
use Px\Rendering\RenderNode;

/**
 * FlexItem — Flex 子项数据对象
 *
 * 封装 flex 子项的算法属性，使 Collector/Breaker/Distributor 可以
 * 在纯数据层面操作，不直接依赖 RenderNode 的树结构。
 */
class FlexItem
{
    /** 来源 RenderNode（用于写回布局结果） */
    public RenderNode $node;

    // ── Flex 伸缩属性 ──
    public float $grow = 0.0;
    public float $shrink = 1.0;
    /** flex-basis 像素值，-1 = auto */
    public int $basis = -1;
    public bool $isFlexGrow = false;

    // ── 尺寸（算法中间结果） ──
    /** 主轴尺寸 */
    public int $mainSize = 0;
    /** 交叉轴尺寸 */
    public int $crossSize = 0;

    // ── 定位 ──
    /** 主轴起始偏移 */
    public int $mainOffset = 0;
    /** 交叉轴起始偏移 */
    public int $crossOffset = 0;

    // ── 外边距（沿主轴/交叉轴方向） ──
    public int $marginBefore = 0;
    public int $marginAfter = 0;
    public int $marginCrossBefore = 0;
    public int $marginCrossAfter = 0;

    /** 是否显式设置了交叉轴尺寸 */
    public bool $hasExplicitCrossSize = false;

    public function __construct(RenderNode $node)
    {
        $this->node = $node;
    }
}
