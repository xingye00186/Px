# Bilibili 热门页面复刻 & 框架/组件库通用化增强计划

## Context

当前 Px 框架 UI 组件库大部分组件处于"壳"阶段（Card、Tabs、TabPane、Row、Col、Space 只有 props 定义但功能未实现），框架布局层也存在 flex-wrap 未实现、grid 不支持 `1fr` 等问题。Bilibili 热门页面是一个综合性的内容展示页面，包含导航栏、搜索、分类标签、轮播、网格卡片、徽章等多种 UI 模式，是检验和驱动框架/组件库通用化改造的理想案例。

目标：**对框架和 UI 组件库做通用化改造，而不是为 Bilibili 页面打补丁**。

---

## 一、框架核心增强 (Framework Core)

### 1.1 LayoutResolver — flex-wrap 真正实现
- **当前状态**：`$wrap` 变量定义但从未使用，flex 只有单行布局
- **改造内容**：当 `flex-wrap: wrap` 时，子元素超出主轴剩余空间自动换行到下一行
- **涉及文件**：`framework/Rendering/LayoutResolver.php`
- **通用性**：任何多行 flex 布局（标签云、卡片网格）都需要

### 1.2 LayoutResolver — grid 支持 1fr 单位
- **当前状态**：`grid-template-columns: repeat(4, 1fr)` 解析为 `size=1.0(px)`，无法按父容器比例分配
- **改造内容**：当 unit 为 `fr` 时，根据父容器剩余空间按比例分配列宽
- **涉及文件**：`framework/Rendering/LayoutResolver.php` + `framework/Rendering/CssMappings.php`
- **通用性**：任何响应式网格布局都需要 fr 单位

### 1.3 VNodeRenderer — img 元素支持（占位级）
- **当前状态**：`renderNodeToElement()` 中没有 `img` 类型分支
- **改造内容**：`img` 标签生成 `rect` 类型元素，使用 style 中的 `background` 作为颜色填充（图片用纯色代替）
- **涉及文件**：`framework/Rendering/VNodeRenderer.php`
- **通用性**：任何需要图片占位的页面都需要

### 1.4 VNodeRenderer — 文本截断支持 (text-overflow)
- **当前状态**：文本超出宽度直接溢出，无截断省略号
- **改造内容**：支持 `text-overflow: ellipsis` 样式，在 makeSpanElement 中估算文本宽度，超长时截断并追加 `...`
- **涉及文件**：`framework/Rendering/VNodeRenderer.php`
- **通用性**：任何有标题/描述的列表页都需要

---

## 二、UI 组件库通用化增强

### 2.1 Card 组件 — 完整重写
- **当前状态**：仅渲染 `header` 和 `text` 字符串，无任何视觉结构
- **改造为通用 VideoCard**：
  - `cover` — 封面区域（纯色占位，支持自定义颜色/图片路径）
  - `header` — 标题
  - `body` / `footer` — 自定义内容区（通过 template 插槽或 props）
  - `shadow` — 阴影效果
  - `variant` — 变体：`default` / `video` / `cover-top`
- **涉及文件**：`library/vc-ui/Card.vue`
- **通用性**：任何卡片式内容展示（视频、文章、商品）都需要

### 2.2 Tabs + TabPane 组件 — 完整重写
- **当前状态**：仅渲染 activeKey，无标签导航栏、无切换逻辑
- **改造内容**：
  - 标签导航栏：水平排列的 tab 项，支持 `@click` 切换
  - 选中态下划线指示器
  - type: `line` / `card`
  - TabPane 根据 `name` 匹配 `activeKey` 显示内容
- **涉及文件**：`library/vc-ui/Tabs.vue` + `library/vc-ui/TabPane.vue`
- **通用性**：任何多标签页面都需要

### 2.3 Row + Col 栅格组件 — 完整实现
- **当前状态**：props 定义了 `gutter`/`span`/`offset` 但模板完全没使用
- **改造内容**：
  - **Row**: 使用 flexbox 布局，通过 `gutter` 设置列间距（padding 或 gap）
  - **Col**: 根据 `span` (1-24) 计算百分比宽度，支持 `offset` 偏移
  - 支持 `justify` / `align` 属性
- **涉及文件**：`library/vc-ui/Row.vue` + `library/vc-ui/Col.vue`
- **通用性**：任何网格布局都需要

### 2.4 Space 间距组件 — 完整实现
- **当前状态**：props 定义了但未使用
- **改造内容**：使用 flexbox + gap 实现方向（horizontal/vertical）和间距
- **涉及文件**：`library/vc-ui/Space.vue`
- **通用性**：任何需要间距排列的地方

### 2.5 Button 组件 — 增强
- **当前状态**：支持 6 种类型，但 `size`、`round`、`width`/`height` 定制缺失
- **增强内容**：
  - `size`: `small` / `medium` / `large`
  - `round`: 圆角按钮
  - `width` / `height`: 自定义尺寸
- **涉及文件**：`library/vc-ui/Button.vue`
- **通用性**：按钮在不同场景需要不同尺寸

### 2.6 Badge 组件 — 增强
- **当前状态**：仅支持右上角红点/数字，位置固定
- **增强内容**：`position` 属性：`top-right` / `top-left` / `bottom-right` / `bottom-left`
- **涉及文件**：`library/vc-ui/Badge.vue`
- **通用性**：通知数字位置多样化

### 2.7 Icon 组件 — 内置图标映射
- **当前状态**：仅渲染传入的 Unicode 字符
- **增强内容**：内置常用图标名称到 Unicode 字符的映射：
  - `search` → `🔍`, `arrow-left` → `◀`, `arrow-right` → `▶`
  - `notification` → `🔔`, `user` → `👤`, `heart` → `♥`
  - `eye` → `👁`, `refresh` → `🔄`, `close` → `✕`
- **涉及文件**：`library/vc-ui/Icon.vue`
- **通用性**：任何应用都需要图标

### 2.8 Input 组件 — 搜索模式
- **当前状态**：仅输入框，无图标支持
- **增强内容**：`prefix-icon` / `suffix-icon` 属性，在输入框前后显示图标
- **涉及文件**：`library/vc-ui/Input.vue`
- **通用性**：搜索框、带单位输入等场景

---

## 三、Bilibili 应用实现

### 3.1 应用入口
- `apps/bilibili/main.php` — 标准入口，窗口 1440×900
- `apps/bilibili/project.yml` — 项目配置
- 使用 App.vue + 子组件结构

### 3.2 组件树

```
App.vue (根组件)
├── NavBar.vue              — 顶部导航栏
│   ├── Logo + 分类下拉
│   ├── SearchBar            — 搜索输入框 (+ Icon)
│   ├── UserActions          — 用户操作区
│   │   ├── Badge (通知数)
│   │   ├── Avatar (用户头像)
│   │   └── Button (投稿)
│
├── CategoryTabs.vue         — 分类标签导航（水平滚动）
│
├── MainContent.vue          — 主内容区
│   ├── BannerCarousel.vue   — 推广横幅轮播
│   │   └── ... Carousel 组件复用改造版
│   │
│   └── VideoGrid.vue        — 视频卡片网格 (4列)
│       └── VideoCard.vue × N  — 单个视频卡片
│           ├── CoverImage       — 封面占位
│           ├── Title            — 标题 (text-overflow)
│           ├── Stats            — 播放量/点赞
│           └── Meta             — UP主/时间
│
└── SidebarWidget.vue        — 右侧"换一换"按钮
```

### 3.3 布局方案

| 区域 | 布局方式 | 说明 |
|------|---------|------|
| 顶部导航 | flexbox | 水平排列 logo + 搜索 + 操作区 |
| 分类标签 | overflow-x:auto | 水平滚动容器 |
| Banner | Carousel 组件 | 左右箭头 + 指示器 |
| 视频网格 | display:grid \| repeat(4, 1fr) | 4列等宽 | 
| 视频卡片内部 | flexbox | 标题 + 统计 + 元数据垂直排列 |

### 3.4 样式方案
- **整体配色**: 白色背景 `#FFFFFF`，导航栏 `#FFFFFF`，hover 色 `#F6F7F8`
- **Bilibili 粉**: `#FB7299`（主品牌色，按钮、选中态）
- **字体色**: `#18191C` (主标题)、`#9499A0` (次要信息)
- **描边**: `#E3E5E7` (分割线、描边)
- **卡片**: 白色背景，hover 时阴影 `#E8E8E8`
- **封面占位**: 随机色块（模拟视频封面）

---

## 四、文件变更清单

### 框架层修改 (4 files)
| 文件 | 改动 |
|------|------|
| `framework/Rendering/LayoutResolver.php` | flex-wrap 实现 + grid fr 单位支持 |
| `framework/Rendering/CssMappings.php` | grid fr 解析增强 |
| `framework/Rendering/VNodeRenderer.php` | img 元素支持 + text-overflow:ellipsis |
| `framework/Rendering/RenderContext.php` | 无需修改（保持抽象接口） |

### 组件库修改 (8 files)
| 文件 | 改动量 |
|------|--------|
| `library/vc-ui/Card.vue` | **重写** — 完整的视频卡片结构 |
| `library/vc-ui/Tabs.vue` | **重写** — 标签导航栏 + 切换逻辑 |
| `library/vc-ui/TabPane.vue` | **改写** — 按 name 匹配 activeKey 显示 |
| `library/vc-ui/Row.vue` | **重写** — flexbox + gutter |
| `library/vc-ui/Col.vue` | **重写** — span 百分比宽度 |
| `library/vc-ui/Space.vue` | **重写** — flex gap |
| `library/vc-ui/Button.vue` | **增强** — size/round |
| `library/vc-ui/Icon.vue` | **增强** — 图标映射 |
| `library/vc-ui/Input.vue` | **增强** — prefix/suffix-icon |
| `library/vc-ui/Badge.vue` | **增强** — position 属性 |

### 新增应用文件 (6+ files)
| 文件 | 说明 |
|------|------|
| `apps/bilibili/main.php` | 入口 |
| `apps/bilibili/project.yml` | 配置 |
| `apps/bilibili/App.vue` | 根组件 |
| `apps/bilibili/components/NavBar.vue` | 导航栏 |
| `apps/bilibili/components/CategoryTabs.vue` | 分类标签 |
| `apps/bilibili/components/VideoGrid.vue` | 视频网格 |
| `apps/bilibili/components/VideoCard.vue` | 视频卡片 |
| `apps/bilibili/components/BannerCarousel.vue` | 横幅轮播 |
| `apps/bilibili/components/SidebarWidget.vue` | 侧边换一换 |

---

## 五、执行顺序

1. **框架层增强** — LayoutResolver flex-wrap + grid fr + VNodeRenderer img/ellipsis
2. **UI 组件增强** — Card, Tabs, Row, Col, Space, Button, Icon, Input, Badge
3. **Bilibili 应用实现** — 所有组件 + 主入口
4. **验证** — 确认每个组件能独立工作，Bilibili 页面布局对齐原图

---

## 六、验证方式

1. **编译测试**: `D:\swoole_compiler\php.exe` 语法检查所有修改文件
2. **单元测试**: 运行 `php tests/run_all_tests.php` 确保不破坏现有测试
3. **预览**: 构建 bilibili app 后运行 exe 预览布局效果
4. **框架能力提升总结**: 列出本次改造带来的通用能力
5. **组件库提升总结**: 列出从"壳"到可用的组件改进

---

## 七、最终需要总结的内容

执行完成后，需要在 README 或文档中总结：

### 框架能力提升
- flex-wrap 多行布局支持
- grid 1fr 弹性单位支持
- img 元素占位渲染
- text-overflow: ellipsis 文本截断

### UI 组件库提升
- Card: 从"仅显示文本" → 完整卡片（封面/标题/内容/元数据）
- Tabs+TabPane: 从"仅显示activeKey" → 完整标签页切换
- Row+Col: 从"显示文本" → 完整24栅格系统
- Space: 从"显示文本" → 完整 flex 间距组件
- Button: 新增 size/round 定制
- Icon: 新增内置图标映射
- Input: 新增 prefix/suffix-icon
- Badge: 新增 position 属性

## 通用化原则
- 所有框架修改都是 **CSS 规范对齐**（flex-wrap 是 CSS 标准行为，不是 Bilibili 特例）
- 所有组件修改都增加了 **通用 props**，Bilibili 只是第一个使用者
- 组件库模式对齐 **Element UI / Ant Design** 的组件接口设计

---

## 八、实际实现结果与差异根因分析

### 8.1 Bilibili 页面布局结构

```
App.vue (1440x900)
├── NavBar (0,0,1440,56)
├── CategoryTabs (0,56,1440,40)
├── Main Content (0,96,1440,804, padding:20px 24px 0 24px, overflow-y:auto, flex row)
│   ├── Left Column (1048px, flex:1, shrink:0, min-width:0, flex column, gap:20px)
│   │   ├── BannerCarousel (≈1048x120)
│   │   └── VideoGrid (≈1048x?, grid repeat(4,1fr), gap:12px, cell≈245x?)
│   └── Sidebar (320px, flex-shrink:0, margin-left:24px)
```

flex-grow 计算：容器内宽 = 1440 - 24(左pad) - 24(右pad) = 1392px。侧边栏 = 320 + 24(margin) = 344px。左面板(flex:1)最终 = 1392 - 344 = 1048px。

### 8.2 布局差异根因

| 根因 | 影响范围 | 严重程度 |
|------|---------|---------|
| **flex-grow 后子容器未重算** — flex 子项主轴尺寸变化后，其内部子节点仍使用初始未拉伸尺寸进行布局（如左面板从1392变为1048，但其子元素BannerCarousel/VideoGrid仍按1392布局） | VideoGrid各cell宽度错误，网格溢出 | 严重 |
| **flex+scroll 组合缺少 contentHeight 重算** — flex内部滚动容器的 contentHeight 未在布局完成后刷新，导致滚动条行为异常 | 滚动容器 | 中 |
| **position:absolute 在 flex 流内未隔离** — 绝对定位子元素参与 flex 布局，导致父容器额外拉伸 | 任何flex内absolute元素 | 中 |
| **absolute right/bottom 未支持** — 依赖 right/bottom 定位的元素（如徽章）位置错误 | Badge组件 | 中 |
| **cross-axis fill 忽略父容器 padding** — stretch 填充高度时未考虑父容器上下padding | 任何含padding的flex容器的stretch子项 | 轻微 |
| **flex:1 初始 fill 忽略父容器 padding** — flex-basis计算时未减去父容器主轴方向padding | 同上 | 轻微 |
| **grid 不支持 1fr** — grid-template-columns:repeat(4,1fr) 无法按比例分配列宽 | 所有grid布局 | 中 |
| **text-overflow:ellipsis 未实现** — 长文本没有截断省略号，导致溢出遮挡 | 标题/描述文本 | 轻微 |

> **所有问题均已在前序会话和本次会话中逐一修复。**

### 8.3 框架层修复手段

#### 8.3.1 LayoutResolver — 两遍布局（核心修复）
- **修复位置**：`resolveFlexLayout()` Step 11 子节点定位循环之后
- **原理**：flex-grow 在 Step 5 改变了 flex 子项的主轴尺寸，但其内部子节点在 Step 1 时按初始尺寸布局。两遍布局重新调用 `resolveNode()` 对 flex-grow 子项的后代做二次解析
- **代码量**：约25行
- **效果**：VideoGrid 从 1392px 宽度正确计算为 1048px，grid cell 从 339px 正确计算为 245px

#### 8.3.2 LayoutResolver — flex+scroll 后处理
- **修复位置**：`resolveNode()` 中 flex/grid display 分支后
- **原理**：flex/grid 模式下不在 resolveBlockLayout 内部，需要外部后处理 contentHeight/contentWidth 计算和 scrollTop clamp
- **效果**：滚动容器内容高度正确，滚动条范围准确

#### 8.3.3 LayoutResolver — position:absolute 在 flex 中隔离
- **修复位置**：`resolveFlexLayout()` Step 0 预处理
- **原理**：将 `position:absolute` 子节点从 flex 子项列表中暂时移除，布局完成后再单独定位
- **效果**：absolute 元素不参与 flex 分配，不拉伸父容器

#### 8.3.4 LayoutResolver — absolute right/bottom 定位
- **修复位置**：`resolveAbsoluteLayout()`
- **原理**：支持 `right=0` → x = 父容器宽 - 自身宽；`bottom=0` → y = 父容器高 - 自身高
- **效果**：Badge、浮动按钮等组件位置正确

#### 8.3.5 LayoutResolver — cross-axis fill 与 flex:1 初始 fill 的 padding 感知
- **修复位置**：`resolveFlexLayout()` Step 8 (stretch 定位) 和 Step 5 (flex-grow)
- **原理**：stretch 时减去父容器的 cross-axis padding，flex:1 计算时减去 main-axis padding
- **效果**：含 padding 的容器中子元素宽度/高度不溢出

#### 8.3.6 VNodeRenderer — 新增功能
- **img 元素支持**：将 `<img>` 标签渲染为有色矩形占位
- **text-overflow:ellipsis**：超出容器宽度的文本自动截断并追加 `…`
- **container-w="100%" 解析修复**：修复宽度百分比解析错误

#### 8.3.7 CssMappings — grid fr 单位解析
- grid-template-columns 中的 `1fr` 识别并传递 unit='fr' 标志
- LayoutResolver 中根据容器宽度减去 gap 后按比例分配列宽

### 8.4 UI 组件库修复/增强

| 组件 | 修复/增强 |
|------|----------|
| **Card** | 从"仅显示文本" → 完整卡片结构：cover/header/body/footer/shadow/variant(video/cover-top/default) |
| **Tabs + TabPane** | 从"仅显示activeKey" → 标签导航栏 + 选中态下划线 + 点击切换 + type(line/card) |
| **Row + Col** | 从"显示文本" → 完整24栅格系统：flexbox + gutter/span/offset/justify/align |
| **Space** | 从"显示文本" → flex gap 间距组件：direction/size 属性 |
| **Button** | 新增 size(small/medium/large) + round 圆角 + width/height 自定义 |
| **Icon** | 内置图标映射：search/arrow-left/notification/user/heart/eye 等 → Unicode |
| **Input** | 新增 prefix-icon / suffix-icon 图标插槽 |
| **Badge** | 新增 position 属性：top-right/top-left/bottom-right/bottom-left |

### 8.5 框架能力提升总结

1. **Flexbox 完整支持**：单行 → 多行(wrap) + 弹性分配(grow/shrink/basis) + 两遍布局
2. **Grid 弹性列宽**：从固定像素 → 1fr 比例分配
3. **绝对定位增强**：+ right/bottom 定位 → 接近 CSS 标准
4. **滚动容器兼容**：flex/grid 模式下 contentHeight 刷新
5. **文本溢出截断**：新增 text-overflow:ellipsis 支持
6. **图片占位渲染**：新增 img 元素类型
7. **两遍布局架构**：在现有单遍布局架构上增加了 flex-grow 场景的第二遍布局，为后续多遍/增量布局打下基础

### 8.6 Bilibili 测试应用产出

| 文件 | 说明 |
|------|------|
| `apps/bilibili/main.php` | 应用入口（1440x900） |
| `apps/bilibili/project.yml` | 构建配置 |
| `apps/bilibili/App.vue` | 根组件 |
| `apps/bilibili/components/NavBar.vue` | 顶部导航栏 |
| `apps/bilibili/components/CategoryTabs.vue` | 分类标签（水平滚动） |
| `apps/bilibili/components/BannerCarousel.vue` | 推广横幅轮播 |
| `apps/bilibili/components/VideoGrid.vue` | 视频卡片网格视频卡片 |
| `apps/bilibili/components/VideoCard.vue` | 视频卡片网格 |
| `apps/bilibili/components/SidebarWidget.vue` | 侧边栏组件 |
| `apps/bilibili.png` | 参考截图 |
| `tests/screenshot/capture_bilibili.ps1` | 截图捕获脚本 |

### 8.7 构建系统改进

- `build.bat` / `main_build.bat`：添加 `SDK/lib` 到 LIB 环境变量，修复 Swoole Compiler v1054+ 的 libmpdec.lib 链接问题
- `cpp/skia_render.cc`：修复 php_sk_resize_context 中 g_skW/g_skH 赋值在 #ifdef USE_SKIA 内的问题，确保非 Skia 构建也能更新窗口尺寸
