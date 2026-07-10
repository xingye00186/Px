<?php
namespace Px\Rendering\Layout;
use native_types;

/**
 * AbsoluteStrategy — 绝对定位策略接口（由 AbsolutePositioning 实现）
 */
interface AbsoluteStrategy
{
    public function absoluteLayout(LayoutInput $input): LayoutResult;
}
