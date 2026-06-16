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

// GDI+ 图片加载（非 Skia 路径）
// 注意：Windows SDK 10.0.26100.0 要求先包含 COM 头文件再包含 gdiplus.h
#include <objidl.h>    // IStream
#include <propidl.h>   // PROPID
#include <gdiplus.h>
#pragma comment(lib, "gdiplus.lib")

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
#include "include/core/SkData.h"
#include "include/core/SkImage.h"
#include "include/ports/SkFontMgr_directory.h"   // SkFontMgr_New_Custom_Directory
#include <cstdio>

#endif

// [SK] trace macro — disable for production; enable by uncommenting the #define below
#define SK_TRACE_ENABLED
#ifdef SK_TRACE_ENABLED
#define SK_TRACE(...) fprintf(stderr, __VA_ARGS__)
#else
#define SK_TRACE(...) ((void)0)
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

// 待保存截图路径（save_screenshot 设置，end_frame 在清理 DC 前处理）
static std::string g_skPendingSSPath = "";

// GDI+ 初始化状态（图片加载需要，非 USE_SKIA 路径）
static ULONG_PTR g_skGdiplusToken = 0;
static bool      g_skGdiplusInited = false;

// 默认字体名（可通过 sk_set_default_font 修改）
// GDI 路径：CreateFont 参数；Skia 路径：skEnsureFont 优先查找
static std::string g_skDefaultFont = "Noto Sans SC";

// GDI 字体加载状态（非 USE_SKIA 路径）：通过 AddFontMemResourceEx 预加载 Noto Sans SC 字体文件
// AddFontMemResourceEx 比 AddFontResourceEx 更可靠，能确保 CreateFont 通过族名找到已加载字体
static bool g_skPrivateFontsLoaded = false;
static void* g_skFontRegData = NULL;   // Regular 字体内存数据
static void* g_skFontBoldData = NULL;   // Bold 字体内存数据
static DWORD g_skFontRegSize = 0;
static DWORD g_skFontBoldSize = 0;

// 在 GDI 路径中预加载 Noto Sans SC 字体（Regular + Bold）到私有字体集合
// 确保 CreateFont("Noto Sans SC", FW_BOLD) 能使用真实的粗体字体文件
static void skLoadPrivateFonts() {
    if (g_skPrivateFontsLoaded) return;
    const char* searchDirs[] = {"cpp/fonts", "fonts"};
    const char* addFontFiles[] = {"NotoSansSC-Regular.ttf", "NotoSansSC-Bold.ttf"};
    void** fontData[] = {&g_skFontRegData, &g_skFontBoldData};
    DWORD* fontSizes[] = {&g_skFontRegSize, &g_skFontBoldSize};
    for (int i = 0; i < 2; i++) {
        for (int f = 0; f < 2; f++) {
            if (*fontData[f] != NULL) continue;  // 已加载
            std::string path = std::string(searchDirs[i]) + "/" + addFontFiles[f];
            HANDLE hFile = CreateFileA(path.c_str(), GENERIC_READ, FILE_SHARE_READ, NULL,
                OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, NULL);
            if (hFile != INVALID_HANDLE_VALUE) {
                *fontSizes[f] = GetFileSize(hFile, NULL);
                if (*fontSizes[f] > 0) {
                    *fontData[f] = malloc(*fontSizes[f]);
                    DWORD bytesRead = 0;
                    ReadFile(hFile, *fontData[f], *fontSizes[f], &bytesRead, NULL);
                }
                CloseHandle(hFile);
                
                if (*fontData[f] != NULL) {
                    DWORD fontCount = 0;
                    HANDLE hFont = AddFontMemResourceEx(*fontData[f], *fontSizes[f], NULL, &fontCount);
                    SK_TRACE("[SK] AddFontMemResourceEx('%s') fontCount=%d\n", path.c_str(), (int)fontCount);
                }
            } else {
                SK_TRACE("[SK] AddFontMemResourceEx: file not found '%s'\n", path.c_str());
            }
        }
    }
    // AddFontResourceEx 配合 FR_PRIVATE 注册字体，确保 CreateFont 能通过族名找到
    // AddFontMemResourceEx 对某些环境可能注册名称不完整
    // 支持两种路径：cpp/fonts/（开发时从项目根目录运行）和 fonts/（打包后从 bin/ 运行）
    const char* fontSearchDirs[] = {"cpp/fonts", "fonts"};
    for (int d = 0; d < 2; d++) {
        for (int f = 0; f < 2; f++) {
            std::string fontPath = std::string(fontSearchDirs[d]) + "/" + addFontFiles[f];
            int res = AddFontResourceEx(fontPath.c_str(), FR_PRIVATE, 0);
            SK_TRACE("[SK] AddFontResourceEx('%s') result=%d\n", fontPath.c_str(), res);
        }
    }
    g_skPrivateFontsLoaded = true;
}

// 设置默认字体名（C++ 编译后生效）
void php_sk_set_default_font(String fontFamily) {
    if (fontFamily.length() > 0) {
        g_skDefaultFont = std::string(fontFamily.data(), fontFamily.length());
        SK_TRACE("[SK] set_default_font '%s'\n", g_skDefaultFont.c_str());
    }
}

#ifdef USE_SKIA
// 阶段三：Skia 离屏位图 + canvas
static SkBitmap  g_skSkBitmap;             // 后端像素缓冲
static std::unique_ptr<SkCanvas> g_skCanvas;  // 绘制 canvas
static bool              g_skFontInited = false;
static sk_sp<SkFontMgr>  g_skFontMgr;       // 阶段三：用 Custom_Directory 扫描 fonts 目录
static sk_sp<SkTypeface> g_skTypeface;      // 从 FontMgr 加载的 Regular typeface
static sk_sp<SkTypeface> g_skTypefaceBold;   // 从 FontMgr 加载的 Bold typeface
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
// 字体路径使用相对路径，支持两种运行场景：
//   - cpp/fonts/  : 开发时从项目根目录运行
//   - fonts/      : 打包后从 bin/ 目录运行
static bool skEnsureFont() {
    if (g_skFontInited && g_skTypeface) {
        return true;  // 已加载，复用
    }
    g_skFontInited = true;

    const char* searchDirs[] = {"cpp/fonts", "fonts"};
    const char* fontName     = "NotoSansSC-Regular.ttf";
    const char* boldFontName = "NotoSansSC-Bold.ttf";

    for (int i = 0; i < 2; i++) {
        g_skFontMgr = SkFontMgr_New_Custom_Directory(searchDirs[i]);
        if (!g_skFontMgr) continue;
        std::string fullPath = std::string(searchDirs[i]) + "/" + fontName;
        g_skTypeface = g_skFontMgr->makeFromFile(fullPath.c_str());
        if (g_skTypeface) {
            // 预加载粗体字体文件，确保测宽与绘制使用真实粗体字形
            std::string boldPath = std::string(searchDirs[i]) + "/" + boldFontName;
            g_skTypefaceBold = g_skFontMgr->makeFromFile(boldPath.c_str());
            break;
        }
    }

    if (!g_skTypeface) {
        // Fallback：尝试标准系统字体
        g_skFontMgr = SkFontMgr_New_Custom_Directory("cpp/fonts");
        if (g_skFontMgr) {
            g_skTypeface = g_skFontMgr->makeFromFile("C:/Windows/Fonts/msyh.ttc");
        }
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
    SK_TRACE("[SK] create_window_context hwnd=%p w=%d h=%d\n", g_skHwnd, g_skW, g_skH);

    // GDI 路径：预加载 Noto Sans SC 字体（Regular + Bold）
    // 确保后续 CreateFont("Noto Sans SC", FW_BOLD) 使用真实粗体字体
    skLoadPrivateFonts();
    SK_TRACE("[SK] private fonts loaded (gdi path)\n");

    // GDI+ 初始化（图片加载需要）
    if (!g_skGdiplusInited) {
        Gdiplus::GdiplusStartupInput gdiplusStartupInput;
        Gdiplus::GdiplusStartup(&g_skGdiplusToken, &gdiplusStartupInput, NULL);
        g_skGdiplusInited = true;
        SK_TRACE("[SK] GDI+ initialized\n");
    }

#ifdef USE_SKIA
    g_skSkBitmap.allocN32Pixels(g_skW, g_skH);
    g_skCanvas = SkCanvas::MakeRasterDirectN32(
        g_skW, g_skH,
        (SkPMColor*)g_skSkBitmap.getPixels(),
        g_skSkBitmap.rowBytes());
    SK_TRACE("[SK] canvas=%p\n", g_skCanvas.get());
    if (g_skCanvas) {
        g_skCanvas->clear(SK_ColorWHITE);
    }
    bool fontOk = skEnsureFont();
    SK_TRACE("[SK] skEnsureFont=%d fontMgr=%p typeface=%p\n", fontOk, g_skFontMgr.get(), g_skTypeface.get());
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
    // GDI+ 关闭
    if (g_skGdiplusInited) {
        Gdiplus::GdiplusShutdown(g_skGdiplusToken);
        g_skGdiplusInited = false;
        SK_TRACE("[SK] GDI+ shutdown\n");
    }
}

// 开始一帧：GDI 创双缓冲 memDC（headless 时用桌面 DC 创建兼容内存 DC）
void php_sk_begin_frame() {
    HDC screen = g_skHwnd ? GetDC(g_skHwnd) : GetDC(NULL);
    int w = g_skW, h = g_skH;
    g_skHdc = CreateCompatibleDC(screen);
    g_skBitmap = CreateCompatibleBitmap(screen, w, h);
    SelectObject(g_skHdc, g_skBitmap);
    if (g_skHwnd) {
        ReleaseDC(g_skHwnd, screen);
    } else {
        ReleaseDC(NULL, screen);
    }
    SK_TRACE("[SK] begin_frame w=%d h=%d hdc=%p bmp=%p%s\n", w, h, g_skHdc, g_skBitmap, g_skHwnd ? "" : " (headless)");

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
    if (!g_skHdc) {
        SK_TRACE("[SK] end_frame SKIP (hdc=%p)\n", g_skHdc);
        return;
    }
    int w = g_skW, h = g_skH;
#ifdef USE_SKIA
    SK_TRACE("[SK] end_frame BEGIN w=%d h=%d hdc=%p canvas=%p\n", w, h, g_skHdc, g_skCanvas.get());
    if (g_skCanvas) {
        g_skCanvas->restore();
        SK_TRACE("[SK] end_frame after restore\n");
        skBlitToGdi();
        SK_TRACE("[SK] end_frame after skBlitToGdi\n");
    }
#endif

    if (g_skHwnd) {
        HDC screen = GetDC(g_skHwnd);
        SK_TRACE("[SK] end_frame screen=%p\n", screen);
        if (screen) {
            BOOL ok = BitBlt(screen, 0, 0, w, h, g_skHdc, 0, 0, SRCCOPY);
            SK_TRACE("[SK] end_frame BitBlt ok=%d\n", ok);
            ReleaseDC(g_skHwnd, screen);
        }
    } else {
        SK_TRACE("[SK] end_frame SKIP BitBlt (headless)\n");
    }
    // 保存待处理截图（在清理 DC 之前，从 g_skHdc 直接读取像素）
    if (!g_skPendingSSPath.empty()) {
        std::string ssPath = g_skPendingSSPath;
        g_skPendingSSPath.clear();
        int wideLen = MultiByteToWideChar(CP_UTF8, 0, ssPath.data(), (int)ssPath.length(), NULL, 0);
        if (wideLen > 0) {
            std::wstring widePath(wideLen, L'\0');
            MultiByteToWideChar(CP_UTF8, 0, ssPath.data(), (int)ssPath.length(), &widePath[0], wideLen);
            HDC hdcMem = CreateCompatibleDC(g_skHdc);
            HBITMAP hBitmap = CreateCompatibleBitmap(g_skHdc, w, h);
            HBITMAP hOld = (HBITMAP)SelectObject(hdcMem, hBitmap);
            BitBlt(hdcMem, 0, 0, w, h, g_skHdc, 0, 0, SRCCOPY);
            Gdiplus::Bitmap gdiBmp(hBitmap, NULL);
            CLSID pngClsid = {};
            UINT numEncoders = 0, encSize = 0;
            Gdiplus::GetImageEncodersSize(&numEncoders, &encSize);
            if (encSize > 0) {
                Gdiplus::ImageCodecInfo* encoders = (Gdiplus::ImageCodecInfo*)malloc(encSize);
                if (encoders) {
                    Gdiplus::GetImageEncoders(numEncoders, encSize, encoders);
                    for (UINT i = 0; i < numEncoders; i++) {
                        if (wcscmp(encoders[i].MimeType, L"image/png") == 0) {
                            pngClsid = encoders[i].Clsid;
                            break;
                        }
                    }
                    free(encoders);
                }
            }
            Gdiplus::Status status = gdiBmp.Save(widePath.c_str(), &pngClsid, NULL);
            SK_TRACE("[SK] save_screenshot %s (gdi): status=%d\n", ssPath.c_str(), (int)status);
            SelectObject(hdcMem, hOld);
            DeleteObject(hBitmap);
            DeleteDC(hdcMem);
        }
    }
    DeleteDC(g_skHdc);
    if (g_skBitmap) {
        DeleteObject(g_skBitmap);
        g_skBitmap = NULL;
    }
    g_skHdc = NULL;
    SK_TRACE("[SK] end_frame DONE\n");
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
        SK_TRACE("[SK] fill_rect #%d x=%d y=%d w=%d h=%d rgb=0x%X\n", fillRectCount, (int)x, (int)y, (int)w, (int)h, (unsigned)rgb);
    }
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    SkPaint paint;
    // 对细矩形（1-2px）禁用抗锯齿，保持线条清晰
    // 1px 分隔线/边框在 setAntiAlias(true) 下会模糊扩散至 ~3px
    paint.setAntiAlias((int)w > 2 && (int)h > 2);
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
    // 对细矩形（1-2px）禁用抗锯齿，保持线条清晰
    paint.setAntiAlias((int)w > 2 && (int)h > 2);
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
// PHP 传入的 Y 为 text-top 坐标，Skia drawString 需要 baseline → 内部用 font metrics 转换
void php_sk_draw_text(Int x, Int y, String text, Int fontSize, Int rgb, Int bold) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if (text.length() == 0) return;
    if (!skEnsureFont()) return;  // 字体未加载 → 静默跳过

    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor(rgbToSkColor(rgb));

    g_skFont.setSize((SkScalar)(int)fontSize);

    // 粗体：使用真实粗体字体文件（NotoSansSC-Bold.ttf），而非 setEmbolden 模拟
    // setEmbolden 只改变笔画粗细但不改 glyph advance，导致测宽与绘制不一致
    if ((Int)bold != 0 && g_skTypefaceBold) {
        g_skFont.setTypeface(g_skTypefaceBold);
    } else {
        g_skFont.setTypeface(g_skTypeface);
        g_skFont.setEmbolden((Int)bold != 0);
    }

    // PHP convention: Y = text-top; Skia drawString: Y = baseline
    // 用 font metrics 将 Y 从 text-top 转换为 baseline
    SkFontMetrics metrics;
    g_skFont.getMetrics(&metrics);
    SkScalar baselineY = (SkScalar)(int)y - metrics.fAscent;  // fAscent 为负值

    SK_TRACE("[SK] draw_text x=%d y=%d baselineY=%.0f text='%s' fontSize=%d rgb=0x%X bold=%d canvas=%p\n",
        (int)x, (int)y, (double)baselineY, text.data() ? text.data() : "(null)", (int)fontSize, (unsigned int)(Int)rgb, (int)bold, g_skCanvas.get());

    // text 是 php::String，用 .data() 取 char*
    g_skCanvas->drawString(text.data(), (SkScalar)(int)x, baselineY, g_skFont, paint);

    // 恢复默认字体
    g_skFont.setTypeface(g_skTypeface);
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
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, g_skDefaultFont.c_str());
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

// 精确测量文本宽度（用于 line-clamp 行拆分）
Int php_sk_measure_text_width(String text, Int fontSize, Int bold) {
    if (text.length() == 0) return 0;
#ifdef USE_SKIA
    // 使用 GDI GetTextExtentPoint32W 测量文本宽度以匹配浏览器行为
    // （DirectWrite 与 GDI 的文本测量更接近，而 Skia/FreeType 测量偏宽）
    // Skia 仍用于文本绘制，仅测量走 GDI 以保证布局一致性
    skLoadPrivateFonts();
    HDC hdc = GetDC(NULL);
    if (!hdc) return 0;
    HFONT hFont = CreateFont((int)fontSize, 0, 0, 0,
        (Int)bold ? FW_BOLD : FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, g_skDefaultFont.c_str());
    if (!hFont) { ReleaseDC(NULL, hdc); return 0; }
    HFONT oldFont = (HFONT)SelectObject(hdc, hFont);
    // 调试：确认 GDI 实际使用的字体
    char faceName[128];
    if (GetTextFaceA(hdc, 128, faceName)) {
        SK_TRACE("[SK] GDI face='%s' fontSize=%d bold=%d\n", faceName, (int)fontSize, (int)bold);
    }
    int wlen = MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, NULL, 0);
    Int result = 0;
    if (wlen > 0) {
        std::wstring wtext(wlen, L'\0');
        MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, &wtext[0], wlen);
        SIZE sz = {0, 0};
        GetTextExtentPoint32W(hdc, wtext.c_str(), (wlen > 1) ? (wlen - 1) : 0, &sz);
        result = (Int)sz.cx;
    }
    SelectObject(hdc, oldFont);
    DeleteObject(hFont);
    ReleaseDC(NULL, hdc);
    SK_TRACE("[SK] measure_text (GDI) text='%s' fontSize=%d bold=%d width=%d\n", text.data(), (int)fontSize, (int)bold, (int)result);
    return result;
#else
    HDC hdc = GetDC(NULL);
    if (!hdc) return 0;
    HFONT hFont = CreateFont((int)fontSize, 0, 0, 0,
        (Int)bold ? FW_BOLD : FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, g_skDefaultFont.c_str());
    if (!hFont) { ReleaseDC(NULL, hdc); return 0; }
    HFONT oldFont = (HFONT)SelectObject(hdc, hFont);
    int wlen = MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, NULL, 0);
    Int result = 0;
    if (wlen > 0) {
        std::wstring wtext(wlen, L'\0');
        MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, &wtext[0], wlen);
        SIZE sz = {0, 0};
        GetTextExtentPoint32W(hdc, wtext.c_str(), (wlen > 1) ? (wlen - 1) : 0, &sz);
        result = (Int)sz.cx;
    }
    SelectObject(hdc, oldFont);
    DeleteObject(hFont);
    ReleaseDC(NULL, hdc);
    return result;
#endif
}

// 精确测量文本总高度（ascent + descent），用于垂直居中
// 返回文本在给定 fontSize 下的像素高度
Int php_sk_measure_text_height(Int fontSize, Int bold) {
#ifdef USE_SKIA
    skLoadPrivateFonts();
    if (!g_skFont.getTypeface()) return (Int)fontSize;
    g_skFont.setSize((SkScalar)(int)fontSize);
    if ((Int)bold != 0 && g_skTypefaceBold) {
        g_skFont.setTypeface(g_skTypefaceBold);
    } else {
        g_skFont.setTypeface(g_skTypeface);
    }
    SkFontMetrics metrics;
    g_skFont.getMetrics(&metrics);
    SkScalar totalHeight = -metrics.fAscent + metrics.fDescent;
    g_skFont.setTypeface(g_skTypeface);
    Int result = (Int)totalHeight;
    if (result <= 0) result = (Int)fontSize;
    SK_TRACE("[SK] measure_text_height fontSize=%d bold=%d height=%d\n", (int)fontSize, (int)bold, (int)result);
    return result;
#else
    if (!g_skHdc) return (Int)fontSize;
    HFONT hFont = CreateFont((int)fontSize, 0, 0, 0,
        (Int)bold ? FW_BOLD : FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, g_skDefaultFont.c_str());
    if (!hFont) return (Int)fontSize;
    HFONT oldFont = (HFONT)SelectObject(g_skHdc, hFont);
    TEXTMETRICW tm;
    Int result = (Int)fontSize;
    if (GetTextMetricsW(g_skHdc, &tm)) {
        result = (Int)(tm.tmAscent + tm.tmDescent);
    }
    SelectObject(g_skHdc, oldFont);
    DeleteObject(hFont);
    return result;
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
    ExtSelectClipRgn(g_skHdc, clipRgn, RGN_AND);
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

// ============================================================
// WM_PAINT 处理：将缓存 SkBitmap 内容 blit 到屏幕 DC
// 修复最小化/恢复后白屏问题
// ============================================================
Int php_sk_handle_paint(Int hdc) {
#ifdef USE_SKIA
    if (!g_skSkBitmap.getPixels()) return 0;
    int w = g_skSkBitmap.width();
    int h = g_skSkBitmap.height();
    if (w <= 0 || h <= 0) return 0;

    size_t rowBytes = w * 4;
    g_skPixelBuf.resize(rowBytes * h);

    SkImageInfo info = SkImageInfo::Make(w, h, kBGRA_8888_SkColorType, kPremul_SkAlphaType);
    g_skSkBitmap.readPixels(info, g_skPixelBuf.data(), rowBytes, 0, 0);

    BITMAPINFO bmi;
    ZeroMemory(&bmi, sizeof(bmi));
    bmi.bmiHeader.biSize        = sizeof(BITMAPINFOHEADER);
    bmi.bmiHeader.biWidth       = w;
    bmi.bmiHeader.biHeight      = -h;
    bmi.bmiHeader.biPlanes      = 1;
    bmi.bmiHeader.biBitCount    = 32;
    bmi.bmiHeader.biCompression = BI_RGB;

    SetDIBitsToDevice((HDC)hdc, 0, 0, w, h, 0, 0, 0, h,
                      g_skPixelBuf.data(), &bmi, DIB_RGB_COLORS);
    return 1;
#else
    (void)hdc;
    return 0;
#endif
}

// ============================================================
// 图片加载（双路径：USE_SKIA → SkImage, 非USE_SKIA → GDI+）
// ============================================================

// 加载图片文件
// 返回句柄（Int 伪装指针），0 = 失败
// USE_SKIA 路径：返回 sk_sp<SkImage>::release()
// 非 USE_SKIA 路径：返回 Gdiplus::Image*
Int php_sk_load_image(String path) {
    if (path.length() == 0) return 0;

#ifdef USE_SKIA
    // 阶段三：Skia 原生图片解码
    sk_sp<SkData> data = SkData::MakeFromFileName(path.data());
    if (!data) {
        SK_TRACE("[SK] load_image FAIL: cannot read '%s'\n", path.data());
        return 0;
    }
    sk_sp<SkImage> img = SkImages::DeferredFromEncodedData(std::move(data));
    if (!img) {
        SK_TRACE("[SK] load_image FAIL: decode failed '%s'\n", path.data());
        return 0;
    }
    // release() → 调用方持有一份 ref，free 时 unref()
    return (Int)img.release();
#else
    // 阶段二：GDI+ 图片加载（UTF-8 → WCHAR → FromFile）
    int wlen = MultiByteToWideChar(CP_UTF8, 0, path.data(), -1, NULL, 0);
    if (wlen <= 0) return 0;
    std::wstring wpath(wlen, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, path.data(), -1, &wpath[0], wlen);

    Gdiplus::Image* img = Gdiplus::Image::FromFile(wpath.c_str());
    if (!img || img->GetLastStatus() != Gdiplus::Ok) {
        SK_TRACE("[SK] load_image FAIL: GDI+ load failed '%s'\n", path.data());
        delete img;
        return 0;
    }
    SK_TRACE("[SK] load_image OK '%s' -> %p\n", path.data(), (void*)img);
    return (Int)img;
#endif
}

// 绘制图片（填充到指定矩形）
void php_sk_draw_image(Int handle, Int x, Int y, Int w, Int h) {
    if (handle == 0) return;
    if ((int)w <= 0 || (int)h <= 0) return;

#ifdef USE_SKIA
    SkImage* image = reinterpret_cast<SkImage*>((int)handle);
    if (!image || !g_skCanvas) return;
    SkPaint paint;
    paint.setAntiAlias(true);
    g_skCanvas->drawImageRect(
        image,
        SkRect::MakeWH((SkScalar)image->width(), (SkScalar)image->height()),
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h),
        SkSamplingOptions(SkFilterMode::kLinear),
        &paint,
        SkCanvas::kFast_SrcRectConstraint);
#else
    Gdiplus::Image* image = reinterpret_cast<Gdiplus::Image*>((int)handle);
    if (!image || !g_skHdc) return;
    Gdiplus::Graphics gfx(g_skHdc);
    gfx.DrawImage(image, (int)x, (int)y, (int)w, (int)h);
#endif
}

// 释放图片句柄
void php_sk_free_image(Int handle) {
    if (handle == 0) return;

#ifdef USE_SKIA
    SkImage* image = reinterpret_cast<SkImage*>((int)handle);
    image->unref();  // 释放 sk_sp::release() 转交的引用
    SK_TRACE("[SK] free_image %p (SkImage)\n", (void*)image);
#else
    Gdiplus::Image* image = reinterpret_cast<Gdiplus::Image*>((int)handle);
    delete image;
    SK_TRACE("[SK] free_image %p (GDI+ Image)\n", (void*)image);
#endif
}

// ============================================================
// 延迟保存截图：仅存储路径，由 end_frame 在清理 DC 前执行
void php_sk_save_screenshot(String path) {
    g_skPendingSSPath = std::string(path.data(), path.length());
    SK_TRACE("[SK] save_screenshot deferred: %s\n", path.data());
}
