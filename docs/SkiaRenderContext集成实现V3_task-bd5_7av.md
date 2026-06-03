# SkiaRenderContext 集成实现规划（v3 — 合并详细版本 + 用户反馈）

## 1. Context（背景与目标）

Px 框架目前唯一渲染路径是 Win32 GDI：`Win32Platform::init()`（`d:/Px/framework/Platform/Win32Platform.php:32`）硬编码 `return new GdiRenderContext(...)`，后者直接调用 9 个 `vue_*` C++ 原语。GDI 存在三个长期痛点：圆角无抗锯齿 / 不支持硬件加速 / Windows 专属无法跨平台。

**目标**：保留 GDI 路径，引入 Skia 路径作为 `RenderContext` 抽象的第二个实现，默认 GDI 兜底零侵入，Skia 模式由 `const APP_RENDERER = 'skia';` 开关。

**用户决策摘要**：

| 决策项 | 选择 |
|---|---|
| 文件组织 | 平铺新增：C++ `cpp/skia_render.cc`、Stub `stub/skia.stub.php`、PHP `framework/Rendering/SkiaRenderContext.php` |
| 后端开关 | `const APP_RENDERER = 'gdi'\|'skia';`，默认 `gdi`，与 `APP_PLATFORM` 同处 |
| Skia 引入 | 阶段一/二先不接真 Skia 库，用 GDI 兼容层验证 AOT 扫描；阶段三再接 Skia 静态库 |
| POC 应用 | 新建 `apps/skia-poc/`（最小化蓝色矩形） |

**关键架构约束（已结合现状调整）**：

- Px 框架**不构建独立 PHP 扩展**（无 .so/.dll 模式）。C++ 源作为 translation unit 通过 `project.yml::sources: - ../../cpp` 整体链接进 AOT 二进制。Swoole Compiler 的 `sources` 字段**支持目录递归扫描**（`docs/swooler compiler AOT 编译器文档.md:174`），新加的 `cpp/skia_render.cc` 会被自动发现。
- Swoole Compiler 识别 C++ 函数：必须以 `php_` 前缀 + `php::` 类型 + stub 中必须有声明。
- 草稿中的 `framework/ext/skia/` + `config.m4` + `phpize` 模式**不适用**，已被替换为平铺新增。
- AOT 检查器 `excludedFiles`（`d:/Px/framework/aot-checker.php:138-144`）**只扫描 `.php` 文件**，因此只需追加 `SkiaRenderContext.php`，无需追加 `skia_render.cc`（不是 PHP 文件，追加无效）。
- `project.yml` 字段中 `cxx-flags`（编译选项）与 `ld-flags`（链接选项）**分离**（`docs/swooler compiler AOT 编译器文档.md:178-179`）。Skia 静态库必须放 `ld-flags`，Skia 头路径 `/I` 必须放 `cxx-flags`。
- Swoole Compiler 提供 `php::Box` 机制（`docs/swooler compiler AOT 编译器文档.md:1672-1814`）封装 C++ 对象为 PHP 资源。SkiaRenderContext 阶段三可借此让 `sk_create_window_context` 返回 `mixed`（Box 资源）以携带 SkSurface 状态跨函数调用，避免静态全局变量（多窗口兼容基础）。
- 阶段一追加 `use native_types;` 到 `RenderContext.php`（13 行，6 个抽象方法声明，无动态类型使用，加 native_types 是安全的）——这是 SkiaRenderContext 享受 AOT 类型推导性能优化的必要前提（参见记忆 `AOT 链式类型推导要求调用链所有类声明 use native_types`）

## 2. 设计原则

| # | 原则 | 体现 |
|---|---|---|
| P1 | 不引入独立 PHP 扩展 | 全部 C++ 源码作为 translation unit 链接进 AOT 二进制 |
| P2 | 不引入额外目录层级 | C++ → `cpp/`、Stub → `stub/`、PHP → `framework/Rendering/`，与现有 `vue_calc.*` 同级 |
| P3 | 复用 `RenderContext` 抽象 + 构造注入 | `SkiaRenderContext extends RenderContext`，`VNodeRenderer` 零修改 |
| P4 | 复用元素描述符模式 | `drawElement` 12 路 switch 1:1 移植 `GdiRenderContext` |
| P5 | AOT 友好值类型 | Stub 形参全部 `int/string/float/array` |
| P6 | 零侵入 | 现有 7 个 `apps/*/main.php` 全部不需改动 |

## 3. 实施任务分解

### Task 1：阶段一 POC — 验证 AOT 扫描链接链路

**目标**：确认 Swoole Compiler 能扫描 `skia.stub.php` → 链接 `cpp/skia_render.cc` 的 `php_sk_xxx` → `SkiaRenderContext` 可调用。**不引入 Skia 库**。预计 2-3 小时。

#### 1.1 新增 C++ 源 `d:/Px/cpp/skia_render.cc`（POC 版 ~80 行）
- 文件头部与 `vue_calc.cc:9-14` 一致：`#include <phpx.h>` + `#include <windows.h>` + `using namespace php;`
- **文件顶部追加 `#pragma comment(lib, "msimg32.lib")`**（与 `vue_calc.cc:11` 一致；阶段二 `php_sk_alpha_fill_rect` 调用 `AlphaBlend` 时需要）
- 维护 `static HWND g_skHwnd = NULL;` 与 `static HDC g_skHdc = NULL;`（模块全局，与 GDI 版分离）。**注意**：`g_skHdc` 必须由 `php_sk_begin_frame` 中的 `BeginPaint` 赋值，`php_sk_end_frame` 中的 `EndPaint` 清空（与 `vue_calc.cc` 中 `vue_begin_paint/vue_end_paint` 模式对齐）
- 实现 6 个 POC 函数（POC 阶段用 GDI 真实绘制，目标只是验证符号链）：
  - `Int php_sk_create_window_context(Int hWnd, Int width, Int height)` — 保存句柄与尺寸到 `g_skHwnd/g_skW/g_skH`，返回 1
  - `void php_sk_destroy_context()` — 清空三个静态变量
  - `void php_sk_begin_frame()` — `BeginPaint(g_skHwnd, &ps)` 保存到 `g_skHdc`
  - `void php_sk_end_frame()` — `EndPaint(g_skHwnd, &ps)` 并清零 `g_skHdc`
  - `void php_sk_clear_window(Int rgb)` — `FillRect(g_skHdc, &rc, ...)` 全窗口清屏
  - `void php_sk_fill_rect(Int x, Int y, Int w, Int h, Int rgb)` — `FillRect(g_skHdc, &rc, ...)` 单矩形

#### 1.2 新增 stub `d:/Px/stub/skia.stub.php`（~30 行）
- 格式严格照搬 `vue_calc.stub.php:48-78`
- 声明 6 个函数（形参与 C++ `php::Int` 一一对应）：
  - `sk_create_window_context(int $hWnd, int $width, int $height): int`
  - `sk_destroy_context(): void`
  - `sk_begin_frame(): void` / `sk_end_frame(): void`
  - `sk_clear_window(int $rgb): void`
  - `sk_fill_rect(int $x, int $y, int $w, int $h, int $rgb): void`
- 文件顶部加注释：PHP 中以 `sk_` 开头，C++ 实现中对应 `php_sk_` 前缀

#### 1.3 新增 `d:/Px/framework/Rendering/SkiaRenderContext.php`（POC 版 ~70 行）
- `namespace Px\Rendering;` + `use native_types;`（与 `GdiRenderContext.php:3,5` 一致）
- `class SkiaRenderContext extends RenderContext`
- 构造：`__construct(int $hWnd, int $width, int $height)` → `sk_create_window_context($hWnd, $width, $height)`
  - **重要**：参数列表已含 `$width/$height`（阶段三需要尺寸创建 SkSurface，阶段一占位以避免阶段三需重构构造签名）
- 析构：`__destruct()` → `sk_destroy_context()`
- 实现 6 个抽象方法：
  - `beginFrame/endFrame/fillRect` → 委派 `sk_*`
  - `drawElement` → 仅 `type==='rect'` 走 `fillRect`，其余抛 `\LogicException`
  - `drawText/drawButton` → 抛 `\LogicException("not implemented in POC")`

#### 1.4 修改 `d:/Px/framework/Platform/Win32Platform.php`
- 文件顶部追加 `use Px\Rendering\SkiaRenderContext;`
- `init()` 方法第 30-32 行之间插入分支（**构造须传入 $width, $height**）：
  ```php
  $this->hwnd = vue_window_create($title, $width, $height);
  vue_window_show($this->hwnd, WinMsg::SW_SHOW);

  if (defined('APP_RENDERER') && APP_RENDERER === 'skia') {
      return new SkiaRenderContext($this->hwnd, $width, $height);
  }
  return new GdiRenderContext($this->hwnd);
  ```
- 位置：基于现有代码（`vue_window_show` 后），位于 `return new GdiRenderContext(...)` 之前

#### 1.5 修改 `d:/Px/framework/aot-checker.php` 第 138-144 行
- **只在 `excludedFiles` 数组追加 `SkiaRenderContext.php` 一项**（不追加 `skia_render.cc`，因为 `aot-checker` 只扫描 `.php` 文件，`.cc` 文件不在扫描范围内，追加无效）
- 同步检查 `excludedRefDirs`（147-149 行）无需改动
- `direct_cpp_call` 正则（130-134 行）只匹配 `vue_*`，`sk_*` 天然不误报，本阶段不扩展

#### 1.6 创建 POC 应用 `d:/Px/apps/skia-poc/`
- `main.php`（参照 `apps/calculator-ng/main.php` 模板）：
  ```php
  const APP_PLATFORM  = 'win32';
  const APP_RENDERER  = 'skia';  // 关键新增
  const WINDOW_WIDTH  = 400;
  const WINDOW_HEIGHT = 300;
  const WINDOW_TITLE  = 'Skia POC';
  ```
- `App.vue`：根模板仅一个 `<div style="left:0;top:0;width:100%;height:100%;background:#1E88E5"></div>`
- `project.yml`：照抄 `apps/calculator-ng/project.yml`（`name: skia-poc`）
- **sources 路径选择**：
  - Swoole Compiler 的 `sources` 字段**支持目录递归**（`docs/swooler compiler AOT 编译器文档.md:174`）—— 现有 `sources: - ../../cpp` 会自动包含 `skia_render.cc`，**无需修改**
  - **防御性补充**：如果未来发现 Swoole Compiler 对新加 .cc 的索引有缓存延迟（需 `-f` 强制重编），可在 `project.yml` 中显式追加 `- ../../cpp/skia_render.cc`（行尾追加，与现有 `- ../../cpp` 并存，不冲突）

#### 1.7 构建验证
- 仓库根执行 `d:/Px/build.bat skia-poc`
- 验证 4 项：
  1. `build_full.log` 无 `LNK2019: unresolved external symbol php_sk_*`
  2. `dumpbin /symbols skia-poc.exe | findstr php_sk_` 列出 6 个新符号
  3. 双击 `skia-poc.exe`，窗口创建并显示蓝色矩形
  4. 临时 `error_log` 验证 `sk_create_window_context/sk_begin_frame/sk_fill_rect` 依次被调用

### Task 2：阶段二 — 补全渲染原语（GDI 兼容层）

**目标**：在 `SkiaRenderContext` 上补齐 `RenderContext` 全部 6 个抽象方法 + `drawElement` 12 路 switch，使 `calculator-ng` 在 `APP_RENDERER='skia'` 下完整复现。**仍不引入 Skia 库**。预计 3-4 小时。

#### 2.1 在 `d:/Px/cpp/skia_render.cc` 追加 6 个函数（~150 行）
- 全部仍用 Win32 GDI 真实实现，逐行对照 `vue_calc.cc` 中对应函数：
  - `Var php_sk_draw_text(Int x, Int y, String text, Int fontSize, Int rgb, Int bold)` — `SetBkMode + CreateFont + TextOut`
  - `Var php_sk_draw_round_rect(Int x, Int y, Int w, Int h, Int radius, Int rgb)` — `CreateRoundRectRgn + FillRgn`
  - `Var php_sk_alpha_fill_rect(Int x, Int y, Int w, Int h, Int rgb, Double opacity)` — `AlphaBlend`（`/msimg32.lib` 与 `vue_calc.cc:11` 一致）
  - `Var php_sk_draw_button(Int x, Int y, Int w, Int h, Int bgColor, Int borderColor)` — 浅色填充 + 1px 边框
  - `Var php_sk_push_clip(Int x, Int y, Int w, Int h)` — `SaveDC` 入栈
  - `Var php_sk_pop_clip()` — `RestoreDC`

#### 2.2 在 `d:/Px/stub/skia.stub.php` 追加 6 个声明
- 按相同顺序追加：`sk_draw_text`、`sk_draw_round_rect`、`sk_alpha_fill_rect`、`sk_draw_button`、`sk_push_clip`、`sk_pop_clip`

#### 2.3 在 `d:/Px/framework/Rendering/SkiaRenderContext.php` 补全实现
- 新增 `private array $clipStack = [];`（与 `GdiRenderContext.php:27` 一致）
- 补全 6 个抽象方法（`fillRect/beginFrame/endFrame` 已在 Task 1 实现）
- `drawElement(array $el)` → **1:1 移植 `GdiRenderContext::drawElement`**，把所有 `vue_*` 替换为 `sk_*`，含 12 路 case（`rect`、`text`、`group`、`button`、`input`、`scroll-container`、`scrollbar-v`、`scrollbar-h`、`clip-push`、`clip-pop`、`line-h`、`line-v`、`progress`）
- 私有 `pushClip/popClip` → 委派 `sk_push_clip/sk_pop_clip`

#### 2.4 验证 calculator-ng 全 UI 复现
- **临时修改 `apps/calculator-ng/main.php`**（阶段二验证专用）：
  ```diff
  const APP_PLATFORM  = 'win32';
  +const APP_RENDERER  = 'skia';  // 临时：阶段二验证用，跑完删
  const WINDOW_WIDTH  = 340;
  ```
  跑 `d:/Px/build.bat calculator-ng`，验证后**必须回滚**到无 `APP_RENDERER` 行（Git 友好：不要提交此临时修改）
- 检查 5 项：
  1. 数字按钮（圆角+文字）正确
  2. 显示屏（带边框 rect）正确
  3. 顶部操作按钮行（多种边框/背景色）正确
  4. 滚动列表 scroll-container + scrollbar-v 正确（如有）
  5. 透明度（hover/active 状态）正确

#### 2.5 **GDI 基线截图**（用户反馈 7.4 + 本次新增）
- 在阶段二验证完成后、**回滚临时 `APP_RENDERER` 修改之前**，临时保留 `APP_RENDERER='skia'` 时的运行截图
- 随后再次**临时修改** `apps/calculator-ng/main.php` 切回 GDI（删除 `APP_RENDERER` 行或设为 `'gdi'`），重新构建运行，截图保存为基线
- 两个基线截图都保存到 `d:/Px/tests/screenshot/baseline/`：
  - `calculator-ng-gdi.png`（GDI 模式，作为阶段三对比参照）
  - `calculator-ng-skia-stage2.png`（SkiaRenderContext + GDI 兼容层，作为阶段三前的"伪 Skia"快照）
- 验证后**必须回滚** `apps/calculator-ng/main.php`（Git 友好：不要提交此临时修改）
- 此步骤为**手动操作**，不需要写脚本
### Task 3：阶段三 — 接入真实 Skia 库

**目标**：将 `sk_*` 6 个基础原语底层从 GDI 切换为 Skia 调用。预计 5-8 小时（Skia 静态库预编译是大头）。

#### 3.1 Skia 静态库准备
- 选型：优先 aseprite 预编译 fork（针对 AOT 场景的稳定镜像），可避免从源码构建
- 平台：**Windows x64 Release**（本阶段只限 Windows MSVC；跨平台生成是 Phase 5 路线图）
- 输出位置：`d:/Px/cpp/skia/lib/skia.lib` + `d:/Px/cpp/skia/include/`
- 具体命令（git clone + ninja 一键构建）：
  ```bash
  # 1) 克隆 aseprite fork（针对 Windows 预编译包成熟）
  git clone https://github.com/aseprite/skia.git
  cd skia

  # 2) 同步依赖
  python tools/git-sync-deps

  # 3) 生成 Release 项目（按需启用 GPU backend，静态库输出）
  bin/gn gen out/Release --args="is_official_build=true \
    skia_use_system_libjpeg_turbo=false \
    skia_use_system_libpng=false \
    skia_use_system_libwebp=false \
    skia_use_system_zlib=false \
    skia_use_system_icu=false \
    skia_use_system_expat=false \
    skia_use_dawn=false \
    skia_use_direct3d=false \
    skia_use_metal=false \
    skia_use_vulkan=false \
    skia_use_gl=false"

  # 4) 编译（约 10-20 分钟，需 8 核以上）
  ninja -C out/Release
  ```
- 备选：vcpkg `vcpkg install skia:x64-windows`（可能版本偏旧，但环境配置简单）
- Spike 原则：**阶段三前必须先跑一次上述构建并验证 1 个矩形绘制可工作**；不通过则降级为 Direct2D 后端（实际是 Stage 2 加上 GDI+ 抗锯齿，不是真 Skia）

#### 3.2 修改 POC 应用 `project.yml`
- 在 `cxx-flags` 段追加 Skia 头路径与 `USE_SKIA` 宏定义：
  ```yaml
  cxx-flags:
    - /utf-8
    - /wd4267
    - /DUSE_SKIA
    - /I"../../cpp/skia/include"
  ```
- 在 `ld-flags` 段（**注意是 `ld-flags` 不是 `cxx-flags`**）追加 Skia 静态库与 Windows GPU 依赖：
  ```yaml
  ld-flags:
    - ../../cpp/skia/lib/skia.lib
    - d3d12.lib dxgi.lib d3dcompiler.lib windowscodecs.lib user32.lib gdi32.lib
  ```
- **跨平台标注**：本阶段仅限 Windows MSVC 环境；macOS/Linux 的 cxx-flags 差异（-fobjc-arc、-framework 等）留作 Phase 5 路线图
- 现有 7 个应用的 `project.yml` **不修改**，仍走 GDI 路径

#### 3.3 在 `d:/Px/cpp/skia_render.cc` 条件编译
- 引入 Skia 头（`USE_SKIA` 包裹）：
  ```cpp
  #ifdef USE_SKIA
  #include "core/SkSurface.h"
  #include "core/SkCanvas.h"
  #include "core/SkPaint.h"
  #include "core/SkFont.h"
  #include "core/SkFontMgr.h"
  #include "core/SkTypeface.h"
  #ifdef _WIN32
  #include "ports/SkFontMgr_New_FCI.h"  // Windows 系统字体管理器
  #endif
  static sk_sp<SkSurface> g_skSurface;
  static sk_sp<SkFont>    g_skFont;
  #endif
  ```
- 用 `#ifdef USE_SKIA ... #else ... #endif` 包裹所有 `php_sk_xxx` 实现：
  - `USE_SKIA` 分支：`SkSurface::MakeRasterN32Premul` / `SkCanvas::drawRect` / `SkPaint` / `canvas->drawString`
  - 否则保持阶段二的 GDI 实现（兜底）

#### 3.4 替换 6 个基础原语
- `php_sk_create_window_context` → 创建 SkSurface（`SkSurface::MakeRasterN32Premul(width, height)`），保存 `width/height` 到静态变量，初始化全局 `g_skFont`
- `php_sk_begin_frame` → `g_skSurface->getCanvas()->save()`
- `php_sk_fill_rect` → `canvas->drawRect(SkRect, SkPaint)` 享受抗锯齿
- `php_sk_draw_round_rect` → `canvas->drawRRect(SkRRect, SkPaint)` 圆角完美抗锯齿
- `php_sk_alpha_fill_rect` → `paint.setAlphaf(opacity)` 混合精确
- `php_sk_draw_text` → `canvas->drawString(text, x, y, *g_skFont, paint)`
- `php_sk_end_frame` → `BitBlt` 拷贝 SkSurface 像素到窗口 HDC（阶段一 B GDI 创建设备上下文复用）
- `php_sk_push_clip/pop_clip` → `canvas->saveLayer` / `canvas->restore`

##### 3.4.1 **字体回退（关键预备点）**——阶段三 POC 必须验证的一项
Skia 默认不含系统字体，必须手动初始化 `SkFontMgr`：
```cpp
// 阶段三 POC 首选方案：Windows DirectWrite 字体管理器
#ifdef _WIN32
sk_sp<SkFontMgr> fontMgr = SkFontMgr_New_DirectWrite();
sk_sp<SkTypeface> typeface = fontMgr->matchFamilyStyle("Segoe UI", SkFontStyle());
g_skFont = sk_make_sp<SkFont>(typeface, 12);
#endif
```
**备选**：若 DirectWrite 不可用（或需要零依赖）可使用 `SkFontMgr_New_FCI`（Windows GDI/DirectWrite 联合）或嵌入一个开源字体：
```cpp
// 备选：嵌入 NotoSans-Regular.ttf （阶段三仅作 POC）
#include "notosans_regular.h"  // xxd 生成的 .h 文件
auto typeface = SkTypeface::MakeFromData(SkData::MakeWithoutCopy(
    kNotoSansRegular_ttf, sizeof(kNotoSansRegular_ttf)));
```
**验证标准**：POC 阶段三启动后，在窗口中央画一个含 "Hello Skia 123" 的 text 元素，能正常显示（不出现空白、方框、或崩溃），才算字体问题初验通过。否则 `apps/calculator-ng` 数字按钮会全部空白。

#### 3.5 视觉对比验证
- 截图保存到 `d:/Px/apps/skia-poc/screenshots/skia-rounded.png`
- 与 `apps/design-guide/screenshots/` 已有 GDI 截图对比：圆角边缘平滑度、文本次像素渲染、半透明叠加准确性
- **R9 风险对策**：在阶段三 POC 阶段保留一个编译开关 `STRICT_MODE`（默认关闭）——开启后 `php_sk_fill_rect` 使用 `SkPaint::setAntiAlias(false)` 并走 GDI 的 FillRect 调用；关闭后走真 Skia。两者像素 1:1 对比可以发现 `drawElement` 中的细节问题。
#### 3.6 **窗口 resize 支持**（用户反馈 3.1 + 7.1，R12 风险对策）
**问题**：Skia Surface 在 `sk_create_window_context` 时基于初始尺寸创建，若窗口被拖拽改变大小（`WM_SIZE`），Surface 与实际窗口像素尺寸不一致，导致绘制拉伸或错位。

**实现方案**（4 个子项）：

**3.6.1 C++ 端**——在 `d:/Px/cpp/skia_render.cc` 新增 1 个函数：
```cpp
#ifdef USE_SKIA
void php_sk_resize_context(Int width, Int height) {
    if (g_skSurface && g_skSurface->width() == (int)width && g_skSurface->height() == (int)height) {
        return;  // 尺寸未变，无须重建
    }
    g_skSurface = SkSurface::MakeRasterN32Premul((int)width, (int)height);
    g_skW = (int)width;
    g_skH = (int)height;
}
#endif
```

**3.6.2 Stub 端**——在 `d:/Px/stub/skia.stub.php` 追加 1 个声明：
```php
function sk_resize_context(int $width, int $height): void;
```

**3.6.3 PHP 端**——在 `d:/Px/framework/Rendering/SkiaRenderContext.php` 新增 1 个公开方法：
```php
public function resizeContext(int $width, int $height): void {
    sk_resize_context($width, $height);
}
```

**3.6.4 事件路由**——在 `framework/Platform/PlatformEvent.php` 与 `Win32Platform.php` 中：
- 监听 `WM_SIZE` 消息（`PlatformEvent::action === 'resize'`）→ 解码出 `width, height` → 调 `renderContext->resizeContext($width, $height)` → `requestRender()`
- 位置：`framework/Platform/Win32Platform.php` 现有 `handleWindowEvent()` 分支中追加 resize case
- **兜底机制**：`php_sk_begin_frame` 内部检查 `g_skSurface->width() != g_skW` 时自动调用 `php_sk_resize_context`（防止事件丢失导致表面失配）

**验证**：手动拖动窗口边框 / 点击最大化，POC 应用不出现拉伸错位或黑边

#### 3.7 **sk_clear_window 退化为空实现**（用户反馈 3.2 + 7.2，R14 风险对策）
**问题**：阶段一/二中 `sk_clear_window` 用于 GDI 全窗口清屏。阶段三使用 Skia 后，根节点绘制前通常通过 `canvas->clear(SK_ColorWHITE)` 或背景矩形覆盖，`sk_clear_window` 可能不再需要。

**实现决策**（保持接口一致 + 零调用点修改）：
- C++ 端 `php_sk_clear_window`（`USE_SKIA` 分支）变为空实现（仅返回，不调用任何 API）：
  ```cpp
  #ifdef USE_SKIA
  void php_sk_clear_window(Int rgb) {
      // 阶段三后不需在清屏函数中绘背景
      // 背景清屏统一在 drawElement 根节点绘制前 canvas->clear() 完成
      (void)rgb;  // 静默未使用参数警告
  }
  #endif
  ```
- Stub 签名**保留**（`sk_clear_window(int $rgb): void`）以维持与阶段一/二的接口一致，避免拆改所有调用点
- PHP 端 `SkiaRenderContext::clearWindow()` 照旧委派 `sk_clear_window`
- **避免选择**：不采用"在 `beginFrame` 中调 `sk_clear_window` 内部仅当 `g_skSurface` 存在时执行 `canvas->clear(0)`"的方案，原因：`sk_clear_window` 需要外部传入 `rgb` 参数，而 `canvas->clear(0)` 只接受透明色；使用透明色可能导致背景闪烁。保持空实现 + 根节点绘制前 `canvas->clear(SK_ColorWHITE)` 是更可控的设计
- 文档说明：在 `docs/skia-render-context-guide.md` 4.2 节明确"阶段三后 `sk_clear_window` 为空实现，背景清屏统一在 `drawElement` 根节点 `canvas->clear()`"

**验证**：阶段三跑 `apps/calculator-ng`，全窗口背景色与 GDI 模式一致（深灰或系统色）即可

#### 3.8 **sk_alpha_fill_rect 合并实现**（7.3 反馈，R15 风险对策）
**问题**：Skia 中 `sk_alpha_fill_rect(x, y, w, h, rgb, opacity)` 与 `sk_fill_rect(x, y, w, h, rgb)` 本质相同——都是 `canvas->drawRect()` 调用，唯一区别是 `paint.setAlphaf(opacity)`。可合并实现。

**实现**：
- C++ 端 `php_sk_alpha_fill_rect`（`USE_SKIA` 分支）：
  ```cpp
  #ifdef USE_SKIA
  Var php_sk_alpha_fill_rect(Int x, Int y, Int w, Int h, Int rgb, Double opacity) {
      if (!g_skSurface) return nullptr;
      SkPaint paint;
      paint.setAntiAlias(true);
      paint.setColor((SkColor)(int)rgb);
      paint.setAlphaf((SkScalar)(double)opacity);  // 仅此行与 fill_rect 不同
      g_skSurface->getCanvas()->drawRect(
          SkRect::MakeXYWH((SkScalar)(int)x, (SkScalar)(int)y,
                           (SkScalar)(int)w, (SkScalar)(int)h),
          paint);
      return nullptr;
  }
  #endif
  ```
- 与 `php_sk_fill_rect` 实现**仅一行不同**（`paint.setAlphaf(...)`）
- 函数签名保留（6 个形参不变），stub 保持稳定
- 验证：阶段三跑 `apps/calculator-ng` 中 hover/active 状态的半透明叠加，透明度与 GDI 模式一致（0.0~1.0 范围）

### Task 4：文档与长期演进

#### 4.1 新增 `d:/Px/docs/skia-render-context-guide.md`
- 章节：背景、架构图（RenderContext 抽象 → GdiRenderContext / SkiaRenderContext）、AOT 接入步骤、调试技巧、性能数据、已知限制、Roadmap（macOS/Linux 平台绑定）

#### 4.2 归档草稿与新增实施文档
- **新建 `d:/Px/docs/archive/` 目录**（如果不存在）
- **移动** `d:/Px/docs/skiaRenderContext 实现规划草案.txt` → `d:/Px/docs/archive/skiaRenderContext 实现规划草案.txt`
- 在 `d:/Px/docs/archive/skiaRenderContext 实现规划草案.txt` 顶部加注：`> 状态：草稿。已被 d:/Px/docs/skia-render-context-guide.md 替代。归档于 docs/archive/，保留作为历史参考。`
- 新建 `d:/Px/docs/skia-render-context-guide.md`（实施过程文档）

#### 4.3 更新 `d:/Px/AGENTS.md`
- 新增"渲染后端切换"小节，给出 GDI / Skia 模式示例

## 4. 关键文件清单

### 4.1 新增文件
| 路径 | 行数估算 | 所属阶段 |
|---|---|---|
| `d:/Px/cpp/skia_render.cc` | ~230 行（80 → 150 → 重构） | Task 1 |
| `d:/Px/stub/skia.stub.php` | ~50 行 | Task 1 |
| `d:/Px/framework/Rendering/SkiaRenderContext.php` | ~250 行 | Task 1 |
| `d:/Px/apps/skia-poc/main.php` | 15 行 | Task 1 |
| `d:/Px/apps/skia-poc/App.vue` | 10 行 | Task 1 |
| `d:/Px/apps/skia-poc/project.yml` | 18 行 | Task 1 |
| `d:/Px/apps/skia-poc/screenshots/skia-rounded.png` | 二进制 | Task 3 |
| `d:/Px/docs/skia-render-context-guide.md` | ~200 行 | Task 4 |

### 4.2 修改文件
| 路径 | 改动点 | 所属阶段 |
|---|---|---|
| `d:/Px/framework/Platform/Win32Platform.php` | 第 6 行后追加 `use`、第 30-32 行插入分支（传递 $width, $height） | Task 1 |
| `d:/Px/framework/aot-checker.php` | 第 138-144 行 `excludedFiles` 追加 1 项（仅 `SkiaRenderContext.php`） | Task 1 |
| `d:/Px/framework/Rendering/RenderContext.php` | 追加 `use native_types;`（13 行小文件，6 个抽象方法无动态类型，安全） | Task 1 |
| `d:/Px/apps/skia-poc/project.yml` | 追加 `cxx-flags` / `ld-flags` Skia 头与库 | Task 3 |
| `d:/Px/cpp/skia_render.cc` | 顶部追加 `#pragma comment(lib, "msimg32.lib")` | Task 1 |
| `d:/Px/cpp/skia_render.cc` | 阶段三新增 `php_sk_resize_context` | Task 3.6 |
| `d:/Px/framework/Platform/Win32Platform.php` | 监听 `WM_SIZE` → `renderContext->resizeContext()` | Task 3.6 |
| `d:/Px/docs/skiaRenderContext 实现规划草案.txt` | 移动到 `docs/archive/` + 顶部加注历史标记 | Task 4 |
| `d:/Px/AGENTS.md` | 新增"渲染后端切换"小节 | Task 4 |

### 4.3 不修改（保持兼容）
- `d:/Px/framework/Rendering/GdiRenderContext.php`（368 行，GDI 完整保留）
- `d:/Px/framework/Core/Application.php`（`initRenderer` 不动，构造注入天然兼容）
- 全部 7 个现有应用（calculator-ng / design-guide / list-test / multi-scroll / aot-property-test / aot-syntax-test / video-platform），默认 `APP_RENDERER='gdi'` 零改

## 5. 风险与对策

| 风险 | 概率 | 对策 |
|---|---|---|
| R1：Skia 静态库下载/编译阻塞 | 中 | 阶段三前先做 1 小时 spike 验证预编译包；不行则降级 Direct2D 或 bgfx |
| R2：AOT 类型推导链断裂（LNK2019） | 低 | 严格保持 stub 形参与 C++ `php::Int/String/Double` 一一对应；回查 `project.yml::sources` |
| R3：字体回退差异 | 中→高 | **阶段三 POC 强制验证**。首选 `SkFontMgr_New_DirectWrite`（Windows 原生）；备选嵌入 NotoSans-Regular.ttf；阶段三前先在 POC 验证 `drawText`（参见 Task 3.4.1） |
| R4：现有 7 个应用 GDI 回归 | 低 | Task 1/2 完成后必须跑 `build.bat` 全部 7 个应用回归 |
| R5：aot-checker `direct_cpp_call` 误报 | 低 | 当前正则只匹配 `vue_*` 9 个函数，`sk_*` 天然不触发；如未来需扩展到 `sk_*`，在 `framework/aot-checker.php:130-134` 的正则中追加 `\bsk_[a-z_]+\(` 分支 |
| R6：阶段一 GDI 兜底"看起来工作但没切到 Skia" | 中 | Task 1 验证时 `SkiaRenderContext::__construct` 临时加 `trigger_error("SKIA PATH", E_USER_NOTICE)` |
| R7：SkSurface → HDC 像素拷贝开销 | 中 | 阶段三先实现 CPU `SkSurface::MakeRasterN32Premul` + `BitBlt`；GPU backend 后续优化 |
| R8：clip 栈语义差异 | 低 | 阶段二严格用 `SaveDC/RestoreDC` 1:1 移植 GDI 行为；阶段三切到 `saveLayer/restore` 时增加嵌套 clip 3 层测试 |
| **R9：阶段一/三行为不一致**（GDI 兜底 vs 真 Skia） | 中 | Task 3.5 保留 `STRICT_MODE` 编译开关（GDI 参照）作为参照；与 GDI 参考截图像素 1:1 对比 |
| **R10：`sk_begin_frame` 中 HDC 的跨函数传递** | 中 | `cpp/skia_render.cc` 中使用静态变量 `g_skHdc` 存储 `BeginPaint` 返回的 HDC；`php_sk_end_frame` 中 `EndPaint` 后清空。与 `vue_begin_paint/vue_end_paint` 模式严格对齐（参见 Task 1.1） |
| **R11：多窗口支持的现状限制** | 低（本阶段不处理） | 当前设计（`g_skHwnd/g_skHdc/g_skSurface` 静态全局）在多窗口下冲突。本阶段**明确仅支持单窗口**。阶段三后期可重构为以 `contextId` 为键的映射表（`std::unordered_map<int, SkiaBox*>`），或使用 `php::Box` 机制让 `sk_create_window_context` 返回 `mixed`（Box 资源）携带 SkSurface 跨函数传递（参见 `docs/swooler compiler AOT 编译器文档.md:1672-1814`）。多窗口架构重构作为未来 Phase 6 路线图 |
| **R12：窗口 resize 时 SkSurface 失配**（用户反馈 3.1） | 中 | 阶段三**强制补充** `sk_resize_context(int width, int height)`：监听 `WM_SIZE`（`PlatformEvent::resize`）→ 比较新尺寸与 `g_skSurface->width()/height()` → 不一致则 `g_skSurface = SkSurface::MakeRasterN32Premul(w, h)`，同步更新 `g_skW/g_skH`（参见 Task 3.6）。`sk_begin_frame` 中作为兜底再检查一次 |
| R13：阶段二 `drawElement` 12 路 case 漏迁移 | 中 | Task 2.3 严格 1:1 移植 `GdiRenderContext::drawElement` 全部分支；Task 2.4 验证 calculator-ng 全部 UI 元素（数字按钮、显示屏、顶栏按钮、scrollbar）正确显示 |
| **R14：阶段三 `sk_clear_window` 接口冗余**（用户反馈 3.2） | 低 | 阶段三后 `sk_clear_window` 不再需要（清屏统一在 `drawElement` 根节点绘制前 `canvas->clear()` 完成）；保留空实现以维持 stub 签名稳定（参见 Task 3.7） |
| R15：`sk_alpha_fill_rect` 与 `sk_fill_rect` 重复 | 低 | Skia 中两者仅 `paint.setAlphaf()` 差异；`sk_alpha_fill_rect` 内部直接 `drawRect`（与 `fillRect` 同实现），无需独立绘制逻辑（参见 Task 3.8） |

### 5.1 实施后检查清单（基于用户反馈）

以下要点是在反馈文档 `.qoder/specs/细节检查与修正建议.txt`、`.qoder/specs/潜在遗漏与建议.txt` 中明确提出的，均已合并到本规划中：

- [x] 2.1：`Win32Platform::init()` 调用 `new SkiaRenderContext($hWnd, $width, $height)` 传递宽高（Task 1.4）
- [x] 2.2：`aot-checker.php` 只追加 `SkiaRenderContext.php`，不追加 `skia_render.cc`（Task 1.5）
- [x] 2.3：`project.yml` cxx-flags 跨平台标注为"仅限 Windows MSVC"（Task 3.2）
- [x] 2.4：阶段三 POC 明确字体回退方案（Task 3.4.1，强制验证项）
- [x] 2.5：`cpp/skia_render.cc` 顶部追加 `#pragma comment(lib, "msimg32.lib")`（Task 1.1）
- [x] 2.6：sources 路径决策——以目录递归为主，为防万一给出显式追加 `skia_render.cc` 的防御性补充（Task 1.6）
- [x] R9：阶段三保留 `STRICT_MODE` 兜底开关（Task 3.5）
- [x] R10：`g_skHdc` 静态变量存储 `BeginPaint` HDC，与 `vue_begin_paint` 对齐（Task 1.1）
- [x] R11：明确仅支持单窗口，多窗口重构作为未来 Phase 6 路线图（Section 5）
- [x] **3.1（用户本次反馈）**：阶段三补充 `sk_resize_context(width, height)`，监听 `WM_SIZE` 重建 SkSurface；`sk_begin_frame` 兜底检测（Task 3.6）
- [x] **3.2（用户本次反馈）**：阶段三 `sk_clear_window` 保留为空实现，根节点绘制前 `canvas->clear()`（Task 3.7）
- [x] 7.1：阶段三补充 `sk_resize_context(width, height)`（Task 3.6）
- [x] 7.2：阶段三 `sk_clear_window` 保留为空实现（Task 3.7）
- [x] 7.3：`sk_alpha_fill_rect` 与 `sk_fill_rect` 合并实现（仅 `paint.setAlphaf()` 差异），函数签名保留（Task 3.8）
- [x] 7.4：阶段二完成后手动保存 GDI 模式 `calculator-ng` 截图作为基线，阶段三再对比（Task 2.5 + Section 6.7）
- [x] 文档改进 1：Skia 静态库准备给出 git clone + ninja 完整命令（Task 3.1）
- [x] 文档改进 2：归档草稿到 `docs/archive/` + 顶部加注历史标记（Task 4.2）
- [x] 文档改进 3：阶段三前 spike 2 小时编译 Skia 静态库 + 最小 C++ 测试程序（Task 3.1 末尾）
- [x] 8.1：阶段一 3 小时内完成，验证 AOT 符号链接流程
- [x] 8.2：阶段二不要压缩，完整移植 12 路 switch
- [x] 8.3：阶段三 spike 前置——2 小时编译 Skia 静态库 + 最小 C++ 测试程序验证 `SkSurface → BitBlt` 流程
- [x] 8.4：补充窗口 resize 支持作为阶段三的附加任务
- [x] 8.5：保持 GDI 默认，所有现有 7 个应用的 `main.php` 不添加 `APP_RENDERER`，零回归

## 6. 验证步骤

### 6.1 阶段一验证（POC：AOT 链路）
- [ ] `build.bat skia-poc` 退出码 0
- [ ] `build_full.log` 无 `LNK2019: unresolved external symbol php_sk_*`
- [ ] `dumpbin /symbols skia-poc.exe | findstr php_sk_` 列出 6 个新符号
- [ ] 双击 `skia-poc.exe`，窗口创建，显示蓝色矩形，无崩溃
- [ ] 临时 `error_log` 验证 `sk_create_window_context/sk_begin_frame/sk_fill_rect` 依次被调用
- [ ] Task 2.4 临时修改 `apps/calculator-ng/main.php` 后**回滚验证**：Git diff 应为空

### 6.2 阶段二验证（GDI 兼容层：全 UI 复现）
- [ ] `build.bat calculator-ng`（临时 `APP_RENDERER='skia'`）退出码 0
- [ ] 数字按钮（圆角+文字）显示正确
- [ ] 显示屏（带边框 rect）显示正确
- [ ] 顶部操作按钮行（多色）显示正确
- [ ] 滚动条 thumb/track 比例与位置正确
- [ ] 透明叠加（如 hover 高亮）颜色与 GDI 路径一致（允许 ±2/255 误差）
- [ ] 关闭并重启 3 次，无内存泄漏

### 6.3 阶段三验证（真 Skia：视觉对比 + 字体）
- [ ] `d:/Px/cpp/skia/lib/skia.lib` 存在且链接成功
- [ ] **字体问题验证**（POC 启动后在窗口画 "Hello Skia 123" text 元素）：能正常显示，不出现空白或方框
- [ ] `USE_SKIA` 宏定义后 `php_sk_fill_rect` 走 `SkCanvas::drawRect` 路径
- [ ] `STRICT_MODE` 兜底开关可切换 GDI / Skia 两种路径
- [ ] 圆角矩形边缘目视无锯齿（10x 放大截图对比 GDI 路径）
- [ ] 文本笔画平滑（尤其小字号 12px）
- [ ] 帧时间在 `tests/perf/skia-vs-gdi.md` 记录：Skia 优于或等于 GDI
- [ ] 截图保存到 `d:/Px/apps/skia-poc/screenshots/skia-rounded.png`

### 6.4 回归验证（现有应用零影响）
- [ ] `build.bat calculator-ng` 退出码 0（默认 GDI）
- [ ] `build.bat design-guide` 退出码 0
- [ ] `build.bat list-test` 退出码 0
- [ ] `build.bat multi-scroll` 退出码 0
- [ ] `build.bat aot-property-test` 退出码 0
- [ ] `build.bat aot-syntax-test` 退出码 0
- [ ] 上述 6 个应用运行无 GDI 渲染回归（视觉与 Task 0 基线一致）
- [ ] `aot-checker.php --project <APP_DIR> --skip direct_cpp_call` 在 6 个应用上无新增 ERROR

### 6.5 长期验证（每季度）
- [ ] 新平台（macOS/Linux）接入时，`SkiaRenderContext` 类不需修改，仅需新增 `sk_*` 实现层
- [ ] `docs/skia-render-context-guide.md` 与实际代码保持同步（半年 review 一次）

### 6.6 Spike 验收（阶段三前必须项）
- [ ] Task 3.1 的 `ninja -C out/Release` 成功生成 `skia.lib`
- [ ] 能在一个最小 POC（仅 fill_rect + 一个 text）中验证 Skia 绘制工作
- [ ] 不通过 → 降级为 Direct2D 后端（不作为正式 Skia 交付）

### 6.7 **基线截图对比**（Task 2.5 + 7.4）
- 阶段二完成后，**手动运行** `apps/calculator-ng` 在 GDI 模式下，截图保存到 `d:/Px/tests/screenshot/baseline/calculator-ng-gdi.png`（用 Windows 截图工具或 PowerShell 截屏脚本）
- 阶段三接入真 Skia 后，再截图保存到 `d:/Px/tests/screenshot/baseline/calculator-ng-skia.png`
- **对比要点**：
  1. 圆角边缘像素差异（Skia 应更平滑）
  2. 文字边缘清晰度（Skia 应有抗锯齿）
  3. 半透明叠加区域颜色一致性
  4. 整体布局坐标一致性（无错位）
- 自动化基线对比**不强制**（框架缺乏截图对比基础设施），手动对比即可

## 7. 补充说明：与 AOT native_types 链式依赖的兼容性

根据记忆 `AOT 链式类型推导要求调用链所有类声明 use native_types` 的提示，为保证阶段三 Skia 集成能享受 AOT 类型推导优化，需检查调用链上所有 `use native_types`：

- `framework/Rendering/SkiaRenderContext.php` 需 `use native_types;`（本规划 Task 1.3 已明确）
- `framework/Rendering/RenderContext.php` **当前未声明 `use native_types;`** ——如果 `SkiaRenderContext` 的 `parent::` 方法（`beginFrame/endFrame/drawElement/fillRect/drawText/drawButton`）需要在 native_types 上下文编译，必须为 `RenderContext.php` 追加 `use native_types;`
- **建议**：阶段一添加 `use native_types;` 到 `RenderContext.php`（该文件 13 行，只有 6 个抽象方法声明，无动态类型使用，加 native_types 是安全的）——这是阶段一 SkiaRenderContext 得以享受性能优化的必要前提

## 8. 补充：用户原反馈中的 5 条最终建议

1. **立即开展阶段一**——验证 AOT 链接链路，是后续所有工作的基础。
2. **阶段二不要跳过**——`calculator-ng` 的 UI 复杂度足以暴露所有原语的正确性。
3. **阶段三前先做一个 spike**——2 小时尝试编译 Skia 静态库并运行 `skia-poc` 的单个矩形绘制，确认环境可行（参见 Section 6.6）。
4. **字体问题优先解决**——阶段三 `sk_draw_text` 实现中，至少保证英文字母和数字显示（使用 `SkFontMgr::RefDefault()` 在 Windows 上会自动映射到系统字体，但需验证，参见 Task 3.4.1）。
5. **保持 GDI 后端为默认**——所有现有应用无需改动，降低回归风险。

## 9. 路线图（未来 Phase）

- **Phase 4（性能优化）**：Skia GPU backend（Direct3D 12 / Metal / Vulkan），R7 风险进一步降级
- **Phase 5（跨平台）**：macOS（Skia Metal）、Linux（Skia Vulkan）后端绑定
- **Phase 6（多窗口）**：基于 `php::Box` 重构 SkSurface 跨函数传递，移除静态全局变量
- **Phase 7（自动化测试）**：补全截图对比基础设施，将 Section 6.7 的手动对比自动化