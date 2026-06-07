# Px 框架 CSS 标准支持差异报告

## 移植来源
`apps/architecture-roadmap.html` (1284 行技术文档) → `apps/roadmap/` (Px Vue SFC 应用)

## 测试方法
使用 `tests/css-standards/CssTestBase.php` 的 `run_minimal_pipeline()` 渲染 VNode → `dumpRenderTree()` 快照对比

---

## 一、完全不支持的 CSS 特性（无法在 Px 中表达）

| # | CSS 特性 | HTML 使用位置 | 影响 | 严重度 |
|---|---------|-------------|------|--------|
| 1 | **CSS 变量 `var()`** | 全局 `:root` 定义 13 个变量，所有颜色/字体/间距引用 | 必须硬编码所有值，失去主题切换能力 | 🔴 高 |
| 2 | **`@media` 查询** | `prefers-color-scheme:light` 亮色主题；`max-width:768px` 响应式 | 无法实现响应式和亮色主题 | 🔴 高 |
| 3 | **`box-sizing: border-box`** | `* { box-sizing: border-box }` 全局 | padding/border 不计入 width，导致布局偏移 | 🔴 高 |
| 4 | **`::before` / `::after` 伪元素** | `.milestone::before` 竖线; `.step-item::before` 计数圆点 | 无法实现 timeline 竖线和步骤编号 | 🟡 中 |
| 5 | **CSS 计数器** | `counter-reset: step` / `counter-increment: step` / `content: counter(step)` | 无法自动编号步骤 | 🟡 中 |
| 6 | **`border-collapse: collapse`** | `table { border-collapse: collapse }` | 表格边框双线，间距不正确 | 🟡 中 |
| 7 | **CSS `columns`** | `.toc-grid { columns: 2; column-gap: 2rem }` | 必须用 flex-wrap 模拟，布局语义不同 | 🟡 中 |
| 8 | **`line-height`** | `body { line-height: 1.7 }` / `pre { line-height: 1.6 }` | 行高固定为 font-size * 1.35，无法自定义 | 🟡 中 |
| 9 | **`font-family`** | `var(--font-family)` / `var(--font-mono)` | 所有文本使用系统默认字体 | 🟡 中 |
| 10 | **`position: relative`** | `.milestone { position: relative }` / `.step-item { position: relative }` | 无法实现子元素相对定位（伪元素依赖） | 🟡 中 |
| 11 | **`scroll-behavior: smooth`** | `html { scroll-behavior: smooth }` | 无平滑滚动 | 🟢 低 |
| 12 | **`text-decoration: underline`** | `a:hover { text-decoration: underline }` | 链接无下划线悬停效果 | 🟢 低 |
| 13 | **`vertical-align: middle`** | `.badge { vertical-align: middle }` | badge 无法与文本基线对齐 | 🟢 低 |
| 14 | **`:hover` 伪类** | `tr:hover td { background }` / `a:hover { text-decoration }` | 无悬停交互效果 | 🟢 低 |

---

## 二、部分支持但有差异的 CSS 特性

| # | CSS 特性 | 期望行为 | 实际行为 | 快照证据 | 严重度 |
|---|---------|---------|---------|---------|--------|
| 15 | **`display:flex` + `align-items:center`** | 28px 圆形与文本垂直居中对齐 | 圆形 y=6, 文本 y=11，偏移 5px | Test 7: `div (0,6 28x28)` vs `span (36,11 40x18)` | 🟡 中 |
| 16 | **`flex-wrap: wrap`** | tag 小元素按行换行排列 | 每个 tag 宽度占满整行 (400px) 而非自适应 | Test 18: `div (0,0 400x0)` 每个占满宽度 | 🔴 高 |
| 17 | **`rgba()` 颜色** | 半透明背景色 | 背景色可能被解析但不一定支持 alpha 通道 | Test 15: `div (0,0 400x40)` 无 alpha 可见证据 | 🟡 中 |
| 18 | **`overflow-x:auto` 水平滚动** | maxScrollX 应为 500px | `maxScroll=0 sl=0 ov=auto` — 水平 maxScroll 为 0 | Test 19: `maxScroll=0` 但内容 800px 容器 300px | 🔴 高 |
| 19 | **`text-align:center`** | 文本水平居中 | 快照无居中偏移信息，无法验证是否真正居中 | Test 16: 位置 `(0,0)` 未体现居中 | 🟡 中 |
| 20 | **`margin: 0 auto` 居中** | body 居中 max-width:1100px | 不支持 auto margin 居中 | HTML body 依赖此特性 | 🔴 高 |

---

## 三、严重布局 Bug（快照中发现的 0 高度问题）

### 核心问题：文本节点不撑开父容器 auto-height

这是此次测试中发现的最严重问题。当 div 没有显式 `height` 时，其高度应由内容撑开，但框架中文本节点无法正确撑开父容器。

| 测试 | 快照结果 | 期望 | 根因 |
|------|---------|------|------|
| **Test 9: flex row 模拟表格** | `div (0,0 600x0)` 高度=0 | 约 30-40px | 文本行不影响 flex item 的 auto-height |
| **Test 11: 多层嵌套卡片** | 内层 `div (52,52 396x0)` 高度=0 | 约 40-60px | 嵌套文本节点不参与高度计算 |
| **Test 12: badge 标签** | `div (0,12 162x0)` 高度=0 | 约 16px | badge 文本不撑开高度 |
| **Test 13: 代码块** | `div (20,16 460x0)` 高度=0 | 约 80px | pre 多行文本不撑开高度 |
| **Test 14: timeline** | `div (32,0 600x0)` 高度=0 | 约 40px | 文本+子文本不撑开高度 |
| **Test 17: dep-box** | header `div (0,0 500x0)` + body `div (0,0 500x12)` 高度=0 | 各约 30px | 文本不撑开高度 |
| **Test 20: 完整卡片行** | 全部内容区高度=0 | 多段内容应有明显高度 | 文本不撑开高度 |

### 根因分析

在 `LayoutResolver.php` 中，flex column 方向的 auto-height 计算（第 3248-3314 行）和 block 布局的 auto-height 计算，仅统计**有显式高度的子节点**的底部坐标。纯文本节点（`#text` 或只含文本的 div/span）的高度为 0，不参与 auto-height 汇总。

具体流程：
1. 纯文本 VNode 的 `h` 初始化为 0
2. flex column auto-height 只遍历 `children`，取 `max(child.y + child.h)`
3. 文本节点的 `child.h = 0` → `child.y + child.h = child.y` → 不贡献额外高度
4. 结果：父容器高度 = 第一个子节点的 y 坐标（不含文本高度）

---

## 四、可正常工作的 CSS 特性

以下特性在渲染快照中验证工作正常：

| CSS 特性 | 快照验证 |
|---------|---------|
| `display:flex` + `flex-direction:row` | ✅ 两列正确排列 |
| `flex:1` 弹性宽度 | ✅ 等分剩余空间 |
| `gap` 间距 | ✅ 16px/12px gap 正确 |
| `display:grid` + `grid-template-columns` | ✅ 3列等宽 (192px + gap) |
| `border-left` 左边框 | ✅ 3px 左边框渲染 |
| `border-radius` 圆角 | ✅ 圆角渲染 |
| `border` 整体边框 | ✅ 1px/2px 边框 |
| `overflow-y:auto` 垂直滚动 | ✅ contentHeight=200 maxScroll=100 |
| `background` 背景色 | ✅ 各颜色正确 |
| `color` 前景色 | ✅ 文本颜色正确 |
| `font-size` / `font-weight` | ✅ 文本属性正确 |
| `padding` 内边距 | ✅ 正确偏移内容 |
| `white-space:pre` | ✅ 保留空格换行 |
| `overflow:hidden` | ✅ 裁切标记 `ov=hidden` |
| `flex-shrink:0` | ✅ 固定尺寸不收缩 |
| `border-radius:50%` 圆形 | ✅ 24px 圆形渲染 |
| `scroll container` | ✅ 垂直滚动条正常工作 |

---

## 五、修复建议优先级

### P0 — 阻塞性问题（导致页面大面积不可用）

1. **文本节点撑开 auto-height**: 修改 `LayoutResolver` 的 auto-height 计算，当子节点为文本类型且 h=0 时，使用 `font-size * 1.35` 作为最小高度贡献
2. **水平滚动 maxScroll 为 0**: 修复 `overflow-x:auto` 的 `maxScrollX` 计算，当前只计算了垂直方向的 maxScroll

### P1 — 重要功能缺失

3. **`box-sizing: border-box`**: 在 `CssMappings.php` 和 `LayoutResolver` 中实现，将 padding/border 计入 width/height
4. **`margin: 0 auto` 居中**: 在 `LayoutResolver` 的 margin 解析中支持水平 auto 居中
5. **`flex-wrap: wrap` 子元素宽度**: 修复 flex-wrap 容器中子元素宽度计算，不应占满整行

### P2 — 体验改进

6. **CSS 变量**: 在 CssMappings 中实现 `var()` 替换
7. **`line-height`**: 作为 CSS 属性支持，影响文本高度计算
8. **伪元素 `::before`/`::after`**: 在 VNode 渲染时自动生成伪元素节点

---

## 六、量化统计

| 分类 | 数量 |
|------|------|
| 完全不支持的 CSS 特性 | 14 |
| 部分支持有差异 | 6 |
| 严重布局 Bug (0 高度) | 7 个测试用例 |
| 正常工作 | 17 |
| **HTML 原始使用的 CSS 特性总数** | **37** |
| **支持率** | **46% (17/37)** |

---

*报告生成时间: 2026-06-07*
*测试工具: CssTestBase::run_minimal_pipeline() + dumpRenderTree() 快照*