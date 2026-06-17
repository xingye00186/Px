# CSS 标准对齐迭代工作流 — AI 驱动（PxTest v2）

> **目标**：通过 `apps/css-test/` + `tools/PxTest/` 统一测试框架 + 自动化迭代，使 Px 框架渲染结果与 Edge Chromium 像素级一致。
> **核心**：数据驱动 → 治本修复 → 归档验证 → 分类提交，形成持续闭环。
> **入口**：`php apps/css-test/test_pipeline.php`（Pipeline + Strategy D→I 六步编排）

---

## 一、核心原则

| 原则 | 说明 |
|------|------|
| **先验证后修复** | 跑完整测试链，数据说话，不靠猜测 |
| **治本不治标** | 框架层 bug 在框架层修复，不在 App.vue 打补丁 |
| **通用合规** | 修复严格符合 CSS 标准，不针对特定测试特化 |
| **分治** | 每个 test_case 只测一个 CSS 特性或一个页面区域 |
| **回归防护** | 每次修复后验证原有测试不退化 |
| **排假阳** | 差异出现时，先排除浏览器 wrapper HTML 引入的基线差异 |
| **CSS 标准铁律** | 框架 fallback 使用 CSS 标准默认值。非标准行为必须在样式声明中显式写出 |
| **不支持即实现** | CSS 特性缺失必须按规范实现，不得 SKIP。GDI 不支持的使用 Skia 后端 |
| **不可绕过测试样例** | 任何情况下不得修改测试样例来绕过功能缺失 |

### 决策优先级

```
差异出现
├─ 框架符合 CSS 标准吗？查看属性标准默认值
│   └─ 框架正确但测试期望非标准值 → 框架正确，应用层缺显式声明，改应用层
├─ wrapper 引入基线差异（box-sizing/line-height/字体不一致）
│   └─ 修复 buildCssTestWrapper() + 重新生成浏览器 ref
├─ 框架不符合 CSS 标准（fallback 用了非标准默认值）
│   └─ 改框架 + 更新问题清单
├─ 框架尚未实现该 CSS 特性
│   └─ 必须实现，不得 SKIP。GDI 不支持的使用 Skia 后端
├─ 框架符合标准，应用层用法错
│   └─ 改 .vue + 同步改 .html
└─ 字体引擎差异（Skia/DirectWrite）
    └─ 记录到问题清单 B-012 类，后续修复
```

---

## 二、工具链速查

### 2.1 核心工具

| 工具 | 路径 | 用途 |
|------|------|------|
| **PxTest 编排器** | `php apps/css-test/test_pipeline.php` | Pipeline+Strategy D→I 全流程编排 |
| 构建脚本 | `.\build.bat css-test` | PHP → AOT exe（BuildStep 内部调用） |
| 布局导出 | `bin/css_test.exe --headless --dump-layout` | → engine_layout.json |
| 无窗口截图 | `bin/css_test.exe --headless --screenshot=out.png` | 离屏渲染 PNG |
| 多帧截图 | `--headless --screenshot=out.png --screenshot-frames=5` | 渲染 N 帧后截图 |
| 浏览器 ref | `PxTest\Pipeline\Strategy\BrowserRefStep` | Edge headless + wrapper!important 注入 |
| 对比引擎 | `PxTest\Comparison\ComparatorRegistry` | 几何+样式+稳定性+像素 四维对比 |
| 锚点对齐 | `PxTest\Pipeline\ScreenshotStep::detectColorAnchors()` | #FF00FF/#00FFFF 8×8 块三策略 |
| 归档基线 | `php apps/css-test/archive_case.php` | case 通过后冻存基线 |
| 回归检查 | `php apps/css-test/check_regression.php` | 四维度回归对比 |
| 单元测试 | `php tests/run_all.php` | 全部单元+集成+压力测试 |

### 2.2 目录结构

```
apps/css-test/
├── test_pipeline.php         PxTest 编排器（入口）
├── main.php                  exe 入口（支持 --dump-layout/--screenshot）
├── App.vue                   根模板（自动加载 test_case 的组件）
├── test_case/                所有测试用例（当前 32 个 case）
│   └── case-NNN-name/
│       ├── CaseNnnName.vue   引擎端模板
│       ├── CaseNnnName.html  浏览器参考 HTML
│       ├── ref/              参考数据 + 截图（test_pipeline.php 自动生成）
│       └── baseline/         归档基线（archive_case.php 生成）
├── archive_case.php          归档工具
├── check_regression.php      回归检查
├── gen/                      SFC 编译器输出
├── bin/                      构建输出（css_test.exe）
└── project.yml               构建配置

tools/PxTest/
├── Pipeline/                 Pipeline + Strategy 编排引擎
│   ├── PipelineBuilder/Orchestrator    CLI→Pipeline + 依赖拓扑
│   ├── BuildStep                      构建（哈希缓存+进程锁+孤儿清理）
│   ├── Strategy/DumpStrategy          ExeDump / MockDump 双轨
│   ├── Strategy/BrowserRefStrategy    EdgeDom / EdgeScreenshot
│   └── ScreenshotStep                 截图对比（锚点对齐+diff图+锚点校验）
├── Comparison/               对比器（Geometry/Style/Stability/Pixel/RenderNode）
├── Mock/                     测试双轨（MockPlatform/Component/EventSimulator）
├── Snapshot/                 快照管理器
├── Reporting/                报告器（Console/Markdown/JSON/TAP）
├── Baseline/                 基线归档
└── Builder/                  Fluent Builder（VNodeBuilder/RenderNodeBuilder）

tests/
├── unit/PxTest/              单元测试（11 模块）
├── integration/              集成测试（7 跨模块协作）
├── stress/                   压力测试
├── e2e/                      E2E 编排 + headless 脚本
└── run_all.php               统一运行器
```

### 2.3 关键框架文件

```
布局引擎（差异定位→修复入口）
  framework/Rendering/LayoutResolver.php             布局引擎调度入口
  framework/Rendering/Layout/BlockLayoutStrategy.php  Block 布局 + auto-height
  framework/Rendering/Layout/FlexLayoutStrategy.php   Flex 布局
  framework/Rendering/Layout/GridLayoutStrategy.php   Grid 布局
  framework/Rendering/Layout/InlineLayoutStrategy.php Inline 布局
  framework/Rendering/Layout/AbsolutePositioning.php  绝对/固定定位
  framework/Rendering/Layout/Tools/PercentResolver.php 百分比+单位解析
  framework/Rendering/CssMappings.php                 CSS → 内部属性映射
  framework/Rendering/CssValueParser.php              CSS 值解析
  framework/Core/Application.php                      serializeRenderNode 白名单

渲染系统
  framework/Rendering/GdiRenderContext.php            GDI 绘制实现
  framework/Rendering/SkiaRenderContext.php           Skia 绘制实现
  cpp/skia_render.cc                                  C++ 原生渲染层
  framework/Rendering/VNode.php                       虚拟 DOM 节点
  framework/Rendering/RenderNode.php                  渲染专用节点
  framework/Rendering/VNodeRenderer.php               渲染树遍历 + clip
  framework/Rendering/RenderTreeManager.php           VNode→RenderNode 转换
```

---

## 三、完整迭代流程（4 Phases）

### Phase 0：创建/准备测试用例

```bash
# 创建新 case 目录
apps/css-test/test_case/case-NNN-name/
├── CaseNnnName.vue         # 引擎端模板
├── CaseNnnName.html        # 浏览器参考 HTML
├── ref/                    # 参考数据（test_pipeline.php 自动生成）
└── baseline/               # 归档基线（archive_case.php 生成）

# 编译 + 构建
.\build.bat css-test
```

**Vue ↔ HTML 同步规则**：
- `.vue` `<template>` 与 `.html` `<body>` 内容一致（相同结构 + inline style）
- 基础样式：`* { margin:0; padding:0; box-sizing:border-box; }`
- 容器宽度建议 720px，居中（`margin:0 auto`），卡片式设计
- `.vue` 需要 `<script lang="php">class TestContent extends ReactiveComponent {}</script>`
- 关键元素加 `id` 属性（便于 ElementCompare 精确匹配）

### 锚点嵌入（截图对比必须）

每个测试 case 的最外层卡片容器嵌入颜色锚点：

```html
<div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
<div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div>
```

- TL 锚点 `top:0;left:0` → 卡片 padding-box 左上角
- BR 锚点 `bottom:0;right:0` → 卡片 padding-box 右下角
- 锚点及卡片必须在 `main.php` 定义的 `WINDOW_WIDTH × WINDOW_HEIGHT` 视口内
- `position:relative` 加到卡片容器的 inline style
- 对齐算法：`detectColorAnchors()` O(n) 扫描 8×8 色块 → 计算偏移 dx/dy

### Phase 1：运行测试

```bash
# 全量测试（跳过截图加速）
php apps/css-test/test_pipeline.php --skip-screenshot

# 单 case
php apps/css-test/test_pipeline.php --case=case-007-border-styles

# 强制重编 + 测试
php apps/css-test/test_pipeline.php --case=case-xxx --force-build

# Markdown 报告
php apps/css-test/test_pipeline.php --format=md
```

**六步流程（Step 0→I）**：

| 步骤 | 内容 | 产出 |
|------|------|------|
| **Build** | 哈希缓存 + 进程锁(.build.lock) + proc_open + Ctrl+C | `css_test.exe` |
| **D** | 布局导出（ExeDump/MockDump）+ REF_STALE 校验 | `ref/engine_layout.json` |
| **E** | 多帧稳定性（5 帧逐节点 x/y/w/h 对比） | STABILITY 标记 |
| **G** | 浏览器参考（wrapper!important 注入 + Edge headless） | `ref/browser_ref_*.png` |
| **H** | 逐元素对比（几何+样式+稳定性 + Phase F 溢出检测） | PASS/FAIL/SKIP |
| **I** | 截图对比（三层锚点对齐 + GD 像素 diff + diff 图 + 锚点校验） | 差异 % |

**自动告警**：REF_STALE · DOC_WARN（FAIL 不在问题清单）· 锚点可见性

### Phase 2：构建 + 运行测试（高级选项）

```bash
php apps/css-test/test_pipeline.php                 # 全量
php apps/css-test/test_pipeline.php --case=case-xxx # 单 case
php apps/css-test/test_pipeline.php --skip-build    # 跳过编译
php apps/css-test/test_pipeline.php --skip-browser-ref # 跳过浏览器对比
php apps/css-test/test_pipeline.php --update-baseline   # 更新参考数据
```

### Phase 3：分析测试报告

#### 3.1 差异分类与排查

| 差异类型 | 典型原因 | 修复位置 |
|----------|---------|---------|
| **假阳性** | 浏览器 ref wrapper 引入非标准基线 | `BrowserRefStep.buildCssTestWrapper()` |
| **位置偏差 (Δx/Δy > 1px)** | line-height 缺失/margin 折叠/padding 未计算 | `BlockLayoutStrategy` / `FlexLayoutStrategy` / `AbsolutePositioning` |
| **容器 auto-height 偏差** | auto-height 未减 padding ，或未排除 absolute/fixed 子节点 | `BlockLayoutStrategy.resolveBlockLayout()` |
| **Grid/Flex 子元素 w=0** | GridLayoutStrategy 未设 style['width'] / BlockLayout 重解释 | `GridLayoutStrategy` / `BlockLayoutStrategy` |
| **颜色不匹配** | GDI 颜色格式转换有误 | `CssMappings` |
| **属性引擎缺失** | serializeRenderNode 白名单未添加 / CssMappings 未映射 | `Application.php` / `CssMappings` |
| **尺寸偏差 (w/h)** | 盒模型假设不一致 / 百分比解析 | `PercentResolver` / `buildCssTestWrapper()` |
| **STABILITY 问题** | 多帧间坐标或尺寸不稳定（auto-height 正反馈） | `BlockLayoutStrategy` auto-height 排除 absolute/fixed |
| **截图差异 > 5%** | 字体渲染 / 抗锯齿 / 颜色差异 / 布局偏移 | 联合 JSON 对比+浏览器元素对比定位 |

#### 3.2 根因定位决策树

```
报告显示 FAIL
├─ 所有元素系统性偏移（同方向同量级）?
│   └─ 视口不一致 → 检查 buildCssTestWrapper() 的 --window-size
├─ 元素位置/尺寸偏差但样式值正确?
│   ├─ 容器 auto-height 偏差 → BlockLayoutStrategy
│   ├─ Grid/Flex 子元素宽度不对 → GridLayoutStrategy / FlexLayoutStrategy
│   ├─ 文本高度偏差 → PercentResolver line-height
│   └─ 绝对定位偏差 → AbsolutePositioning
├─ 样式值不匹配?
│   ├─ 字体/颜色差异 → CssMappings / Skia/GDI 渲染
│   └─ 边框/间距差异 → 盒模型检查
├─ 布局正确但渲染效果不对?
│   └─ VNodeRenderer / GdiRenderContext / SkiaRenderContext
├─ 引擎无此属性（浏览器有）?
│   └─ RenderNodeSerializer 白名单缺失 / CssMappings 未映射
└─ STABILITY 标记?
    └─ auto-height + absolute 子节点正反馈 → BlockLayoutStrategy
```

### Phase 4：修复框架/应用缺陷

#### 治本三原则

1. **框架层修复** — 不在 App.vue 打补丁
2. **通用合规** — 修复符合 CSS 规范，不特化
3. **先覆盖后优化** — 先通过测试，再考虑性能

#### 回归验证

```bash
php apps/css-test/test_pipeline.php --case=case-xxx   # 单 case 验证
php apps/css-test/test_pipeline.php --skip-screenshot  # 全量回归
php apps/css-test/check_regression.php                 # 基线回归
```

**回归标准**：已有归档 case 不应新增 FAIL · 截图差异不应从 <5% 上升到 >10% · 不应新增 STABILITY

#### 归档

```bash
php apps/css-test/archive_case.php case-xxx     # 归档
php apps/css-test/archive_case.php --list       # 查看状态
```

#### 提交

分类提交：`fix(framework):` / `fix(css-test):` / `docs:` / `chore:` 分开 commit

**提交前必查**：
- [ ] `docs/01-问题清单.md` 已更新
- [ ] 已归档 case 的 `baseline/` 已加入提交
- [ ] `baseline_registry.json` 已随归档更新
- [ ] 全量测试通过：`php apps/css-test/test_pipeline.php`
- [ ] 基线回归通过：`php apps/css-test/check_regression.php`

---

## 四、文档体系

```
apps/css-test/docs/
├── 00-索引.md               ← 文档地图
├── 01-问题清单.md            ← 唯一 Bug 台账
└── 02-测试报告/
    └── 最新报告.md           ← test_pipeline.php 自动生成

项目根 docs/：
├── CSS标准对齐迭代工作流——AI驱动.md   ← 本文（完整版工作流）
└── css-test-iteration-workflow.md    ← 精简版（日常速查）
```

---

## 五、截图对齐机制

### 5.1 颜色锚点对齐（推荐，优先级最高）

- TL 锚点 `#FF00FF` 8×8，位于卡片 padding-box 左上角
- BR 锚点 `#00FFFF` 8×8，位于卡片 padding-box 右下角
- `detectColorAnchors()` O(n) 扫描 → 验证 8×8 块 85%+ 像素匹配
- 优势：无需模板预提取、像素级精确、不受内容变化影响

### 5.2 窗口尺寸一致性

| 场景 | 窗口尺寸 | 注意事项 |
|------|---------|---------|
| Px 应用 | `main.php` WINDOW_WIDTH/WINDOW_HEIGHT | 默认 1600×800 |
| 浏览器 ref | Edge headless `--window-size=1600,800` | headless 精确控制视口 |

### 5.3 对齐流程优先级

```
comparePixels() 执行：
  1. detectColorAnchors() → 颜色锚点（最快最准）
  2. autoDetectContentBounds() → 自动内容边界（回退）
  3. fallback → 返回 100% 差异
```

---

## 六、对比维度

`ComparatorRegistry::default()` 当前覆盖：

| 类别 | 属性 |
|------|------|
| 排版 | fontSize, fg(color), bg(始终导出：-1=透明), bold, textAlign, lineHeight, whiteSpace, wordBreak, fontStyle, textDecoration |
| 内边距 | paddingTop/Left/Right/Bottom |
| 外边距 | marginTop/Left/Right/Bottom |
| 边框 | borderWidth, borderColor, borderRadius, borderTop/Right/Bottom/Left Width+Color |
| 阴影/轮廓 | boxShadow, outline |
| 布局 | display, flexDirection, flexWrap, gap, alignItems, justifyContent, boxSizing |

> 70+ 样式属性已覆盖。`bg` 始终导出：未显式设置 → `-1`（透明），确保无背景元素也参与颜色对比。

---

## 七、已知 AOT 编译陷阱

| 模式 | 问题 | 修复 |
|------|------|------|
| `$var ?? expr` | AOT 不支持 `??` | `$var !== null ? $var : expr` |
| `$arr['key'] ?? default` | 部分 AOT 版本不支持 | `isset($arr['key']) ? $arr['key'] : default` |
| 动态属性访问 | AOT 编译禁止 | 改为固定属性名 |

---

## 八、命令速查

```bash
# 测试
php apps/css-test/test_pipeline.php --skip-screenshot
php apps/css-test/test_pipeline.php --case=case-xxx --force-build
php apps/css-test/test_pipeline.php --format=md

# 单元/集成/压力
php tests/run_all.php

# 归档与回归
php apps/css-test/archive_case.php case-xxx
php apps/css-test/archive_case.php --list
php apps/css-test/check_regression.php

# 构建
.\build.bat css-test

# 手动 exe 操作（调试用）
apps\css-test\bin\css_test.exe --case=case-001 --dump-layout
apps\css-test\bin\css_test.exe --case=case-029 --dump-layout-after-frames=5
apps\css-test\bin\css_test.exe --headless --screenshot=out.png
apps\css-test\bin\css_test.exe --headless --screenshot=out.png --screenshot-frames=5

# SFC 编译
php sfc-compiler.php apps/css-test/App.vue

# Edge headless 手动截图
msedge --headless --disable-gpu --window-size=1600,800 --screenshot=ref/browser_ref.png "file:///D:/Px/apps/css-test/test_case/case-NNN/wrapper.html"

# 回归（JSON 格式）
php apps/css-test/check_regression.php --json
```

---

## 九、技术参考

### CSS 规范

- [CSS Positioned Layout Level 3](https://www.w3.org/TR/css-position-3/) — 绝对/固定定位
- [CSS Flexible Box Layout Level 1](https://www.w3.org/TR/css-flexbox-1/) — Flex 布局
- [CSS Grid Layout Level 1](https://www.w3.org/TR/css-grid-1/) — Grid 布局
- [CSS Box Model Level 3](https://www.w3.org/TR/css-box-3/) — 盒模型 padding/margin
- [CSS Values and Units Level 3](https://www.w3.org/TR/css-values-3/) — 百分比/calc/单位
- [CSS Overflow Module Level 3](https://www.w3.org/TR/css-overflow-3/) — 溢出/滚动
- [CSS Cascading and Inheritance Level 4](https://www.w3.org/TR/css-cascade-4/) — 层叠/继承

### 诊断技巧

```php
// 在框架代码中加日志（修完后删除）
error_log('[DIAG] enter resolveFlexLayout type=' . $node->type . ' w=' . ($style['width'] ?? 0));

// 直接检查 engine_layout.json 确认引擎坐标和样式
// 位于 test_case/case-NNN/ref/engine_layout.json

// 在框架中添加调试输出
if ($label === '目标元素') {
    file_put_contents('debug_element.log', print_r(['browser' => $bEl, 'engine' => $eEl], true));
}
```

---

## 十、多帧稳定性验证

**背景**：auto-height + absolute 子节点的正反馈循环 bug，证明了 Frame 依赖 bug 是 `--dump-layout` 的死角。

**PxTest 中的多帧验证**（内置在 `test_pipeline.php` 的 Step E 中）：
- `--dump-layout-after-frames=5` 默认执行
- `MultiFrameStep` 自动比较 Frame 1 与 Frame N 的布局 JSON，逐节点对比 x/y/w/h
- 任何节点跨帧变化（Δx/Δy/Δw/Δh ≠ 0）标记为 **STABILITY** 问题计入失败

**具体场景**（必须关注多帧稳定性）：
- 任何含 `auto-height` 的 block 容器 + absolute/fixed 子节点
- 任何含 `padding` 的 auto-height 容器
- 任何调整了子节点 y 坐标的布局策略（flex/grid 重定位后）

---

## 十一、test_case 创建模板

### Vue 模板（引擎端）

```php
// test_case/case-NNN-name/CaseNnnName.vue
<template>
  <div class="card" style="width:720px;margin:20px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);position:relative">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    <div class="header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      Test Title
    </div>
    <!-- 测试内容 →
    <div class="footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-NNN: Description
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
```

### HTML 模板（浏览器参考）

```html
<!-- test_case/case-NNN-name/CaseNnnName.html →
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Test Title</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:#f0f2f5; font-family:sans-serif; display:flex; justify-content:center; padding:20px; }
  /* 与 .vue 同步的样式 */
</style></head><body>
  <!-- 与 .vue <template> 一致的内容 →
</body></html>
```

> **样本偏差警示**：.vue 与 .html 结构必须严格一致！
>
> 浏览器参考数据从 `.html` 生成，引擎布局快照从 `.vue` 编译的 exe 生成。
> 若两者 DOM 结构不一致，对比将产生**全用例一致的 dx/dw 系统性偏差**。
>
> **历史案例**：case-001/case-002 的 `.html` 有 `<div class="sandbox" style="padding:20px">` 包装层，
> 而 `.vue` 直接以根元素开始，导致引擎缺少 20px padding 包装 → 引擎元素宽度比浏览器窄 90px。
>
> **检查清单**（每次新建 test_case 必须核对）：
> 1. `.vue` `<template>` 根元素与 `.html` `<body>` 内第一个元素结构一致
> 2. 所有 CSS 类名和 inline style 在两者间一致
> 3. 嵌套层级（额外 wrapper 层）完全对齐
> 4. `buildCssTestWrapper()` 注入的全局 CSS（`* { margin:0; padding:0; }` 等）在引擎端有无匹配
> 5. 使用 `php apps/css-test/test_pipeline.php --case=case-NNN` 后检查 dw 是否接近 0
>
> **修复流程**：优先修改 `.vue` 对齐 `.html`（`<template>` 是源），然后重新编译并重新测试。
> 切勿仅修改 `.html` 而不更新 `.vue`，否则引擎与浏览器参考的偏差将持续存在。

---

## 十二、AI 迭代引导规则

### 12.1 迭代退出条件

AI 在运行测试→修复循环时必须检查以下条件，满足任一即停止迭代并报告：

| 条件 | 判定 | 动作 |
|------|------|------|
| **全部 PASS** | 所有 case 的 D/E/G/H/I 步骤均通过 | 运行 `check_regression.php` → 归档 → 提交 |
| **FAIL 收敛** | 连续 2 次迭代 FAIL 数不变或增加 | 停止。报告"修复无效或引入新回归"，附 diff |
| **假阳性确认** | 所有 FAIL 均为 wrapper CSS 基线差异 | 修复 `buildCssTestWrapper()` → 重新生成 ref |
| **已知限制** | 所有剩余差异均为已知引擎限制（如字体差异） | 记录到问题清单 B-xxx 类，标注"已知限制" |
| **迭代上限** | 同一 case 迭代超过 5 轮 | 停止。报告阻塞点，请求人工判断 |
| **新回归** | `check_regression.php` 发现新 FAIL | 回滚本次修复，先修复回归 |

### 12.2 浏览器参考生成规范

AI 生成浏览器参考数据时必须遵守以下规则，避免引入假阳性：

**wrapper CSS 基线**（`BrowserRefStep.buildCssTestWrapper()` 自动注入，`!important` 最高优先级）：

```css
*,*::before,*::after { margin:0!important; padding:0!important; box-sizing:border-box!important; }
html,body { width:1600px!important; height:800px!important; overflow:hidden!important;
  font-family:"Segoe UI","Noto Sans SC",sans-serif!important;
  font-size:16px!important; line-height:1.2!important; background:#fff!important; color:#000!important; }
```

**窗口尺寸匹配**：`main.php` 的 `WINDOW_WIDTH × WINDOW_HEIGHT` 必须与 Edge headless `--window-size` 一致。

**生成后验证**：
1. 确认 ref JSON 包含 `font-family: 'Noto Sans SC'`
2. 确认 `font-size: 16px`
3. 确认 body `background-color` 不是透明/none
4. 检查 `.vue` 与 `.html` 的第一个元素结构一致（无额外 wrapper 层）

### 12.3 典型修复案例

以下是常见差异类型的诊断路径和修复位置，供 AI 参考：

| 症状 | 诊断 | 修复文件 | 关键代码 |
|------|------|---------|---------|
| Flex 子元素宽度=0，parent=null 时百分比失效 | 根容器无 parent，`PercentResolver` 返回 0 | `PercentResolver.php` | 无 parent 时退回 content-box 宽度 |
| 绝对定位 `bottom:0;right:0` 锚点位置错误 | `AbsolutePositioning` 未正确处理 bottom/right | `AbsolutePositioning.php` | 计算 y = parentH - nodeH |
| auto-height 容器 Frame 2+ 高度漂移 | absolute 子节点被计入 auto-height 导致正反馈 | `BlockLayoutStrategy.php` | `resolveBlockLayout` 排除 absolute/fixed 子节点 |
| 所有元素宽度系统性偏窄 90px | `.html` 有 wrapper padding 层而 `.vue` 无 | 修改 `.vue` 对齐 `.html` | 统一根元素结构 |
| Skia 渲染的细矩形/分隔线膨胀 1-2px | Skia 抗锯齿导致 fillRect 边界外溢 | `skia_render.cc` | 禁用细矩形的抗锯齿或使用 integral 坐标 |
| CSS 属性有值但 compareElement 报告缺失 | `RenderNodeSerializer` 白名单未包含该属性 | `Application.php` | 在 `serializeRenderNode` 的 `styleKeys` 中新增 |
| margin:auto 居中偏移 | 盒宽度计算未包含 padding+border | `BlockLayoutStrategy.php` | `availableSpace = parentW - nodeW - padding - border` |
| 引擎渲染黑色背景但 JSON 报告无背景色 | 渲染层默认填充黑色，布局层未导出 | `VNodeRenderer.php` | 无 `background-color` 时显式填充 `#fff` |

### 12.4 修复后自检清单

AI 每次修复框架代码后必须执行：

```bash
# 1. 验证受影响的 case
php apps/css-test/test_pipeline.php --case=case-xxx

# 2. 全量回归（确保未引入新退化）
php apps/css-test/test_pipeline.php --skip-screenshot

# 3. 基线回归
php apps/css-test/check_regression.php

# 4. 更新问题清单
# 编辑 apps/css-test/docs/01-问题清单.md
```

**自检问题**：
- [ ] 修复在框架层还是应用层？必须框架层修复
- [ ] 修复是否符合 CSS 规范？不可针对特定测试特化
- [ ] 已有归档 case 是否新增 FAIL？
- [ ] 截图差异是否从 <5% 上升到 >10%？
- [ ] 问题清单是否已更新？
- [ ] 分类提交：`fix(framework):` / `fix(css-test):` / `docs:` / `chore:`
