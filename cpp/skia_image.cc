#include "skia_render.h"

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

// 入栈裁剪区域（圆角矩形）
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
