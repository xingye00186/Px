# Px 框架架构评审与技术债务改进报告

---

## 一、架构全貌与反模式识别

### 1.1 当前架构分层

```
┌─────────────────────────────────────────────────────────┐
│  Application Layer (Application.php 1086行)              │
│  事件路由 + 组件注册 + VNode展开 + bind解析               │
│  + 渲染调度 + 滚动管理 + 布局导出 + 序列化 + 截图         │
├─────────────────────────────────────────────────────────┤
│  Component Layer (BaseComponent + ReactiveComponent)     │
│  生命周期 + VNode缓存 + 事件发射 + 脏标记                  │
├─────────────────────────────────────────────────────────┤
│  Tree Layer (VNode → RenderTreeManager → RenderNode)    │
│  VNode(描述) → RenderNode(布局+渲染) 转换                  │
├─────────────────────────────────────────────────────────┤
│  Layout Layer (LayoutResolver + 7策略类)                  │
│  坐标计算、百分比解析、滚动容器、sticky定位                │
├─────────────────────────────────────────────────────────┤
│  Render Layer (VNodeRenderer → RenderContext)             │
│  元素收集 + 按layer排序 + 委托Backend绘制                  │
├─────────────────────────────────────────────────────────┤
│  Backend Layer (IRenderBackend + 6实现 + 降级代理)        │
│  GDI/Skia-CPU/Skia-D3D11/... 多后端自动选择+故障切换      │
├─────────────────────────────────────────────────────────┤
│  Platform Layer (Platform接口 + Win32Platform 150行)     │
│  Win32消息循环 + 事件解码 + 动画定时器                     │
├─────────────────────────────────────────────────────────┤
│  Compiler (sfc-compiler.php 2973行单文件)                 │
│  .vue → PHP代码 全流程 (解析/代码生成/AOT验证)            │
└─────────────────────────────────────────────────────────┘
```

### 1.2 识别出的反模式

#### 反模式 1：God Class — Application.php

`Application.php` 承载了以下 **10+ 种职责**：

| # | 职责 | 对应代码 |
|---|------|----------|
| 1 | 平台/调度器创建与注入 | `create()` |
| 2 | CLI 参数解析 | `handleDumpArgs()` |
| 3 | 组件注册表管理 | `registerComponent/unregisterComponent` |
| 4 | VNode 树重建 | `rebuildVNodeTree()` |
| 5 | 组件展开与匹配 | `expandComponentNode/matchComponentNode` |
| 6 | 组件树 Patch | `patchComponentTree()` |
| 7 | 渲染管线调度 | `render()` |
| 8 | 直接渲染（拖拽） | `directRender()` |
| 9 | 鼠标/键盘事件处理 | `handleMouseEvent/handleKeyboardEvent` |
| 10 | 命中测试与事件分发 | 内联 hitTest + dispatchClick |
| 11 | 滚动管理初始化 | `ScrollManager` 构造 |
| 12 | 渲染后端初始化 | `initRenderer()` (3阶段初始化) |
| 13 | 布局快照序列化 | `dumpLayoutToFile/serializeRenderNode` |
| 14 | 截图保存 | `saveScreenshot()` |
| 15 | 调试日志 | `debugDumpRenderNode/logScrollContainerStates` |

**SOLID 单一职责原则严重违反**。Application 是典型的 "God Class" — 它知道太多、做太多。

#### 反模式 2：职责混合 — RenderTreeManager

`RenderTreeManager` (1047行) 将以下不相关职责混在一个类中：

- VNode → RenderNode 转换 (`updateFromVNode`)
- CSS 样式解析 (`resolveNodeStyle` — 跨组件搜索 class styles)
- CSS 伪类/伪元素处理 (`:hover/:focus/::before/::after`)
- CSS 继承传播 (颜色/字体/文本属性向上继承)
- RenderNode 匹配与复用 (`findMatchingRenderNode`)
- 命中测试 (`hitTest/hitTestRecursive`)
- 滚动容器查找 (`findScrollContainerAt`)
- 树销毁 (`destroyRenderNodeTree`)
- 树调试输出 (`dumpRenderTree/dumpNode`)

#### 反模式 3：巨类 — CssMappings (2056行)

`CssMappings` 名为 "映射表"，但实际上包含：
- CSS 属性解析器 (颜色、像素、简写展开)
- CSS 选择器匹配 (`matchComplexSelector`)
- CSS 值转换逻辑
- 2000+ 行混合了数据定义与解析逻辑

#### 反模式 4：单文件巨型编译器

`sfc-compiler.php` (2973行) 是一个巨大的过程式脚本，50+ 个全局函数，混合了：
- 模板解析
- 代码生成
- AOT 验证
- 表达式解析
- 组件注册

#### 反模式 5：接口贫血

- `Scheduler` — 无接口，Application 直接依赖具体类
- `ScrollManager` — 无接口，通过闭包注入与 Application 通信
- `ImageManager` — 纯静态类，无抽象
- `Config` — 纯静态类，全局状态

#### 反模式 6：RenderNode 职责膨胀

`RenderNode` (235行，33个字段) 同时承载：
- 布局结果（x/y/w/h/visualW/visualH/layer）
- 滚动状态（scrollTop/scrollLeft/contentHeight/contentWidth/...）
- 动画状态（animatedStyle/isAnimating/lastX/lastY）
- 交互状态（hovered/focused/active）
- 树关系（parent/positioningAncestor/sourceVNode/children）
- 脏标记（layoutDirty/lastPaintFrame）
- 渲染偏移（renderOffsetX/renderOffsetY）
- 文本渲染信息

---

## 二、对照 9 原则逐项评审

### 2.1 SOLID — 单一职责原则 (SRP)

**评分: 3/10** — 严重违规

| 类 | 评分 | 说明 |
|----|------|------|
| Application | 1/10 | God Class，15种职责 |
| RenderTreeManager | 3/10 | 转换+样式+命中+滚动，4种职责 |
| VNodeRenderer | 5/10 | 遍历收集+绘制+clip管理，尚可 |
| CssMappings | 4/10 | 映射+解析+匹配，混合 |
| VNode | 8/10 | 纯数据结构，职责清晰 |
| RenderNode | 5/10 | 字段过多但定位为渲染节点 |
| LayoutResolver | 7/10 | 纯调度器，委派给策略类 |
| ScrollManager | 8/10 | 职责单一：滚动交互 |
| Scheduler | 9/10 | 微任务/宏任务调度，职责极简 |
| ThemeProvider | 7/10 | 主题+class注册表，两项职责 |
| Win32Platform | 8/10 | 平台事件+定时器，职责清晰 |
| Backend系统 | 9/10 | IRenderBackend接口定义清晰 |

### 2.2 SOLID — 开闭原则 (OCP)

**评分: 6/10** — 部分合规

**合规点：**
- `LayoutResolver` 使用策略模式 (`LayoutStrategyInterface`)，新增 display 类型只需新增策略类 + switch 分支
- `IRenderBackend` 接口允许新增渲染后端而不修改调用方
- `ITextBackend` 同理

**违规点：**
- `LayoutResolver::resolveNode` 中的 switch 分支**必须修改源码**才能扩展新 display 类型。理想方案是让每个策略类声明支持的 display 列表
- `RenderTreeManager::resolveNodeStyle` 中的 `INLINE_TYPES` 和伪类处理写死扩展方式
- `Application::handleMouseEvent` 的事件分发固定 4 种 action 类型

### 2.3 SOLID — 里氏替换原则 (LSP)

**评分: 7/10** — 基本合规

**合规点：**
- `ReactiveComponent extends BaseComponent implements ReactiveComponentInterface` — 子类可安全替换父类
- `Win32Platform implements Platform` — 接口契约完整
- Backend 实现类全部实现 `IRenderBackend`

**违规点：**
- `BaseComponent::dispatchClick` 默认冒泡到 parent，子类 override 时如果不调用 `parent::dispatchClick` 会截断冒泡链
- `ReactiveComponent::on()` 方法的 `$child` 参数类型为 `ReactiveComponent`（具体类），而非 `ReactiveComponentInterface`（接口）— 违反了面向接口编程

### 2.4 SOLID — 依赖倒置原则 (DIP)

**评分: 4/10** — 多处违规

**合规点：**
- `Application` 依赖 `Platform` 接口，通过 `PlatformFactory` 创建 — **良好**
- `VNodeRenderer` 依赖 `RenderContext` 抽象类 — **良好**
- `LayoutResolver` 依赖 `LayoutStrategyInterface` 接口 — **良好**

**严重违规点：**
- `Application` 直接依赖具体类 `Scheduler`、`ScrollManager`、`RenderTreeManager`、`LayoutResolver`、`ImageManager`
- `ScrollManager` 通过闭包回调而非接口与 Application 通信 — 虽然解耦了依赖方向，但闭包类型不安全
- `ReactiveComponent::setScheduler(Scheduler $scheduler)` — 参数为具体类
- `Win32Platform::init()` — 直接 `new GdiRenderContext()` / `new SkiaRenderContext()`，硬编码具体类

### 2.5 SOLID — 接口隔离原则 (ISP)

**评分: 6/10** — 中等

**合规点：**
- `Platform` 接口 (6个方法) — 精简、聚焦
- `IRenderBackend` (5个方法) — 精简
- `LayoutStrategyInterface` (1个方法) — 极简

**违规点：**
- `ReactiveComponentInterface` (12个方法) 混合了渲染、生命周期、bind、事件分发 — 应拆分
- `ComponentInterface` 只有 6 个方法但缺少 dispatchClick/dispatchKey 声明

### 2.6 高内聚低耦合

**评分: 5/10** — 耦合问题突出

**低耦合亮点：**
- `ScrollManager` 通过闭包注入与 Application 解耦 — 良好模式
- Backend 选择系统 (`RuntimeBackendSelector + ResilientRenderContext`) — 独立子系统
- Layout 策略类通过接口通信

**高耦合问题：**
- `Application` 直接操作 `RenderNode.hovered` 字段 (`$this->hoveredNode` 引用)
- `RenderTreeManager` 内部直接调用 `ThemeProvider::getAllClassStyles()` — 跨层调用
- `RenderTreeManager::updateFromVNode` 的参数列表有 **8个参数**，耦合极深
- `ScrollManager` 的函数闭包需要 Application 传入 4 个回调
- `Application::serializeRenderNode` 内联了庞大的样式 key 白名单列表

### 2.7 DRY — 不重复原则

**评分: 5/10** — 多处重复

**重复点清单：**

| # | 重复内容 | 位置 |
|---|----------|------|
| 1 | scrollTop/scrollLeft clamp 逻辑 | `LayoutResolver::resolveNode` 脏路径中 3 处 + 洁净路径 1 处 |
| 2 | layer-aware 子节点遍历 + krsort | `RenderTreeManager::hitTestRecursive` + `findScrollContainerRecursive` |
| 3 | `childrenToArray` 模式 | 历史上 Application + RenderTreeManager 各维护一份（已统一到 VNode::childrenToArray） |
| 4 | CSS 样式序列化 key 白名单 | `Application::serializeRenderNode` + 测试对比层 |
| 5 | `computPaddingBoxClip` 逻辑 | VNodeRenderer 内部 + LayoutResolver 可能重复 |
| 6 | scrollWidth/thumbWidth 计算 | `ScrollManager::hitTestScrollbar` + `handleScrollbarDown` + `handleScrollbarDrag` 三处几乎相同 |

### 2.8 KISS — 保持简单原则

**评分: 5/10** — 存在不必要的复杂度

**复杂设计：**

1. **LayoutResolver 脏/洁净双路径** — 最复杂的部分。洁净路径中有 flex/grid 容器子节点脏检测 + 回退重布局逻辑（`LayoutResolver.php` L462-L498），构成深层嵌套的条件分支。

2. **VNode → RenderNode 转换的双重匹配** — `RenderTreeManager::updateFromVNode` 中 `#root` handler 的 candidates 匹配 + 普通元素 handler 的 oldChildren 匹配，两套匹配逻辑交织。

3. **Application 的两种渲染路径** — `render()` (完整) 和 `directRender()` (跳过VNode重建)，ScrollManager 需要知道何时调用哪个。

4. **ResilientRenderContext 的 safeCall** — 虽然设计思想好，但 AOT 兼容性导致用 switch 替代动态方法调用，代码冗长。

### 2.9 YAGNI — 不做多余设计

**评分: 5/10** — 部分过度设计

**疑似 YAGNI：**
- `TableLayoutStrategy` / `MultiColumnLayoutStrategy` — 已导入但实现度存疑
- `InlineLayoutStrategy` — 已存在但复杂度未知
- 6 个 Backend 实现中仅 2-3 个可用（SkiaCPU / GdiLegacy 确认可用），其余为占位
- `VNodeDevTools::findScrollContainers` — 返回空数组（注释说明 RenderNode 才有此属性）
- `Platform::setCursor` — 标记为 `@deprecated`，空实现

### 2.10 迪米特法则 (最少知识原则)

**评分: 4/10** — 多处违反

**违规点：**
- `Application::handleMouseEvent` 直接操作 `$this->hoveredNode->hovered = false/true`
- `Application` 通过 `$renderNode->sourceVNode->props['@click']` 链式访问 3 层
- `Application` 通过 `$renderNode->style['cursor']` 读取内部样式
- `ScrollManager::applyScrollTop` 通过 `$node->sourceVNode->props[':scroll-top']` 读取 VNode 内部

### 2.11 分层单向依赖

**评分: 7/10** — 基本合规

整体依赖方向：**Compiler → Application → Platform → Render → Layout → Backend**

问题点：
- `RenderTreeManager` (Rendering层) 依赖 `ThemeProvider` (Styling层) 和 `Config` (Core层) — 渲染层反向依赖样式层
- `Application` (Core层) 直接使用 `CssMappings::parseInlineStyle` — 跨层调用

### 2.12 无循环依赖

**评分: 7/10** — 弱双向耦合存在但无死循环

- `RenderNode.sourceVNode` 形成 RenderNode → VNode 的反向引用，但这是设计上必要的数据访问通道
- `LayoutResolver` ← 策略类 → `LayoutResolver` 之间的引用是构造注入的单向依赖
- 无编译期循环依赖

### 2.13 最小数据冗余

**评分: 5/10** — 存在冗余

| 冗余 | 说明 |
|------|------|
| VNode.type ↔ RenderNode.type | 同一标识在两处存储 |
| VNode.key ↔ RenderNode.key | 同上 |
| VNode.groupId ↔ RenderNode.groupId | 由 updateFromVNode 同步复制 |
| VNode.props['style'] → RenderNode.style | 解析后存为两套格式 |
| RenderNode.content ↔ sourceVNode.children | 文本内容重复 |

---

## 三、对标 Flutter 提出目标架构

### 3.1 Flutter 架构参考模型

```
Flutter:
  ┌──────────────┐     ┌──────────┐     ┌──────────────┐
  │   Widget     │────→│ Element  │────→│ RenderObject │
  │ (immutable)  │     │(lifecycle)│    │(layout+paint) │
  └──────────────┘     └──────────┘     └──────────────┘
        ↑                                     │
   BuildOwner                            PipelineOwner
   (管理构建)                             (管理布局和绘制)
                                              │
                                         Layer Tree
                                         (合成层)
```

**Flutter 核心设计要点：**
1. **Widget 完全不可变** — 每次 build 返回新 Widget 树
2. **Element 负责生命周期** — createState/mount/update/unmount
3. **RenderObject 只做布局和绘制** — performLayout() + paint()
4. **PipelineOwner** — 独立管理布局/绘制/合成阶段
5. **BuildOwner** — 管理脏 Element 的重建调度
6. **Layer 树** — 独立的合成层，支持硬件加速

### 3.2 Px 现状 vs Flutter 对标

| 概念 | Px 现状 | Flutter 对标 | 差距 |
|------|---------|-------------|------|
| UI 描述 | VNode (包含运行时字段) | Widget (纯不可变) | VNode 含 layoutOffset/componentPropValues |
| 生命周期管理 | Application 直接管理 | Element 独立管理 | 无 Element 概念 |
| 布局+绘制 | RenderNode (33字段) | RenderObject (专注) | RenderNode 过于臃肿 |
| 调度协调 | Application::render() | PipelineOwner | 无独立PipelineOwner |
| 构建管理 | Application::rebuildVNodeTree | BuildOwner | 逻辑内嵌在Application |
| 合成层 | VNodeRenderer::collectElements | Layer Tree | 无独立Layer抽象 |
| 渲染后端 | Backend 系统 (6后端) | Engine (Skia) | Px 更灵活，但复杂 |
| 事件系统 | Application::handleMouseEvent | GestureBinding | 硬编码在Application |

### 3.3 目标架构设计

```
Px 目标架构:

┌──────────────────────────────────────────────────────────────┐
│                     BuildOwner                                │
│  管理组件构建周期：markDirty → scheduleBuild → rebuild        │
│  从 Application.rebuildVNodeTree() 提取                      │
└──────────────┬───────────────────────────────────────────────┘
               │
┌──────────────▼───────────────────────────────────────────────┐
│                   ComponentElement (新)                       │
│  从 Application.expandComponentNode/matchComponentNode 提取  │
│  负责：组件实例化、bind值传递、生命周期、子Element管理         │
│  对标 Flutter Element：createState → mount → update → unmount │
└──────────────┬───────────────────────────────────────────────┘
               │
┌──────────────▼───────────────────────────────────────────────┐
│               RenderObject (重构 RenderNode)                  │
│  只保留：布局字段(x/y/w/h) + 树关系 + 脏标记                  │
│  移出：动画状态、交互状态(hover/focus/active)、文本渲染信息    │
│  移出：滚动字段 → 独立 ScrollableRenderObject                 │
│  对标 Flutter RenderObject                                   │
└──────────────┬───────────────────────────────────────────────┘
               │
┌──────────────▼───────────────────────────────────────────────┐
│                   PipelineOwner                               │
│  从 Application::render() 提取：                              │
│  LayoutPhase → PaintPhase → CompositePhase                   │
│  管理 LayoutResolver + VNodeRenderer 的调度                   │
└──────────────┬───────────────────────────────────────────────┘
               │
┌──────────────▼───────────────────────────────────────────────┐
│                      Layer Tree (新)                          │
│  从 VNodeRenderer::collectElements 提取                      │
│  独立 layer 数据结构，支持合成优化                            │
└──────────────────────────────────────────────────────────────┘
```

### 3.4 关键改进方向

| 优先级 | 改进项 | 影响范围 |
|--------|--------|----------|
| P0 | 拆分 Application → BuildOwner + EventDispatcher + PipelineOwner | 核心架构 |
| P0 | 引入 ComponentElement 管理组件生命周期 | Component/Application |
| P1 | RenderNode 瘦身（交互状态/动画状态移出） | RenderNode |
| P1 | 引入 Layer 树抽象 | VNodeRenderer |
| P1 | Scheduler/ScrollManager 接口化 | Core |
| P2 | CssMappings 拆分为映射 + 解析器 | Rendering |
| P2 | sfc-compiler 模块化拆分 | Compiler |
| P3 | 全局静态状态治理 | 多处 |

---

## 四、技术债务分级清单

### P0 — 架构阻塞（影响后续所有演进）

#### P0-1: Application God Class 拆分

| 项 | 内容 |
|----|------|
| **文件** | `framework/Core/Application.php` (1086行) |
| **问题** | 15种职责耦合在一个类中，任何改动都需要理解整个 1086 行 |
| **方案** | 拆分为 4 个类：`BuildOwner`（组件构建）、`EventDispatcher`（事件路由）、`RenderPipeline`（渲染调度）、`Application`（仅做组装入口） |
| **风险** | 高 — 涉及所有应用入口 |
| **工作量** | 大（约 3-5 天） |

#### P0-2: 引入 ComponentElement 管理组件生命周期

| 项 | 内容 |
|----|------|
| **文件** | `Application.php` (expandComponentNode/matchComponentNode/patchComponentTree) |
| **问题** | 组件实例化、bind传递、生命周期管理全部硬编码在 Application 中 |
| **方案** | 创建 `ComponentElement` 类，封装：组件创建、属性绑定、子Element递归、卸载清理 |
| **风险** | 高 — 核心渲染路径 |
| **工作量** | 大（约 2-3 天） |

#### P0-3: RenderNode 职责瘦身

| 项 | 内容 |
|----|------|
| **文件** | `framework/Rendering/RenderNode.php` (33字段) |
| **问题** | 动画状态、交互状态(hover/focus/active)、滚动字段混在一起 |
| **方案** | 拆分：`RenderNode`（布局+绘制核心）、`InteractionState`（hover/focus/active）、滚动字段移入 `ScrollableRenderNode` 子类 |
| **风险** | 高 — 所有布局/渲染代码都使用 RenderNode |
| **工作量** | 中（约 1-2 天，渐进式迁移） |

### P1 — 影响可维护性

#### P1-1: 接口抽象补全

| 项 | 内容 |
|----|------|
| **文件** | `Scheduler.php`、`ScrollManager.php`、`ImageManager.php` |
| **问题** | Application 直接依赖具体类，无法替换实现或单元测试 |
| **方案** | 提取 `IScheduler`、`IScrollManager`、`IImageManager` 接口 |
| **风险** | 低 |
| **工作量** | 小（约 0.5 天） |

#### P1-2: VNodeRenderer 职责拆分

| 项 | 内容 |
|----|------|
| **文件** | `framework/Rendering/VNodeRenderer.php` (1498行) |
| **问题** | 混合了树遍历、元素收集、clip管理、元素生成 |
| **方案** | 拆分为：`ElementCollector` (遍历+收集)、`LayerCompositor` (layer分组+clip)、`ElementBuilder` (生成绘制元素描述) |
| **风险** | 中 |
| **工作量** | 中（约 1-2 天） |

#### P1-3: CssMappings 拆分

| 项 | 内容 |
|----|------|
| **文件** | `framework/Rendering/CssMappings.php` (2056行) |
| **问题** | 数据定义(PROPERTY_MAP) + 解析逻辑 + 选择器匹配混在一起 |
| **方案** | 拆分为：`CssPropertyMap`(纯数据)、`CssValueParser`(解析器，已存在)、`ComplexSelectorMatcher`(选择器匹配) |
| **风险** | 中 — 大量方法被外部调用 |
| **工作量** | 中（约 1 天） |

#### P1-4: sfc-compiler 模块化

| 项 | 内容 |
|----|------|
| **文件** | `framework/compiler/sfc-compiler.php` (2973行) |
| **问题** | 单文件过程式代码，50+ 全局函数，不可测试 |
| **方案** | 拆分为 `SfcCompiler` 类：`TemplateExtractor` → `StyleCompiler` → `TemplateCompiler` → `ScriptCompiler` → `CodeGenerator` → `AotValidator` |
| **风险** | 高 — 编译流程核心 |
| **工作量** | 大（约 2-3 天） |

#### P1-5: LayoutResolver 脏/洁净路径简化

| 项 | 内容 |
|----|------|
| **文件** | `framework/Rendering/LayoutResolver.php` (L427-L498) |
| **问题** | 洁净路径中的子节点脏检测 + 回退重布局构成深层嵌套 |
| **方案** | 在 `RenderTreeManager::updateFromVNode` 层将父节点也标记为脏（当子节点变脏时），消除 LayoutResolver 中的回退逻辑 |
| **风险** | 中 — 可能影响增量布局性能 |
| **工作量** | 中（约 1 天） |

### P2 — 代码质量

#### P2-1: 消除 DRY 违规

| 项 | 内容 |
|----|------|
| **重复1** | `ScrollManager` 中 scrollbar thumb 计算逻辑在 `hitTestScrollbar` + `handleScrollbarDown` + `handleScrollbarDrag` 三处重复 |
| **重复2** | `LayoutResolver::resolveNode` 中 scrollTop clamp 在脏路径 flex/grid 后处理 + block 内部 + 洁净路径底部重复 |
| **重复3** | `RenderTreeManager` 中 layer-aware 遍历在 `hitTestRecursive` + `findScrollContainerRecursive` 几乎相同 |
| **方案** | 提取公共方法 `ScrollMetrics::calculateThumb()`、`RenderTreeManager::traverseLayersDescending()` |
| **工作量** | 小（约 0.5 天） |

#### P2-2: 样式序列化 key 白名单统一

| 项 | 内容 |
|----|------|
| **文件** | `Application.php` L772-L789 |
| **问题** | 70+ 个样式 key 硬编码在 serializeRenderNode 中，添加新属性容易遗漏 |
| **方案** | 从 `CssMappings::PROPERTY_MAP` 动态生成序列化 key 列表，或提取为专用常量 |
| **工作量** | 小（约 0.3 天） |

#### P2-3: 移除 YAGNI 未实现/废弃代码

| 项 | 内容 |
|----|------|
| **TableLayoutStrategy** | 已声明但实现度存疑，确认后删除或标记 `@experimental` |
| **MultiColumnLayoutStrategy** | 同上 |
| **Platform::setCursor** | 已标记 `@deprecated`，空实现，可在下个大版本删除 |
| **VNodeDevTools::findScrollContainers** | 直接返回 `[]`，应删除或实现 |
| **工作量** | 小（约 0.3 天） |

#### P2-4: Win32Platform 硬编码依赖

| 项 | 内容 |
|----|------|
| **文件** | `framework/Platform/Win32Platform.php` L41-L44 |
| **问题** | `init()` 中硬编码 `new GdiRenderContext()` / `new SkiaRenderContext()` |
| **方案** | 通过工厂或依赖注入获取 RenderContext，使 Platform 只负责窗口管理 |
| **风险** | 中 |
| **工作量** | 小（约 0.5 天） |

### P3 — 优化建议

#### P3-1: 全局静态状态治理

| 项 | 内容 |
|----|------|
| **问题** | `ThemeProvider`、`ImageManager`、`Config`、`Scheduler::getInstance()`、`AnimationManager::getInstance()` 全部使用静态全局状态 |
| **影响** | 无法在单进程中运行多个 Px 应用实例；测试难以隔离 |
| **方案** | 引入 `ApplicationContext` 统一管理应用级单例，替代分散的静态属性 |
| **工作量** | 大（约 2 天，渐进式迁移） |

#### P3-2: ComponentInterface 完善

| 项 | 内容 |
|----|------|
| **问题** | `ComponentInterface` 缺少 `dispatchClick`/`dispatchKey` 声明，缺少 `mount`/`unmount` 声明 |
| **方案** | 补充接口方法声明，确保 `BaseComponent` 和 `ReactiveComponent` 的接口契约完整 |
| **工作量** | 小（约 0.2 天） |

#### P3-3: RenderNode 交互状态分离

| 项 | 内容 |
|----|------|
| **问题** | `hovered`/`focused`/`active` 是临时交互状态，存储在 RenderNode 中导致序列化时需要特殊处理 |
| **方案** | 移入独立的 `InteractionManager` 中维护 `RenderNode → InteractionState` 映射 |
| **工作量** | 中（约 1 天） |

#### P3-4: 编译器表达式解析器模块化

| 项 | 内容 |
|----|------|
| **文件** | `framework/compiler/expression/` |
| **评价** | 表达式解析器已按类型拆分（Ternary/Comparison/Logical/Concatenation），结构良好 |

---

## 五、总结

### 5.1 综合评分

| 原则 | 评分 | 关键问题 |
|------|------|----------|
| 单一职责 | 3/10 | Application God Class |
| 开闭原则 | 6/10 | 策略模式不错但 switch 仍需修改 |
| 里氏替换 | 7/10 | 基本合规 |
| 依赖倒置 | 4/10 | 多数依赖具体类 |
| 接口隔离 | 6/10 | 部分接口过大 |
| 高内聚低耦合 | 5/10 | Application 耦合过深 |
| DRY | 5/10 | scroll/样式序列化重复 |
| KISS | 5/10 | 脏/洁净双路径复杂 |
| YAGNI | 5/10 | 未实现策略/废弃方法 |
| 迪米特法则 | 4/10 | 多处穿透访问 |
| 分层单向依赖 | 7/10 | 基本合规 |
| 无循环依赖 | 7/10 | 弱双向但无死循环 |
| 最小数据冗余 | 5/10 | VNode/RenderNode 字段重叠 |
| **综合** | **5.3/10** | **中等偏下** |

### 5.2 亮点（值得保留的设计）

1. **Backend 系统** — `IRenderBackend` + `RuntimeBackendSelector` + `ResilientRenderContext` 三层降级设计，是优秀的可扩展架构
2. **Layout 策略模式** — `LayoutStrategyInterface` + 7 个策略类，职责清晰
3. **ScrollManager 闭包注入** — 虽然无接口抽象，但通过闭包回调避免循环依赖的思路正确
4. **VNode::childrenToArray** — 消除重复实现的良好实践
5. **PerfCounter** — 轻量级性能计数器设计合理
6. **编译器表达式解析器** — 按类型拆分，模块化良好
7. **VNode ↔ RenderNode 分离** — 描述与渲染分离的设计方向正确，只是 RenderNode 走得太远

### 5.3 建议执行路线

```
Phase 1 (P0 - 架构重构, 约 7-10 天):
  ├── Application 拆分为 BuildOwner + RenderPipeline + EventDispatcher
  ├── 引入 ComponentElement
  └── RenderNode 瘦身第一阶段

Phase 2 (P1 - 可维护性, 约 5-7 天):
  ├── 接口抽象补全
  ├── VNodeRenderer / CssMappings 拆分
  └── LayoutResolver 脏/洁净路径简化

Phase 3 (P2 - 代码质量, 约 2-3 天):
  ├── 消除 DRY 违规
  ├── 移除 YAGNI 代码
  └── 样式序列化 key 白名单统一

Phase 4 (P3 - 优化, 渐进):
  ├── 全局状态治理
  └── 交互状态分离
```