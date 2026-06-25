<?php
/**
 * 从 skia_render.cc 精确提取各模块到拆分文件。
 * 已在 skia_render.h + skia_font.cc 创建后运行。
 * 用法: php cpp/extract_modules.php
 */
$src = file_get_contents(__DIR__ . '/skia_render.cc');
if (!$src) die("Cannot read skia_render.cc\n");

// 关键标记位置
$markers = [];
foreach ([
    '// 创建窗口上下文' => 'php_sk_create_window_context',
    '// 销毁窗口上下文' => 'php_sk_destroy_context',
    '// 开始一帧' => 'php_sk_begin_frame',
    '// 结束一帧' => 'php_sk_end_frame',
    '// 全窗口清屏' => 'php_sk_clear_window',
    '// 单矩形填充' => 'php_sk_fill_rect',
    '// 圆角矩形填充' => 'php_sk_draw_round_rect',
    '// 带透明度的矩形' => 'php_sk_alpha_fill_rect',
    '// 绘制阴影' => 'php_sk_shadow_round_rect',
    '// 绘制阴影（独立XY半径' => 'php_sk_shadow_round_rect_xy',
    '// 线性渐变矩形填充' => 'php_sk_fill_gradient_rect',
    '// 独立XY半径渐变' => 'php_sk_fill_gradient_rect_xy',
    '// 绘制按钮' => 'php_sk_draw_button',
    '// push_clip' => 'php_sk_push_clip',
    '// push_clip_rrect' => 'php_sk_push_clip_rrect',
    '// pop_clip' => 'php_sk_pop_clip',
    '// 绘制文本' => 'php_sk_draw_text',
    '// 精确测量文本宽度' => 'php_sk_measure_text_width',
    '// 测量文本高度' => 'php_sk_measure_text_height',
    '// 加载图片' => 'php_sk_load_image',
    '// 绘制图片' => 'php_sk_draw_image',
    '// 释放图片' => 'php_sk_free_image',
    '// 保存截图' => 'php_sk_save_screenshot',
] as $comment => $func) {
    $p = strpos($src, $comment);
    $markers[$func] = $p;
    if ($p === false) echo "WARNING: marker not found for $func\n";
}
// Add skBlitToGdi
$markers['skBlitToGdi'] = strpos($src, 'static void skBlitToGdi');
if ($markers['skBlitToGdi'] === false) echo "WARNING: skBlitToGdi not found\n";

echo "Found " . count($markers) . " markers\n";

// Find function end positions (look for "}\n" after function body)
// Simplified: find next line that starts with "void php_sk_" or "Int php_sk_" or "}\n\n"
// This is an approximation

// Since exact extraction is complex, output the markers to guide manual extraction
foreach ($markers as $name => $pos) {
    if ($pos === false) continue;
    $lineNum = substr_count(substr($src, 0, $pos), "\n") + 1;
    printf("%-35s at line %d (char %d)\n", $name, $lineNum, $pos);
}
