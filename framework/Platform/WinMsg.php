<?php

namespace Px\Platform;

/**
 * WinMsg — Windows 消息常量和 Window State 常量
 */
class WinMsg
{
    // Window Messages
    public const WM_LBUTTONDOWN = 0x0201;
    public const WM_LBUTTONUP   = 0x0202;
    public const WM_MOUSEMOVE   = 0x0200;
    public const WM_MOUSEWHEEL  = 0x020A;
    public const WM_KEYDOWN     = 0x0100;
    public const WM_KEYUP       = 0x0101;
    public const WM_CHAR        = 0x0102;
    public const WM_SIZE        = 0x0005;
    public const WM_PAINT       = 0x000F;
    public const WM_CLOSE       = 0x0010;

    // Window Show State
    public const SW_SHOW = 5;
    public const SW_HIDE = 0;
}