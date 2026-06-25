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
function sk_shadow_round_rect(int $x, int $y, int $w, int $h, int $radius, int $blur, int $rgb, float $opacity): void {}
function sk_draw_button(int $x, int $y, int $w, int $h, int $bgColor, int $borderColor): void {}
function sk_push_clip(int $x, int $y, int $w, int $h): void {}
function sk_push_clip_rrect(int $x, int $y, int $w, int $h, int $radius): void {}
function sk_pop_clip(): void {}

// ---- 字体管理 ----
function sk_set_default_font(string $fontFamily): void {}
function sk_set_text_engine(string $engine): void {}

// ---- 阶段三：窗口尺寸变更（WM_SIZE 监听，重建 SkSurface） ----
function sk_measure_text_width(string $text, int $fontSize, int $bold): int {}
function sk_measure_text_height(int $fontSize, int $bold): int {}
function sk_resize_context(int $width, int $height): void {}

// ---- 图片加载（双路径：USE_SKIA → SkImage, 非USE_SKIA → GDI+） ----
function sk_load_image(string $path): int {}
function sk_draw_image(int $handle, int $x, int $y, int $w, int $h): void {}
function sk_free_image(int $handle): void {}

// ---- 截图：将当前窗口 DC 保存为 PNG（headless 模式） ----
function sk_save_screenshot(string $path): void {}

// ---- 阶段三增强：线性渐变填充 ----
function sk_fill_gradient_rect(int $x, int $y, int $w, int $h, int $angle, int $color1, int $color2, int $radius): void {}

// ---- 阶段四：独立XY半径变体 ----
function sk_draw_round_rect_xy(int $x, int $y, int $w, int $h, int $rx, int $ry, int $rgb): void {}
function sk_shadow_round_rect_xy(int $x, int $y, int $w, int $h, int $rx, int $ry, int $blur, int $rgb, float $opacity): void {}
function sk_fill_gradient_rect_xy(int $x, int $y, int $w, int $h, int $angle, int $color1, int $color2, int $rx, int $ry): void {}
