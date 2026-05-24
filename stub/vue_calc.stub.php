<?php

/**
 * VueCalc Win32 API 声明 (stub)
 *
 * C++ 层仅提供 Win32 API 的薄封装。
 * 所有计算器逻辑、响应式数据均由 PHP 端实现。
 *
 * 函数命名规范: 在 PHP 中以 vue_ 开头, C++ 实现中对应 php_vue_ 前缀
 */

// ---- Win32 消息常量 (v6 M4) ----
// AOT 兼容: 使用类常量替代 define()
class WinMsg
{
    // 鼠标消息
    public const WM_LBUTTONDOWN = 0x0201;
    public const WM_RBUTTONDOWN = 0x0204;
    public const WM_MBUTTONDOWN = 0x0207;
    public const WM_MOUSEWHEEL  = 0x020A;
    // 键盘消息
    public const WM_KEYDOWN = 0x0100;
    public const WM_KEYUP = 0x0101;
    public const WM_CHAR = 0x0102;
    // 系统消息
    public const WM_QUIT = 0x0012;
    public const WM_CLOSE = 0x0010;
    public const WM_DESTROY = 0x0002;
    // 虚拟键码
    public const VK_BACK = 0x08;
    public const VK_TAB = 0x09;
    public const VK_RETURN = 0x0D;
    public const VK_ESCAPE = 0x1B;
    public const VK_SPACE = 0x20;
    public const VK_LEFT = 0x25;
    public const VK_UP = 0x26;
    public const VK_RIGHT = 0x27;
    public const VK_DOWN = 0x28;
    public const VK_DELETE = 0x2E;
    // ShowWindow commands
    public const SW_SHOW = 1;
    public const SW_HIDE = 0;
}

// ---- 窗口管理 ----
function vue_window_create(string $title, int $width, int $height): int {}
function vue_window_show(int $hWnd, int $cmdShow): void {}
function vue_quit_requested(): bool {}
function vue_peek_message(): array {}

// ---- GDI 绘制原语 ----
function vue_begin_paint(int $hWnd): int {}
function vue_end_paint(int $hWnd, int $hdc): void {}
function vue_fill_rect(int $hdc, int $x, int $y, int $w, int $h, int $rgb): void {}
function vue_draw_text(int $hdc, int $x, int $y, string $text, int $fontSize, int $rgb, int $bold): void {}
function vue_draw_button(int $hdc, int $x, int $y, int $w, int $h, int $bgColor, int $borderColor): void {}
function vue_measure_text_width(int $hdc, string $text, int $fontSize): int {}
