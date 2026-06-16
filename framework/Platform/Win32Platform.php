<?php

namespace Px\Platform;

use native_types;

use Px\Rendering\RenderContext;
use Px\Rendering\GdiRenderContext;
use Px\Rendering\SkiaRenderContext;

class Win32Platform implements Platform
{
    private int $hwnd = 0;
    private int $animationTimerId = 0;
    /** @var callable|null */
    private ?\Closure $animationCallback = null;
    private bool $animationTimerSet = false;

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

    public function init(string $title, int $width, int $height): RenderContext
    {
        $this->hwnd = vue_window_create($title, $width, $height);
        // APP_HEADLESS: 不显示窗口（用于 CI/自动化 dump-layout）
        if (!defined('APP_HEADLESS') || !APP_HEADLESS) {
            vue_window_show($this->hwnd, WinMsg::SW_SHOW);
        }
        vue_hide_console();
        // APP_RENDERER='skia' 则用 Skia 路径，默认 GDI（零侵入）
        if (defined('APP_RENDERER') && APP_RENDERER === 'skia') {
            return new SkiaRenderContext($this->hwnd, $width, $height);
        }
        return new GdiRenderContext($this->hwnd);
    }

    public function getHwnd(): int
    {
        return $this->hwnd;
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
        return vue_quit_requested();
    }

    /**
     * @deprecated 光标切换已由 Win32 GDI 渲染层自动处理，此方法保留仅为兼容。
     */
    public function setCursor(string $cursor): void
    {
        // 光标切换由 Win32 GDI 层在渲染时根据元素 cursor 字段处理
        // 此方法用于外部直接控制（如 Application hover 检测）
        // C++ 层需要实现 Win32 SetCursor() 调用
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

    /** @return PlatformEvent[] */
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
                $events[] = new MouseEvent($action, $x, $y, 0, $delta, $shiftDown);
            } elseif ($cat === 'keyboard') {
                $char    = ($msgType === WinMsg::WM_CHAR)
                    ? chr($wParam & 0xFF) : '';
                $events[] = new KeyboardEvent($action, $wParam, $char);
            } elseif ($cat === 'window' && $action === 'resize') {
                $width   = $lParam & 0xFFFF;
                $height  = ($lParam >> 16) & 0xFFFF;
                $events[] = new WindowEvent('resize', $width, $height);
            } elseif ($cat === 'window' && $action === 'paint') {
                $events[] = new WindowEvent('paint');
            } elseif ($action === 'close') {
                $events[] = new WindowEvent('close');
            }
        }

        return $events;
    }
}
