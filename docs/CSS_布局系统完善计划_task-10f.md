# CSS 布局系统完善计划（严格 CSS 标准版）

## 背景

根据 `D:\Px\docs\布局系统完善2026061001.txt` 逐项对比框架布局代码现状，并经过 W3C CSS 规范严格审查，发现多处与标准不符的问题。以下计划按优先级分批实施，每项均标注具体 CSS 规范引用。

---

## 一、P0：严重缺陷（立即修复）

### 1. 绝对定位百分比偏移支持 — `left: 50%` 被静默当作 50px

**CSS 规范**：CSS 2.2 §10.3.7 / §10.6.4 — 绝对定位元素的 `left`/`right` 百分比相对于包含块宽度，`top`/`bottom` 百分比相对于包含块高度。

**严重性**：`parsePixels("50%")` 返回 50（当作像素值），而非 0。**这意味着 `left: 50%` 被静默解释为 `left: 50px`，是完全错误的布局行为。**

**文件与修改**：

**A) `framework/Rendering/CssMappings.php` — parseInlineStyle (L509-L522)**
- 在 `$pctMap` 中新增四个属性：
  ```
  'left'   => 'leftPercent',
  'top'    => 'topPercent',
  'right'  => 'rightPercent',
  'bottom' => 'bottomPercent',
  ```
  当前 `$pctMap` 完全遗漏了 `left/top/right/bottom`。

**B) `framework/Rendering/Layout/AbsolutePositioning.php` (L44-L46, L88-L91)**
- 当前 `$left = (int)($style['left'] ?? 0)` 直接将所有值转 int，丢失百分比语义
- 改为：
  ```php
  $left = PercentResolver::resolvePercent($style, 'left', 'leftPercent', $ancestorW);
  $top  = PercentResolver::resolvePercent($style, 'top', 'topPercent', $ancestorH);
  ```
  - 注意：`$ancestorW` 是定位祖先的 padding box 宽度（CSS Positioned Layout §3.1）
  - 对于 `position:fixed`，使用 `$viewportW`/`$viewportH`

- `$right` 和 `$bottom` 同理
- 当 `left` + `right` 同时为百分比且 `width: auto` 时，推导 `width = containingBlock - left% - right%`
- 注意 CSS §10.5：若包含块无显式高度，`top: 50%` 按 `auto`（0）处理

---

## 二、P1：标准缺失（次优先）

### 2. 命中测试滚动偏移修复

**CSS 规范**：CSSOM View Module §7.1 — `scrollLeft`/`scrollTop` 表示内容被滚动的像素数；命中测试坐标应相对于视口。

**当前问题**：`RenderTreeManager::hitTestRecursive` (L646-L687) 和 `findScrollContainerRecursive` (L702-L742) 未将视口坐标转换为文档布局坐标。

**修改**：`framework/Rendering/RenderTreeManager.php`

**关键细节（纠正方向）**：
```
鼠标视口坐标 (vx, vy)
  → 元素文档坐标 (ex, ey) = 元素布局 x/y
  → 元素视口坐标 (vx_el, vy_el) = (ex - scrollLeft, ey - scrollTop)
  → 所以对于点击坐标 (cx, cy)：
    子文档坐标 = cx + scrollLeft, cy + scrollTop
```
- 在 `hitTestRecursive` 中，递归子节点前创建局部副本 `$childX = $x; $childY = $y;`，
  若 `$node->isScrollContainer`，则 `$childX += $node->scrollLeft; $childY += $node->scrollTop`
- 对每个子节点传入调整后的 `$childX, $childY`
- 检查自身时仍使用原始 `$x, $y`（节点自身坐标不随滚动偏移）
- `findScrollContainerRecursive` 做同样修改

**边缘情况处理**：
- 嵌套滚动容器：递归调用自然支持多层偏移叠加
- `position:fixed` 子元素不参与滚动偏移：需在递归前跳过 fixed 子元素的偏移
- `overflow:hidden` 元素不是滚动容器，不应调整坐标（当前 `isScrollContainer` 只对 `auto/scroll` 设 true，正确）
- `transform` 偏移（已有 `$hitOffX/$hitOffY` 在自身检查处处理）：transform 在 scroll 之后应用，当前顺序正确

### 3. 块级外边距折叠 (Margin Collapsing)

**CSS 规范**：CSS 2.2 §8.3.1 — 同一 BFC 中相邻块级盒的垂直外边距折叠为其中较大者。

**当前问题**：`BlockLayoutStrategy` 中 auto-stack 直接累加 `$ch->visualH + $mBottom`，下一个子元素从 `$stackY + $mTop` 开始，导致垂直 margin 累加而非折叠。

**修改**：`framework/Rendering/Layout/BlockLayoutStrategy.php`

**兄弟折叠**（§8.3.1 第 2 条）：
- 跟踪前一个块级子元素的 `marginBottom`
- 当前子元素的 `$effectiveTopMargin = max($currentChild->marginTop, $previousChild->marginBottom)`
- 推进 stack：`$stackY += max($currentChild->visualH, 0)`（不额外加 margin）
- 仅对 `display:block` 的普通流子元素生效，跳过 `flex`/`grid`/`absolute`/`fixed`/`inline-block`

**父子折叠**（§8.3.1 第 4 条）：
- 若父容器无 `border`/`padding`/`BFC`，第一个子元素的 `margin-top` 与父容器的 `margin-top` 折叠
- 最后一个子元素的 `margin-bottom` 与父容器的 `margin-bottom` 折叠
- 实现：在 auto-stack 开始时，若父容器无 border-top/padding-top，则 `$stackY += max(0, $firstChild->marginTop - $parentMarginTop)`

**空元素自折叠**（§8.3.1 第 3 条）：
- 无 border/padding/content/height 的空块元素，其 `margin-top` 和 `margin-bottom` 互相折叠
- 多个相邻空元素的 margin 可以穿透折叠

**负边距**（§8.3.1 第 5 条）：
- 折叠结果为 `max(positive_values) + min(negative_values)`
- 例如：margin-bottom: 30px 与 margin-top: -10px 折叠 → 20px

**BFC 边界**：
- `overflow:hidden/auto/scroll` 创建新 BFC，阻止 margin 折叠
- `display:flex/grid/inline-block` 创建新 BFC（但容器内部仍折叠）

### 4. 增强脏标记传播

**CSS 规范**：CSS 2.2 §10.5 — `height: auto` 的块级容器高度由内容决定。子节点尺寸变化时父节点必须重新计算。

**当前问题**：当前 dirty child 检测仅在 flex/grid 的 clean path 中（`LayoutResolver` L414-L425），block 容器完全被忽略。且 dirty 传播未考虑父节点是否 auto-sizing。

**修改**：`framework/Rendering/LayoutResolver.php`

- **block 容器补充 dirty child 检测**：在 clean path block 分支中，遍历子节点检查 `layoutDirty`，若任一子节点脏且父节点为 auto-height，强制父节点进入脏路径
- **auto-sizing 守卫**：仅在父节点 `$hasExplicitW === false` 或 `$hasExplicitH === false` 时触发传播
- **百分比级联**：父节点 `contentWidth` 变化后，子节点百分比宽度需要重新解析（`PercentResolver::resolvePercent` 依赖 `parentSize`）
- **防御循环**：当前 500-520 深度保护保留，但正常布局链式反应不应触达此限制

---

## 三、P2：标准增强（后续实施）

### 5. Flex 布局补充 align-content + flex-basis:content

**CSS 规范**：CSS Flexbox §8.4（align-content）、§7.2.3（flex-basis）

**align-content**（`framework/Rendering/Layout/FlexLayoutStrategy.php`）：
- 在多行（`flex-wrap: wrap`）容器中，跨行循环结束后，计算交叉轴剩余空间
- 不同关键字的行为：
  - `stretch`（默认）：行拉伸填满剩余空间（行高增加）
  - `center`：所有行居中
  - `flex-start`：行靠交叉轴起点（当前行为）
  - `flex-end`：行靠交叉轴终点
  - `space-between` / `space-around` / `space-evenly`：均匀分布
- 单行容器（nowrap）时 `align-content` 不生效
- 与 `gap` 的交互：gap 已在行间计入，`align-content` 分布剩余空间不应重复计算

**flex-basis:content**（`CssValueParser::parseFlexValue` + `FlexLayoutStrategy::applyFlexBasis`）：
- 当前 `parseFlexValue` 将 `'content'` 等同于 `initial`（`['grow'=>0, 'shrink'=>1, 'basis'=>'auto']`），不符合规范
- 修正：`'content'` 应单独标记，在 `applyFlexBasis` 中忽略 `width`/`height` 属性，始终使用内容测量尺寸
- 与 `auto` 的区别：`auto` 优先使用 `width`/`height`，没设才用内容；`content` 总是用内容

### 6. Grid 布局完善

**CSS 规范**：CSS Grid Layout Level 1 §7.3（grid-template-areas）、§7.4（grid-auto-rows）、§6（minmax）

**grid-template-areas**：
- 在 `CssMappings::PROPERTY_MAP` 注册 `'grid-template-areas'` 属性
- 解析 ASCII-art 字符串生成 `[areaName => (rowStart, colStart, rowEnd, colEnd)]` 映射
- 子项目用 `grid-area: name` 引用命名区域定位

**grid-auto-rows**：
- 在 `CssMappings::PROPERTY_MAP` 注册 `'grid-auto-rows'`
- `GridLayoutStrategy` 中创建隐式行时使用 `grid-auto-rows` 值替代硬编码 60px

**minmax()**：
- `parseGridTemplateValue` 中将 `minmax()` 解析扩展到所有 track 定义（不仅是 auto-fill）
- 支持 `minmax(100px, 1fr)`, `minmax(0, auto)` 等常见组合
- `$explicitColWidths` 中应支持 `minmax()` 作为完整轨道定义

---

## 四、实施顺序与依赖关系

| 步骤 | 内容 | 涉及文件 | 依赖 |
|------|------|----------|------|
| 1 | 绝对定位百分比偏移（parser + layout） | `CssMappings.php`, `AbsolutePositioning.php` | 无 |
| 2 | 命中测试滚动偏移 | `RenderTreeManager.php` | 无 |
| 3 | 脏标记传播增强 | `LayoutResolver.php` | 无 |
| 4 | 块级外边距折叠 | `BlockLayoutStrategy.php` | 步骤 3 |
| 5 | Flex align-content + flex-basis:content | `FlexLayoutStrategy.php`, `CssValueParser.php` | 无 |
| 6 | Grid 完善 | `GridLayoutStrategy.php`, `CssMappings.php` | 无 |
| 7 | 运行测试验证 | `tests/unit/Layout/*` | 全部 |

---

## 五、验证方案

1. **单元测试**：运行并确保全部测试通过
   ```
   php tests/unit/LayoutResolverTest.php
   php tests/unit/Layout/FlexLayoutTest.php
   php tests/unit/Layout/GridLayoutTest.php
   php tests/unit/Layout/BlockLayoutTest.php
   php tests/unit/Layout/ComboLayoutTest.php
   php tests/unit/Layout/PositionLayoutTest.php
   php tests/unit/Layout/ScrollLayoutTest.php
   ```

2. **集成测试**：
   ```
   php tests/unit/BilibiliLayoutTest.php
   php tests/unit/LayoutEngineTest.php
   ```

3. **集成测试**：
   ```
   php tests/unit/BilibiliLayoutTest.php
   php tests/unit/LayoutEngineTest.php
   ```

4. **calculator-ng 应用构建与运行测试**：
   - 构建 calculator-ng 应用并确认编译通过
   - 运行 calculator-ng 的布局截图/快照测试（如有）
   - 验证 CSS Grid、Flex 布局在 calculator-ng 中的显示正确性

5. **特定验证用例**：
   - **绝对定位百分比**：构造 `left: 50%; width: 100px` 在 400px 宽定位祖先中 → x=200
   - **命中测试滚动**：构造 200px 高滚动容器，scrollTop=50，子元素位于 y=120 → 点击视口 y=60 应命中
   - **外边距折叠**：两个 block 兄弟各有 margin-top:20 和 margin-bottom:30 → 间距=30（取max），非 50
   - **脏标记传播**：flex-grow 子节点尺寸变化后读取父节点 contentHeight 应为更新后值

---

## 六、CSS 规范引用汇总

| 项目 | 规范编号 |
|------|---------|
| 绝对定位百分比 | CSS 2.2 §10.3.7, §10.6.4, §10.5 |
| 定位包含块 | CSS Positioned Layout §3.1 |
| 命中测试 | CSSOM View Module §7.1 |
| 外边距折叠 | CSS 2.2 §8.3.1 |
| 块格式化上下文 | CSS 2.2 §9.4.1 |
| BFC 创建规则 | CSS 2.2 §9.4.1, CSS Display Level 3 |
| 负边距折叠 | CSS 2.2 §8.3.1 第 5 条 |
| auto height | CSS 2.2 §10.5, §10.6 |
| Flexbox align-content | CSS Flexbox §8.4 |
| Flexbox flex-basis | CSS Flexbox §7.2.3 |
| Grid grid-template-areas | CSS Grid §7.3 |
| Grid auto rows | CSS Grid §7.4 |
| Grid minmax() | CSS Grid §6 |

---

## 七、不在此计划中的项目

- **内联格式化上下文（Inline Formatting Context）** — CSS 2.2 §9.4.2，涉及新增策略类，影响面大，单独实施
- **单位系统扩展（em/rem/vw/vh）** — CSS Values Level 3，纯新增功能，不影响现有逻辑
- **滚动条占位布局** — CSS Overflow Level 3，可能破坏现有 UI，需评估
- **样式选择器级联增强** — CSS Cascading Level 4，独立于布局引擎
