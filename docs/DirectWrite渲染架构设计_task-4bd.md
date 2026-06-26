## DirectWrite 文本渲染集成方案

### 架构原则
保持最小改动，不引入 PHP 层抽象。文本渲染的复杂度和平台相关性集中在 C++ 层，PHP 层通过 `RenderContext::drawText()` 透明调用。

### 当前架构

```
PHP RenderContext::drawText()
  └─ php_sk_draw_text(x, y, text, fontSize, rgb, bold)  ← C++ 层
       ├─ #ifdef USE_SKIA → Skia (g_skFont + drawString)
       └─ #else → GDI (CreateFont + DrawTextW)
```

### 目标架构

```
PHP RenderContext::drawText()
  └─ php_sk_draw_text(x, y, text, fontSize, rgb, bold)  ← C++ 层
       ├─ DirectWrite rendering (try first)  ← 新增
       ├─ #ifdef USE_SKIA → Skia (fallback)
       └─ #else → GDI (fallback)
```

### 实现步骤

① **C++ `drawTextDWrite` 函数**（`skia_render.cc`）：
   - 复用现有的 `ensureDWriteFactory()` + `g_dwFactory`
   - 创建 `IDWriteTextFormat` + `IDWriteTextLayout`
   - 设置文本内容、字体、大小、粗细、颜色
   - 使用 `IDWriteBitmapRenderTarget` 渲染到 HDC（与 GDI 共享设备上下文）
   - 返回 bool 表示成功/失败

② **修改 `php_sk_draw_text`**：
   ```
   if (g_textEngine == DWRITE || g_textEngine == AUTO) {
       if (drawTextDWrite(...)) return;
   }
   // 继续现有 Skia/GDI 路径
   ```

③ **配置切换**：
   - 新增 `php_sk_set_text_engine(string engine)` 原生函数
   - 参数: "dwrite" / "gdi" / "skia" / "auto"（默认 auto）
   - 静态变量 `g_textEngine` 存储当前选择
   - PHP 层通过 `project.yml` 的 `Px_debug_text_engine` 配置调用

④ **自动降级**：
   - DirectWrite 初始化失败 → 静默回退到 Skia/GDI（不报错）
   - `drawTextDWrite` 内部捕获 COM 异常，失败返回 false

### 文件修改
- `cpp/skia_render.cc` — 新增 `drawTextDWrite` + `php_sk_set_text_engine`
- `stub/skia.stub.php` — 添加 `sk_set_text_engine` 声明
- `framework/Rendering/GdiRenderContext.php` — 调用 `sk_set_text_engine`（可选）
- `framework/Rendering/SkiaRenderContext.php` — 同上

### 验证
1. 构建: `build.bat css-test`
2. 运行: `php test_pipeline.php --case=case-003-basic-block --skip-build --browser-engine-el-compare`
3. 对比 element_compare_report 的 height MISMATCH 是否缩小（从 26px vs 21.6px 到更接近）