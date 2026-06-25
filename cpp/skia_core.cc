#include "skia_render.h"

Int php_sk_create_window_context(Int hWnd, Int width, Int height) {
    g_skHwnd = (HWND)(Int)hWnd;
    g_skW    = (int)width;
    g_skH    = (int)height;
    SK_TRACE("[SK] create_window_context hwnd=%p w=%d h=%d\n", g_skHwnd, g_skW, g_skH);

    // GDI 路径：预加载 Noto Sans SC 字体（Regular + Bold�?
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

// 开始一帧：GDI 创双缓冲 memDC（headless 时用桌面 DC 创建兼容内存 DC�?
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
        // 每帧开�?clear 画布（避免残留上一�?+ 替代 sk_clear_window 退化为空）
        g_skCanvas->clear(SK_ColorWHITE);
    }
#endif
}

// 结束一帧：阶段�?Skia �?GDI 中转 �?BitBlt �?screen
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
    // 保存待处理截图（在清�?DC 之前，从 g_skHdc 直接读取像素�?
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

// 全窗口清�?
// 阶段三：R14 风险对策——退化为空实现（清屏由根节点 canvas->clear 完成�?
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

// 单矩形填�?
void php_sk_fill_rect(Int x, Int y, Int w, Int h, Int rgb) {
    static int fillRectCount = 0;
    if (++fillRectCount <= 20 || fillRectCount % 20 == 0) {
        SK_TRACE("[SK] fill_rect #%d x=%d y=%d w=%d h=%d rgb=0x%X\n", fillRectCount, (int)x, (int)y, (int)w, (int)h, (unsigned)rgb);
    }
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    SkPaint paint;
    // 对细矩形�?-2px）禁用抗锯齿，保持线条清�?
    // 1px 分隔�?边框�?setAntiAlias(true) 下会模糊扩散�?~3px
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
// Task 2.1 �?阶段�?GDI 兼容层：6 个函�?
// USE_SKIA 阶段三替换为�?Skia 实现
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

// 绘制圆角矩形（独立XY半径�?
void php_sk_draw_round_rect_xy(Int x, Int y, Int w, Int h, Int rx, Int ry, Int rgb) {
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
        (SkScalar)(int)rx, (SkScalar)(int)ry);
    g_skCanvas->drawRRect(rrect, paint);
#else
    (void)ry;
    php_sk_draw_round_rect(x, y, w, h, rx, rgb);
#endif
}

// 绘制阴影（带圆角 + 高斯模糊�?
void php_sk_shadow_round_rect(Int x, Int y, Int w, Int h, Int radius, Int blur, Int rgb, double opacity) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    if (opacity <= 0.0) return;
    float sigma = (float)(int)blur * 0.333f;
    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor(rgbToSkColor(rgb));
    paint.setAlphaf((SkScalar)opacity);
    if (sigma >= 0.5f) {
        paint.setImageFilter(SkImageFilters::Blur(sigma, sigma, SkTileMode::kDecal, nullptr));
    }
    int r = (int)radius;
    if (r > 0) {
        SkRRect rrect;
        rrect.setRectXY(
            SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                             (SkScalar)(int)w, (SkScalar)(int)h),
            (SkScalar)r, (SkScalar)r);
        g_skCanvas->drawRRect(rrect, paint);
    } else {
        g_skCanvas->drawRect(
            SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                             (SkScalar)(int)w, (SkScalar)(int)h),
            paint);
    }
#else
    (void)blur;
    (void)radius;
    if (!g_skHdc) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    int alpha = (int)(opacity * 255.0);
    if (alpha >= 255 || alpha <= 0) return;
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

// 绘制阴影（独立XY半径 + 高斯模糊�?
void php_sk_shadow_round_rect_xy(Int x, Int y, Int w, Int h, Int rx, Int ry, Int blur, Int rgb, double opacity) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    if (opacity <= 0.0) return;
    float sigma = (float)(int)blur * 0.333f;
    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor(rgbToSkColor(rgb));
    paint.setAlphaf((SkScalar)opacity);
    if (sigma >= 0.5f) {
        paint.setImageFilter(SkImageFilters::Blur(sigma, sigma, SkTileMode::kDecal, nullptr));
    }
    SkRRect rrect;
    rrect.setRectXY(
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h),
        (SkScalar)(int)rx, (SkScalar)(int)ry);
    g_skCanvas->drawRRect(rrect, paint);
#else
    (void)blur;
    (void)rx;
    (void)ry;
    if (!g_skHdc) return;
    php_sk_shadow_round_rect(x, y, w, h, rx, blur, rgb, opacity);
#endif
}

// 线性渐变矩形填充（Skia 路径：使�?SkGradientShader�?
// angle: CSS 角度�?=向上�?0=向右�?80=向下�?70=向左�?
// color1/color2: BGR 格式颜色�?
// radius: 圆角半径�?=直角�?
void php_sk_fill_gradient_rect(Int x, Int y, Int w, Int h, Int angle, Int color1, Int color2, Int radius) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    
    // CSS 角度转换为数学角�?
    // CSS: 0deg=向上, 90deg=向右
    // Math: 0°=向右, 90°=向上
    // CSS θ �?math: 90° - θ
    double rad = (90.0 - (double)(int)angle) * M_PI / 180.0;
    
    // 从矩形中心出发的梯度线，长度覆盖对角�?
    double cx = (double)(int)x + (double)(int)w / 2.0;
    double cy = (double)(int)y + (double)(int)h / 2.0;
    double r = sqrt((double)(int)w * (double)(int)w + (double)(int)h * (double)(int)h) / 2.0;
    
    SkPoint pts[2] = {
        { (SkScalar)(cx - cos(rad) * r), (SkScalar)(cy - sin(rad) * r) },
        { (SkScalar)(cx + cos(rad) * r), (SkScalar)(cy + sin(rad) * r) }
    };
    
    // Convert BGR ints to SkColor4f (float [0-1] RGBA)
    auto toSkColor4f = [](int bgr) -> SkColor4f {
        // Px BGR int format: bits [23:16]=B, [15:8]=G, [7:0]=R
        return SkColor4f{
            (float)(bgr & 0xFF) / 255.0f,             // R from low byte
            (float)((bgr >> 8) & 0xFF) / 255.0f,       // G from middle byte
            (float)((bgr >> 16) & 0xFF) / 255.0f,      // B from high byte
            1.0f
        };
    };
    SkColor4f color4f[2] = {
        toSkColor4f((int)color1),
        toSkColor4f((int)color2)
    };
    
    SkPaint paint;
    paint.setAntiAlias(true);
    
    SkGradient::Colors gradColors(SkSpan<const SkColor4f>(color4f, 2), SkTileMode::kClamp);
    SkGradient grad(gradColors, {});
    paint.setShader(SkShaders::LinearGradient(pts, grad));
    
    int rr = (int)radius;
    if (rr > 0) {
        SkRRect rrect;
        rrect.setRectXY(
            SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                             (SkScalar)(int)w, (SkScalar)(int)h),
            (SkScalar)rr, (SkScalar)rr);
        g_skCanvas->drawRRect(rrect, paint);
    } else {
        g_skCanvas->drawRect(
            SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                             (SkScalar)(int)w, (SkScalar)(int)h),
            paint);
    }
#else
    (void)angle;
    (void)radius;
    // GDI fallback: draw with color1 as solid (simple approximation)
    php_sk_fill_rect(x, y, w, h, color1);
#endif
}

// 线性渐变矩形填充（独立XY半径�?
void php_sk_fill_gradient_rect_xy(Int x, Int y, Int w, Int h, Int angle, Int color1, Int color2, Int rx, Int ry) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    
    double rad = (90.0 - (double)(int)angle) * M_PI / 180.0;
    double cx = (double)(int)x + (double)(int)w / 2.0;
    double cy = (double)(int)y + (double)(int)h / 2.0;
    double r = sqrt((double)(int)w * (double)(int)w + (double)(int)h * (double)(int)h) / 2.0;
    
    SkPoint pts[2] = {
        { (SkScalar)(cx - cos(rad) * r), (SkScalar)(cy - sin(rad) * r) },
        { (SkScalar)(cx + cos(rad) * r), (SkScalar)(cy + sin(rad) * r) }
    };
    
    auto toSkColor4f = [](int bgr) -> SkColor4f {
        return SkColor4f{
            (float)(bgr & 0xFF) / 255.0f,
            (float)((bgr >> 8) & 0xFF) / 255.0f,
            (float)((bgr >> 16) & 0xFF) / 255.0f,
            1.0f
        };
    };
    SkColor4f color4f[2] = {
        toSkColor4f((int)color1),
        toSkColor4f((int)color2)
    };
    
    SkPaint paint;
    paint.setAntiAlias(true);
    SkGradient::Colors gradColors(SkSpan<const SkColor4f>(color4f, 2), SkTileMode::kClamp);
    SkGradient grad(gradColors, {});
    paint.setShader(SkShaders::LinearGradient(pts, grad));
    
    int rrx = (int)rx, rry = (int)ry;
    if (rrx > 0 || rry > 0) {
        SkRRect rrect;
        rrect.setRectXY(
            SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                             (SkScalar)(int)w, (SkScalar)(int)h),
            (SkScalar)rrx, (SkScalar)rry);
        g_skCanvas->drawRRect(rrect, paint);
    } else {
        g_skCanvas->drawRect(
            SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                             (SkScalar)(int)w, (SkScalar)(int)h),
            paint);
    }
#else
    (void)angle;
    (void)rx;
    (void)ry;
    php_sk_fill_rect(x, y, w, h, color1);
#endif
}

// 半透明矩形填充
// 阶段三：R15 风险对策——与 php_sk_fill_rect 合并实现（唯一差异 paint.setAlphaf�?
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
    // 对细矩形�?-2px）禁用抗锯齿，保持线条清�?
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


// 绘制文本（阶段三：用 SkFontMgr_New_Custom_Directory 加载 Noto Sans SC �?drawString�?
// PHP 传入�?Y �?text-top 坐标，Skia drawString 需�?baseline �?内部�?font metrics 转换

// ─── 裁剪区域（clip）───
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

void php_sk_push_clip_rrect(Int x, Int y, Int w, Int h, Int radius) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    g_skCanvas->save();
    SkRRect clipRRect;
    clipRRect.setRectXY(
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h),
        (SkScalar)(int)radius, (SkScalar)(int)radius);
    g_skCanvas->clipRRect(clipRRect, SkClipOp::kIntersect, true);
#else
    (void)radius;
    php_sk_push_clip(x, y, w, h);
#endif
}

void php_sk_pop_clip() {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    g_skCanvas->restore();
#else
    if (!g_skHdc) return;
    RestoreDC(g_skHdc, -1);
#endif
}

// ─── 绘制按钮 ───
void php_sk_draw_button(Int x, Int y, Int w, Int h, Int bgColor, Int borderColor) {
#ifdef USE_SKIA
    if (!g_skCanvas) return;
    if ((int)w <= 0 || (int)h <= 0) return;
    SkPaint bgPaint;
    bgPaint.setAntiAlias(true);
    bgPaint.setColor(rgbToSkColor(bgColor));
    g_skCanvas->drawRect(
        SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                         (SkScalar)(int)w, (SkScalar)(int)h),
        bgPaint);
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

// ─── 窗口尺寸变更 ───
void php_sk_resize_context(Int width, Int height) {
    if ((int)width <= 0 || (int)height <= 0) return;
    g_skW = (int)width;
    g_skH = (int)height;
#ifdef USE_SKIA
    if (g_skCanvas
        && g_skSkBitmap.width()  == (int)width
        && g_skSkBitmap.height() == (int)height) {
        return;
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

// ─── WM_PAINT 处理 ───
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
#include "skia_render.h"

