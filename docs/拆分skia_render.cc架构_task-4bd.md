## 拆分 `skia_render.cc` 架构设计

### 当前问题
- 1500+ 行单文件，混合全局状态、字体管理、DirectWrite、Skia/GDI 渲染、图片处理
- 任何修改都影响整个文件的编译，DirectWrite 集成因文件过大难以编辑

### 拆分方案

```
cpp/
├── skia_render.h          ← 新增：共享声明（全局变量 extern、函数声明、常量）
├── skia_core.cc           ← 新增：窗口上下文、帧管理、clip、基础形状（fill_rect、round_rect、shadow）
├── skia_text.cc           ← 新增：**文本渲染**（GDI TextOut + Skia drawString + DirectWrite drawTextDWrite）
├── skia_font.cc           ← 新增：字体加载（GDI AddFontMemResourceEx + Skia FontMgr + DirectWrite Factory）
├── skia_image.cc          ← 新增：图片加载/绘制、截图
├── skia_render.cc         ← 精简为：仅保留 PHP stub 转发 + 引擎切换逻辑
├── skia_render.obj        ← 已有（预编译）
├── skia_dinkumware_stubs.cc  ← 已有
├── vue_calc.cc            ← 已有
└── fonts/                 ← 已有
```

### 各文件职责

| 文件 | 职责 | 行数估计 |
|------|------|---------|
| `skia_render.h` | extern 全局变量声明、函数声明、常量定义 | ~100 行 |
| `skia_core.cc` | `php_sk_create_window_context`, `php_sk_destroy_context`, `php_sk_begin_frame`, `php_sk_end_frame`, `php_sk_fill_rect`, `php_sk_draw_round_rect`, `php_sk_shadow_round_rect`, `php_sk_alpha_fill_rect`, `php_sk_push_clip`, `php_sk_pop_clip`, `php_sk_fill_gradient_rect`, `php_sk_draw_button` | ~400 行 |
| `skia_text.cc` | `php_sk_draw_text`, `php_sk_measure_text_width`, `php_sk_measure_text_height`, `drawTextDWrite`, `php_sk_set_text_engine`, `php_sk_set_default_font`, `g_textEngine` | **新增**完整 DirectWrite 渲染路径 | ~300 行 |
| `skia_font.cc` | `g_dwFactory`, `ensureDWriteFactory()`, `measureHeightDWrite()`, `g_skTypeface`, `g_skFont`, `skEnsureFont()`, `skLoadPrivateFonts()`, `g_skDefaultFont`, `php_sk_set_default_font` | ~250 行 |
| `skia_image.cc` | `php_sk_load_image`, `php_sk_draw_image`, `php_sk_free_image`, `php_sk_save_screenshot`, GDI+ init | ~200 行 |

### 构建方式

`.obj` 文件需要本地 MSVC 环境编译，我提供以下产物：
1. 拆分的 `.cc` 文件 + `.h` 头文件
2. 编译脚本：`_build_native.ps1`

你本地运行 `.\_build_native.ps1` 即可编译所有 `.cc`→`.obj`，再运行 `build.bat css-test` 正常构建。

### 后续优势
- `skia_text.cc` 独立后，可以完整实现 `drawTextDWrite`（`IDWriteTextLayout::Draw` 回调）而不影响其他模块
- 文本引擎添加新后端（如 Direct2D）只需新增文件，不改现有代码（OCP）
- 各文件独立编译，修改后只需重新编译受影响文件