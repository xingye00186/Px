<?php
namespace Px\Rendering\Layout;
use native_types;
/**
 * LayoutStrategyInterface — 旧策略接口（由旧布局策略实现）
 * Phase 5 过渡期保留，将被 Algorithm 类完全替代。
 */
interface LayoutStrategyInterface
{
    public function layout(LayoutInput $input): LayoutResult;
}
