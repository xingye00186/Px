# CSS 布局引擎路线图

## Context

Px 框架的 CSS 属性解析（CssMappings）已经支持 border-radius、padding、margin、opacity、box-shadow、border 等属性的解析，但这些属性在 LayoutResolver（布局计算）和 GdiRenderContext（GDI 绘制）中**完全未被使用**。用户的需求是让这些属性在渲染管线中真正生效，同时对样式-布局引擎做适当的职责分离。

## 设计原则

1. **每层只做自己的事**：LayoutResolver 管坐标和盒模型偏移，VNodeRenderer 管元素收集和裁切，GdiRenderContext 管 GDI 绘制
2. **computedStyle 是唯一接口**：所有 CSS 属性都经由 StyleResolver 合并后存入 VNode.computedStyle，各层从中读取
3. **增量实现**：按属性依赖关系分 5 个 Phase 逐步推进
4. **AOT 兼容**：所有新增代码遵循无动态属性/无闭包/无 eval 规则

## Phase 1: border-radius（圆角矩形）

**目标**：让 `border-radius: Npx` 在 GDI 渲染中生效。

### 修改文件

#### 1.1 C++ 层 `d:/Px/cpp/vue_calc.cc`
- 新增 `php_vue_draw_round_rect(Int hdc, Int x, Int y, Int w, Int h, Int radius, Int color)`：
  - 使用 GDI `CreateRoundRectRgn` 创建圆角区域
  - 用 `FillRgn` 填充（替代 FillRect）
  - 删除 region 对象
- 或者使用 `RoundRect` API（HPEN/HBRUSH 方式）

#### 1.2 PHP stub `d:/Px/stub/vue_calc.stub.php`
- 新增 `function vue_draw_round_rect(int $hdc, int $x, int $y, int $w, int $h, int $radius, int $color): void {}`

#### 1.3 GdiRenderContext `d:/Px/framework/Rendering/GdiRenderContext.php`
- `drawElement()` 的 `'rect'` case 中：检查 `$el['borderRadius'] ?? 0`，> 0 时调用 `vue_draw_round_rect()` 而非 `vue_fill_rect()`
- `drawElement()` 新增 `'round-rect'` case（可选，更清晰的类型分发）

#### 1.4 VNodeRenderer `d:/Px/framework/Rendering/VNodeRenderer.php`
- `makeDivElement()` 和 `makeButtonElement()` 中：从 `$style['borderRadius']` 读取 borderRadius
- 在生成的 rect/button 元素描述中传入 `'borderRadius' => $radius`

### 验证方法
1. 在测试应用的 CSS class 或 inline style 中添加 `border-radius: 8px`
2. 构建并运行：`build.bat test --run`
3. 观察圆角矩形是否正确渲染（无锯齿、无溢出）

---

## Phase 2: padding / margin（内边距和外边距）

**目标**：让 `padding` 和 `margin` 在 LayoutResolver 的布局计算中生效，影响元素的实际定位和尺寸。

### 关键设计决策

| 属性 | 影响范围 | 实现方式 |
|------|---------|---------|
| margin | 元素外部间距 | 在 resolveBlockLayout/flex/grid 中添加偏移 |
| padding | 容器内部间距 | 缩小内容区域，子元素相对 padding-box 定位 |

### 修改文件

#### 2.1 CssMappings `d:/Px/framework/Rendering/CssMappings.php`
- PROPERTY_MAP 和 INLINE_PROPERTY_MAP 中把 `padding`/`margin` 从单值扩展为四方向：
  - `padding-top` / `padding-right` / `padding-bottom` / `padding-left`
  - `margin-top` / `margin-right` / `margin-bottom` / `margin-left`
- 兼容旧单值写法：`padding: 10px` → 四个方向都是 10
- 保留 `padding`/`margin` 简写解析（四个值用空格分隔）

#### 2.2 LayoutResolver `d:/Px/framework/Rendering/LayoutResolver.php`

**margin 处理**（在 `resolveNode()` 或各布局模式中）：
- 计算子元素位置时，X/Y 方向加上对应的 margin 值
- 不影响容器本身的 computedStyle，只在定位时偏移

**padding 处理**（在 `resolveBlockLayout()` 中）：
- 滚动容器的 `childOffsetX/Y` 加上 `paddingLeft/paddingTop`
- 容器的可用宽度/高度减去 paddingLeft+paddingRight / paddingTop+paddingBottom
- `auto-stack` 的 `containerW` 需要减去 padding 总和
- `contentHeight`/`contentWidth` 计算以 padding-box 为基准

**flex 布局**：类似处理，main axis 和 cross axis 上分别应用 padding

#### 2.3 GdiRenderContext `d:/Px/framework/Rendering/GdiRenderContext.php`
- 背景绘制：容器的 bg 覆盖 margin+padding+border 区域（全区域）
- 初步：不做背景扩展，保持现状（背景只填充 w×h 区域）

### 验证方法
1. 现有 `LayoutResolverTest.php` 增加 padding/margin 测试用例
2. 手动 `php tests/unit/LayoutResolverTest.php` 运行测试
3. 构建 test app 验证视觉布局

---

## Phase 3: opacity（不透明度）

**目标**：让 `opacity: 0.x` 实现元素的 Alpha 混合。

### 关键设计
GDI 的 AlphaBlend 需要 32-bit DIB 支持。当前渲染管线使用 24-bit GDI（FillRect 不支持 alpha）。解决方案：
- 方法 A（推荐）：当 opacity < 1.0 时，在内存中创建一个临时位图，绘制内容后使用 `AlphaBlend` 合成到主 DC
- 方法 B（简化）：opacity 只影响文本（通过 `SetTextColor` + alpha 通道），背景预先计算混合色

**选择方法 A** 以获得语义正确的 opacity 行为。

### 修改文件

#### 3.1 C++ 层 `d:/Px/cpp/vue_calc.cc`
- 新增 `php_vue_alpha_blend(Int hdc, Int x, Int y, Int w, Int h, Int color, Double opacity)`：
  - 创建 32-bit DIB（DIBSection）作为临时缓冲区
  - 填充半透明颜色
  - 使用 `AlphaBlend` API 合成到目标 DC
- 或新增通用的 `php_vue_begin_alpha_layer / php_vue_end_alpha_layer` 用于包裹一组绘制调用

#### 3.2 PHP stub
- 新增 `vue_alpha_blend` 或 `vue_begin_alpha_layer`/`vue_end_alpha_layer`

#### 3.3 GdiRenderContext `d:/Px/framework/Rendering/GdiRenderContext.php`
- `drawElement()` 中，`'rect'` 和 `'text'` 的 `$el['opacity']` < 1.0 时调用 alpha blend 变体

#### 3.4 VNodeRenderer `d:/Px/framework/Rendering/VNodeRenderer.php`
- 各元素 builder 从 `$style['opacity']` 读取 opacity 并传入元素描述

### 验证方法
1. 在元素上使用 `opacity: 0.5`
2. 构建并观察半透明渲染效果
3. 验证嵌套 opacity 的复合效果（子元素不应比父元素更不透明）

---

## Phase 4: box-shadow（阴影）

**目标**：实现 `box-shadow: h-offset v-offset blur spread color`。

### 实现策略（简化版）
GDI 没有原生阴影支持。采用**多遍绘制**方案：
1. 在主内容之前，绘制一个偏移的阴影矩形（半透明黑色）
2. 阴影矩形使用 `fillRect` + 预设 alpha（暂不支持 blur）

### 修改文件

#### 4.1 CssMappings `d:/Px/framework/Rendering/CssMappings.php`
- 增强 `boxShadow` 解析：从 `parseIdent` 改为专用解析器 `parseBoxShadow`
- 解析格式：`h-offset v-offset blur-radius spread-radius color`
- 提取偏移量、模糊半径、颜色

#### 4.2 VNodeRenderer `d:/Px/framework/Rendering/VNodeRenderer.php`
- `makeDivElement()` 和 `makeButtonElement()` 中，从 `$style['boxShadow']` 解析阴影参数
- 在元素描述中增加 shadow 部分

#### 4.3 GdiRenderContext `d:/Px/framework/Rendering/GdiRenderContext.php`
- `drawElement()` 的 `'rect'` case 中：先绘制阴影矩形（偏移 + 半透明），再绘制主内容
- 阴影颜色固定为 `box-shadow` 指定的颜色，或默认半透明黑色 `rgba(0,0,0,0.5)`

### 验证方法
1. 使用 `box-shadow: 4px 4px 0px 0px #000000` 测试
2. 构建验证阴影偏移和颜色正确

---

## Phase 5: border（边框）

**目标**：让 `border: width style color` 在所有元素类型上生效（目前仅在 button 中有部分支持）。

### 修改文件

#### 5.1 CssMappings `d:/Px/framework/Rendering/CssMappings.php`
- 增强 `parseBorder`：提取 border-width、border-style、border-color
- 或拆分为 `border-width`、`border-style`、`border-color` 独立属性

#### 5.2 VNodeRenderer `d:/Px/framework/Rendering/VNodeRenderer.php`
- `makeDivElement()`：当 `borderWidth > 0` 时，在 rect 之后绘制边框矩形
- 边框仅在原有内容之上绘制 outline（不改变布局尺寸，与 CSS box-sizing 保持一致）

#### 5.3 GdiRenderContext `d:/Px/framework/Rendering/GdiRenderContext.php`
- `drawElement()` 新增 `'border-rect'` 类型：使用 HPEN + SelectObject + Rectangle 绘制
- 或 `'rect'` case 中一体处理：填充后加边框

### 验证方法
1. 使用 `border: 2px solid #FF0000` 测试
2. 构建验证红色边框正确渲染

---

## 文件修改清单汇总

| 文件 | Phase | 修改内容 |
|------|-------|---------|
| `cpp/vue_calc.cc` | 1, 3 | 新增 RoundRect / AlphaBlend C++ 函数 |
| `stub/vue_calc.stub.php` | 1, 3 | 新增 PHP stub 声明 |
| `Rendering/CssMappings.php` | 2, 4, 5 | 扩展 padding/margin 四方向解析；增强 boxShadow/border 解析 |
| `Rendering/LayoutResolver.php` | 2 | padding/margin 影响定位和尺寸 |
| `Rendering/VNodeRenderer.php` | 1-5 | 各元素 builder 传递新 CSS 属性 |
| `Rendering/GdiRenderContext.php` | 1-5 | drawElement 处理 borderRadius/opacity/boxShadow/border |
| `tests/unit/LayoutResolverTest.php` | 2 | 添加 padding/margin 布局测试用例 |

## 验证流程

1. **单元测试**（每个 Phase 后）：
   ```bash
   D:\swoole_compiler\php.exe tests/unit/bootstrap.php
   # 运行特定测试
   D:\swoole_compiler\php.exe tests/unit/LayoutResolverTest.php
   ```

2. **PHP 语法检查**：
   ```bash
   D:\swoole_compiler\php.exe -l path/to/modified/file.php
   ```

3. **构建验证**（Phase 1/3 需要）：
   ```bash
   build.bat test --run
   ```

4. **截图验证**（可选）：使用 PowerShell 截图脚本确认视觉渲染正确
