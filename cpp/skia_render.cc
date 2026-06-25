/**
 * skia_render.cc — Skia 渲染模块入口（精简版）
 *
 * 已拆分为独立模块，本文件通过 #include 确保各模块被编译。
 * 拆分：
 *   skia_font.cc  — DirectWrite、字体加载、引擎选择、Skia 字体
 *   skia_core.cc  — 窗口上下文、帧管理、形状渲染原语
 *   skia_text.cc  — 文本绘制与测量
 *   skia_image.cc — 图片加载/绘制/截图
 */
#include "skia_render.h"
