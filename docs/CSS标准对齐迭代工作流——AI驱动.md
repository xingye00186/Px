# CSS 标准对齐迭代工作�?�?AI 驱动

> **目标**：通过 `apps/css-test/` 统一测试框架 + 自动化迭代，逐步�?Px 框架渲染结果与浏览器（Edge Chromium）像素级一致�?
>
> **核心思想**：数据驱动差异分�?�?定位根因 �?区分框架问题与应用问�?�?更新清单 �?治本修复 �?回归验证 �?更新清单 �?分类提交代码和文�?�?形成持续迭代闭环�?

---

## 一、核心原�?

| 原则 | 说明 |
|------|------|
| **先验证后修复** | 跑完整测试链，让数据告诉你差异在哪，不靠猜测 |
| **治本不治�?* | 框架层的 bug 在框架层修复，不�?App.vue 打补�?|
| **通用合规** | 修复应严格符�?CSS 标准，不针对特定测试特化 |
| **分治** | 每个 test_case 只测一�?CSS 特性或一个页面区�?|
| **回归防护** | 每次修复后必须验证原有测试不退�?|
| **排假�?* | 差异出现时，先排除浏览器 wrapper HTML 本身引入的基线差�?|
| **工具共享优先** | 所有工具优先使用已有的共享库（`shared_test_lib.php` 等）；增强修复优先应用到共享工具 |
| **持续追踪** | 每个项目独立维护 Bug 台账，记录所有已知缺陷及其修复状�?|
| **CSS 标准铁律** | 框架�?fallback 必须使用 CSS 标准默认值。项目想要的非标准行为（�?`box-sizing:border-box`）必须在样式声明�?*显式写出�?*。凡框架符合 CSS 标准而测试失败，必须核查修正应用层，不得改动正确的标准框架行�?|
| **不支持即实现** | 测试发现的框架未支持�?CSS 特性，不得通过 SKIP 跳过，不得修改测试样例回避。必须按�?CSS 规范在框架层实现完整支持。GDI 不支持的渲染特性（�?box-shadow、outline、border-radius 高级效果），必须使用 Skia 后端实现，不可绕�?|
| **不可绕过测试样例** | 任何情况下不得修改测试样例（.vue/.html）来绕过框架的功能缺失。测试样例是 CSS 标准的忠实表达，应保持不变作为验证基准。框架能力不足时，增强框架，而非降低测试标准 |

**决策优先�?*：差异出现时，先判断�?
0. **框架符合 CSS 标准吗？** �?查看相关 CSS 属性的标准默认值。如果框架的 fallback 已经使用�?CSS 标准默认值（�?`box-sizing:content-box`），但测试期望非标准值（�?`border-box`）→ **框架正确，应用层缺显式声�?*，改应用�?|
1. **浏览�?ref 生成 wrapper 引入了基线差�?*（box-sizing/line-height/reset/字体不一致） �?修复 `buildCssTestWrapper()`，重新生成参考数�?
2. **框架不符�?CSS 标准**（fallback 用了非标准默认值等�?�?改框�?+ 加测�?
3. **框架符合 CSS 标准，应用层用法�?* �?改应�?
4. **框架尚未实现该特�?* �?**必须实现，不�?SKIP**。按�?CSS 规范实现完整支持后继续迭代。GDI 不支持的特性使�?Skia 后端，不得绕�?|

---

## 二、工具链速查

### 2.1 核心工具

| 工具 | 路径 | 用�?|
|------|------|------|
| **PxTest 编排�?* | `php apps/css-test/test_pipeline.php` | Pipeline+Strategy D→I 全流程编排（构建→布局→多帧→浏览器→元素对比→截图） |
| **入口包装** | `php apps/css-test/test_pipeline.php` | 薄包装，委托�?pipeline.php |
| SFC 编译�?| `php sfc-compiler.php apps/css-test` | .vue �?gen/*.php（build.bat 内含�?|
| 构建脚本 | `.\build.bat css-test` | PHP �?AOT exe（pipeline.php �?BuildStep 内部调用�?|
| 布局导出 | `bin/css-test.exe --headless --dump-layout` | �?engine_layout.json |
| 浏览�?ref 生成 | `PxTest\Pipeline\Strategy\BrowserRefStep` | Edge headless + wrapper!important 注入 |
| 对比引擎 | `PxTest\Comparison\ComparatorRegistry` | 几何 + 样式 + 稳定�?+ 像素 四维对比�?|
| 锚点对齐 | `PxTest\Pipeline\ScreenshotStep::detectColorAnchors()` | 颜色锚点(#FF00FF/#00FFFF)/8×8块检�?自动内容边界 三策�?|
| 无窗口布局导出+截图 | `bin/css-test.exe --case=case-xxx --headless --dump-layout` | 导出 JSON 并自动截�?|
| 无窗口截�?| `bin/css-test.exe --case=case-xxx --headless --screenshot=out.png` | 离屏渲染 PNG |
| 多帧截图 | `bin/css-test.exe --headless --screenshot=out.png --screenshot-frames=5` | 渲染 N 帧后截图 |
| 归档基线 | `php apps/css-test/archive_case.php` | case 通过后冻存基�?|
| 回归检�?| `php apps/css-test/check_regression.php` | 四维度回归对�?|
| PxTest 测试基础设施 | `tools/PxTest/` | Pipeline/Strategy/Mock/Builder/Snapshot/Reporting/Baseline/Comparison |

### 2.2 css-test 测试沙盒目录结构

```
apps/css-test/
├── run.php                  测试沙盒编排器（入口�?
├── main.php                 exe 入口（支�?--dump-layout�?
├── App.vue                  根模板（自动加载 test_case 的组件）
├── components/              备用组件目录（动态组件模式下未使用）
├── test_case/               所有测试用例（每个独立目录�?
�?  ├── case-001-wrapper-x/
�?  �?  ├── Case001WrapperX.vue    引擎端模�?
�?  �?  ├── Case001WrapperX.html   浏览器参�?HTML
�?  �?  ├── ref/
�?  �?  �?  ├── engine_layout.json           引擎布局快照
�?  �?  �?  ├── engine_layout_after_5frames.json  多帧后快�?
�?  �?  �?  └── browser_ref_level_0.json     浏览器参考数�?
�?  �?  └── bin/                  构建产物缓存
�?  ├── case-002-auto-height/
�?  ├── case-003-basic-block/
�?  ├── case-004-flex-layout/
�?  ├── case-005-grid-layout/
�?  ├── case-006-typography/
�?  ├── case-007-border-styles/
�?  ├── ...（标准布局/排版测试，至 case-026�?
�?  ├── case-027-scroll-diagnostic/
�?  ├── case-028-scroll-block/
�?  ├── case-029-scroll-flex-col/
�?  ├── case-030-scroll-flex-row/
�?  ├── case-031-scroll-grid/
�?  ├── case-032-scroll-relative/
�?  ...（持续扩展，当前 32 �?case�?
├── gen/                    SFC 编译器输出（run.php 自动清空/重建�?
├── bin/                    构建输出�?exe + .dll�?
├── test_cases/             旧版 Level 测试（已迁移�?test_case/�?
├── origin_case/            原始批量转换用例
├── project.yml             构建配置
└── engine_layout.json      全局布局快照（run.php 运行时产物）
```

### 2.3 关键框架文件

```
框架布局引擎（差异定位→修复入口�?
  framework/Rendering/LayoutResolver.php             布局引擎调度入口
  framework/Rendering/Layout/BlockLayoutStrategy.php  Block 布局 + auto-height
  framework/Rendering/Layout/FlexLayoutStrategy.php   Flex 布局
  framework/Rendering/Layout/GridLayoutStrategy.php   Grid 布局
  framework/Rendering/Layout/InlineLayoutStrategy.php Inline 布局
  framework/Rendering/Layout/AbsolutePositioning.php  绝对/固定定位（第一遍）
  framework/Rendering/Layout/AbsoluteStrategy.php     绝对/固定定位策略（重构版�?
  framework/Rendering/Layout/Tools/PercentResolver.php 百分�?单位解析
  framework/Rendering/Layout/Tools/ScrollHelper.php    滚动容器辅助
  framework/Rendering/Layout/MultiColumnLayoutStrategy.php 多列布局
  framework/Rendering/Layout/TableLayoutStrategy.php  表格布局
  framework/Rendering/CssMappings.php                 CSS �?内部属性映�?
  framework/Rendering/CssValueParser.php              CSS 值解析（拆分�?CssMappings�?
  framework/Core/Application.php                      serializeRenderNode 白名�?

渲染系统
  framework/Rendering/GdiRenderContext.php            GDI 绘制实现
  framework/Rendering/SkiaRenderContext.php           Skia 绘制实现
  cpp/skia_render.cc                                  C++ 原生渲染层（字体加载/绘制原语/抗锯齿控制）
  framework/Rendering/VNode.php                       虚拟 DOM 节点
  framework/Rendering/RenderNode.php                  渲染专用节点（布局结果�?
  framework/Rendering/VNodeRenderer.php               渲染树遍�?clip
  framework/Rendering/RenderTreeManager.php           VNode→RenderNode 转换
  framework/Rendering/TextOverflowProcessor.php       文本溢出处理
  framework/Rendering/ScrollbarEmitter.php             滚动条管�?
  framework/Rendering/ImageManager.php                图片句柄缓存

渲染后端自动选择（Backend 系统�?
  framework/Rendering/Backend/BackendRegistry.php     后端注册表（6 个候选）
  framework/Rendering/Backend/RuntimeBackendSelector.php 运行时选择器（probe+fallback�?
  framework/Rendering/Backend/ResilientRenderContext.php 故障降级代理
  framework/Rendering/Backend/GdiLegacyBackend.php    GDI 传统后端（永远可用）
  framework/Rendering/Backend/GdiDirect2DBackend.php  GDI Direct2D 后端
  framework/Rendering/Backend/SkiaCpuBackend.php      Skia CPU 后端
  framework/Rendering/Backend/SkiaGaneshD3D11Backend.php Skia D3D11 后端
  framework/Rendering/Backend/SkiaGaneshWGLBackend.php  Skia WGL 后端
  framework/Rendering/Backend/SkiaGraphiteDawnBackend.php Skia Dawn 后端

测试工具
  tools/PxTest/Pipeline/                  Pipeline+Strategy 编排引擎
  tools/PxTest/Comparison/                对比器（Geometry+Style+Stability+Pixel�?
  tools/PxTest/Mock/                      Mock 平台（无需 exe 即可验证�?
  tools/PxTest/Builder/                   Fluent Builder 测试数据工厂
  tools/PxTest/Snapshot/                  快照管理�?
  tools/PxTest/Reporting/                 报告器（Console/Markdown/JSON/TAP�?
  tools/PxTest/Baseline/                  基线归档
  tests/unit/PxTest/                      单元测试�?1 模块覆盖�?
  tests/integration/                      集成测试�? 跨模块协作）
  tests/stress/                           压力测试�?00 节点/200 帧内存泄漏）
  tests/e2e/                              E2E 编排 + headless 脚本
```

---

## 三、完整迭代流程（4 Phases�?

### Phase 0：css-test 测试框架初始�?

css-test 已作为标准测试项目存在。如需准备新测试环境：

```bash
# 1. 创建�?test_case 目录
apps/css-test/test_case/case-NNN-name/
├── CaseNnnName.vue         # Vue 模板（与 HTML 内容一致）
├── CaseNnnName.html        # 浏览器参�?HTML
├── ref/                    # 参考数据（�?run.php 自动生成�?
└── bin/                    # 构建缓存（自动）

# 2. 编译（SFC 编译器自动扫�?test_case/ 目录�?
php sfc-compiler.php apps/css-test/App.vue

# 3. 构建（一次构建，所�?case 共享同一�?exe�?
.\build.bat css-test

# 4. 验证 --dump-layout 可用
cd apps/css-test/bin
.\css-test.exe --case=case-001 --dump-layout
```

**Vue 模板 �?HTML 同步规则**�?
- `.vue` �?`<template>` 内容�?`.html` �?`<body>` 内容必须一致（相同结构 + 相同 inline style�?
- `.vue` 文件必须包含 `<script lang="php">class TestContent extends ReactiveComponent {}</script>`
- .html 使用自包含格式（`<!DOCTYPE html>` + `<style>` + `<body>`�?
- 测试内容外层容器建议 720px 宽，居中布局

---

### Phase 1：添�?更新测试用例

**步骤**�?

| # | 操作 | 命令/说明 |
|---|------|----------|
| 1.1 | �?`test_case/` 创建新目�?`case-NNN-name/` | 命名建议：case-007-border-styles |
| 1.2 | 编写 `CaseNnnName.vue`（引擎端模板�?| 从目�?HTML 提取，保�?inline style 不变 |
| 1.3 | 编写 `CaseNnnName.html`（浏览器参�?HTML�?| �?.vue `<template>` 内容一�?|
| 1.4 | 运行单个 case 验证 | `php apps/css-test/test_pipeline.php --case=case-007-border-styles`（使用完整目录名�?|
| 1.5 | 确认 wrapper CSS 与引擎基线一�?| �?§�?buildCssTestWrapper() 规范 |
| 1.6 | 确认 `--window-size` 匹配引擎 | run.php �?`--window-size=1600,800` |
| 1.7 | 全量运行 | `php apps/css-test/test_pipeline.php` |

**测试用例编写规范**�?
- 每个 case 独立一个目录，只测一�?CSS 特性范�?
- `.vue` �?`.html` 使用 inline style（不依赖外部 CSS），确保确定�?
- 容器宽度建议 720px，居中（margin:0 auto），美观的卡片式设计
- 为关键测试元素加 `id` 属性（便于 `compareElementEnhanced` 精确匹配�?
- 基础样式：`* { margin:0; padding:0; box-sizing:border-box; }` 匹配引擎
- 文件名使�?PascalCase（如 `BorderStyles.vue`），与目录名无关

**`<script>` 块规�?*�?
```php
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
```
- 如果 case 需要动态绑定，在类内定义属性和 `render()` 方法
- 简�?case 只需空类（继�?`render()` 的自动处理）

---

### Phase 2：构�?+ 运行测试

```bash
# 全量测试
php apps/css-test/test_pipeline.php

# 单用例测试（开发阶段常用）
php apps/css-test/test_pipeline.php --case=case-007

# 高级选项
php apps/css-test/test_pipeline.php --case=case-010 --frames=10 --verbose
php apps/css-test/test_pipeline.php --skip-build                  # 跳过编译
php apps/css-test/test_pipeline.php --skip-browser-ref             # 跳过浏览器对�?
php apps/css-test/test_pipeline.php --update-baseline              # 更新参考数�?
```

**pipeline.php 标准执行流程（Pipeline+Strategy 六步编排�?*�?

```
Step 0: Build（BuildStep�?
   ├─ 哈希缓存跳过（ComputeHash + .build_hash 对比�?
   ├─ ProcessManager：孤儿进程清�?+ 编译�?+ Ctrl+C 安全退�?
   ├─ proc_open build.bat css-test + 子进程注�?
   └─ 产出：apps/css-test/bin/css_test.exe

Step D: 布局导出（LayoutDumpStep + DumpStrategy�?
   ├─ ExeDumpStrategy�?exe> --case=xxx --headless --dump-layout
   ├─ MockDumpStrategy（无 exe 降级）：MockPlatform 渲染
   ├─ REF_STALE 检测：验证导出�?JSON 包含测试用例关键文本内容
   ├─ 防止 ref/ 目录下的过期参考数据被误用于对�?
   └─ 产出：ref/engine_layout.json

Step E: 多帧稳定性（MultiFrameStep�?
   ├─ --dump-layout-after-frames=5 �?
   ├─ 逐节点对�?Frame 1 vs Frame N �?x/y/w/h
   └─ Δ�? 标记�?STABILITY 问题

Step G: 浏览器参考（BrowserRefStep + BrowserRefStrategy�?
   ├─ buildCssTestWrapper()：注�?normalize.css 重置�?important 最大优先级�?
   ├─ EdgeDomStrategy：Edge headless 渲染 �?DOM JSON
   ├─ EdgeScreenshotStrategy：Edge headless 截图
   └─ 产出：ref/browser_ref_*.png + ref/wrapper.html

Step H: 逐元素对比（ElementCompareStep + ComparatorRegistry�?
   ├─ GeometryComparator：位�?+ 尺寸 对比
   ├─ StyleComparator�?0+ 样式属性（�?bg 透明检测）
   ├─ StabilityComparator：多帧稳定�?
   ├─ Phase F 溢出检测：textRenderInfo.textWidth vs contentW
   └─ 产出：PASS/FAIL/SKIP 统计

Step I: 截图对比（ScreenshotStep�?
   ├─ exe --headless --screenshot �?engine_screenshot_{ts}.png
   ├─ Edge headless �?browser_ref_{ts}.png
   ├─ 三层锚点对齐：detectColorAnchors(#FF00FF/#00FFFF) �?autoDetectContentBounds
   ├─ GD 像素 diff + diff_{ts}.png 差异图生�?
   ├─ 锚点可见性校验：main.php WINDOW_WIDTH/HEIGHT 常量
   └─ 截图步骤不被元素对比结果阻塞
```

**多帧稳定性验证（强制�?*�?

- 所�?case 必须执行 `--dump-layout-after-frames=N`（默�?5 帧）
- `run.php` 自动比较 Frame 1 �?Frame N 的布局 JSON，逐节点对�?x/y/w/h
- 任何节点跨帧变化（Δx/Δy/Δw/Δh �?0）标记为 **STABILITY** 问题计入失败
- 已知触发场景：auto-height + absolute 子节点正反馈（见 §�?案例 3b�?

**对比维度**（`shared_test_lib.php defaultChecks()` 当前覆盖）：

| 类别 | 属�?|
|------|------|
| 排版 | fontSize, fg(color), **bg（始终导出：-1=透明�?*, bold(font-weight), textAlign, lineHeight, whiteSpace, wordBreak, fontStyle, textDecoration |
| 内边�?| paddingTop/Left/Right/Bottom |
| 外边�?| marginTop/Left/Right/Bottom |
| 边框 | borderWidth, borderColor, borderRadius, **borderTop/Right/Bottom/Left Width+Color** |
| 阴影/轮廓 | **boxShadow, outline** |
| 布局 | display, flexDirection, flexWrap, gap, alignItems, justifyContent, boxSizing |

> **粗体**为新近增补的属性�?0+ 样式属性已覆盖。`bg` 现在**始终导出**：未显式设置 �?`-1`（透明），确保即使无背景的元素也参与颜色对比，消除漏检盲区�?

---

### Phase 3：分析测试报�?

#### 3.1 报告结构

测试报告包含�?

**�?用例汇总表**
```
| 用例 | 构建 | 布局导出 | 多帧稳定�?| 浏览器对�?| 截图像素 | 结果 | 耗时 |
|------|------|----------|------------|-----------|----------|------|------|
| case-007 | �?| �?| �?| �?| 差异=0.5% | �?通过 | 12.3s |
```

**�?逐元�?JSON 对比详情**
```
[边框] target-box rel=(140,55) w=300 h=120
  �?x=144 y=63 w=300 h=120 (tol=1)
  �?borderTopWidth: engine=4 browser=4
  �?borderTopColor: engine=#e94560 browser=#e94560
  �?borderLeftWidth: engine=1 browser=1
  �?borderLeftColor: engine=#fb923c browser=#fb923c
```

**�?每个 case �?engine_layout.json** 持久化到 `test_case/case-NNN/ref/`

#### 3.2 差异分类与排�?

| 差异类型 | 典型原因 | 修复位置 |
|----------|---------|---------|
| **假阳�?* | 浏览�?ref wrapper 引入非标准基线（box-sizing/line-height/字体�?| `run.php` �?`buildCssTestWrapper()` |
| **位置偏差 (Δx/Δy > 1px)** | line-height 缺失/margin 折叠/padding 未计�?绝对定位 | `BlockLayoutStrategy` / `FlexLayoutStrategy` / `AbsolutePositioning` |
| **容器 auto-height 偏差** | auto-height 未减 paddingTop，或未排�?absolute/fixed 子节�?| `BlockLayoutStrategy` �?`resolveBlockLayout()` |
| **Grid/Flex 子元�?w=0** | GridLayoutStrategy 未设 style['width'] / BlockLayout 重解�?| `GridLayoutStrategy` / `BlockLayoutStrategy` |
| **颜色不匹�?* | GDI 颜色格式转换有误 / border-left 简写默认颜�?| `CssMappings` |
| **属性引擎缺�?* | serializeRenderNode 白名单未添加 / CssMappings 未映�?| `Application.php` / `CssMappings` |
| **Flex 宽度/位置偏差** | flex-grow/flex-shrink/justify-content/gap 计算 | `FlexLayoutStrategy` | **浏览器元素对比（JSON 对比无法发现�?* |
| **尺寸偏差 (w/h)** | 盒模型假设不一�?/ 百分比解�?/ 视口不匹�?| `PercentResolver` / `buildCssTestWrapper()` |
| **截图差异 > 5%** | 字体渲染 / 抗锯�?/ 颜色差异 / 布局偏移 | 联合 JSON 对比+浏览器元素对比定�?|
| **截图中锚点找不到** | 窗口尺寸不对 / 色块被遮�?/ 偏移过大 | 检�?WINDOW_WIDTH/HEIGHT 匹配 |
| **STABILITY 问题** | 多帧间坐标或尺寸不稳定（auto-height 正反馈） | `BlockLayoutStrategy` auto-height 排除 absolute/fixed |

#### 3.3 决策�?

```
差异出现
├─ JSON 对比显示引擎与浏览器 w/h/x/y 系统性偏移（所有元素同方向偏移相同量）�?
�?  ├─ �?�?视口不一�?�?检�?buildCssTestWrapper() �?`--window-size` 是否匹配引擎
�?  └─ �?�?继续
�?
├─ JSON 对比显示引擎与浏览器 w/h 偏差（仅特定元素，非系统性）�?
�?  ├─ 容器 auto-height 偏差�?
�?  �?  ├─ �?�?BlockLayoutStrategy auto-height 计算
�?  �?  └─ �?�?其他布局差异，进�?Phase 4
�?  └─ 子元素宽度未被父容器 padding 约束？→ 继承/百分比解�?
�?
├─ 引擎 JSON 位置/尺寸正确，但样式值不匹配（颜�?字号/行高/边框）？
�?  ├─ 浏览�?wrapper 引入了非标准 line-height/reset�?
�?  �?  ├─ �?�?**假阳�?*：修�?buildCssTestWrapper() + 重新生成 ref
�?  �?  └─ �?�?框架 bug，进�?Phase 4
�?  └─ 颜色格式差异？→ CssMappings GDI 颜色格式转换
�?
├─ 引擎 JSON 坐标/尺寸/样式与浏览器 ref 不一致（非上述情况）�?
�?  ├─ �?�?框架布局/样式 bug �?进入 Phase 4
�?  └─ �?�?截图仍有差异�?
�?      ├─ �?�?渲染效果差异（抗锯齿/字体/颜色格式�?
�?      └─ �?�?�?通过
�?
├─ 引擎无此属性（浏览器有）？
�?  ├─ 框架尚未实现 �?**禁止 SKIP，禁止修改测试样�?*，必须按�?CSS 标准实现完整支持
�?  ├─ 框架已实现但未导�?�?serializeRenderNode 白名�?
�?  └─ GDI 不支持但 Skia 可支�?�?使用 Skia 后端实现，不可绕�?
�?
└─ STABILITY 标记�?
    └─ auto-height/absolute 相关布局修改必须加多帧稳定性断言
```

---

### Phase 4：修复框�?应用缺陷

#### 4.1 框架修复路径

根据差异类型，进入对应的框架文件修改�?

```
布局坐标错误 (x/y/w/h 不匹�?
  ├─ display:block 容器�?auto-stack 位置不对
  �?  └─ framework/Rendering/Layout/BlockLayoutStrategy.php
  �?    - auto-stack 推进逻辑（childOffsetY �?visualH �?padding�?
  �?    - auto-height 计算：`maxBottom - (node->y + paddingTop)`（CSS §10.6.3�?
  �?    - margin 折叠逻辑（�?.3.1�?
  �?    - 百分比宽度解析与父容�?fallback
  �?    - **排除 position:absolute/fixed 子节点的 auto-height**
  �?
  ├─ display:flex 容器/子项位置不对
  �?  └─ framework/Rendering/Layout/FlexLayoutStrategy.php
  �?    - justify-content/align-items 计算
  �?    - flex-grow/shrink/basis
  �?    - parent=null 时使�?WINDOW_WIDTH/HEIGHT fallback
  �?
  ├─ display:grid 子项 w=0
  �?  └─ framework/Rendering/Layout/GridLayoutStrategy.php
  �?    - 设置 child->w 同时设置 child->style['width']
  �?
  ├─ position:absolute/fixed 定位不对
  �?  └─ framework/Rendering/Layout/AbsolutePositioning.php / AbsoluteStrategy.php
  �?    - padding box 边界计算公式
  �?    - positioningAncestor 缓存失效
  �?    - two-pass 容器 auto-height + padding
  �?
  ├─ inline 布局问题
  �?  └─ framework/Rendering/Layout/InlineLayoutStrategy.php
  �?
  └─ 百分�?相对单位不生�?
      └─ framework/Rendering/Layout/Tools/PercentResolver.php
        - parentSize=0 fallback 逻辑
        - calc() 表达式解�?

样式属性值不匹配
  ├─ CSS 属性未映射到内�?key
  �?  └─ framework/Rendering/CssMappings.php
  �?    - �?$propertyMap 添加新映�?
  �?    - �?$pctMap 添加百分比映射（如需要）
  �?
  └─ 属性存在但序列化未导出
      └─ framework/Core/Application.php
        - serializeRenderNode �?$styleKeys 白名单添�?

渲染视觉效果不一�?
  ├─ GDI 渲染问题
  �?  └─ framework/Rendering/GdiRenderContext.php
  �?    - drawText line-height 支持
  �?    - 颜色格式转换
  �?    - 边框绘制
  �?
  ├─ Skia 渲染问题
  �?  └─ framework/Rendering/SkiaRenderContext.php
  �?
  ├─ 文本溢出处理
  �?  └─ framework/Rendering/TextOverflowProcessor.php
  �?
  └─ clip/overflow 处理
      └─ framework/Rendering/VNodeRenderer.php
```

#### 4.2 新增 CSS 属�?Checklist

以新�?`box-shadow` 为例�?

- [ ] `CssMappings.php` �?添加 `'box-shadow' => 'boxShadow'` 映射
- [ ] `CssMappings.php` �?如属性有默认值，�?`'default' => 'none'`
- [ ] `Application.php serializeRenderNode` �?`$styleKeys` 中添�?`'boxShadow'`
- [ ] `tools/shared_test_lib.php` �?`defaultChecks()` 中添加对应项
- [ ] `test_case/case-NNN-name/` �?添加包含该属性的测试 case
- [ ] 运行验证：`php apps/css-test/test_pipeline.php --case=case-NNN`
- [ ] 全量回归：`php apps/css-test/test_pipeline.php`

#### 4.3 修复准则（三原则�?

1. **治本不治�?* �?在框架层修复，不在应用层�?workaround
2. **通用合规** �?修复应符�?CSS 标准，不针对特定测试用例特化。参�?[CSS 规范](https://www.w3.org/Style/CSS/) 后实现，不要猜测
3. **先覆盖后优化** �?先通过测试，再考虑性能。一次修复一个差�?

#### 4.4 应用层修�?

当确认框架已符合 CSS 标准（通过 JSON 对比 + 查阅规范验证），但渲染效果仍有差异时�?
- 修改 `.vue` 中的模板/样式
- 同步修改对应�?`.html`
- 重启迭代验证

#### 4.5 Bug 追踪与状态维�?

**Bug 台账格式**（`test_log/bug_tracker.md`）：

```markdown
# <项目> Bug 追踪台账

| # | 发现日期 | 问题描述 | 分类 | 状�?| 根因文件 | 修复提交 | 测试增强 |
|---|---------|---------|------|------|---------|---------|---------|
| 1 | 2026-06-09 | auto-height + absolute 子节点正反馈 | 框架 Bug | �?已修�?| BlockLayoutStrategy.php | abc1234 | css-test case-004 多帧验证 |
```

**分类与状态定�?*�?

| 字段 | 可选�?|
|------|--------|
| **分类** | `框架 Bug` / `工具 Bug` / `测试 Bug` / `应用层问题` / `已知限制` / `待确认` |
| **状�?* | `🟡 待处理` / `🟢 排查中` / `�?已修复` / `�?无法修复` / `📋 待定` |

**维护规范**�?
1. **发现即记�?* �?无论通过何种方式发现，立即新增台账条�?
2. **修复后更�?* �?更新状态、填写修复提交、记录新增的 test_case
3. **�?case 必更** �?每个 case 完成后，必须立即更新 bug_tracker.md 的全局清单�?per-case 跳过清单，完成确认后（含 SKIP 项）再进入下一�?case
4. **反思联�?* �?每次反思（§九）后，检查是否需要新增台账条�?
5. **定期审查** �?每个迭代周期结束时审�?"待处�? �?"已知限制"

**Per-Case 跳过清单**（`test_log/bug_tracker.md §二`）：

对于每个测试 case，必须维护一份显式的 **Per-Case 跳过清单**，列出所�?*已知差异但不阻塞迭代**的项目�?

```markdown
### case-NNN-name
| # | 跳过�?| 引擎�?| 浏览器�?| 分类 | 根因 | 关联Bug# |
|---|--------|--------|---------|------|------|---------|
| 1 | 描述具体差异 | engine_value | browser_value | SKIP-分类 | 根因说明 | #Bug编号 |
```

**跳过分类定义**�?

| 分类 | 说明 | 典型原因 |
|------|------|---------|
| **SKIP-工具差异** | buildCssTestWrapper() �?App.vue 之间�?CSS 基线不匹�?| wrapper �?`* { box-sizing }` �?bg |
| **SKIP-连锁反应** | 因其�?SKIP 项目导致的次级偏�?| 位置偏移由上级文本高度偏差级联导�?|

**通过条件**：所有不通过的项均为 SKIP 分类时，�?case 视为**通过**。但 SKIP 仅允�?*工具差异**�?*连锁反应**两类�?*不允许以「框架不支持」为�?SKIP**。框架未实现�?CSS 特性必须进�?Phase 4 修复，不得跳�?

**禁止性规�?*（从本文档生效起强制执行）：
1. 框架未支持的 CSS 特�?�?�?不得 SKIP �?必须：Phase 4 实现完整支持
2. 测试样例�?vue/.html�?�?�?不得修改来绕过框架限�?�?必须：增强框�?
3. GDI 不支持的特�?�?�?不得删减测试内容 �?必须：使�?Skia 后端实现
4. `serializeRenderNode` 白名单缺�?�?�?不得作为假阳性跳�?�?必须：补�?`$styleKeys`

—�?

**维护规范**（续）：
6. **直通不�?* �?已知限制/SKIP 项不阻塞迭代进度，但必须�?per-case 清单中显式记录后才能跳过
7. **SKIP 升级** �?同一 SKIP 项在同一模块多次出现，应升级为独立的框架改进任务

---

## 四、浏览器参考数据生成规�?

生成准确、无污染的浏览器参考数据是避免"假阳�?的基础�?

### 4.1 buildCssTestWrapper() CSS 基线规则

`run.php` 中的 `buildCssTestWrapper()` 负责组装测试 wrapper HTML�?

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

**四条铁律**（新增第 4 条）�?
1. **html/body 固定宽高** �?1600×800，与引擎 `WINDOW_WIDTH/HEIGHT` 一�?
2. **`* { box-sizing: border-box }`** �?引擎使用 border-box 盒模型（与标�?CSS content-box 不一致但已统一�?
3. **字体声明** �?必须包含 Noto Sans SC �?`@font-face` 加载块，否则浏览�?fallback 字体不同导致文本尺寸偏差
4. **粗体字体分离声明** �?`@font-face` 必须拆分�?Regular(400) �?Bold(700) 两个声明，分别加�?`NotoSansSC-Regular.ttf` �?`NotoSansSC-Bold.ttf`，使浏览器基线也使用真实粗体字宽（而非合成粗体�?

### 4.2 --window-size 匹配规则

| 上下�?| main.php | buildCssTestWrapper() |
|--------|---------|----------------------|
| css-test | `WINDOW_WIDTH=1600, WINDOW_HEIGHT=800` | `--window-size=1600,800` |
| 其他项目 | `WINDOW_WIDTH=1800, WINDOW_HEIGHT=1200` | `runEdgeHeadless()` 匹配 |

### 4.3 生成后验证清�?

- [ ] 参�?JSON 中元素的坐标在合理范�?
- [ ] normalize.css 正常加载（内�?CDN 可达性）
- [ ] 所有元素的 w/h/x/y 不为负数
- [ ] `@font-face` 正确引用 Noto Sans SC 字体
- [ ] 首元素位置在视口居中区域�?

---

## 五、迭代退出条�?

### 单次迭代退出（满足其一�?

1. **新增用例通过�?�?5%** �?所有匹配元素的样式+位置正确
2. **截图差异 �?%** �?像素级对比在阈值内
3. **所有属性均已覆�?* �?核心 CSS 属性白名单已全覆盖

### 全项目最终验收标�?

- 所�?case 通过�?�?0%
- 半数以上 case 通过�?�?5%
- 截图对比 �?% 差异
- 无构建失�?
- 无回归（�?case 通过率不低于上次报告�?5%�?

---

## 六、截图对齐机�?

### 6.1 颜色锚点对齐（推荐，优先级最高）

每个测试 case �?*最外层卡片容器**（`background:#fff` 的卡�?div）上设置 `position:relative`�?
在其 padding-box 四角嵌入两个 8×8 纯色块：

```html
<!-- __PX_ANCHOR_TL__ 左上角（卡片 padding-box 的左上角�?-->
<div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
<!-- __PX_ANCHOR_BR__ 右下角（卡片 padding-box 的右下角�?-->
<div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div>
```

- `position:relative` 设置方式�?
  - `.vue` 文件：在 card �?inline style 末尾添加 `;position:relative`
  - `.html` 文件：在对应 CSS class（如 `.bx-card`、`.wrapper-test`）定义末尾添�?`;position:relative}`
- 锚点与卡片关系：
  - TL 锚点 `top:0;left:0` �?卡片 padding-box 左上�?
  - BR 锚点 `bottom:0;right:0` �?卡片 padding-box 右下�?
- 锚点及卡片必须全程在可视化视口（1600×800）内
- 引擎渲染：绝对定位保证锚点始终在卡片 padding box 四角
- 浏览器截图：CSS 标准定位保证同样位置
- `buildCssTestWrapper()` / `buildScreenshotWrapper()` 不再注入锚点包装�?
- 对齐算法：`detectColorAnchors()` �?O(n) 扫描找到两个色块 �?计算偏移 dx/dy
- 优势：无需模板预提取、像素级精确、不受内容变化影�?

### 6.2 窗口尺寸一致�?

| 场景 | 窗口尺寸设置 | 注意事项 |
|------|-------------|---------|
| Px 应用 | `main.php` �?`WINDOW_WIDTH`, `WINDOW_HEIGHT` | 默认 1600×800；exe 不含 DPI 感知清单，高 DPI 系统�?`GetClientRect` 返回虚拟坐标 |
| 浏览�?ref 生成 | `buildCssTestWrapper()` �?`--window-size=1600,800`（Edge headless�?| Edge headless 不受窗口管理器约束，精确控制视口 |
| 浏览器基准截�?| Edge headless `--window-size=1600,800 --screenshot=out.png` | 替代旧的 PowerShell MoveWindow 方式（不可靠�?|

### 6.3 模板匹配对齐（回退方案�?

当无法嵌入颜色锚点时，使�?SAD 模板匹配�?
1. 从基准截图中提取视觉独特的区�?
2. `findAnchorInImage()` 多级分辨率搜索（4x 降采样粗�?�?全分辨率精搜�?
3. 计算偏移量对齐后像素级对�?

### 6.4 全自动内容检测对齐（最终回退�?

- `autoDetectContentBounds()` 基于亮度变化 + 颜色范围自动识别内容边界
- 裁剪掉均匀的边缘区�?
- 适用于无明显锚点或特征区域的截图

### 6.5 对齐流程优先�?

```
compareScreenshot() 执行�?
  1. 颜色锚点检测（detectColorAnchors）→ 最快最�?
  2. 模板锚点匹配（findAnchorInImage）→ 需预先提取模板
  3. 自动内容检测（autoDetectContentBounds）→ 通用回退
  4. 无对齐（dx=0, dy=0）→ 仅用于完全一致的截图
```

---

## 七、项目治理与回归防护

### 7.1 css-test 统一框架的优�?

所�?CSS 标准对齐工作统一�?`apps/css-test/` 下进行，避免多项目分散：

| 方面 | 旧方案（多项目） | 新方案（css-test 统一�?|
|------|----------------|----------------------|
| 测试入口 | 每个项目�?auto_test.php | 单一 `run.php` |
| 测试用例 | 各项�?test_cases/ 独立 | 统一 `test_case/` 目录 |
| 对比�?| 各项目复�?shared_test_lib.php | 单一共享�?|
| 报告 | 各项目独�?test_log/ | 统一 `apps/css-test/test_log/` |
| 执行 | 逐个项目手动运行 | `php apps/css-test/test_pipeline.php` 一次跑�?|

### 7.2 回归防护

每次框架修改后，运行全量测试�?

```bash
php apps/css-test/test_pipeline.php
```

**回归验证标准**�?
- 已有 case 的通过率不应下降超�?5%
- 截图差异不应�?<5% 上升�?>10%
- 已有 case 不应新增 FAIL
- 不应新增 STABILITY 标记

### 7.3 执行策略

- **日常开�?*：`php apps/css-test/test_pipeline.php --case=case-NNN` 聚焦单一特�?
- **提交�?*：`php apps/css-test/test_pipeline.php` 全量回归
- **框架修改�?*：全量回归验证无退�?

### 7.4 归档基线回归检�?

**原理**：将通过�?case 布局数据冻存为基线快照，后续框架修改后重新运行已归档 case 的布局并与基线比对，在几何/样式/稳定�?浏览器元素对比四维度检测回归�?

**归档内容**（每个已归档 case �?`test_case/case-xxx/baseline/` 下）�?

```
baseline/
├── engine_layout.json                   �?Frame 0 布局快照（含完整 RenderNode �?+ 样式字段�?
├── engine_layout_after_5frames.json     �?多帧稳定性基�?
└── browser_ref_elements.json            �?浏览器基线元素数据（Edge headless 渲染参考）
```

**四维度回归对�?*�?

| 维度 | 对比内容 | 数据来源 | 覆盖范围 |
|------|---------|---------|---------|
| 几何 | x, y, w, h, visualW, visualH（容差可配，默认 1px�?| `--dump-layout` | 32/32 case �?|
| 样式 | style 对象中所�?CSS 属性（bg/fontSize/color/display/border…） | `--dump-layout` | 32/32 case �?|
| 稳定�?| 多帧间节点位�?尺寸漂移 | `--dump-layout-after-frames=5` | 32/32 case �?|
| 浏览器元素对�?| 引擎 vs 浏览器逐文本元素对比（位置+尺寸+样式），发现 JSON 布局对比无法捕捉�?flex 宽度偏差、内容高度差异、渲染效果偏�?| `browser_ref_elements.json` | 32/32 case �?|

**使用流程**�?

```bash
# 归档（case 稳定后）
php archive_case.php case-003-basic-block        # 单个归档（默�?5 帧）
php archive_case.php --frames=10 case-xxx        # 自定义帧�?
php archive_case.php --all                       # 批量归档所有有 ref/ �?case
php archive_case.php --list                      # 查看归档状态（多帧数、样式字段数�?

# 回归检查（框架修改后或提交前）
php check_regression.php                              # 全量检查所有归�?case
php check_regression.php case-003-basic-block         # 只检查指�?case
php check_regression.php --json                       # JSON 输出（供 CI/工具解析�?
php check_regression.php --tolerance=2                # 自定义几何容�?
php check_regression.php --skip-styles                # 跳过样式对比（调试加速）
php check_regression.php --skip-multiframe            # 跳过稳定性对比（调试加速）
php check_regression.php --skip-browser               # 跳过浏览器元素对比（调试加速）
php check_regression.php --fail-fast                  # 遇首个失败即�?
```

**工作流中的位�?*�?

```
框架修改 �?构建 �?check_regression.php（全量回归）
                              �?通过
                           run.php --case=新case（开发新特性）
                              �?通过
                           archive_case.php（归档新case�?
                              �?
                           git commit
```

**容差策略**�?
- 几何容差默认 1px，允�?GDI/Skia/浏览器间的亚像素舍入差异
- 样式字段必须精确匹配（无容差），任何 CSS 属性值变化都视为回归
- 稳定性要�?5 帧间零漂移，否则标记�?STABILITY 失败

**�?run.php 的区�?*�?

| 方面 | run.php（全量测试） | check_regression.php（回归检查） |
|------|-------------------|--------------------------------|
| 参考源 | 浏览�?ref（Edge headless�?| 基线快照（引擎自身历史数据） |
| 速度 | 慢（需启动 Edge�?| 快（�?exe + JSON 对比�?|
| 检测范�?| 几何 + 样式 + 截图 | 几何 + 样式 + 稳定�?|
| 使用时机 | �?case 开�?| 框架修改后快速回�?|
| 依赖 | Edge headless + 字体 | 无外部依�?|

---

## 八、典型修复案例分�?

### 案例 1：Flex 容器 parent=null 时百分比尺寸解析失败

**症状**：外�?`display:flex; width:100%; height:100%` 的容器被解析�?100×100

**根因**：`FlexLayoutStrategy` �?`$ctx->parent === null` 时返�?`parentW=0, parentH=0`，导�?`width:100%` �?`parentSize > 0` 条件不满足而回退�?`parsePixels('100%') = 100`

**修复**：当 parent=null 时使�?`WINDOW_WIDTH/HEIGHT` 作为父容器尺寸（`BlockLayoutStrategy` 已有�?fallback�?

**框架文件**：`framework/Rendering/Layout/FlexLayoutStrategy.php`

---

### 案例 2：绝对定�?bottom:0 right:0 位置�?

**症状**：`position:absolute;bottom:0;right:0` 的元素不在容�?padding box 右下�?

**根因**：`AbsolutePositioning.php` �?padding box 边界计算公式未正确包�?`paddingRight`/`paddingBottom`

**修复**�?
- rightEdge：padding box 右边�?= `ancestorX + ancestorW + ancestorPaddingRight - right`
- bottomEdge：padding box 下边�?= `ancestorY + ancestorH + ancestorPaddingBottom - bottom`

**框架文件**：`framework/Rendering/Layout/AbsolutePositioning.php`

---

### 案例 3a：auto-height 容器 + absolute 子节点（第一遍跳过）

**症状**：`position:relative; height:auto` 的容器包�?`position:absolute` 子节点，absolute 子节点位置不�?

**根因**：BlockLayoutStrategy 第一遍解析时跳过 absolute 子节点，�?auto-height 计算后未重新解析

**修复**：在 auto-height 算出后增加第二遍解析，传入容器最终坐�?

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php`

---

### 案例 3b：auto-height 容器 + absolute 子节点（Frame 2+ 正反馈循环）

**⚠️ Frame 依赖�?bug**：Frame 1 正确、Frame N 异常。`--dump-layout` 单帧模式永远检测不到�?

**症状**：容器高度逐帧膨胀（每帧增�?paddingBottom）。布局 dump 正确但实际截图尺寸巨大�?

**根因**：`BlockLayoutStrategy::resolveBlockLayout()` �?auto-height 计算在遍历所有子节点�?`maxBottom` 时，没有排除 `position:absolute` �?`position:fixed` 的子节点，违�?CSS 2.2 §10.6.3�?

**正反馈链**�?
1. Frame 1: absolute 子节点尚未定位（y=0），auto-height 只取 normal flow �?正确 �?
2. Frame 2: BR 锚点已被 Frame 1 第二遍定位到容器底部 �?auto-height 错误包含�?�?computedH 增长
3. Frame N: h 每帧增长，直到逼近窗口高度

**修复**：auto-height foreach 循环开头添�?position 检查：
```php
$childPosition = $child->style['position'] ?? 'static';
if ($childPosition === 'absolute' || $childPosition === 'fixed') {
    continue;
}
```

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php`

**经验教训**�?
1. Frame 依赖�?bug �?`--dump-layout` 和单�?resolve 测试的死�?
2. CSS 布局必须严格遵循规范：auto-height 只计�?normal flow 子节�?
3. css-test 的多帧稳定性验证强制所�?case 执行 `--dump-layout-after-frames=N`

---

### 案例 4：Block auto-height 包含 paddingTop

**症状**：带 padding �?auto-height 容器引擎 h=456，浏览器 h=520（偏�?64px = 2×paddingTop�?

**根因**：CSS 2.2 §10.6.3 规定 auto-height = 从内容区上边缘到最后一�?in-flow 子元素下边缘。BlockLayoutStrategy �?`maxBottom - node->y` 计算，但 `node->y` �?border-box 上边缘（包含�?paddingTop）�?

**修复**：`computedH = maxBottom - (node->y + paddingTop)`

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php`

---

### 案例 5：浏览器 ref wrapper CSS 导致假阳性（box-sizing / line-height�?

**症状**：测试多项失败是假阳性——文字宽度偏�?56px、文本高度偏�?8px。引擎行为正常�?

**根因**：`buildCssTestWrapper()` �?`generate_project_ref.php` 中设置了非标�?CSS�?
- `* { box-sizing: border-box }` vs 引擎默认 content-box（已统一使用 border-box�?
- `body { line-height: 1.7 }` vs 引擎 `line-height: normal`

**修复**�?
1. 统一 `* { box-sizing: border-box }` 匹配引擎
2. 移除 body 行高设置，改�?normalize.css 基线
3. 引入 `<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/normalize.css">`

**框架文件**：`run.php` �?`buildCssTestWrapper()` / `tools/generate_project_ref.php`

---

### 案例 6：浏览器视口与引擎窗口不一致导致坐标系统性偏�?

**症状**：所有元�?x 坐标引擎比浏览器�?260px，方向一�?

**根因**：引�?WINDOW_WIDTH �?`--window-size` 不匹配。flex 容器在不同视口中居中位置不同�?

**修复**：将 `--window-size` 匹配引擎窗口尺寸。css-test 统一�?1600×800�?

---

### 案例 7：Skia 细矩形抗锯齿导致分隔线和卡片边缘渲染膨胀

**症状**�?px 高的分隔线（`border-top:1px solid #eee`）在 EXE 中显示为 ~3px 高；卡片边缘轻微模糊，整体视觉偏"�?

**根因**：`skia_render.cc` �?`php_sk_alpha_fill_rect` 在绘制矩形时无条件设�?`paint.setAntiAlias(true)`。对于宽或高只有 1~2px 的细矩形，抗锯齿会使本应锐利的线条在两侧各扩展约 1px，造成视觉膨胀�?

`php_sk_fill_rect` 的对应代码（line 403）已有保护逻辑�?
```cpp
paint.setAntiAlias((int)w > 2 && (int)h > 2);
```
�?`php_sk_alpha_fill_rect` 缺失此保护，导致透明矩形路径（如分隔线）渲染膨胀�?

**修复**：在 `php_sk_alpha_fill_rect` 中添加相同的细矩形防抗锯齿保护：
```cpp
paint.setAntiAlias((int)w > 2 && (int)h > 2);
```

**框架文件**：`cpp/skia_render.cc`

**管线盲区**�?

| 防线�?| 问题 | 根因 |
|--------|------|------|
| 元素对比（layout JSON�?| 通过 �?| 布局引擎报告 h=1 正确，但渲染时抗锯齿膨胀；元素对比只比较 JSON 数据，不验渲染效�?|
| 截图对比 | 未拦�?| 基线截图从同一 buggy exe 生成，膨胀效果抵销 |
| 单元测试 | 未涉�?| 无渲染原语级别的抗锯齿行为测�?|

**反�?*：布局层数据正�?�?渲染层效果正确。仅依赖 JSON 对比无法捕获渲染器级别的抗锯�?颜色/字体渲染差异�?

---

### 案例 8：Skia/FreeType 字体测宽与浏览器 DirectWrite 不一�?

**症状**�?Test Case" 粗体 14px 引擎测量 72px，浏览器参�?64px（dw=8）。引擎整体布局因文本测宽偏宽而系统性偏移，导致等比例截图中 EXE �?�?�?

**根因**：`skia_render.cc` �?`php_sk_measure_text_width` �?`USE_SKIA` 路径下使�?Skia/FreeType 引擎进行文本宽度测量。FreeType 与浏览器 Edge 使用�?DirectWrite 字体引擎渲染策略（hinting、glyph advance 计算）不同，同一字体的测宽结果存在固有差异�?

**修复**：`php_sk_measure_text_width` �?`USE_SKIA` 路径下改�?GDI `GetTextExtentPoint32W` 进行文本宽度测量。Skia 仍负责实际绘制（提供抗锯齿和圆角），宽度测量改用 GDI 以保证与浏览器的 DirectWrite 测量一致�?

```cpp
// 不再使用 Skia measureText，改�?GDI GetTextExtentPoint32W
SelectObject(hdc, hFont);
GetTextExtentPoint32W(hdc, text, len, &sz);
width = sz.cx;
```

**额外修复——粗体真实字形加�?*�?
- 引擎端：`skia_render.cc` 通过 `AddFontMemResourceEx` 预加�?`NotoSansSC-Bold.ttf`，在粗体绘制/测宽时切换到真实粗体字体文件
- 浏览器端：`buildCssTestWrapper()` �?`@font-face` 拆分�?Regular(400) �?Bold(700) 两个声明，使浏览器基线也加载真实粗体字体

**框架文件**：`cpp/skia_render.cc`、`apps/css-test/test_pipeline.php`（@font-face 分离�?

**管线盲区**�?

| 防线�?| 问题 | 根因 |
|--------|------|------|
| 元素对比 | 未拦�?| 对比用的浏览器参考数据也受字体影响，差异未超过容�?|
| 截图对比（cropAnchors 模式�?| 未拦�?| 等比例缩放抵销了绝对宽度差异，但比例差异被归入"渲染差异" |
| 布局 JSON 对比 | 未涉�?| 布局层只关心测宽结果，不判断测量值的绝对正确�?|

**教训**：跨平台/跨引擎的字体渲染差异是持续问题。GDI 测宽 + Skia 绘制是当前最实用的折中方案�?

---

### 案例 9：布局�?font-weight key �?CssMappings 映射不一�?

**症状**：粗体文本（font-weight:700）在引擎中始终以常规宽度测量和渲染，即使 CSS 正确解析�?bold=1�?

**根因**：`CssMappings.php` �?CSS 属�?`font-weight` 映射到内�?key `'bold'`（�?0/1），但所有布局策略类（`BlockLayoutStrategy`、`FlexLayoutStrategy`、`GridLayoutStrategy`、`AbsolutePositioning`、`InlineLayoutStrategy`）在访问粗体值时使用 `$style['fontWeight']`，导致始终读取到 `null`（fallback �?'normal'）�?

CssMappings 映射链：
```
font-weight CSS �?parseFontWeight �?$style['bold'] = 0|1
                                                 �?
                                         布局策略错误地用
                                         $style['fontWeight']
```

**修复**：将 5 个布局策略文件中的 `$style['fontWeight']` 全部替换�?`($style['bold'] ?? 0)`�?
```php
// 修复�?
$bold = $style['fontWeight'] ?? 'normal';
// 修复�?
$bold = ($style['bold'] ?? 0) !== 0;
```

**框架文件**�?
- `framework/Rendering/Layout/BlockLayoutStrategy.php`
- `framework/Rendering/Layout/FlexLayoutStrategy.php`
- `framework/Rendering/Layout/GridLayoutStrategy.php`
- `framework/Rendering/Layout/AbsolutePositioning.php`
- `framework/Rendering/Layout/InlineLayoutStrategy.php`

**教训**：CssMappings �?key 映射与布局层的消费方之间存在隐式契约。新�?CSS 属性映射后，必须同步检查所有消费方使用�?key 是否正确�?

---

### 案例 10：Block 布局 margin:auto 未包�?padding �?border

**症状**：`margin:0 auto` 居中时，引擎计算的位置比浏览器左偏。以 case-001 为例，引�?`x=400`，浏览器 `x=375`（偏�?25px = 1px border-left + 24px padding-left）�?

**根因**：`BlockLayoutStrategy` �?`resolveMarginAuto()` 函数�?`$parentContentW - $node->w` 计算水平剩余空间，只减了子元素的 content width，漏�?border �?padding。CSS 规范�?margin:auto 居中的剩余空间应该用**父容�?content box 宽度 - 子元�?border-box 宽度**�?

```php
// 修复�?
$remaining = $parentContentW - $node->w;
// 修复后（考虑 padding �?border�?
$remaining = $parentContentW - ($node->w + $paddingLeft + $paddingRight + $borderLeft + $borderRight);
```

**修复**：在 `resolveMarginAuto` 中从子元素的 `visualW` 获取完整盒宽度（�?padding + border），替代�?content width�?

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php`

**管线盲区**�?

| 防线�?| 问题 | 根因 |
|--------|------|------|
| 元素对比 | 未拦�?| margin:auto 在浏览器和引擎间偏差 25px，但 layout JSON 对比未检查居中计算路�?|
| 截图对比 | 未拦�?| 基线从旧 exe 生成，偏差被 normalize |
| 单元测试 | 未涉�?| �?margin:auto 居中路径的独立断言 |

**教训**：margin:auto 布局正确性依赖浏览器参考数据作为独立锚点，不可依赖 exe 自生成的基线�?

---

### 案例 11：无背景元素默认渲染黑色——渲染器 vs 布局 JSON 分离盲区

**症状**：Footer div（`border-top:1px solid #eee`，无 `background`）在 EXE 中渲染为黑色大黑条，浏览器中为透明背景+细线�?

**根因**：`VNodeRenderer::makeDivElement()` �?`$drawColor = ($bg !== null) ? $bg : 0`。CSS 标准 `background-color` 初始值为 `transparent`，但引擎在无显式背景时默认用 `0`（黑�?GDI 颜色）填充整个元素区域�?

**修复**�?
1. 引入 `$noFill` 标志：当 `$bg === null` 时所有背景填充分支（圆角/半透明/实心）跳过，边框不受影响
2. 同时修复�?`$btc/bbc/blc/brc` 变量作用域问题——直角边框路径中 border color 变量未初始化

**框架文件**�?
- `framework/Rendering/VNodeRenderer.php`
- `framework/Rendering/GdiRenderContext.php`
- `framework/Rendering/SkiaRenderContext.php`

**增强**：dump-layout 现在**总是导出 `bg`**（未显式设置时用 `-1` 透明标记），元素对比和容器对比都参与颜色检�?

**管线盲区分析**�?

| 防线�?| 问题 | 根因 |
|--------|------|------|
| 元素对比（Phase A�?| 未拦�?| `bg` 未显式设�?�?引擎不导�?�?对比跳过 |
| 元素对比（Phase B 容器�?| 未拦�?| `noTextStyle=true` 跳过全部样式对比 |
| 截图对比 | 未拦�?| 基线可能从同一 buggy exe 生成 |

**教训**：布局层数据正�?�?渲染层效果正确。JSON 属性缺失时对比直接跳过，造成无声漏检。必须确保所有关键样式属�?*始终导出**，即使取默认值也应显式标记，让对比能够参与检测�?

## 九、问题反思机�?

### 9.1 反思触发条�?

| 触发条件 | 说明 |
|---------|------|
| **管线未捕获的 bug** | 测试管线全部通过后，仍被人工/截图发现 |
| **回归遗漏** | 框架修改导致已通过的测试退化但未被检测到 |
| **反复出现的同类问�?* | 同一模块、同一模式在不同场景下多次�?bug |
| **测试盲区暴露** | 现有测试体系完全无法覆盖�?bug 类型 |
| **人工审查发现的模式缺�?* | Code review 发现的设计层面的问题 |

### 9.2 反思六步法

#### Step 1：症状记�?

```
记录�?              内容示例
──────────────────────────────────────────────
发现时间            2026-06-09
发现方式            布局 dump vs 截图对比（TL/BR 锚点跨度异常�?
预期结果            536×484（与布局 dump 一致）
实际结果            536×898（截图膨胀 414px�?
影响范围            case-004 容器高度
```

#### Step 2：根因定�?

- **最小化**：从完整 case 逐步剥离到最简可复�?
- **归因**：确定是框架 bug、应�?bug、还是工�?bug
- **定位**：具体到文件 × 行号 × 逻辑分支

#### Step 3：管线盲区分�?

| 防线�?| 问题 | 根因 |
|--------|------|------|
| `--dump-layout` 对比 | 通过 �?| 单帧模式，Frame 2+ �?bug 永不触发 |
| 截图对比 | 未拦�?| baseline 从同一 buggy exe 生成 �?差异抵销 |
| 单元测试 | 通过 �?| 所有测试只单次 resolve()，无跨帧断言 |
| Code review | 未发�?| auto-height 逻辑修改时未考虑 Frame 2+ 行为 |

**关键产出**：明确列出每层的**失效原因**�?*改进措施**�?

#### Step 4：修复验�?

- 确认修复代码符合 CSS 规范引用
- 在最小测�?case 上验�?Frame 1�?�? 稳定�?
- 在全量测试上验证无回�?

#### Step 5：测试增�?

| 防线 | 增强措施 | 对应产出 |
|------|---------|---------|
| css-test | 新增多帧稳定性验证（`--dump-layout-after-frames=5`�?| run.php 内置 |
| 回归验证 | 将新 case 加入回归套件 | test_case/ 目录 |
| 文档 | 更新案例�?| 本文�?|

#### Step 6：知识沉淀

1. **本文�?§�?案例�?* �?记录 Bug 症状、根因、修复、教�?
2. **本文�?§�?反思清�?* �?新增/更新反思清单条�?
3. **AGENTS.md** �?同步�?AI 驱动的开发规�?
4. **test_case/** �?新增测试 case 作为活的文档

### 9.3 反思输�?Checklist

- [ ] Bug 症状与根因已记入 §�?案例�?
- [ ] 管线盲区分析（防线层 �?问题 �?根因 三列表）已完�?
- [ ] 测试增强已实现且通过
- [ ] 对应 test_case/ 目录已有覆盖�?Bug 的用�?
- [ ] AGENTS.md 中相关规范已同步更新
- [ ] 反思中暴露的通用盲区已抽象为检查清单项
- [ ] 反思清单（§9.4）已更新
- [ ] 对应项目�?Bug 台账已新增或更新条目

### 9.4 反思清单（持续维护�?

| # | 盲区类别 | 首次暴露�?| 预检要求 |
|---|---------|-----------|---------|
| 1 | **Frame 依赖�?Bug**：单帧正�?�?多帧正确 | 案例 3b | 涉及 auto-height / absolute / padding 的布局修改必须通过 `--dump-layout-after-frames=N` 验证 |
| 2 | **自引用基�?*：截�?布局对比�?baseline 从同一 buggy exe 生成 �?差异抵销 | 案例 3b | baseline 优先使用浏览�?ref（非 exe），迫不得已时在报告中注明基线来�?|
| 3 | **单次 resolve 假设**：单元测试只 resolve 一�?�?无法暴露 Frame 2+ 的稳定性问�?| 案例 3b | run.php 默认 5 帧多帧验证，禁止减少帧数 |
| 4 | **隐性基线偏�?*：ref 生成工具�?wrapper CSS 引入非标准基�?| 案例 5 | 出现系统性差异时，先排查 buildCssTestWrapper()，再排查引擎 |
| 5 | **视口不一�?*：引擎和 ref 生成�?window-size 不匹�?�?系统性坐标偏�?| 案例 6 | 项目初始化时确认 `--window-size` �?`WINDOW_WIDTH/HEIGHT` 一�?|
| 6 | **字体缺失引起文本尺寸偏差**：浏览器 fallback 字体与引�?Noto Sans SC 不同 | css-test 日常 | `@font-face` 声明必须包含�?buildCssTestWrapper() �?|
| 7 | **渲染�?vs 布局层分�?*：layout JSON 数据正确 �?渲染效果正确 | 案例 7 | 涉及 C++ Skia/GDI 绘制原语修改后，必须通过截图对比验证渲染效果 |
| 8 | **字体引擎差异**：FreeType(Skia) �?DirectWrite(浏览�? 测宽存在固有差异 | 案例 8 | `measure_text_width` 修改后需交叉验证 GDI/Skia/DirectWrite 三路测量结果一致�?|
| 9 | **CSS 映射 key 不一�?*：CssMappings 新增属性后消费�?key 未同步更�?| 案例 9 | 新增 CSS 属性映射后必须 grep 所�?`$style['...']` 消费方确�?key 一�?|
| 10 | **过期参考数�?*：ref/engine_layout.json 内容与测试用例不匹配未被检�?| 多个 case | 布局导出后立即执�?`validateEngineLayoutContent()` 校验内容一致�?|
| 11 | **margin:auto 无独立断言**：居中计算正确性依赖外部基线，无自洽验�?| 案例 10 | margin:auto 修改后必须用浏览�?ref（非 exe）作为独立锚点验证居中结�?|
| **12** | **属性缺失时对比跳过**：引擎未导出 `bg` �?对比 `!isset(eStyles['bg'])` �?无声跳过 �?黑色大黑条不被检�?| **案例 11** | 所有关键样式属性必�?*始终导出**，未显式设置时用 sentinel 值（�?`-1`）标记默认态，确保对比参与检�?|

---

## 十、附�?

### 命令速查

```bash
# css-test 全量测试
php apps/css-test/test_pipeline.php

# 单用例开�?
php apps/css-test/test_pipeline.php --case=case-007
php apps/css-test/test_pipeline.php --case=case-007 --verbose
php apps/css-test/test_pipeline.php --case=case-007 --frames=10

# 跳过某些步骤（调试时加速）
php apps/css-test/test_pipeline.php --skip-build               # 跳过编译
php apps/css-test/test_pipeline.php --skip-browser-ref          # 跳过浏览器对�?
php apps/css-test/test_pipeline.php --update-baseline           # 更新参考数�?

# 截图（手�?�?Edge headless�?
msedge --headless --disable-gpu --window-size=1600,800 `
  --screenshot="test_log/browser_ref_20260613_143025.png" `
  "file:///D:/Px/apps/css-test/test_case/case-NNN/wrapper.html"

# 构建（单独）
cd D:\Px
.\build.bat css-test

# SFC 编译（动态组件模式）
php sfc-compiler.php apps/css-test/App.vue

# 手动导出布局（带 --case 参数�?
cd apps/css-test/bin
.\css-test.exe --case=case-001 --dump-layout
.\css-test.exe --case=case-029-scroll-flex-col --dump-layout-after-frames=5

# 单项目浏览器参考（�?css-test�?
php tools\generate_project_ref.php <project>

# css-test 浏览器参考（旧版工具，run.php 已内联）
php tools\generate_browser_refs.php

# 截图（手�?�?PowerShell，已不建议使用）
powershell -ExecutionPolicy Bypass -File tools/capture_screenshot.ps1 `
    -AppName css-test -ProjectRoot D:/Px `
    -OutputPath apps/css-test/test_log/captured.png

# 归档与回归检�?
php archive_case.php --list                         # 查看归档状�?
php archive_case.php --all                          # 全量归档
php check_regression.php                            # 回归对比检�?
php check_regression.php --json | python -m json.tool # JSON 格式输出

# 全量测试（所有项目，含回归）
php tests/run_all_tests.php
```

### 参考文�?

- [CSS Positioned Layout Module Level 3](https://www.w3.org/TR/css-position-3/) �?绝对/固定定位
- [CSS Flexible Box Layout Module Level 1](https://www.w3.org/TR/css-flexbox-1/) �?Flex 布局
- [CSS Grid Layout Module Level 1](https://www.w3.org/TR/css-grid-1/) �?Grid 布局
- [CSS Box Model Module Level 3](https://www.w3.org/TR/css-box-3/) �?盒模�?padding/margin
- [CSS Values and Units Module Level 3](https://www.w3.org/TR/css-values-3/) �?百分�?calc/单位
- [CSS Overflow Module Level 3](https://www.w3.org/TR/css-overflow-3/) �?溢出/滚动
- [CSS Cascading and Inheritance Level 4](https://www.w3.org/TR/css-cascade-4/) �?层叠/继承

### 诊断技�?

```php
// 在框架代码中加日志（记得修完后删除或用开关控制）
error_log('[DIAG] enter resolveFlexLayout type=' . $node->type . ' w=' . ($style['width'] ?? 0));

// �?compareElementEnhanced 中针对特定元素加调试
if ($label === '目标元素') {
    file_put_contents('debug_element.log', print_r([
        'browser' => $bEl, 'engine' => $eEl
    ], true));
}

// 直接检�?engine_layout.json 确认引擎坐标和样式�?
// 位于 test_case/case-NNN/ref/engine_layout.json
```

### 多帧布局稳定性验�?

**背景**：auto-height + absolute 子节点的正反馈循�?bug 证明�?Frame 依赖�?bug �?`--dump-layout` 的死角�?

**css-test 中的多帧验证**（内置在 run.php 中）�?
- `--dump-layout-after-frames=5` 默认执行
- 自动比较 Frame 1 �?Frame 5 的布局 JSON，逐节点对�?x/y/w/h
- 任何跨帧变化标记�?**STABILITY** 问题计入失败

**具体场景**（必须关注多帧稳定性）�?
- 任何�?auto-height �?block 容器 + absolute/fixed 子节�?
- 任何�?padding �?auto-height 容器
- 任何调整了子节点 y 坐标的布局策略（flex/grid 重定位后�?

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
  /* �?.vue 同步的样�?*/
</style></head><body>
  <!-- �?.vue <template> 一致的内容 -->
</body></html>
```

> **⚠️ 样本偏差警示 �?.vue �?.html 结构必须严格一�?*
>
> 浏览器参考数据（`browser_ref_level_0.json`）从 `.html` 生成，引擎布局快照�?`.vue` 编译�?exe 生成�?
> 若两�?DOM 结构不一致，对比将产生系统性偏差，表现�?*全用例一致的 dx/dw**（如 dw=90）�?
>
> **历史案例**：case-001/case-002 �?`.html` �?`<div class="sandbox" style="padding:20px">` 包装层，
> �?`.vue` 直接以根元素开始，导致引擎缺少 20px padding 包装 �?引擎元素宽度比浏览器�?90px�?
>
> **检查清�?*（每次新�?test_case 必须核对）：
> 1. `.vue` �?`<template>` 根元素与 `.html` �?`<body>` 内第一个元素结构一�?
> 2. 所�?CSS 类名�?inline style 在两者间一�?
> 3. 嵌套层级（额�?wrapper 层）完全对齐
> 4. `buildCssTestWrapper()` 注入的全局 CSS（`* { margin:0; padding:0; }` 等）在引擎端有无匹配�?
> 5. 使用 `php run.php --case=case-NNN --update-baseline` 后检�?dw 是否接近 0
>
> **修复流程**：优先修�?`.vue` 对齐 `.html`（`<template>` 是源），然后重新编译�?`--update-baseline`�?
> 切勿仅修�?`.html` 而不更新 `.vue`，否则引擎与浏览器参考的偏差将持续存在�?
