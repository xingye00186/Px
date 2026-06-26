# CSS 缺失特性迭代实现计划

## 概述

针对 `apps/css-test` 项目，当前有 32 个通过的 case（case-001~032，含归档基线），以及 18 个标记为"特性缺口"（待处理/已评估）的新增 case（case-033~050）。

**核心任务**：逐个实现缺失的 CSS 特性，每次迭代严格遵循：
1. 定位根因（分析引擎为何不支持该CSS属性）
2. 修复（按CSS规范+浏览器算法实现）
3. 增强测试（更新vue/html模板确认覆盖边界情况）
4. 验证（运行测试pipeline + 截图对比，努力缩小差异）
5. 回归测试（运行全部case确保无退化）
6. 更新问题清单
7. 提交git
8. 进入下一个特性

---

## 阶段 0：环境准备与基线建立

### Task 0.1：编译 SFC
```bash
php sfc-compiler.php apps/css-test/App.vue
```

### Task 0.2：构建 exe
```bash
.\build.bat css-test
```

### Task 0.3：全量测试（建立当前基线）
```bash
php apps/css-test/test_pipeline.php
```

### Task 0.4：查看测试报告
- 检查哪些case通过，哪些失败
- 确认现有32个case无退化
- 检查case-033~050的当前状态

---

## 阶段 1：渲染层特性实现（纯视觉，不涉及布局）

这些特性只需要修改渲染层（GdiRenderContext / SkiaRenderContext / VNodeRenderer），不需要改动LayoutResolver。

### 迭代 1.1：text-shadow（case-033, B-045）
**CSS规范**：CSS Text Decoration Module L3 §7
**根因**：CssMappings已经解析text-shadow属性值，但VNodeRenderer/drawElement未传递给渲染上下文，GdiRenderContext/SkiaRenderContext的drawText方法不支持阴影绘制。
**修复文件**：
- `framework/Rendering/CssMappings.php` — 确认text-shadow属性映射存在
- `framework/Rendering/VNodeRenderer.php` — 在drawElement中将text-shadow属性传递
- `framework/Rendering/GdiRenderContext.php` — drawText实现阴影文字（SetTextColor+偏移绘制）
- `framework/Rendering/SkiaRenderContext.php` — drawText实现阴影文字（Skia paint + 偏移）
**验证**：`php apps/css-test/test_pipeline.php --case=case-033-text-shadow`

### 迭代 1.2：text-decoration（case-045, B-057）
**CSS规范**：CSS Text Decoration Module L3 §2-5
**根因**：text-decoration属性值被解析但从未传递给渲染层，GDI无绘制修饰线的逻辑。
**修复文件**：
- `framework/Rendering/CssMappings.php` — 添加text-decoration解析
- `framework/Rendering/VNodeRenderer.php` — 传递text-decoration
- `framework/Rendering/GdiRenderContext.php` — drawText后绘制underline/overline/line-through
- `framework/Rendering/SkiaRenderContext.php` — 类似实现

### 迭代 1.3：letter-spacing / word-spacing（case-034, B-046）
**CSS规范**：CSS Text L3 §4
**根因**：间距值被解析但不影响文本测量和绘制，skia_render.cc/text测量不接收间距参数。
**修复文件**：
- `framework/Rendering/CssMappings.php` — 确认letter-spacing/word-spacing映射
- `cpp/skia_render.cc` — sk_measure_text_width / sk_draw_text 接收间距参数
- `framework/Rendering/GdiRenderContext.php` — 绘制时应用字符间距

### 迭代 1.4：text-indent / text-transform（case-035, B-047）
**CSS规范**：CSS Text L3 §2 / §3
**根因**：text-indent被解析但不影响首行偏移；text-transform被解析但不执行大小写转换。
**修复文件**：
- `framework/Rendering/VNodeRenderer.php` — 应用text-indent到首行x偏移
- `framework/Rendering/GdiRenderContext.php` — text-transform大小写转换逻辑

### 迭代 1.5：background-repeat（case-036, B-048）
**CSS规范**：CSS Backgrounds L3 §3.4
**根因**：背景图平铺模式参数未被渲染层使用，始终为拉伸行为。
**修复文件**：
- `framework/Rendering/GdiRenderContext.php` — fillRect/fillImage支持repeat/no-repeat/repeat-x/repeat-y
- `framework/Rendering/SkiaRenderContext.php` — 类似实现

### 迭代 1.6：background-clip / background-origin（case-041, B-053）
**CSS规范**：CSS Backgrounds L3 §3.7-3.8
**根因**：背景裁剪区域和原点位置参数被解析但不生效。
**修复文件**：
- `framework/Rendering/GdiRenderContext.php` — 裁剪背景绘制区域

### 迭代 1.7：font-variant / font-stretch（case-042, B-054）
**CSS规范**：CSS Fonts L3 §5
**根因**：small-caps/all-small-caps等字体变体未在渲染层实现。
**修复文件**：
- `framework/Rendering/GdiRenderContext.php` — 实现小大写字母转换
- （注意：AOT环境+GD可能限制功能）

### 迭代 1.8：background-attachment（case-044, B-056）
**CSS规范**：CSS Backgrounds L3 §3.6
**根因**：scroll/fixed/local背景附着参数被解析但不影响滚动行为。
**修复文件**：
- `framework/Rendering/VNodeRenderer.php` — 处理滚动容器背景附着

### 迭代 1.9：word-wrap / overflow-wrap（case-040, B-052）
**CSS规范**：CSS Text L3 §6
**根因**：break-word强制换行长单词逻辑缺失。
**修复文件**：
- `framework/Rendering/VNodeRenderer.php` — 文本换行逻辑添加break-word支持

---

## 阶段 2：布局+渲染混合特性

### 迭代 2.1：vertical-align（case-039, B-051）
**CSS规范**：CSS Inline Layout L3 §2
**根因**：vertical-align值被解析但不影响内联元素Y轴定位。
**修复文件**：
- `framework/Rendering/Layout/BlockLayoutStrategy.php` — 内联元素基线对齐
- `framework/Rendering/VNodeRenderer.php` — 渲染偏移

### 迭代 2.2：direction:rtl（case-037, B-049）⚠️ 当前崩溃
**CSS规范**：CSS Writing Modes L3 §3
**根因**：direction:rtl + unicode-bidi:embed + Arabic文本导致布局或渲染层崩溃。
**修复文件**：
- `framework/Rendering/LayoutResolver.php` 或 `VNodeRenderer.php` — RTL文本方向支持
- 需要先定位崩溃根因（可能是template-parser或measureText）

### 迭代 2.3：list-style（case-038, B-050）⚠️ 当前崩溃
**CSS规范**：CSS Lists L3 §3-4
**根因**：ul/ol/li标签不被引擎识别。
**修复文件**：
- `framework/compiler/template-parser.php` — 添加ul/ol/li标签支持
- `framework/Rendering/VNodeRenderer.php` — 列表项标记渲染

### 迭代 2.4：object-fit / object-position（case-047, B-059）
**CSS规范**：CSS Images L3 §5
**根因**：替换元素（img）的cover/contain/fill等填充模式未实现。
**修复文件**：
- `framework/Rendering/VNodeRenderer.php` — 根据object-fit调整图片绘制区域
- `framework/Rendering/GdiRenderContext.php` — 支持对象填充模式

### 迭代 2.5：appearance / cursor（case-046, B-058）
**CSS规范**：CSS UI L4 §4 / §6
**根因**：appearance值不改变UI控件样式，cursor值不改变光标形状。
**修复文件**：
- `framework/Platform/Win32Platform.php` — cursor光标切换
- `framework/Rendering/VNodeRenderer.php` — appearance样式映射

### 迭代 2.6：resize / outline-offset（case-043, B-055）
**CSS规范**：CSS UI L4 §5 / §4
**根因**：resize无交互实现，outline-offset未参与布局。
**修复文件**：
- `framework/Rendering/VNodeRenderer.php` — outline-offset偏移
- `framework/Platform/Win32Platform.php` — resize拖拽手柄

---

## 阶段 3：复杂布局特性

### 迭代 3.1：表格属性（case-048, B-060）
**CSS规范**：CSS Table L3
**根因**：border-collapse/border-spacing/table-layout/caption-side完全无实现。
**修复文件**：
- `framework/Rendering/Layout/` — 新增TableLayoutStrategy
- `framework/Rendering/VNodeRenderer.php` — 表格元素类型处理

### 迭代 3.2：多列布局（case-049, B-061）
**CSS规范**：CSS Multi-column L1
**根因**：column-count/column-rule/column-width/column-gap完全无实现。
**修复文件**：
- `framework/Rendering/Layout/` — 新增MultiColumnLayoutStrategy

### 迭代 3.3：text-emphasis / content / quotes（case-050, B-062）
**CSS规范**：CSS Text Decoration L3 §8 / CSS Generated Content L3
**根因**：文字强调标记和生成内容完全无实现。
**修复文件**：
- `framework/Rendering/VNodeRenderer.php` — 强调标记渲染
- `framework/Rendering/GdiRenderContext.php` — 强调标记绘制

---

## 迭代周期定义（每个迭代均遵循）

对于每个特性迭代，执行以下步骤：

```
Step 1 定位根因
  ├── 搜索框架代码确认该CSS属性的现状
  ├── 阅读CSS规范确定标准行为
  ├── 检查browser_ref（Edge输出）作为参考标准
  └── 定位需要修改的文件和修改点

Step 2 修复
  ├── 按CSS规范实现
  ├── 遵循SOLID原则
  ├── 最小化改动范围
  ├── AOT兼容性检查（不使用??操作符等）
  └── 尽量遵循浏览器算法

Step 3 增强测试
  ├── 确保vue模板和html参考一致
  └── 添加足够覆盖边界情况的测试内容

Step 4 验证
  ├── 重新构建（sfc-compiler + build.bat）
  ├── 单独运行该case
  └── 检查对比报告，努力缩小差异

Step 5 回归测试
  ├── 运行全部case
  └── 确认无退化

Step 6 更新问题清单
  ├── 更新docs/01-问题清单.md中对应条目状态
  └── 记录修复详情、文件变更

Step 7 提交
  ├── git add + git commit
  └── commit信息包含特性名和相关case编号
```

---

## 关键文件索引

### 框架渲染层
| 文件 | 路径 | 职责 |
|------|------|------|
| CssMappings.php | `framework/Rendering/CssMappings.php` | CSS属性→GDI映射表，PROPERTY_MAP定义 |
| VNodeRenderer.php | `framework/Rendering/VNodeRenderer.php` | RenderNode遍历，生成元素描述 |
| GdiRenderContext.php | `framework/Rendering/GdiRenderContext.php` | GDI绘制原语（fillRect/drawText/drawButton等） |
| SkiaRenderContext.php | `framework/Rendering/SkiaRenderContext.php` | Skia绘制原语 |
| RenderContext.php | `framework/Rendering/RenderContext.php` | 渲染上下文抽象基类 |
| Application.php | `framework/Core/Application.php` | 布局序列化，事件路由 |
| LayoutResolver.php | `framework/Rendering/LayoutResolver.php` | 布局解析入口 |
| RenderTreeManager.php | `framework/Rendering/RenderTreeManager.php` | VNode→RenderNode转换 |

### 测试应用
| 文件 | 路径 | 职责 |
|------|------|------|
| test_pipeline.php | `apps/css-test/test_pipeline.php` | 测试编排入口 |
| 问题清单 | `apps/css-test/docs/01-问题清单.md` | 统一的Bug台账 |
| 最新报告 | `apps/css-test/docs/02-测试报告/最新报告.md` | 当前测试结果 |

---

## 优先级策略

1. **先易后难** — 先修复纯渲染层特性（阶段1），再处理布局相关特性（阶段2），最后处理复杂布局特性（阶段3）
2. **崩溃优先** — 优先修复当前导致崩溃的特性（direction:rtl case-037, list-style case-038）
3. **视觉优先** — 纯视觉效果的特性实现后可立即通过截图对比验证

## 开始执行

第一阶段先从 **渲染层特性** 开始，具体从 **text-decoration** 开始，因为它是纯视觉效果，不涉及布局改动，实现后可立即观察效果并通过截图验证。
