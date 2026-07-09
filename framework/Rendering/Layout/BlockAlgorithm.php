<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;

/**
 * BlockAlgorithm — Block 布局算法（Phase 1）
 *
 * 对标 Blink BlockLayoutAlgorithm。
 * 当前为适配器实现，内部委托到 BlockLayoutStrategy。
 * 后续逐步将换行逻辑等内联到此算法。
 */
class BlockAlgorithm extends LayoutAlgorithm
{
    private BlockLayoutStrategy $strategy;

    public function __construct()
    {
        $this->strategy = new BlockLayoutStrategy();
    }

    public function layout(ConstraintSpace $space, ?PhysicalFragment $inputFragment = null): PhysicalFragment
    {
        // 适配器模式：通过旧策略执行布局
        $input = new LayoutInput(
            constraints: $space->toLegacy(),
            style: new ComputedStyle([]), // 由 Orchestrator 外部填充
        );
        $result = $this->strategy->layout($input);
        return PhysicalFragment::fromLayoutResult($result);
    }

    public function intrinsicSize(ConstraintSpace $space): IntrinsicSizes
    {
        $input = new LayoutInput(
            constraints: $space->toLegacy(),
            style: new ComputedStyle([]),
        );
        $result = $this->strategy->layout($input);
        return new IntrinsicSizes(
            minContentWidth: $result->minContentWidth,
            maxContentWidth: $result->maxContentWidth,
            minContentHeight: $result->minContentHeight,
            maxContentHeight: $result->maxContentHeight,
        );
    }
}
