/**
 * SkiaRenderContext Native Layer
 *
 * 阶段一/二：用 Win32 GDI 真实绘制，验证 AOT 扫描链与 sk_* 符号链路
 * 阶段三（USE_SKIA 宏开启）：切换为真 Skia 调用，享受抗锯齿 + 圆角优势
 *
 * 与 vue_calc.cc 平铺共存，使用独立的 g_sk* 全局变量隔离状态
 * 本阶段仅支持单窗口（多窗口在 Phase 6 通过 php::Box 重构）
 *
 * Task 3.7 风险对策：阶段三 sk_clear_window 退化为空实现（背景清屏统一在
 *           drawElement 根节点绘制前 canvas->clear() 完成）
 * Task 3.8 风险对策：阶段三 sk_alpha_fill_rect 与 sk_fill_rect 合并实现
 *           （唯一差异是 paint.setAlphaf()）
 * Task 3.6 风险对策：阶段三新增 sk_resize_context，监听 WM_SIZE 重建 SkCanvas
 *
 * 阶段三字体限制：aseprite fork 已移除 SkFontMgr_New_FCI，Skia 内置系统字体
 * 加载需走 DirectWrite 集成（阶段四）。本阶段文本绘制静默跳过；矩形/圆角抗锯齿
 * （阶段三核心价值）正常工作
 *
 * Skia m148 API 变化：SkSurface 静态工厂（如 MakeRasterN32Premul）已移除，
 * 改用 SkBitmap + SkCanvas::MakeRasterDirectN32 模式创建离屏画布
 */

#include <phpx.h>
#include <windows.h>
#pragma comment(lib, "msimg32.lib")
#include <cstdio>
#include <string>
#include <vector>
#include <memory>

#ifdef USE_SKIA
#include "include/core/SkBitmap.h"
#include "include/core/SkCanvas.h"
#include "include/core/SkPaint.h"
#include "include/core/SkFont.h"
#include "include/core/SkFontMgr.h"
#include "include/core/SkTypeface.h"
#include "include/core/SkRect.h"
#include "include/core/SkRRect.h"
#include "include/core/SkImageInfo.h"
#include "include/core/SkColor.h"
#include "include/core/SkString.h"
#include "include/core/SkFontMetrics.h"
#include "include/ports/SkFontMgr_directory.h"   // SkFontMgr_New_Custom_Directory
#include <cstdio>

#endif

using namespace php;

// ============================================================
// Skia 模块全局状态（仅供 sk_* 函数使用，与 vue_* 隔离）
// ============================================================

static HWND g_skHwnd = NULL;
static HDC  g_skHdc  = NULL;       // begin_frame 期间持有的 memDC（双缓冲）
static HBITMAP g_skBitmap = NULL;  // memDC 选中的 bitmap，end_frame 时释放
static void*  g_skBits = NULL;     // CreateDIBSection 返回的像素指针（直接 memcpy 用）
static int  g_skW = 0;
static int  g_skH = 0;

#ifdef USE_SKIA
// 阶段三：Skia 离屏位图 + canvas
static SkBitmap  g_skSkBitmap;             // 后端像素缓冲
static std::unique_ptr<SkCanvas> g_skCanvas;  // 绘制 canvas
static bool              g_skFontInited = false;
static sk_sp<SkFontMgr>  g_skFontMgr;       // 阶段三：用 Custom_Directory 扫描 fonts 目录
static sk_sp<SkTypeface> g_skTypeface;      // 从 FontMgr 加载的 typeface
static SkFont            g_skFont;          // 值类型（SkFont 非 ref-counted）
static std::vector<uint8_t> g_skPixelBuf;  // end_frame 时 SkBitmap → GDI 中转
#endif

#ifdef USE_SKIA
// ============================================================
// 阶段三辅助函数
// ============================================================

// 颜色字节序转换：Windows COLORREF (0x00BBGGRR) → SkColor (0xAARRGGBB)
// 修复 #3：PHP 端用 hexToBgr 转换 CSS #RRGGBB → 0x00BBGGRR，Skia 期望 ARGB
//         原代码直接 cast 导致 alpha=0 (Skia 透明不绘制)
// 修复 #4：强制 alpha=0xFF (不透明)，避免从 COLORREF 读出 alpha=0
static inline SkColor rgbToSkColor(Int rgb) {
    uint32_t c = (uint32_t)(int)rgb;
    return SkColorSetARGB(0xFF,
        (U8CPU)( c        & 0xFF),   // R (was in COLORREF bits 0-7)
        (U8CPU)((c >> 8)  & 0xFF),   // G (was in bits 8-15)
        (U8CPU)((c >> 16) & 0xFF));  // B (was in bits 16-23)
}

// 初始化字体（阶段三：走 SkFontMgr_New_Custom_Directory 扫描 fonts 目录 + FreeType 渲染）
static bool skEnsureFont() {
    if (g_skFontInited && g_skTypeface) {
        return true;  // 已加载，复用
    }
    g_skFontInited = true;

    // 首选：扫描 D:/Px/cpp/fonts 目录（可一次性加载 Noto Sans SC 9 个字重）
    g_skFontMgr = SkFontMgr_New_Custom_Directory("D:/Px/cpp/fonts");
    if (!g_skFontMgr) {
        return false;
    }
    // 从指定路径加载 Regular 字重
    g_skTypeface = g_skFontMgr->makeFromFile("D:/Px/cpp/fonts/NotoSansSC-Regular.ttf");
    if (!g_skTypeface) {
        // Fallback：尝试标准系统字体
        g_skTypeface = g_skFontMgr->makeFromFile("C:/Windows/Fonts/msyh.ttc");
    }
    if (!g_skTypeface) {
        return false;
    }
    g_skFont = SkFont(g_skTypeface, 14.0f);
    g_skFont.setSubpixel(true);
    g_skFont.setEdging(SkFont::Edging::kAntiAlias);
    return true;
}

// 把 SkBitmap 像素拷贝到 g_skHdc（end_frame 调用）
// 关键步骤：bitmap.readPixels(BGRA) → SetDIBits 写入 g_skBitmap（HBITMAP）
// 说明：SetDIBitsToDevice 在 compatible DC + compatible bitmap 组合下行为不一致
//       （DIB 数据直接绘到 DC surface，不写入位图），改用 SetDIBits 直接更新
//       当前选中的 HBITMAP。BI_BITFIELDS 模式 + 显式 BGRA 掩码，避免依赖
//       BI_RGB + 32-bit 的隐式字节序行为。
static void skBlitToGdi() {
    if (!g_skCanvas || !g_skHdc) return;

    int w = g_skSkBitmap.width();
    int h = g_skSkBitmap.height();
    if (w <= 0 || h <= 0) return;

    size_t rowBytes = w * 4;
    g_skPixelBuf.resize(rowBytes * h);

    SkImageInfo info = SkImageInfo::Make(w, h, kBGRA_8888_SkColorType, kPremul_SkAlphaType);
    g_skSkBitmap.readPixels(info, g_skPixelBuf.data(), rowBytes, 0, 0);

    // 把 Skia 离屏画布像素直接绘到 GDI DC surface
    // 说明：v15 实验：SetDIBitsToDevice 直接把 DIB 绘到 DC，行为稳定（+ 颜色修复后）
    // 替代方案 SetDIBits（写 HBITMAP）和 CreateDIBSection 路径在之前实验中均失败
    BITMAPINFO bmi;
    ZeroMemory(&bmi, sizeof(bmi));
    bmi.bmiHeader.biSize        = sizeof(BITMAPINFOHEADER);
    bmi.bmiHeader.biWidth       = w;
    bmi.bmiHeader.biHeight      = -h;  // 负值：top-down DIB
    bmi.bmiHeader.biPlanes      = 1;
    bmi.bmiHeader.biBitCount    = 32;
    bmi.bmiHeader.biCompression = BI_RGB;

    int linesSet = SetDIBitsToDevice(g_skHdc, 0, 0, w, h, 0, 0, 0, h,
                                     g_skPixelBuf.data(), &bmi, DIB_RGB_COLORS);
    (void)linesSet;  // 静默失败不影响运行（除非 DC 无效，但会在下一帧检测到）
}
#endif  // USE_SKIA

// ============================================================
// Task 1.1 — 阶段一 POC：6 个基础原语
// ============================================================

// 创建窗口上下文（保存 hWnd 与尺寸；阶段三创建 SkCanvas）
Int php_sk_create_window_context(Int hWnd, Int width, Int height) {
    g_skHwnd = (HWND)(Int)hWnd;
    g_skW    = (int)width;
    g_skH    = (int)height;
    fprintf(stderr, "[SK] create_window_context hwnd=%p w=%d h=%d\n", g_skHwnd, g_skW, g_skH);
#ifdef USE_SKIA
    g_skSkBitmap.allocN32Pixels(g_skW, g_skH);
    g_skCanvas = SkCanvas::MakeRasterDirectN32(
        g_skW, g_skH,
        (SkPMColor*)g_skSkBitmap.getPixels(),
        g_skSkBitmap.rowBytes());
    fprintf(stderr, "[SK] canvas=%p\n", g_skCanvas.get());
    if (g_skCanvas) {
        g_skCanvas->clear(SK_ColorWHITE);
    }
    bool fontOk = skEnsureFont();
    fprintf(stderr, "[SK] skEnsureFont=%d fontMgr=%p typeface=%p\n", fontOk, g_skFontMgr.get(), g_skTypeface.get());
#endif
    return (Int)1;
}

// 销毁窗口上下文
void php_sk_destroy_context() {
    g_skHwnd = NULL;
    g_skW    = 0;
    g_skH    = 0;
#ifdef USE_SKIA
    g_skCanvas.reset();
    g_skSkBitmap.reset();
#endif
}

// 开始一帧：GDI 创双缓冲 memDC（始终保留以兼容 end_frame BitBlt 流程）
void php_sk_begin_frame() {
    if (!g_skHwnd) {
        fprintf(stderr, "[SK] begin_frame SKIP (no hwnd)\n");
        return;
    }
    HDC screen = GetDC(g_skHwnd);
    RECT rc;
    GetClientRect(g_skHwnd, &rc);
    g_skHdc = CreateCompatibleDC(screen);
    g_skBitmap = CreateCompatibleBitmap(screen, rc.right, rc.bottom);
    SelectObject(g_skHdc, g_skBitmap);
    ReleaseDC(g_skHwnd, screen);
    fprintf(stderr, "[SK] begin_frame rc=(%d,%d) hdc=%p bmp=%p\n", rc.right, rc.bottom, g_skHdc, g_skBitmap);

#ifdef USE_SKIA
    if (g_skCanvas) {
        g_skCanvas->save();
        // 每帧开始 clear 画布（避免残留上一帧 + 替代 sk_clear_window 退化为空）
        g_skCanvas->clear(SK_ColorWHITE);
    }
#endif
}

// 结束一帧：阶段三 Skia → GDI 中转 → BitBlt 到 screen
void php_sk_end_frame() {
    if (!g_skHwnd || !g_skHdc) {
        fprintf(stderr, "[SK] end_frame SKIP (hwnd=%p hdc=%p)\n", g_skHwnd, g_skHdc);
        return;
    }
    RECT rc;
    GetClientRect(g_skHwnd, &rc);
#ifdef USE_SKIA
    fprintf(stderr, "[SK] end_frame BEGIN rc=(%d,%d) hdc=%p canvas=%p\n", rc.right, rc.bottom, g_skHdc, g_skCanvas.get());
    if (g_skCanvas) {
        g_skCanvas->restore();
        fprintf(stderr, "[SK] end_frame after restore\n");
        skBlitToGdi();
        fprintf(stderr, "[SK] end_frame after skBlitToGdi\n");
    }
#endif

    HDC screen = GetDC(g_skHwnd);
    fprintf(stderr, "[SK] end_frame screen=%p\n", screen);
    if (screen) {
        BOOL ok = BitBlt(screen, 0, 0, rc.right, rc.bottom, g_skHdc, 0, 0, SRCCOPY);
        fprintf(stderr, "[SK] end_frame BitBlt ok=%d\n", ok);
        ReleaseDC(g_skHwnd, screen);
    }
    DeleteDC(g_skHdc);
    if (g_skBitmap) {
        DeleteObject(g_skBitmap);
        g_skBitmap = NULL;
    }
    g_skHdc = NULL;
    fprintf(stderr, "[SK] end_frame DONE\n");
}

// 全窗口清屏
// 阶段三：R14 风险对策——退化为空实现（清屏由根节点 canvas->clear 完成）
void php_sk_clear_window(Int rgb) {
#ifdef USE_SKIA
    (void)rgb;
#else
    if (!g_skHdc) return;
    RECT rc = {0, 0, g_skW, g_skH};
    HBRUSH brush = CreateSolidBrush((COLORREF)(Int)rgb);
    FillRect(g_skHdc, &rc, brush);
    DeleteObject(brush);
#endif
}

// 单矩形填充
void php_sk_fill_rect(Int x, Int y, Int w, Int h, Int rgb) {
    static int fillRectCount = 0;
    if (++fillRectCount <= 20 || fillRectCount % 20 == 0) {
        fprintf(stderr, "[SK] fill_rect #%d x=%d y=%d w=%d h=%d rgb=0x%X\n", fillRectCount, (int)x, (int)y, (int)w, (int)h, (unsigned)rgb);
    }
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor(rgbToSkColor(rgb));
    g_skCanvas->drawRect(
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h),
        paint);
#else
    if (!g_skHdc) return;
    HBRUSH brush = CreateSolidBrush((COLORREF)(Int)rgb);
    RECT r = {(int)x, (int)y, (int)(x + w), (int)(y + h)};
    FillRect(g_skHdc, &r, brush);
    DeleteObject(brush);
#endif
}

// ============================================================
// Task 2.1 — 阶段二 GDI 兼容层：6 个函数
// USE_SKIA 阶段三替换为真 Skia 实现
// ============================================================

// 绘制圆角矩形
void php_sk_draw_round_rect(Int x, Int y, Int w, Int h, Int radius, Int rgb) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor(rgbToSkColor(rgb));
    SkRRect rrect;
    rrect.setRectXY(
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h),
        (SkScalar)(int)radius, (SkScalar)(int)radius);
    g_skCanvas->drawRRect(rrect, paint);
#else
    if (!g_skHdc) return;
    HRGN hrgn = CreateRoundRectRgn((int)x, (int)y, (int)(x + w), (int)(y + h),
                                    (int)radius * 2, (int)radius * 2);
    HBRUSH brush = CreateSolidBrush((COLORREF)(Int)rgb);
    FillRgn(g_skHdc, hrgn, brush);
    DeleteObject(brush);
    DeleteObject(hrgn);
#endif
}

// 半透明矩形填充
// 阶段三：R15 风险对策——与 php_sk_fill_rect 合并实现（唯一差异 paint.setAlphaf）
void php_sk_alpha_fill_rect(Int x, Int y, Int w, Int h, Int rgb, double opacity) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    if (opacity <= 0.0) return;
    if (opacity >= 1.0) {
        php_sk_fill_rect(x, y, w, h, rgb);
        return;
    }
    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor(rgbToSkColor(rgb));
    paint.setAlphaf((SkScalar)opacity);
    g_skCanvas->drawRect(
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h),
        paint);
#else
    if (!g_skHdc) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    int alpha = (int)(opacity * 255.0);
    if (alpha >= 255) {
        HBRUSH brush = CreateSolidBrush((COLORREF)(Int)rgb);
        RECT r = {(int)x, (int)y, (int)(x + w), (int)(y + h)};
        FillRect(g_skHdc, &r, brush);
        DeleteObject(brush);
        return;
    }
    if (alpha <= 0) return;

    int blue  = (Int)rgb & 0xFF;
    int green = ((Int)rgb >> 8) & 0xFF;
    int red   = ((Int)rgb >> 16) & 0xFF;

    BITMAPINFO bmi;
    ZeroMemory(&bmi, sizeof(bmi));
    bmi.bmiHeader.biSize        = sizeof(BITMAPINFOHEADER);
    bmi.bmiHeader.biWidth       = (int)w;
    bmi.bmiHeader.biHeight      = -(int)h;
    bmi.bmiHeader.biPlanes      = 1;
    bmi.bmiHeader.biBitCount    = 32;
    bmi.bmiHeader.biCompression = BI_RGB;

    void* bits = NULL;
    HBITMAP hBitmap = CreateDIBSection(g_skHdc, &bmi, DIB_RGB_COLORS, &bits, NULL, 0);
    if (!hBitmap || !bits) {
        if (hBitmap) DeleteObject(hBitmap);
        return;
    }

    HDC memDC = CreateCompatibleDC(g_skHdc);
    HBITMAP oldBitmap = (HBITMAP)SelectObject(memDC, hBitmap);

    unsigned char* p = (unsigned char*)bits;
    int total = (int)w * (int)h;
    for (int i = 0; i < total; i++) {
        p[0] = (unsigned char)(blue  * alpha / 255);
        p[1] = (unsigned char)(green * alpha / 255);
        p[2] = (unsigned char)(red   * alpha / 255);
        p[3] = (unsigned char)alpha;
        p += 4;
    }

    BLENDFUNCTION blend;
    blend.BlendOp             = AC_SRC_OVER;
    blend.BlendFlags          = 0;
    blend.SourceConstantAlpha = 255;
    blend.AlphaFormat         = AC_SRC_ALPHA;

    AlphaBlend(g_skHdc, (int)x, (int)y, (int)w, (int)h,
               memDC, 0, 0, (int)w, (int)h, blend);

    SelectObject(memDC, oldBitmap);
    DeleteObject(hBitmap);
    DeleteDC(memDC);
#endif
}

// 绘制文本（阶段三：用 SkFontMgr_New_Custom_Directory 加载 Noto Sans SC 后 drawString）
void php_sk_draw_text(Int x, Int y, String text, Int fontSize, Int rgb, Int bold) {
#ifdef USE_SKIA
    fprintf(stderr, "[SK] draw_text x=%d y=%d text='%s' fontSize=%d rgb=0x%X bold=%d canvas=%p\n",
        (int)x, (int)y, text.data() ? text.data() : "(null)", (int)fontSize, (unsigned int)(Int)rgb, (int)bold, g_skCanvas.get());
    if (!g_skCanvas) return;
    if (text.length() == 0) return;
    if (!skEnsureFont()) return;  // 字体未加载 → 静默跳过

    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor(rgbToSkColor(rgb));

    g_skFont.setSize((SkScalar)(int)fontSize);
    g_skFont.setEmbolden((Int)bold != 0);

    // text 是 php::String，用 .data() 取 char*
    g_skCanvas->drawString(text.data(), (SkScalar)(int)x, (SkScalar)(int)y, g_skFont, paint);
#else
    if (!g_skHdc) return;
    if (text.length() == 0) return;
    if ((int)fontSize <= 0) return;

    RECT clipRect;
    int clipType = GetClipBox(g_skHdc, &clipRect);
    if (clipType == NULLREGION) return;
    bool hasClip = (clipType == SIMPLEREGION || clipType == COMPLEXREGION);

    SetTextColor(g_skHdc, (COLORREF)(Int)rgb);
    SetBkMode(g_skHdc, TRANSPARENT);
    HFONT hFont = CreateFont((int)fontSize, 0, 0, 0,
        (Int)bold ? FW_BOLD : FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, "Microsoft YaHei");
    if (hFont == NULL) return;
    HFONT oldFont = (HFONT)SelectObject(g_skHdc, hFont);

    int wlen = MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, NULL, 0);
    if (wlen > 0) {
        std::wstring wtext(wlen, L'\0');
        MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, &wtext[0], wlen);
        int charsToDraw = (wlen > 1) ? (wlen - 1) : 0;

        if (hasClip && clipRect.right > 0) {
            SIZE sz = {0, 0};
            GetTextExtentPoint32W(g_skHdc, wtext.c_str(), charsToDraw, &sz);
            while (charsToDraw > 0 && (int)x + sz.cx > clipRect.right) {
                charsToDraw--;
                GetTextExtentPoint32W(g_skHdc, wtext.c_str(), charsToDraw, &sz);
            }
        }

        if (charsToDraw > 0) {
            TextOutW(g_skHdc, (int)x, (int)y, wtext.c_str(), charsToDraw);
        }
    }
    SelectObject(g_skHdc, oldFont);
    DeleteObject(hFont);
#endif
}

// 入栈裁剪区域
void php_sk_push_clip(Int x, Int y, Int w, Int h) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    g_skCanvas->save();
    g_skCanvas->clipRect(
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h));
#else
    if (!g_skHdc) return;
    SaveDC(g_skHdc);
    HRGN clipRgn = CreateRectRgn((int)x, (int)y, (int)(x + w), (int)(y + h));
    SelectClipRgn(g_skHdc, clipRgn);
    DeleteObject(clipRgn);
#endif
}

// 出栈裁剪区域
void php_sk_pop_clip() {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    g_skCanvas->restore();
#else
    if (!g_skHdc) return;
    RestoreDC(g_skHdc, -1);
#endif
}

// 绘制按钮（背景填充 + 1px 边框）
void php_sk_draw_button(Int x, Int y, Int w, Int h, Int bgColor, Int borderColor) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    // 背景填充
    SkPaint bgPaint;
    bgPaint.setAntiAlias(true);
    bgPaint.setColor(rgbToSkColor(bgColor));
    g_skCanvas->drawRect(
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h),
        bgPaint);
    // 1px 边框
    SkPaint borderPaint;
    borderPaint.setAntiAlias(true);
    borderPaint.setColor(rgbToSkColor(borderColor));
    borderPaint.setStyle(SkPaint::kStroke_Style);
    borderPaint.setStrokeWidth(1.0f);
    SkScalar fx = (SkScalar)(int)x + 0.5f;
    SkScalar fy = (SkScalar)(int)y + 0.5f;
    SkScalar fw = (SkScalar)(int)w - 1.0f;
    SkScalar fh = (SkScalar)(int)h - 1.0f;
    g_skCanvas->drawRect(SkRect::MakeLTRB(fx, fy, fx + fw, fy + fh), borderPaint);
#else
    if (!g_skHdc) return;
    HBRUSH brush = CreateSolidBrush((COLORREF)(Int)bgColor);
    RECT r = {(int)x, (int)y, (int)(x + w), (int)(y + h)};
    FillRect(g_skHdc, &r, brush);
    DeleteObject(brush);
    HPEN pen = CreatePen(PS_SOLID, 1, (COLORREF)(Int)borderColor);
    HPEN oldPen = (HPEN)SelectObject(g_skHdc, pen);
    HBRUSH oldBrush = (HBRUSH)SelectObject(g_skHdc, GetStockObject(NULL_BRUSH));
    Rectangle(g_skHdc, (int)x, (int)y, (int)(x + w), (int)(y + h));
    SelectObject(g_skHdc, oldBrush);
    SelectObject(g_skHdc, oldPen);
    DeleteObject(pen);
#endif
}

// ============================================================
// Task 3.6 — 阶段三新增：窗口尺寸变更时重建 SkCanvas + SkBitmap
// 监听 WM_SIZE 时调用
// ============================================================
void php_sk_resize_context(Int width, Int height) {
    if ((int)width <= 0 || (int)height <= 0) return;
    g_skW = (int)width;
    g_skH = (int)height;
#ifdef USE_SKIA
    if (g_skCanvas
        && g_skSkBitmap.width()  == (int)width
        && g_skSkBitmap.height() == (int)height) {
        return;  // 尺寸未变，跳过重建
    }
    g_skCanvas.reset();
    g_skSkBitmap.reset();
    g_skSkBitmap.allocN32Pixels(g_skW, g_skH);
    g_skCanvas = SkCanvas::MakeRasterDirectN32(
        g_skW, g_skH,
        (SkPMColor*)g_skSkBitmap.getPixels(),
        g_skSkBitmap.rowBytes());
    if (g_skCanvas) {
        g_skCanvas->clear(SK_ColorWHITE);
    }
#endif
}
