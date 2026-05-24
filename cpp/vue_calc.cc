/**
 * VueCalc Win32 API Layer
 *
 * Minimal Win32 API wrapper as thin rendering primitive layer.
 * All calculator logic and reactive data implemented in PHP.
 * Rendering engine for the Vue-like data-driven desktop framework.
 */

#include <phpx.h>
#include <windows.h>
#include <cstdio>

using namespace php;

// ============================================================
// Win32 Window & Message
// ============================================================

static bool g_quitRequested = false;

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
        Array result;
        result.append((Int)msg.hwnd);
        result.append((Int)msg.message);
        result.append((Int)msg.wParam);
        result.append((Int)msg.lParam);
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

// 绘制文本
void php_vue_draw_text(Int hdc, Int x, Int y, String text, Int fontSize, Int rgbColor, Int bold) {
    SetTextColor((HDC)hdc, (COLORREF)rgbColor);
    SetBkMode((HDC)hdc, TRANSPARENT);
    HFONT hFont = CreateFont((int)fontSize, 0, 0, 0,
        bold ? FW_BOLD : FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, "Segoe UI");
    HFONT oldFont = (HFONT)SelectObject((HDC)hdc, hFont);
    TextOutA((HDC)hdc, (int)x, (int)y, text.data(), (int)strlen(text.data()));
    SelectObject((HDC)hdc, oldFont);
    DeleteObject(hFont);
}

// v6 M5 FIX: Measure text pixel width (for cursor positioning)
Int php_vue_measure_text_width(Int hdc, String text, Int fontSize) {
    HFONT hFont = CreateFont((int)fontSize, 0, 0, 0,
        FW_NORMAL, FALSE, FALSE, FALSE,
        DEFAULT_CHARSET, OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
        DEFAULT_QUALITY, DEFAULT_PITCH | FF_SWISS, "Segoe UI");
    HFONT oldFont = (HFONT)SelectObject((HDC)hdc, hFont);
    SIZE sz = {0, 0};
    GetTextExtentPoint32A((HDC)hdc, text.data(), (int)strlen(text.data()), &sz);
    SelectObject((HDC)hdc, oldFont);
    DeleteObject(hFont);
    return (Int)sz.cx;
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
