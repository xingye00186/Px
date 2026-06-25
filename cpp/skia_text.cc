#include "skia_render.h"

/**
 * drawTextDWrite — 用 DirectWrite + 同一字体 GDI 渲染文本。
 * 确保测量（DWrite）和渲染（GDI）使用同一字体族（Segoe UI），
 * 消除字体度量偏差。
 * 返回 true 表示渲染成功，false 回退到 Skia/GDI。
 */
bool drawTextDWrite(HDC hdc, int x, int y, const char* text, int textLen,
                    int fontSize, int color, int bold, const char* fontFamily) {
    if (!ensureDWriteFactory() || !hdc || textLen <= 0 || fontSize <= 0) return false;
    const char* fontName = (fontFamily && fontFamily[0]) ? fontFamily : g_skDefaultFont.c_str();

    // Convert font name UTF-8 -> WCHAR
    int wlen = MultiByteToWideChar(CP_UTF8, 0, fontName, -1, NULL, 0);
    if (wlen <= 0) return false;
    std::wstring wfont(wlen, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, fontName, -1, &wfont[0], wlen);

    // Convert text UTF-8 -> WCHAR  
    int tlen = MultiByteToWideChar(CP_UTF8, 0, text, textLen, NULL, 0);
    if (tlen <= 0) return false;
    std::wstring wtext(tlen, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, text, textLen, &wtext[0], tlen);

    // Create DWrite text format
    IDWriteTextFormat* format = nullptr;
    HRESULT hr = g_dwFactory->CreateTextFormat(
        wfont.c_str(), nullptr,
        (bold != 0) ? DWRITE_FONT_WEIGHT_BOLD : DWRITE_FONT_WEIGHT_REGULAR,
        DWRITE_FONT_STYLE_NORMAL, DWRITE_FONT_STRETCH_NORMAL,
        (FLOAT)fontSize, L"en-US", &format);
    if (FAILED(hr) || !format) return false;

    float estW = (float)fontSize * (float)textLen * 0.6f + 4.0f;
    if (estW < 10.0f) estW = 10.0f;
    float estH = (float)fontSize * 2.0f;

    IDWriteTextLayout* layout = nullptr;
    hr = g_dwFactory->CreateTextLayout(wtext.c_str(), (UINT32)tlen, format, estW, estH, &layout);
    if (FAILED(hr) || !layout) { format->Release(); return false; }

    // Get baseline from DWrite metrics
    DWRITE_TEXT_METRICS metrics;
    layout->GetMetrics(&metrics);

    // Render using GDI with the SAME font name as DWrite measurement
    COLORREF oldColor = SetTextColor(hdc, (COLORREF)color);
    int oldBkMode = SetBkMode(hdc, TRANSPARENT);
    float baseline = (float)fontSize * 0.8f;  // DWrite ascent ~ 80% of fontSize
    int drawY = y + (int)baseline;

    HFONT hFont = CreateFont(fontSize, 0, 0, 0,
        bold ? FW_BOLD : FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, fontName);
    if (hFont) {
        SelectObject(hdc, hFont);
        TextOutA(hdc, x, drawY, text, textLen);
        SelectObject(hdc, GetStockObject(SYSTEM_FONT));
        DeleteObject(hFont);
    }

    SetTextColor(hdc, oldColor);
    SetBkMode(hdc, oldBkMode);
    layout->Release();
    format->Release();
    return true;
}

void php_sk_draw_text(Int x, Int y, String text, Int fontSize, Int rgb, Int bold) {
    // ── 优先：DirectWrite 路径（auto 或 dwrite 模式）──
    if (g_textEngine != 2 && g_textEngine != 3) {
        if (g_skHdc && text.length() > 0) {
            if (drawTextDWrite(g_skHdc, (int)x, (int)y,
                    text.data(), (int)text.length(),
                    (int)fontSize, (int)(Int)rgb, (int)(Int)bold,
                    g_skDefaultFont.c_str())) {
                return;
            }
        }
    }
    if (g_textEngine == 1) return;  // dwrite-only mode, skip fallback

    // ── Fallback: Skia 路径 ──
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if (text.length() == 0) return;
    if (!skEnsureFont()) return;

    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor(rgbToSkColor(rgb));

    g_skFont.setSize((SkScalar)(int)fontSize);

    if ((Int)bold != 0 && g_skTypefaceBold) {
        g_skFont.setTypeface(g_skTypefaceBold);
    } else {
        g_skFont.setTypeface(g_skTypeface);
        g_skFont.setEmbolden((Int)bold != 0);
    }

    SkFontMetrics metrics;
    g_skFont.getMetrics(&metrics);
    SkScalar baselineY = (SkScalar)(int)y - metrics.fAscent;

    SK_TRACE("[SK] draw_text x=%d y=%d baselineY=%.0f text='%s' fontSize=%d rgb=0x%X bold=%d canvas=%p\n",
        (int)x, (int)y, (double)baselineY, text.data() ? text.data() : "(null)",
        (int)fontSize, (unsigned int)(Int)rgb, (int)bold, g_skCanvas.get());

    g_skCanvas->drawString(text.data(), (SkScalar)(int)x, baselineY, g_skFont, paint);
    g_skFont.setTypeface(g_skTypeface);
#else
    // ── Fallback: GDI 路径（不使用 DWrite 时）──
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
    skLoadPrivateFonts();
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
Int php_sk_measure_text_height(Int fontSize, Int bold) {
    int dwResult = measureHeightDWrite((int)fontSize, (int)bold);
    if (dwResult > 0) {
        SK_TRACE("[SK] measure_text_height fontSize=%d bold=%d height=%d (DirectWrite)\n", (int)fontSize, (int)bold, dwResult);
        return (Int)dwResult;
    }

#ifdef USE_SKIA
    skLoadPrivateFonts();
    HDC hdc = GetDC(NULL);
    if (!hdc) return (Int)fontSize;
    HFONT hFont = CreateFont((int)fontSize, 0, 0, 0,
        (Int)bold ? FW_BOLD : FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, g_skDefaultFont.c_str());
    if (!hFont) { ReleaseDC(NULL, hdc); return (Int)fontSize; }
    HFONT oldFont = (HFONT)SelectObject(hdc, hFont);
    TEXTMETRICW tm;
    Int result = (Int)fontSize;
    if (GetTextMetricsW(hdc, &tm)) {
        result = (Int)(tm.tmAscent + tm.tmDescent);
    }
    SelectObject(hdc, oldFont);
    DeleteObject(hFont);
    ReleaseDC(NULL, hdc);
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
