/**
 * skia_font.cc — 字体管理模块
 * 包含：DirectWrite 工厂与度量、GDI 私有字体加载、Skia 字体初始化、默认字体名管理
 */
#include "skia_render.h"

// ─── DirectWrite ───
bool g_dwInitAttempted = false;
IDWriteFactory* g_dwFactory = nullptr;
IDWriteBitmapRenderTarget* g_dwRenderTarget = nullptr;

bool ensureDWriteFactory() {
    if (g_dwFactory) return true;
    if (g_dwInitAttempted) return false;
    g_dwInitAttempted = true;
    HRESULT hr = DWriteCreateFactory(
        DWRITE_FACTORY_TYPE_SHARED,
        __uuidof(IDWriteFactory),
        reinterpret_cast<IUnknown**>(&g_dwFactory));
    return SUCCEEDED(hr) && g_dwFactory != nullptr;
}

bool ensureDWriteRenderTarget(HDC hdc, int w, int h) {
    if (g_dwRenderTarget) {
        if (w > 0 && h > 0) {
            g_dwRenderTarget->Resize(w, h);
        }
        return true;
    }
    if (!ensureDWriteFactory()) return false;
    // CreateBitmapRenderTarget was moved from IDWriteFactory to IDWriteGdiInterop in SDK 10.0.26100.0+.
    // Using IDWriteGdiInterop works on all SDK versions.
    IDWriteGdiInterop* gdiInterop = nullptr;
    HRESULT hr = g_dwFactory->GetGdiInterop(&gdiInterop);
    if (SUCCEEDED(hr) && gdiInterop) {
        hr = gdiInterop->CreateBitmapRenderTarget(
            hdc, w > 0 ? w : 1, h > 0 ? h : 1, &g_dwRenderTarget);
        gdiInterop->Release();
    }
    return SUCCEEDED(hr) && g_dwRenderTarget != nullptr;
}

void shutdownDWrite() {
    if (g_dwRenderTarget) {
        g_dwRenderTarget->Release();
        g_dwRenderTarget = nullptr;
    }
    if (g_dwFactory) {
        g_dwFactory->Release();
        g_dwFactory = nullptr;
    }
    g_dwInitAttempted = false;
    SK_TRACE("[SK] DWrite shutdown\n");
}

int measureHeightDWrite(int fontSize, int bold) {
    if (!ensureDWriteFactory()) return 0;
    int wlen = MultiByteToWideChar(CP_UTF8, 0, g_skDefaultFont.c_str(), -1, NULL, 0);
    if (wlen <= 0) return 0;
    std::wstring wfont(wlen, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, g_skDefaultFont.c_str(), -1, &wfont[0], wlen);

    IDWriteTextFormat* format = nullptr;
    HRESULT hr = g_dwFactory->CreateTextFormat(
        wfont.c_str(), nullptr,
        (Int)bold != 0 ? DWRITE_FONT_WEIGHT_BOLD : DWRITE_FONT_WEIGHT_REGULAR,
        DWRITE_FONT_STYLE_NORMAL, DWRITE_FONT_STRETCH_NORMAL,
        (FLOAT)fontSize, L"", &format);
    if (FAILED(hr) || !format) return 0;

    IDWriteTextLayout* layout = nullptr;
    hr = g_dwFactory->CreateTextLayout(L"A", 1, format, 10000.0f, 10000.0f, &layout);
    int result = 0;
    if (SUCCEEDED(hr) && layout) {
        DWRITE_TEXT_METRICS metrics;
        layout->GetMetrics(&metrics);
        result = (int)(metrics.height + 0.5f);
        layout->Release();
    }
    format->Release();
    return result > 0 ? result : 0;
}

// DirectWrite 精确测量文本宽度
int measureWidthDWrite(const char* text, int textLen, int fontSize, int bold) {
    if (!ensureDWriteFactory() || textLen <= 0) return 0;
    int wlen = MultiByteToWideChar(CP_UTF8, 0, text, textLen, NULL, 0);
    if (wlen <= 0) return 0;
    std::wstring wtext(wlen, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, text, textLen, &wtext[0], wlen);

    int fwlen = MultiByteToWideChar(CP_UTF8, 0, g_skDefaultFont.c_str(), -1, NULL, 0);
    if (fwlen <= 0) return 0;
    std::wstring wfont(fwlen, L'\0');
    MultiByteToWideChar(CP_UTF8, 0, g_skDefaultFont.c_str(), -1, &wfont[0], fwlen);

    IDWriteTextFormat* format = nullptr;
    HRESULT hr = g_dwFactory->CreateTextFormat(
        wfont.c_str(), nullptr,
        (Int)bold != 0 ? DWRITE_FONT_WEIGHT_BOLD : DWRITE_FONT_WEIGHT_REGULAR,
        DWRITE_FONT_STYLE_NORMAL, DWRITE_FONT_STRETCH_NORMAL,
        (FLOAT)fontSize, L"", &format);
    if (FAILED(hr) || !format) return 0;

    IDWriteTextLayout* layout = nullptr;
    hr = g_dwFactory->CreateTextLayout(wtext.c_str(), wlen - 1, format, 10000.0f, 10000.0f, &layout);
    int result = 0;
    if (SUCCEEDED(hr) && layout) {
        DWRITE_TEXT_METRICS metrics;
        layout->GetMetrics(&metrics);
        result = (int)(metrics.widthIncludingTrailingWhitespace + 0.5f);
        layout->Release();
    }
    format->Release();
    return result > 0 ? result : 0;
}

// ─── GDI 字体加载 ───
bool g_skPrivateFontsLoaded = false;
void* g_skFontRegData = NULL;
void* g_skFontBoldData = NULL;
DWORD g_skFontRegSize = 0;
DWORD g_skFontBoldSize = 0;

void skLoadPrivateFonts() {
    if (g_skPrivateFontsLoaded) return;
    const char* searchDirs[] = {"cpp/fonts", "fonts"};
    const char* addFontFiles[] = {"NotoSansSC-Regular.ttf", "NotoSansSC-Bold.ttf"};
    void** fontData[] = {&g_skFontRegData, &g_skFontBoldData};
    DWORD* fontSizes[] = {&g_skFontRegSize, &g_skFontBoldSize};
    for (int i = 0; i < 2; i++) {
        for (int f = 0; f < 2; f++) {
            if (*fontData[f] != NULL) continue;
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
                    AddFontMemResourceEx(*fontData[f], *fontSizes[f], NULL, &fontCount);
                    SK_TRACE("[SK] AddFontMemResourceEx('%s') fontCount=%d\n", path.c_str(), (int)fontCount);
                }
            } else {
                SK_TRACE("[SK] AddFontMemResourceEx: file not found '%s'\n", path.c_str());
            }
        }
    }
    const char* fontSearchDirs[] = {"cpp/fonts", "fonts"};
    for (int d = 0; d < 2; d++) {
        for (int f = 0; f < 2; f++) {
            std::string fontPath = std::string(fontSearchDirs[d]) + "/" + addFontFiles[f];
            AddFontResourceEx(fontPath.c_str(), FR_PRIVATE, 0);
            SK_TRACE("[SK] AddFontResourceEx('%s')\n", fontPath.c_str());
        }
    }
    g_skPrivateFontsLoaded = true;
}

// ─── 设置默认字体 ───
std::string g_skDefaultFont = "Segoe UI";

void php_sk_set_default_font(String fontFamily) {
    if (fontFamily.length() > 0) {
        g_skDefaultFont = std::string(fontFamily.data(), fontFamily.length());
        SK_TRACE("[SK] set_default_font '%s'\n", g_skDefaultFont.c_str());
    }
}

// ─── 文本引擎选择 ───
int g_textEngine = 0; // 0=auto, 1=dwrite, 2=skia, 3=gdi

void php_sk_set_text_engine(String engine) {
    if (engine.length() == 0) return;
    std::string e(engine.data(), engine.length());
    if (e == "dwrite") g_textEngine = 1;
    else if (e == "skia") g_textEngine = 2;
    else if (e == "gdi") g_textEngine = 3;
    else g_textEngine = 0;
    SK_TRACE("[SK] set_text_engine '%s' -> %d\n", e.c_str(), g_textEngine);
}

#ifdef USE_SKIA
// ─── Skia 字体 ───
SkBitmap  g_skSkBitmap;
std::unique_ptr<SkCanvas> g_skCanvas;
bool g_skFontInited = false;
sk_sp<SkFontMgr>  g_skFontMgr;
sk_sp<SkTypeface> g_skTypeface;
sk_sp<SkTypeface> g_skTypefaceBold;
SkFont g_skFont;
std::vector<uint8_t> g_skPixelBuf;

SkColor rgbToSkColor(Int rgb) {
    uint32_t c = (uint32_t)(int)rgb;
    return SkColorSetARGB(0xFF,
        (U8CPU)( c        & 0xFF),
        (U8CPU)((c >> 8)  & 0xFF),
        (U8CPU)((c >> 16) & 0xFF));
}

bool skEnsureFont() {
    if (g_skFontInited && g_skTypeface) return true;
    g_skFontInited = true;
    const char* searchDirs[] = {"cpp/fonts", "fonts"};
    const char* fontName = "NotoSansSC-Regular.ttf";
    const char* boldFontName = "NotoSansSC-Bold.ttf";
    for (int i = 0; i < 2; i++) {
        g_skFontMgr = SkFontMgr_New_Custom_Directory(searchDirs[i]);
        if (!g_skFontMgr) continue;
        std::string fullPath = std::string(searchDirs[i]) + "/" + fontName;
        g_skTypeface = g_skFontMgr->makeFromFile(fullPath.c_str());
        if (g_skTypeface) {
            std::string boldPath = std::string(searchDirs[i]) + "/" + boldFontName;
            g_skTypefaceBold = g_skFontMgr->makeFromFile(boldPath.c_str());
            break;
        }
    }
    if (!g_skTypeface) {
        g_skFontMgr = SkFontMgr_New_Custom_Directory("cpp/fonts");
        if (g_skFontMgr) {
            g_skTypeface = g_skFontMgr->makeFromFile("C:/Windows/Fonts/msyh.ttc");
        }
    }
    if (!g_skTypeface) return false;
    g_skFont = SkFont(g_skTypeface, 14.0f);
    g_skFont.setSubpixel(true);
    g_skFont.setEdging(SkFont::Edging::kAntiAlias);
    return true;
}
#endif
