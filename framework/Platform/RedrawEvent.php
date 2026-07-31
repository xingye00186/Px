<?php

namespace Px\Platform;

use native_types;

/**
 * RedrawEvent — 表面内容失效事件（终极融合 P0.4）
 *
 * 取代 WindowEvent('paint')。"表面内容失效需重绘"是**跨平台通用**语义，
 * 而非 Win32 专属，故独立成类而非塞进 LifecycleEvent：
 *   Win32   WM_PAINT
 *   Android onNativeWindowRedrawNeeded
 *   iOS     CALayer displayLayer / drawLayer:inContext:
 */
class RedrawEvent extends PlatformEvent
{
    public function __construct()
    {
        parent::__construct('redraw');
    }
}
