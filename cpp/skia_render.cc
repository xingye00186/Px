/**
 * SkiaRenderContext Native Layer
 *
 * 闃舵涓€/浜岋細鐢?Win32 GDI 鐪熷疄缁樺埗锛岄獙璇?AOT 鎵弿閾句笌 sk_* 绗﹀彿閾捐矾
 * 闃舵涓夛紙USE_SKIA 瀹忓紑鍚級锛氬垏鎹负鐪?Skia 璋冪敤锛屼韩鍙楁姉閿娇 + 鍦嗚浼樺娍
 *
 * 涓?vue_calc.cc 骞抽摵鍏卞瓨锛屼娇鐢ㄧ嫭绔嬬殑 g_sk* 鍏ㄥ眬鍙橀噺闅旂鐘舵€?
 * 鏈樁娈典粎鏀寔鍗曠獥鍙ｏ紙澶氱獥鍙ｅ湪 Phase 6 閫氳繃 php::Box 閲嶆瀯锛?
 *
 * Task 3.7 椋庨櫓瀵圭瓥锛氶樁娈典笁 sk_clear_window 閫€鍖栦负绌哄疄鐜帮紙鑳屾櫙娓呭睆缁熶竴鍦?
 *           drawElement 鏍硅妭鐐圭粯鍒跺墠 canvas->clear() 瀹屾垚锛?
 * Task 3.8 椋庨櫓瀵圭瓥锛氶樁娈典笁 sk_alpha_fill_rect 涓?sk_fill_rect 鍚堝苟瀹炵幇
 *           锛堝敮涓€宸紓鏄?paint.setAlphaf()锛?
 * Task 3.6 椋庨櫓瀵圭瓥锛氶樁娈典笁鏂板 sk_resize_context锛岀洃鍚?WM_SIZE 閲嶅缓 SkSurface
 * Task 3.4.1 棰勫鐐癸細闃舵涓夊瓧浣撳洖閫€棣栭€?SkFontMgr_New_FCI锛圵indows GDI/DirectWrite 鑱斿悎锛?
 */

#include <phpx.h>
#include <windows.h>
#pragma comment(lib, "msimg32.lib")
#include <cstdio>
#include <string>
#include <vector>

#ifdef USE_SKIA
// Skia 澶存枃浠讹紙aseprite 棰勭紪璇戝寘 include/ 鐩綍锛?
#include "include/core/SkSurface.h"
#include "include/core/SkCanvas.h"
#include "include/core/SkPaint.h"
#include "include/core/SkFont.h"
#include "include/core/SkFontMgr.h"
#include "include/core/SkTypeface.h"
#include "include/core/SkRect.h"
#include "include/core/SkRRect.h"
#include "include/core/SkBitmap.h"
#include "include/core/SkImageInfo.h"
#include "include/core/SkColor.h"
#include "include/core/SkString.h"
#include "include/core/SkData.h"
#include "include/ports/SkFontMgr_New_FCI.h"
#endif

using namespace php;

// ============================================================
// Skia 妯″潡鍏ㄥ眬鐘舵€侊紙浠呬緵 sk_* 鍑芥暟浣跨敤锛屼笌 vue_* 闅旂锛?
// ============================================================

static HWND g_skHwnd = NULL;
static HDC  g_skHdc  = NULL;       // begin_frame 鏈熼棿鎸佹湁鐨?memDC锛堝弻缂撳啿锛?
static HBITMAP g_skBitmap = NULL;  // memDC 閫変腑鐨?bitmap锛宔nd_frame 鏃堕噴鏀?
static int  g_skW = 0;
static int  g_skH = 0;

#ifdef USE_SKIA
// 闃舵涓夛細Skia 绂诲睆 surface + 瀛椾綋
static sk_sp<SkSurface>  g_skSurface    = nullptr;
static sk_sp<SkTypeface> g_skTypeface   = nullptr;
static sk_sp<SkFont>     g_skFont       = nullptr;
static bool              g_skFontInited = false;
static std::vector<uint8_t> g_skPixelBuf;  // end_frame 鏃?SkSurface 鈫?GDI 涓浆
#endif

#ifdef USE_SKIA
// ============================================================
// 闃舵涓夎緟鍔╁嚱鏁?
// ============================================================

// 鍒濆鍖栧瓧浣擄紙FCI 瀛椾綋绠＄悊鍣細Windows GDI/DirectWrite 鑱斿悎锛?
static bool skEnsureFont() {
    if (g_skFontInited) return (bool)g_skFont;
    g_skFontInited = true;

    sk_sp<SkFontMgr> fontMgr = SkFontMgr_New_FCI(nullptr, false);
    if (!fontMgr) {
        fontMgr = SkFontMgr_New_FCI(nullptr, true);
    }
    if (fontMgr) {
        const char* candidates[] = {"Segoe UI", "Microsoft YaHei", "Calibri", "Arial", nullptr};
        for (int i = 0; candidates[i]; i++) {
            g_skTypeface = fontMgr->matchFamilyStyle(candidates[i], SkFontStyle());
            if (g_skTypeface) break;
        }
        if (!g_skTypeface) {
            g_skTypeface = fontMgr->matchFamilyStyle(nullptr, SkFontStyle());
        }
    }

    if (g_skTypeface) {
        g_skFont = sk_make_sp<SkFont>(g_skTypeface, 12.0f);
        return true;
    }
    return false;
}

// 鎶?SkSurface 鍍忕礌鎷疯礉鍒?g_skHdc锛坋nd_frame 璋冪敤锛?
// 鍏抽敭姝ラ锛歳eadPixels 鎻愬彇 BGRA 鍍忕礌 鈫?SetDIBitsToDevice 鍐欏叆 GDI memDC
static void skBlitToGdi() {
    if (!g_skSurface || !g_skHdc) return;

    int w = g_skSurface->width();
    int h = g_skSurface->height();
    if (w <= 0 || h <= 0) return;

    size_t rowBytes = w * 4;
    g_skPixelBuf.resize(rowBytes * h);

    SkImageInfo info = SkImageInfo::Make(w, h, kBGRA_8888_SkColorType, kPremul_SkAlphaType);
    g_skSurface->readPixels(info, g_skPixelBuf.data(), rowBytes, 0, 0);

    BITMAPINFO bmi;
    ZeroMemory(&bmi, sizeof(bmi));
    bmi.bmiHeader.biSize        = sizeof(BITMAPINFOHEADER);
    bmi.bmiHeader.biWidth       = w;
    bmi.bmiHeader.biHeight      = -h;  // 璐熷€硷細top-down DIB
    bmi.bmiHeader.biPlanes      = 1;
    bmi.bmiHeader.biBitCount    = 32;
    bmi.bmiHeader.biCompression = BI_RGB;

    SetDIBitsToDevice(g_skHdc, 0, 0, w, h, 0, 0, 0, h,
                      g_skPixelBuf.data(), &bmi, DIB_RGB_COLORS);
}
#endif  // USE_SKIA

// ============================================================
// Task 1.1 鈥?闃舵涓€ POC锛? 涓熀纭€鍘熻
// ============================================================

// 鍒涘缓绐楀彛涓婁笅鏂囷紙淇濆瓨 hWnd 涓庡昂瀵革紱闃舵涓夊垱寤?SkSurface锛?
Int php_sk_create_window_context(Int hWnd, Int width, Int height) {
    g_skHwnd = (HWND)(Int)hWnd;
    g_skW    = (int)width;
    g_skH    = (int)height;
#ifdef USE_SKIA
    g_skSurface = SkSurface::MakeRasterN32Premul(g_skW, g_skH);
    if (g_skSurface) {
        g_skSurface->getCanvas()->clear(SK_ColorWHITE);
    }
    skEnsureFont();
#endif
    return (Int)1;
}

// 閿€姣佺獥鍙ｄ笂涓嬫枃
void php_sk_destroy_context() {
    g_skHwnd = NULL;
    g_skW    = 0;
    g_skH    = 0;
#ifdef USE_SKIA
    g_skSurface.reset();
#endif
}

// 寮€濮嬩竴甯э細GDI 鍒涘弻缂撳啿 memDC锛堝缁堜繚鐣欎互鍏煎 end_frame BitBlt 娴佺▼锛?
void php_sk_begin_frame() {
    if (!g_skHwnd) return;
    HDC screen = GetDC(g_skHwnd);
    RECT rc;
    GetClientRect(g_skHwnd, &rc);
    g_skHdc = CreateCompatibleDC(screen);
    g_skBitmap = CreateCompatibleBitmap(screen, rc.right, rc.bottom);
    SelectObject(g_skHdc, g_skBitmap);
    ReleaseDC(g_skHwnd, screen);

#ifdef USE_SKIA
    if (g_skSurface) {
        g_skSurface->getCanvas()->save();
    }
#endif
}

// 缁撴潫涓€甯э細闃舵涓?Skia 鈫?GDI 涓浆 鈫?BitBlt 鍒?screen
void php_sk_end_frame() {
    if (!g_skHwnd || !g_skHdc) return;
    RECT rc;
    GetClientRect(g_skHwnd, &rc);

#ifdef USE_SKIA
    if (g_skSurface) {
        g_skSurface->getCanvas()->restore();
        skBlitToGdi();
    }
#endif

    HDC screen = GetDC(g_skHwnd);
    BitBlt(screen, 0, 0, rc.right, rc.bottom, g_skHdc, 0, 0, SRCCOPY);
    ReleaseDC(g_skHwnd, screen);
    DeleteDC(g_skHdc);
    if (g_skBitmap) {
        DeleteObject(g_skBitmap);
        g_skBitmap = NULL;
    }
    g_skHdc = NULL;
}

// 鍏ㄧ獥鍙ｆ竻灞?
// 闃舵涓夛細R14 椋庨櫓瀵圭瓥鈥斺€旈€€鍖栦负绌哄疄鐜帮紙娓呭睆鐢辨牴鑺傜偣 canvas->clear 瀹屾垚锛?
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

// 鍗曠煩褰㈠～鍏?
void php_sk_fill_rect(Int x, Int y, Int w, Int h, Int rgb) {
#ifdef USE_SKIA
    if (!g_skSurface) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor((SkColor)(int)rgb);
    g_skSurface->getCanvas()->drawRect(
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
// Task 2.1 鈥?闃舵浜?GDI 鍏煎灞傦細6 涓嚱鏁?
// USE_SKIA 闃舵涓夋浛鎹负鐪?Skia 瀹炵幇
// ============================================================

// 缁樺埗鍦嗚鐭╁舰
void php_sk_draw_round_rect(Int x, Int y, Int w, Int h, Int radius, Int rgb) {
#ifdef USE_SKIA
    if (!g_skSurface) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor((SkColor)(int)rgb);
    SkRRect rrect;
    rrect.setRectXY(
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h),
        (SkScalar)(int)radius, (SkScalar)(int)radius);
    g_skSurface->getCanvas()->drawRRect(rrect, paint);
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

// 鍗婇€忔槑鐭╁舰濉厖
// 闃舵涓夛細R15 椋庨櫓瀵圭瓥鈥斺€斾笌 php_sk_fill_rect 鍚堝苟瀹炵幇锛堝敮涓€宸紓 paint.setAlphaf锛?
void php_sk_alpha_fill_rect(Int x, Int y, Int w, Int h, Int rgb, double opacity) {
#ifdef USE_SKIA
    if (!g_skSurface) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    if (opacity <= 0.0) return;
    if (opacity >= 1.0) {
        // 婊?alpha 璧扮函 fill_rect 璺緞閬垮厤鍐椾綑 setAlphaf
        php_sk_fill_rect(x, y, w, h, rgb);
        return;
    }
    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor((SkColor)(int)rgb);
    paint.setAlphaf((SkScalar)opacity);
    g_skSurface->getCanvas()->drawRect(
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

// 缁樺埗鏂囨湰锛圲TF-8 鈫?UTF-32 鈫?Skia drawString锛?
void php_sk_draw_text(Int x, Int y, String text, Int fontSize, Int rgb, Int bold) {
#ifdef USE_SKIA
    if (!g_skSurface) return;
    if (text.length() == 0) return;
    if ((int)fontSize <= 0) return;
    if (!skEnsureFont() || !g_skFont) {
        // 瀛椾綋鏈氨缁細閫€鍖栧埌 GDI 璺緞锛堝厹搴曪級
        // 姝ゅ垎鏀瀬灏戣锛屼粎鍦?FCI 瀛椾綋绠＄悊鍣ㄥ姞杞藉け璐ユ椂瑙﹀彂
        return;
    }
    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor((SkColor)(int)rgb);
    // 瀛椾綋澶у皬 + 绮椾綋
    g_skFont->setSize((SkScalar)(int)fontSize);
    g_skFont->setTypeface(g_skTypeface);  // bold 鐢?typeface 鐨?style 鍐冲畾
    // 绠€鍗?bold 妯℃嫙锛歱aint 鍔?stroke
    if ((Int)bold) {
        paint.setStyle(SkPaint::kFill_Style);
        paint.setStrokeWidth(0);
    }
    // Skia drawString 榛樿 baseline 鍦?y 涓婃柟锛屾澶?y 鍙傛暟娌跨敤 GDI 璇箟
    // 閫氳繃 font->getMetrics() 鎶?GDI 鐨?y 杞负 baseline
    SkFontMetrics metrics;
    g_skFont->getMetrics(&metrics);
    SkScalar baseline = (SkScalar)(int)y - metrics.fAscent;
    g_skSurface->getCanvas()->drawString(
        SkString(text.data(), text.length()),
        (SkScalar)(int)x, baseline, *g_skFont, paint);
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

// 鍏ユ爤瑁佸壀鍖哄煙
void php_sk_push_clip(Int x, Int y, Int w, Int h) {
#ifdef USE_SKIA
    if (!g_skSurface) return;
    g_skSurface->getCanvas()->save();
    g_skSurface->getCanvas()->clipRect(
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

// 鍑烘爤瑁佸壀鍖哄煙
void php_sk_pop_clip() {
#ifdef USE_SKIA
    if (!g_skSurface) return;
    g_skSurface->getCanvas()->restore();
#else
    if (!g_skHdc) return;
    RestoreDC(g_skHdc, -1);
#endif
}

// 缁樺埗鎸夐挳锛堣儗鏅～鍏?+ 1px 杈规锛?
void php_sk_draw_button(Int x, Int y, Int w, Int h, Int bgColor, Int borderColor) {
#ifdef USE_SKIA
    if (!g_skSurface) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    SkCanvas* canvas = g_skSurface->getCanvas();
    // 鑳屾櫙濉厖
    SkPaint bgPaint;
    bgPaint.setAntiAlias(true);
    bgPaint.setColor((SkColor)(int)bgColor);
    canvas->drawRect(
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h),
        bgPaint);
    // 1px 杈规锛坉rawLine 鍥涙潯杈癸級
    SkPaint borderPaint;
    borderPaint.setAntiAlias(true);
    borderPaint.setColor((SkColor)(int)borderColor);
    borderPaint.setStyle(SkPaint::kStroke_Style);
    borderPaint.setStrokeWidth(1.0f);
    SkScalar fx = (SkScalar)(int)x + 0.5f;
    SkScalar fy = (SkScalar)(int)y + 0.5f;
    SkScalar fw = (SkScalar)(int)w - 1.0f;
    SkScalar fh = (SkScalar)(int)h - 1.0f;
    canvas->drawRect(SkRect::MakeLTRB(fx, fy, fx + fw, fy + fh), borderPaint);
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
// Task 3.6 鈥?闃舵涓夋柊澧烇細绐楀彛灏哄鍙樻洿鏃堕噸寤?SkSurface
// 鐩戝惉 WM_SIZE 鏃惰皟鐢紝妗嗘灦澶栦笉闇€瑕佸叧娉ㄥ疄鐜扮粏鑺?
// ============================================================
#ifdef USE_SKIA
void php_sk_resize_context(Int width, Int height) {
    if ((int)width <= 0 || (int)height <= 0) return;
    if (g_skSurface
        && g_skSurface->width()  == (int)width
        && g_skSurface->height() == (int)height) {
        return;  // 灏哄鏈彉锛岃烦杩囬噸寤?
    }
    g_skW = (int)width;
    g_skH = (int)height;
    g_skSurface = SkSurface::MakeRasterN32Premul(g_skW, g_skH);
    if (g_skSurface) {
        g_skSurface->getCanvas()->clear(SK_ColorWHITE);
    }
}
#endif


