# CSS 标准对齐迭代工作流 — AI 驱动

> **目标**：通过 `apps/css-test/` 统一测试框架 + 自动化迭代，逐步使 Px 框架渲染结果与浏览器（Edge Chromium）像素级一致。
>
> **核心思想**：数据驱动差异分析 → 定位根因 → 区分框架问题与应用问题 → 治本修复 → 回归验证 — 形成持续迭代闭环。

---

## 一、核心原则

| 原则 | 说明 |
|------|------|
| **先验证后修复** | 跑完整测试链，让数据告诉你差异在哪，不靠猜测 |
| **治本不治标** | 框架层的 bug 在框架层修复，不在 App.vue 打补丁 |
| **通用合规** | 修复应符合 CSS 标准，不针对特定测试特化 |
| **分治** | 每个 test_case 只测一个 CSS 特性或一个页面区域 |
| **回归防护** | 每次修复后必须验证原有测试不退化 |
| **排假阳** | 差异出现时，先排除浏览器 wrapper HTML 本身引入的基线差异 |
| **工具共享优先** | 所有工具优先使用已有的共享库（`shared_test_lib.php` 等）；增强修复优先应用到共享工具 |
| **持续追踪** | 每个项目独立维护 Bug 台账，记录所有已知缺陷及其修复状态 |
| **CSS 标准铁律** | 框架层 fallback 必须使用 CSS 标准默认值。项目想要的非标准行为（如 `box-sizing:border-box`）必须在样式声明中**显式写出来**。凡框架符合 CSS 标准而测试失败，必须核查修正应用层，不得改动正确的标准框架行为 |

**决策优先级**：差异出现时，先判断：
0. **框架符合 CSS 标准吗？** — 查看相关 CSS 属性的标准默认值。如果框架的 fallback 已经使用了 CSS 标准默认值（如 `box-sizing:content-box`），但测试期望非标准值（如 `border-box`）→ **框架正确，应用层缺显式声明**，改应用层 |
1. **浏览器 ref 生成 wrapper 引入了基线差异**（box-sizing/line-height/reset/字体不一致） → 修复 `buildCssTestWrapper()`，重新生成参考数据
2. **框架不符合 CSS 标准**（fallback 用了非标准默认值等） → 改框架 + 加测试
3. **框架符合 CSS 标准，应用层用法错** → 改应用
4. **框架尚未实现该特性** → 记录清单、实现或增强，并分析同类特性支持完善度

---

## 二、工具链速查

### 2.1 核心工具

| 工具 | 路径 | 用途 |
|------|------|------|
| 测试沙盒编排器 | `php apps/css-test/run.php` | 遍历 test_case/ 编译 → 布局导出 → 多帧验证 → 浏览器对比 → 报告 |
| SFC 编译器 | `php sfc-compiler.php apps/css-test` | .vue → gen/*.php |
| 构建脚本 | `.\build.bat css-test` | PHP → .exe（run.php 内部调用） |
| 布局导出 | `bin/css-test.exe --dump-layout` | → engine_layout.json |
| 浏览器 ref 生成 | `run.php` 内嵌 `generateBrowserRef()` 函数 | Edge headless 渲染 .html → JSON |
| 布局 JS 导出器 | `tools/dump_layout.js` | 注入浏览器 HTML，从 DOM 提取布局 JSON |
| 共享对比库 | `tools/shared_test_lib.php` | flattenEngineTree, compareElementEnhanced, alignImages, compareScreenshots |
| 截图工具 | `tools/capture_screenshot.ps1` | 应用/浏览器窗口截图 |
| 锚点对齐 | `tools/shared_test_lib.php::alignImages()` | 颜色锚点/模板匹配/自动检测 三策略 |
| 浏览器 ref 批量生成 | `tools/generate_browser_refs.php` | 旧版 css-test Level 参考（保留兼容，run.php 已内联） |
| 单项目 ref 生成 | `tools/generate_project_ref.php <project>` | 单项目浏览器参考 JSON |

### 2.2 css-test 测试沙盒目录结构

```
apps/css-test/
├── run.php                  测试沙盒编排器（入口）
├── main.php                 exe 入口（支持 --dump-layout）
├── App.vue                  根模板（自动加载 test_case 的组件）
├── components/              备用组件目录（动态组件模式下未使用）
├── test_case/               所有测试用例（每个独立目录）
│   ├── case-001-wrapper-x/
│   │   ├── Case001WrapperX.vue    引擎端模板
│   │   ├── Case001WrapperX.html   浏览器参考 HTML
│   │   ├── ref/
│   │   │   ├── engine_layout.json           引擎布局快照
│   │   │   ├── engine_layout_after_5frames.json  多帧后快照
│   │   │   └── browser_ref_level_0.json     浏览器参考数据
│   │   └── bin/                  构建产物缓存
│   ├── case-002-auto-height/
│   ├── case-003-basic-block/
│   ├── case-004-flex-layout/
│   ├── case-005-grid-layout/
│   ├── case-006-typography/
│   ├── case-007-border-styles/
│   ├── ...（标准布局/排版测试，至 case-026）
│   ├── case-027-scroll-diagnostic/
│   ├── case-028-scroll-block/
│   ├── case-029-scroll-flex-col/
│   ├── case-030-scroll-flex-row/
│   ├── case-031-scroll-grid/
│   ├── case-032-scroll-relative/
│   ...（持续扩展，当前 32 个 case）
├── gen/                    SFC 编译器输出（run.php 自动清空/重建）
├── bin/                    构建输出（.exe + .dll）
├── test_cases/             旧版 Level 测试（已迁移到 test_case/）
├── origin_case/            原始批量转换用例
├── project.yml             构建配置
└── engine_layout.json      全局布局快照（run.php 运行时产物）
```

### 2.3 关键框架文件

```
框架布局引擎（差异定位→修复入口）
  framework/Rendering/LayoutResolver.php             布局引擎调度入口
  framework/Rendering/Layout/BlockLayoutStrategy.php  Block 布局 + auto-height
  framework/Rendering/Layout/FlexLayoutStrategy.php   Flex 布局
  framework/Rendering/Layout/GridLayoutStrategy.php   Grid 布局
  framework/Rendering/Layout/InlineLayoutStrategy.php Inline 布局
  framework/Rendering/Layout/AbsolutePositioning.php  绝对/固定定位（第一遍）
  framework/Rendering/Layout/AbsoluteStrategy.php     绝对/固定定位策略（重构版）
  framework/Rendering/Layout/Tools/PercentResolver.php 百分比+单位解析
  framework/Rendering/Layout/Tools/ScrollHelper.php    滚动容器辅助
  framework/Rendering/Layout/MultiColumnLayoutStrategy.php 多列布局
  framework/Rendering/Layout/TableLayoutStrategy.php  表格布局
  framework/Rendering/CssMappings.php                 CSS → 内部属性映射
  framework/Rendering/CssValueParser.php              CSS 值解析（拆分自 CssMappings）
  framework/Core/Application.php                      serializeRenderNode 白名单

渲染系统
  framework/Rendering/GdiRenderContext.php            GDI 绘制实现
  framework/Rendering/SkiaRenderContext.php           Skia 绘制实现
  cpp/skia_render.cc                                  C++ 原生渲染层（字体加载/绘制原语/抗锯齿控制）
  framework/Rendering/VNode.php                       虚拟 DOM 节点
  framework/Rendering/RenderNode.php                  渲染专用节点（布局结果）
  framework/Rendering/VNodeRenderer.php               渲染树遍历+clip
  framework/Rendering/RenderTreeManager.php           VNode→RenderNode 转换
  framework/Rendering/TextOverflowProcessor.php       文本溢出处理
  framework/Rendering/ScrollbarEmitter.php             滚动条管理
  framework/Rendering/ImageManager.php                图片句柄缓存

渲染后端自动选择（Backend 系统）
  framework/Rendering/Backend/BackendRegistry.php     后端注册表（6 个候选）
  framework/Rendering/Backend/RuntimeBackendSelector.php 运行时选择器（probe+fallback）
  framework/Rendering/Backend/ResilientRenderContext.php 故障降级代理
  framework/Rendering/Backend/GdiLegacyBackend.php    GDI 传统后端（永远可用）
  framework/Rendering/Backend/GdiDirect2DBackend.php  GDI Direct2D 后端
  framework/Rendering/Backend/SkiaCpuBackend.php      Skia CPU 后端
  framework/Rendering/Backend/SkiaGaneshD3D11Backend.php Skia D3D11 后端
  framework/Rendering/Backend/SkiaGaneshWGLBackend.php  Skia WGL 后端
  framework/Rendering/Backend/SkiaGraphiteDawnBackend.php Skia Dawn 后端

测试工具
  tools/shared_test_lib.php                           共享对比函数库
  tools/dump_layout.js                                浏览器端布局数据提取脚本
  tools/generate_browser_refs.php                     css-test 多 Level ref 生成（旧版）
  tools/generate_project_ref.php                      单项目 ref 生成
  tools/capture_screenshot.ps1                        截图工具
  tools/screenshot_test.php                           截图测试
  tools/calibrate_anchors.php                         锚点校准
```

---

## 三、完整迭代流程（4 Phases）

### Phase 0：css-test 测试框架初始化

css-test 已作为标准测试项目存在。如需准备新测试环境：

```bash
# 1. 创建新 test_case 目录
apps/css-test/test_case/case-NNN-name/
├── CaseNnnName.vue         # Vue 模板（与 HTML 内容一致）
├── CaseNnnName.html        # 浏览器参考 HTML
├── ref/                    # 参考数据（由 run.php 自动生成）
└── bin/                    # 构建缓存（自动）

# 2. 编译（SFC 编译器自动扫描 test_case/ 目录）
php sfc-compiler.php apps/css-test/App.vue

# 3. 构建（一次构建，所有 case 共享同一个 exe）
.\build.bat css-test

# 4. 验证 --dump-layout 可用
cd apps/css-test/bin
.\css-test.exe --case=case-001 --dump-layout
```

**Vue 模板 → HTML 同步规则**：
- `.vue` 的 `<template>` 内容与 `.html` 的 `<body>` 内容必须一致（相同结构 + 相同 inline style）
- `.vue` 文件必须包含 `<script lang="php">class TestContent extends ReactiveComponent {}</script>`
- .html 使用自包含格式（`<!DOCTYPE html>` + `<style>` + `<body>`）
- 测试内容外层容器建议 720px 宽，居中布局

---

### Phase 1：添加/更新测试用例

**步骤**：

| # | 操作 | 命令/说明 |
|---|------|----------|
| 1.1 | 在 `test_case/` 创建新目录 `case-NNN-name/` | 命名建议：case-007-border-styles |
| 1.2 | 编写 `CaseNnnName.vue`（引擎端模板） | 从目标 HTML 提取，保持 inline style 不变 |
| 1.3 | 编写 `CaseNnnName.html`（浏览器参考 HTML） | 与 .vue `<template>` 内容一致 |
| 1.4 | 运行单个 case 验证 | `php apps/css-test/run.php --case=case-007-border-styles`（使用完整目录名） |
| 1.5 | 确认 wrapper CSS 与引擎基线一致 | 见 §四 buildCssTestWrapper() 规范 |
| 1.6 | 确认 `--window-size` 匹配引擎 | run.php 中 `--window-size=1600,800` |
| 1.7 | 全量运行 | `php apps/css-test/run.php` |

**测试用例编写规范**：
- 每个 case 独立一个目录，只测一个 CSS 特性范围
- `.vue` 及 `.html` 使用 inline style（不依赖外部 CSS），确保确定性
- 容器宽度建议 720px，居中（margin:0 auto），美观的卡片式设计
- 为关键测试元素加 `id` 属性（便于 `compareElementEnhanced` 精确匹配）
- 基础样式：`* { margin:0; padding:0; box-sizing:border-box; }` 匹配引擎
- 文件名使用 PascalCase（如 `BorderStyles.vue`），与目录名无关

**`<script>` 块规范**：
```php
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
```
- 如果 case 需要动态绑定，在类内定义属性和 `render()` 方法
- 简单 case 只需空类（继承 `render()` 的自动处理）

---

### Phase 2：构建 + 运行测试

```bash
# 全量测试
php apps/css-test/run.php

# 单用例测试（开发阶段常用）
php apps/css-test/run.php --case=case-007

# 高级选项
php apps/css-test/run.php --case=case-010 --frames=10 --verbose
php apps/css-test/run.php --skip-build                  # 跳过编译
php apps/css-test/run.php --skip-browser-ref             # 跳过浏览器对比
php apps/css-test/run.php --update-baseline              # 更新参考数据
```

**run.php 标准执行流程**：

```
Step A: 准备工作
   ├─ 解析 CLI 参数（--case= / --frames= / --skip-build 等）
   ├─ 遍历 test_case/ 下所有 case-NNN-* 目录
   └─ 如指定 --case，只处理匹配的单个用例

Step B: 构建（一次构建，所有 case 共享）
   ├─ Px_dynamic_component_file: test_case → sfc-compiler 自动扫描 test_case/ 目录
   ├─ 所有 .vue 编译为独立组件（如 Case029ScrollFlexColComponent）
   ├─ App.vue 通过 `<component :is="caseName">` 运行时动态加载
   ├─ 调用 build.bat css-test（单次构建产出单一 css_test.exe）
   ├─ 运行时通过 --case=case-xxx（完整目录名）选择测试用例
   └─ 所有 case 共享 apps/css-test/bin/css_test.exe

Step C: 布局导出 + 多帧稳定性
   ├─ <exe> --case=case-NNN-xxx --dump-layout → engine_layout.json
   ├─ <exe> --case=case-NNN-xxx --dump-layout-after-frames=N → ...frames.json
   ├─ 对比 Frame 1 vs Frame N 的布局 JSON（逐节点 x/y/w/h）
   └─ 任何跨帧变化标记为 STABILITY 问题

Step D: 布局内容一致性校验（新增）
   ├─ validateEngineLayoutContent() 检查 engine_layout.json 是否包含测试用例关键文本
   ├─ 防止 ref/ 目录下的过期参考数据被误用于对比
   └─ 内容不匹配时标记为 REF_STALE 错误，触发自动重新生成

Step E: 浏览器参考生成
   ├─ Edge headless 渲染 CaseNnnName.html → browser_ref_level_0.json
   ├─ 注入 dump_layout.js + normalize.css + Noto Sans SC 字体（Regular+Bold 分离声明）
   └─ 验证参考 JSON 结构完整性

Step F: 逐元素对比（compareElementEnhanced）
   ├─ flattenEngineTree() 展平引擎布局树
   ├─ 按标签/内容/id 匹配浏览器元素
   ├─ defaultChecks() 覆盖所有样式属性
   ├─ 位置 + 尺寸 + 样式三项对比
   ├─ bg 始终参与对比：未显式设置时导出 -1（透明），与浏览器 background-color 比对
   │   └─ 引擎透明 vs 浏览器非透明 → FAIL，消除背景色漏检盲区
   └─ Phase B 容器也新增 bg 对比（以前因 noTextStyle=true 完全跳过）

Step G: 截图对比（必须，独立于元素对比结果）
   ├─ exe --dump-layout 后自动截图 → exe_capture_{timestamp}.png
   ├─ Edge headless --window-size=1600,800 浏览器参考截图 → browser_ref_{timestamp}.png
   ├─ diff_{timestamp}.png 差异图
   ├─ alignImages() 锚点对齐（颜色锚点/模板匹配/自动检测 三策略）
   ├─ compareScreenshots() 像素级对比（cropAnchors 模式裁剪+缩放）
   └─ 截图步骤不被元素对比结果阻塞，即使有 FAIL 仍执行

Step H: 生成测试报告
   ├─ test_log/test_report_YYYYmmdd_HHMMSS.md
   ├─ 更新 test_log/latest_report.md
   └─ 控制台实时输出每步状态
```

**多帧稳定性验证（强制）**：

- 所有 case 必须执行 `--dump-layout-after-frames=N`（默认 5 帧）
- `run.php` 自动比较 Frame 1 与 Frame N 的布局 JSON，逐节点对比 x/y/w/h
- 任何节点跨帧变化（Δx/Δy/Δw/Δh ≠ 0）标记为 **STABILITY** 问题计入失败
- 已知触发场景：auto-height + absolute 子节点正反馈（见 §八 案例 3b）

**对比维度**（`shared_test_lib.php defaultChecks()` 当前覆盖）：

| 类别 | 属性 |
|------|------|
| 排版 | fontSize, fg(color), **bg（始终导出：-1=透明）**, bold(font-weight), textAlign, lineHeight, whiteSpace, wordBreak, fontStyle, textDecoration |
| 内边距 | paddingTop/Left/Right/Bottom |
| 外边距 | marginTop/Left/Right/Bottom |
| 边框 | borderWidth, borderColor, borderRadius, **borderTop/Right/Bottom/Left Width+Color** |
| 阴影/轮廓 | **boxShadow, outline** |
| 布局 | display, flexDirection, flexWrap, gap, alignItems, justifyContent, boxSizing |

> **粗体**为新近增补的属性。70+ 样式属性已覆盖。`bg` 现在**始终导出**：未显式设置 → `-1`（透明），确保即使无背景的元素也参与颜色对比，消除漏检盲区。

---

### Phase 3：分析测试报告

#### 3.1 报告结构

测试报告包含：

**① 用例汇总表**
```
| 用例 | 构建 | 布局导出 | 多帧稳定性 | 浏览器对比 | 截图像素 | 结果 | 耗时 |
|------|------|----------|------------|-----------|----------|------|------|
| case-007 | ✅ | ✅ | ✅ | ✅ | 差异=0.5% | ✅ 通过 | 12.3s |
```

**② 逐元素 JSON 对比详情**
```
[边框] target-box rel=(140,55) w=300 h=120
  ✅ x=144 y=63 w=300 h=120 (tol=1)
  ✅ borderTopWidth: engine=4 browser=4
  ✅ borderTopColor: engine=#e94560 browser=#e94560
  ✅ borderLeftWidth: engine=1 browser=1
  ✅ borderLeftColor: engine=#fb923c browser=#fb923c
```

**③ 每个 case 的 engine_layout.json** 持久化到 `test_case/case-NNN/ref/`

#### 3.2 差异分类与排查

| 差异类型 | 典型原因 | 修复位置 |
|----------|---------|---------|
| **假阳性** | 浏览器 ref wrapper 引入非标准基线（box-sizing/line-height/字体） | `run.php` → `buildCssTestWrapper()` |
| **位置偏差 (Δx/Δy > 1px)** | line-height 缺失/margin 折叠/padding 未计算/绝对定位 | `BlockLayoutStrategy` / `FlexLayoutStrategy` / `AbsolutePositioning` |
| **容器 auto-height 偏差** | auto-height 未减 paddingTop，或未排除 absolute/fixed 子节点 | `BlockLayoutStrategy` → `resolveBlockLayout()` |
| **Grid/Flex 子元素 w=0** | GridLayoutStrategy 未设 style['width'] / BlockLayout 重解释 | `GridLayoutStrategy` / `BlockLayoutStrategy` |
| **颜色不匹配** | GDI 颜色格式转换有误 / border-left 简写默认颜色 | `CssMappings` |
| **属性引擎缺失** | serializeRenderNode 白名单未添加 / CssMappings 未映射 | `Application.php` / `CssMappings` |
| **尺寸偏差 (w/h)** | 盒模型假设不一致 / 百分比解析 / 视口不匹配 | `PercentResolver` / `buildCssTestWrapper()` |
| **截图差异 > 5%** | 字体渲染 / 抗锯齿 / 颜色差异 / 布局偏移 | 联合 JSON 对比定位 |
| **截图中锚点找不到** | 窗口尺寸不对 / 色块被遮挡 / 偏移过大 | 检查 WINDOW_WIDTH/HEIGHT 匹配 |
| **STABILITY 问题** | 多帧间坐标或尺寸不稳定（auto-height 正反馈） | `BlockLayoutStrategy` auto-height 排除 absolute/fixed |

#### 3.3 决策树

```
差异出现
├─ JSON 对比显示引擎与浏览器 w/h/x/y 系统性偏移（所有元素同方向偏移相同量）？
│   ├─ 是 → 视口不一致 → 检查 buildCssTestWrapper() 的 `--window-size` 是否匹配引擎
│   └─ 否 → 继续
│
├─ JSON 对比显示引擎与浏览器 w/h 偏差（仅特定元素，非系统性）？
│   ├─ 容器 auto-height 偏差？
│   │   ├─ 是 → BlockLayoutStrategy auto-height 计算
│   │   └─ 否 → 其他布局差异，进入 Phase 4
│   └─ 子元素宽度未被父容器 padding 约束？→ 继承/百分比解析
│
├─ 引擎 JSON 位置/尺寸正确，但样式值不匹配（颜色/字号/行高/边框）？
│   ├─ 浏览器 wrapper 引入了非标准 line-height/reset？
│   │   ├─ 是 → **假阳性**：修复 buildCssTestWrapper() + 重新生成 ref
│   │   └─ 否 → 框架 bug，进入 Phase 4
│   └─ 颜色格式差异？→ CssMappings GDI 颜色格式转换
│
├─ 引擎 JSON 坐标/尺寸/样式与浏览器 ref 不一致（非上述情况）？
│   ├─ 是 → 框架布局/样式 bug → 进入 Phase 4
│   └─ 否 → 截图仍有差异？
│       ├─ 是 → 渲染效果差异（抗锯齿/字体/颜色格式）
│       └─ 否 → ✅ 通过
│
├─ 引擎无此属性（浏览器有）？
│   ├─ 框架尚未实现 → 记录清单并且严格按照css标准实现或增强
│   └─ 框架已实现但未导出 → serializeRenderNode 白名单
│
└─ STABILITY 标记？
    └─ auto-height/absolute 相关布局修改必须加多帧稳定性断言
```

---

### Phase 4：修复框架/应用缺陷

#### 4.1 框架修复路径

根据差异类型，进入对应的框架文件修改：

```
布局坐标错误 (x/y/w/h 不匹配)
  ├─ display:block 容器的 auto-stack 位置不对
  │   └─ framework/Rendering/Layout/BlockLayoutStrategy.php
  │     - auto-stack 推进逻辑（childOffsetY → visualH 含 padding）
  │     - auto-height 计算：`maxBottom - (node->y + paddingTop)`（CSS §10.6.3）
  │     - margin 折叠逻辑（§8.3.1）
  │     - 百分比宽度解析与父容器 fallback
  │     - **排除 position:absolute/fixed 子节点的 auto-height**
  │
  ├─ display:flex 容器/子项位置不对
  │   └─ framework/Rendering/Layout/FlexLayoutStrategy.php
  │     - justify-content/align-items 计算
  │     - flex-grow/shrink/basis
  │     - parent=null 时使用 WINDOW_WIDTH/HEIGHT fallback
  │
  ├─ display:grid 子项 w=0
  │   └─ framework/Rendering/Layout/GridLayoutStrategy.php
  │     - 设置 child->w 同时设置 child->style['width']
  │
  ├─ position:absolute/fixed 定位不对
  │   └─ framework/Rendering/Layout/AbsolutePositioning.php / AbsoluteStrategy.php
  │     - padding box 边界计算公式
  │     - positioningAncestor 缓存失效
  │     - two-pass 容器 auto-height + padding
  │
  ├─ inline 布局问题
  │   └─ framework/Rendering/Layout/InlineLayoutStrategy.php
  │
  └─ 百分比/相对单位不生效
      └─ framework/Rendering/Layout/Tools/PercentResolver.php
        - parentSize=0 fallback 逻辑
        - calc() 表达式解析

样式属性值不匹配
  ├─ CSS 属性未映射到内部 key
  │   └─ framework/Rendering/CssMappings.php
  │     - 在 $propertyMap 添加新映射
  │     - 在 $pctMap 添加百分比映射（如需要）
  │
  └─ 属性存在但序列化未导出
      └─ framework/Core/Application.php
        - serializeRenderNode 的 $styleKeys 白名单添加

渲染视觉效果不一致
  ├─ GDI 渲染问题
  │   └─ framework/Rendering/GdiRenderContext.php
  │     - drawText line-height 支持
  │     - 颜色格式转换
  │     - 边框绘制
  │
  ├─ Skia 渲染问题
  │   └─ framework/Rendering/SkiaRenderContext.php
  │
  ├─ 文本溢出处理
  │   └─ framework/Rendering/TextOverflowProcessor.php
  │
  └─ clip/overflow 处理
      └─ framework/Rendering/VNodeRenderer.php
```

#### 4.2 新增 CSS 属性 Checklist

以新增 `box-shadow` 为例：

- [ ] `CssMappings.php` — 添加 `'box-shadow' => 'boxShadow'` 映射
- [ ] `CssMappings.php` — 如属性有默认值，设 `'default' => 'none'`
- [ ] `Application.php serializeRenderNode` — `$styleKeys` 中添加 `'boxShadow'`
- [ ] `tools/shared_test_lib.php` — `defaultChecks()` 中添加对应项
- [ ] `test_case/case-NNN-name/` — 添加包含该属性的测试 case
- [ ] 运行验证：`php apps/css-test/run.php --case=case-NNN`
- [ ] 全量回归：`php apps/css-test/run.php`

#### 4.3 修复准则（三原则）

1. **治本不治标** — 在框架层修复，不在应用层加 workaround
2. **通用合规** — 修复应符合 CSS 标准，不针对特定测试用例特化。参考 [CSS 规范](https://www.w3.org/Style/CSS/) 后实现，不要猜测
3. **先覆盖后优化** — 先通过测试，再考虑性能。一次修复一个差异

#### 4.4 应用层修复

当确认框架已符合 CSS 标准（通过 JSON 对比 + 查阅规范验证），但渲染效果仍有差异时：
- 修改 `.vue` 中的模板/样式
- 同步修改对应的 `.html`
- 重启迭代验证

#### 4.5 Bug 追踪与状态维护

**Bug 台账格式**（`test_log/bug_tracker.md`）：

```markdown
# <项目> Bug 追踪台账

| # | 发现日期 | 问题描述 | 分类 | 状态 | 根因文件 | 修复提交 | 测试增强 |
|---|---------|---------|------|------|---------|---------|---------|
| 1 | 2026-06-09 | auto-height + absolute 子节点正反馈 | 框架 Bug | ✅ 已修复 | BlockLayoutStrategy.php | abc1234 | css-test case-004 多帧验证 |
```

**分类与状态定义**：

| 字段 | 可选值 |
|------|--------|
| **分类** | `框架 Bug` / `工具 Bug` / `测试 Bug` / `应用层问题` / `已知限制` / `待确认` |
| **状态** | `🟡 待处理` / `🟢 排查中` / `✅ 已修复` / `❌ 无法修复` / `📋 待定` |

**维护规范**：
1. **发现即记录** — 无论通过何种方式发现，立即新增台账条目
2. **修复后更新** — 更新状态、填写修复提交、记录新增的 test_case
3. **反思联动** — 每次反思（§九）后，检查是否需要新增台账条目
4. **定期审查** — 每个迭代周期结束时审查 "待处理" 和 "已知限制"

---

## 四、浏览器参考数据生成规范

生成准确、无污染的浏览器参考数据是避免"假阳性"的基础。

### 4.1 buildCssTestWrapper() CSS 基线规则

`run.php` 中的 `buildCssTestWrapper()` 负责组装测试 wrapper HTML：

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/normalize.css">
<style>
@font-face {
  font-family: 'Noto Sans SC';
  src: local('Noto Sans SC'), url('file:///.../NotoSansSC-Regular.ttf');
  font-weight: 400;
}
@font-face {
  font-family: 'Noto Sans SC';
  src: local('Noto Sans SC Bold'), url('file:///.../NotoSansSC-Bold.ttf');
  font-weight: 700;
}
* { margin: 0; padding: 0; box-sizing: border-box; }     <!-- 匹配引擎 -->
html, body { width: 1600px; height: 800px; overflow: hidden; background: #0d1117; }
</style>
</head>
<body style="font-family:'Noto Sans SC',sans-serif;font-size:16px;">
<div class="px-app-root" style="width:1600px;height:800px;overflow:auto;background:#f5f5f5;">
  <!-- 测试内容 -->
</div>
</body>
</html>
```

**四条铁律**（新增第 4 条）：
1. **html/body 固定宽高** — 1600×800，与引擎 `WINDOW_WIDTH/HEIGHT` 一致
2. **`* { box-sizing: border-box }`** — 引擎使用 border-box 盒模型（与标准 CSS content-box 不一致但已统一）
3. **字体声明** — 必须包含 Noto Sans SC 的 `@font-face` 加载块，否则浏览器 fallback 字体不同导致文本尺寸偏差
4. **粗体字体分离声明** — `@font-face` 必须拆分为 Regular(400) 和 Bold(700) 两个声明，分别加载 `NotoSansSC-Regular.ttf` 和 `NotoSansSC-Bold.ttf`，使浏览器基线也使用真实粗体字宽（而非合成粗体）

### 4.2 --window-size 匹配规则

| 上下文 | main.php | buildCssTestWrapper() |
|--------|---------|----------------------|
| css-test | `WINDOW_WIDTH=1600, WINDOW_HEIGHT=800` | `--window-size=1600,800` |
| 其他项目 | `WINDOW_WIDTH=1800, WINDOW_HEIGHT=1200` | `runEdgeHeadless()` 匹配 |

### 4.3 生成后验证清单

- [ ] 参考 JSON 中元素的坐标在合理范围
- [ ] normalize.css 正常加载（内置 CDN 可达性）
- [ ] 所有元素的 w/h/x/y 不为负数
- [ ] `@font-face` 正确引用 Noto Sans SC 字体
- [ ] 首元素位置在视口居中区域内

---

## 五、迭代退出条件

### 单次迭代退出（满足其一）

1. **新增用例通过率 ≥95%** — 所有匹配元素的样式+位置正确
2. **截图差异 ≤5%** — 像素级对比在阈值内
3. **剩余差异为已知框架限制** — 如 `<table>` 等框架尚未实现的特性
4. **所有属性均已覆盖** — 核心 CSS 属性白名单已全覆盖

### 全项目最终验收标准

- 所有 case 通过率 ≥90%
- 半数以上 case 通过率 ≥95%
- 截图对比 ≤5% 差异
- 无构建失败
- 无回归（各 case 通过率不低于上次报告的 5%）

---

## 六、截图对齐机制

### 6.1 颜色锚点对齐（推荐，优先级最高）

每个测试 case 的**最外层卡片容器**（`background:#fff` 的卡片 div）上设置 `position:relative`，
在其 padding-box 四角嵌入两个 8×8 纯色块：

```html
<!-- __PX_ANCHOR_TL__ 左上角（卡片 padding-box 的左上角） -->
<div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
<!-- __PX_ANCHOR_BR__ 右下角（卡片 padding-box 的右下角） -->
<div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div>
```

- `position:relative` 设置方式：
  - `.vue` 文件：在 card 的 inline style 末尾添加 `;position:relative`
  - `.html` 文件：在对应 CSS class（如 `.bx-card`、`.wrapper-test`）定义末尾添加 `;position:relative}`
- 锚点与卡片关系：
  - TL 锚点 `top:0;left:0` → 卡片 padding-box 左上角
  - BR 锚点 `bottom:0;right:0` → 卡片 padding-box 右下角
- 锚点及卡片必须全程在可视化视口（1600×800）内
- 引擎渲染：绝对定位保证锚点始终在卡片 padding box 四角
- 浏览器截图：CSS 标准定位保证同样位置
- `buildCssTestWrapper()` / `buildScreenshotWrapper()` 不再注入锚点包装层
- 对齐算法：`detectColorAnchors()` 用 O(n) 扫描找到两个色块 → 计算偏移 dx/dy
- 优势：无需模板预提取、像素级精确、不受内容变化影响

### 6.2 窗口尺寸一致性

| 场景 | 窗口尺寸设置 | 注意事项 |
|------|-------------|---------|
| Px 应用 | `main.php` 中 `WINDOW_WIDTH`, `WINDOW_HEIGHT` | 默认 1600×800；exe 不含 DPI 感知清单，高 DPI 系统上 `GetClientRect` 返回虚拟坐标 |
| 浏览器 ref 生成 | `buildCssTestWrapper()` 的 `--window-size=1600,800`（Edge headless） | Edge headless 不受窗口管理器约束，精确控制视口 |
| 浏览器基准截图 | Edge headless `--window-size=1600,800 --screenshot=out.png` | 替代旧的 PowerShell MoveWindow 方式（不可靠） |

### 6.3 模板匹配对齐（回退方案）

当无法嵌入颜色锚点时，使用 SAD 模板匹配：
1. 从基准截图中提取视觉独特的区域
2. `findAnchorInImage()` 多级分辨率搜索（4x 降采样粗搜 → 全分辨率精搜）
3. 计算偏移量对齐后像素级对比

### 6.4 全自动内容检测对齐（最终回退）

- `autoDetectContentBounds()` 基于亮度变化 + 颜色范围自动识别内容边界
- 裁剪掉均匀的边缘区域
- 适用于无明显锚点或特征区域的截图

### 6.5 对齐流程优先级

```
compareScreenshot() 执行：
  1. 颜色锚点检测（detectColorAnchors）→ 最快最准
  2. 模板锚点匹配（findAnchorInImage）→ 需预先提取模板
  3. 自动内容检测（autoDetectContentBounds）→ 通用回退
  4. 无对齐（dx=0, dy=0）→ 仅用于完全一致的截图
```

---

## 七、项目治理与回归防护

### 7.1 css-test 统一框架的优势

所有 CSS 标准对齐工作统一在 `apps/css-test/` 下进行，避免多项目分散：

| 方面 | 旧方案（多项目） | 新方案（css-test 统一） |
|------|----------------|----------------------|
| 测试入口 | 每个项目有 auto_test.php | 单一 `run.php` |
| 测试用例 | 各项目 test_cases/ 独立 | 统一 `test_case/` 目录 |
| 对比库 | 各项目复制 shared_test_lib.php | 单一共享库 |
| 报告 | 各项目独立 test_log/ | 统一 `apps/css-test/test_log/` |
| 执行 | 逐个项目手动运行 | `php apps/css-test/run.php` 一次跑完 |

### 7.2 回归防护

每次框架修改后，运行全量测试：

```bash
php apps/css-test/run.php
```

**回归验证标准**：
- 已有 case 的通过率不应下降超过 5%
- 截图差异不应从 <5% 上升到 >10%
- 已有 case 不应新增 FAIL
- 不应新增 STABILITY 标记

### 7.3 执行策略

- **日常开发**：`php apps/css-test/run.php --case=case-NNN` 聚焦单一特性
- **提交前**：`php apps/css-test/run.php` 全量回归
- **框架修改后**：全量回归验证无退化

---

## 八、典型修复案例分析

### 案例 1：Flex 容器 parent=null 时百分比尺寸解析失败

**症状**：外层 `display:flex; width:100%; height:100%` 的容器被解析为 100×100

**根因**：`FlexLayoutStrategy` 在 `$ctx->parent === null` 时返回 `parentW=0, parentH=0`，导致 `width:100%` 因 `parentSize > 0` 条件不满足而回退到 `parsePixels('100%') = 100`

**修复**：当 parent=null 时使用 `WINDOW_WIDTH/HEIGHT` 作为父容器尺寸（`BlockLayoutStrategy` 已有此 fallback）

**框架文件**：`framework/Rendering/Layout/FlexLayoutStrategy.php`

---

### 案例 2：绝对定位 bottom:0 right:0 位置偏

**症状**：`position:absolute;bottom:0;right:0` 的元素不在容器 padding box 右下角

**根因**：`AbsolutePositioning.php` 中 padding box 边界计算公式未正确包含 `paddingRight`/`paddingBottom`

**修复**：
- rightEdge：padding box 右边界 = `ancestorX + ancestorW + ancestorPaddingRight - right`
- bottomEdge：padding box 下边界 = `ancestorY + ancestorH + ancestorPaddingBottom - bottom`

**框架文件**：`framework/Rendering/Layout/AbsolutePositioning.php`

---

### 案例 3a：auto-height 容器 + absolute 子节点（第一遍跳过）

**症状**：`position:relative; height:auto` 的容器包含 `position:absolute` 子节点，absolute 子节点位置不对

**根因**：BlockLayoutStrategy 第一遍解析时跳过 absolute 子节点，但 auto-height 计算后未重新解析

**修复**：在 auto-height 算出后增加第二遍解析，传入容器最终坐标

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php`

---

### 案例 3b：auto-height 容器 + absolute 子节点（Frame 2+ 正反馈循环）

**⚠️ Frame 依赖型 bug**：Frame 1 正确、Frame N 异常。`--dump-layout` 单帧模式永远检测不到。

**症状**：容器高度逐帧膨胀（每帧增长 paddingBottom）。布局 dump 正确但实际截图尺寸巨大。

**根因**：`BlockLayoutStrategy::resolveBlockLayout()` 的 auto-height 计算在遍历所有子节点取 `maxBottom` 时，没有排除 `position:absolute` 和 `position:fixed` 的子节点，违反 CSS 2.2 §10.6.3。

**正反馈链**：
1. Frame 1: absolute 子节点尚未定位（y=0），auto-height 只取 normal flow → 正确 ✓
2. Frame 2: BR 锚点已被 Frame 1 第二遍定位到容器底部 → auto-height 错误包含它 → computedH 增长
3. Frame N: h 每帧增长，直到逼近窗口高度

**修复**：auto-height foreach 循环开头添加 position 检查：
```php
$childPosition = $child->style['position'] ?? 'static';
if ($childPosition === 'absolute' || $childPosition === 'fixed') {
    continue;
}
```

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php`

**经验教训**：
1. Frame 依赖型 bug 是 `--dump-layout` 和单次 resolve 测试的死角
2. CSS 布局必须严格遵循规范：auto-height 只计算 normal flow 子节点
3. css-test 的多帧稳定性验证强制所有 case 执行 `--dump-layout-after-frames=N`

---

### 案例 4：Block auto-height 包含 paddingTop

**症状**：带 padding 的 auto-height 容器引擎 h=456，浏览器 h=520（偏差 64px = 2×paddingTop）

**根因**：CSS 2.2 §10.6.3 规定 auto-height = 从内容区上边缘到最后一个 in-flow 子元素下边缘。BlockLayoutStrategy 用 `maxBottom - node->y` 计算，但 `node->y` 是 border-box 上边缘（包含了 paddingTop）。

**修复**：`computedH = maxBottom - (node->y + paddingTop)`

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php`

---

### 案例 5：浏览器 ref wrapper CSS 导致假阳性（box-sizing / line-height）

**症状**：测试多项失败是假阳性——文字宽度偏差 56px、文本高度偏差 8px。引擎行为正常。

**根因**：`buildCssTestWrapper()` 或 `generate_project_ref.php` 中设置了非标准 CSS：
- `* { box-sizing: border-box }` vs 引擎默认 content-box（已统一使用 border-box）
- `body { line-height: 1.7 }` vs 引擎 `line-height: normal`

**修复**：
1. 统一 `* { box-sizing: border-box }` 匹配引擎
2. 移除 body 行高设置，改用 normalize.css 基线
3. 引入 `<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/normalize.css">`

**框架文件**：`run.php` → `buildCssTestWrapper()` / `tools/generate_project_ref.php`

---

### 案例 6：浏览器视口与引擎窗口不一致导致坐标系统性偏移

**症状**：所有元素 x 坐标引擎比浏览器大 260px，方向一致

**根因**：引擎 WINDOW_WIDTH 与 `--window-size` 不匹配。flex 容器在不同视口中居中位置不同。

**修复**：将 `--window-size` 匹配引擎窗口尺寸。css-test 统一为 1600×800。

---

### 案例 7：Skia 细矩形抗锯齿导致分隔线和卡片边缘渲染膨胀

**症状**：1px 高的分隔线（`border-top:1px solid #eee`）在 EXE 中显示为 ~3px 高；卡片边缘轻微模糊，整体视觉偏"厚"

**根因**：`skia_render.cc` 中 `php_sk_alpha_fill_rect` 在绘制矩形时无条件设置 `paint.setAntiAlias(true)`。对于宽或高只有 1~2px 的细矩形，抗锯齿会使本应锐利的线条在两侧各扩展约 1px，造成视觉膨胀。

`php_sk_fill_rect` 的对应代码（line 403）已有保护逻辑：
```cpp
paint.setAntiAlias((int)w > 2 && (int)h > 2);
```
但 `php_sk_alpha_fill_rect` 缺失此保护，导致透明矩形路径（如分隔线）渲染膨胀。

**修复**：在 `php_sk_alpha_fill_rect` 中添加相同的细矩形防抗锯齿保护：
```cpp
paint.setAntiAlias((int)w > 2 && (int)h > 2);
```

**框架文件**：`cpp/skia_render.cc`

**管线盲区**：

| 防线层 | 问题 | 根因 |
|--------|------|------|
| 元素对比（layout JSON） | 通过 ✅ | 布局引擎报告 h=1 正确，但渲染时抗锯齿膨胀；元素对比只比较 JSON 数据，不验渲染效果 |
| 截图对比 | 未拦截 | 基线截图从同一 buggy exe 生成，膨胀效果抵销 |
| 单元测试 | 未涉及 | 无渲染原语级别的抗锯齿行为测试 |

**反思**：布局层数据正确 ≠ 渲染层效果正确。仅依赖 JSON 对比无法捕获渲染器级别的抗锯齿/颜色/字体渲染差异。

---

### 案例 8：Skia/FreeType 字体测宽与浏览器 DirectWrite 不一致

**症状**："Test Case" 粗体 14px 引擎测量 72px，浏览器参考 64px（dw=8）。引擎整体布局因文本测宽偏宽而系统性偏移，导致等比例截图中 EXE 偏"宽"。

**根因**：`skia_render.cc` 中 `php_sk_measure_text_width` 在 `USE_SKIA` 路径下使用 Skia/FreeType 引擎进行文本宽度测量。FreeType 与浏览器 Edge 使用的 DirectWrite 字体引擎渲染策略（hinting、glyph advance 计算）不同，同一字体的测宽结果存在固有差异。

**修复**：`php_sk_measure_text_width` 在 `USE_SKIA` 路径下改用 GDI `GetTextExtentPoint32W` 进行文本宽度测量。Skia 仍负责实际绘制（提供抗锯齿和圆角），宽度测量改用 GDI 以保证与浏览器的 DirectWrite 测量一致。

```cpp
// 不再使用 Skia measureText，改用 GDI GetTextExtentPoint32W
SelectObject(hdc, hFont);
GetTextExtentPoint32W(hdc, text, len, &sz);
width = sz.cx;
```

**额外修复——粗体真实字形加载**：
- 引擎端：`skia_render.cc` 通过 `AddFontMemResourceEx` 预加载 `NotoSansSC-Bold.ttf`，在粗体绘制/测宽时切换到真实粗体字体文件
- 浏览器端：`buildCssTestWrapper()` 将 `@font-face` 拆分为 Regular(400) 和 Bold(700) 两个声明，使浏览器基线也加载真实粗体字体

**框架文件**：`cpp/skia_render.cc`、`apps/css-test/run.php`（@font-face 分离）

**管线盲区**：

| 防线层 | 问题 | 根因 |
|--------|------|------|
| 元素对比 | 未拦截 | 对比用的浏览器参考数据也受字体影响，差异未超过容差 |
| 截图对比（cropAnchors 模式） | 未拦截 | 等比例缩放抵销了绝对宽度差异，但比例差异被归入"渲染差异" |
| 布局 JSON 对比 | 未涉及 | 布局层只关心测宽结果，不判断测量值的绝对正确性 |

**教训**：跨平台/跨引擎的字体渲染差异是持续问题。GDI 测宽 + Skia 绘制是当前最实用的折中方案。

---

### 案例 9：布局层 font-weight key 与 CssMappings 映射不一致

**症状**：粗体文本（font-weight:700）在引擎中始终以常规宽度测量和渲染，即使 CSS 正确解析为 bold=1。

**根因**：`CssMappings.php` 将 CSS 属性 `font-weight` 映射到内部 key `'bold'`（值 0/1），但所有布局策略类（`BlockLayoutStrategy`、`FlexLayoutStrategy`、`GridLayoutStrategy`、`AbsolutePositioning`、`InlineLayoutStrategy`）在访问粗体值时使用 `$style['fontWeight']`，导致始终读取到 `null`（fallback 为 'normal'）。

CssMappings 映射链：
```
font-weight CSS → parseFontWeight → $style['bold'] = 0|1
                                                 ↑
                                         布局策略错误地用
                                         $style['fontWeight']
```

**修复**：将 5 个布局策略文件中的 `$style['fontWeight']` 全部替换为 `($style['bold'] ?? 0)`：
```php
// 修复前
$bold = $style['fontWeight'] ?? 'normal';
// 修复后
$bold = ($style['bold'] ?? 0) !== 0;
```

**框架文件**：
- `framework/Rendering/Layout/BlockLayoutStrategy.php`
- `framework/Rendering/Layout/FlexLayoutStrategy.php`
- `framework/Rendering/Layout/GridLayoutStrategy.php`
- `framework/Rendering/Layout/AbsolutePositioning.php`
- `framework/Rendering/Layout/InlineLayoutStrategy.php`

**教训**：CssMappings 的 key 映射与布局层的消费方之间存在隐式契约。新增 CSS 属性映射后，必须同步检查所有消费方使用的 key 是否正确。

---

### 案例 10：Block 布局 margin:auto 未包含 padding 和 border

**症状**：`margin:0 auto` 居中时，引擎计算的位置比浏览器左偏。以 case-001 为例，引擎 `x=400`，浏览器 `x=375`（偏移 25px = 1px border-left + 24px padding-left）。

**根因**：`BlockLayoutStrategy` 的 `resolveMarginAuto()` 函数用 `$parentContentW - $node->w` 计算水平剩余空间，只减了子元素的 content width，漏了 border 和 padding。CSS 规范中 margin:auto 居中的剩余空间应该用**父容器 content box 宽度 - 子元素 border-box 宽度**。

```php
// 修复前
$remaining = $parentContentW - $node->w;
// 修复后（考虑 padding 和 border）
$remaining = $parentContentW - ($node->w + $paddingLeft + $paddingRight + $borderLeft + $borderRight);
```

**修复**：在 `resolveMarginAuto` 中从子元素的 `visualW` 获取完整盒宽度（含 padding + border），替代纯 content width。

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php`

**管线盲区**：

| 防线层 | 问题 | 根因 |
|--------|------|------|
| 元素对比 | 未拦截 | margin:auto 在浏览器和引擎间偏差 25px，但 layout JSON 对比未检查居中计算路径 |
| 截图对比 | 未拦截 | 基线从旧 exe 生成，偏差被 normalize |
| 单元测试 | 未涉及 | 无 margin:auto 居中路径的独立断言 |

**教训**：margin:auto 布局正确性依赖浏览器参考数据作为独立锚点，不可依赖 exe 自生成的基线。

---

### 案例 11：无背景元素默认渲染黑色——渲染器 vs 布局 JSON 分离盲区

**症状**：Footer div（`border-top:1px solid #eee`，无 `background`）在 EXE 中渲染为黑色大黑条，浏览器中为透明背景+细线。

**根因**：`VNodeRenderer::makeDivElement()` 中 `$drawColor = ($bg !== null) ? $bg : 0`。CSS 标准 `background-color` 初始值为 `transparent`，但引擎在无显式背景时默认用 `0`（黑色 GDI 颜色）填充整个元素区域。

**修复**：
1. 引入 `$noFill` 标志：当 `$bg === null` 时所有背景填充分支（圆角/半透明/实心）跳过，边框不受影响
2. 同时修复了 `$btc/bbc/blc/brc` 变量作用域问题——直角边框路径中 border color 变量未初始化

**框架文件**：
- `framework/Rendering/VNodeRenderer.php`
- `framework/Rendering/GdiRenderContext.php`
- `framework/Rendering/SkiaRenderContext.php`

**增强**：dump-layout 现在**总是导出 `bg`**（未显式设置时用 `-1` 透明标记），元素对比和容器对比都参与颜色检测

**管线盲区分析**：

| 防线层 | 问题 | 根因 |
|--------|------|------|
| 元素对比（Phase A） | 未拦截 | `bg` 未显式设置 → 引擎不导出 → 对比跳过 |
| 元素对比（Phase B 容器） | 未拦截 | `noTextStyle=true` 跳过全部样式对比 |
| 截图对比 | 未拦截 | 基线可能从同一 buggy exe 生成 |

**教训**：布局层数据正确 ≠ 渲染层效果正确。JSON 属性缺失时对比直接跳过，造成无声漏检。必须确保所有关键样式属性**始终导出**，即使取默认值也应显式标记，让对比能够参与检测。

## 九、问题反思机制

### 9.1 反思触发条件

| 触发条件 | 说明 |
|---------|------|
| **管线未捕获的 bug** | 测试管线全部通过后，仍被人工/截图发现 |
| **回归遗漏** | 框架修改导致已通过的测试退化但未被检测到 |
| **反复出现的同类问题** | 同一模块、同一模式在不同场景下多次出 bug |
| **测试盲区暴露** | 现有测试体系完全无法覆盖的 bug 类型 |
| **人工审查发现的模式缺陷** | Code review 发现的设计层面的问题 |

### 9.2 反思六步法

#### Step 1：症状记录

```
记录项               内容示例
──────────────────────────────────────────────
发现时间            2026-06-09
发现方式            布局 dump vs 截图对比（TL/BR 锚点跨度异常）
预期结果            536×484（与布局 dump 一致）
实际结果            536×898（截图膨胀 414px）
影响范围            case-004 容器高度
```

#### Step 2：根因定位

- **最小化**：从完整 case 逐步剥离到最简可复现
- **归因**：确定是框架 bug、应用 bug、还是工具 bug
- **定位**：具体到文件 × 行号 × 逻辑分支

#### Step 3：管线盲区分析

| 防线层 | 问题 | 根因 |
|--------|------|------|
| `--dump-layout` 对比 | 通过 ✓ | 单帧模式，Frame 2+ 的 bug 永不触发 |
| 截图对比 | 未拦截 | baseline 从同一 buggy exe 生成 → 差异抵销 |
| 单元测试 | 通过 ✓ | 所有测试只单次 resolve()，无跨帧断言 |
| Code review | 未发现 | auto-height 逻辑修改时未考虑 Frame 2+ 行为 |

**关键产出**：明确列出每层的**失效原因**和**改进措施**。

#### Step 4：修复验证

- 确认修复代码符合 CSS 规范引用
- 在最小测试 case 上验证 Frame 1→2→3 稳定性
- 在全量测试上验证无回归

#### Step 5：测试增强

| 防线 | 增强措施 | 对应产出 |
|------|---------|---------|
| css-test | 新增多帧稳定性验证（`--dump-layout-after-frames=5`） | run.php 内置 |
| 回归验证 | 将新 case 加入回归套件 | test_case/ 目录 |
| 文档 | 更新案例库 | 本文档 |

#### Step 6：知识沉淀

1. **本文档 §八 案例库** — 记录 Bug 症状、根因、修复、教训
2. **本文档 §九 反思清单** — 新增/更新反思清单条目
3. **AGENTS.md** — 同步到 AI 驱动的开发规范
4. **test_case/** — 新增测试 case 作为活的文档

### 9.3 反思输出 Checklist

- [ ] Bug 症状与根因已记入 §八 案例库
- [ ] 管线盲区分析（防线层 → 问题 → 根因 三列表）已完成
- [ ] 测试增强已实现且通过
- [ ] 对应 test_case/ 目录已有覆盖此 Bug 的用例
- [ ] AGENTS.md 中相关规范已同步更新
- [ ] 反思中暴露的通用盲区已抽象为检查清单项
- [ ] 反思清单（§9.4）已更新
- [ ] 对应项目的 Bug 台账已新增或更新条目

### 9.4 反思清单（持续维护）

| # | 盲区类别 | 首次暴露于 | 预检要求 |
|---|---------|-----------|---------|
| 1 | **Frame 依赖型 Bug**：单帧正确 ≠ 多帧正确 | 案例 3b | 涉及 auto-height / absolute / padding 的布局修改必须通过 `--dump-layout-after-frames=N` 验证 |
| 2 | **自引用基线**：截图/布局对比的 baseline 从同一 buggy exe 生成 → 差异抵销 | 案例 3b | baseline 优先使用浏览器 ref（非 exe），迫不得已时在报告中注明基线来源 |
| 3 | **单次 resolve 假设**：单元测试只 resolve 一次 → 无法暴露 Frame 2+ 的稳定性问题 | 案例 3b | run.php 默认 5 帧多帧验证，禁止减少帧数 |
| 4 | **隐性基线偏差**：ref 生成工具的 wrapper CSS 引入非标准基线 | 案例 5 | 出现系统性差异时，先排查 buildCssTestWrapper()，再排查引擎 |
| 5 | **视口不一致**：引擎和 ref 生成的 window-size 不匹配 → 系统性坐标偏移 | 案例 6 | 项目初始化时确认 `--window-size` 与 `WINDOW_WIDTH/HEIGHT` 一致 |
| 6 | **字体缺失引起文本尺寸偏差**：浏览器 fallback 字体与引擎 Noto Sans SC 不同 | css-test 日常 | `@font-face` 声明必须包含在 buildCssTestWrapper() 中 |
| 7 | **渲染层 vs 布局层分离**：layout JSON 数据正确 ≠ 渲染效果正确 | 案例 7 | 涉及 C++ Skia/GDI 绘制原语修改后，必须通过截图对比验证渲染效果 |
| 8 | **字体引擎差异**：FreeType(Skia) 与 DirectWrite(浏览器) 测宽存在固有差异 | 案例 8 | `measure_text_width` 修改后需交叉验证 GDI/Skia/DirectWrite 三路测量结果一致性 |
| 9 | **CSS 映射 key 不一致**：CssMappings 新增属性后消费方 key 未同步更新 | 案例 9 | 新增 CSS 属性映射后必须 grep 所有 `$style['...']` 消费方确认 key 一致 |
| 10 | **过期参考数据**：ref/engine_layout.json 内容与测试用例不匹配未被检测 | 多个 case | 布局导出后立即执行 `validateEngineLayoutContent()` 校验内容一致性 |
| 11 | **margin:auto 无独立断言**：居中计算正确性依赖外部基线，无自洽验证 | 案例 10 | margin:auto 修改后必须用浏览器 ref（非 exe）作为独立锚点验证居中结果 |
| **12** | **属性缺失时对比跳过**：引擎未导出 `bg` → 对比 `!isset(eStyles['bg'])` → 无声跳过 → 黑色大黑条不被检测 | **案例 11** | 所有关键样式属性必须**始终导出**，未显式设置时用 sentinel 值（如 `-1`）标记默认态，确保对比参与检测 |

---

## 十、附录

### 命令速查

```bash
# css-test 全量测试
php apps/css-test/run.php

# 单用例开发
php apps/css-test/run.php --case=case-007
php apps/css-test/run.php --case=case-007 --verbose
php apps/css-test/run.php --case=case-007 --frames=10

# 跳过某些步骤（调试时加速）
php apps/css-test/run.php --skip-build               # 跳过编译
php apps/css-test/run.php --skip-browser-ref          # 跳过浏览器对比
php apps/css-test/run.php --update-baseline           # 更新参考数据

# 截图（手动 — Edge headless）
msedge --headless --disable-gpu --window-size=1600,800 `
  --screenshot="test_log/browser_ref_20260613_143025.png" `
  "file:///D:/Px/apps/css-test/test_case/case-NNN/wrapper.html"

# 构建（单独）
cd D:\Px
.\build.bat css-test

# SFC 编译（动态组件模式）
php sfc-compiler.php apps/css-test/App.vue

# 手动导出布局（带 --case 参数）
cd apps/css-test/bin
.\css-test.exe --case=case-001 --dump-layout
.\css-test.exe --case=case-029-scroll-flex-col --dump-layout-after-frames=5

# 单项目浏览器参考（非 css-test）
php tools\generate_project_ref.php <project>

# css-test 浏览器参考（旧版工具，run.php 已内联）
php tools\generate_browser_refs.php

# 截图（手动 — PowerShell，已不建议使用）
powershell -ExecutionPolicy Bypass -File tools/capture_screenshot.ps1 `
    -AppName css-test -ProjectRoot D:/Px `
    -OutputPath apps/css-test/test_log/captured.png

# 全量测试（所有项目，含回归）
php tests/run_all_tests.php
```

### 参考文献

- [CSS Positioned Layout Module Level 3](https://www.w3.org/TR/css-position-3/) — 绝对/固定定位
- [CSS Flexible Box Layout Module Level 1](https://www.w3.org/TR/css-flexbox-1/) — Flex 布局
- [CSS Grid Layout Module Level 1](https://www.w3.org/TR/css-grid-1/) — Grid 布局
- [CSS Box Model Module Level 3](https://www.w3.org/TR/css-box-3/) — 盒模型/padding/margin
- [CSS Values and Units Module Level 3](https://www.w3.org/TR/css-values-3/) — 百分比/calc/单位
- [CSS Overflow Module Level 3](https://www.w3.org/TR/css-overflow-3/) — 溢出/滚动
- [CSS Cascading and Inheritance Level 4](https://www.w3.org/TR/css-cascade-4/) — 层叠/继承

### 诊断技巧

```php
// 在框架代码中加日志（记得修完后删除或用开关控制）
error_log('[DIAG] enter resolveFlexLayout type=' . $node->type . ' w=' . ($style['width'] ?? 0));

// 在 compareElementEnhanced 中针对特定元素加调试
if ($label === '目标元素') {
    file_put_contents('debug_element.log', print_r([
        'browser' => $bEl, 'engine' => $eEl
    ], true));
}

// 直接检查 engine_layout.json 确认引擎坐标和样式值
// 位于 test_case/case-NNN/ref/engine_layout.json
```

### 多帧布局稳定性验证

**背景**：auto-height + absolute 子节点的正反馈循环 bug 证明了 Frame 依赖型 bug 是 `--dump-layout` 的死角。

**css-test 中的多帧验证**（内置在 run.php 中）：
- `--dump-layout-after-frames=5` 默认执行
- 自动比较 Frame 1 与 Frame 5 的布局 JSON，逐节点对比 x/y/w/h
- 任何跨帧变化标记为 **STABILITY** 问题计入失败

**具体场景**（必须关注多帧稳定性）：
- 任何含 auto-height 的 block 容器 + absolute/fixed 子节点
- 任何含 padding 的 auto-height 容器
- 任何调整了子节点 y 坐标的布局策略（flex/grid 重定位后）

### test_case 创建模板

```php
// test_case/case-NNN-name/CaseNnnName.vue
<template>
  <div class="card" style="width:720px;margin:20px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    <div class="header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      📐 测试标题
    </div>
    <!-- 测试内容 -->
    <div class="footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-NNN: 测试描述
    </div>
  <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
```

```html
<!-- test_case/case-NNN-name/CaseNnnName.html -->
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>测试标题</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:#f0f2f5; font-family:sans-serif; display:flex; justify-content:center; padding:20px; }
  /* 与 .vue 同步的样式 */
</style></head><body>
  <!-- 与 .vue <template> 一致的内容 -->
</body></html>
```

> **⚠️ 样本偏差警示 — .vue 与 .html 结构必须严格一致**
>
> 浏览器参考数据（`browser_ref_level_0.json`）从 `.html` 生成，引擎布局快照从 `.vue` 编译的 exe 生成。
> 若两者 DOM 结构不一致，对比将产生系统性偏差，表现为**全用例一致的 dx/dw**（如 dw=90）。
>
> **历史案例**：case-001/case-002 的 `.html` 含 `<div class="sandbox" style="padding:20px">` 包装层，
> 但 `.vue` 直接以根元素开始，导致引擎缺少 20px padding 包装 → 引擎元素宽度比浏览器宽 90px。
>
> **检查清单**（每次新建 test_case 必须核对）：
> 1. `.vue` 的 `<template>` 根元素与 `.html` 的 `<body>` 内第一个元素结构一致
> 2. 所有 CSS 类名和 inline style 在两者间一致
> 3. 嵌套层级（额外 wrapper 层）完全对齐
> 4. `buildCssTestWrapper()` 注入的全局 CSS（`* { margin:0; padding:0; }` 等）在引擎端有无匹配项
> 5. 使用 `php run.php --case=case-NNN --update-baseline` 后检查 dw 是否接近 0
>
> **修复流程**：优先修改 `.vue` 对齐 `.html`（`<template>` 是源），然后重新编译并 `--update-baseline`。
> 切勿仅修改 `.html` 而不更新 `.vue`，否则引擎与浏览器参考的偏差将持续存在。
