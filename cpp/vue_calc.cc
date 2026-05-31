/**
 * VueCalc Win32 API Layer
 *
 * Minimal Win32 API wrapper as thin rendering primitive layer.
 * All calculator logic and reactive data implemented in PHP.
 * Rendering engine for the Vue-like data-driven desktop framework.
 */

#include <phpx.h>
#include <windows.h>
#pragma comment(lib, "msimg32.lib")
#include <cstdio>

using namespace php;

// ============================================================
// Win32 Window & Message
// ============================================================

static bool g_quitRequested = false;

// 定时器回调映射表（支持多个定时器）
static std::map<HWND, void(*)()> g_timerCallbacks;

// 定时器回调函数（由 SetTimer 调用）
void CALLBACK TimerCallback(HWND hwnd, UINT msg, UINT_PTR idEvent, DWORD time) {
    auto it = g_timerCallbacks.find(hwnd);
    if (it != g_timerCallbacks.end()) {
        it->second();
    }
}

LRESULT CALLBACK VueCalcWndProc(HWND hWnd, UINT msg, WPARAM wParam, LPARAM lParam) {
    switch (msg) {
        case WM_CLOSE:
            g_quitRequested = true;
            PostQuitMessage(0);
            return 0;
        case WM_DESTROY:
            g_quitRequested = true;
            PostQuitMessage(0);
            return 0;
        case WM_TIMER:
            // 由 TimerCallback 处理，此处不处理
            return 0;
    }
    return DefWindowProc(hWnd, msg, wParam, lParam);
}

// 创建窗口, 返回 hWnd
Int php_vue_window_create(String title, Int width, Int height) {
    SetConsoleOutputCP(65001);

    WNDCLASS wc;
    ZeroMemory(&wc, sizeof(wc));
    wc.style = CS_HREDRAW | CS_VREDRAW;
    wc.lpfnWndProc = VueCalcWndProc;
    wc.hInstance = GetModuleHandle(NULL);
    wc.hCursor = LoadCursor(NULL, IDC_ARROW);
    wc.hbrBackground = (HBRUSH)(COLOR_WINDOW + 1);
    wc.lpszClassName = "VueCalcWindow";
    RegisterClass(&wc);

    // v6 M5 FIX: Adjust window rect to get desired client area
    DWORD dwStyle = WS_OVERLAPPEDWINDOW & ~WS_THICKFRAME & ~WS_MAXIMIZEBOX;
    RECT wr = {0, 0, (LONG)width, (LONG)height};
    AdjustWindowRect(&wr, dwStyle, FALSE);

    HWND hWnd = CreateWindowEx(
        0, "VueCalcWindow", title.data(),
        dwStyle,
        CW_USEDEFAULT, CW_USEDEFAULT,
        wr.right - wr.left, wr.bottom - wr.top,
        NULL, NULL, GetModuleHandle(NULL), NULL
    );
    return (Int)hWnd;
}

// Show window
void php_vue_window_show(Int hWnd, Int cmdShow) {
    ShowWindow((HWND)hWnd, (int)cmdShow);
}

// 检查是否请求退出
Bool php_vue_quit_requested() {
    return g_quitRequested;
}

// PeekMessage wrapper - returns [hwnd, message, wParam, lParam] or empty array
Array php_vue_peek_message() {
    MSG msg;
    ZeroMemory(&msg, sizeof(msg));
    if (PeekMessage(&msg, NULL, 0, 0, PM_REMOVE)) {
        Int lParam = (Int)msg.lParam;

        // WM_MOUSEWHEEL lParam is screen coords; convert to client coords
        // so findScrollContainerAt can compare against VNode client coordinates
        if (msg.message == WM_MOUSEWHEEL) {
            POINT pt;
            pt.x = (short)(msg.lParam & 0xFFFF);
            pt.y = (short)((msg.lParam >> 16) & 0xFFFF);
            ScreenToClient(msg.hwnd, &pt);
            lParam = (Int)(LPARAM)(((DWORD)(pt.y) << 16) | (DWORD)(pt.x & 0xFFFF));
        }

        Array result;
        result.append((Int)msg.hwnd);
        result.append((Int)msg.message);
        result.append((Int)msg.wParam);
        result.append(lParam);
        TranslateMessage(&msg);
        DispatchMessage(&msg);
        return result;
    }
    return Array();
}

// ============================================================
// Win32 GDI drawing primitives (double buffered)
// ============================================================

// Begin double-buffered frame, return memDC handle
Int php_vue_begin_paint(Int hWnd) {
    HDC hdc = GetDC((HWND)hWnd);
    RECT rc;
    GetClientRect((HWND)hWnd, &rc);

    HDC memDC = CreateCompatibleDC(hdc);
    HBITMAP memBitmap = CreateCompatibleBitmap(hdc, rc.right, rc.bottom);
    SelectObject(memDC, memBitmap);

    ReleaseDC((HWND)hWnd, hdc);
    return (Int)memDC;
}

// End double-buffered frame: blit back buffer to screen and cleanup
void php_vue_end_paint(Int hWnd, Int hdcHandle) {
    HDC memDC = (HDC)hdcHandle;
    RECT rc;
    GetClientRect((HWND)hWnd, &rc);

    HDC hdc = GetDC((HWND)hWnd);
    BitBlt(hdc, 0, 0, rc.right, rc.bottom, memDC, 0, 0, SRCCOPY);
    ReleaseDC((HWND)hWnd, hdc);

    HBITMAP hBitmap = (HBITMAP)GetCurrentObject(memDC, OBJ_BITMAP);
    DeleteDC(memDC);
    if (hBitmap) DeleteObject(hBitmap);
}

// Fill rectangle
void php_vue_fill_rect(Int hdc, Int x, Int y, Int w, Int h, Int rgbColor) {
    HBRUSH brush = CreateSolidBrush((COLORREF)rgbColor);
    RECT r = {(int)x, (int)y, (int)(x + w), (int)(y + h)};
    FillRect((HDC)hdc, &r, brush);
    DeleteObject(brush);
}

// Draw rounded rectangle (filled) — for border-radius
void php_vue_draw_round_rect(Int hdc, Int x, Int y, Int w, Int h, Int radius, Int rgbColor) {
    HRGN hrgn = CreateRoundRectRgn((int)x, (int)y, (int)(x + w), (int)(y + h), (int)radius * 2, (int)radius * 2);
    HBRUSH brush = CreateSolidBrush((COLORREF)rgbColor);
    FillRgn((HDC)hdc, hrgn, brush);
    DeleteObject(brush);
    DeleteObject(hrgn);
}

// Fill rectangle with alpha (opacity) — uses 32-bit DIB + AlphaBlend
void php_vue_alpha_fill_rect(Int hdc, Int x, Int y, Int w, Int h, Int bgrColor, double opacity) {
    if (w <= 0 || h <= 0) return;
    int alpha = (int)(opacity * 255.0);
    if (alpha >= 255) {
        // Fully opaque: fall back to regular fill
        HBRUSH brush = CreateSolidBrush((COLORREF)bgrColor);
        RECT r = {(int)x, (int)y, (int)(x + w), (int)(y + h)};
        FillRect((HDC)hdc, &r, brush);
        DeleteObject(brush);
        return;
    }
    if (alpha <= 0) return; // fully transparent: skip

    // Extract B,G,R from COLORREF (BGR format)
    int blue  = bgrColor & 0xFF;
    int green = (bgrColor >> 8) & 0xFF;
    int red   = (bgrColor >> 16) & 0xFF;

    // Create 32-bit top-down DIB section
    BITMAPINFO bmi;
    ZeroMemory(&bmi, sizeof(bmi));
    bmi.bmiHeader.biSize        = sizeof(BITMAPINFOHEADER);
    bmi.bmiHeader.biWidth       = (int)w;
    bmi.bmiHeader.biHeight      = -(int)h;  // negative = top-down
    bmi.bmiHeader.biPlanes      = 1;
    bmi.bmiHeader.biBitCount    = 32;
    bmi.bmiHeader.biCompression = BI_RGB;

    void* bits = NULL;
    HBITMAP hBitmap = CreateDIBSection((HDC)hdc, &bmi, DIB_RGB_COLORS, &bits, NULL, 0);
    if (!hBitmap || !bits) {
        if (hBitmap) DeleteObject(hBitmap);
        return;
    }

    HDC memDC = CreateCompatibleDC((HDC)hdc);
    HBITMAP oldBitmap = (HBITMAP)SelectObject(memDC, hBitmap);

    // Fill with pre-multiplied alpha (BGRA format in DIB)
    unsigned char* p = (unsigned char*)bits;
    int total = w * h;
    for (int i = 0; i < total; i++) {
        p[0] = (unsigned char)(blue  * alpha / 255);  // B
        p[1] = (unsigned char)(green * alpha / 255);  // G
        p[2] = (unsigned char)(red   * alpha / 255);  // R
        p[3] = (unsigned char)alpha;                   // A
        p += 4;
    }

    // AlphaBlend to target DC
    BLENDFUNCTION blend;
    blend.BlendOp             = AC_SRC_OVER;
    blend.BlendFlags          = 0;
    blend.SourceConstantAlpha = 255;  // per-pixel alpha already pre-multiplied
    blend.AlphaFormat         = AC_SRC_ALPHA;

    AlphaBlend((HDC)hdc, (int)x, (int)y, (int)w, (int)h,
               memDC, 0, 0, (int)w, (int)h, blend);

    // Cleanup
    SelectObject(memDC, oldBitmap);
    DeleteObject(hBitmap);
    DeleteDC(memDC);
}

// 绘制文本
void php_vue_draw_text(Int hdc, Int x, Int y, String text, Int fontSize, Int rgbColor, Int bold) {
    SetTextColor((HDC)hdc, (COLORREF)rgbColor);
    SetBkMode((HDC)hdc, TRANSPARENT);
    HFONT hFont = CreateFont((int)fontSize, 0, 0, 0,
        bold ? FW_BOLD : FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, "Microsoft YaHei");
    HFONT oldFont = (HFONT)SelectObject((HDC)hdc, hFont);
    // Convert UTF-8 to UTF-16 for Unicode text rendering (supports CJK)
    int wlen = MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, NULL, 0);
    if (wlen > 0) {
        std::wstring wtext(wlen, L'\0');
        MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, &wtext[0], wlen);
        TextOutW((HDC)hdc, (int)x, (int)y, wtext.c_str(), (wlen > 1) ? (wlen - 1) : 0);
    }
    SelectObject((HDC)hdc, oldFont);
    DeleteObject(hFont);
}

// v6 M5 FIX: Measure text pixel width (for cursor positioning)
Int php_vue_measure_text_width(Int hdc, String text, Int fontSize) {
    HFONT hFont = CreateFont((int)fontSize, 0, 0, 0,
        FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, "Microsoft YaHei");
    HFONT oldFont = (HFONT)SelectObject((HDC)hdc, hFont);
    SIZE sz = {0, 0};
    int wlen = MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, NULL, 0);
    if (wlen > 0) {
        std::wstring wtext(wlen, L'\0');
        MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, &wtext[0], wlen);
        GetTextExtentPoint32W((HDC)hdc, wtext.c_str(), (wlen > 1) ? (wlen - 1) : 0, &sz);
    }
    SelectObject((HDC)hdc, oldFont);
    DeleteObject(hFont);
    return (Int)sz.cx;
}

// Push clip rectangle - saves DC state and sets clip region
void php_vue_push_clip(Int hdc, Int x, Int y, Int w, Int h) {
    SaveDC((HDC)hdc);
    HRGN clipRgn = CreateRectRgn((int)x, (int)y, (int)(x + w), (int)(y + h));
    SelectClipRgn((HDC)hdc, clipRgn);
    DeleteObject(clipRgn);
}

// Pop clip rectangle - restores DC state (removes clip)
void php_vue_pop_clip(Int hdc) {
    RestoreDC((HDC)hdc, -1);
}

// Hit test for scrollbar (returns array with scroll info)
Array php_vue_hit_test_scrollbar(Int hwnd, Int x, Int y) {
    Array result;
    // Basic implementation - scrollbar hit test would be implemented here
    // For now, return empty result indicating no scrollbar hit
    return result;
}

// 绘制按钮(填充+边框)
void php_vue_draw_button(Int hdc, Int x, Int y, Int w, Int h, Int bgColor, Int borderColor) {
    // 填充背景
    HBRUSH brush = CreateSolidBrush((COLORREF)bgColor);
    RECT r = {(int)x, (int)y, (int)(x + w), (int)(y + h)};
    FillRect((HDC)hdc, &r, brush);
    DeleteObject(brush);
    // Draw border
    HPEN pen = CreatePen(PS_SOLID, 1, (COLORREF)borderColor);
    HPEN oldPen = (HPEN)SelectObject((HDC)hdc, pen);
    HBRUSH oldBrush = (HBRUSH)SelectObject((HDC)hdc, GetStockObject(NULL_BRUSH));
    Rectangle((HDC)hdc, (int)x, (int)y, (int)(x + w), (int)(y + h));
    SelectObject((HDC)hdc, oldBrush);
    SelectObject((HDC)hdc, oldPen);
    DeleteObject(pen);
}

// ============================================================
// Win32 Timer (Animation Frame Driver)
// ============================================================

// 设置定时器，返回定时器ID（>0成功，0失败）
Int php_vue_set_timer(Int hWnd, Int intervalMs) {
    HWND hwnd = (HWND)(Int)hWnd;
    UINT_PTR id = SetTimer(hwnd, NULL, (UINT)intervalMs, TimerCallback);
    return (Int)id;
}

// 停止定时器
void php_vue_kill_timer(Int hWnd, Int timerId) {
    HWND hwnd = (HWND)(Int)hWnd;
    KillTimer(hwnd, (UINT_PTR)timerId);
}
