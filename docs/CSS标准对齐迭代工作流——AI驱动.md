# CSS 标准对齐迭代工作流 — AI 驱动

> **目标**：给定任意一个或多个 HTML 样例，通过分治测试 + 自动化迭代，逐步使 Px 框架渲染结果与浏览器（Edge Chromium）像素级一致。
>
> **核心思想**：数据驱动差异分析 → 定位根因 → 区分框架问题与应用问题 → 治本修复 → 回归验证 — 形成持续迭代闭环。

---

## 一、核心原则

| 原则 | 说明 |
|------|------|
| **先验证后修复** | 跑完整测试链，让数据告诉你差异在哪，不靠猜测 |
| **治本不治标** | 框架层的 bug 在框架层修复，不在 App.vue 打补丁 |
| **通用合规** | 修复应符合 CSS 标准，不针对特定测试特化 |
| **分治** | 一次只聚焦一个 CSS 特性或页面区域，逐个击破 |
| **回归防护** | 每次修复后必须验证原有测试不退化 |
| **排假阳** | 差异出现时，先排除浏览器 wrapper HTML 本身引入的基线差异 |
| **工具共享优先** | 所有工具优先使用已有的共享库（`shared_test_lib.php` 等）；增强修复也是优先应用到共享工具，再使用它们；实在需要个案工具才编写临时工具，用完即删 |
| **持续追踪** | 每个项目独立维护 Bug 台账，记录所有已知缺陷及其修复状态，避免同类问题反复出现 |

**决策优先级**：差异出现时，先判断：
1. **浏览器 ref 生成 wrapper 引入了基线差异**（box-sizing/line-height/reset 不一致） → 修复 ref 生成工具，重新生成参考数据
2. **框架不符合 CSS 标准** → 改框架 + 加测试
3. **框架符合 CSS 标准，应用层用法错** → 改应用
4. **框架尚未实现该特性** → 记录清单，并实现或者增强，并分析同类下的特性支持完善度，如需完善，就完善！

---

## 二、工具链速查

### 2.1 核心工具

| 工具 | 路径 | 用途 |
|------|------|------|
| SFC 编译器 | `php sfc-compiler.php apps/<project>` | .vue → gen/\*.php |
| 构建脚本 | `.\build.bat <project>` | PHP → .exe |
| 布局导出 | `bin/<project>.exe --dump-layout` | → engine_layout.json |
| 多 Level ref | `php tools\generate_browser_refs.php` | css-test 多 Level 浏览器参考 JSON |
| 单项目 ref | `php tools\generate_project_ref.php <project>` | 单个项目浏览器参考 JSON |
| 布局 JS 导出器 | `tools/dump_layout.js` | 注入浏览器 HTML，从 DOM 提取布局 JSON |
| 共享对比库 | `tools/shared_test_lib.php` | flattenEngineTree, compareElement/compreElementEnhanced, alignImages, compareScreenshots |
| 截图工具 | `tools/capture_screenshot.ps1` | 应用/浏览器窗口截图 |
| 锚点对齐 | `tools/shared_test_lib.php::alignImages()` | 颜色锚点/模板匹配/自动检测 三策略 |

### 2.2 关键文件

```
框架布局引擎（差异定位→修复入口）
  framework/Rendering/LayoutResolver.php             布局引擎调度入口
  framework/Rendering/Layout/BlockLayoutStrategy.php  Block 布局
  framework/Rendering/Layout/FlexLayoutStrategy.php   Flex 布局
  framework/Rendering/Layout/GridLayoutStrategy.php   Grid 布局
  framework/Rendering/Layout/AbsolutePositioning.php  绝对/固定定位
  framework/Rendering/Layout/PercentResolver.php      百分比+单位解析
  framework/Rendering/CssMappings.php                 CSS → 内部属性映射
  framework/Core/Application.php                      serializeRenderNode 白名单

渲染系统
  framework/Rendering/GdiRenderContext.php            GDI 绘制实现
  framework/Rendering/SkiaRenderContext.php           Skia 绘制实现
  framework/Rendering/VNodeRenderer.php              渲染树遍历+clip
  framework/Rendering/RenderTreeManager.php           VNode→RenderNode 转换

测试项目
  apps/<project>/App.vue                              主模板
  apps/<project>/baseline.html                        浏览器参考 HTML
  apps/<project>/main.php                             入口（支持 --dump-layout）
  apps/<project>/auto_test.php                        自动化测试脚本
  apps/<project>/ref/browser_ref_*.json               浏览器标准数据
  apps/<project>/test_cases/level_*.html              分治测试用例
  apps/<project>/engine_layout.json                   引擎布局快照
  apps/<project>/base_line_pic.png                    浏览器基线截图
  tools/generate_project_ref.php                      单项目 ref 生成（含 normalize.css wrapper）
  tools/generate_browser_refs.php                     css-test 多 Level ref 生成
  tools/dump_layout.js                                浏览器端布局数据提取脚本
  tools/shared_test_lib.php                           共享对比函数库
```

---

## 三、完整迭代流程（4 Phases）

### Phase 0：项目初始化

```bash
# 1. 创建项目目录结构
apps/<project>/
├── App.vue              # Vue 3 模板（从 HTML 翻译）
├── baseline.html        # 浏览器参考 HTML
├── main.php             # 入口（必须支持 --dump-layout）
├── project.yml          # 构建配置
├── components/          # 子组件（可选）
├── test_cases/          # 分治用例（可选）
│   └── level_0.html     # 浏览器参考数据
├── ref/                 # 浏览器 ref 数据（生成）
├── gen/                 # SFC 编译器输出（自动）
├── bin/                 # 构建输出（自动）
└── test_log/            # 测试报告（自动）

# 2. SFC 编译
php sfc-compiler.php apps/<project>

# 3. 构建
.\build.bat <project>

# 4. 验证 --dump-layout 可用
cd apps/<project>/bin
.\<project>.exe --dump-layout
```

**HTML → App.vue 转换规则**：
- **内联样式项目**：`<body>` 内容直接嵌入 `<template>`，保持所有 `style` 属性不变
- **CSS 样式表项目**：`<style>` 块内容嵌入 App.vue 的 `<style>` 节，`<body>` 内容嵌入 `<template>`
- 外层容器建议：`<div style="width:1800px;height:1200px;display:flex;align-items:center;justify-content:center">`
- 如需截图对齐，在内容区 `position:relative` 容器内嵌入锚点色块：
  ```html
  <!-- __PX_ANCHOR_TL__ --><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;"></div>
  <!-- __PX_ANCHOR_BR__ --><div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;"></div>
  ```

---

### Phase 1：添加/更新测试用例

**步骤**：

| # | 操作 | 命令 |
|---|------|------|
| 1.1 | 从目标 HTML 提取独立 CSS 特性片段，编写 test_cases/level_X.html | 手写 |
| 1.2 | 编写/更新 components/LevelX_\*.vue（与 HTML 内容一致） | 手写 |
| 1.3 | 在 App.vue 中添加新 Level 组件引用 | 手写 |
| 1.4 | 重新编译 SFC | `php sfc-compiler.php apps/<project>` |
| 1.5 | 确认 ref 生成工具的 wrapper CSS 与引擎基线一致（normalize.css + 无 box-sizing/line-height 覆盖） | 检查 buildWrapperHtml() |
| 1.6 | 确认 ref 生成工具 `--window-size` 匹配引擎 `WINDOW_WIDTH x WINDOW_HEIGHT` | 检查 runEdgeHeadless() |
| 1.7 | 重新生成浏览器参考 | `php tools\generate_browser_refs.php`（多 Level）或 `php tools\generate_project_ref.php <project>`（单项目） |

**HTML 测试用例编写规范**：
- 每个 Level 独立一个 HTML，只测其对应 CSS 特性范围
- 使用内联 style（不依赖外部 CSS），确保确定性
- 容器宽度默认 1100px，padding:32px
- 基础样式由 normalize.css 统一处理（替换手写 `* { box-sizing: border-box; ... }`），引擎使用 CSS 默认 content-box
- 测试文件命名：`test_cases/level_{0-7}.html`

---

### Phase 2：构建 + 运行测试

```bash
# 构建 exe
cd F:\work\Px
.\build.bat <project>

# 运行完整自动化测试（包含 --dump-layout + JSON 对比 + 截图对比 + 报告）
php apps\<project>\auto_test.php
```

**auto_test.php 标准执行流程**：

```
Step 1: .\build.bat <project> → bin/<project>.exe
Step 2: <project>.exe --dump-layout → engine_layout.json
Step 3: 加载 ref/browser_ref_level_{0-7}.json（或 browser_ref_level_0.json）
Step 4: 三阶段逐元素对比（详见下方说明）
Step 5: 对齐图片 → captureAppScreenshot → compareScreenshots 像素级对比
Step 6: 生成 test_log/test_report_YYYYmmdd_HHMMSS.md
        更新 test_log/latest_report.md
```

**⚠️ `--dump-layout` 的重要局限**：`--dump-layout` 是单帧模式——在 main.php 中执行 `$app->render()` 后立即 dump 并 exit(0)，**不进入事件循环**。因此它只能暴露 Frame 1 的布局问题，**无法检测 Frame 依赖型 bug**（如 auto-height + absolute 的正反馈循环，在 Frame 2+ 才触发）。

**补偿措施**：
- Phase 1 通过后，对涉及 auto-height 和 absolute 定位的测试，建议额外验证：先 `--dump-layout` 一次，再运行 exe 截图对比
- 单元测试中增加跨帧稳定性断言（同一棵 RenderNode 树 resolve 两次 → 结果一致）
- 参见 §九「多帧布局稳定性验证」

**三阶段逐元素对比**（music-player 实践验证）：

| 阶段 | 匹配策略 | 对比内容 | 捕获差异类型 |
|------|---------|---------|------------|
| A: 文本元素 | `flattenEngineTree` + `indexAllBrowserElements` 按标签/内容匹配 | w/h/x/y + 样式（fontSize, fg, bg, bold, textAlign 等） | 文本位置/尺寸/颜色 |
| B: 容器元素 | 按 depth-1 层级匹配父容器（包装器） | w/h（skipPos，因绝对坐标可能因视口偏移） | 容器尺寸（auto-height 等） |
| C: 锚点验证 | `compareElementEnhanced` 锚点色块定位，用 `$checks=['x','y','w','h']` 精确比较 | 锚点 relX/relY（相对父容器 padding box） | 内容区整体偏移 |

**对比维度**（shared_test_lib.php defaultChecks）：

| 类别 | 属性 |
|------|------|
| 排版 | fontSize, fg(color), bg, bold, textAlign, lineHeight, whiteSpace |
| 内边距 | paddingTop/Left/Right/Bottom |
| 外边距 | marginTop/Left/Right/Bottom |
| 边框 | borderWidth, borderColor, borderLeftWidth, borderLeftColor, borderRadius |
| 布局 | display, flexDirection, flexWrap, gap, alignItems, justifyContent, boxSizing |

---

### Phase 3：分析测试报告

#### 3.1 报告结构

报告包含三大部分：

**① 逐元素 JSON 对比** — `compareElement()` 逐项输出
```
| Level | 文本 | 相对位置 | 样式差异 | 状态 |
| Level-4 | 左侧卡片 | w(e:0|b:510) h(e:90|b:90) | borderLeftColor: engine=#30363D browser=#ef4444 | ❌ |
```

**② Level 汇总**
```
| Level | 通过 | 失败 | 跳过 | 总数 | 通过率 |
| Level-0 | 42 | 0 | 0 | 42 | 100% |
```

**③ 截图对比**
```
| 像素差异 | 2.3% | 1234/54321 差异像素 | ✅ |
```

#### 3.2 差异分类与排查

| 差异类型 | 典型原因 | 修复位置 |
|----------|---------|---------|
| **假阳性** | 浏览器 ref 生成 wrapper 引入非标准基线（box-sizing/line-height/reset） | `tools/generate_project_ref.php` / `tools/generate_browser_refs.php` → buildWrapperHtml() |
| **位置偏差 (Δx/Δy > 5px)** | line-height 缺失/margin 折叠/padding 未计算 | `BlockLayoutStrategy` / `FlexLayoutStrategy` |
| **容器 auto-height 偏差** | auto-height 计算未减去 paddingTop（CSS §10.6.3），或未排除 absolute/fixed 子节点导致多帧膨胀 | `BlockLayoutStrategy` → resolveBlockLayout() auto-height 计算 |
| **Grid/Flex 子元素 w=0** | GridLayoutStrategy 未设 style['width'] / BlockLayout 重解释 | `GridLayoutStrategy` / `BlockLayoutStrategy` |
| **颜色不匹配** | GDI 颜色格式转换有误 / border-left 简写默认颜色 | `CssMappings` |
| **属性引擎缺失** | serializeRenderNode 白名单未添加 / CssMappings 未映射 | `Application.php` / `CssMappings` |
| **尺寸偏差 (w/h)** | 盒模型假设不一致 / 百分比解析 / 视口不匹配 | `PercentResolver` / `generate_*_ref.php` 检查 window-size |
| **截图差异 > 5%** | 字体渲染 / 抗锯齿 / 颜色差异 / 布局偏移 | 联合 JSON 对比定位 |
| **截图中锚点找不到** | 窗口尺寸不对 / 色块被遮挡 / 偏移过大 / 视口与 ref 生成不一致 | 检查 WINDOW_WIDTH/HEIGHT 与 `--window-size` 一致 |

#### 3.3 决策树

```
差异出现
├─ JSON 对比显示引擎与浏览器 w/h/x/y 系统性偏移（所有元素同方向偏移相同量）？
│   ├─ 是 → 视口不一致 → 检查 generate_*_ref.php 的 `--window-size` 是否匹配引擎 `WINDOW_WIDTH/HEIGHT`
│   └─ 否 → 继续
│
├─ JSON 对比显示引擎与浏览器 w/h 偏差（仅特定元素，非系统性）？
│   ├─ 容器 auto-height 偏差 + paddingTop？
│   │   ├─ 是 → `BlockLayoutStrategy` auto-height 未减 paddingTop
│   │   └─ 否 → 其他布局差异，进入 Phase 4
│   └─ 子元素宽度未被父容器 padding 约束？
│       ├─ 父容器 w 计算不含 padding？→ `BlockLayoutStrategy`
│       └─ 父容器 w 正确但子元素 w 不对？→ 继承/百分比解析
│
├─ 引擎 JSON 位置/尺寸正确，但样式值不匹配（颜色/字号/行高）？
│   ├─ 浏览器 wrapper 引入了非标准 line-height/reset？
│   │   ├─ 是 → **假阳性**：修复 generate_*_ref.php buildWrapperHtml() + 重新生成 ref
│   │   └─ 否 → 框架 bug，进入 Phase 4
│   └─ 颜色格式差异？→ `CssMappings` GDI 颜色格式转换
│
├─ 引擎 JSON 坐标/尺寸/样式与浏览器 ref 不一致（非上述情况）？
│   ├─ 是 → 框架布局/样式 bug → 进入 Phase 4
│   └─ 否 → 截图仍有差异？
│       ├─ 是 → 渲染效果差异（抗锯齿/字体/颜色格式）
│       │   ├─ 框架 RenderContext → 改框架
│       │   └─ baseline.html 样式不对 → 改应用
│       └─ 否 → ✅ 通过
│
├─ 引擎无此属性（浏览器有）？
│   ├─ 框架尚未实现 → 记录清单，并实现或者增强，并分析同类下的特性支持完善度，如需完善，就完善！
│   └─ 框架已实现但未导出 → serializeRenderNode 白名单
│
└─ 引擎失败项 > 5%？
    └─ 框架 bug，进入 Phase 4
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
  │   └─ framework/Rendering/Layout/AbsolutePositioning.php
  │     - padding box 边界计算公式
  │     - positioningAncestor 缓存失效
  │     - two-pass 容器 auto-height + padding
  │
  └─ 百分比/相对单位不生效
      └─ framework/Rendering/Layout/Tools/PercentResolver.php
        - parentSize=0 fallback 逻辑
        - calc() 表达式解析
        - em/rem/vw/vh 单位

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
  └─ clip/overflow 处理
      └─ framework/Rendering/VNodeRenderer.php
```

#### 4.2 新增 CSS 属性 Checklist

以新增 `text-align` 为例：

- [ ] `CssMappings.php` — 添加 `'text-align' => 'textAlign'` 映射
- [ ] `CssMappings.php` — 如属性有默认值，设 `'default' => 'left'`
- [ ] `Application.php serializeRenderNode` — `$styleKeys` 中添加 `'textAlign'`
- [ ] `auto_test.php` — `$checks` 中添加 `['textAlign', 'text-align', 'string', 'textAlign']`
- [ ] `shared_test_lib.php` — `defaultChecks()` 中添加对应项
- [ ] `test_cases/level_X.html` — 添加包含 text-align 的测试片段
- [ ] 对应的 `.vue` 组件 — 与 HTML 同步
- [ ] 重新生成 ref：`php tools\generate_browser_refs.php`
- [ ] 构建测试：`.\build.bat <project> && php apps\<project>\auto_test.php`

#### 4.3 修复准则（三原则）

1. **治本不治标** — 在框架层修复，不在应用层 App.vue 加 workaround
2. **通用合规** — 修复应符合 CSS 标准，不针对特定测试用例特化。参考 [CSS 规范](https://www.w3.org/Style/CSS/) 后实现，不要猜测
3. **先覆盖后优化** — 先通过测试，再考虑性能。一次修复一个差异

#### 4.4 应用层修复

当确认框架已符合 CSS 标准（通过 JSON 对比 + 查阅规范验证），但应用层渲染效果仍有差异时：

- 修改 `apps/<project>/App.vue` 中的模板/样式
- 修改 `apps/<project>/baseline.html` 与之同步
- 重启迭代验证

#### 4.5 Bug 追踪与状态维护

**每个项目必须独立维护 Bug 台账**，作为测试报告的补充，持续追踪所有已知问题。

#### Bug 台账格式（建议）

每个项目的 `test_log/bug_tracker.md` 中维护：

```markdown
# <项目名> Bug 追踪台账

| # | 发现日期 | 问题描述 | 分类 | 状态 | 根因文件 | 修复提交 | 测试增强 | 关联反思 |
|---|---------|---------|------|------|---------|---------|---------|---------|
| 1 | 2026-06-09 | auto-height + absolute 子节点正反馈循环 | 框架 Bug | ✅ 已修复 | BlockLayoutStrategy.php | abc1234 | LayoutResolverTest 新增多帧稳定性测试 | §九 反思清单 #1 |
| 2 | 2026-06-11 | ref wrapper CSS box-sizing 导致假阳性 | 工具 Bug | ✅ 已修复 | generate_project_ref.php | def5678 | css-test 通过率+15% | §九 反思清单 #4 |
| 3 | 2026-06-11 | emoji ⏮ 高度与浏览器偏差 18px | 已知限制 | 🟡 待处理 | GdiRenderContext | — | — | 引擎限制 |
```

#### 分类与状态定义

| 字段 | 可选值 |
|------|--------|
| **分类** | `框架 Bug` / `工具 Bug` / `测试 Bug` / `应用层问题` / `已知限制` / `待确认` |
| **状态** | `🟡 待处理` / `🟢 排查中` / `✅ 已修复` / `❌ 无法修复` / `📋 待定` |

#### 维护规范

1. **发现即记录** — 无论通过何种方式发现（测试失败、截图对比、人工 Review），立即新增台账条目
2. **修复后更新** — 每次修复后更新状态、填写修复提交、记录新增的测试用例
3. **反思联动** — 每次反思（§九）后，检查是否需要新增台账条目或更新现有条目
4. **报告关联** — 在测试报告（Phase 3 产出）末尾追加 Bug 台账摘要，保持全局可见
5. **定期审查** — 每个迭代周期结束时审查台账中的 "待处理" 和 "已知限制"，判断优先级

**典型应用层问题**：
- 盒模型假设不一致（引擎用 content-box，浏览器 wrapper 不应设 border-box）
- 浏览器 ref wrapper CSS 的 reset 与引擎基线不匹配 → 统一用 normalize.css
- 浏览器 ref 生成视口 `--window-size` 与引擎 `WINDOW_WIDTH/HEIGHT` 不一致 → 需匹配
- HTML 元素嵌套层级不对导致选择器不匹配
- 字体 fallback 顺序不同

---

## 四、浏览器参考数据生成规范

生成准确、无污染的浏览器参考数据是避免"假阳性"的基础。

### 4.1 buildWrapperHtml() CSS 基线规则

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/normalize.css">  <!-- 统一基线 -->
<style>
* { margin: 0; padding: 0; }          <!-- 覆盖 normalize 中元素级 margin，匹配引擎 -->
body { background: #0d1117; color: #e6edf3; font-size: 14px; }  <!-- 按需设基础样式 -->
</style>
</head>
```

**三条铁律**：
1. **永远不要** 在 `*` 选择器中设 `box-sizing: border-box` — 引擎使用 CSS 默认 content-box
2. **永远不要** 在 `body` 中设 `line-height` 数值 — 引擎使用 `normal` (= fontSize×1.2)，normalize.css `html { line-height: 1.15 }` 作为统一基线
3. **±1px 子像素差异是正常范围** — 浏览器和引擎舍入方式不同，`compareElementEnhanced` 默认 `posTol=1, sizeTol=1` 即可

### 4.2 --window-size 匹配规则

| 应用 | main.php | generate_*_ref.php |
|------|---------|-------------------|
| music-player | `WINDOW_WIDTH=1800, WINDOW_HEIGHT=1200` | `--window-size=1800,1200` |
| css-test | `WINDOW_WIDTH=1280, WINDOW_HEIGHT=3000` | `--window-size=1280,3000` |

```php
// 在 runEdgeHeadless() 中，--window-size 必须与 main.php 的 WINDOW_WIDTH/HEIGHT 一致
function runEdgeHeadless(...): ?string {
    $cmd = sprintf(
        '"%s" --headless --disable-gpu --window-size=%d,%d --dump-dom "%s"',
        $edgePath, WINDOW_WIDTH, WINDOW_HEIGHT, $fileUrl  // 从引擎配置读取，勿硬编码
    );
}
```

### 4.3 生成后验证清单

- [ ] 参考 JSON 中初始化 remark 记录的 viewport 尺寸与引擎一致
- [ ] 首元素的 x/y 坐标视觉上在合理范围（引擎居中布局时，应接近 `(WINDOW_WIDTH - 元素宽)/2`）
- [ ] normalize.css 是否正常加载（CDN 可达性）
- [ ] 所有元素的 w/h/x/y 不为负数（引擎和浏览器都应如此）

### 4.4 多项目共享 ref 工具

| 工具 | 适用范围 | wrapper HTML 差异 |
|------|---------|-----------------|
| `tools/generate_project_ref.php` | 单个项目（如 music-player） | 调用方灵活定制 buildWrapperHtml() |
| `tools/generate_browser_refs.php` | css-test 多 Level 批量 | 在 buildWrapperHtml() 中统一管理 |

> **原则**：每个项目的 ref 生成工具是一个独立副本，但共享相同的 normalize.css 基线策略。

> **工具共享 × 项目特化**：工具共享是通用原则（参见 §一），项目特化工具应在本节显式登记并说明使用场景。

---

### 4.5 工具共享优先实践指引

#### 工具选择决策树

```
需要编写的某个测试/诊断工具时：
  ├─ shared_test_lib.php 已有该函数？
  │   └─ 直接用，不写新的
  ├─ 共享库有类似函数但缺功能？
  │   └─ 增强共享库（加参数/新函数）→ 记入 shared_test_lib.php 头部
  ├─ 需求新但通用（其他项目也能用）？
  │   └─ 写入 shared_test_lib.php（通用工具）或独立的 tools/ 文件（公共工具）
  └─ 确实是一锤子诊断脚本？
      └─ 写临时文件 → 用完即删（如 tools/__check_*.php、tools/__dump_*.php）
      注意：即使在临时工具中也优先复用 ȿhared_test_lib.php 的函数
```

#### 共享库（shared_test_lib.php）增强规范

```php
// 文件头部更新日志格式：
// 2026-06-XX + functionName(param): 功能说明

// 新增函数的签名为求通用化，参数应包括：
// - $bElements / $eElements: 浏览器/引擎元素数组（由 produceBrowserRef + 引擎工具输出）
// - $options: 可选参数数组，包含 posTol/sizeTol/skipPos/noTextStyle 等
// - 返回统一的对比报告格式（text+container+anchor three-phase）

// 共享函数示例（已在 shared_test_lib.php 中）：
//   flattenEngineTree(array $elements, ?array $rootCoords = null): array
//   compareElementEnhanced(string $category, string $label, array $bEl, array $eEl, ...): array
//   alignImages(string $targetPath, string $refPath, string $outputPath, ...): array
```

#### 对比：共享 vs 个案

| 方面 | 共享工具（shared_test_lib.php / tools/公共文件） | 个案临时工具（tools/__*.php） |
|------|----------------------------------------------|---------------------------|
| 校验工具 | `generate_project_ref.php` / `generate_browser_refs.php` | 单个项目 auto_test.php 中的专用逻辑 |
| 对比函数 | `compareElementEnhanced()` / `alignImages()` | 项目 auto_test.php 中的简化对比写法 |
| 持久性 | 永久，持续增强 | 用完即删 |
| 增强方式 | 加参数/新函数，向后兼容 | 不需要增强，直接重写 |

---

## 五、迭代退出条件

### 单次迭代退出（满足其一）

1. **新增测试通过率 ≥95%** — 所有已匹配元素的样式+位置正确
2. **截图差异 ≤5%** — 像素级对比在阈值内
3. **剩余差异为已知框架限制** — 如 `<table>`、`backdrop-filter` 等框架尚未实现的特性
4. **所有属性均已覆盖** — 核心 CSS 属性白名单已全覆盖

### 全项目最终验收标准

- 所有 Level 通过率 ≥90%
- 半数以上 Level 通过率 ≥95%
- 截图对比 ≤5% 差异
- 无构建失败
- 无回归（各 Level 通过率不低于上次报告的 5%）

---

## 六、截图对齐机制

### 6.1 颜色锚点对齐（推荐，优先级最高）

在 HTML 内容区的 `position:relative` 容器内嵌入两个 8×8 纯色块：

```html
<!-- __PX_ANCHOR_TL__ 左上角 --><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;"></div>
<!-- __PX_ANCHOR_BR__ 右下角 --><div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;"></div>
```

- 引擎渲染：绝对定位保证锚点始终在容器 padding box 四角
- 浏览器截图：CSS 标准定位保证同样位置
- 对齐算法：`detectColorAnchors()` 用 O(n) 扫描找到两个色块 → 计算偏移 dx/dy
- 优势：无需模板预提取、像素级精确、不受内容变化影响

**⚠️ 前提条件**：窗口尺寸一致。
  - `main.php` 中 `WINDOW_WIDTH` / `WINDOW_HEIGHT` 必须与 ref 生成工具的 `--window-size` 一致
  - 例如 music-player 引擎 1800×1200 → `generate_project_ref.php` 中 `--window-size=1800,1200`
  - css-test 引擎 1280×3000 → `generate_browser_refs.php` 中 `--window-size=1280,3000`
  - 截图时调整窗口至相同 `client area` 尺寸

### 6.2 窗口尺寸一致性

为确保锚点对齐有效，浏览器截图与应用截图必须使用一致的 client area 尺寸：

| 场景 | 窗口尺寸设置 |
|------|-------------|
| Px 应用 | `main.php` 中 `WINDOW_WIDTH`, `WINDOW_HEIGHT` |
| 浏览器 ref 生成 | `generate_project_ref.php` / `generate_browser_refs.php` 的 `--window-size` 匹配引擎 |
| 浏览器基准截图 | `capture_baseline_screenshot()` 传入 `TargetWidth/TargetHeight` 匹配引擎 |
| 截图锚点 | 自动检测色块位置，计算偏移对齐 |

### 6.3 模板匹配对齐（回退方案）

当无法嵌入颜色锚点时（如测试第三方 HTML），使用 SAD 模板匹配：
1. 从基准截图中提取视觉独特的区域（如专辑封面、Logo）
2. `findAnchorInImage()` 多级分辨率搜索（4x 降采样粗搜 → 全分辨率精搜）
3. 计算偏移量对齐后像素级对比

### 6.4 全自动内容检测对齐（最终回退）

- `autoDetectContentBounds()` 基于亮度变化 + 颜色范围自动识别内容边界
- 裁剪掉均匀的边缘区域（如窗口标题栏、空白边框）
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

## 七、多项目并行迭代策略

当有多个 HTML 样例需要对齐时，按以下策略并行：

### 7.1 优先级排序

1. **CSS 特性最简单的项目先做** — 快速建立信心
2. **共享框架 bug 先修** — 一个框架 bug 修一次，多个项目受益
3. **截图对比先从简单布局开始** — Flex > Grid > Table

### 7.2 迭代顺序建议

```
第 1 轮：music-player（简单内联样式，验证流程）
第 2 轮：calculator-ng（子组件 + Grid 布局）
第 3 轮：list-test（滚动容器）
第 4 轮：bilibili（复杂集成，综合验证）
其余项目：根据 CSS 特性复杂度逐个推进
```

### 7.3 回归防护

每次框架修改后，对所有已通过的项目执行回归验证：

```bash
# 在 auto_test.php 或 run_all_tests.php 中统一调度
php tests\run_all_tests.php

# 或对指定项目单独验证
.\build.bat music-player && php apps\music-player\auto_test.php
.\build.bat calculator-ng && php apps\calculator-ng\auto_test.php
```

**回归验证标准**：
- 已有项目的通过率不应下降超过 5%
- 截图差异不应从 <5% 上升到 >10%
- 已有测试不应新增 FAIL

### 7.4 项目 Bug 台账

**每个项目独立维护 Bug 台账**（见 §三 4.5 格式规范），在多项目并行时确保：

1. **全局可见** — 每个项目在 `test_log/bug_tracker.md` 中维护台账，新加入迭代的项目首先阅读已有项目的台账，避免重复排查
2. **共享 Bug 优先修** — 当一个框架 Bug 影响多个项目时，在各自台账中标注 "共享" 标签，优先修复（§7.2 排序原则）
3. **迭代交接** — 当切换迭代项目时，将当前项目 Bug 台账摘要写入切换记录，确保上下文不丢失
4. **集成报告** — `run_all_tests.php` 执行时，输出所有项目 Bug 台账的摘要汇总（开放/已修复数量）

---

## 八、典型修复案例分析

### 案例 1：Flex 容器 parent=null 时百分比尺寸解析失败

**症状**：外层 `display:flex; width:100%; height:100%` 的容器被解析为 100×100

**根因**：`FlexLayoutStrategy` 在 `$ctx->parent === null` 时返回 `parentW=0, parentH=0`，导致 `width:100%` 因 `parentSize > 0` 条件不满足而回退到 `parsePixels('100%') = 100`

**修复**：当 parent=null 时使用 `WINDOW_WIDTH/HEIGHT` 作为父容器尺寸（`BlockLayoutStrategy` 已有此 fallback）

**框架文件**：`framework/Rendering/Layout/FlexLayoutStrategy.php` line 79-83

**测试验证**：创建带 `width:100%;height:100%` 的 flex 容器，验证其尺寸等于 WINDOW_WIDTH/HEIGHT

---

### 案例 2：绝对定位 bottom:0 right:0 位置偏

**症状**：`position:absolute;bottom:0;right:0` 的元素不在容器 padding box 右下角

**根因**：`AbsolutePositioning.php` 中 padding box 边界计算公式未正确包含 `paddingRight`/`paddingBottom`

**修复**：
- rightEdge：padding box 右边界 = `ancestorX + ancestorW + ancestorPaddingRight - right`
- bottomEdge：padding box 下边界 = `ancestorY + ancestorH + ancestorPaddingBottom - bottom`

**框架文件**：`framework/Rendering/Layout/AbsolutePositioning.php` line 152-165

**测试**：`tests/unit/Layout/PositionLayoutTest.php` + `tests/unit/LayoutResolverTest.php` 中的绝对值定位测试

---

### 案例 3a：auto-height 容器 + absolute 子节点（第一遍跳过）

**症状**：`position:relative; height:auto` 的容器包含 `position:absolute` 子节点，absolute 子节点位置不对

**根因**：BlockLayoutStrategy 第一遍解析时跳过 absolute 子节点，但 auto-height 计算后未重新解析 absolute 子节点

**修复**：在 auto-height 算出后增加第二遍解析，传入容器最终坐标

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php` lines 390-400（第二遍） + 461-470（第一遍跳过）

---

### 案例 3b：auto-height 容器 + absolute 子节点（Frame 2+ 正反馈循环）

**⚠️ 这是一个 Frame 依赖型 bug**：Frame 1 正确、Frame N 异常。`--dump-layout` 单帧模式永远检测不到。

**症状**：容器高度逐帧膨胀（每帧增长 paddingBottom），TL 锚点上移、BR 锚点下移。布局 dump 正确但实际截图尺寸巨大。

**根因**：`BlockLayoutStrategy::resolveBlockLayout()` 的 auto-height 计算（行 374-391）在遍历所有子节点取 `maxBottom` 时，没有排除 `position:absolute` 和 `position:fixed` 的子节点，违反 CSS 2.2 §10.6.3。

**正反馈链**：
1. Frame 1: absolute 子节点尚未定位（y=0），auto-height 只取 normal flow → 正确 ✓
2. Frame 2: BR 锚点已被 Frame 1 的第二遍 AbsolutePositioning 定位到容器底部 → auto-height 错误包含它 → computedH 增长 paddingBottom(28px)
3. Frame N: h 每帧增长 28px，直到逼近窗口高度

**为什么 `--dump-layout` 没发现**：`--dump-layout` 在 main.php 中执行 `$app->render()` 后立即 `$app->dumpLayoutToFile()` 并 exit(0)，只做一次 render。正反馈需要 Frame 2+ 才触发。

**为什么单元测试没发现**：LayoutResolverTest 已有 "auto-height + absolute" 测试用例（行 866-914），但都是单次 `resolve()` 调用。无跨帧稳定性断言。

**修复**：在 auto-height 的 foreach 循环开头添加 position 检查：
```php
$childPosition = $child->style['position'] ?? 'static';
if ($childPosition === 'absolute' || $childPosition === 'fixed') {
    continue;
}
```

**经验教训**：
1. Frame 依赖型 bug 是测试死角——单次 resolve 无法暴露
2. CSS 布局必须严格遵循规范：auto-height 只计算 normal flow 子节点
3. 调试锚点（`position:absolute`）本身就是最易触发此类 bug 的元素
4. `--dump-layout` 单帧正确 ≠ `run()` 多帧正确

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php` line 374-391

**追踪**：此 bug 由锚点 TL→BR 相对位置差异（布局 dump 536×484 vs 截图 536×898）直接暴露，锚点对比是高效诊断手段。

---

### 案例 4：Block auto-height 包含 paddingTop

**症状**：`position:relative; height:auto; padding:28px` 的容器引擎 h=456，浏览器 h=520（偏差 64px = 2×paddingTop）

**根因**：CSS 2.2 §10.6.3 规定 auto-height = 从内容区上边缘到最后一个 in-flow 子元素下边缘。BlockLayoutStrategy 用 `maxBottom - node->y` 计算，但 `node->y` 是 border-box 上边缘（包含了 paddingTop），导致 content-box 高度多了 paddingTop。

**修复**：`computedH = maxBottom - (node->y + paddingTop)`

**框架文件**：`framework/Rendering/Layout/BlockLayoutStrategy.php` line 383

**测试验证**：创建带 padding 的 auto-height 容器，验证 h 等于子元素最大 bottom 减去内容区上边缘

---

### 案例 5：浏览器 ref wrapper CSS 导致假阳性（box-sizing / line-height）

**症状**：music-player 测试 10 项失败中 4 项是假阳性——文字宽度偏差 56px（480 vs 424）、文本高度偏差 8px（16 vs 24）、控件偏移 28px。引擎行为正常。

**根因**：`generate_project_ref.php`/`generate_browser_refs.php` 的 buildWrapperHtml() 中 set 了两条非标准 CSS：
- `* { box-sizing: border-box }` — 引擎用 CSS 默认 content-box，浏览器 wrapper 却用 border-box，导致盒子模型不一致
- `body { line-height: 1.7 }` — 引擎 `line-height: normal` = fontSize×1.2，浏览器 wrapper 设 1.7，导致文本高度差异

**修复**：
1. 移除 `* { box-sizing: border-box }` — 引擎使用 content-box（CSS 默认），浏览器也保持默认
2. 移除 `body { line-height: 1.7 }` — 改用 normalize.css `html { line-height: 1.15 }` 作为统一基线
3. 引入 `<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/normalize.css">` 处理其他基础差异

**框架文件**：`tools/generate_project_ref.php` / `tools/generate_browser_refs.php` → `buildWrapperHtml()`

**验证**：重新生成 ref → 重新测试 → 假阳性项归零

**经验教训**：
- 浏览器 ref 生成工具引入的 wrapper CSS 是"隐性基线"，容易被忽略
- 用 normalize.css（行业标准）替代手写 reset，避免人为引入偏差
- 差异出现时，先排查 ref 生成工具，再排查引擎

---

### 案例 6：浏览器视口与引擎窗口不一致导致坐标系统性偏移

**症状**：所有元素 x 坐标引擎比浏览器大 260px（例如 card 引擎 x=660，浏览器 x=400），方向一致

**根因**：music-player 引擎 `WINDOW_WIDTH=1800`，但 `generate_project_ref.php` 的 `--window-size=1280,3000`。flex 容器在 1800px 和 1280px 视口中居中计算位置不同。

**修复**：将 `--window-size` 改为 `--window-size=1800,1200`，匹配引擎窗口尺寸。

**框架文件**：`tools/generate_project_ref.php` → `runEdgeHeadless()` 的 `--window-size` 参数

**验证**：重新生成 ref → 对比坐标 → 偏差归零（或接近零，+/- 子像素舍入差异）

**经验教训**：
- 每个应用的 ref 生成工具的 `--window-size` 必须独立匹配其引擎的 `WINDOW_WIDTH/HEIGHT`
- 数据列到项目初始化 checklist 中

---

## 九、问题反思机制

### 9.1 反思触发条件

当出现以下情况时，必须执行完整反思流程：

| 触发条件 | 说明 | 示例 |
|---------|------|------|
| **管线未捕获的 bug** | 测试管线（Phase 1→4）全部通过后，仍被人工/截图发现 | auto-height 正反馈循环（案例 3b） |
| **回归遗漏** | 框架修改导致已通过的测试退化但未被检测到 | — |
| **反复出现的同类问题** | 同一模块、同一模式在不同场景下多次出 bug | auto-height 相关 bug（案例 3a/3b/4 均涉及） |
| **测试盲区暴露** | 现有测试体系完全无法覆盖的 bug 类型 | Frame 依赖型 bug、多帧稳定性 |
| **人工审查发现的模式缺陷** | Code review 发现的设计层面的问题 | — |

### 9.2 反思六步法

> **总体原则**：一次反思，三处同步（本文档案例库 + AI 驱动文档 + 测试用例）。

#### Step 1：症状记录

客观记录 Bug 的表面表现，不掺杂推测：

```
记录项               内容示例
──────────────────────────────────────────────
发现时间            2026-06-09
发现方式            布局 dump vs 截图对比（TL/BR 锚点跨度异常）
预期结果            536×484（与布局 dump 一致）
实际结果            536×898（布局 dump 正确，截图膨胀 414px）
影响范围            Bilibili 卡片渲染
```

#### Step 2：根因定位

回溯到最小可复现条件，定位到具体代码行：

- **最小化**：从完整应用逐步剥离到最简测试用例（单元测试）
- **归因**：确定是框架 bug、应用 bug、还是工具 bug
- **定位**：具体到文件 × 行号 × 逻辑分支

```
根因：BlockLayoutStrategy::resolveBlockLayout() auto-height foreach
      （行 374-391）未排除 position:absolute/fixed 子节点
规范依据：CSS 2.2 §10.6.3 — auto-height 仅计算 normal flow 子节点
```

#### Step 3：管线盲区分析（核心反思步骤）

对**每层防线**逐一审查为什么没有拦截 Bug，形成"防线层 → 问题 → 根因" 三列分析表：

| 防线层 | 问题 | 根因 |
|--------|------|------|
| `--dump-layout` 对比 | 通过 ✓ | 单帧模式，只 render 一次就 exit(0)，Frame 2+ 的 bug 永不触发 |
| 截图对比 | 未拦截 | baseline 截图从同一 buggy exe 生成 → 两者对齐 → 差异抵销 |
| 单元测试（LayoutResolverTest） | 通过 ✓ | 所有 "auto-height + absolute" 测试只单次 resolve()，无跨帧断言 |
| Code review | 未发现 | auto-height 逻辑修改时未考虑 Frame 2+ 行为 |

**关键产出**：明确列出每层的**失效原因**和**改进措施**。

#### Step 4：修复验证

- 确认修复代码符合 CSS 规范引用
- 在最小测试用例上验证 Frame 1→2→3 稳定性
- 在全量测试上验证无回归
- 记录修复后的关键指标（锚点跨度、容器 h 等）

#### Step 5：测试增强

基于 Step 3 的盲区分析，补强每层防线：

| 防线 | 增强措施 | 对应产出 |
|------|---------|---------|
| 单元测试 | 新增多帧稳定性测试（2×resolve + 断言） | LayoutResolverTest 新增测试用例 |
| auto_test.php | 可选：Frame 2 的 `--dump-layout` 对比 | auto_test.php 扩展 |
| 回归验证 | 将新测试加入回归套件 | run_all_tests.php 覆盖 |
| 文档 | 更新案例库 + AI 驱动文档 | 本文档 + AGENTS.md |

#### Step 6：知识沉淀

将反思结果同步到三处：

1. **本文档 §八 案例库** — 记录 Bug 症状、根因、修复、教训
2. **本文档 §九 反思清单** — 新增/更新反思清单条目
3. **AGENTS.md** — 同步到 AI 驱动的开发规范（检查清单 / 测试规范）
4. **单元测试** — 测试用例作为活的文档

---

### 9.3 反思输出 Checklist

每次反射执行完毕后，确认以下产出齐全：

- [ ] Bug 症状与根因已记入 §八 案例库
- [ ] 管线盲区分析（防线层 → 问题 → 根因 三列表）已完成
- [ ] 测试增强已实现且通过
- [ ] AI 驱动文档（AGENTS.md）中相关规范已同步更新
- [ ] 反思中暴露的通用盲区已抽象为检查清单项
- [ ] 反思清单（§9.4）已更新
- [ ] 对应项目的 Bug 台账（test_log/bug_tracker.md）已新增或更新条目
- [ ] 多项目场景下，影响范围分析已完成（是否需同步更新其他项目的台账）

---

### 9.4 反思清单（持续维护）

此清单记录所有已知管线盲区，作为 AI 和开发者每次修改前的预检参考。每次反思后，如有新盲区类型则追加至此表。

| # | 盲区类别 | 首次暴露于 | 预检要求 |
|---|---------|-----------|---------|
| 1 | **Frame 依赖型 Bug**：单帧正确 ≠ 多帧正确 | 案例 3b | 涉及 auto-height / absolute / padding 的布局修改必须加多帧稳定性测试（2×resolve + 断言） |
| 2 | **自引用基线**：截图/布局对比的 baseline 从同一 buggy exe 生成 → 差异抵销 | 案例 3b | baseline 优先使用浏览器 ref（非 exe），迫不得已时在报告中注明基线来源 |
| 3 | **单次 resolve 假设**：单元测试只 resolve 一次 → 无法暴露 Frame 2+ 的稳定性问题 | 案例 3b | 所有涉及 auto-height / absolute / padding 的测试必须 resolve 至少 2 次 |
| 4 | **隐性基线偏差**：ref 生成工具的 wrapper CSS 引入非标准基线 | 案例 5 | 出现系统性差异时，先排查 ref 生成工具 buildWrapperHtml()，再排查引擎 |
| 5 | **视口不一致**：引擎和 ref 生成的 window-size 不匹配 → 系统性坐标偏移 | 案例 6 | 项目初始化时确认 `--window-size` 与 `WINDOW_WIDTH/HEIGHT` 一致 |

**维护规范**：
- 每添加一个案例到 §八，同时检查是否需要增加盲区条目
- 盲区条目的预检要求应具体、可操作，能在代码修改前作为 checklist 逐条过

---

## 十、附录

### 命令速查

```bash
# 项目初始化
cd F:\work\Px
php sfc-compiler.php apps/<project>            # SFC 编译
.\build.bat <project>                           # 构建 exe

# 运行测试
php apps/<project>/auto_test.php               # 自动化测试

# 仅导出布局
cd apps/<project>/bin
.\<project>.exe --dump-layout

# 浏览器参考
php tools\generate_browser_refs.php            # css-test 多 Level 参考数据
php tools\generate_project_ref.php <project>   # 单个项目参考数据（注意 `--window-size` 匹配引擎）

# 截图（手动）
powershell -ExecutionPolicy Bypass -File tools/capture_screenshot.ps1 `
    -AppName <project> -ProjectRoot F:/work/Px `
    -OutputPath apps/<project>/test_log/captured.png

# 基线截图
powershell -ExecutionPolicy Bypass -File tools/capture_screenshot.ps1 `
    -Mode baseline -ProjectRoot F:/work/Px -AppName <project> `
    -HtmlPath apps/<project>/baseline.html `
    -OutputPath apps/<project>/base_line_pic.png

# 全量测试
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
error_log('[DIAG_FLEX] enter resolveFlexLayout type=' . $node->type . ' w=' . ($style['width'] ?? 0));

// 在 auto_test.php 中针对特定元素加调试
if ($text === '目标元素文本') {
    file_put_contents('debug_element.log', print_r([
        'browser' => $bEl, 'engine' => $eEl
    ], true));
}

// 直接检查 engine_layout.json
// 搜索目标文本确认引擎的坐标和样式值
```

### 多帧布局稳定性验证

**背景**：auto-height + absolute 子节点的正反馈循环 bug 证明了 Frame 依赖型 bug（Frame 1 正确、Frame N 异常）是 `--dump-layout` 和单次 resolve 测试的死角。

**新增测试要求**：所有涉及布局计算的修复，必须在**同一棵 RenderNode 树**上运行 2 次 `LayoutResolver::resolve()` 并断言关键尺寸不变：

```php
// 在单元测试中增加多帧稳定性断言
$resolver->resolve($root);
$h1 = $container->h;
$y_abs = $absoluteChild->y;

$resolver->resolve($root);  // 第二次：模拟 Frame 2

// 断言：auto-height 在多帧间必须稳定
assert_eq($container->h, $h1, 'Frame 2 auto-height 应与 Frame 1 一致');
// 断言：absolute 子节点位置在多帧间必须稳定
assert_eq($absoluteChild->y, $y_abs, 'Frame 2 absolute child y 应与 Frame 1 一致');
```

**具体场景**（必须添加多帧断言）：
- 任何含 auto-height 的 block 容器 + absolute/fixed 子节点
- 任何含 padding 的 auto-height 容器
- 任何调整了子节点 y 坐标的布局策略（flex/grid 重定位后）

**`auto_test.php` 增强**：
- Phase 1: `--dump-layout` 通过后，建议额外调 `$app->render()` 第二次再 dump，对比两次 layout JSON 中关键容器的 w/h 是否一致
- 参考：此 bug 修复后已验证的稳定指标——TL 锚点 (660,386)、BR 锚点 (1188,862)、跨度 536×484

### auto_test.php 模板

```php
<?php
require_once __DIR__ . '/../../tools/shared_test_lib.php';

$appName = '<project>';
$projectRoot = dirname(__DIR__, 2);
$appDir = __DIR__;

echo "========================================\n";
echo "  CSS Layout Test - $appName\n";
echo "========================================\n\n";

// Step 1: Build
echo "Step 1: Building...\n";
$buildResult = run_cmd("cd /d \"$projectRoot\" && .\\build.bat $appName 2>&1");
// ... 检查构建结果

// Step 2: Dump layout
echo "\nStep 2: Dumping layout...\n";
$exeDir = "$appDir/bin";
$exePath = glob("$exeDir/*.exe")[0];
$dumpResult = run_cmd("cd /d \"$exeDir\" && \"" . basename($exePath) . "\" --dump-layout 2>nul");
// ... 检查 layout 生成

// Step 3: Load engine layout
$engineJson = json_decode(file_get_contents("$appDir/engine_layout.json"), true);
$flatEngine = flattenEngineTree($engineJson);

// Step 4: Load browser refs + compare
$refDir = "$appDir/ref";
$refFiles = glob("$refDir/browser_ref_level_*.json");
// ... 逐 Level 加载、匹配、对比

// Step 5: Screenshot comparison
echo "\nStep 5: Screenshot comparison...\n";
// --dump-layout 模式不需要窗口，但截图需要 exe 运行
// 如需要截图，使用 runScreenshotTest()

// Step 6: Report
echo "\n========================================\n";
echo "  Test Complete\n";
echo "========================================\n";
```
