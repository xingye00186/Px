# Px Framework — AI-Friendly 全流程指南

## 一、一句话概述

Px 是一个 **PHP → 原生 exe** 的桌面 GUI 框架，模板语法对标 **Vue 3**，渲染引擎基于 **Win32 GDI**，通过 **Swoole Compiler** 实现 AOT 编译。

```
.vue 文件 → sfc-compiler.php → 生成 PHP 类 → Swoole Compiler → C++ → MSVC → .exe
```

---

## 二、目录结构速查

```
d:/Px/
├── framework/              核心框架（只读，所有应用共享）
│   ├── Core/
│   │   ├── Application.php     事件循环、组件注册、VNode 树展开、bind 解析
│   │   ├── ScrollManager.php   滚动服务（状态管理、拖拽、滚轮、水平滚动）
│   │   └── Scheduler.php       微任务/宏任务调度
│   ├── Rendering/
│   │   ├── VNode.php           虚拟 DOM 节点（布局字段 + 滚动字段 + 组件占位字段）
│   │   ├── VNodeRenderer.php   树遍历 → 收集元素 → 按 layer 分组 → 调用 GDI
│   │   ├── LayoutResolver.php  CSS 布局引擎（block/flex/grid/scroll）
│   │   ├── GdiRenderContext.php Win32 GDI 绘制原语
│   │   ├── CssMappings.php     CSS 属性 → GDI 属性映射
│   │   └── RenderContext.php   渲染上下文接口
│   ├── Platform/
│   │   ├── Platform.php        平台抽象接口
│   │   ├── Win32Platform.php    Win32 消息泵 + 事件解码
│   │   ├── PlatformEvent.php   事件类型层级（Mouse/Keyboard/Window/Timer）
│   │   ├── PlatformFactory.php 平台工厂
│   │   └── WinMsg.php          Win32 消息常量
│   ├── interfaces/
│   │   └── ComponentInterface.php  组件接口契约
│   ├── compiler/
│   │   ├── sfc-compiler.php    主编译器（.vue → PHP 代码生成）
│   │   ├── template-parser.php 模板解析器（HTML → VNode 树）
│   │   ├── script-analyzer.php  脚本分析器（自动注入 markDirty）
│   │   ├── component-registry.php 组件注册表
│   │   └── aot-validator.php    AOT 兼容性检查
│   ├── BaseComponent.php        组件基类（生命期 + 父子层级）
│   ├── ReactiveComponent.php    响应式组件基类（dirty + VNode 缓存）
│   └── aot-checker.php          AOT 规则检查工具（20K 行）
├── apps/                  每个应用一个子目录
│   ├── calculator/            计算器示例（4 个组件、CSS Grid 布局）
│   ├── list-test/             列表滚动测试（v-for + scroll-container）
│   └── test/                  基础测试应用
├── stub/                   PHP stub 文件（C++ 原生函数声明）
├── cpp/                    C++ 桥接层实现
├── docs/                   设计文档
├── tests/                  单元测试（PHPUnit 风格）
├── build.bat               非交互构建脚本
├── sfc-compiler.php        编译器入口（框架根目录）
├── config.yml              编译器路径配置
└── vendor/                 依赖（Composer）
```

### 应用目录模板

```
apps/<app-name>/
├── App.vue                 根组件 SFC
├── main.php                入口：4 个 AOT 常量 + main() 函数
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
    │
    ▼
Platform (Win32Platform::pollEvents)
    │   WM_LBUTTONDOWN → MouseEvent(action='down', x, y)
    │   WM_MOUSEWHEEL  → MouseEvent(action='wheel', x, y, delta)
    │   WM_KEYDOWN      → KeyboardEvent(action='down', keyCode, char)
    ▼
Application::handleMouseEvent / handleKeyboardEvent
    │
    ├─ 滚轮： findScrollContainerAt → handleScrollWheel → applyScrollTop → requestRender
    ├─ 拖拽： hitTestScrollbar → handleScrollbarDown → handleScrollbarDrag → directRender
    └─ 点击： hitTest → resolveComponent → dispatchClick(handler, arg)
         │
         ▼
    Component 方法（如 deleteItem）
         │  修改 $this->todoItems → $this->markDirty()
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
         ├─ LayoutResolver::resolve
         │   ├─ 解析 CSS styles（class + inline 合并）
         │   ├─ 按 display 模式计算 x/y/w/h
         │   ├─ auto-stack 垂直排列子节点
         │   └─ clamp scrollTop + 子节点重定位
         │
         └─ VNodeRenderer::render
             ├─ collectElements（按 layer 分组）
             └─ GdiRenderContext::drawElement（逐 element 调用 GDI 原语）
```

### 3.2 职责边界（SOLID）

```
┌──────────────────────────────────────────────────────────┐
│ 模块              负责                        不负责       │
├──────────────────────────────────────────────────────────┤
│ Component         声明状态 + 绑定键            不参与坐标 │
│ Application       事件路由 + bind 解析         不参与布局 │
│ LayoutResolver    所有坐标计算                 不参与渲染 │
│ VNodeRenderer     收集元素 + clip 裁切         不修改坐标 │
│ GdiRenderContext  GDI 调用                    不参与布局 │
└──────────────────────────────────────────────────────────┘
```

> **核心原则**：VNode 的 x/y 坐标由 LayoutResolver 一家说了算。Application 只通过 bind 机制（`:scroll-top`）间接影响布局，不直接操作坐标。

---

## 四、关键类速查

### 4.1 VNode（framework/Rendering/VNode.php）

**最重要的字段**（按使用频率排序）：

```php
// —— 树结构 ——
string  $type;         // 'div'|'span'|'button'|'input'|'#root'|'#component'|'#text'
?array  $props;        // HTML 属性 + Vue 指令（@click, :bind, v-for, :scroll-top 等）
mixed   $children;     // VNode[] | VNode | string | null
?string $key;          // v-for key

// —— 布局结果（由 LayoutResolver 填入）——
int $x, $y, $w, $h;           // 绝对坐标
array $computedStyle;         // 合并后的 CSS 属性
int  $layer;                  // z-order
string $groupId = 'app';      // 事件路由 key

// —— 滚动容器 ——
bool $isScrollContainer;
int  $scrollTop;               // 垂直滚动偏移 (px)
int  $contentHeight;           // 可滚动内容总高度 (px)
int  $scrollLeft;              // 水平滚动偏移 (px)
int  $contentWidth;            // 可滚动内容总宽度 (px)

// —— 组件占位 ——
bool $isComponent;
?string $componentClass;
?ReactiveComponent $componentInstance;
?array $componentProps;       // 子组件属性映射
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
//   $this->scheduleUpdate();  // 把 performUpdate() 加入微任务队列

// 在微任务中 → performUpdate() → renderCallback() → Application::requestRender()
// 在事件循环的下一个 tick → Application::render() → getVNodeTree() → $this->render()
```

**必须实现的抽象方法**：
```php
abstract public function render(): VNode;                       // 返回 VNode 树
abstract public function setBindValue(string $key, string $val);  // 写入绑定值
abstract public function getBindValue(string $key): string;      // 读取绑定值
```

**子→父通信**：
```php
// 子组件
$this->emit('itemSelected', ['id' => 5]);
// 父组件
$this->on($child, 'itemSelected', function($payload) { ... });
```

### 4.3 Application（framework/Core/Application.php）

**重要方法速查**：
```php
// 入口
Application::create()->mount($root)->run();

// 组件注册
registerComponent(string $groupId, ReactiveComponent $comp)

// 命中测试
hitTest(int $x, int $y, VNode $node): ?VNode   // 返回最上层可点击 VNode

// 滚动系统
findScrollContainerAt(int $x, int $y, VNode $node): ?VNode
hitTestScrollbar(int $x, int $y, VNode $node): ?array
applyScrollTop(VNode $node, int $value, bool $persist): void
directRender(VNode $tree): void    // 跳过树重建，仅重新 layout + render
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
    → 未匹配的 handler → parent::dispatchClick 冒泡
```

### 5.2 组件中定义事件处理器

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

当前仅支持聚焦 input 元素的 @keydown / @keyup / @enter。

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

> 滚动状态（target、start 坐标、start scroll 位置、isHorizontal）全部在 ScrollManager 中。
> Application 只负责将事件路由给 ScrollManager，不再直接持有滚动状态。

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

<!-- 横向+纵向滚动（overflow:auto 同时启用两轴） -->
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
public string $scrollLeft = "0";  // 水平滚动位置（仅横向容器需要）
```

**横向滚动交互**：`Shift + 滚轮` 触发横向滚动。水平滚动条位于容器底部 12px 区域。

### 6.3 滚动交互流程

```
滚轮 → ScrollManager::handleScrollWheel (含 Shift 键检测 → 横向)
     → applyScrollTop / applyScrollLeft (persist=true)
     → setBindValue → markDirty → requestRender

轨道点击 → ScrollManager::hitTestScrollbar (返回 {scrollNode, type, isHorizontal})
       → handleScrollbarDown → applyScroll*(jumped_value, persist=true)

滑块拖拽 → hitTestScrollbar → handleScrollbarDown(type='thumb')
       → handleScrollbarDrag (高频) → applyScroll*(persist=false) → directRender
       → 鼠标释放 → applyScroll*(persist=true) → requestRender
```

### 6.4 核心机制

1. **Bind 同步**：`resolveVNodeBindings` 在每次 rebuild 时将组件 `scrollTop`/`scrollLeft` 值写入 `VNode`
2. **布局偏移**：LayoutResolver 用 `childOffsetY = node.y - scrollTop` 和 `childOffsetX = node.x - scrollLeft` 定位子节点
3. **自动 clamp**：auto-stack 后若 `scrollTop > maxScroll` 或 `scrollLeft > maxScrollX`，LayoutResolver 自动修正并重定位子节点
4. **拖拽优化**：拖拽过程中走 `directRender`，跳过 VNode 树重建
5. **横向滚动检测**：`overflow-x:auto` / `overflow-x:scroll` 或 `overflow:auto` 继承两轴

### 6.5 多滚动容器注意事项

- 滚轮事件找**鼠标下方最深的**滚动容器
- 滚动条拖拽一次**只能操作一个**容器
- 拖拽状态由 ScrollManager 持有，拖拽过程中**不要**触发树重建

---

## 七、AOT 编译约束

### 7.1 禁止的 PHP 模式

| 模式 | 原因 |
|------|------|
| `$obj->$prop` 动态属性 | AOT 无法静态推导 |
| `$fn()` 非闭包调用 | 字符串函数名不可编译 |
| `$obj->$method()` 动态方法 | 同上 |
| 顶层 `require_once` / `include` | 必须在函数/类内 |
| `eval()` / `create_function()` | 完全不可编译 |
| `compact()` / `extract()` | 动态变量 |

### 7.2 必须遵守的模式

| 模式 | 说明 |
|------|------|
| `objval($x, ClassName::class)` | AOT 显式类型标注，**必须使用** |
| `ComponentFactory::create($className)` | 允许字符串类名作为工厂参数 |
| `match` 表达式 | 仅 swoole_compiler 自带的 PHP 8.x 支持 |

### 7.3 构建前检查

```bash
# 使用 compiler 自带的 PHP 做语法检查
D:\swoole_compiler\php.exe -l framework/Core/Application.php

# AOT 静态检查（build.bat Step 0.5 自动运行）
D:\swoole_compiler\php.exe framework/aot-checker.php --project apps/list-test --skip direct_cpp_call
```

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
Step 0.5: AOT 静态检查 → 检查禁止模式
Step 1:   SFC 编译 (.vue → gen/*.php)
Step 2:   AOT 编译 (PHP → C++ → link → .exe)
Step 3:   打包 (exe + php8ts.dll + phpx.dll → bin/)
```

### 8.3 常见失败

| 错误 | 解决 |
|------|------|
| `cl.exe` 找不到 | 从 Developer Command Prompt for VS 运行 |
| `php8embed.lib` 找不到 | 复制到 `D:\swoole_compiler\` 根目录 |
| AOT Checker 报错 | 检查代码是否使用了禁止模式 |
| Step 2 Swoole 编译器报错 | 先用手动 `php -l` 检查 PHP 语法 |
| 系统 `php -l` 报语法错 | 用 `D:\swoole_compiler\php.exe` 而非系统 PATH 中的 PHP |

---

## 九、常见开发任务

### 9.1 新建应用

1. 在 `apps/` 下创建目录
2. 创建 `main.php`（4 个常量 + main()）：
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

SFC 编译器会自动为 `myValue` 生成 `getBindValue` / `setBindValue` 的 case 分支。

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

支持 **Vue 3 风格**：`v-for` 可以写在 `<template>` 或任意 HTML 元素（`<div>`、`<span>` 等）上。

**`<template v-for>`** — 仅重复子节点，不产生额外包装元素：

```html
<template v-for="item in items" :key="item.id">
  <div @click="handleClick(item.id)">
    <span>{{ item.text }}</span>
  </div>
</template>
```

**元素 v-for**（Vue 3 风格） — 元素本身参与循环：

```html
<div v-for="item in items" :key="item.id" @click="handleClick(item.id)">
  <span>{{ item.text }}</span>
</div>
```

两种写法均会被编译器提取为独立的 render 辅助方法，`{{ item.text }}` 等循环变量会被正
确处理为局部变量而非组件级 bind key。

### 9.5 使用子组件

1. 创建子组件 `.vue` 文件
2. 在父组件模板中引用：
```html
<my-component :my-prop="parentValue"></my-component>
```
3. SFC 编译器自动发现、编译、生成占位 VNode
4. Application 在运行时展开

### 9.6 调试技巧

- **检查 VNode 树**：在 `render()` 返回前 `var_dump` VNode 结构（需在开发环境 PHP 而非 AOT 中运行）
- **检查布局**：查看 `LayoutResolver::resolve()` 返回的 `scrollContainers` 列表
- **检查渲染元素**：在 `collectElements` 中打印 `$elementsByLayer`
- **formatted 输出**：在 `Application::render()` 中调用 `var_dump` 输出 activeVNodeTree

---

## 十、已知问题与设计债务

### 10.1 SOLID 违反：Application 持有 scrollDragTarget — ✅ 已解决

> `ScrollManager` 服务已抽取（`framework/Core/ScrollManager.php`）。Application 仅负责事件路由，
> 所有滚动状态（drag target、drag start 坐标、drag start scroll 位置）和逻辑（滚轮、拖拽、clamp）
> 归属 ScrollManager。横向滚动状态同样由 ScrollManager 统一管理。

### 10.2 多滚动容器限制

`scrollDragTarget` 是单引用，同一时刻只能拖拽一个滚动条（鼠标操作天然如此，暂不影响使用）。但如果未来增加键盘滚动，需要改为容器 ID 索引的 Map。

### 10.3 VNode 悬空引用风险

拖拽过程中若 VNode 树被重建（例如定时器触发 markDirty），`scrollDragTarget` 指向旧的对象。当前通过 `directRender` 避免重建，但长期需改为 stable identifier。

### 10.4 Bind 值同步延迟

LayoutResolver clamp 后，组件的 bind 值（如 scrollTop）保持旧值。下一次 render 时先恢复旧值、再被 LayoutResolver 重新 clamp——每帧一次"错误→修正"循环。需要 `setBindValueSilent` 方法。

### 10.5 未实现的功能

- 键盘滚动（PgUp/PgDn/Home/End/Arrow）
- 编程式滚动到指定 item
- 窗口 resize 时的动态重布局（当前需要手动触发渲染）
- 文字输入时 IME 支持

---

## 十一、编码约定

### 11.1 PHP 版本要求

- 源文件：PHP 8.0+（使用 `match` 表达式）
- AOT 编译：swoole_compiler 内置 PHP 8.x
- **系统 PATH 中的 PHP 可能是 7.4，仅用于开发调试，不能用于编译**

### 11.2 代码风格

- 使用 4 空格缩进
- 类属性使用 `protected` 或 `private`（AOT 友好）
- `public` 属性用于组件状态（由 SFC 编译器生成）
- 方法名 camelCase
- VNode factory 统一使用 `VNode::h()` 和 `VNode::hComponent()`

### 11.3 VNode 树规范

- 每个组件的 `render()` 返回以 `#root` 为根的 VNode 树
- `#root` 的 style 设置 `width` 和 `height`
- `#component` 是运行时展开的占位节点，不产生渲染
- `#text` 用于纯文本节点
- children 可以是 `null`、`string`、`VNode`、`VNode[]`

---

## 十二、测试

### 运行测试

```bash
cd tests/unit
D:\swoole_compiler\php.exe bootstrap.php
```

### 测试文件

| 文件 | 覆盖范围 |
|------|---------|
| `ReactiveComponentTest.php` | dirty 标记、VNode 缓存、组件更新 |
| `HitTestTest.php` | 命中测试、事件路由 |
| `LayoutResolverTest.php` | block/flex/grid/scroll 布局 |
| `VNodeRendererTest.php` | 元素收集、layer 分组、clip |
| `SfcCompilerVIfTest.php` | v-if 编译期优化 |

---

## 十三、修改框架代码时的检查清单

1. **PHP 语法**：`D:\swoole_compiler\php.exe -l <file>`
2. **AOT 兼容**：无 `->$var`、无动态调用
3. **布局职责**：LayoutResolver 管位置，VNodeRenderer 管裁切，互不越界
4. **Bind 同步**：新增 bind 属性后在组件中声明 `public string`，编译器自动生成 get/set
5. **事件冒泡**：子组件 dispatchClick 的 default 分支调用 `parent::dispatchClick`
6. **构建验证**：`build.bat <app-name>` 全流程通过
