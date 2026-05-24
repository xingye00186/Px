<?php

namespace Px\Platform;

use Px\Rendering\RenderContext;
use Px\Rendering\GdiRenderContext;

class Win32Platform implements Platform
{
    private int $hwnd = 0;

    private array $eventMap = [
        WinMsg::WM_LBUTTONDOWN => ['mouse', 'down'],
        WinMsg::WM_LBUTTONUP   => ['mouse', 'up'],
        WinMsg::WM_MOUSEMOVE   => ['mouse', 'move'],
        WinMsg::WM_MOUSEWHEEL  => ['mouse', 'wheel'],
        WinMsg::WM_KEYDOWN     => ['keyboard', 'down'],
        WinMsg::WM_KEYUP       => ['keyboard', 'up'],
        WinMsg::WM_CHAR        => ['keyboard', 'char'],
        WinMsg::WM_SIZE        => ['window', 'resize'],
        WinMsg::WM_CLOSE       => ['window', 'close'],
    ];

    public function init(string $title, int $width, int $height): RenderContext
    {
        $this->hwnd = vue_window_create($title, $width, $height);
        vue_window_show($this->hwnd, WinMsg::SW_SHOW);
        return new GdiRenderContext($this->hwnd);
    }

    public function shutdown(): void
    {
        // Window is destroyed by the native layer on quit
        $this->hwnd = 0;
    }

    public function shouldClose(): bool
    {
        return vue_quit_requested();
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
                $events[] = new MouseEvent($action, $x, $y, 0, $delta);
            } elseif ($cat === 'keyboard') {
                $char    = ($msgType === WinMsg::WM_CHAR)
                    ? chr($wParam & 0xFF) : '';
                $events[] = new KeyboardEvent($action, $wParam, $char);
            } elseif ($cat === 'window' && $action === 'resize') {
                $width   = $lParam & 0xFFFF;
                $height  = ($lParam >> 16) & 0xFFFF;
                $events[] = new WindowEvent('resize', $width, $height);
            } elseif ($action === 'close') {
                $events[] = new WindowEvent('close');
            }
        }

        return $events;
    }
}
