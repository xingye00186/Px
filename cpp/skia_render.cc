/**
 * skia_render.cc — Skia 渲染模块入口（编译入口）
 *
 * 通过 #include 包含所有拆分模块，确保 AOT 编译器能编译全部函数。
 * 各模块函数定义在其独立的 .cc 文件中，此文件仅作为编译入口。
 */
#include "skia_render.h"
#include "skia_core.cc"
#include "skia_text.cc"
#include "skia_image.cc"
// skia_font.cc 的全局变量和函数通过头文件声明 + .obj 文件提供
