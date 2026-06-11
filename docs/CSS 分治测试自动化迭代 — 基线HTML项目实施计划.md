# CSS 分治测试自动化迭代 — 基线HTML项目实施计划

## 上下文

**目标**：将 `apps/base_line_html/` 下的 15 个 HTML 样例转化为独立的 Px 框架测试项目。先一次性创建所有 15 个项目的基础文件，再逐个执行完整的自动化迭代周期（Phase 1→2→3→4），逐步发现框架缺陷、完善 CSS 标准支持。

**用户选择**：
- ✅ 全15个项目一次性创建基础结构
- ✅ 完整自动化测试（auto_test.php + browser_ref）
- ✅ 保留CSS样式表（`<style>` 标签保持原样，直接测试框架CSS解析能力）

---

## 项目名称映射

| # | 原始文件 | 项目名称 | CSS 类型 |
|---|---------|---------|----------|
| 1 | 1 (5).html | music-player | 内联样式 |
| 2 | 1 (3).html | weather-app | 内联样式 |
| 3 | 1 (15).html | online-courses | 内联样式 |
| 4 | 1 (8).html | job-listings | 内联样式 |
| 5 | 1 (4).html | social-media | 内联样式 |
| 6 | 1 (1).html | hotel-booking | 内联样式 |
| 7 | 1 (12).html | kanban-board | 内联样式 |
| 8 | 1 (13).html | product-detail | 内联样式 |
| 9 | 1 (2).html | medical-appointment | 内联样式 |
| 10 | 1 (7).html | finance-dashboard | 内联样式 |
| 11 | 1 (6).html | login-form | CSS 样式表 |
| 12 | 1 (9).html | product-grid | CSS 样式表 |
| 13 | 1 (10).html | monitor-dashboard | CSS 样式表 |
| 14 | 1 (11).html | blog-list | CSS 样式表 |
| 15 | 1 (14).html | admin-dashboard | CSS 样式表 |

---

## Phase 0：基础设施创建（一次性创建全部15个项目）

### 创建通用共享工具

1. **`tools/shared_test_lib.php`** — 通用对比库
   - `compareElement($engineEl, $browserEl, $checks)` — 单元素对比逻辑
   - `flattenEngineTree($root)` — 展平 engine_layout.json
   - `loadBrowserRef($path)` — 加载 browser_ref json
   - `generateReport($results, $projectName)` — 生成 MD 报告
   - `projectChecks()` — 默认 21 项 CSS 属性检查项

2. **`tools/generate_project_ref.php`** — 单项目浏览器参考生成器
   - 接受项目名参数 `php tools/generate_project_ref.php <project>`
   - 用 Edge headless 渲染 `test_cases/level_0.html`
   - 注入 `dump_layout.js` 提取布局数据
   - 输出到 `apps/<project>/ref/browser_ref_level_0.json`

### 为每个项目创建以下文件：

每个项目的标准结构：

```
apps/<project-name>/
├── baseline.html              # 从 base_line_html 复制
├── project.yml                # name: <project-name>, 其余模板化
├── main.php                   # 入口，支持 --dump-layout
├── App.vue                    # Vue 模板（保留 CSS <style> 块）
├── test_cases/                # 独立 HTML 测试用例
│   └── level_0.html           # 提取 body + reset 样式
├── ref/                       # 浏览器参考数据
├── gen/                       # SFC 编译器输出（自动）
├── bin/                       # 构建输出（自动）
└── test_log/                  # 测试报告（自动）
```

**关键转换规则**：
- 内联样式项目：body 内容直接嵌入 `<template>`，保持所有 style 属性不变
- CSS 样式表项目：`<style>` 块内容嵌入 App.vue 的 `<style>` 节，body 内容嵌入 `<template>`，class 引用保持
- 外层容器统一加 `style="width:1280px;height:3000px;overflow-y:auto"`

---

## Phase 1 → 4：逐项目迭代周期

对每个项目，依次执行以下 4 个 Phase：

### Phase 1：添加测试用例

| 步骤 | 操作 |
|------|------|
| 1.1 | SFC 编译：`php sfc-compiler.php apps/<project>` |
| 1.2 | 生成浏览器参考：`php tools/generate_project_ref.php <project>` |

### Phase 2：构建并运行测试

```bash
build.bat <project>
php apps/<project>/auto_test.php
```

auto_test.php 执行流程：
1. 调用 `build.bat <project>`
2. 运行 `bin/<project>.exe --dump-layout` → engine_layout.json
3. 加载 `ref/browser_ref_level_0.json`
4. 展平引擎树 → 按文本匹配浏览器元素
5. 逐元素 compareElement()
6. 生成 test_log/latest_report.md

### Phase 3：分析测试报告

关注点：
- **整体通过率** — 目标 ≥95%
- **位置偏差** — Δx/Δy > 5px 需调查
- **尺寸偏差** — w=0 表示 Grid/Flex 子元素宽度未计算
- **样式属性缺失** — 浏览器有但引擎缺失的属性列表
- **属性匹配失败** — 颜色格式、边框样式等

### Phase 4：修复框架缺陷

| 差异类型 | 修复位置 |
|----------|---------|
| 布局坐标错误 | `framework/Rendering/Layout/` 策略类 |
| 样式属性缺失 | `framework/Rendering/CssMappings.php` + `Application.php` serializeRenderNode |
| 渲染效果不一致 | `framework/Rendering/GdiRenderContext.php` |
| CSS 样式表解析 | `framework/Rendering/LayoutResolver.php` + inline style 解析器 |

修复原则：**治本不治标** — 在框架层修复，不在 App.vue 加 workaround

修复后回归验证：
```bash
build.bat <project>
php apps/<project>/auto_test.php
# 对比通过率：修复项通过，原有项不回归
```

---

## 迭代退出条件

每个项目的迭代退出条件（满足其一）：
1. **通过率 ≥95%** — 所有已匹配元素的样式+位置基本正确
2. **引擎CSS属性覆盖 ≥28 项** — 核心CSS属性已全覆盖
3. **剩余差异为已知框架限制** — 如 `backdrop-filter`、`<table>` 等框架尚未实现的特性

全15个项目最终验收标准：
- 全部项目通过率 ≥90%
- 半数以上项目通过率 ≥95%
- 引擎CSS属性覆盖 ≥30 项
- 无构建失败
- 无回归（各项目通过率不低于上次报告的5%）

---

## 首批执行节点

### 第1步（当前）：创建基础设施
- 创建 `tools/shared_test_lib.php`（通用对比库）
- 创建 `tools/generate_project_ref.php`（浏览器参考生成器）
- 验证可用性

### 第2步：创建全部15个项目
- 按映射表创建项目目录和基础文件
- 每个项目包含：baseline.html / project.yml / main.php / App.vue / test_cases/level_0.html

### 第3步：从 music-player 开始逐个迭代
按复杂度从小到大，逐个项目执行 Phase 1→4

### 验证方式
每次修复后重新构建并运行自动化测试，检查通过率变化。
