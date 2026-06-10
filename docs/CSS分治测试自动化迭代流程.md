# CSS 分治测试自动化迭代框架 — 流程指南

## 一、概述

本文档描述 Px 框架的 CSS 布局引擎**分治测试 + 自动化迭代**的完整工作流。其核心思想是：

> **用独立的 HTML 样例，分别测试不同 CSS 特性组合 → 对比引擎输出与浏览器标准 → 定位差异根因 → 修复框架层 → 验证回归**

整个流程设计为可被 AI 代理自动化执行，形成持续迭代闭环。

### 核心原则

| 原则 | 说明 |
|------|------|
| **分治** | 将 CSS 特性按布局能力拆分为独立 Level（0~7），每 Level 聚焦特定特性集 |
| **标准对齐** | 以浏览器（Edge Chromium）渲染结果为参考标准，引擎输出逐像素对比 |
| **数据驱动** | 差异分析基于结构化 JSON 对比，不依赖人工视觉检查 |
| **先治本再治标** | 框架层面的 bug 必须从根因修复，不在应用层打补丁 |
| **渐进式覆盖** | 先核心排版 → 盒模型 → 卡片系统 → Flex → Grid → 表格 → 特殊组件 → 集成页 |

---

## 二、系统架构

```
                          ┌──────────────────────┐
                          │   test_cases/         │
                          │   level_{0-7}.html    │
                          │   (独立CSS特性HTML)    │
                          └──────┬───────────────┘
                                 │
              ┌──────────────────┼──────────────────────┐
              ▼                  ▼                      ▼
    ┌─────────────────┐  ┌─────────────────┐  ┌────────────────────┐
    │   App.vue        │  │ Edge Headless    │  │ generate_browser_ │
    │   (集成所有Level) │  │ --dump-dom       │  │ refs.php           │
    │                  │  │ 渲染 1280×3000   │  │ (生成浏览器参考)    │
    └────────┬────────┘  └────────┬────────┘  └──────────┬─────────┘
             │                    │                       │
             ▼                    ▼                       ▼
    ┌─────────────────┐  ┌─────────────────┐  ┌────────────────────┐
    │ sfc-compiler     │  │ ref/            │  │ browser_ref_level_ │
    │ .vue → gen/*.php │  │ browser_ref_    │  │ {0-7}.json         │
    └────────┬────────┘  │ level_{0-7}.json │  │ (浏览器标准数据)    │
             │           └────────┬────────┘  └────────────────────┘
             ▼                    │
    ┌─────────────────┐           │
    │ build.bat        │           │
    │ Swoole Compiler  │           │
    │ → .exe           │           │
    └────────┬────────┘           │
             │                    │
             ▼                    ▼
    ┌──────────────────────────────────────────┐
    │  auto_test.php (自动化测试编排)            │
    │                                          │
    │  Step 1: build.bat → exe                 │
    │  Step 2: exe --dump-layout → engine.json │
    │  Step 3: 加载 ref/browser_ref_*.json      │
    │  Step 4: 逐元素对比 (文本匹配+样式对比)    │
    │  Step 5: 生成 test_report_*.md            │
    └──────────────────────────────────────────┘
```

---

## 三、文件清单与职责

### 3.1 测试用例（需要 AI 编写/维护）

| 文件 | 用途 | CSS 特性 |
|------|------|----------|
| `test_cases/level_0.html` | 页面容器 + 排版 | font-size、color、margin、padding、border |
| `test_cases/level_1.html` | 排版元素 | inline、border-radius、font-family |
| `test_cases/level_2.html` | 卡片系统 | border-left、background、padding 组合 |
| `test_cases/level_3.html` | Flex 布局 | display:flex、gap、flex-wrap、justify-content |
| `test_cases/level_4.html` | Grid 布局 | grid-template-columns、gap、1fr |
| `test_cases/level_5.html` | 表格 | display:flex 模拟表格、border-collapse |
| `test_cases/level_6.html` | 特殊组件 | overflow、border-radius、flex 组合 |
| `test_cases/level_7.html` | 整页集成 | 所有 Level 的 CSS 集成 |

**编写规范**：
- 每个 Level 独立一个 HTML 文件，只测试其对应 CSS 特性范围
- 使用内联 style（不依赖外部 CSS）以确保确定性
- body 基础样式固定：`* { box-sizing: border-box; margin: 0; padding: 0; }`
- 容器宽度默认为 1100px，padding:32px，box-sizing:border-box

### 3.2 浏览器参考生成

| 文件 | 用途 |
|------|------|
| `tools/generate_browser_refs.php` | 用 Edge headless 渲染 test_cases 并提取布局数据 |
| `tools/dump_layout.js` | 注入每个 Level HTML 的 JS 提取器（getBoundingClientRect + getComputedStyle） |
| `apps/css-test/ref/browser_ref_level_{0-7}.json` | 生成的浏览器参考数据 |

**运行命令**：
```bash
cd F:\work\Px
php tools\generate_browser_refs.php
```

> **浏览器参考是"标准答案"**。当浏览器参考过时时（CSS 属性列表变化、布局算法更新），需要重新生成。

### 3.3 引擎端

| 文件 | 用途 |
|------|------|
| `apps/css-test/App.vue` | Vue 3 模板集成所有 Level 组件 |
| `apps/css-test/components/Level{0-6}_*.vue` | 各 Level 的 Vue 组件（与 test_cases HTML 内容对齐） |
| `apps/css-test/main.php` | 入口：支持 `--dump-layout` 参数导出布局 JSON |
| `apps/css-test/project.yml` | 构建配置 |

### 3.4 测试自动化

| 文件 | 用途 |
|------|------|
| `apps/css-test/auto_test.php` | 主控脚本：构建→运行→对比→报告 |
| `apps/css-test/test_log/` | 测试日志和报告输出目录 |

---

## 四、核心数据流

### 4.1 调用的关键方法链

```php
// Application.php — 布局 JSON 输出
dumpLayoutToFile($path) {
    $root = renderTreeManager->getRoot();
    return json_encode($this->serializeRenderNode($root), JSON_PRETTY_PRINT);
}

serializeRenderNode($node) {
    // 输出: type, x, y, w, h, visualW, visualH, layer, isScrollContainer, content
    // style (白名单过滤): bg, fg, fontSize, bold, display, padding*, margin*,
    //                     border*, borderRadius, gap, boxSizing, flex*, grid*, align*, justify*
}
```

### 4.2 对比引擎 vs 浏览器的匹配策略

```
浏览器文本索引                   引擎文本索引
┌────────────────────┐         ┌────────────────────┐
│ "左侧卡片"          │ ────→  │ "左侧卡片"          │ (完全匹配)
│ "这是一个包含\n内联  │         │ "内联代码"          │ (部分匹配)
│  代码\n的段落。"     │ ────→  │ "function hello()"│
└────────────────────┘         └────────────────────┘
         │                             │
         ▼                             ▼
  跳过父容器串联文本              叶子节点文本
  (含换行的组合文本)              (实际渲染的文本)
```

**匹配逻辑优先级**（auto_test.php）：
1. **完全匹配**：引擎文本 === 浏览器文本
2. **部分匹配**：引擎文本前20字符 === 浏览器文本前20字符
3. **父容器跳过**：引擎文本包含在浏览器文本中且占比20%~85% → 父容器串联文本，跳过
4. **匹配失败**：标记为 ❌

### 4.3 对比维度

每个匹配的文本元素对比：
- **相对位置** (`relX`, `relY`)：相对于 Level 容器计算的偏移（消除整体布局差异）
- **尺寸** (`w`, `h`)：宽度和高度
- **样式属性**（21 项覆盖）：

| 类别 | 属性 |
|------|------|
| 排版 | fontSize、fg(color)、bold |
| 内边距 | paddingTop/Left/Right/Bottom |
| 外边距 | marginTop/Left/Right/Bottom |
| 边框 | borderWidth、borderColor、borderLeftWidth、borderLeftColor、borderRadius |
| 布局 | display、flexDirection、flexWrap、gap、alignItems、justifyContent、boxSizing |

---

## 五、自动化迭代工作流

### 5.1 完整迭代周期

```
┌─────────────────────────────────────────────────────────────────┐
│                    一次迭代周期（约 30 分钟）                     │
│                                                                   │
│  ┌──────────┐   ┌──────────┐   ┌──────────┐   ┌──────────────┐  │
│  │ Phase 1  │   │ Phase 2  │   │ Phase 3  │   │   Phase 4    │  │
│  │ 添加测试  │ → │ 构建+运行 │ → │ 分析报告  │ → │ 修复框架缺陷  │  │
│  │ case     │   │          │   │          │   │              │  │
│  └──────────┘   └──────────┘   └──────────┘   └──────────────┘  │
│       │                                                          │
│       └──────────────────── 循环 ────────────────────────────────┘
│                                                                   │
│  退出条件: 新增测试的通过率 ≥95% 或已覆盖所有目标 CSS 属性         │
└─────────────────────────────────────────────────────────────────┘
```

### 5.2 Phase 1：添加/更新测试用例

**AI 执行步骤**：

```
Step 1.1: 确定要测试的 CSS 特性
  → 从 ["text-align", "line-height", "white-space", "font-family", "opacity", ...] 选一个

Step 1.2: 编写 test_cases/level_X.html
  → 包含待测特性的独立 HTML 片段
  → body 基础样式固定
  → 只测试该特性，保持其他 CSS 简单

Step 1.3: 编写/更新 components/LevelX_*.vue
  → 与 level_X.html 内容完全一致（Vue 3 模板语法）
  → 在 App.vue 中添加新 Level 组件

Step 1.4: 重新编译 SFC
  → php sfc-compiler.php apps/css-test

Step 1.5: 重新生成浏览器参考
  → php tools\generate_browser_refs.php
```

### 5.3 Phase 2：构建并运行测试

```bash
# 编译 exe
cd F:\work\Px
build.bat css-test

# 运行自动化测试（包含 --dump-layout + 对比 + 报告）
php apps\css-test\auto_test.php
```

**auto_test.php 执行流程**：

```
Step 1: build.bat css-test → bin/css-test.exe
  ↓ 成功？
  ↓
Step 2: bin/css-test.exe --dump-layout → engine_layout.json
  ↓ 成功？
  ↓
Step 3: 加载 ref/browser_ref_level_0-7.json
  ↓
Step 4: 展平 engine_layout.json → 扁平元素列表
  ↓ 按文本匹配浏览器元素
  ↓ 逐元素 compareElement()
  ↓
Step 5: 生成 test_log/test_report_YYYYmmdd_HHMMSS.md
        更新 test_log/latest_report.md
```

### 5.4 Phase 3：分析测试报告

**报告结构**：

```markdown
## Level 逐元素对比
| Level | 文本 | 相对位置 | 样式差异 | 状态 |
| Level-0 | xxx | rel=(32,32) w=1036 h=37 | - | ✅ |
| Level-4 | 左侧卡片 | w(e:0|b:510) h(e:90|b:90) | borderLeft: ... | ❌ |

## Level 汇总
| Level | 通过 | 失败 | 跳过 | 总数 | 通过率 |

## 相对位置一致性统计
| 对照维度 | 数量 | 占比 |
| rel 完全一致 (Δx=0 ∧ Δy=0) | 5 | 2.9% |

## CSS 属性覆盖分析
### 引擎已输出的 CSS 属性 (24)
### 浏览器有但引擎缺失的 CSS 属性 (12)
### 每属性匹配统计
```

**AI 分析模式**：

1. **检查整体通过率** — 169/171 pass vs 2 fail
2. **检查失败项的样式差异** — `borderLeftColor: engine=#30363D browser=#ef4444`
3. **检查缺失的 CSS 属性** — `text-align`、`line-height` 等
4. **检查 Grid/Flex 等复杂布局的位置一致性** — `w=0` 表示 Grid 子元素宽度未正确计算
5. **深度阅读差异细节** — 从 engine_layout.json 中定位具体元素，追踪布局管道

### 5.5 Phase 4：修复框架缺陷

**典型修复路径**：

```php
// 根据差异类型，进入不同的框架文件修改

// ─── 布局坐标错误 (x/y/w/h 不匹配) ───
// 跟踪 LayoutResolver → 对应策略类
framework/Rendering/LayoutResolver.php           // 布局引擎入口
framework/Rendering/Layout/BlockLayoutStrategy.php // Block 布局
framework/Rendering/Layout/FlexLayoutStrategy.php  // Flex 布局
framework/Rendering/Layout/GridLayoutStrategy.php  // Grid 布局
framework/Rendering/Layout/AbsolutePositioning.php // 绝对定位
framework/Rendering/Layout/PercentResolver.php     // 百分比解析

// ─── 样式属性值不匹配 ───
// 跟踪样式映射 + RenderNode 输出
framework/Rendering/CssMappings.php               // CSS → GDI 映射
framework/Core/Application.php (serializeRenderNode) // 属性白名单

// ─── 渲染视觉效果不一致 ───
// 跟踪 RenderContext
framework/Rendering/GdiRenderContext.php           // GDI 渲染实现
framework/Rendering/VNodeRenderer.php              // 渲染树遍历
```

**修复准则**（Px 框架三原则）：

1. **治本不治标** — 在框架层修复，不在应用层 $.vue 加 workaround
2. **通用合规** — 修复应符合 CSS 标准，不针对特定测试用例特化
3. **先覆盖后优化** — 先通过测试，再考虑性能

### 5.6 回归验证

修复后重新执行 Phase 2：

```bash
build.bat css-test
php apps\css-test\auto_test.php
```

**验证标准**：
- 新增测试的失败项应减少到 0
- 原有通过的测试不应新增失败（回归防护）
- `latest_report.md` 中的引擎属性覆盖数应增加

---

## 六、引擎维度 vs 浏览器维度对比

### 6.1 数据对齐方式

```
引擎坐标系:
  root (x:0, y:0, w:1280)
    └─ Level Container (x:0, y:?, w:1100, boxSizing:border-box)
         └─ elements (x, y 是相对于 root 的绝对坐标)

浏览器坐标系:
  body (x:0, y:0)
    └─ 容器 div (x:0, y:0, w:1100)
         └─ elements (x, y 是相对于 viewport 的绝对坐标)

相对位置计算:
  engine: relX = el.x - container.x
          relY = el.y - container.y
  browser: relX = el.x (容器在 (0,0))
           relY = el.y (容器在 (0,0))
```

### 6.2 样式属性归一化

| 引擎格式 | 浏览器格式 | 转换方式 |
|---------|-----------|---------|
| `fontSize: 14` | `font-size: 14px` | `cssPxToInt("14px") → 14` |
| `fg: 15132390` | `color: rgb(230,237,243)` | `cssColorToGdi → 15132390` |
| `bold: 1` | `font-weight: 700` | `cssWeightToBold("700") → 1` |
| `borderColor: 0` | `border-color: rgb(48,54,61)` | `cssColorContains(0, ...)` |

---

## 七、CSS 属性覆盖递进路线

当前引擎输出 24 项 CSS 属性，浏览器参考包含 34 项，12 项缺失。

### 优先级排序

| 优先级 | 属性 | 影响 | 实现位置 |
|--------|------|------|---------|
| 🔴 高 | `text-align` | 文字对齐效果 | LayoutResolver → CssMappings |
| 🔴 高 | `line-height` | 行高导致纵坐标累积偏移 | GdiRenderContext::drawText |
| 🔴 高 | `white-space` | 空白/换行处理 | VNodeRenderer 文本布局 |
| 🟡 中 | `font-family` | 字体选择 | CssMappings → GdiRenderContext |
| 🟡 中 | `opacity` | 透明度 | RenderNode → RenderContext alpha |
| 🟡 中 | `overflow-x/y` | 溢出隐藏 | ScrollContainer 处理 |
| 🟢 低 | `width/height` | 已在布局 w/h 覆盖 | serializeRenderNode 白名单 |
| 🟢 低 | `position/top/left` | 已在布局坐标覆盖 | serializeRenderNode 白名单 |

### 实现检查清单

当引擎新增一个 CSS 属性时，需要修改以下位置：

- [ ] `framework/Rendering/CssMappings.php` — CSS 属性到 RenderNode style 的映射
- [ ] `framework/Rendering/Layout/LayoutResolver.php` 或对应策略类 — 属性影响坐标计算
- [ ] `framework/Core/Application.php` — serializeRenderNode 白名单添加新属性键
- [ ] `framework/Rendering/RenderContext.php` 或实现类 — 属性影响绘制效果
- [ ] `apps/css-test/auto_test.php` — 对比逻辑中添加检查项
- [ ] 对应的 test_cases/level_X.html — 加入包含该属性的测试用例

---

## 八、常见问题与诊断

### 8.1 测试执行失败

| 症状 | 诊断 | 解决 |
|------|------|------|
| build.bat 失败 | 检查 config.yml 中编译器路径 | 确保 Swoole Compiler 路径正确 |
| engine_layout.json 未生成 | exe 崩溃 | 检查 main.php 中 --dump-layout 分支 |
| 浏览器 ref 不存在 | 未运行 generate_browser_refs.php | 安装 Edge Chromium 后运行 |
| 文本匹配 0 个 | 引擎输出 JSON 格式异常 | 检查 serializeRenderNode 输出 |

### 8.2 框架修复常见问题

| 模式 | 根因位置 | 修复方法 |
|------|---------|---------|
| Grid 子元素 w=0 | GridLayoutStrategy 未设置 style['width'] | 在设置 `$ch->w` 的同时设置 `$ch->style['width']`；BlockLayoutStrategy 添加 parentIsGrid 防护 |
| 纵坐标 Δy > 50px | line-height 缺失或 margin 累积 | GdiRenderContext::drawText 添加行高支持；margin 折叠逻辑 |
| 横坐标 Δx > 50px | inline 布局/Grid 布局未实现 | 完善 FlexLayoutStrategy 和 GridLayoutStrategy |
| 颜色匹配失败 | GDI 颜色格式转换不一致 | 检查 CssMappings 中颜色值提取逻辑 |
| 属性在 engine 中不存在 | serializeRenderNode 白名单未添加 | 在 `$styleKeys` 数组中添加属性键 |

### 8.3 调试技巧

```php
// 在框架代码中加诊断日志
error_log('[DIAG_GRID] gridContainer w=' . $containerW . ' cellW=' . $cellWFinal);
error_log('[DIAG_LAYOUT] after grid child: type=' . $ch->type . ' x=' . $ch->x . ' w=' . $ch->w);

// 在 auto_test.php 中添加临时调试
// 在 compareElement() 中针对特定文本打印详细信息
if ($text === '左侧卡片') {
    file_put_contents('debug_card.log', print_r(['browser' => $bEl, 'engine' => $eEl], true));
}

// 直接检查 engine_layout.json 定位元素
// 搜索目标文本，确认 engine 的输出值和坐标
```

---

## 九、框架修改规范

### 9.1 文件修改限制

| 目录 | 修改策略 |
|------|---------|
| `framework/` | 核心框架，修改后影响所有应用 → 严格回归测试 |
| `apps/css-test/` | 测试专用，可频繁修改 |
| `apps/css-test/gen/` | **自动生成，禁止手动修改** — 由 sfc-compiler.php 生成 |
| `apps/css-test/test_log/` | 测试输出，可删除重新生成 |
| `tools/` | 测试工具，按需修改 |

### 9.2 修改流程

```
1. 修改 framework/ 下的源文件
2. build.bat css-test (确保编译通过)
3. php apps\css-test\auto_test.php (验证 no regression)
4. 如有新增 CSS 属性：
   a. 更新 serializeRenderNode 白名单
   b. 更新 auto_test.php 的对比 checks
   c. 更新 test_cases 和对应的 .vue 组件
   d. 重新生成浏览器参考
5. 提交前验证：全量测试通过率不应下降
```

### 9.3 新增属性实例 — checklist

以新增 `text-align` 为例：

1. **CssMappings.php**: 添加 `'text-align' => 'textAlign'` 映射
2. **Application.php serializeRenderNode**: `$styleKeys` 中添加 `'textAlign'`
3. **auto_test.php compareElement**: `$checks` 中添加 `['textAlign', 'text-align', 'string', 'textAlign']`
4. **test_cases/level_X.html**: 添加包含 text-align 的测试片段
5. **对应的 .vue 组件**: 与 HTML 同步
6. **重新生成 ref**: `php tools\generate_browser_refs.php`
7. **构建测试**: `build.bat css-test && php apps\css-test\auto_test.php`

---

## 十、完整迭代示例

以下演示一个完整的迭代周期，目标和步骤均已定式化，可直接由 AI 遵循执行：

### 目标：修复 Grid 子元素宽度为 0 的问题

```
Phase 1: 分析
├── auto_test.php 报告显示 Level-4 Grid 卡片 w=0
├── engine_layout.json: 卡片 w=0, 文本子元素 w=1100(父容器宽度)
└── 根因: GridLayoutStrategy 设置了 $ch->w 但未设置 $ch->style['width']
         → BlockLayoutStrategy 重解析时 auto-fill 为父容器宽度

Phase 2: 修复
├── GridLayoutStrategy.php line 369: 添加 $ch->style['width'] = $cellWFinal
└── BlockLayoutStrategy.php line 96: 添加 parentIsGrid 判断防止 auto-fill

Phase 3: 验证
├── build.bat css-test
├── php apps\css-test\auto_test.php
├── 确认卡片 w=510 (正确)
└── 确认无其他 Level 回归
```

---

## 附录 A：关键文件路径速查

```
框架布局引擎
  f:\work\Px\framework\Rendering\LayoutResolver.php
  f:\work\Px\framework\Rendering\Layout\BlockLayoutStrategy.php
  f:\work\Px\framework\Rendering\Layout\FlexLayoutStrategy.php
  f:\work\Px\framework\Rendering\Layout\GridLayoutStrategy.php
  f:\work\Px\framework\Rendering\Layout\AbsolutePositioning.php
  f:\work\Px\framework\Rendering\Layout\PercentResolver.php
  f:\work\Px\framework\Rendering\CssMappings.php
  f:\work\Px\framework\Core\Application.php

渲染后端
  f:\work\Px\framework\Rendering\GdiRenderContext.php
  f:\work\Px\framework\Rendering\VNodeRenderer.php
  f:\work\Px\framework\Rendering\RenderTreeManager.php

测试基础设施
  f:\work\Px\apps\css-test\auto_test.php
  f:\work\Px\apps\css-test\test_cases\level_{0-7}.html
  f:\work\Px\apps\css-test\components\Level{0-6}_*.vue
  f:\work\Px\apps\css-test\ref\browser_ref_level_{0-7}.json
  f:\work\Px\apps\css-test\test_log\latest_report.md
  f:\work\Px\tools\generate_browser_refs.php
  f:\work\Px\tools\dump_layout.js
```

## 附录 B：命令速查

```bash
# 生成浏览器参考数据（添加新测试后用）
php tools\generate_browser_refs.php

# 重新编译 SFC（修改 .vue 后用）
php sfc-compiler.php apps/css-test

# 构建 exe
build.bat css-test

# 运行完整测试
php apps\css-test\auto_test.php

# 仅运行 exe 导出布局（不对比）
cd apps/css-test
bin/css-test.exe --dump-layout
```
