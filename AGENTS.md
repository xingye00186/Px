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
             ├─ collectElements（按 layer 分组，scroll/overflow:hidden 生成 clip-push/clip-pop）
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
│ VNodeRenderer     收集元素 + clip 裁切（scroll + overflow:hidden） 不修改坐标 │
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

### 7.4 闭包使用限制

**问题**：`v-for` 循环内使用闭包（如条件 class）时，AOT 编译会丢失闭包外部变量的作用域，导致 `$ch` 等循环变量无法访问。

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
- 不使用 `v-if` / `v-else`，改用**两个独立的 `v-for`** 遍历不同数据源
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
Step 1:   SFC 编译（编译根组件 App.vue，自动 BFS 发现并编译所有子组件到 gen/*.php）
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
| 编译子组件 .vue 后 gen/ 未更新到正确位置 | 必须编译根组件 App.vue，子组件不会被单独编译到 apps/<name>/gen/ |

### 8.4 多机器 vcvarsall 路径配置

`build.bat` 的 Step 0 需要找到 `vcvarsall.bat` 来初始化 MSVC 编译环境。不同机器上 Visual Studio 安装路径可能不同（如 VS 2017/2019/2022、Community/Professional/Enterprise），框架采用**三级优先级自动检测**：

| 优先级 | 来源 | 说明 |
|--------|------|------|
| 1 | 当前 PATH | 如果 `cl.exe` 已在 PATH 中（如手动打开 VS Dev Cmd），直接跳过 vcvarsall |
| 2 | `config.yml` | 在项目根目录 `config.yml` 中配置 `vcvarsall` 键，显式指定路径 |
| 3 | 自动搜索 | 递归搜索 `C:\Program Files\Microsoft Visual Studio\` 下所有 `vcvarsall.bat`，取第一个 |

**配置示例**（`config.yml`）：

```yaml
# 家目录电脑 VS 2022 Community
vcvarsall: C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat

# 笔记本 VS 2019 Professional（注释掉不需要的行）
# vcvarsall: C:\Program Files\Microsoft Visual Studio\2019\Professional\VC\Auxiliary\Build\vcvarsall.bat
```

> **提示**：绝大多数情况下**无需配置**，自动搜索即可覆盖 VS 2017/2019/2022 的所有版本。只有在自动搜索失败或需要指定特定版本时才需要手动配置。

### 8.5 config.yml 配置文件

`config.yml` 是构建系统的核心配置文件，必须位于项目根目录。首次使用时可从模板复制：

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
| `swoole_compiler path not found in config.yml` | config.yml 不存在或路径错误 | 从 `config.example.yml` 复制并修改路径 |
| `swoole_compiler directory not found` | 路径指向的目录不存在 | 检查并修正 `swoole_compiler` 配置 |

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

### 9.5 使用 v-if / v-else-if / v-else

支持 Vue 3 风格的条件渲染链：

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
- `v-else-if` 和 `v-else` 必须紧跟在 `v-if` 之后，中间不能有其他非条件元素
- 编译器使用 `ExpressionParser` 解析条件表达式，支持三元表达式、比较运算、逻辑运算

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
2. 在父组件模板中引用：
```html
<my-component :my-prop="parentValue"></my-component>
```
3. SFC 编译器自动发现、编译、生成占位 VNode
4. Application 在运行时展开

### 9.9 重新编译 SFC（修改 .vue 后）

修改 `.vue` 文件后，必须重新编译才能生效。关键规则：

- **编译根组件 App.vue**（而非子组件），编译器会 BFS 发现所有有变更的子组件并自动重新编译
- 输出目录由 .vue 文件路径决定：`dirname($vueFile) + '/gen/'`
  - 编译 `apps/<name>/App.vue` → 输出到 `apps/<name>/gen/`（正确位置）
  - 编译 `apps/<name>/components/MyComp.vue` → 输出到 `apps/<name>/components/gen/`（错误位置）
- 命令：`php sfc-compiler.php apps/<name>/App.vue`
- **禁止手动编辑 `gen/*.php` 文件**（会被编译器覆盖）

### 9.10 调试技巧

- **检查 VNode 树**：在 `render()` 返回前 `var_dump` VNode 结构（需在开发环境 PHP 而非 AOT 中运行）
- **检查布局**：查看 `LayoutResolver::resolve()` 返回的 `scrollContainers` 列表
- **检查渲染元素**：在 `collectElements` 中打印 `$elementsByLayer`
- **formatted 输出**：在 `Application::render()` 中调用 `var_dump` 输出 activeVNodeTree

### 9.7 AI 自动截图测试

在进行 UI 渲染测试时，可以使用 PowerShell 脚本自动截图验证布局效果。

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

- 截图前需确保 `gen/` 目录被清理，否则可能使用旧代码
- 每个应用目录应只保留一个 `.vue` 文件（按字母顺序编译）
- 窗口定位使用 `EnumWindows` 匹配进程 PID，避免捕获错误窗口

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

Px 框架使用**三层测试策略**：
1. **单元测试**（PHP）— dispatchClick 模拟点击 + 组件树语义验证
2. **状态快照测试**（PHP）— 文字版"截图"，将组件状态序列化为可读文本
3. **截图测试**（PowerShell）— 启动真实 exe 抓取窗口截图，用于视觉回归

### 测试设计原则

| 原则 | 说明 |
|------|------|
| **不依赖外部服务** | 所有测试在内存中运行，无文件/网络/数据库依赖 |
| **dispatchClick 驱动** | 模拟用户点击，直接调用组件 handler 方法 |
| **状态断言 + 快照** | 既校验具体属性值，也 dump 完整状态用于调试 |
| **组件树语义对标 Vue 3** | 测试 parent 链、事件冒泡、VNode 缓存、patchComponentTree |
| **AOT polyfill** | bootstrap.php 提供 `objval()`、`any()` 等 AOT 函数 polyfill |

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
D:\swoole_compiler\php.exe tests/unit/PlatformTest.php

# 运行完整渲染管道测试（快照差异分析）
D:\swoole_compiler\php.exe tests/unit/RenderingPipelineTest.php

# 运行内存压力测试（多帧累积检测）
D:\swoole_compiler\php.exe tests/unit/MemoryStressTest.php
```

### 测试文件

| 文件 | 覆盖范围 | 用例数 |
|------|---------|--------|
| `CalculatorAppTest.php` | 计算器全部 18 类操作 + 状态快照 + 边界情况 | 107 |
| `ComponentTreeTest.php` | 组件 parent 链、事件冒泡、实例独立、生命周期、VNode 缓存、hComponent 工厂、patchComponentTree、组件定位保留 | 26 |
| `ReactiveComponentTest.php` | dirty 标记、VNode 缓存、组件更新 | 9 |
| `HitTestTest.php` | 命中测试、事件路由 | 10 |
| `LayoutResolverTest.php` | block/flex/grid/scroll 布局 | 14 |
| `VNodeRendererTest.php` | 元素收集、layer 分组、clip（scroll + overflow:hidden）、button 边框渲染、render 完整流程 | 20 |
| `SfcCompilerPartsTest.php` | 编译器 parts 元数据：collectVNodeBindKeys 提取、generateVNodeExpr 代码生成 | 8 |
| `SfcCompilerVIfTest.php` | v-if 编译期优化（含连续相同条件合并） | 9 |
| `CssMappingsBorderTest.php` | border 简写/独立属性解析、parseInlineStyle/parseStyleBlock 边框处理、hexToBgr/borderColor 辅助函数 | 14 |
| `PlatformTest.php` | Platform 接口 SOLID/DIP 合规 | 10 |
| `MemoryStressTest.php` | 内存增长检测（9 模块 28+ 场景） | 28+ |
| `RenderingPipelineTest.php` | 完整渲染管道快照差异分析（100 次循环点击 + 5 类规则校验 + 异常存档） | 5 |
| `ListTestPipelineTest.php` | list-test 渲染管道测试（30 次点击 + 增长规则 + clip 有效性 + 滚动拖动） | 8 |
| `GdiRenderContextTest.php` | GDI 渲染上下文直接测试（clip 栈 + drawText 截断 + 参数守卫） | 15 |

### CalculatorAppTest 测试清单

覆盖以下 18 类场景（107 个测试用例）：

| # | 类别 | 用例数 | 说明 |
|---|------|--------|------|
| 1 | Digit Input | 7 | 初始显示、数字输入、去除前导零、运算符后新输入 |
| 2 | Decimal Input | 6 | 小数点输入、防重复、运算符后新输入、15 位限制（2 个） |
| 3 | Clear/Reset | 2 | C 清除输入、AC 完全重置 |
| 4 | Backspace | 4 | 删除末位、归零、newInput 保护、删除小数点 |
| 5 | Toggle Sign | 3 | 正负切换、零值保护 |
| 6 | Percentage | 2 | 50%→0.5、200%→2 |
| 7 | Basic Arithmetic | 6 | ±×÷、除以零 Error、空操作符 |
| 8 | Operator Chaining | 3 | 链式计算、运算符覆盖、混合运算 |
| 9 | Scientific Functions | 14 | sin/cos/tan/log/ln/x²/x³/√/inv/π/e + Error 分支 |
| 10 | Memory Functions | 6 | MS/MR/MC/M+/M−/空记忆 |
| 11 | Parentheses | 4 | openParen/closeParen 显示 |
| 12 | History | 5 | 历史记录生成、切换面板、清除、加载 |
| 13 | Error Recovery | 3 | Error 后数字/C/= 恢复 |
| 14-16 | Routing | 26 | ScientificPad/BasicPad/HistoryPanel 冒泡路由 |
| 17 | State Snapshot | 3 | 视觉化状态跟踪：完整会话、Error→恢复、括号表达式 |
| 18 | Edge Cases | 13 | 超大数字、运算符链、重复等号、带符号运算、连续清除、多轮压力测试等 |

### ComponentTreeTest 测试清单

覆盖 8 类 Vue 3 组件语义（26 个测试用例）：

| # | 类别 | 说明 |
|---|------|------|
| 1 | Parent Chain | setParent/getParent、addChild 双向绑定、孤立组件 |
| 2 | Event Bubbling | dispatchClick 沿 parent 冒泡、stop 消费、null parent、dispatchKey |
| 3 | Instance Identity | 同类型不同实例、唯一 ID |
| 4 | Lifecycle | mount/unmount、重复 mount |
| 5 | VNode Caching | 首次 render()、缓存复用、dirty 重建、markDirty 清缓存 |
| 6 | VNode Factory | hComponent 占位、componentProps 映射、groupId 递归 |
| 7 | Patch Component Tree | 普通节点 groupId、#component 展开、实例复用（同 class+同位置） |
| 8 | Component Positioning | matchComponentNode 实例重用后 transferComponentPositioning 保留定位 |

### 截图测试

提供 PowerShell 脚本用于视觉回归：

```powershell
# 直接截图（使用已有 exe）
powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1

# 先构建再截图
powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1 -BuildFirst $true
```

截图保存在 `tests/screenshot/output/<timestamp>/`，并自动生成 HTML 报告。

### 测试最佳实践（经验总结）

1. **dispatchClick 是首选测试方式** — 直接调用组件 handler，不依赖布局坐标和渲染管道，速度快、结果确定
2. **测试 helper 函数化** — `createApp()`、`runCalculation()`、`assertDisplay()`、`captureState()` 等 helper 提高可读性和可维护性
3. **避免过度模拟** — 测试真实组件行为比 mock 更有价值。只在需要隔离时才用 test double
4. **状态快照 vs 具体断言** — 关键路径用具体断言（`assertDisplay('42')`），调试用状态快照（`captureState()`）
5. **Application 私有方法通过反射测试** — `newInstanceWithoutApp()` + `ReflectionMethod` 访问 private 方法
6. **先修复测试再提交** — 失败的测试比没有测试更糟。每次修改后运行全部测试确保回归
7. **组件树测试验证框架语义** — ComponentTreeTest 验证框架层面的 Vue 3 语义对齐，不依赖具体应用
8. **Mock 渲染上下文暴露 GDI 不可测漏洞** — `_MockRenderContext` 只记录 `drawElement()` 调用，不执行真实 GDI。Bug 发生在 GDI 实现层（clip 边界绘制累积损坏 HDC 状态），纯元素层 Mock 无法捕获。补偿策略：
   - Mock 需模拟 clip 栈追踪 + 文本截断（`applyClipTruncation()` 与 `GdiRenderContext::drawText()` 逻辑一致）
   - 流水线测试必须包含 clip 溢出规则（Rule E：任何溢出 ≥1px 即告警）
   - GDI 层行为必须通过 `GdiRenderContextTest.php` 直接验证（stub GDI C++ 函数记录调用参数）
9. **clip-aware drawText 是所有 text 输出路径的必选守卫** — 任何新增的 GDI text 调用点都必须经过 `drawText()`（含 clip 截断），禁止直接调 `vue_draw_text()`
10. **新应用接入时必须添加对应的流水线测试** — 至少包含：N 次循环点击稳定性测试 + A/B/C 规则（不变/条件/约束） + clip 有效性规则
11. **粗体文本字符宽度是常规体的 1.35 倍** — `drawText()` 截断逻辑必须区分 `$bold` 参数。粗体 36px 实际宽度 ~28px/char，而 `fontSize * 0.6` 只给出 21px/char。未区分粗体会导致截断后仍然溢出
12. **测试必须覆盖完整的用户操作链** — 仅测试"一直按 1"不够，必须包含"大量操作 → 清除/重置 → 验证 UI 完整性"的端到端场景。每个新管道测试都应包含 clear-after-corruption 验证
13. **按钮标签提取测试** — 使用 `<button><span :bind="label">{{ label }}</span></button>` 模板时，`makeButtonElement()` 必须提取到标签。管道测试中 `ltCheckButtonLabel()` 应断言 label 非空，不再标记为"known bug"
14. **滚动拖动测试必须验证 auto-stacked 位置** — 仅测试"添加 item 后布局正确"不够。必须模拟滚动拖动（直接设置 scrollTop + directRender），验证 auto-stacked items 的 y 坐标保持严格递增不折叠。洁净路径中 `style` 无显式 `top` 的节点不应被重算 y

---

## 十三、修改框架代码时的检查清单

1. **PHP 语法**：`D:\swoole_compiler\php.exe -l <file>`
2. **AOT 兼容**：无 `->$var`、无动态调用
3. **布局职责**：LayoutResolver 管位置，VNodeRenderer 管裁切，互不越界
4. **负宽高防御**：LayoutResolver 中所有 `$node->w`/`$node->h` 赋值用 `max(0, (int)$val)`
5. **GDI 调用保护**：GdiRenderContext 中所有 GDI 调用前检查 `$w > 0 && $h > 0`
6. **drawText clip 截断**：所有 text 绘制必须经过 `drawText()`（含 `clipStack` 追踪 + 粗体感知溢出截断），禁止直接调 `vue_draw_text()`。新增 text 输出路径时必须同步添加截断逻辑。截断公式：`charWidth = (int)(fontSize * 0.6 * ($bold ? 1.35 : 1.0))`，并保留 4px 安全余量
7. **clip 栈平衡**：clip-push/clip-pop 必须成对出现，每帧结束时 clip 栈应为空。`GdiRenderContextTest` 中已有 `clip stack push and pop balanced` 测试
8. **Mock clip 追踪**：修改 `_MockRenderContext`/`_LTMockRenderContext` 时必须同步添加 clip 栈追踪 + `applyClipTruncation()`（含粗体因子和 4px 安全余量），确保 mock 的可见行为接近真实 GDI
9. **overflow:hidden 裁切**：需要裁切子内容的容器必须设置 `overflow:hidden`，VNodeRenderer 会为其生成 clip-push/clip-pop
10. **数值输入限制**：所有数值输入方法（inputDigit、inputDecimal 等）必须有 15 字符长度限制
11. **Bind 同步**：新增 bind 属性后在组件中声明 `public string`，编译器自动生成 get/set
12. **事件冒泡**：子组件 dispatchClick 的 default 分支调用 `parent::dispatchClick`
13. **SFC 编译**：仅编译根组件 App.vue，不直接编译子组件 .vue；不手动编辑 gen/*.php
14. **构建验证**：`build.bat <app-name>` 全流程通过
15. **测试完整闭环**：新增管道测试必须覆盖完整的用户操作链（不限于一直按同一按钮），包括：大量操作后 → 清除/重置 → 验证所有 UI 元素完整的端到端场景
16. **按钮标签提取**：`makeButtonElement()` 必须遍历子 RenderNode 提取标签（`<button><span :bind="x">{{ x }}</span></button>`），仅检查 `node->content`(string) 和 `props[':bind']` 不够，还要检查子节点的 content 和 bind 引用
17. **LayoutResolver 洁净路径保留 auto-stack 位置**：洁净路径（`layoutDirty=false`）中，只有显式 `top`/`left` 定位的节点才重算 x/y。auto-stacked 子节点应保留脏路径设定的位置，仅由快速滚动路径（`shiftChildrenY`）平移。修改 `resolveNode()` 中 `$node->x = ($style['left'] ?? 0) + $parentX` 这类无条件赋值时必须改用 `array_key_exists` 保护

---

## 十四、新增 CSS 布局属性（LayoutResolver v2）

以下 CSS 布局属性已在 LayoutResolver 中实现支持：

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

百分比在尺寸解析**之后**、min/max 约束**之前**应用。百分比也在 flex 和 grid 容器上生效。

### position:relative
- 在 auto-stack 中，`position:relative` 的子节点**不禁止** auto-stack
- `top` 在 auto-stacked 位置基础上做额外偏移，不影响兄弟节点定位
- `left` 通过 resolveBlockLayout 的 relative 路径正确处理

### Flex 扩展
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `order` | 排列顺序（冒泡排序，稳定） | 0 |
| `flex-basis` | 初始主轴尺寸 (`auto` 回退到 `width`/`height`) | `auto` |
| `flex-shrink` | 收缩因子 | 1 |
| `align-self` | 单项交叉轴对齐 (`auto`/`flex-start`/`flex-end`/`center`/`stretch`) | `auto` |

### Flex-shrink 算法
```
overflow = totalMain - containerMain  (当 overflow > 0)
totalShrinkWeight = Σ(item.mainSize × item.shrink)
item.mainSize -= overflow × (item.mainSize × item.shrink) / totalShrinkWeight
min-width/min-height 约束在收缩后应用
```

### Grid 扩展
| 属性 | 说明 | 默认值 |
|------|------|--------|
| `align-self` | 垂直方向对齐 (`stretch`/`center`/`start`/`end`) | `stretch`(auto) |
| `justify-self` | 水平方向对齐 (`stretch`/`center`/`start`/`end`) | `stretch`(auto) |

### 内联样式百分数预检测
`CssMappings::parseInlineStyle()` 在解析时自动检测 `width`、`height`、`min-width`、`max-width`、`min-height`、`max-height` 的百分比值，存入 `*Percent` 键（如 `widthPercent`），LayoutResolver 在父容器尺寸已知时据此解析实际像素值。
