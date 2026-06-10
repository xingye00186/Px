# CSS 布局分治测试实施计划

## 上下文

基于 `docs/CSS 布局分治测试.md` 方法论，在 `apps/css-test` 实现 `base.html`（1283行）的分治测试与迭代修复。

**范围确认**：核心功能子集（Flex/卡片/配色/表格/代码块等），跳过 CSS 变量/媒体查询
**自动化**：完整闭环（auto_test.php + 布局快照 + 差异对比 + 迭代修复）
**起点**：从零创建 css-test

---

## 一、文件清单

### 框架修改（3文件）
| 文件 | 改动 |
|------|------|
| `framework/Core/Application.php` | 新增 `dumpLayoutToFile()` + `serializeRenderNode()`，约+35行 |
| `framework/Rendering/CssMappings.php` | 补充 `line-height`、`font-family` 映射，约+15行 |

### 测试应用创建（~15文件）
| 文件 | 说明 |
|------|------|
| `apps/css-test/main.php` | 入口，支持 `--dump-layout` |
| `apps/css-test/project.yml` | 构建配置 |
| `apps/css-test/dep.json` | 依赖声明 |
| `apps/css-test/project.dep.yml` | 项目依赖 |
| `apps/css-test/App.vue` | 根组件 |
| `apps/css-test/components/Level0_Container.vue` | 页面容器 |
| `apps/css-test/components/Level1_Typography.vue` | 排版元素 |
| `apps/css-test/components/Level2_Cards.vue` | 卡片系统（6种） |
| `apps/css-test/components/Level3_Flex.vue` | Flex布局 |
| `apps/css-test/components/Level4_Grid.vue` | Grid布局 |
| `apps/css-test/components/Level5_Tables.vue` | 表格 |
| `apps/css-test/components/Level6_Special.vue` | 特殊组件 |
| `apps/css-test/auto_test.php` | 自动化脚本 |
| `apps/css-test/test_cases/level_0~7.html` | 浏览器参考(8个) |
| `apps/css-test/run_all.bat` | 一键运行 |

---

## 二、分治 Level 划分

| Level | 名称 | 核心CSS特性 | 来自base.html的区域 |
|-------|------|------------|---------------------|
| 0 | 页面容器 | max-width居中、h1-h4层级、段落列表 | body全局样式 |
| 1 | 排版 | code/pre/blockquote/a 样式 | 全文内联代码和引用块 |
| 2 | 卡片系统 | border-left彩色卡片、卡片通用样式 | card/card-accent/card-success等 |
| 3 | Flex布局 | display:flex、align-items、gap、flex-wrap | tier-header/badge/tag |
| 4 | Grid布局 | display:grid、grid-template-columns: 1fr 1fr、gap | grid-2/grid-3区域 |
| 5 | 表格 | table/th/td/tr:hover | 3个表格（影响文件/框架对比/差距分析） |
| 6 | 特殊组件 | milestone竖线、step-item编号、highlight、dep-box、toc | 路线图/步骤/高亮块/目录卡片 |
| 7 | 整页 | 所有组件集成 | 完整base.html |

---

## 三、实施步骤

1. **框架补丁**：Application.php 加 dumpLayoutToFile()，CssMappings.php 补 line-height 映射
2. **应用初始化**：创建 css-test 目录结构 + main.php + project.yml
3. **浏览器参考**：为每个 Level 创建独立 HTML 参考
4. **逐个Level实施**：从 Level 0 到 Level 6，每个循环：创建.vue → 编译 → dump布局快照 → 差异对比 → 修复框架 → 重测
5. **整页集成**：组装 App.vue，运行完整布局快照
6. **auto_test.php**：实现全自动快照对比+差异分析+迭代修复+报告
7. **最终验证**：全面回归 + record.md

---

## 四、通过标准
- 位置/尺寸偏差 ≤ 2px
- 颜色差异精确匹配
- 用例通过率 ≥ 90%
- 遗留问题 ≤ 3项

---

## 五、风险应对
- CSS 变量 → 替换为硬编码颜色
- counter-increment → 编译为静态序号
- box-sizing → 根节点注入 border-box
- line-height → 补充 CssMappings
- column-count → 使用 grid-2 模拟


##增加浏览器数据对照

当前 `auto_test.php` 只检查组件**是否存在**（按 type/content 字符串匹配），**没有对比 Px 引擎输出的位置/尺寸/样式与浏览器渲染结果是否一致**。

设计文档 `docs/CSS 布局分治测试.md` 明确规定了比对流程：
1. 对每个测试用例 HTML，用内置浏览器生成布局快照 → `browser_ref_X.json`
2. 用 Px 引擎生成布局快照 → `engine_layout.json`
3. **对比差异** — 逐元素比较 x/y/w/h 和样式属性

当前缺的就是**第1步**（浏览器参考数据生成）和**第3步**（实际的数值对比逻辑）。

**已有基础**（无需重复创建）：
- `test_cases/level_0~7.html` — 8个独立 HTML 浏览器参考文件
- `components/Level0~6*.vue` — 6个 Px 组件（对应 test_cases level 0~6）
- `App.vue` — 整页集成的 Px 根组件（对应 level_7 完整页面）
- `engine_layout.json` — `--dump-layout` 输出的引擎布局快照（4718 行）
- `auto_test.php` — 已有脚本框架（构建→运行→验证）
- `main.php` — 已支持 `--dump-layout` 参数

---

## 一、浏览器参考数据生成

### 方案：Edge headless + --dump-dom

利用已安装的 Edge (`C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe`) 的 headless 模式提取布局数据：

1. 为每个 `test_cases/level_X.html` 生成临时包装 HTML：
   - 嵌入原始 HTML 内容
   - 末尾注入 `<script>`：遍历 `querySelectorAll('[style]')` 或根容器下的所有 div，调用 `getBoundingClientRect()` 收集坐标，调用 `getComputedStyle()` 收集样式
   - 将结果 JSON 写入 `<textarea id="layout-output" style="position:absolute;left:-9999px">`
2. 运行 Edge headless with `--dump-dom` 输出渲染后 DOM
3. 解析输出提取 `<textarea id="layout-output">` 的文本内容作为 JSON
4. 保存为 `apps/css-test/ref/browser_ref_level_X.json`

### 输出格式

```json
{
  "browser": "Edge 149.0",
  "viewport": {"width": 1280, "height": 3000},
  "level": 0,
  "elements": [
    {
      "tag": "div",
      "x": 32, "y": 32, "w": 1036, "h": 37,
      "text": "VueCalc 框架架构演进路线图",
      "styles": {
        "font-size": "28px",
        "font-weight": "700",
        "color": "#e6edf3"
      }
    }
  ]
}
```

### 创建文件

| 文件 | 说明 |
|------|------|
| `tools/generate_browser_refs.php` | 主控：对每个 level_X.html 生成包装页 → 用 Edge headless 渲染 → 提取 JSON → 清理 |
| `tools/dump_layout.js` | JavaScript 布局提取函数：遍历 DOM、收集 getBoundingClientRect() + getComputedStyle() |
| `apps/css-test/ref/` | 存放生成的浏览器参考 JSON 文件 |

---

## 二、auto_test.php 改造

将当前 Step 3 从简单的存在性检查改为**数值对比验证**。

### 新增对比步骤

```
Step 3: 加载浏览器参考数据
  - 遍历 ref/browser_ref_level_0~7.json
  - 解析为元素列表
Step 4: 逐元素对比
  - 将 engine_layout.json 展平为元素列表（递归遍历 + 按内容/位置匹配）
  - 匹配引擎元素与浏览器参考元素
    - 主要匹配键：text 文本内容（最可靠）
    - 次要匹配键：type + 父级关系
  - 对比每个匹配对: x/y/w/h/样式值
  - 允许容差: 位置/尺寸 ≤ 2px
Step 5: 生成详细差异报告
```

### 匹配策略

引擎输出的 `engine_layout.json` 是完整树结构（含所有 6 级子组件），而浏览器参考是分 Level 独立的。匹配时：
1. 从 engine_layout.json 中按 `content`（文本内容）索引所有叶子节点
2. 从 browser_ref 中按 `text` 索引所有有文本的元素
3. 匹配文本内容相同的元素对
4. 对比 x/y/w/h/color/fontSize 等数值
5. 样式值需要做单位换算（浏览器返回 `"28px"`，引擎存 `28`）和颜色格式映射

### 报告格式

```
[PASS] Level-2 Cards: "强调卡片" — x(32/32) y(382/382) w(1036/1036) h(84/84)
[FAIL] Level-2 Cards: "强调卡片左边框" — borderLeftColor: engine=0x30363D, browser=#7c3aed
  possible cause: border-left 未覆盖 border 简写的默认颜色
```

### 修改文件

| 文件 | 改动 |
|------|------|
| `apps/css-test/auto_test.php` | 替换 Step 3：添加参考数据加载、元素平铺匹配、逐项对比、差异报告 |

---

## 三、逐元素对比的核心逻辑

### 颜色归一化

引擎输出 GDI 颜色格式为 `0xRRGGBB`（整数，如 `15986150 = 0xF0E6ED`），浏览器输出 CSS 格式（`#e6edf3` 或 `rgb(r,g,b)`）。需要双向转换。

### 样式单位解析

| 引擎格式 | 浏览器格式 | 归一化 |
|---------|-----------|--------|
| `fontSize: 28` | `font-size: "28px"` | 统一为 int |
| `fg: 15986150` | `color: "#e6edf3"` | 统一为 0xRRGGBB |
| `bold: 1` | `font-weight: "700"` | 700=bold |
| `w: 1036` | `width: "1036px"` | 统一为 int |

### 容差策略

| 属性 | 容差 | 说明 |
|------|------|------|
| x, y | ±2px | 子像素舍入差异 |
| w, h | ±2px | 滚动条/边框含入差异 |
| color | 精确 | 颜色必须精确匹配 |
| fontSize | ±0 | 字号必须精确 |

---

## 四、实施步骤

### Task 1: 创建浏览器参考生成器
- 创建 `tools/dump_layout.js` — JS 布局提取函数
- 创建 `tools/generate_browser_refs.php` — 对每个 level_X.html 生成包装、Edge headless 渲染、提取 JSON
- 创建 `apps/css-test/ref/` 目录
- 运行一次生成全部参考文件
- 验证参考文件完整性（每个 Level 的元素数量是否合理）

### Task 2: 改造 auto_test.php
- 添加参考数据加载函数 `loadBrowserRefs()`
- 添加引擎布局展平函数 `flattenEngineLayout()`
- 添加元素匹配函数 `matchElements(browser, engine)`
- 添加对比函数 `compareElement(browserEl, engineEl)`
- 添加报告生成函数 `generateDiffReport()`
- 替换当前 Step 3 的简单存在性检查

### Task 3: 运行验证
- 构建 + dump-layout + auto_test.php 完整流程
- 记录所有 PASS/FAIL
- 对 FAIL 项目分类（引擎 Bug / 参考偏差 / 匹配失败）
- 修复已知的 CssMappings border-left 问题（已在之前对话中修复）

---

## 五、验证方法

```bash
cd f:\work\Px
php apps\css-test\auto_test.php
```

运行后应输出类似：
```
========================================
  CSS Layout 分治测试 - css-test
========================================

Step 1: 编译构建
----------------------------------------
  [PASS] 构建成功

Step 2: 运行 --dump-layout 导出布局
----------------------------------------
  [INFO] exe: ...\bin\css-test.exe
  [PASS] engine_layout.json 已生成 (xxxxx bytes)

Step 3: 加载浏览器参考数据
----------------------------------------
  [INFO] 加载 ref/browser_ref_level_0.json ... 42 elements
  [INFO] 加载 ref/browser_ref_level_1.json ... 18 elements
  [INFO] 加载 ref/browser_ref_level_2.json ... 30 elements
  ...

Step 4: 逐元素对比验证
----------------------------------------
  [PASS] Level 0: "VueCalc 框架架构演进路线图" — x(32/32) y(32/32) w(1036/1036) h(37/37)
  [PASS] Level 2: "强调卡片" — x(32/32) y(382/382) w(1036/1036) h(84/84)
  [FAIL] Level 2: "强调卡片左边框" — borderLeftColor: engine=0x30363D, browser=#7c3aed
  ...

========================================
  测试完成
========================================
  通过: 180 / 失败: 5
  通过率: 97.3%
```
