# 核心架构与关键类速查

> **何时加载**：需要理解数据流、模块职责边界、关键类 API 时加载此文档。

---

## 目录结构总览

```
framework/
├── Animation/          动画系统（AnimationManager/CssAnimationParser/EasingFunctions/Interpolator/KeyframeResolver/TransitionComponent/TransitionController/TransitionGroupComponent）
├── Component/          组件系统
│   ├── Contracts/      ComponentInterface/ReactiveComponentInterface
│   ├── BaseComponent.php        组件基类（生命期 + 父子层级）
│   └── ReactiveComponent.php    响应式组件基类（dirty + VNode 缓存 + $emit）
├── Compiler/           编译器
│   ├── sfc-compiler.php         主编译器：.vue → PHP 代码生成
│   ├── TemplateParser.php       模板解析器（HTML → VNode 树）
│   ├── ScriptAnalyzer.php       脚本分析器（自动注入 markDirty）
│   ├── ComponentRegistry.php    组件注册器
│   ├── AotValidator.php         AOT 兼容性验证器
│   ├── CompilerPipeline.php     编译管道协调
│   ├── Codegen/                 代码生成器（5 个文件）
│   ├── Helpers/                 编译器辅助（5 个文件）
│   ├── Transform/               AST 变换（6 个文件）
│   └── expression/              表达式解析器（6 个文件）
├── Core/               核心
│   ├── Application.php     事件路由、组件注册、VNode 树展开、bind 解析
│   ├── ScrollManager.php   滚动服务
│   ├── Scheduler.php       微任务/宏任务调度器
│   ├── Config.php          配置管理类
│   ├── Diag.php            诊断日志系统
│   └── PerfCounter.php     性能计数器（PX_PERF=1 启用）
├── Css/                样式系统
│   ├── ComputedStyle.php    计算样式
│   ├── CssColor.php         颜色值类型
│   ├── CssFlex.php          Flex 值类型
│   ├── CssKeyword.php       关键字值类型
│   ├── CssLength.php        长度值类型
│   ├── CssMappings.php      CSS 属性 → GDI 属性映射
│   ├── CssRect.php          矩形值类型
│   ├── CssValue.php         CSS 值对象
│   ├── CssValueParser.php   CSS 值解析器
│   ├── StylePool.php        样式池（共享不可变样式）
│   ├── StyleRecalcPass.php  样式重算
│   └── StyleResolver.php    样式解析器
├── DevTools/           VNodeDevTools
├── Dom/                VNode.php（虚拟 DOM 节点）
├── Layout/             布局引擎（LayoutNG 对标 Blink）
│   ├── LayoutOrchestrator.php       布局编排器入口
│   ├── LayoutAlgorithm.php          布局算法基类
│   ├── LayoutInputNode.php          布局输入节点
│   ├── LayoutResult.php             布局结果
│   ├── ConstraintSpace.php          约束空间
│   ├── ConstraintSpaceBuilder.php   约束空间构建器
│   ├── PhysicalFragment.php         物理片段（不可变输出）
│   ├── PhysicalFragmentBuilder.php  片段构建器
│   ├── MarginStrut.php              外边距折叠支撑
│   ├── ChildLayoutProvider.php      子布局提供者
│   ├── TextMeasureCache.php         文本测量缓存
│   ├── LruNode.php                  LRU 缓存节点
│   ├── BlockAlgorithm.php           Block 布局
│   ├── FlexAlgorithm.php            Flex 布局
│   ├── GridAlgorithm.php            Grid 布局
│   ├── InlineAlgorithm.php          Inline 布局
│   ├── OOFLayoutAlgorithm.php       OOF 定位
│   ├── TableAlgorithm.php           Table 布局
│   ├── TextOverflowProcessor.php    文本溢出处理
│   ├── Flex/（FlexItem/FlexLineBreaker）
│   └── Grid/（GridItem/GridPlacer/GridTrack/GridTracker）
├── Paint/              绘制层
│   ├── Backend/            渲染后端系统（6 个候选 + 故障降级）
│   ├── GdiRenderContext.php/SkiaRenderContext.php/RenderContext.php
│   ├── VNodeRenderer.php   树遍历 → 收集元素 → 按 layer → RenderContext
│   ├── ImageManager.php    图片缓存管理器
│   └── InteractionState.php
├── Platform/           跨平台抽象
│   ├── Platform.php         平台抽象接口
│   ├── Win32Platform.php    Win32 消息循环 + 事件解码
│   ├── PlatformEvent.php    事件基类
│   ├── PlatformFactory.php  平台工厂
│   ├── MouseEvent.php       鼠标事件
│   ├── KeyboardEvent.php    键盘事件
│   ├── WindowEvent.php      窗口事件
│   ├── IoEvent.php          IO 事件
│   ├── TimerEvent.php       定时器事件
│   └── WinMsg.php           Win32 消息常量
├── Reactive/           原生响应式系统（#[Reactive] 属性标记）
│   ├── DependencyTracker.php   依赖追踪器（track/notify + Effect 栈）
│   ├── DependentsMap.php       依赖映射（depId → Effect[]）
│   ├── Effect.php              副作用（组件 render + 微任务调度）
│   ├── Notifier.php            通知器
│   └── Reactive.php            #[Reactive] 属性注解
├── Render/             渲染树管理（RenderNode/RenderTreeManager/ScrollState）
├── Text/               文本渲染后端（多后端架构）
│   ├── ITextBackend.php              文本后端接口
│   ├── TextBackendSelector.php       后端选择器
│   ├── TextBackendRegistry.php       后端注册表
│   ├── GdiTextBackend.php            GDI 文本后端
│   ├── SkiaTextBackend.php           Skia 文本后端
│   ├── DWriteTextBackend.php         DirectWrite 文本后端
│   └── ResilientTextBackendProxy.php 故障降级代理
└── Theme/              主题系统
    ├── ThemeProvider.php      主题提供者
    ├── ThemeData.php          主题数据
    ├── ColorScheme.php        配色方案
    ├── ComponentTheme.php     组件主题
    ├── TextTheme.php          文本主题
    ├── PlatformAdapter.php    平台适配器
    ├── PlatformStyling.php    平台样式基类
    ├── Win32Styling.php       Windows 样式
    ├── MacOSStyling.php       macOS 样式
    └── LinuxStyling.php       Linux 样式
```

---

## 完整数据流

```
用户在窗口中操作
    │
    ▼
Platform (Win32Platform::pollEvents)
    │  WM_LBUTTONDOWN → MouseEvent(action='down', x, y)
    │  WM_MOUSEWHEEL  → MouseEvent(action='wheel', x, y, delta)
    │  WM_KEYDOWN     → KeyboardEvent(action='down', keyCode, char)
    ▼
Application::handleMouseEvent / handleKeyboardEvent
    │
    ├─ 滚轮：findScrollContainerAt → handleScrollWheel → applyScrollTop → requestRender
    ├─ 拖拽：hitTestScrollbar → handleScrollbarDown → handleScrollbarDrag → directRender
    └─ 点击：hitTest → resolveComponent → dispatchClick(handler, arg)
         │
         ▼
    Component 方法（如 deleteItem）
         │ 修改 $this->todoItems → $this->markDirty()
         ▼
    Scheduler::flushMicrotasks
         │  performUpdate → renderCallback → Application::requestRender
         ▼
    Application::render
         │
         ├─ rebuildVNodeTree
         │   ├─ rootComponent->getVNodeTree()    // 调用 render()，返回 VNode 树
         │   ├─ expandComponentTree()             // 展开子组件占位节点
         │   └─ resolveVNodeBindings()            // 将组件 bind 值写入 VNode
         │
         ├─ RenderTreeManager::updateFromVNode
         │   ├─ VNode 树 → RenderNode 树转换
         │   ├─ 跨帧复用匹配（基于 key + type + groupId）
         │   ├─ 脏标记传播 + positioningAncestor 缓存失效
         │   └─ 保留动画状态（animatedStyle/isAnimating）
         │
         ├─ LayoutResolver::resolve（委派策略类）
         │   ├─ AbsolutePositioning / BlockLayoutStrategy / FlexLayoutStrategy / GridLayoutStrategy
         │   ├─ 解析 CSS styles → 按 display 模式计算 x/y/w/h
         │   ├─ auto-stack 垂直排列子节点
         │   └─ clamp scrollTop + 子节点重定位
         │
         └─ VNodeRenderer::render（遍历 RenderNode 树）
             ├─ collectElements（按 layer 分组，scroll/overflow:hidden 生成 clip-push/clip-pop）
             └─ RenderContext::drawElement（通过 Backend 系统委派给具体实现）
```

---

## 职责边界（SOLID）

| 模块 | 负责 | 不负责 |
|------|------|--------|
| Component | 声明状态 + 绑定描述 | 不参与坐标计算 |
| Application | 事件路由 + bind 解析 | 不参与布局计算 |
| RenderTreeManager | VNode → RenderNode 转换 | 不参与坐标计算 |
| LayoutResolver | 所有坐标计算 + 委派策略类 | 不参与渲染绘制 |
| VNodeRenderer | 收集元素 + clip 裁切 | 不修改坐标 |
| RenderContext | 渲染原语（GDI/Skia/其他） | 不参与布局计算 |
| Backend | 运行时后端选择 + 故障降级 | 不参与布局计算 |

> **核心原则**：VNode 的 x/y 坐标由 LayoutResolver 一家说了算。Application 只通过 bind 机制（`:scroll-top`）间接影响布局，不直接操作坐标。

---

## 关键类速查

### VNode（framework/Dom/VNode.php）

**重要字段**：
```php
// ———— 树结构 ————
string  $type;         // 'div'|'span'|'button'|'input'|'#root'|'#component'|'#text'
?array  $props;        // HTML 属性 + Vue 指令（@click, :bind, v-for, :scroll-top 等）
mixed   $children;     // VNode[] | VNode | string | null
?string $key;          // v-for key
string $groupId = 'app';      // 事件路由 key

// ———— 组件占位 ————
bool $isComponent;
?string $componentClass;
?ReactiveComponent $componentInstance;
?array $componentProps;       // 子组件属性映射
?array $componentPropValues;  // v-for 循环中预计算的属性值
?array $layoutOffset;         // 父组件传递的定位偏移 ['left'=>int, 'top'=>int]
```

**工厂方法**：
```php
VNode::h('div', ['style'=>'width:100px;height:50px'], [$child])
VNode::hKey('div', [...], $children, 'item-1')       // 带 v-for key
VNode::hComponent('MyComponent', [...props], [...bindings])  // 子组件占位
```

**常用辅助方法**：`getProp(name, default)`, `getClass()`, `getInlineStyle()`, `isRoot()`, `isComponent()`

---

### ReactiveComponent（framework/Component/ReactiveComponent.php）

**关键状态**：
```php
bool $dirty;            // true → 下次 getVNodeTree() 会重新 render()
?VNode $vnodeCache;     // 缓存的上次渲染结果
bool $isMounted;        // mount() 之后为 true
```

**核心流程**：
```php
$this->markDirty();
// 等价于：$this->vnodeCache = null; $this->scheduleUpdate();
// 在微任务中 → performUpdate() → renderCallback() → Application::requestRender()
// 在事件循环的下一 tick → Application::render() → getVNodeTree() → $this->render()
```

**必须实现的抽象方法**：
```php
abstract public function render(): VNode;
abstract public function setBindValue(string $key, string $val);
abstract public function getBindValue(string $key): string;
```

**子→父通信**：
```php
$this->emit('itemSelected', ['id' => 5]);
$this->on($child, 'itemSelected', function($payload) { ... });
```

---

### RenderNode（framework/Render/RenderNode.php）

**RenderNode 是渲染专用节点**，持有布局结果和渲染数据，与 VNode 分离。

**关键字段**：
```php
string $type;              // 'div'|'span'|'button'|'input'|'text'
array $style;              // 已解析的 GDI 可用样式
mixed $content;            // 文本内容或子节点数组
?string $key;              // v-for key
int $x, $y, $w, $h;       // 绝对坐标（由 LayoutResolver 填入）
int $layer;                // z-order
bool $isScrollContainer;
int $scrollTop, $scrollLeft;
int $contentHeight, $contentWidth;
?array $animatedStyle;     // 动画叠加样式
bool $isAnimating;
bool $layoutDirty;         // true → 需要重新计算布局
?RenderNode $parent;
?RenderNode $positioningAncestor;
?VNode $sourceVNode;
array $children;
?string $groupId;
```

**关键方法**：`markLayoutDirty()`, `markSubtreeDirty()`, `needsPaint()`, `addChild()`, `clearChildren()`

---

### Config（framework/Core/Config.php）

- 静态类，由 `Application::mount()` 初始化，从 `{APP_DIR}/project.yml` 中读取 `Px_debug_` 前缀配置
- 核心方法：`init(string $appDir)`、`get(string $key, mixed $default)`、`getAppDir()`、`getOutputDir()`
- AOT 兼容：`use native_types`，静态 `$cache`/`$appDir`

---

### RenderTreeManager（framework/Render/RenderTreeManager.php）

渲染树管理，VNode 树的脏路径追踪、差异比较、缓存快照管理。在 `Application::render()` 中协调 VNode 树的构建/重建/差异更新。

---

### Application（framework/Core/Application.php）

**核心流程**：
```php
Application::create()->mount($root)->run();
```

**关键方法**：
```php
registerComponent(string $groupId, ReactiveComponent $comp)
initRenderer(): void               // Backend 探测+选择
hitTest(int $x, int $y): ?RenderNode
render(): void          // 完整渲染：VNode → RenderNode → Layout → Draw
requestRender(): void   // 异步请求渲染
directRender(): void    // 跳过 VNode 树重建，仅重新 layout + render
```

**渲染流程**：
```
VNode 树重建 → RenderTreeManager::updateFromVNode
→ LayoutResolver::resolve（RenderNode 坐标计算）
→ VNodeRenderer::render（RenderNode 树 → Backend 委派的 RenderContext 调用）
```

**Backend 初始化流程**（Application::initRenderer()）：
```
1. Platform::init(…) 创建窗口 + 创建默认 RenderContext
2. 检查 C++ 绑定（vue_begin_paint 等原生函数）
   - 不存在 → 测试环境，直接使用默认 RenderContext
   - 存在 → 继续 Step 3
3. RuntimeBackendSelector 遍历 6 个候选后端（按优先级）
4. 包一层 ResilientRenderContext（连续失败 N 次自动降级）
5. 创建 VNodeRenderer(renderCtx) 开始工作
```

**后端候选列表**（按优先级降序）：

| 优先级 | 后端名称 | 说明 |
|--------|---------|------|
| 100 | SkiaGraphiteDawnBackend | Skia Dawn（阶段五） |
| 90  | SkiaGaneshD3D11Backend  | Skia D3D11（阶段四） |
| 80  | SkiaGaneshWGLBackend   | Skia WGL（阶段四） |
| 60  | SkiaCpuBackend          | Skia CPU（阶段三，当前可用） |
| 50  | GdiDirect2DBackend      | GDI Direct2D（阶段四） |
| 10  | GdiLegacyBackend        | GDI 传统（永远可用） |

> **强制选择**：`PX_RENDERER=skia-cpu|skia-d3d11|gdi-legacy`
> **传统兼容**：`const APP_RENDERER = 'skia'`

---

### ImageManager（framework/Paint/ImageManager.php）

静态图片缓存管理器：路径→句柄映射，避免重复加载，退出时统一释放。

```php
ImageManager::setAppRoot(string $appDir)
ImageManager::loadImage(string $path): int    // 返回句柄（0=失败）
ImageManager::freeAll(): void                 // 释放所有资源
ImageManager::isCached(string $path): bool
```

---

### PerfCounter（framework/Core/PerfCounter.php）

轻量级静态性能计数器，`PX_PERF=1` 时启用，默认关闭零开销。

```php
PerfCounter::start(string $name)
PerfCounter::end(string $name)
PerfCounter::inc(string $name, int $delta = 1)
PerfCounter::snapshot(): array
```

启用方式：`$env:PX_PERF=1; .\bin\my_app.exe`
