<?php

/**
 * Skia 渲染原语 stub
 *
 * 命名规范：PHP 中 sk_ 开头 → C++ 实现中 php_sk_ 前缀
 * 阶段一：6 个 POC 函数（GDI 兜底实现）
 * 阶段二：追加 6 个完整 GDI 兼容层函数
 * 阶段三：底层 USE_SKIA 切换为真 Skia 调用
 *
 * 与 vue_calc.stub.php 平铺共存，互不影响。
 */

// ---- 阶段一 POC：上下文与基础原语 ----
function sk_create_window_context(int $hWnd, int $width, int $height): int {}
function sk_destroy_context(): void {}
function sk_begin_frame(): void {}
function sk_end_frame(): void {}
function sk_clear_window(int $rgb): void {}
function sk_fill_rect(int $x, int $y, int $w, int $h, int $rgb): void {}

// ---- 阶段二：GDI 兼容层函数（与 vue_calc GDI 调用 1:1 对应） ----
function sk_draw_text(int $x, int $y, string $text, int $fontSize, int $rgb, int $bold): void {}
function sk_draw_round_rect(int $x, int $y, int $w, int $h, int $radius, int $rgb): void {}
function sk_alpha_fill_rect(int $x, int $y, int $w, int $h, int $rgb, float $opacity): void {}
function sk_draw_button(int $x, int $y, int $w, int $h, int $bgColor, int $borderColor): void {}
function sk_push_clip(int $x, int $y, int $w, int $h): void {}
function sk_pop_clip(): void {}

// ---- 阶段三：窗口尺寸变更（WM_SIZE 监听，重建 SkSurface） ----
function sk_resize_context(int $width, int $height): void {}
