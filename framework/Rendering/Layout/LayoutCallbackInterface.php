<?php
namespace Px\Rendering\Layout;
use native_types;
/**
 * LayoutCallbackInterface — 布局回调接口（由 LayoutResolver 实现）
 * Phase 5 过渡期保留。
 */
interface LayoutCallbackInterface
{
    public function onLayoutComplete(array $renderNodes): void;
}
