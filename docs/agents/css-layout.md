# CSS 布局属性（LayoutOrchestrator）

> **何时加载**：修改布局引擎或 CSS 属性支持时加载此文档。包含全部已支持的 CSS 属性表。

---

## 盒模型

| 属性 | 说明 | 默认值 |
|------|------|--------|
| `box-sizing` | `content-box`/`border-box` | content-box |
| `box-shadow` | h-offset v-offset blur spread color | none |
| `opacity` | 0.0~1.0 | 1.0 |
| `cursor` | `pointer` 等 | default |

`box-sizing: border-box` 下 width/height 包含 padding+border。

## 显示模式

| 属性 | 说明 |
|------|------|
| `display` | `block`/`flex`/`grid`/`inline-flex` |

## 尺寸约束

| 属性 | 默认值 |
|------|--------|
| `min-width` / `max-width` | 0 |
| `min-height` / `max-height` | 0 |

CSS 规范：当 `min > max` 时，`max` 被忽略。

## 百分比尺寸

- `width: 50%` — 相对父容器 content width
- `height: 50%` — 相对父容器 content height
- `calc(100% - 40px)` — 百分比 + 像素偏移
- margin/padding 百分比基于**包含块宽度**

## Position

| 属性 | 说明 |
|------|------|
| `static` | 默认流式 |
| `relative` | 相对定位（不参与 auto-stack） |
| `absolute` | 相对最近非 static 祖先 |
| `fixed` | 相对视口 |
| `sticky` | 滚动容器内堆叠 |
| `z-index` | 层叠顺序 |

- `position:sticky` 支持垂直/水平堆叠
- 命中测试按 layer（z-index）层叠顺序

## 文本与字体

| 属性 | 说明 |
|------|------|
| `line-height` | 行高（影响文本节点高度） |
| `white-space` | `normal`/`nowrap` |
| `text-overflow` | `clip`/`ellipsis` |
| `text-align` | `left`/`center`/`right` |
| `font-family` | 字体回退链（逗号分隔） |

文本节点 auto-height：根据 `line-height` × 行数自动计算。

## Background

| 属性 | 说明 |
|------|------|
| `background: #RRGGBB` | 十六进制 |
| `background: rgba(r,g,b,a)` | alpha 自动提取为 opacity |
| `background: linear-gradient(...)` | 提取第一个色值 |
| `background: url(...) center/cover no-repeat` | 简写展开 |
| `background-image: url(...)` | 图片背景 |
| `background-size` | `cover`/`contain` |

## 图像与替换元素

| 属性 | 说明 |
|------|------|
| `object-fit` | `fill`/`contain`/`cover`/`none` |
| `border-radius` | 圆角半径 (px) |

## Transform

| 属性 | 说明 |
|------|------|
| `transform: translateX/Y(px)` | 平移 |
| `transform: translate(x, y)` | 双轴简写 |
| `transform: rotate(deg)` | 旋转（仅解析） |

transform 偏移纳入命中测试范围。

## 指针事件

| 属性 | 说明 |
|------|------|
| `pointer-events: none` | 事件穿透 |
| `pointer-events: auto` | 正常交互 |

## Flex 布局

| 属性 | 默认值 |
|------|--------|
| `flex-direction` | row |
| `flex-wrap` | nowrap |
| `justify-content` | flex-start |
| `align-items` | stretch |
| `align-content` | stretch |
| `gap` | 0 |
| `flex-grow` | 0 |
| `flex-shrink` | 1 |
| `flex-basis` | auto |
| `order` | 0 |
| `align-self` | auto |

**flex 简写**：`auto`→1 1 auto，`none`→0 0 auto，`1`→1 1 0

**Flex-shrink 算法**：
```
overflow = totalMain - containerMain
item.mainSize -= overflow × (item.mainSize × item.shrink) / totalShrinkWeight
min-width 约束在收缩后应用
```

> 无限循环防护：`if ($distributedInPass <= 0) break;`

## Grid 布局

| 属性 | 说明 |
|------|------|
| `grid-template-columns` | 列定义（`repeat(N, SIZE)`、`1fr`） |
| `grid-template-rows` | 行定义 |
| `grid-column-gap` / `grid-row-gap` | 间距 |
| `grid-column` / `grid-row` | 跨列/行范围 |
| `align-self` / `justify-self` | 对齐 |

支持 `repeat(auto-fill/auto-fit, minmax(MIN, MAX))`。

## 滚动条样式

| 属性 | 默认值 |
|------|--------|
| `scrollbar-width` | 12 |
| `scrollbar-track-color` | 0x4A4A4A |
| `scrollbar-thumb-color` | 0x888888 |
| `scrollbar-border-radius` | 0 |

## Overflow

| 属性 | 默认值 |
|------|--------|
| `overflow` / `overflow-x` / `overflow-y` | visible |
| `text-overflow` | clip |

- `overflow:auto/scroll` → 可滚动（`isScrollContainer=true`）
- `overflow:hidden` → clip-push/clip-pop 裁切

## 内联样式百分比标记

`CssMappings::parseInlineStyle()` 自动标记百分比值为 `*Percent` 字段，支持 `calc(100% - 40px)`。
