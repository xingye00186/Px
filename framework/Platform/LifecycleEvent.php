<?php

namespace Px\Platform;

use native_types;

/**
 * LifecycleEvent — 应用生命周期事件（终极融合 P0.4）
 *
 * 取代 WindowEvent('close') 的语义升级。对标 Flutter AppLifecycleState：
 * 桌面端只用 active/detached 两态；移动端由 OS 状态机驱动全部四态。
 *
 *   active   前台可见可交互（桌面默认态）
 *   inactive 前台但不可交互（来电、通知栏下拉、iOS 应用切换器）
 *   paused   后台不可见（Android onPause / iOS didEnterBackground）
 *   detached 宿主已销毁，应终止事件循环（Win32 WM_CLOSE）
 */
class LifecycleEvent extends PlatformEvent
{
    public const STATE_ACTIVE   = 'active';
    public const STATE_INACTIVE = 'inactive';
    public const STATE_PAUSED   = 'paused';
    public const STATE_DETACHED = 'detached';

    public string $state;

    public function __construct(string $state)
    {
        parent::__construct('lifecycle');
        $this->state = $state;
    }

    public function getState(): string { return $this->state; }

    /** 宿主已销毁 —— 事件循环终止条件 */
    public function isDetached(): bool { return $this->state === self::STATE_DETACHED; }
}
