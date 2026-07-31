<?php

namespace Px\Platform;

use native_types;

/**
 * Win32Platform — Windows Embedder
 *
 * 职责：把 Win32 的一切（HWND / WM_* 消息）翻译为 Framework 层的统一抽象。
 * Framework 层不得看到任何 Win32 概念（终极融合铁律 1）。
 * 渲染上下文不在此创建（P1.3 Surface 解耦）：由框架侧
 * RuntimeBackendSelector 从 getSurface() 构造。
 *
 *   WM_LBUTTONDOWN/UP/MOUSEMOVE/MOUSEWHEEL → PointerEvent(kind:'mouse')
 *   WM_KEYDOWN/KEYUP/CHAR                  → KeyEvent
 *   WM_SIZE                                → MetricsEvent
 *   WM_PAINT                               → RedrawEvent
 *   WM_CLOSE                               → LifecycleEvent('detached')
 */
class Win32Platform implements Platform
{
    private int $hwnd = 0;
    private int $animationTimerId = 0;
    /** @var callable|null */
    private ?\Closure $animationCallback = null;
    private bool $animationTimerSet = false;

    private int $surfaceWidth = 0;
    private int $surfaceHeight = 0;
    /** 生命周期状态：WM_CLOSE 后置 detached */
    private string $lifecycleState = LifecycleEvent::STATE_ACTIVE;

    private array $eventMap = [
        WinMsg::WM_LBUTTONDOWN => ['mouse', 'down'],
        WinMsg::WM_LBUTTONUP   => ['mouse', 'up'],
        WinMsg::WM_MOUSEMOVE   => ['mouse', 'move'],
        WinMsg::WM_MOUSEWHEEL  => ['mouse', 'wheel'],
        WinMsg::WM_KEYDOWN     => ['keyboard', 'down'],
        WinMsg::WM_KEYUP       => ['keyboard', 'up'],
        WinMsg::WM_CHAR        => ['keyboard', 'char'],
        WinMsg::WM_SIZE        => ['window', 'resize'],
        WinMsg::WM_PAINT       => ['window', 'paint'],
        WinMsg::WM_CLOSE       => ['window', 'close'],
    ];

    public function init(string $title, int $width, int $height): void
    {
        // headless 模式：不创建窗口，hwnd 保持 0（Skia 用内存 DC 离屏渲染）
        if (!\Px\Core\Application::$HEADLESS) {
            $this->hwnd = vue_window_create($title, $width, $height);
            vue_window_show($this->hwnd, WinMsg::SW_SHOW);
        }
        $this->surfaceWidth  = $width;
        $this->surfaceHeight = $height;
        vue_hide_console();
        // 渲染上下文不在此创建：框架侧从 getSurface() 构造（P1.3）。
        // 旧实现在此返回的 Gdi/SkiaRenderContext 在生产路径恒被
        // Application::initRenderer 立即丢弃（unset），属无效构造。
    }

    public function getSurface(): RenderSurface
    {
        return new RenderSurface($this->hwnd, $this->surfaceWidth, $this->surfaceHeight, $this->currentDprPermille());
    }

    public function getMetrics(): ViewMetrics
    {
        // 桌面端安全区域恒 0（无刘海屏/手势条）
        return new ViewMetrics(
            $this->surfaceWidth,
            $this->surfaceHeight,
            $this->currentDprPermille(),
            0, 0, 0, 0,
            'win32'
        );
    }

    public function getLifecycleState(): string
    {
        if ($this->lifecycleState !== LifecycleEvent::STATE_DETACHED && vue_quit_requested()) {
            $this->lifecycleState = LifecycleEvent::STATE_DETACHED;
        }
        return $this->lifecycleState;
    }

    /**
     * 当前 DPR 的千分比。
     *
     * 未接 Per-Monitor DPI 前恒返 1000（1.0x）—— 不猜测。
     * 真实 DPI 查询需 C++ 侧新增 vue_get_dpi 绑定，归属 P4.4（DpiManager）。
     */
    private function currentDprPermille(): int
    {
        return 1000;
    }

    public function shutdown(): void
    {
        // 停止动画定时器
        if ($this->animationTimerSet && $this->hwnd !== 0) {
            vue_kill_timer($this->hwnd, $this->animationTimerId);
            $this->animationTimerSet = false;
            $this->animationTimerId = 0;
        }
        // Window is destroyed by the native layer on quit
        $this->hwnd = 0;
    }

    public function shouldClose(): bool
    {
        return $this->getLifecycleState() === LifecycleEvent::STATE_DETACHED;
    }

    /**
     * @deprecated 光标切换已由 Win32 GDI 渲染层自动处理，此方法保留仅为兼容。
     */
    public function setCursor(string $cursor): void
    {
        if ($cursor !== '') {
            vue_set_cursor($cursor);
        } else {
            vue_set_cursor('default');
        }
    }

    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void
    {
        // 停止旧的定时器
        if ($this->animationTimerSet && $this->hwnd !== 0) {
            vue_kill_timer($this->hwnd, $this->animationTimerId);
            $this->animationTimerSet = false;
        }

        $this->animationCallback = $callback;

        if ($this->hwnd !== 0) {
            $this->animationTimerId = vue_set_timer($this->hwnd, $intervalMs);
            $this->animationTimerSet = ($this->animationTimerId > 0);
        }
    }

    /** @return PlatformEvent[] PointerEvent / KeyEvent / MetricsEvent / RedrawEvent / LifecycleEvent */
    public function pollEvents(): array
    {
        $events = [];
        $msg = vue_peek_message();
        if ($msg === null || count($msg) === 0) {
            return $events;
        }

        $msgType = $msg[1] ?? 0;
        $wParam  = $msg[2] ?? 0;
        $lParam  = $msg[3] ?? 0;

        // 处理定时器消息（动画帧驱动）
        if ($msgType === WinMsg::WM_TIMER) {
            $timerId = (int)$wParam;
            if ($timerId === $this->animationTimerId && $this->animationCallback !== null) {
                ($this->animationCallback)();
            }
            return $events;
        }

        if (isset($this->eventMap[$msgType])) {
            $cat    = $this->eventMap[$msgType][0];
            $action = $this->eventMap[$msgType][1];

            if ($cat === 'mouse') {
                $x     = $lParam & 0xFFFF;
                $y     = ($lParam >> 16) & 0xFFFF;
                $delta = ($msgType === WinMsg::WM_MOUSEWHEEL)
                    ? (($wParam >> 16) & 0xFFFF) : 0;
                if ($delta >= 32768) {
                    $delta -= 65536;
                }
                // 从 wParam LOWORD 提取修饰键（MK_SHIFT = 0x0004）
                $shiftDown = (($wParam & 0xFFFF) & 0x0004) !== 0;
                // 鼠标 → 统一指针：kind='mouse'、pointerId=0、无压感（pressure=1000）
                $events[] = new PointerEvent($action, $x, $y, 0, $delta, $shiftDown, 'mouse', 0, 1000);
            } elseif ($cat === 'keyboard') {
                $char    = ($msgType === WinMsg::WM_CHAR)
                    ? chr($wParam & 0xFF) : '';
                $events[] = new KeyEvent($action, $wParam, $char, $this->currentModifiers());
            } elseif ($cat === 'window' && $action === 'resize') {
                $width   = $lParam & 0xFFFF;
                $height  = ($lParam >> 16) & 0xFFFF;
                $this->surfaceWidth  = $width;
                $this->surfaceHeight = $height;
                $events[] = new MetricsEvent($this->getMetrics());
            } elseif ($cat === 'window' && $action === 'paint') {
                $events[] = new RedrawEvent();
            } elseif ($action === 'close') {
                $this->lifecycleState = LifecycleEvent::STATE_DETACHED;
                $events[] = new LifecycleEvent(LifecycleEvent::STATE_DETACHED);
            }
        }

        return $events;
    }

    /**
     * 当前修饰键位掩码（KeyEvent::MOD_*）。
     *
     * WM_KEYDOWN/CHAR 的 wParam/lParam 不携带修饰键位，需查当前键盘状态。
     * C++ 侧尚未提供 GetKeyState 绑定，故恒返 0（不猜测）—— 修饰键的
     * 真实接入归属 P2.1（快捷键系统）。当前无消费者：没有任何代码读
     * KeyEvent::$modifiers，此字段为 Phase 2 预置的抽象位。
     */
    private function currentModifiers(): int
    {
        return 0;
    }
}
