<?php

namespace Px\Platform;

use native_types;

/**
 * MetricsEvent — 视图度量变化事件（终极融合 P0.4）
 *
 * 取代 WindowEvent('resize')。除窗口尺寸外，还承载 DPR 与安全区域变化：
 *   Win32   WM_SIZE / WM_DPICHANGED
 *   Android onNativeWindowResized / WindowInsets 变化
 *   iOS     viewWillTransitionToSize / safeAreaInsetsDidChange
 *
 * 移动端旋转屏会同时改变尺寸与安全区域，故合为一个事件而非拆分。
 */
class MetricsEvent extends PlatformEvent
{
    public ViewMetrics $metrics;

    public function __construct(ViewMetrics $metrics)
    {
        parent::__construct('metrics');
        $this->metrics = $metrics;
    }

    public function getMetrics(): ViewMetrics { return $this->metrics; }
}
