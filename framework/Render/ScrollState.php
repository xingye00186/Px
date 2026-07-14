<?php

namespace Px\Render;

use native_types;

/**
 * ScrollState — 滚动状态（平台层维护）
 *
 * 由 ScrollManager 持有 Map<RenderNode, ScrollState> 管理。
 * 布局引擎通过 ScrollManager 访问滚动状态，不再直接操作 RenderNode。
 *
 * AOT 兼容：纯值对象，无方法。
 */
class ScrollState
{
    public int $scrollTop = 0;
    public int $scrollLeft = 0;
    public int $lastScrollTop = 0;
    public int $contentWidth = 0;
    public int $contentHeight = 0;
    public bool $isScrollContainer = false;
}
