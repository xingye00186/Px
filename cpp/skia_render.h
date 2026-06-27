/**
 * skia_render.h — Skia 渲染模块公共声明
 * 拆分自 skia_render.cc，各模块共享此头文件。
 */
#pragma once

#include <phpx.h>
#include <windows.h>
#include <string>
#include <vector>
#include <memory>
#include <dwrite.h>
#pragma comment(lib, "msimg32.lib")
#pragma comment(lib, "dwrite.lib")

#include <objidl.h>
#include <propidl.h>
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
#include "include/ports/SkFontMgr_directory.h"
#include "include/effects/SkImageFilters.h"
#include "include/effects/SkGradient.h"
#endif

using namespace php;

#define SK_TRACE_ENABLED
#ifdef SK_TRACE_ENABLED
#define SK_TRACE(...) fprintf(stderr, __VA_ARGS__)
#else
#define SK_TRACE(...) ((void)0)
#endif

// ─── 全局状态 ───
extern HWND g_skHwnd;
extern HDC  g_skHdc;
extern HBITMAP g_skBitmap;
extern void*  g_skBits;
extern int  g_skW;
extern int  g_skH;
extern std::string g_skPendingSSPath;
extern ULONG_PTR g_skGdiplusToken;
extern bool g_skGdiplusInited;
extern std::string g_skDefaultFont;

// DirectWrite
// DWrite RenderTarget（真正用 DWrite 绘制到 GDI 兼容表面）
bool ensureDWriteRenderTarget(HDC hdc, int w, int h);
void shutdownDWrite();
extern IDWriteBitmapRenderTarget* g_dwRenderTarget;

extern bool g_dwInitAttempted;
extern IDWriteFactory* g_dwFactory;
bool ensureDWriteFactory();
int measureHeightDWrite(int fontSize, int bold);
int measureWidthDWrite(const char* text, int textLen, int fontSize, int bold);
bool drawTextDWrite(HDC hdc, int x, int y, const char* text, int textLen,
                    int fontSize, int color, int bold, const char* fontFamily);

// 文本引擎选择
extern int g_textEngine;
void php_sk_set_text_engine(String engine);

// GDI 字体加载
extern bool g_skPrivateFontsLoaded;
extern void* g_skFontRegData;
extern void* g_skFontBoldData;
extern DWORD g_skFontRegSize;
extern DWORD g_skFontBoldSize;
void skLoadPrivateFonts();

// 截图
extern std::string g_skPendingSSPath;

#ifdef USE_SKIA
extern SkBitmap  g_skSkBitmap;
extern std::unique_ptr<SkCanvas> g_skCanvas;
extern bool g_skFontInited;
extern sk_sp<SkFontMgr>  g_skFontMgr;
extern sk_sp<SkTypeface> g_skTypeface;
extern sk_sp<SkTypeface> g_skTypefaceBold;
extern SkFont g_skFont;
extern std::vector<uint8_t> g_skPixelBuf;
SkColor rgbToSkColor(Int rgb);
bool skEnsureFont();
#endif
int measureWidthDWrite(const char* text, int textLen, int fontSize, int bold);
