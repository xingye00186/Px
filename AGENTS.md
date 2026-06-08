# Px Framework — AI-Friendly 全流程指南

## 一、一句话概述

Px 是一款 **PHP → 原生 exe** 的跨平台 GUI 框架，模板语法类似 **Vue 3**，渲染引擎基于 **Win32 GDI**，通过 **Swoole Compiler** 实现 AOT 编译　

```
.vue 文件 → sfc-compiler.php → 生成 PHP 代码 → Swoole Compiler → C++ → MSVC → .exe
```

---

## 二、目录结构总览

```
d:/Px/
├── framework/              核心框架（只读，所有应用共享）
│   ├── Core/
│   │   ├── Application.php     事件路由、组件注册、VNode 树展开、bind 解析
│   │   ├── ScrollManager.php   滚动服务（状态管理、拖拽、滚轮、水平滚动）
│   │   ├── Scheduler.php       微任务/宏任务调度器
│   │   ├── Config.php          配置管理类（px_debug.yml 解析）
│   │   └── PerfCounter.php     性能计数器（PX_PERF=1 启用，微秒计时）
│   ├── Rendering/
│   │   ├── VNode.php           虚拟 DOM 节点（元素描述 + 组件占位字段）
│   │   ├── RenderNode.php      渲染专用节点（布局结果 + 脏标记 + 动画字段）
│   │   ├── RenderTreeManager.php 渲染树管理（VNode → RenderNode 转换/差异更新/命中测试）
│   │   ├── VNodeRenderer.php   树遍历 → 收集元素 → 按 layer 分组 → 调用 RenderContext
│   │   ├── LayoutResolver.php  CSS 布局引擎入口（委派策略类）
│   │   ├── CssMappings.php     CSS 属性 → GDI 属性映射
│   │   ├── RenderContext.php   渲染上下文抽象基类（beginFrame/endFrame/drawElement/fillRect/drawText/drawButton）
│   │   ├── GdiRenderContext.php Win32 GDI 绘制实现
│   │   ├── SkiaRenderContext.php Skia 渲染实现（阶段三：CPU 离屏 + GDI 桥接）
│   │   ├── ImageManager.php    图片缓存管理器（路径→句柄映射，自动释放）
│   │   ├── Layout/             布局策略类（LayoutResolver 拆分产物）
│   │   │   ├── AbsolutePositioning.php   绝对定位解析
│   │   │   ├── BlockLayoutStrategy.php   Block 布局策略
│   │   │   ├── FlexLayoutStrategy.php    Flex 布局策略
│   │   │   ├── GridLayoutStrategy.php    Grid 布局策略
│   │   │   ├── PercentResolver.php       百分比值解析
│   │   │   └── ScrollHelper.php          滚动容器辅助
│   │   └── Backend/            渲染后端系统（运行时自动探测+故障降级）
│   │       ├── IRenderBackend.php            后端统一接口
│   │       ├── BackendRegistry.php           后端注册表（6 个候选）
│   │       ├── BackendCapability.php         探测结果描述
│   │       ├── BackendInitException.php      初始化异常
│   │       ├── RenderBackendFailedException.php 运行期异常
│   │       ├── RuntimeBackendSelector.php    运行时选择器（probe+fallback）
│   │       ├── ResilientRenderContext.php    故障降级代理（连续失败 N 次自动切换）
│   │       ├── GdiLegacyBackend.php          GDI 传统后端（永远可用，优先级 10）
│   │       ├── GdiDirect2DBackend.php        GDI Direct2D 后端（优先级 50）
│   │       ├── SkiaCpuBackend.php            Skia CPU 后端（优先级 60）
│   │       ├── SkiaGaneshD3D11Backend.php    Skia D3D11 后端（优先级 90）
│   │       ├── SkiaGaneshWGLBackend.php      Skia WGL 后端（优先级 80）
│   │       └── SkiaGraphiteDawnBackend.php   Skia Dawn 后端（优先级 100）
│   ├── Platform/
│   │   ├── Platform.php        平台抽象接口
│   │   ├── Win32Platform.php    Win32 消息循环 + 事件解码
│   │   ├── PlatformEvent.php   事件类型层级（Mouse/Keyboard/Window/Timer）
│   │   ├── PlatformFactory.php 平台工厂
│   │   └── WinMsg.php          Win32 消息常量
│   ├── Styling/                主题/样式系统
│   │   ├── Adapter/            平台适配器
│   │   │   ├── PlatformAdapter.php    平台适配器工厂
│   │   │   ├── PlatformStyling.php    平台样式接口
│   │   │   ├── Win32Styling.php       Win32 主题适配
│   │   │   ├── MacOSStyling.php       macOS 主题适配
│   │   │   └── LinuxStyling.php       Linux 主题适配
│   │   ├── Provider/ThemeProvider.php  主题提供者
│   │   ├── Resolver/StyleResolver.php  样式解析器
│   │   └── Theme/              主题数据（ColorScheme/ComponentTheme/TextTheme/ThemeData）
│   ├── Animation/              动画系统
│   │   ├── AnimationManager.php     动画管理器
│   │   ├── CssAnimationParser.php   CSS 动画解析器
│   │   ├── EasingFunctions.php      缓动函数
│   │   ├── Interpolator.php         插值器
│   │   ├── KeyframeResolver.php     关键帧解析
│   │   ├── TransitionComponent.php  过渡组件封装
│   │   ├── TransitionController.php 过渡控制器
│   │   └── TransitionGroupComponent.php 过渡组封装
│   ├── interfaces/
│   │   └── ComponentInterface.php  组件接口契约
│   ├── DevTools/
│   │   └── VNodeDevTools.php       VNode 树调试工具（快照/对比/搜索）
│   ├── compiler/
│   │   ├── sfc-compiler.php    主编译器：.vue → PHP 代码生成
│   │   ├── template-parser.php 模板解析器（HTML → VNode 树）
│   │   ├── script-analyzer.php  脚本分析器（自动注入 markDirty）
│   │   ├── component-registry.php 组件注册器
│   │   ├── aot-validator.php    AOT 兼容性验证器
│   │   └── expression/          表达式解析器（编译器内部）
│   │       ├── ExpressionParser.php             表达式解析器入口
│   │       ├── ExpressionParserInterface.php    解析器接口契约
│   │       ├── ExpressionType.php               表达式类型枚举
│   │       ├── ExpressionTypeInterface.php      类型接口契约
│   │       ├── ComparisonExpression.php         比较表达式
│   │       ├── ConcatenationExpression.php      连接表达式
│   │       ├── LogicalExpression.php            逻辑表达式
│   │       └── TernaryExpression.php            三元表达式
│   ├── BaseComponent.php        组件基类（生命期 + 父子层级）
│   ├── ReactiveComponent.php    响应式组件基类（dirty + VNode 缓存 + $emit）
│   └── aot-checker.php          AOT 规则检查工具（20K 行）
├── apps/                  每个应用一个独立目录（共 11 个）
│   ├── bilibili/             Bilibili 面板复刻应用
│   ├── calculator-ng/        计算器演示（4 组件、CSS Grid 布局）
│   ├── skia-poc/             Skia 渲染后端 POC 验证应用
│   ├── list-test/             列表滚动测试（v-for + scroll-container）
│   ├── multi-scroll/          多滚动容器测试
│   ├── design-guide/          设计指南说明应用
│   ├── aot-property-test/     AOT 属性访问兼容性测试
│   ├── aot-syntax-test/       AOT 语法兼容性测试
│   ├── array-assign-test/     AOT 数组赋值测试（Direct Call Optimization）
│   ├── roadmap/               Px 框架路线图应用（HTML 迁移示例）
│   └── video-site/            视频站点示例应用
├── stub/                   PHP stub 文件（C++ 原生函数声明）
├── cpp/                    C++ 桥接层实现（vue_calc.cc + skia_render.cc + skia_dinkumware_stubs.cc）
├── docs/                   设计文档
├── tests/                  单元测试（PHPUnit 风格 + 截图测试）
│   ├── unit/               单元测试
│   ├── screenshot/         截图自动化测试（PowerShell）
│   └── run_all_tests.php   统一测试运行器
├── build.bat               非交互构建脚本
├── sfc-compiler.php        编译器入口（框架根目录）
├── config.yml              编译器路径配置
└── vendor/                 依赖（Composer）
```

### 应用目录模板

```
apps/<app-name>/
├── App.vue                 根组件 SFC
├── main.php                入口（定义 AOT 常量 + main() 函数）
├── project.yml             构建配置
├── components/             子组件（可选）
│   └── *.vue
├── gen/                    自动生成的 PHP 组件（由 sfc-compiler 产出）
│   ├── AppComponent.php
│   ├── *Component.php
│   └── ComponentFactory.php
└── bin/                    构建输出（.exe + .dll）
```

---

## 三、核心架构

### 3.1 完整数据流

```
用户在窗口中操作
    ─
    ▀
Platform (Win32Platform::pollEvents)
    ─   WM_LBUTTONDOWN → MouseEvent(action='down', x, y)
    ─   WM_MOUSEWHEEL  → MouseEvent(action='wheel', x, y, delta)
    ─   WM_KEYDOWN      → KeyboardEvent(action='down', keyCode, char)
    ▀
Application::handleMouseEvent / handleKeyboardEvent
    ─
    ├─ 滚轮： findScrollContainerAt → handleScrollWheel → applyScrollTop → requestRender
    ├─ 拖拽： hitTestScrollbar → handleScrollbarDown → handleScrollbarDrag → directRender
    └─ 点击： hitTest → resolveComponent → dispatchClick(handler, arg)
         ─
         ▀
    Component 方法（如 deleteItem）
         ─ 修改 $this->todoItems → $this->markDirty()
         ▀
    Scheduler::flushMicrotasks
         ─  performUpdate → renderCallback → Application::requestRender
         ▀
    Application::render
         ─
         ├─ rebuildVNodeTree
         ─   ├─ rootComponent->getVNodeTree()    // 调用 render()，返回 VNode 树
         ─   ├─ expandComponentTree()             // 展开子组件占位节点
         ─   └─ resolveVNodeBindings()            // 将组件 bind 值写入 VNode
         ─
         ├─ RenderTreeManager::updateFromVNode
         ─   ├─ VNode 树 → RenderNode 树转换
         ─   ├─ 跨帧复用匹配（基于 key + type + groupId）
         ─   ├─ 脏标记传播 + positioningAncestor 缓存失效
         ─   └─ 保留动画状态（animatedStyle/isAnimating）
         ─
         ├─ LayoutResolver::resolve（委派策略类）
         ─   ├─ AbsolutePositioning / BlockLayoutStrategy / FlexLayoutStrategy / GridLayoutStrategy
         ─   ├─ 解析 CSS styles → 按 display 模式计算 x/y/w/h
         ─   ├─ auto-stack 垂直排列子节点
         ─   └─ clamp scrollTop + 子节点重定位
         ─
         └─ VNodeRenderer::render（遍历 RenderNode 树）
             ├─ collectElements（按 layer 分组，scroll/overflow:hidden 生成 clip-push/clip-pop）
             └─ RenderContext::drawElement（通过 Backend 系统委派给具体实现）
```

### 3.2 职责边界（SOLID）

```
┌─────────────────────────────────────────────────────────────────────┐
│ 模块              负责                        不负责              │
├─────────────────────────────────────────────────────────────────────┤
│ Component         声明状态 + 绑定描述            不参与坐标计算    │
│ Application       事件路由 + bind 解析         不参与布局计算      │
│ RenderTreeManager VNode → RenderNode 转换     不参与坐标计算      │
│ LayoutResolver    所有坐标计算 + 委派策略类    不参与渲染绘制      │
│ VNodeRenderer     收集元素 + clip 裁切         不修改坐标         │
│ RenderContext     渲染原语（GDI/Skia/其他）   不参与布局计算      │
│ Backend           运行时后端选择 + 故障降级    不参与布局计算      │
└─────────────────────────────────────────────────────────────────────┘
```

> **核心原则**：VNode 的 x/y 坐标由 LayoutResolver 一家说了算。Application 只通过 bind 机制（`:scroll-top`）间接影响布局，不直接操作坐标　

---

## 四、关键类速查

### 4.1 VNode（framework/Rendering/VNode.php）

**重要的字段**（按使用频率排序）：

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
?array $componentPropValues;  // v-for 循环中预计算的属性值（绕开 bind key 查找）
?array $layoutOffset;         // 父组件传递的定位偏移 ['left'=>int, 'top'=>int]
```

**工厂方法**：
```php
VNode::h('div', ['style'=>'width:100px;height:50px'], [$child])
VNode::hKey('div', [...], $children, 'item-1')       // 带 v-for key
VNode::hComponent('MyComponent', [...props], [...bindings])  // 子组件占位
```

**常用辅助方法**：`getProp(name, default)`, `getClass()`, `getInlineStyle()`, `isRoot()`, `isComponent()`

### 4.2 ReactiveComponent（framework/ReactiveComponent.php）

**关键状态**：
```php
bool $dirty;            // true → 下次 getVNodeTree() 会重新 render()
?VNode $vnodeCache;     // 缓存的上次渲染结果
bool $isMounted;        // mount() 之后为 true
```

**核心流程**：
```php
// 状态变更 → 触发重渲染的标准方式
$this->markDirty();
// 等价于：
//   $this->vnodeCache = null;
//   $this->scheduleUpdate();  // 将 performUpdate() 加入微任务队列

// 在微任务中 → performUpdate() → renderCallback() → Application::requestRender()
// 在事件循环的下一 tick → Application::render() → getVNodeTree() → $this->render()
```

**必须实现的抽象方法**：
```php
abstract public function render(): VNode;                       // 返回 VNode 树
abstract public function setBindValue(string $key, string $val);  // 写入绑定值
abstract public function getBindValue(string $key): string;      // 读取绑定值
```

**子→父通信**：
```php
// 子组什
$this->emit('itemSelected', ['id' => 5]);
// 父组什
$this->on($child, 'itemSelected', function($payload) { ... });
```

### 4.3 RenderNode（framework/Rendering/RenderNode.php）

**RenderNode 是渲染专用节点**，持有布局结果和渲染数据，与 VNode（元素描述）分离。由 RenderTreeManager 从 VNode 树转换生成。

**关键字段**：
```php
// ———— 类型与内容 ————
string $type;              // 'div'|'span'|'button'|'input'|'text'
array $style;              // 已解析的 GDI 可用样式（来自 VNode.computedStyle）
mixed $content;            // 文本内容或子节点数组
?string $key;              // v-for key

// ———— 布局结果（由 LayoutResolver 填入）————
int $x, $y, $w, $h;       // 绝对坐标
int $layer;                // z-order

// ———— 滚动容器 ————
bool $isScrollContainer;
int $scrollTop;            // 垂直滚动偏移 (px)
int $scrollLeft;           // 水平滚动偏移 (px)
int $contentHeight;        // 可滚动内容实际高度 (px)
int $contentWidth;         // 可滚动内容实际宽度 (px)
int $lastScrollTop;        // 上次渲染时 scrollTop（快速滚动路径比较）
int $scrollOffsetX;        // 当前累计视口滚动偏移 X
int $scrollOffsetY;        // 当前累计视口滚动偏移 Y

// ———— 动画专用 ————
?array $animatedStyle;     // 动画叠加样式（AnimationManager 每帧更新）
bool $isAnimating;         // 是否正在动画中
int $lastX, $lastY;        // 上次布局完成时的坐标（FLIP 算法）

// ———— 脏标记 ————
bool $layoutDirty;         // true → 需要重新计算布局
int $lastPaintFrame;       // 最后绘制帧号（增量绘制判断）

// ———— 树关系 ————
?RenderNode $parent;
?RenderNode $positioningAncestor;  // 定位祖先（position 非 static 的最近祖先）
bool $positioningAncestorValid;   // 定位祖先缓存是否有效
?VNode $sourceVNode;              // 来源 VNode（bind 值同步/事件路由）
array $children;
?string $groupId;                  // 所属组件 ID
```

**关键方法**：
- `markLayoutDirty(bool $propagateUp)` — 标记脏并可选向上传播
- `markSubtreeDirty()` — 标记整个子树为脏
- `needsPaint(int $currentFrame)` — 判断是否需要绘制
- `addChild(RenderNode $child)` / `clearChildren()` — 树管理



### 4.4 Config（framework/Core/Config.php）

**关键使用**：
- 静态类，由 `Application::mount()` 初始化，读取 `{APP_DIR}/px_debug.yml`
- 核心方法：`init(string $appDir)`、`get(string $key, mixed $default)`、`getAppDir()`、`getOutputDir()`
- AOT 兼容：`use native_types`，静态 `$cache`/`$appDir`
- 应用示例：`apps/bilibili/px_debug.yml`

### 4.5 RenderTreeManager（framework/Rendering/RenderTreeManager.php）

**职责**：渲染树管理，提供 VNode 树的脏路径追踪、差异比较、缓存快照管理。在 `Application::render()` 中协调 VNode 树的构建/重建/差异更新节奏。

### 4.6 Application（framework/Core/Application.php）

**Application 是 AOT 框架入口**，持有 Platform、Scheduler、ScrollManager、RenderTreeManager，负责事件循环、Backend 选择、渲染流程调度。

**核心流程**：
```php
Application::create()->mount($root)->run();
```

**关键方法**：
```php
// 组件注册
registerComponent(string $groupId, ReactiveComponent $comp)
unregisterComponent(string $groupId)

// 渲染后端
initRenderer(): void               // Backend 探测+选择（RuntimeBackendSelector + ResilientRenderContext）
getSelectedBackendName(): ?string

// 命中测试（委托 RenderTreeManager，返回 RenderNode）
hitTest(int $x, int $y): ?RenderNode

// 渲染流程
render(): void          // 完整渲染：VNode → RenderNode → Layout → Draw
requestRender(): void   // 异步请求渲染（通过微任务延迟）
directRender(): void    // 跳过 VNode 树重建，仅重新 layout + render

// 访问器
getPlatform(): Platform
getScheduler(): Scheduler
getRenderTreeManager(): RenderTreeManager
```

**渲染流程**（Backend 重构后）：
```
VNode 树重建 → RenderTreeManager::updateFromVNode（VNode → RenderNode + bind 值同步）
→ LayoutResolver::resolve（RenderNode 坐标计算）
→ VNodeRenderer::render（RenderNode 树 → Backend 委派的 RenderContext 调用）
```

**Backend 初始化流程**（Application::initRenderer()）：
```
1. Platform::init(…) 创建窗口 + 创建默认 RenderContext（测试兼容）
2. 检查 C++ 绑定（vue_begin_paint 等原生函数是否存在）
   - 不存在 → 测试环境，直接使用默认 RenderContext
   - 存在 → 继续 Step 3
3. RuntimeBackendSelector 遍历 6 个候选后端（按优先级）
   依次 probe() → 跳过不可用 → 第一个 initialize() 成功即为选中
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

> **强制选择**：设置环境变量 `PX_RENDERER=skia-cpu|skia-d3d11|gdi-legacy` 跳过自动探测
> **传统兼容**：仍支持 `const APP_RENDERER = 'skia'` 在 Win32Platform::init() 中直接选择 SkiaRenderContext（零侵入）

### 4.7 ImageManager（framework/Rendering/ImageManager.php）

**ImageManager 是静态图片缓存管理器**，负责：
- 缓存已加载的图片句柄（路径→句柄映射），避免重复加载
- 将相对路径解析为绝对路径（基于应用根目录）
- 应用退出时统一释放所有图片资源

**静态方法**：
```php
ImageManager::setAppRoot(string $appDir)     // 设置应用根目录（由 Application::mount() 调用）
ImageManager::loadImage(string $path): int    // 加载图片，返回句柄（0=失败）
ImageManager::freeImage(string $path): void   // 释放指定图片
ImageManager::freeAll(): void                 // 释放所有图片资源（run() 结束时自动调用）
ImageManager::getHandle(string $path): int    // 获取已缓存的句柄（不触发加载）
ImageManager::isCached(string $path): bool    // 检查图片是否已缓存
ImageManager::getCacheSize(): int             // 获取缓存中的图片数量（诊断用）
```

> 底层调用 `sk_load_image`/`sk_free_image`，skia_render.cc 在 USE_SKIA/非 USE_SKIA 下都有实现

### 4.8 PerfCounter（framework/Core/PerfCounter.php）

**PerfCounter 是轻量级静态性能计数器**，环境变量 `PX_PERF=1` 时启用，默认关闭零开销。

**静态方法**：
```php
PerfCounter::start(string $name)       // 开始计时（微秒 μs）
PerfCounter::end(string $name)         // 结束计时，记录耗时
PerfCounter::inc(string $name, int $delta = 1)    // 递增计数器
PerfCounter::snapshot(): array         // 获取快照并自动重置所有计数器
PerfCounter::formatSnapshot(array $snapshot): string  // 格式化输出快照
```

**启用方式**：
```bash
# 在运行 exe 前设置环境变量
set PX_PERF=1
my_app.exe

# 或在 PowerShell 中
$env:PX_PERF=1; .\bin\my_app.exe
```

---

## 五、事件系统

### 5.1 点击事件处理链

```
鼠标按下 → hitTest(x, y) 反序遍历子节点
    → 检查 @click 属性
    → resolveComponent(node) 通过 groupId 查找组件
    → component->dispatchClick(handler, arg)
    → 组件内 match 分发
    → 最匹配的 handler → parent::dispatchClick 冒泡
```

### 5.2 组件自定义事件处理器

在 `.vue` 的 `<script>` 中定义方法，SFC 编译器自动生成对应的 `dispatchClick`：

```php
// App.vue <script>
public function deleteItem(string $id): void {
    unset($this->todoItems[$id]);
    $this->markDirty();  // SFC 编译器会自动注入此行
}

// 编译器生成的 dispatchClick：
public function dispatchClick(string $handler, ?string $arg = null): void {
    switch ($handler) {
        case 'deleteItem': $this->deleteItem($arg); break;
        default:
            if ($this->parent !== null) {
                $this->parent->dispatchClick($handler, $arg);
            }
    }
}
```

### 5.3 键盘事件

当前仅支持聚焦 input 元素的 @keydown / @keyup / @enter　

---

## 六、滚动系统

### 6.1 职责架构

```
滚动事件 → Application::handleMouseEvent (路由)
         → ScrollManager (状态管理 + 逻辑)
              ├─ handleScrollWheel()      滚轮
              ├─ hitTestScrollbar()       命中测试（垂直条 + 水平条）
              ├─ handleScrollbarDown()    拖拽开始
              ├─ handleScrollbarDrag()    拖拽中
              ├─ handleMouseUp()          拖拽释放
              ├─ applyScrollTop()         垂直滚动
              └─ applyScrollLeft()        水平滚动
```

> 滚动状态（target、start 坐标、start scroll 位置、isHorizontal）全部在 ScrollManager 中
> Application 只负责将事件路由给 ScrollManager，不再直接持有滚动状态

### 6.2 使容器可滚动

在 `.vue` 模板中：
```html
<!-- 仅垂直滚动 -->
<div style="overflow-y:auto;left:10px;top:50px;width:380px;height:400px"
     :scroll-top="scrollTop">
  <template v-for="item in items" :key="item.id">
    <div @click="deleteItem(item.id)">{{ item.text }}</div>
  </template>
</div>

<!-- 水平+纵向滚动（overflow:auto 同时支持两轴） -->
<div style="overflow:auto;left:10px;top:50px;width:390px;height:570px"
     :scroll-top="scrollTop"
     :scroll-left="scrollLeft">
  <!-- 子元素宽度超过容器宽度时出现水平滚动条 -->
  <div style="left:0;top:0;width:800px;height:36px">宽内容</div>
</div>
```

组件中：
```php
public string $scrollTop = "0";   // 垂直滚动位置
public string $scrollLeft = "0";  // 水平滚动位置（仅水平容器需要）
```

**水平滚动交互**：`Shift + 滚轮` 触发水平滚动。水平滚动条位于容器底部 12px 区域　

### 6.3 滚动交互流程

```
滚轮 → ScrollManager::handleScrollWheel (当 Shift 按下时 → 水平)
     → applyScrollTop / applyScrollLeft (persist=true)
     → setBindValue → markDirty → requestRender

轨道点击 → ScrollManager::hitTestScrollbar (返回 {scrollNode, type, isHorizontal})
       → handleScrollbarDown → applyScroll*(jumped_value, persist=true)

滑块拖拽 → hitTestScrollbar → handleScrollbarDown(type='thumb')
       → handleScrollbarDrag (高速) → applyScroll*(persist=false) → directRender
       → 鼠标释放 → applyScroll*(persist=true) → requestRender
```

### 6.4 核心机制

1. **Bind 同步**：`resolveVNodeBindings` 在每次 rebuild 时将组件 `scrollTop`/`scrollLeft` 值写入 `VNode`
2. **布局偏移**：LayoutResolver 用 `childOffsetY = node.y - scrollTop` 和 `childOffsetX = node.x - scrollLeft` 定位子节点
3. **自动 clamp**：auto-stack 后若 `scrollTop > maxScroll` 或 `scrollLeft > maxScrollX`，LayoutResolver 自动修正并重定位子节点
4. **拖拽优化**：拖拽过程中使用 `directRender`，跳过 VNode 树重建
5. **水平滚动检测**：`overflow-x:auto` / `overflow-x:scroll` 或 `overflow:auto` 继承两轴

### 6.5 多滚动容器注意事项

- 滚轮事件使用**鼠标下方最深**的滚动容器
- 滚动条拖拽时只**操作同一个**容器
- 拖拽状态由 ScrollManager 持有，拖拽过程中**不会**触发树重建

---

## 七、AOT 编译约束

### 7.1 禁止的 PHP 模式

| 模式 | 原因 |
|------|------|
| `$obj->$prop` 动态属性 | AOT 无法静态推断 |
| `$fn()` 非闭包调用 | 字符串函数名不可编译 |
| `$obj->$method()` 动态方法 | 同上 |
| 顶层 `require_once` / `include` | 必须在函数/类内 |
| `eval()` / `create_function()` | 完全不可编译 |
| `compact()` / `extract()` | 动态变量 |

### 7.2 必须遵守的模式

| 模式 | 说明 |
|------|------|
| `$x->toObject(ClassName::class)` | AOT 显式类型标注：**必须使用** |
| `ComponentFactory::create($className)` | 允许字符串类名作为工厂参数 |
| `match` 表达式 | 是 swoole_compiler 内置的 PHP 8.x 特性 |

### 7.3 构建前检查

```bash
# 使用 compiler 内置的 PHP 做语法检查
D:\swoole_compiler\php.exe -l framework/Core/Application.php

# AOT 静态检查（build.bat Step 0.5 自动运行）
D:\swoole_compiler\php.exe framework/aot-checker.php --project apps/list-test --skip direct_cpp_call
```

### 7.4 闭包使用限制

**问题**：`v-for` 循环内使用闭包（如条件 class）时，AOT 编译会丢失闭包内部变量的作用域，导致 `$ch` 等循环变量无法访问。

**错误示例**：
```php
// ❌ 错误：AOT 中闭包无法访问 $ch
$children[] = VNode::h('div', [...], (function() {
    $c = [];
    $c[] = VNode::h('span', [..., 'bind'=>$ch['name']], $ch['name']);
    return $c;
})());
```

**正确做法**：不使用闭包，直接在循环中构建 VNode：
```php
// ✅ 正确：循环变量直接在 foreach 中使用
foreach ($this->items as $item) {
    $children[] = VNode::h('div', [...], $item['name']);
}
```

**条件渲染的替代方案**：
- 不使用 `v-if` / `v-else`，改用**两个独立的 `v-for`** 遍历不同数据集
- 在组件中提供分离的方法返回不同类型的数据

```php
// ✅ 在 script 中提供分离的数据方法
public function getUserMessages(): array { /* 过滤 user 类型 */ }
public function getSystemMessages(): array { /* 过滤 system 类型 */ }

// ✅ 在 template 中独立遍历
<template v-for="msg in userMessages" :key="'u-' . msg.id">
  <!-- 用户消息 -->
</template>
<template v-for="msg in systemMessages" :key="'s-' . msg.id">
  <!-- 系统消息 -->
</template>
```

### 7.5 `use native_types` 下的 C2440 类型转换错误

**根因**：文件声明了 `use native_types`（AOT 模式），但以下操作最终返回 `php::Variant` 类型，赋值给已声明为 `php::Int` 的变量/属性时，AOT 编译器无法隐式转换。

| 操作 | 返回值 | 触发条件 |
|------|--------|---------|
| `$arr['key']` 数组元素访问 | `php::Variant` | 赋给 `int` 属性或已类型化的局部变量 |
| `$arr['key'] ?? default` 包含数组访问的 ?? | `php::Variant` | 同上 |
| `max(...)` / `min(...)` | `php::Variant` | 同上 |

**错误信号**：
```
D:\Px/build/...cc(error): error C2440: '=': cannot convert from 'php::Var' to 'php::Int'
```

**五种变体及修复**：

**变体 A — max/min 返回 Variant**
```php
// ❌ 错误：max() 返回 php::Variant，目标变量已类型化为 php::Int
$newScrollTop = max(0, min($max, $x));

// ✅ 正确：外层加 (int) 转换
$newScrollTop = (int)max(0, min($max, $x));
```

> **重要：即使内层已用 `(int)`，外层 `max()` 仍返回 Variant**
> ```php
> // ❌ 仍然错误：$node->w = max(0, (int)$width); → max 返回 Variant
> // ✅ 正确：$node->w = (int)max(0, (int)$width);
> ```

**变体 B — 用 int 字面量初始化，后数组访问重新赋值**
```php
// ❌ 错误：$borderColor 先 =0 初始化为 php::Int
//           又被 $style['borderColor'] ?? ... 赋值为 php::Variant
$borderColor = 0;
if (...) {
    $borderColor = $style['borderColor'] ?? ...;
}

// ✅ 正确：外层加 (int) 转换
$borderColor = 0;
if (...) {
    $borderColor = (int)($style['borderColor'] ?? ...);
}
```

**变体 C — 类属性声明为 `int`，从数组赋值**
```php
public int $primary;  // 声明为 php::Int

// ❌ 错误：$colors['primary'] ?? 0x1976D2 返回 php::Variant
$this->primary = $colors['primary'] ?? 0x1976D2;

// ✅ 正确：外层加 (int) 转换
$this->primary = (int)($colors['primary'] ?? 0x1976D2);
```

**变体 D — translateX/Y / gap / left/top 通过数组访问传播到 Int 属性**
```php
// ❌ 错误：$translateX 为 Variant，$node->x += Variant 触发 C2440
$translateX = $style['translateX'] ?? 0;
$node->x += $translateX;

// ✅ 正确：加 (int) 切断传播
$translateX = (int)($style['translateX'] ?? 0);
$node->x += $translateX;

// ❌ 同样：$left/$gap/$colGap 等数组访问变量传播到 $node->x/$ch->x
$left = $style['left'] ?? 0;         // Variant
$node->x = $left + $parentX;         // C2440

// ✅ 正确
$left = (int)($style['left'] ?? 0);
$node->x = $left + $parentX;
```

**变体 E — Grid 链式传播（源头加 (int) 切断整条链）**
```php
// ❌ 错误：colGap(Variant) → cellX(Variant) → $ch->x(Int)
$colGap = $style['gridColumnGap'] ?? $style['gap'] ?? 0;
$cellX = $node->x + $col * ($cellW + $colGap);
$ch->x = $cellX;  // C2440

// ✅ 正确：源头加 (int)
$colGap = (int)($style['gridColumnGap'] ?? $style['gap'] ?? 0);
```

**变体 F — Variant 传入 int 函数参数**
```php
// ❌ 错误：max(...) 返回 Variant，传入 int 参数
$parentContentW = ($ancestor !== null) ? max(0, $ancestorW - $padLeft - $padRight) : 0;
$this->resolveMarginAuto($node, $style, $parentContentW, $parentContentH);  // C2440

// ✅ 正确
$parentContentW = ($ancestor !== null) ? (int)max(0, $ancestorW - $padLeft - $padRight) : 0;
```

**全库扫描**：已对全部 `use native_types` 文件（Rendering/Layout/*.php、ScrollManager.php、VNodeRenderer.php、ColorScheme.php、LayoutResolver.php）进行三次全面扫描，确认 2026-06-08 版本已修复所有已知模式。涉及修复文件：`FlexLayoutStrategy.php`、`BlockLayoutStrategy.php`、`GridLayoutStrategy.php`、`AbsolutePositioning.php`、`LayoutResolver.php`。

### 7.6 `use native_types` 下 CSS 简写属性 + ?? / 三元表达式类型混用导致 C2446

**根因**：AOT 编译器在 `use native_types` 模式下，三元表达式（`?:` 和 `??`）的多个分支必须类型一致。以下模式会生成 `php::Str : php::Int` 导致 C2446。

**错误信号**：
```
D:\Px/build/...cc(error): error C2446: ':': no conversion from 'php::Int' to 'php::Str'
D:\Px/build/...cc(error): note: Constructor for class 'php::String' is declared 'explicit'
```

**模式 A: CSS 简写属性（padding/margin）与 ?? int 混用**

CSS 简写属性（`padding`、`margin`）在 PHP style 数组中存储为字符串（如 `"10 20"` 或 `"5"`），与 `?? 0`（int 字面量）链式使用时，AOT 生成 `php::Str : php::Int`。

```php
// ❀ 错误：$style['padding'] 是字符串，?? 0 是 int
$paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;

// ✀ 正确：外层加 (int) 统一类型
$paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
```

涉及文件：`AbsolutePositioning.php`、`BlockLayoutStrategy.php`、`FlexLayoutStrategy.php`、`LayoutResolver.php`

**模式 B: 三元表达式 'auto' 与 int 混用**

当 CSS margin 值可能为 `'auto'` 时，三元 `($raw === 'auto') ? 'auto' : intReturningFn()` 产生 `php::Str : php::Int`。

```php
// ❀ 错误：三元 'auto'(Str) : PercentResolver::resolve...(int)
$marginLeft = ($marginLeftRaw === 'auto') ? 'auto' : PercentResolver::resolveMarginPaddingPercent(...);

// ✀ 正确：直接用 0 替代 'auto'，移除后续 sentinel 检查
$marginLeft = ($marginLeftRaw === 'auto') ? 0 : PercentResolver::resolveMarginPaddingPercent(...);
// 移除：if ($marginLeft === 'auto') $marginLeft = 0;  // 死代码
```

涉及文件：`AbsolutePositioning.php`

**修复原则**：所有 `??` 链和 `?:` 三元表达式，确保所有分支类型一致。CSS 简写属性全部在外层加 `(int)`。

---

### 7.7 `use native_types` 下方法内数组属性赋值无效

**根因**：文件声明了 `use native_types` 时，在方法（如 `onMount()`、`initData()`）中对已声明为 `public array` / `private array` 的属性做 `$this->prop = [...]` 赋值，AOT 编译器生成的 C++ 代码**不会真正将数组写入属性**——运行时该属性保持初始空值 `[]`

**错误信号**：没有编译错误，但运行时属性数组为空（`foreach` 执行 0 次）。常见于将数组初始化放入类似 `initData()` 方法的设计模式。

**错误示例**：
```php
// ❌ 错误：AOT 编译时 $this->sidebarItems 保持空数组
public array $sidebarItems = [];

public function onMount(): void {
    parent::onMount();
    $this->initData();
}

private function initData(): void {
    // 此赋值在 AOT 下无效
    $this->sidebarItems = [
        ['id' => 's1', 'title' => '视频1'],
        ['id' => 's2', 'title' => '视频2'],
    ];
}
```

**正确做法**：数组数据**必须在属性声明中内联初始化**：
```php
// ✅ 正确：在声明处直接赋值
public array $sidebarItems = [
    ['id' => 's1', 'title' => '视频1'],
    ['id' => 's2', 'title' => '视频2'],
];
```

**影响范围**：`public array` 和 `private array` 均受影响。`string` / `int` 类型属性的方法内赋值不受此限制

**如何检测**：`aot-checker.php` 暂未覆盖此模式。可搜索 `use native_types` 文件中含 `$this->xxx = [` 模式（方法内数组属性赋值）进行人工审核

### 7.8 模板中 `$` 前缀表达式支持

Px 框架模板使用 `$` 前缀时：
- v-for 循环变量（如 `$item`、`$idx`）：保留为 `$word`（不变）
- 非 v-for 变量（如 `$sz`）：SFC 编译器自动转换为 `$this->word`，即组件属性访问

这是因为 AOT 编译不存在 PHP 的变量作用域概念，非 v-for 的 `$` 变量必须映射到组件属性。涉及文件：`framework/compiler/expression/ConcatenationExpression.php`

---

## 八、构建流程

### 8.1 命令

```bash
# 构建
build.bat list-test

# 构建并运行
build.bat list-test --run
```

### 8.2 各步骤

```
Step 0:   MSVC 环境 (vcvarsall.bat x64)
Step 0.5: AOT 静态检查 + 检查禁止模式
Step 1:   SFC 编译（编译根组件 App.vue，自动 BFS 发现并编译所有子组件到 gen/*.php）
Step 2:   AOT 编译 (PHP → C++ → link → .exe)
Step 3:   打包 (exe + php8ts.dll + phpx.dll + fonts/ → bin/)
         build.bat 自动检测 cpp/fonts/*.ttf 存在时复制到 bin/fonts/，确保渲染后端/Skia 模式下字体随 exe 部署
```

### 8.3 常见失败

| 错误 | 解决 |
|------|------|
| `cl.exe` 找不到 | 用 Developer Command Prompt for VS 运行 |
| `php8embed.lib` 找不到 | 复制到 `D:\swoole_compiler\` 根目录 |
| AOT Checker 报错 | 检查代码是否使用了禁止模式 |
| Step 2 Swoole 编译器报错 | 先用 `php -l` 检查 PHP 语法 |
| 系统 `php -l` 报语法错 | 用 `D:\swoole_compiler\php.exe` 而非系统 PATH 中的 PHP |
| 编译子组件 .vue 时 gen/ 未更新到正确位置 | 必须编译根组件 App.vue，子组件不会被单独编译到 apps/<name>/gen/ |
| `C2440: cannot convert from 'php::Var' to 'php::Int'` | `use native_types` 文件中 `int` 变量从数组访问/max/min 赋值时，外层加 `(int)` 转换（见 7.5） |
| `C2446: no conversion from 'php::Int' to 'php::Str'` | `use native_types` 文件中 CSS 简写属性（padding/margin）与 ?? 0 混用时，外层加 `(int)`；三元表达式两分支类型必须一致（见 7.6） |
| Skia 字体找不到 | 检查 bin/fonts/ 下是否有 .ttf 文件，或手动复制 cpp/fonts/ 下的字体到应用程序目录 |
| `Call to a member function toString() on string` | SFC 编译器生成 `$this->prop->toString()`，但 PHP CLI 中 string 是原生类型。重新运行 `php sfc-compiler.php` 重新编译，新版编译器生成 `(string)$this->prop` |

### 8.4 多机器 vcvarsall 路径配置

`build.bat` 的 Step 0 需要找到 `vcvarsall.bat` 来初始化 MSVC 编译环境。不同机器上 Visual Studio 安装路径各不相同（如 VS 2017/2019/2022、Community/Professional/Enterprise），框架采用**三级优先级自动检测**：

| 优先级 | 来源 | 说明 |
|--------|------|------|
| 1 | 当前 PATH | 如果 `cl.exe` 已在 PATH 中（如手动打开 VS Dev Cmd），直接跳过 vcvarsall |
| 2 | `config.yml` | 在项目根目录 `config.yml` 中设置 `vcvarsall` 路径显式指定路径 |
| 3 | 自动搜索 | 递归搜索 `C:\Program Files\Microsoft Visual Studio\` 下所有 `vcvarsall.bat`，取最新的一个 |

**配置示例**（`config.yml`）：

```yaml
# 家用电脑 VS 2022 Community
vcvarsall: C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat

# 笔记本电脑 VS 2019 Professional（注释掉不需要的行）
# vcvarsall: C:\Program Files\Microsoft Visual Studio\2019\Professional\VC\Auxiliary\Build\vcvarsall.bat
```

> **提示**：绝大多数情况下**无需配置**，自动搜索即可。仅 VS 2017/2019/2022 的所有版本均可自动识别。只有在自动搜索失败或需要指定特定版本时才需要手动配置。

### 8.5 config.yml 配置文件

`config.yml` 是构建系统的核心配置文件，必须位于项目根目录。首次使用时需从模板复制：

```bash
cp config.example.yml config.yml
```

**配置项说明**：

| 配置项 | 说明 | 示例 |
|--------|------|------|
| `swoole_compiler` | Swoole Compiler 工具链目录 | `F:\work\swoole_compiler` |
| `vcvarsall` | MSVC 环境初始化脚本（可选） | `C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat` |

**配置示例**：

```yaml
# Swoole Compiler 路径（必需）
swoole_compiler: F:\work\swoole_compiler

# MSVC 路径（可选，通常自动检测即可）
# vcvarsall: C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat
```

**常见问题**：

| 错误信息 | 原因 | 解决 |
|----------|------|------|
| `swoole_compiler path not found in config.yml` | config.yml 不存在或路径错误 | 用 `config.example.yml` 复制并修改路径 |
| `swoole_compiler directory not found` | 路径指向的目录不存在 | 检查并修正 `swoole_compiler` 配置 |

---

## 九、常见开发任务

### 9.1 新建应用

1. 在 `apps/` 下创建目录
2. 创建 `main.php`（定义常量 + main()）：
```php
<?php
use Px\Core\Application;
const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 400;
const WINDOW_HEIGHT = 500;
const WINDOW_TITLE  = 'My App';
function main(): int {
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}
```
3. 创建 `App.vue`（template + script + style）
4. 创建 `project.yml`：
```yaml
name: my_app
mode: bin
no-console: false
platform: win32
entry: main.php
sources:
  - main.php
  - ./gen
  - ../../framework
  - ../../stub
  - ../../cpp
ignore:
  - ../../framework/compiler
  - ../../framework/aot-checker.php
```

### 9.2 添加带 bind 的属性

在 `.vue` script 中声明属性：
```php
public string $myValue = "0";
```

在模板中使用：
```html
<span :bind="myValue">{{ myValue }}</span>
<div :scroll-top="myValue" style="overflow:auto;...">
```

SFC 编译器会自动为 `myValue` 生成 `getBindValue` / `setBindValue` 的 case 分支

### 9.3 添加点击事件

模板中：
```html
<button @click="handleAction" click-arg="someId">Click</button>
```

script 中：
```php
public function handleAction(string $id): void {
    // 修改状态...
    $this->markDirty();  // 编译器自动注入
}
```

### 9.4 使用 v-for

采用 **Vue 3 风格**：`v-for` 可以写在 `<template>` 或任何 HTML 元素（`<div>`、`<span>` 等）上。

**`<template v-for>`** — 仅重复子节点，不产生额外包裹元素：

```html
<template v-for="item in items" :key="item.id">
  <div @click="handleClick(item.id)">
    <span>{{ item.text }}</span>
  </div>
</template>
```

**元素 v-for**（Vue 3 风格）— 元素本身参与循环：

```html
<div v-for="item in items" :key="item.id" @click="handleClick(item.id)">
  <span>{{ item.text }}</span>
</div>
```

两种写法均会被编译器提取为独立的 render 辅助方法，`{{ item.text }}` 等循环变量会被处理为局部变量，而非组件级 bind key

### 9.5 使用 v-if / v-else-if / v-else

采用 Vue 3 风格的条件渲染链：

```html
<div v-if="status === 'A'" style="background:#4CAF50">
  <span>Status A</span>
</div>
<div v-else-if="status === 'B'" style="background:#FFC107">
  <span>Status B</span>
</div>
<div v-else style="background:#F44336">
  <span>Status C</span>
</div>
```

**注意**：
- `v-else-if` 和 `v-else` 必须紧跟于 `v-if` 之后，中间不能有其他非条件元素
- 编译器使用 `ExpressionParser` 解析条件表达式，支持三元表达式、比较运算符、逻辑运算符

### 9.6 使用 :class 动态类绑定

支持三元表达式动态绑定 CSS 类：

```html
<div :class="isActive ? 'active' : 'inactive'">
  Content
</div>
```

编译为：
```php
['class' => $this->isActive ? 'active' : 'inactive']
```

### 9.7 使用 v-show 条件显示/隐藏

通过 `visibility:hidden` 控制元素可见性：

```html
<div v-show="isVisible" style="background:#2196F3">
  Toggle Me
</div>
```

编译为：
```php
['style' => ($this->isVisible) ? '...' : '...;visibility:hidden']
```

### 9.8 使用子组件

1. 创建子组件 `.vue` 文件
2. 在父组件模板中使用：
```html
<my-component :my-prop="parentValue"></my-component>
```
3. SFC 编译器自动发现、编译、生成占位 VNode
4. Application 在运行时展开

### 9.9 重新编译 SFC（修改 .vue 后）

修改 `.vue` 文件后，必须重新编译才能生效。关键规则：

- **编译根组件 App.vue**（而非子组件），编译器会 BFS 发现所有有变更的子组件并自动重新编译
- 输出目录由 .vue 文件路径决定：`dirname($vueFile) + '/gen/'`
  - 编译 `apps/<name>/App.vue` → 输出到 `apps/<name>/gen/`（正确路径）
  - 编译 `apps/<name>/components/MyComp.vue` → 输出到 `apps/<name>/components/gen/`（错误路径）
- 命令：`php sfc-compiler.php apps/<name>/App.vue`
- **禁止手动编辑 `gen/*.php` 文件**（会被编译器覆盖）

### 9.10 调试技巧

- **检查 VNode 树**：在 `render()` 返回前 `var_dump` VNode 结构（需在开发环境 PHP 而非 AOT 中运行）
- **检查布局**：查看 `LayoutResolver::resolve()` 返回的 `scrollContainers` 列表
- **检查渲染元素**：在 `collectElements` 中查看 `$elementsByLayer`
- **formatted 输出**：在 `Application::render()` 中使用 `var_dump` 输出 activeVNodeTree

### 9.11 在模板使用 `$` 前缀变量

在 `.vue` 模板中 `$word` 形式的变量（非 v-for 局部变量）会被编译器转为 `$this->word`，因此可以直接引用组件属性：

```html
<div>{{ $myValue }}</div>
<!-- 编译为：$this->myValue -->
```

**注意**：`$` 前缀在 v-for 循环变量（如 `$item`、`$idx`）中不能使用，编译器会区分

### 9.12 AI 自动截图测试

在进行 UI 渲染测试时，可以使用 PowerShell 脚本自动截图验证布局效果：

**截图脚本模板**（保存到 `apps/<app-name>/test_screen.ps1`）：

```powershell
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$exePath = "f:/work/Px/apps/<app-name>/bin/<app-name>.exe"
$screenPath = "f:/work/Px/apps/<app-name>/screenshot.png"

$proc = Start-Process $exePath -PassThru
Start-Sleep 3

Add-Type @"
using System;
using System.Runtime.InteropServices;
using System.Text;
public class WND {
    [DllImport("user32.dll")]
    public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    [DllImport("user32.dll")]
    public static extern int GetWindowText(IntPtr hWnd, StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll")]
    public static extern int GetWindowTextLength(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    [DllImport("user32.dll")]
    public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
    [DllImport("user32.dll")]
    public static extern bool GetWindowRect(IntPtr hWnd, out RECT lpRect);
    [DllImport("user32.dll")]
    public static extern bool IsWindowVisible(IntPtr hWnd);
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT {
        public int Left, Top, Right, Bottom;
    }
}
"@

$targetHwnd = [IntPtr]::Zero
$targetPID = $proc.Id

$callback = {
    param([IntPtr]$hWnd, [IntPtr]$lParam)
    $winPid = 0
    [WND]::GetWindowThreadProcessId($hWnd, [ref]$winPid) | Out-Null
    if ($winPid -eq $targetPID) {
        if ([WND]::IsWindowVisible($hWnd)) {
            $len = [WND]::GetWindowTextLength($hWnd)
            if ($len -gt 0) {
                $sb = New-Object System.Text.StringBuilder($len + 1)
                [WND]::GetWindowText($hWnd, $sb, $sb.Capacity) | Out-Null
                $title = $sb.ToString()
                if ($title -ne "") {
                    $script:targetHwnd = $hWnd
                    return $false
                }
            }
        }
    }
    return $true
}

[WND]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null

if ($targetHwnd -ne [IntPtr]::Zero) {
    [WND]::ShowWindow($targetHwnd, 1) | Out-Null
    Start-Sleep -Milliseconds 800
    [WND]::SetForegroundWindow($targetHwnd) | Out-Null
    Start-Sleep -Milliseconds 500

    $rect = New-Object WND+RECT
    [WND]::GetWindowRect($targetHwnd, [ref]$rect) | Out-Null

    $w = $rect.Right - $rect.Left
    $h = $rect.Bottom - $rect.Top
    if ($w -gt 10 -and $h -gt 10) {
        $bmp = New-Object System.Drawing.Bitmap($w, $h)
        $graphics = [System.Drawing.Graphics]::FromImage($bmp)
        $graphics.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
        $bmp.Save($screenPath)
        $graphics.Dispose()
        $bmp.Dispose()
        Write-Host "Screenshot saved: ${w}x${h} at ($($rect.Left), $($rect.Top))"
    }
} else {
    Write-Host "Window not found"
}

if (-not $proc.HasExited) {
    Stop-Process $proc.Id -Force -ErrorAction SilentlyContinue
}
```

**使用流程**：

1. 修改 `.vue` 文件测试布局
2. 运行构建：
   ```bash
   cd f:/work/Px
   Remove-Item 'apps/<app-name>/gen/*.php' -Force  # 清理旧生成文件
   .\build.bat <app-name>
   ```
3. 运行截图脚本：
   ```bash
   powershell -ExecutionPolicy Bypass -File "f:/work/Px/apps/<app-name>/test_screen.ps1"
   ```
4. 查看 `screenshot.png` 验证渲染结果

**注意事项**：

- 截图前需清理 `gen/` 目录，否则可能使用旧代码
- 每个应用程序应只保留唯一 `.vue` 文件（按字母顺序编译）
- 窗口定位使用 `EnumWindows` 匹配进程 PID，避免捕获错误窗口

---

## 十、已知问题与设计债务

### 10.1 SOLID 违反：Application 持有 scrollDragTarget — 已解决

> `ScrollManager` 服务已抽取（`framework/Core/ScrollManager.php`），Application 仅负责事件路由，
> 所有滚动状态（drag target、drag start 坐标、drag start scroll 位置）和逻辑（滚轮、拖拽、clamp）
> 归属 ScrollManager。横向滚动状态同样由 ScrollManager 统一管理

### 10.2 多滚动容器限制

`scrollDragTarget` 是单引用，同一时刻只能拖拽一个滚动条（鼠标操作天然如此，暂不影响使用）。但如果未来增加键盘滚动，需要改为用 ID 索引的 Map

### 10.3 VNode 悬空引用风险

拖拽过程中如果 VNode 树被重建（例如定时器触发 markDirty），`scrollDragTarget` 指向旧的对象。当前通过 `directRender` 避免重建，但长期需改为 stable identifier

### 10.4 Bind 值同步延迟

LayoutResolver clamp 后，组件的 bind 值（如 scrollTop）保持旧值。下次 render 时先恢复旧值，再被 LayoutResolver 重新 clamp——每帧一次"错误→修复"循环。需要 `setBindValueSilent` 方法

### 10.5 未实现的功能

- 键盘滚动（PgUp/PgDn/Home/End/Arrow）
- 编程式滚动到指定 item
- 窗口 resize 时的动态重布局（当前需要手动触发渲染）
- 文字输入及 IME 支持

### 10.6 近期已实现的功能（2026-06~2026-06-08）

| 功能 | 描述 | Commit |
|------|--------|--------|
| `display: inline-flex` | LayoutResolver 新增 inline-flex 支持 | d734b3f |
| `border-radius` | CssMappings 新增 CSS 属性解析，渲染管道传递 | d734b3f |
| `object-fit` | CssMappings 新增 CSS 属性解析 | d734b3f |
| `img` 元素 CSS 标准 | VNodeRenderer 实现 box-shadow/border/alt 回退 | d734b3f |
| Grid `width: auto` | block-level grid 容器自动计算包含块 | d734b3f |
| Flex `height: auto` | 自动尺寸计算修复 | d734b3f |
| `shiftDescendantsY/X` | 子节点偏移翻倍bug 修复 | d734b3f |
| Grid 自动高度 | 从内容计算格子自动高度 | d734b3f |
| Config 配置管理类 | px_debug.yml 解析，由 Application 初始化 | 3c93f78 |
| RenderTreeManager | 渲染树管理，VNode→RenderNode 转换/差异追踪/命中测试 | 3c93f78 |
| SFC 编译器中 `$` 前缀处理 | `$word`（非v-for）→ `$this->word` | d7cd2bc |
| LayoutResolver 策略模式 | 拆分为 6 个策略类 | 878a048 |
| CSS `box-sizing` | box-sizing 支持（content-box/border-box） | ed4c49e |
| CSS `line-height` | 行高计算支持 | ed4c49e |
| 文本节点 auto-height | 文本节点自动高度计算 | ed4c49e |
| `background` 简写展开 | 多值 background 简写展开 | 20fbd8a |
| `rgba()` alpha → opacity | rgba alpha 通道自动提取为 opacity | 20fbd8a |
| CSS `linear-gradient` | background 中 linear-gradient 解析 | 20fbd8a |
| `pointer-events: none` | 触发 pointer-events:none 跳过命中测试 | 0e4ad89 |
| `transform` 命中测试 | transform translate 偏移后的命中测试适配 | 0e4ad89 |
| layer 层叠顺序 | 按 z-index/layer 层叠顺序命中测试 | 0e4ad89 |
| `font-family` 管道 | 字体回退链 + fontFamily 渲染管道 | 8d661df |
| `position: sticky` | sticky 堆叠 + 水平方向 + visual坐标修复 | be6022f |
| 滚动条 CSS 样式化 | scrollbar-width/color/radius 样式支持 | 6716471 |
| VNode 不可变性 | VNode 克隆保护 + 组件树展开克隆（B2） | 65fd6e5 |
| `onMount`/`onUnmount` 去抽象化 | 不再强制为抽象方法（可选覆写） | b2c8bfb |
| `flex-shrink` min-width | flex-shrink + min-width 正确重新分配 | 78ec30d |
| Backend 渲染后端系统 | 6 个后端候选，运行时探测+故障降级 | - |
| RenderNode 分离 | VNode→RenderNode 分离，RenderTreeManager 统一管理 | - |
| ImageManager | 图片缓存管理器（路径→句柄，自动释放） | - |
| PerfCounter | 轻量级性能计数器（PX_PERF=1 启用） | - |
| RenderTreeManager 命中测试 | hitTest/findScrollContainerAt 移至 RenderNode 树 | - |
| ScrollManager RenderNode 化 | 所有滚动操作基于 RenderNode 而非 VNode | - |
| ResilientRenderContext | 故障降级代理（连续失败 N 次自动切换） | - |
| RuntimeBackendSelector | 运行时后端选择器（probe+fallback+强制覆盖） | - |

---

## 十一、编码约定

### 11.1 PHP 版本要求

- 源文件：PHP 8.0+（使用 `match` 表达式）
- AOT 编译：swoole_compiler 内置 PHP 8.x
- **系统 PATH 中的 PHP 可以是 7.4，仅用于开发调试，不能用于编译**

### 11.2 代码风格

- 使用 4 空格缩进
- 类属性使用 `protected` 或 `private`（AOT 友好）
- `public` 属性用于组件状态（由 SFC 编译器生成）
- 方法用 camelCase
- VNode factory 统一使用 `VNode::h()` 和 `VNode::hComponent()`

### 11.3 VNode 树规范

- 每个组件的 `render()` 返回以 `#root` 为根的 VNode 树
- `#root` 的 style 设置 `width` 和 `height`
- `#component` 是运行时展开的占位节点，不产生渲染
- `#text` 用于纯文本节点
- children 可以是 `null`、`string`、`VNode`、`VNode[]`

---

## 十二、测试

Px 框架使用**三层测试策略**：
1. **单元测试**（PHP）—— dispatchClick 模拟点击 + 组件树定义验证
2. **状态快照测试**（PHP）—— 文字描述"基线"，将组件状态序列化为可读文档
3. **基线测试**（PowerShell）—— 启动真实 exe 抓取窗口基线，用于视觉回归

### 测试设计原则

| 原则 | 说明 |
|------|------|
| **不依赖外部服务** | 所有测试在内存中运行，无文件/网络/数据库依赖 |
| **dispatchClick 驱动** | 模拟用户点击，直接调用组件 handler 方法 |
| **状态断言 + 转储** | 既校验具体属性值，也 dump 完整状态用于调试 |
| **组件树定义对齐 Vue 3** | 测试 parent 链/事件冒泡/VNode 缓存、patchComponentTree |
| **AOT polyfill** | bootstrap.php 提供 `toObject()`、`any()` 等 AOT 函数 polyfill |

### 运行测试

```bash
# 运行全部单元测试（推荐）
D:\swoole_compiler\php.exe tests/run_all_tests.php

# 运行单个测试文件
D:\swoole_compiler\php.exe tests/unit/CalculatorAppTest.php
D:\swoole_compiler\php.exe tests/unit/ComponentTreeTest.php
D:\swoole_compiler\php.exe tests/unit/ReactiveComponentTest.php
D:\swoole_compiler\php.exe tests/unit/HitTestTest.php
D:\swoole_compiler\php.exe tests/unit/LayoutResolverTest.php
D:\swoole_compiler\php.exe tests/unit/VNodeRendererTest.php
D:\swoole_compiler\php.exe tests/unit/SfcCompilerPartsTest.php
D:\swoole_compiler\php.exe tests/unit/SfcCompilerVIfTest.php
D:\swoole_compiler\php.exe tests/unit/CssMappingsBorderTest.php
D:\swoole_compiler\php.exe tests/unit/CssMappingsTest.php
D:\swoole_compiler\php.exe tests/unit/PlatformTest.php
D:\swoole_compiler\php.exe tests/unit/ExpressionParserTest.php
D:\swoole_compiler\php.exe tests/unit/LayoutEngineTest.php
D:\swoole_compiler\php.exe tests/unit/RenderNodeTest.php
D:\swoole_compiler\php.exe tests/unit/RenderTreeManagerTest.php
D:\swoole_compiler\php.exe tests/unit/ScrollSnapshotTest.php

# 运行流水线测试（快照差异分析）
D:\swoole_compiler\php.exe tests/unit/RenderingPipelineTest.php
D:\swoole_compiler\php.exe tests/unit/ListTestPipelineTest.php

# 运行内存压力测试（逐帧累计检测）
D:\swoole_compiler\php.exe tests/unit/MemoryStressTest.php

# 运行 Bilibili 相关测试
D:\swoole_compiler\php.exe tests/unit/BilibiliLayoutTest.php
D:\swoole_compiler\php.exe tests/unit/BilibiliSnapshotTest.php
```

### 测试文件

| 文件 | 覆盖范围 | 用例数 |
|------|---------|--------|
| `CalculatorAppTest.php` | 计算器全部 18 类操作 + 状态快照 + 边界情况 | 107 |
| `ComponentTreeTest.php` | 组件 parent 链/事件冒泡/实例独立/生命周期/VNode 缓存、hComponent 工厂、patchComponentTree、组件定位保证 | 26 |
| `ReactiveComponentTest.php` | dirty 标记、VNode 缓存、组件更新 | 9 |
| `HitTestTest.php` | 命中测试、事件路由（基于 RenderNode） | 10 |
| `LayoutResolverTest.php` | block/flex/grid/scroll 布局 + min/max/auto/百分比/relative/flex-basis/shrink/order/align-self/inline-flex/grid-auto-width/shiftDescendants | 69 |
| `LayoutEngineTest.php` | 布局引擎策略集成测试 | - |
| `VNodeRendererTest.php` | 元素收集、layer 分组、clip（scroll + overflow:hidden）、button 边框渲染、render 完整流程 | 20 |
| `CssMappingsBorderTest.php` | border 边样式/简写属性解析、parseInlineStyle/parseStyleBlock 边框处理、hexToBgr/borderColor 辅助函数 | 14 |
| `CssMappingsTest.php` | CSS 属性解析通用测试 | - |
| `SfcCompilerPartsTest.php` | 编译器 parts 元数据：collectVNodeBindKeys 提取、generateVNodeExpr 代码生成 | 8 |
| `SfcCompilerVIfTest.php` | v-if 编译期优化（包含相同条件合并） | 9 |
| `ExpressionParserTest.php` | 表达式解析器（比较/逻辑/三元/连接） | - |
| `PlatformTest.php` | Platform 接口 SOLID/DIP 合规 | 10 |
| `MemoryStressTest.php` | 内存增长检测（9 模块 28+ 场景） | 28+ |
| `RenderingPipelineTest.php` | 完整渲染管道转储差异分析（100 次循环点击 + 5 类规则校验 + 异常存档） | 5 |
| `ListTestPipelineTest.php` | list-test 渲染管道测试（30 次点击 + 增长规则 + clip 有效性 + 滚动拖拽） | 8 |
| `GdiRenderContextTest.php` | GDI 渲染上下文直接测试（clip 栈 + drawText 基线 + 参数守卫） | 15 |
| `RenderNodeTest.php` | RenderNode 字段/脏标记/树操作方法 | - |
| `RenderTreeManagerTest.php` | RenderTreeManager VNode→RenderNode 转换/复用/命中测试 | - |
| `ScrollSnapshotTest.php` | 滚动场景快照测试 | - |
| `BilibiliLayoutTest.php` | Bilibili 页面布局测试 | - |
| `BilibiliSnapshotTest.php` | Bilibili 页面状态快照 | - |

### CalculatorAppTest 测试清单

覆盖以下 18 类场景（107 组测试用例）

| # | 类别 | 用例数 | 说明 |
|---|------|--------|------|
| 1 | Digit Input | 7 | 初始显示、数字输入、去除前导零、运算后新输入 |
| 2 | Decimal Input | 6 | 小数点输入、防重复、运算后新输入、15 位限制（2 组） |
| 3 | Clear/Reset | 2 | C 清除输入、AC 完全重置 |
| 4 | Backspace | 4 | 删除最后、归零/newInput 保护、删除小数点 |
| 5 | Toggle Sign | 3 | 正负切换、零值保持 |
| 6 | Percentage | 2 | 50%→0.5　200%→2 |
| 7 | Basic Arithmetic | 6 | ±×÷、除以零 Error、空操作符 |
| 8 | Operator Chaining | 3 | 链式计算、运算符覆盖、混合运算 |
| 9 | Scientific Functions | 14 | sin/cos/tan/log/ln/x²/x³/√/inv/π/e + Error 分支 |
| 10 | Memory Functions | 6 | MS/MR/MC/M+/M√/空内存 |
| 11 | Parentheses | 4 | openParen/closeParen 显示 |
| 12 | History | 5 | 历史记录生成、切换面板、清除、加载 |
| 13 | Error Recovery | 3 | Error 后数字/C/= 恢复 |
| 14-16 | Routing | 26 | ScientificPad/BasicPad/HistoryPanel 冒泡路由 |
| 17 | State Snapshot | 3 | 可视化状态跟踪（完整会话、Error→恢复、括号表达式） |
| 18 | Edge Cases | 13 | 超大数字、运算符链、重复等号、带符号运算、连续删除、加载压力测试等 |

### ComponentTreeTest 测试清单

覆盖 8 类 Vue 3 组件特性（26 组测试用例）

| # | 类别 | 说明 |
|---|------|------|
| 1 | Parent Chain | setParent/getParent、addChild 双向绑定、独立组件 |
| 2 | Event Bubbling | dispatchClick 向 parent 冒泡、stop 消费、null parent、dispatchKey |
| 3 | Instance Identity | 同类型不同实例、唯一 ID |
| 4 | Lifecycle | mount/unmount、重复 mount |
| 5 | VNode Caching | 首次 render()、缓存复用、dirty 重建、markDirty 清缓存 |
| 6 | VNode Factory | hComponent 占位、componentProps 映射、groupId 递归 |
| 7 | Patch Component Tree | 设置节点 groupId/#component 展开、实例引用+class+同位替换 |
| 8 | Component Positioning | matchComponentNode 实例重用与 transferComponentPositioning 保留定位 |

### 基线测试

提供 PowerShell 脚本用于视觉回归

```powershell
# 直接基线（使用已有 exe）
powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1

# 先构建再基线
powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1 -BuildFirst $true
```

基线保存在 `tests/screenshot/output/<timestamp>/`，并自动生成 HTML 报告　

### 测试最佳实践（经验总结）

1. **dispatchClick 首选测试方式** — 直接调用组件 handler，不依赖布局坐标和渲染管道，速度快、结果确定
2. **测试 helper 函数** — `createApp()`、`runCalculation()`、`assertDisplay()`、`captureState()` 等 helper 提高可用性和可维护性
3. **避免过度模拟** — 测试真实组件行为比 mock 更有价值。只在需要隔离时才用 test double
4. **状态快照 vs 具体断言** — 关键步骤用具体断言（`assertDisplay('42')`），调试用状态快照（`captureState()`）
5. **Application 私有方法通过反射测试**   `newInstanceWithoutApp()` + `ReflectionMethod` 访问 private 方法
6. **先修复测试再提交** — 失败的测试比没有测试更糟。每次修改后运行全部测试确保回归
7. **组件树测试验证框架层面** — ComponentTreeTest 验证框架层面的 Vue 3 特性对齐，不依赖具体应用
8. **Mock 渲染上下文暴露 GDI 不可测漏洞** — `_MockRenderContext` 仅追踪 `drawElement()` 调用，不执行真实 GDI。Bug 发生在 GDI 实现层（clip 边界绘制导致损坏 HDC 状态），纯元素层 Mock 无法捕获。补偿策略：
   - Mock 需模拟 clip 栈追踪 + 文本基线（`applyClipTruncation()` 与 `GdiRenderContext::drawText()` 逻辑一致）
   - 流水线测试必须包含 clip 溢出规则（Rule E：任何溢出 >=1px 即告警）
   - GDI 层行为必须通过 `GdiRenderContextTest.php` 直接验证（stub GDI C++ 函数记录调用参数）
9. **clip-aware drawText 是当前 text 输出路径的必选守卫** — 任何新的 GDI text 调用点都必须经过 `drawText()`（含 clip 基线），禁止直接调 `vue_draw_text()`
10. **新应用接入时必须添加对应的流水线测试** — 至少包含：N 次循环点击稳定性测试 + A/B/C 规则（不变/条件/约束）+ clip 有效性检查
11. **粗体文本字符宽度是常规体的 1.35 倍** — `drawText()` 基线逻辑必须区分 `$bold` 参数。粗体 36px 实际宽度 ~28px/char，而非 `fontSize * 0.6` 计算的 21px/char。未区分粗体会导致截断但仍然溢出
12. **测试必须覆盖完整的用户操作链** — 仅测试"一直按 1"不够，必须包含"大量操作 → 清除/重置 → 验证 UI 完整性"的端到端场景。每个管道测试都应包含 clear-after-corruption 验证
13. **按钮标签提取测试** — 使用 `<button><span :bind="label">{{ label }}</span></button>` 模板时，`makeButtonElement()` 必须提取到标签。管道测试中 `ltCheckButtonLabel()` 应断言 label 非空，不再标记为"known bug"
14. **滚动拖拽测试必须验证 auto-stacked 位置** — 仅测试"添加 item 后布局正确"不够。必须模拟滚动拖拽（直接设置 scrollTop + directRender），验证 auto-stacked items 的 y 坐标保持严格递增不折叠。洁净路由中 style 无显式 top 的节点不应重算 y

---

## 十三、修改框架代码时的检查清单

1. **PHP 语法检查**：`D:\swoole_compiler\php.exe -l <file>`
2. **AOT 兼容**：无 `->$var`、无动态调用
3. **布局职责**：LayoutResolver 管位置、VNodeRenderer 管裁切，互不越界
4. **负高度防护**：LayoutResolver 中所有 `$node->w`/`$node->h` 赋值用 `max(0, (int)$val)`
5. **GDI 调用保护**：GdiRenderContext 中所有 GDI 调用前检查 `$w > 0 && $h > 0`
6. **drawText clip 基线**：所有 text 绘制必须经过 `drawText()`（含 `clipStack` 追踪 + 粗体感知溢出基线），禁止直接调 `vue_draw_text()`。新增 text 输出路径时必须同步添加截断逻辑。截断公式：`charWidth = (int)(fontSize * 0.6 * ($bold ? 1.35 : 1.0))`，并保留 4px 安全余量
7. **clip 栈平衡**：clip-push/clip-pop 必须成对出现，每帧结束时 clip 栈应为空。`GdiRenderContextTest` 中所有 `clip stack push and pop balanced` 测试
8. **Mock clip 追踪**：修改 `_MockRenderContext`/`_LTMockRenderContext` 时必须同步添加 clip 栈追踪 + `applyClipTruncation()`（含粗体因子和 4px 安全余量），确保 mock 的可见行为接近真实 GDI
9. **overflow:hidden 裁切**：需要裁切子内容的容器必须设置 `overflow:hidden`，VNodeRenderer 会为其生成 clip-push/clip-pop
10. **数字输入限制**：所有数值输入方法（inputDigit、inputDecimal 等）必须有 15 字符长度限制
11. **Bind 同步**：新增 bind 属性后在组件中声明 `public string`，编译器自动生成 get/set
12. **事件冒泡**：子组件 dispatchClick 的 default 分支调用 `parent::dispatchClick`
13. **SFC 编译**：仅编译根组件 App.vue，不直接编译子组件 .vue；不手动编辑 gen/*.php
14. **构建验证**：`build.bat <app-name>` 全流程通过
15. **测试完整闭环**：新增管道测试必须覆盖完整的用户操作链（不限于一直按同一按钮），包括：大量操作后 → 清除/重置 → 验证最终 UI 元素完整的端到端场景
16. **按钮标签提取**：`makeButtonElement()` 必须遍历从 RenderNode 提取标签（`<button><span :bind="x">{{ x }}</span></button>`），仅检查 `node->content`(string) 和 `props[':bind']` 不够，还要检查子节点的 content 和 bind 引用
17. **LayoutResolver 洁净路由保留 auto-stack 位置**：洁净路由（`layoutDirty=false`）中，只有显式 `top`/`left` 定位的节点才重算 x/y。auto-stacked 子节点应保留脏路径决定的位置，仅由快速滚动路径（`shiftChildrenY`）平移。修改 `resolveNode()` 中 `$node->x = ($style['left'] ?? 0) + $parentX` 这类无条件赋值时必须改用 `array_key_exists` 保护
18. **`$` 前缀表达式意识**：在 .vue 模板中使用 `$variable` 时确保该变量在组件中有对应的 `public` 属性声明，编译器会替换为 `$this->variable`
19. **LayoutResolver 洁净路径保护**：修改 `resolveNode()` 中 `$node->x = ($style['left'] ?? 0) + $parentX` 类代码时，必须使用 `array_key_exists` 守卫仅对有显式 `left`/`top` 的节点做绝对值赋值（参考 `shiftDescendantsY/X` bug 修复经验）
20. **RenderTreeManager 集成**：新增渲染树管理类时需要同步更新 `Application::render()` 中渲染树构建/差异更新逻辑

---

## 十四、CSS 布局属性（LayoutResolver v2）

以下 CSS 属性已在 LayoutResolver 及 CssMappings 中实现支持：

### 盒模型
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `box-sizing` | 盒模型计算模式（`content-box`/`border-box`） | content-box |
| `box-shadow` | 阴影（h-offset v-offset blur spread color，支持 rgba） | none |
| `opacity` | 元素透明度（0.0~1.0，支持百分比） | 1.0 |
| `cursor` | 鼠标光标类型（`pointer` 等） | default |

`box-sizing: border-box` 下 width/height 包含 padding+border，LayoutResolver 在坐标计算时自动扣除边距。

### 显示模式
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `display` | 布局模式（`block`/`flex`/`grid`/`inline-flex`） | block |
| `inline-flex` | 内联弹性盒布局 | block |

### 尺寸约束
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `min-width` | 最小宽度 (px) | 0 |
| `max-width` | 最大宽度 (px) | 0 |
| `min-height` | 最小高度 (px) | 0 |
| `max-height` | 最大高度 (px) | 0 |

CSS 规范：当 `min > max` 时，`max` 被忽略。

### 百分比尺寸
| 属性 | 说明 |
|------|------|
| `width: 50%` | 相对于父容器 content width |
| `height: 50%` | 相对于父容器 content height |
| `calc(100% - 40px)` | calc 表达式（百分比 + 像素偏移） |

百分比在尺寸解析**之后**、min/max 约束**之前**应用。百分比也在 flex 和 grid 容器上生效。margin/padding 百分比基于**包含块宽度**。

### position
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `static` | 默认流式定位 | static |
| `relative` | 相对定位（偏离正常流位置） | - |
| `absolute` | 绝对定位（相对最近非 static 祖先） | - |
| `fixed` | 固定定位（相对视口） | - |
| `sticky` | 粘性定位（滚动容器内堆叠） | - |
| `z-index` | 层叠顺序 | 0 |

- `position:relative` 的子节点**不参与** auto-stack
- `top`/`left` 在 auto-stacked 位置基础上做额外偏移，不影响兄弟节点定位
- `position:sticky` 支持垂直/水平方向堆叠，visual 坐标修复
- 命中测试按 layer（z-index）层叠顺序，高 layer 优先

### 文本与字体
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `line-height` | 行高（影响文本节点高度） | normal |
| `white-space` | 空白处理模式（`normal`/`nowrap`/...） | normal |
| `text-overflow` | 文本溢出标记（`clip`/`ellipsis`） | clip |
| `text-align` | 文本对齐（`left`/`center`/`right`） | left |
| `font-family` | 字体回退链（逗号分隔，依次回退） | - |

**文本节点 auto-height**：文本节点（`#text`）根据 `line-height` × 行数自动计算高度，无需显式设置 height。

**fontFamily 管道**：`font-family` 支持逗号分隔的字体回退链（如 `"Segoe UI", Arial, sans-serif`），渲染器逐个尝试直至找到可用字体。

### background 简写与颜色
| 属性 | 说明 |
|------|------|
| `background: #RRGGBB` | 十六进制颜色 |
| `background: rgb(r,g,b)` | RGB 颜色 |
| `background: rgba(r,g,b,a)` | RGBA 颜色（alpha 自动提取为 opacity） |
| `background: linear-gradient(...)` | 渐变色（提取第一个色值） |
| `background: url(...) center/cover no-repeat` | 简写展开（color + image + position/size + repeat） |
| `background-image: url(...)` | 图片背景（相对/绝对路径） |
| `background-size` | 背景尺寸（`cover`/`contain`/etc） |
| `background-position` | 背景位置 |

**background 简写展开**：`CssMappings::expandBackgroundShorthand()` 解析多值 background 简写，自动提取 color、image（url）、position、size（/分隔）、repeat 子属性。

**rgba alpha → opacity**：当使用 `rgba(r,g,b,a)` 且 alpha < 1.0 时，alpha 值自动注入为 `opacity`，RGB 部分作为颜色值。

### 图像与替换元素
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `object-fit` | 替换元素内容适配方式（`fill`/`contain`/`cover`/`none`） | fill |
| `img` 元素 | CSS 标准实现（box-shadow/border/alt 回退） | - |

### 圆角
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `border-radius` | 圆角矩形半径（px） | 0 |

### Transform
| 属性 | 说明 |
|------|------|
| `transform: translateX(10px)` | X 轴平移 |
| `transform: translateY(-20px)` | Y 轴平移 |
| `transform: translate(10px, -20px)` | 双轴平移简写 |
| `transform: rotate(45deg)` | 旋转（仅解析，渲染视后端能力） |

**transform 与命中测试**：`transform: translate()` 偏移区域一并纳入命中测试范围，点击 translate 后的位置仍可触发点击事件。

### 指针事件
| 属性 | 说明 |
|------|------|
| `pointer-events: none` | 元素不参与命中测试和滚动容器查找（事件穿透） |
| `pointer-events: auto` | 默认，正常参与交互 |

### Flex 布局
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `flex-direction` | 主轴方向（`row`/`column`） | row |
| `flex-wrap` | 是否换行 | nowrap |
| `justify-content` | 主轴对齐 | flex-start |
| `align-items` | 交叉轴对齐 | stretch |
| `align-content` | 多行对齐 | stretch |
| `gap` | 间距 | 0 |
| `flex-grow` | 增长因子 | 0 |
| `flex-shrink` | 收缩因子 | 1 |
| `flex-basis` | 初始主轴尺寸（`auto` 回退到 width/height） | auto |
| `order` | 排列顺序（冒泡排序，稳定） | 0 |
| `align-self` | 单项交叉轴对齐 | auto |

**flex 简写**：支持 CSS `flex` 简写属性：
- `auto` → flex: 1 1 auto
- `none` → flex: 0 0 auto
- `initial` → flex: 0 1 auto
- `1` → flex: 1 1 0
- `1 0 auto` → flex: 1 0 auto
- `2 0 100px` → flex: 2 0 100

### Flex-shrink 算法
```
overflow = totalMain - containerMain  (若 overflow > 0)
totalShrinkWeight = Σ(item.mainSize × item.shrink)
item.mainSize -= overflow × (item.mainSize × item.shrink) / totalShrinkWeight
min-width/min-height 约束在收缩后应用（min-width 优先于 shrink）
```

### Grid 布局
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `grid-template-columns` | 列定义 | - |
| `grid-template-rows` | 行定义 | - |
| `grid-column-gap` | 列间距 | 0 |
| `grid-row-gap` | 行间距 | 0 |
| `grid-column` | 跨列范围 | - |
| `grid-row` | 跨行范围 | - |
| `align-self` | 垂直方向对齐 | stretch |
| `justify-self` | 水平方向对齐 | stretch |

**grid-template 格式**：支持 `repeat(N, SIZE)`、`repeat(auto-fill/auto-fit, minmax(MIN, MAX))`、`1fr 1fr 1fr` 显式尺寸列表。

### 滚动条样式
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `scrollbar-width` | 滚动条宽度 | 12 |
| `scrollbar-track-color` | 轨道颜色（BGR hex） | 0x4A4A4A |
| `scrollbar-thumb-color` | 滑块颜色（BGR hex） | 0x888888 |
| `scrollbar-border-radius` | 滑块圆角半径 | 0 |

### Overflow
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `overflow` | 溢出处理 | visible |
| `overflow-x` | 水平溢出 | visible |
| `overflow-y` | 垂直溢出 | visible |
| `text-overflow` | 文本溢出标记（`clip`/`ellipsis`） | clip |

- `overflow:auto` / `overflow:scroll` 使容器可滚动（`isScrollContainer=true`）
- `overflow:hidden` 生成 clip-push/clip-pop 裁切子内容

### 内联样式百分比标记
`CssMappings::parseInlineStyle()` 在解析时自动标记 `width`、`height`、`min-width`、`max-width`、`min-height`、`max-height`、`margin-*`、`padding-*` 的百分比值，存入 `*Percent` 字段（如 `widthPercent`），LayoutResolver 在父容器尺寸已知时据此解析实际像素值。同时支持 `calc(100% - 40px)` 表达式。

---

## 十五、渲染后端系统（Backend）

Px 框架使用 **Backend 渲染后端系统**：6 个后端候选，应用启动时自动探测最优后端，运行时支持故障降级。

### 15.1 架构概览

```
Application::initRenderer()
    ─
    ├─ Stage 1: Platform::init() 创建窗口 + 默认 RenderContext（测试环境直接使用）
    ├─ Stage 2: RuntimeBackendSelector::select()
    ─   ├─ collectCandidates() ← BackendRegistry（6 个候选，按优先级排序）
    ─   ├─ 逐一 probe() 检测运行时可用性
    ─   ├─ 第一个 probe+initialize 成功的 → 选定
    ─   └─ 全部失败 → 抛 \RuntimeException
    └─ Stage 3: 包裹 ResilientRenderContext（运行时自动降级代理）
        └─ VNodeRenderer 通过此代理调用绘制原语
```

### 15.2 后端列表

| 后端 | 名称 | 优先级 | 阶段 | 说明 |
|------|------|--------|------|------|
| Skia-Graphite-Dawn | `skia-dawn` | 100 | 阶段四五 | GPU 后端 (Dawn)，待实现 |
| Skia-Ganesh-D3D11 | `skia-d3d11` | 90 | 阶段四五 | GPU 后端 (Direct3D 11)，待实现 |
| Skia-Ganesh-WGL | `skia-wgl` | 80 | 阶段四五 | GPU 后端 (OpenGL)，待实现 |
| Skia-CPU | `skia-cpu` | 60 | 阶段三 | CPU Skia（`SkBitmap + SkCanvas`） |
| GDI-Direct2D | `gdi-d2d` | 50 | 阶段四五 | GDI Direct2D 加速，待实现 |
| GDI-Legacy | `gdi-legacy` | 10 | 阶段一二 | 原生 GDI（永远可用） |

> **GDI-Legacy 永远可用**：只要 Windows + user32/gdi32 存在，probe() 始终返回可用。作为最后兜底。

### 15.3 BackendRegistry（framework/Rendering/Backend/BackendRegistry.php）

**静态后端注册表**，维护 6 个候选类名列表：

```php
BackendRegistry::CANDIDATES            // 全部候选（按优先级降序硬编码）
BackendRegistry::getCandidatesSorted() // 返回候选列表
BackendRegistry::getForcedBackend()    // 读取环境变量 PX_RENDERER
BackendRegistry::isVerbose()           // 读取 PX_RENDERER_VERBOSE
```

**环境变量覆盖**：

| 变量 | 说明 | 示例 |
|------|------|------|
| `PX_RENDERER` | 强制指定后端 | `skia-cpu`、`gdi-legacy` |
| `PX_RENDERER_VERBOSE` | 打印探测详情 | `1` 或 `true` |

```bash
# 强制使用 Skia CPU 后端
set PX_RENDERER=skia-cpu
my_app.exe

# 查看探测日志
set PX_RENDERER_VERBOSE=1
my_app.exe
```

### 15.4 IRenderBackend 接口（framework/Rendering/Backend/IRenderBackend.php）

所有渲染后端实现此接口：

```php
interface IRenderBackend {
    public function getName(): string;                    // 唯一名称
    public static function getPriority(): int;             // 优先级
    public function probe(): BackendCapability;            // 运行时探测（无副作用）
    public function initialize(int $hwnd, int $w, int $h); // 初始化资源
    public function getContext(): RenderContext;            // 获取 RenderContext
    public function shutdown(): void;                       // 释放资源
}
```

**生命周期**：
```
probe() → [available] → initialize(hwnd, w, h) → getContext() → [use] → shutdown()
    ↓ not available
    [skip, try next]
```

### 15.5 RuntimeBackendSelector（framework/Rendering/Backend/RuntimeBackendSelector.php）

**运行时后端选择器**，负责启动选择 + 运行期降级：

- `select(hwnd, w, h)` — 启动时调用：收集候选 → 逐一 probe → 第一个成功的 initialize → 返回
- `selectNext(hwnd, w, h)` — 降级时调用：跳过当前/已失败/不健康的后端，选下一个
- `markFailed(backend)` — 标记后端失败
- `getCurrent()` — 获取当前后端

**探测流程**：
```
foreach (candidates as cls) {
    backend = new cls()
    cap = backend->probe()
    if (!cap->available) { unhealthy[name] = reason; continue }
    try { backend->initialize(hwnd, w, h); current = backend; return }
    catch (BackendInitException) { failed[] = name; continue }
}
throw \RuntimeException('No render backend available')
```

### 15.6 ResilientRenderContext（framework/Rendering/Backend/ResilientRenderContext.php）

**故障降级代理**，继承 RenderContext，对 VNodeRenderer 透明：

- 默认所有方法委派给当前 delegate（RenderContext）
- delegate 抛 `RenderBackendFailedException` 时计数
- **同一后端连续失败 3 次** → 触发降级（`selectNext`）
- 降级后用新 delegate 重试当前调用
- 上层（VNodeRenderer）完全无感知

**工作原理**：
```
safeCall(method, args):
    try { delegate->method(args); failures[name] = 0 }
    catch (RenderBackendFailedException) {
        failures[name]++
        if (failures[name] >= 3) {
            selector->markFailed(current)
            delegate = selector->selectNext(hwnd, w, h)->getContext()
        }
        safeCall(method, args)  // 用新后端重试
    }
```

### 15.7 后端 sop（各阶段状态）

| 阶段 | 后端 | 状态 | 说明 |
|------|------|------|------|
| 阶段一（POC） | GDI-Legacy | [OK] 已通过 | 验证 AOT 扫描链 |
| 阶段二（GDI 兼容层） | GDI-Legacy | [OK] 已通过 | calculator-ng 全 UI 通过 |
| 阶段三（真 Skia） | Skia-CPU | [OK] spike 通过（有限制） | SkBitmap + SkCanvas + drawRect/drawRRect |
| 阶段四五（GPU） | D3D11/WGL/Dawn/D2D | [待实现] | GPU 加速后端 |

> **阶段三说明**：基于 aseprite m148 FCI 分支，文本渲染依赖阶段三集成 DirectWrite，MSVC 17.10+ STL helpers 已基于 `_MSC_VER` 条件编译。Skia 库采用 `/MT` 静态 CRT。详见 `docs/skia-render-context-guide.md`。

### 15.8 已知问题

1. **全局变量**：`g_skHwnd/g_skHdc/g_skSurface` 的模块静态变量会导致冲突，Phase 6 通过 `php::Box` 重构
2. **文本回退限制（Skia-CPU）**：基于 aseprite m148 fork 移除 `SkFontMgr_New_FCI`，`skEnsureFont()` 返回 `false` 走空路径，文字/标签为空白。阶段三集成 `SkFontMgr_New_DirectWrite` 使用 Segoe UI
3. **MSVC 17.10+ STL stubs**：8 个 `__std_*` 函数基于 `_MSC_VER` 条件编译
4. **/MT 静态 CRT**：Skia 预期使用 `/MT`，skia-poc 有 `/MT` 覆盖
5. **GPU 后端未启用**：当前仅 CPU Skia，GPU 后端留到 Phase 4-5

### 15.9 关键文件

| 文件 | 说明 |
|------|------|
| `framework/Rendering/Backend/IRenderBackend.php` | 后端统一接口 |
| `framework/Rendering/Backend/BackendRegistry.php` | 后端注册表（6 个候选） |
| `framework/Rendering/Backend/BackendCapability.php` | 探测结果描述 |
| `framework/Rendering/Backend/BackendInitException.php` | 初始化异常 |
| `framework/Rendering/Backend/RenderBackendFailedException.php` | 运行期异常 |
| `framework/Rendering/Backend/RuntimeBackendSelector.php` | 运行时选择器（probe+fallback） |
| `framework/Rendering/Backend/ResilientRenderContext.php` | 故障降级代理 |
| `framework/Rendering/Backend/GdiLegacyBackend.php` | GDI 传统后端（永远可用） |
| `framework/Rendering/Backend/GdiDirect2DBackend.php` | GDI Direct2D（预留） |
| `framework/Rendering/Backend/SkiaCpuBackend.php` | Skia CPU（阶段三） |
| `framework/Rendering/Backend/SkiaGaneshD3D11Backend.php` | Skia D3D11（预留） |
| `framework/Rendering/Backend/SkiaGaneshWGLBackend.php` | Skia WGL（预留） |
| `framework/Rendering/Backend/SkiaGraphiteDawnBackend.php` | Skia Dawn（预留） |
| `cpp/skia_render.cc` | C++ 原生层 |
| `stub/skia.stub.php` | stub 声明文件 |
| `framework/Rendering/RenderContext.php` | 渲染上下文抽象基类 |
| `framework/Rendering/GdiRenderContext.php` | GDI 绘制实现 |
| `docs/skia-render-context-guide.md` | 实施指南 |

### 15.10 修改框架代码时的补充清单

在第十三条"修改框架代码时的检查清单"基础上，补充：

21. **新增 sk_* 原生函数**：需同时更新 stub（`stub/skia.stub.php`）+ PHP 层 + C++ 层，三端保持一致
22. **修改 RenderContext 抽象方法**：同步更新所有后端 RenderContext 实现
23. **新增后端**：实现 `IRenderBackend` → 注册到 `BackendRegistry::CANDIDATES` → 处理 `PX_RENDERER` 映射