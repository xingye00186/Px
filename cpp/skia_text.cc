#include "skia_render.h"

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
    // 阶段四：优先用 DirectWrite 测量字体行高（与浏览器同引擎）
    int dwResult = measureHeightDWrite((int)fontSize, (int)bold);
    if (dwResult > 0) {
        SK_TRACE("[SK] measure_text_height fontSize=%d bold=%d height=%d (DirectWrite)\n", (int)fontSize, (int)bold, dwResult);
        return (Int)dwResult;
    }

#ifdef USE_SKIA
    // Fallback: GDI GetTextMetricsW
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
    SK_TRACE("[SK] measure_text_height fontSize=%d bold=%d height=%d (GDI)\n", (int)fontSize, (int)bold, (int)result);
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
