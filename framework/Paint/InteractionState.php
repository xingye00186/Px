<?php

namespace Px\Paint;

use native_types;

/**
 * InteractionState — 交互状态（事件处理器维护）
 *
 * 由 Application 持有 Map<RenderNode, InteractionState> 管理。
 * 用于伪类样式（:hover, :focus, :active）的判断。
 *
 * AOT 兼容：纯值对象，无方法。
 */
class InteractionState
{
    public bool $hovered = false;
    public bool $focused = false;
    public bool $active = false;
}
