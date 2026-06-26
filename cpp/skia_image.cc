#include "skia_render.h"

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
    if (!g_skHdc) return;
    Gdiplus::Image* image = reinterpret_cast<Gdiplus::Image*>((int)handle);
    if (!image || !g_skHdc) return;
    Gdiplus::Graphics gfx(g_skHdc);
    Gdiplus::Rect dstRect((int)x, (int)y, (int)w, (int)h);
    gfx.DrawImage(image, dstRect);
#endif
}

// 释放图片
void php_sk_free_image(Int handle) {
    if (handle == 0) return;
#ifdef USE_SKIA
    SkImage* image = reinterpret_cast<SkImage*>((int)handle);
    image->unref();
#else
    Gdiplus::Image* image = reinterpret_cast<Gdiplus::Image*>((int)handle);
    delete image;
#endif
}

// 获取图片宽度
Int php_sk_get_image_width(Int handle) {
    if (handle == 0) return 0;
#ifdef USE_SKIA
    SkImage* image = reinterpret_cast<SkImage*>((int)handle);
    return (Int)image->width();
#else
    Gdiplus::Image* image = reinterpret_cast<Gdiplus::Image*>((int)handle);
    return (Int)image->GetWidth();
#endif
}

// 获取图片高度
Int php_sk_get_image_height(Int handle) {
    if (handle == 0) return 0;
#ifdef USE_SKIA
    SkImage* image = reinterpret_cast<SkImage*>((int)handle);
    return (Int)image->height();
#else
    Gdiplus::Image* image = reinterpret_cast<Gdiplus::Image*>((int)handle);
    return (Int)image->GetHeight();
#endif
}

// 保存截图（headless 模式：Skia → GDI → PNG）
void php_sk_save_screenshot(String path) {
    if (path.length() == 0) return;
#ifdef USE_SKIA
    // 阶段三（Skia）：利用 end_frame 中的 GDI 中转，延后出帧时保存
    g_skPendingSSPath = std::string(path.data(), path.length());
    SK_TRACE("[SK] save_screenshot deferred: %s\n", path.data());
#else
    (void)path;
    SK_TRACE("[SK] save_screenshot not implemented in non-SKIA mode\n");
#endif
}
