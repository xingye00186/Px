# Px Framework   AI-Friendly 全流程指區

## �?、一句话概述

Px 昀��一 **PHP ↀ 原生 exe** 的�靀 GUI 框架，模板�法�栀 **Vue 3**，渲染引擎基亀 **Win32 GDI**，�?�过 **Swoole Compiler** 实现 AOT 编译　

```
.vue 文件 ↀ sfc-compiler.php ↀ 生成 PHP 籀 ↀ Swoole Compiler ↀ C++ ↀ MSVC ↀ .exe
```

---

## 二�?�目录结构�?�查

```
d:/Px/
├─�? framework/              核心框架（只读，�?有应用共亀��
─   ├─�? Core/
─   ─   ├─�? Application.php     事件往��、组件注册�?�VNode 树展�?、bind 解析
─   ─   ├─�? ScrollManager.php   滚动服务（状态�理�?�拖拽�?�滚轀?�水平滚劀��
─   ─   ├─�? Scheduler.php       往��劀/宏任务调庀
─   ─   ├─�? Config.php          配置管理类（px_debug.yml 解析＀
─   ─   └─�? RenderTreeManager.php 渲染树�理（VNode 缓存/巀异追踼�
─   ├─�? Rendering/
─   ─   ├─�? VNode.php           虚拟 DOM 节点（布�?字� + 滚动字� + 组件占位字�＀
─   ─   ├─�? VNodeRenderer.php   树遍厀 ↀ 收集元素 ↀ 挀 layer 分组 ↀ 调用 GDI
─   ─   ├─�? LayoutResolver.php  CSS 布局引擎（block/flex/grid/scroll＀
─   ─   ├─�? GdiRenderContext.php Win32 GDI 绘制原�
─   ─   ├─�? CssMappings.php     CSS 属�?� ↀ GDI 属�?�映尀
─   ─   └─�? RenderContext.php   渲染上下文接叀
─   ├─�? Platform/
─   ─   ├─�? Platform.php        平台抽象接口
─   ─   ├─�? Win32Platform.php    Win32 消息泀 + 事件解码
─   ─   ├─�? PlatformEvent.php   事件类型层级（Mouse/Keyboard/Window/Timer＀
─   ─   ├─�? PlatformFactory.php 平台工厂
─   ─   └─�? WinMsg.php          Win32 消息常量
─   ├─�? interfaces/
─   ─   └─�? ComponentInterface.php  组件接口契约
─   ├─�? compiler/
─   ─   ├─�? sfc-compiler.php    主编译器＀.vue ↀ PHP 代码生成＀
─   ─   ├─�? template-parser.php 模板解析噀��HTML ↀ VNode 树）
─   ─   ├─�? script-analyzer.php  脚本分析噀��臀��注入 markDirty＀
─   ─   ├─�? component-registry.php 组件注册血
─   ─   └─�? aot-validator.php    AOT 兼�性�柀
─   ├─�? BaseComponent.php        组件基类（生命期 + 父子层级＀
─   ├─�? ReactiveComponent.php    响应式组件基类（dirty + VNode 缓存＀
─   └─�? aot-checker.php          AOT 规则�?查工具（20K 行）
├─�? apps/                  每个应用�?一��盀��
─   ├─�? bilibili/             Bilibili 面板复刻应用
─   ├─�? calculator-ng/        计算器演示（4 组件、CSS Grid 布局＀
─   ├─�? skia-poc/             Skia 渲染后� POC 验证应用
─   ├─�? list-test/             列表滚动测试（v-for + scroll-container＀
─   └─�? design-guide/          设�指南说明应用
├─�? stub/                   PHP stub 文件（C++ 原生函数声明＀
├─�? cpp/                    C++ 桥接层实玀
├─�? docs/                   设�文档
├─�? tests/                  单元测试（PHPUnit 风格 + 戀��测试＀
─   ├─�? unit/               单元测试
─   ├─�? screenshot/         戀��臀��化测试（PowerShell＀
─   └─�? run_all_tests.php   统一测试运�噀
├─�? build.bat               非交互构建脚最
├─�? sfc-compiler.php        编译器入口（框架根目录）
├─�? config.yml              编译器路径配罀
└─�? vendor/                 依赖（Composer＀
```

### 应用盀��模板

```
apps/<app-name>/
├─�? App.vue                 根组什 SFC
├─�? main.php                入口＀4 一 AOT 常量 + main() 函数
├─�? project.yml             构建配置
├─�? components/             子组件（叀?�）
─   └─�? *.vue
├─�? gen/                    臀��生成皀 PHP 组件（由 sfc-compiler 产出＀
─   ├─�? AppComponent.php
─   ├─�? *Component.php
─   └─�? ComponentFactory.php
└─�? bin/                    构建输出＀.exe + .dll＀
```

---

## 三�?�核心架枀

### 3.1 完整数据浀

```
用户在窗口中操作
    ─
    ▀
Platform (Win32Platform::pollEvents)
    ─   WM_LBUTTONDOWN ↀ MouseEvent(action='down', x, y)
    ─   WM_MOUSEWHEEL  ↀ MouseEvent(action='wheel', x, y, delta)
    ─   WM_KEYDOWN      ↀ KeyboardEvent(action='down', keyCode, char)
    ▀
Application::handleMouseEvent / handleKeyboardEvent
    ─
    ├─ 滚轮＀ findScrollContainerAt ↀ handleScrollWheel ↀ applyScrollTop ↀ requestRender
    ├─ 拖拽＀ hitTestScrollbar ↀ handleScrollbarDown ↀ handleScrollbarDrag ↀ directRender
    └─ 点击＀ hitTest ↀ resolveComponent ↀ dispatchClick(handler, arg)
         ─
         ▀
    Component 方法（� deleteItem＀
         ─  俀�� $this->todoItems ↀ $this->markDirty()
         ▀
    Scheduler::flushMicrotasks
         ─  performUpdate ↀ renderCallback ↀ Application::requestRender
         ▀
    Application::render
         ─
         ├─ rebuildVNodeTree
         ─   ├─ rootComponent->getVNodeTree()    // 调用 render()，返囀 VNode 栀
         ─   ├─ expandComponentTree()             // 展开子组件占位节炀
         ─   └─ resolveVNodeBindings()            // 将组什 bind 值写兀 VNode
         ─
         ├─ LayoutResolver::resolve
         ─   ├─ 解析 CSS styles（class + inline 合并＀
         ─   ├─ 挀 display 模式计算 x/y/w/h
         ─   ├─ auto-stack 垂直排列子节炀
         ─   └─ clamp scrollTop + 子节点重定位
         ─
         └─ VNodeRenderer::render
             ├─ collectElements（按 layer 分组，scroll/overflow:hidden 生成 clip-push/clip-pop＀
             └─ GdiRenderContext::drawElement（�?� element 调用 GDI 原�＀
```

### 3.2 职责边界（SOLID＀

```
┌─�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?─
─ 模块              负责                        不负贀       ─
├─�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?─
─ Component         声明状�?� + 绑定销            不参与坐栀 ─
─ Application       事件跀�� + bind 解析         不参与布�? ─
─ LayoutResolver    �?有坐标�简                 不参与渲柀 ─
─ VNodeRenderer     收集元素 + clip 裁切（scroll + overflow:hidden＀ 不修改坐栀 ─
─ GdiRenderContext  GDI 调用                    不参与布�? ─
└─�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?─
```

> **核心原则**：VNode 皀 x/y 坐标甀 LayoutResolver �?家�了算。Application 叀?�过 bind 机制（`:scroll-top`）间接影响布�?，不直接操作坐标　

---

## 四�?�关销��速查

### 4.1 VNode（framework/Rendering/VNode.php＀

**�?重�的字殀**（按使用频率排序）：

```php
// —�?� 树结枀 —�?�
string  $type;         // 'div'|'span'|'button'|'input'|'#root'|'#component'|'#text'
?array  $props;        // HTML 属�?� + Vue 指令（@click, :bind, v-for, :scroll-top 等）
mixed   $children;     // VNode[] | VNode | string | null
?string $key;          // v-for key

// —�?� 布局结果（由 LayoutResolver 塀��）�?��?�
int $x, $y, $w, $h;           // 绝�坐标
array $computedStyle;         // 合并后的 CSS 属�?�
int  $layer;                  // z-order
string $groupId = 'app';      // 事件跀�� key

// —�?� 滚动容器 —�?�
bool $isScrollContainer;
int  $scrollTop;               // 垂直滚动偏移 (px)
int  $contentHeight;           // 叀��动内容�?�高庀 (px)
int  $scrollLeft;              // 水平滚动偏移 (px)
int  $contentWidth;            // 叀��动内容�?��庀 (px)

// —�?� 组件占位 —�?�
bool $isComponent;
?string $componentClass;
?ReactiveComponent $componentInstance;
?array $componentProps;       // 子组件属性映尀
```

**工厂方法**＀
```php
VNode::h('div', ['style'=>'width:100px;height:50px'], [$child])
VNode::hKey('div', [...], $children, 'item-1')       // 帀 v-for key
VNode::hComponent('MyComponent', [...props], [...bindings])  // 子组件占佀
```

**常用辅助方法**：`getProp(name, default)`, `getClass()`, `getInlineStyle()`, `isRoot()`, `isComponent()`

### 4.2 ReactiveComponent（framework/ReactiveComponent.php＀

**关键状�?�**＀
```php
bool $dirty;            // true ↀ 下� getVNodeTree() 会重斀 render()
?VNode $vnodeCache;     // 缓存的上次渲染结枀
bool $isMounted;        // mount() 之后一 true
```

**核心流程**＀
```php
// 状�?�变曀 ↀ 触发重渲染的标准方式
$this->markDirty();
// 等价于：
//   $this->vnodeCache = null;
//   $this->scheduleUpdate();  // 技 performUpdate() 加入往��务队刀

// 在微任务一 ↀ performUpdate() ↀ renderCallback() ↀ Application::requestRender()
// 在事件循玀��下一一 tick ↀ Application::render() ↀ getVNodeTree() ↀ $this->render()
```

**必须实现的抽象方泀**＀
```php
abstract public function render(): VNode;                       // 返回 VNode 栀
abstract public function setBindValue(string $key, string $val);  // 写入绑定倀
abstract public function getBindValue(string $key): string;      // 读取绑定倀
```

**子→父�?�信**＀
```php
// 子组什
$this->emit('itemSelected', ['id' => 5]);
// 父组什
$this->on($child, 'itemSelected', function($payload) { ... });
```

### 4.3 Application（framework/Core/Application.php＀

**重�方法速查**＀
```php
// 入口
Application::create()->mount($root)->run();

// 组件注册
registerComponent(string $groupId, ReactiveComponent $comp)

// 命中测试
hitTest(int $x, int $y, VNode $node): ?VNode   // 返回�?上层叀��净 VNode

// 滚动系统
findScrollContainerAt(int $x, int $y, VNode $node): ?VNode
hitTestScrollbar(int $x, int $y, VNode $node): ?array
applyScrollTop(VNode $node, int $value, bool $persist): void
directRender(VNode $tree): void    // 跳过树重建，仅重斀 layout + render

// 配置管理
Config::init(string $appDir)    // 初�化配罀
Config::get(string $key, mixed $default): mixed    // 读取配置
```

### 4.4 Config（framework/Core/Config.php＀

**关键使用**＀
- 静�?�类，由 `Application::mount()` 初�化，读取 `{APP_DIR}/px_debug.yml`
- 核心方法：P`init(string $appDir)`、g`get(string $key, mixed $default)`、g`getAppDir()`、g`getOutputDir()`
- AOT 兼�：u`use native_types`，静怀 `$cache`/`$appDir`
- 应用示例：a`pps/bilibili/px_debug.yml`

### 4.5 RenderTreeManager（framework/Rendering/RenderTreeManager.php＀

**职责**：渲染树管理，提侀 VNode 树的脏路径追�?�差异比较�?�缓存快照�理�?�在 `Application::render()` 一�吀 VNode 树的构建/重建/巀异更新节奏�?�

---

## 五�?�事件系绀

### 5.1 点击事件处理铀

```
鼠标按下 ↀ hitTest(x, y) 反序遍历子节炀
    ↀ �?柀 @click 属�?�
    ↀ resolveComponent(node) 通过 groupId 查找组件
    ↀ component->dispatchClick(handler, arg)
    ↀ 组件冀 match 分发
    ↀ 最��配的 handler ↀ parent::dispatchClick 冒泡
```

### 5.2 组件一��义事件�理器

圀 `.vue` 皀 `<script>` 一��义方法，SFC 编译器自动生成�应的 `dispatchClick`＀

```php
// App.vue <script>
public function deleteItem(string $id): void {
    unset($this->todoItems[$id]);
    $this->markDirty();  // SFC 编译器会臀��注入此�
}

// 编译器生成的 dispatchClick＀
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

### 5.3 销��事件

当前仅支持聚焀 input 元素皀 @keydown / @keyup / @enter　

---

## 兀?�滚动系绀

### 6.1 职责架构

```
滚动事件 ↀ Application::handleMouseEvent (跀��)
         ↀ ScrollManager (状�?��琀 + 逻辑)
              ├─ handleScrollWheel()      滚轮
              ├─ hitTestScrollbar()       命中测试（垂直条 + 水平条）
              ├─ handleScrollbarDown()    拖拽�?姀
              ├─ handleScrollbarDrag()    拖拽一
              ├─ handleMouseUp()          拖拽释放
              ├─ applyScrollTop()         垂直滚动
              └─ applyScrollLeft()        水平滚动
```

> 滚动状�?�（target、start 坐标、start scroll 位置、isHorizontal）全部在 ScrollManager 一?�
> Application 叀��责将事件跀��绀 ScrollManager，不再直接持有滚动状态�?�

### 6.2 使�器可滚动

圀 `.vue` 模板一��
```html
<!-- 仅垂直滚劀 -->
<div style="overflow-y:auto;left:10px;top:50px;width:380px;height:400px"
     :scroll-top="scrollTop">
  <template v-for="item in items" :key="item.id">
    <div @click="deleteItem(item.id)">{{ item.text }}</div>
  </template>
</div>

<!-- 樀��+纵向滚动（overflow:auto 同时吀��两轴＀ -->
<div style="overflow:auto;left:10px;top:50px;width:390px;height:570px"
     :scroll-top="scrollTop"
     :scroll-left="scrollLeft">
  <!-- 子元素�度超过�器�度时出现水平滚动杀 -->
  <div style="left:0;top:0;width:800px;height:36px">宽内宀</div>
</div>
```

组件一��
```php
public string $scrollTop = "0";   // 垂直滚动位置
public string $scrollLeft = "0";  // 水平滚动位置（仅樀��容器�?要）
```

**樀��滚动交互**：`Shift + 滚轮` 触发樀��滚动。水平滚动条位于容器底部 12px 区域　

### 6.3 滚动交互流程

```
滚轮 ↀ ScrollManager::handleScrollWheel (吀 Shift 销�浀 ↀ 樀��)
     ↀ applyScrollTop / applyScrollLeft (persist=true)
     ↀ setBindValue ↀ markDirty ↀ requestRender

轨道点击 ↀ ScrollManager::hitTestScrollbar (返回 {scrollNode, type, isHorizontal})
       ↀ handleScrollbarDown ↀ applyScroll*(jumped_value, persist=true)

滑块拖拽 ↀ hitTestScrollbar ↀ handleScrollbarDown(type='thumb')
       ↀ handleScrollbarDrag (高�) ↀ applyScroll*(persist=false) ↀ directRender
       ↀ 鼠标释放 ↀ applyScroll*(persist=true) ↀ requestRender
```

### 6.4 核心机制

1. **Bind 同�**：`resolveVNodeBindings` 在每欀 rebuild 时将组件 `scrollTop`/`scrollLeft` 值写兀 `VNode`
2. **布局偏移**：LayoutResolver 甀 `childOffsetY = node.y - scrollTop` 咀 `childOffsetX = node.x - scrollLeft` 定位子节炀
3. **臀�� clamp**：auto-stack 后若 `scrollTop > maxScroll` 戀 `scrollLeft > maxScrollX`，LayoutResolver 臀��俀�并重定位子节炀
4. **拖拽优化**：拖拽过程中赀 `directRender`，跳迀 VNode 树重廀
5. **樀��滚动�?浀**：`overflow-x:auto` / `overflow-x:scroll` 戀 `overflow:auto` 继承两轴

### 6.5 多滚动�器注意事顀

- 滚轮事件所**鼠标下方�?深的**滚动容器
- 滚动条拖拽一欀**叀��操作�?一**容器
- 拖拽状�?�由 ScrollManager 持有，拖拽过程中**不�**触发树重廀

---

## 七�?�AOT 编译约束

### 7.1 禁�皀 PHP 模式

| 模式 | 原因 |
|------|------|
| `$obj->$prop` 动�?�属怀 | AOT 无法静�?�推寀 |
| `$fn()` 非闭包调甀 | 字�串函数名不可编译 |
| `$obj->$method()` 动�?�方泀 | 同上 |
| 顶层 `require_once` / `include` | 必须在函敀/类内 |
| `eval()` / `create_function()` | 完全不可编译 |
| `compact()` / `extract()` | 动�?�变釀 |

### 7.2 必须遵守的模开

| 模式 | 说明 |
|------|------|
| `$x->toObject(ClassName::class)` | AOT 显式类型标注＀**必须使用** |
| `ComponentFactory::create($className)` | 允�字�串类名作为工厂参敀 |
| `match` 表达开 | 什 swoole_compiler 臀��皀 PHP 8.x 攀�� |

### 7.3 构建前�柀

```bash
# 使用 compiler 臀��皀 PHP 做�法�柀
D:\swoole_compiler\php.exe -l framework/Core/Application.php

# AOT 静�?��查（build.bat Step 0.5 臀��运�＀
D:\swoole_compiler\php.exe framework/aot-checker.php --project apps/list-test --skip direct_cpp_call
```

### 7.4 闀��使用限制

**闀�**：`v-for` 往��内使用闭包（如条什 class）时，AOT 编译会丢失闭包�部变量的作用域，导致 `$ch` 等循玀��量无法�闀?�

**错�示例**＀
```php
// ❀ 错�：AOT 一��包无法�闀 $ch
$children[] = VNode::h('div', [...], (function() {
    $c = [];
    $c[] = VNode::h('span', [..., 'bind'=>$ch['name']], $ch['name']);
    return $c;
})());
```

**正确做法**：不使用闀��，直接在往��一��廀 VNode＀
```php
// ✀ 正确：循玀��量直接在 foreach 一��甀
foreach ($this->items as $item) {
    $children[] = VNode::h('div', [...], $item['name']);
}
```

**条件渲染的替代方桀**＀
- 不使甀 `v-if` / `v-else`，改甀**两个狀��皀 `v-for`** 遍历不同数据満
- 在组件中提供分�的方法返回不同类型的数据

```php
// ✀ 圀 script 一��供分离的数据方法
public function getUserMessages(): array { /* 过滤 user 类型 */ }
public function getSystemMessages(): array { /* 过滤 system 类型 */ }

// ✀ 圀 template 一��立遍厀
<template v-for="msg in userMessages" :key="'u-' . msg.id">
  <!-- 用户消息 -->
</template>
<template v-for="msg in systemMessages" :key="'s-' . msg.id">
  <!-- 系统消息 -->
</template>
```

### 7.5 `use native_types` 下的 C2440 类型轀��错�

**根因**：文件声明了 `use native_types`（AOT 模式），但以下操作�终返囀 `php::Variant` 类型，赋值给已声明为 `php::Int` 的变釀/属�?�时，AOT 编译器无法隐式转捀��

| 操作 | 返回倀 | 触发条件 |
|------|--------|---------|
| `$arr['key']` 数组元素访问 | `php::Variant` | 赋给 `int` 属�?�或已类型化的局部变釀 |
| `$arr['key'] ?? default` 包含数组访问皀 ?? | `php::Variant` | 同上 |
| `max(...)` / `min(...)` | `php::Variant` | 同上 |

**错�信号**＀
```
D:\Px/build/...cc(error): error C2440: '=': cannot convert from 'php::Var' to 'php::Int'
```

**三�变体及修夀**＀

**变体 A   max/min 返回 Variant**
```php
// ❀ 错�：max() 返回 php::Variant，目标变量已类型化为 php::Int
$newScrollTop = max(0, min($max, $x));

// ✀ 正确：�层加 (int) 轀��
$newScrollTop = (int)max(0, min($max, $x));
```

**变体 B   兀 int 字面量初始化，后数组访问重新赋�?�**
```php
// ❀ 错�＀$borderColor 袀 =0 初�化为 php::Int
//           又� $style['borderColor'] ?? ... 赋�?�为 php::Variant
$borderColor = 0;
if (...) {
    $borderColor = $style['borderColor'] ?? ...;
}

// ✀ 正确：�层加 (int) 轀��
$borderColor = 0;
if (...) {
    $borderColor = (int)($style['borderColor'] ?? ...);
}
```

**变体 C   类属性声明为 `int`，从数组赋�?�**
```php
public int $primary;  // 声明一 php::Int

// ❀ 错�＀$colors['primary'] ?? 0x1976D2 返回 php::Variant
$this->primary = $colors['primary'] ?? 0x1976D2;

// ✀ 正确：�层加 (int) 轀��
$this->primary = (int)($colors['primary'] ?? 0x1976D2);
```

**全库所��**：已通过 Python 脚本对所最 11 一 `use native_types` 文件进�所��，确认无更�危险模式。涉及文件：`ScrollManager.php`(max/min)、`VNodeRenderer.php`(数组重新赋�?�)、`ColorScheme.php`(类属性数组赋倀)　

### 7.6 `use native_types` 下方法内数组属�?�赋值无敀

**根因**：文件声明了 `use native_types` 时，在方法（妀 `onMount()`、`initData()`）中对已声明一 `public array` / `private array` 的属性做 `$this->prop = [...]` 赋�?�，AOT 编译器生成的 C++ 代码**不会真�将数捀��入属怀**—�?�运行时该属性保持初始空倀 `[]`　

**错�信号**：没有编译错诀��但运行时属�?�数捀��空（`foreach` 迀�� 0 次）。常见于将数捀��始化放入类似 `initData()` 方法的�计模式�?�

**错�示例**＀
```php
// ❀ 错�：AOT 编译吀 $this->sidebarItems 保持空数绀
public array $sidebarItems = [];

public function onMount(): void {
    parent::onMount();
    $this->initData();
}

private function initData(): void {
    // 此赋值在 AOT 下无敀
    $this->sidebarItems = [
        ['id' => 's1', 'title' => '视�1'],
        ['id' => 's2', 'title' => '视�2'],
    ];
}
```

**正确做法**：数组数捀**必须在属性声明�内联初�匀**＀
```php
// ✀ 正确：在声明处直接赋倀
public array $sidebarItems = [
    ['id' => 's1', 'title' => '视�1'],
    ['id' => 's2', 'title' => '视�2'],
];
```

**影响范围**：`public array` 咀 `private array` 均受影响。`string` / `int` 类型属�?�的方法内赋值不受�限制　

**如何�?浀**：`aot-checker.php` 暂未覆盖此模式�?�可搜索 `use native_types` 文件一��最 `$this->xxx = [` 模式（方法内数组属�?�赋值）进�人工审核　

### 7.7 模板一 `$word` 前编表达式�?琀

Px 框架模板丽�甀 `$` 前缀时：
- v-for 往��变量（as `$item`、s$idx`）：保留一 `$word`（不叀
- 靀 v-for 变量（as `$sz`）：SFC 编译器自动转捀�� `$this->word`，即组件属�访问

这是因为 AOT 编译不存圀 PHP 的变量作用域概念，非 v-for 皀 `$` 变量必须映射到组件属性�?�涉及文件：`framework/compiler/expression/ConcatenationExpression.php`

---

## 兀?�构建流稀

### 8.1 命令

```bash
# 构建
build.bat list-test

# 构建并运血
build.bat list-test --run
```

### 8.2 各�骀

```
Step 0:   MSVC 玀� (vcvarsall.bat x64)
Step 0.5: AOT 静�?��柀 ↀ �?查�止模开
Step 1:   SFC 编译（编译根组件 App.vue，自劀 BFS 发现并编译所有子组件刀 gen/*.php＀
Step 2:   AOT 编译 (PHP ↀ C++ ↀ link ↀ .exe)
Step 3:   打包 (exe + php8ts.dll + phpx.dll + fonts/ ↀ bin/)
         build.bat 臀���?浀 cpp/fonts/*.ttf 存在时�?制到 bin/fonts/，确保渲染后竀/Skia 模式下字体随 exe 部署
```

### 8.3 常�失败

| 错� | 解决 |
|------|------|
| `cl.exe` 找不刀 | 什 Developer Command Prompt for VS 运� |
| `php8embed.lib` 找不刀 | 复制刀 `D:\swoole_compiler\` 根目彀 |
| AOT Checker 报错 | �?查代码是否使用了禁�模式 |
| Step 2 Swoole 编译器报销 | 先用手动 `php -l` �?柀 PHP 诀�� |
| 系统 `php -l` 报�法错 | 甀 `D:\swoole_compiler\php.exe` 而非系统 PATH 一�� PHP |
| 编译子组什 .vue 吀 gen/ 最��新到正确位置 | 必须编译根组什 App.vue，子组件不会袀��狀��译到 apps/<name>/gen/ |
| `C2440: cannot convert from 'php::Var' to 'php::Int'` | `use native_types` 文件一�� `int` 变量从数组�闀/max/min 赋�?�时，�层加 `(int)` 轀��（�觀 7.5＀
| Skia 字体�?��刀 | �?柀 bin/fonts/ �?��最 .ttf 文件，或手动复制 cpp/fonts/ 下的字体到应用程序目彀 |
| `Call to a member function toString() on string` | SFC 编译器生戀 `$this->prop->toString()`，但 PHP CLI 一 string 昀��生类型�?�重新运血 `php sfc-compiler.php` 重新编译，新版编译器生成 `(string)$this->prop` |

### 8.4 多机噀 vcvarsall 跀��配置

`build.bat` 皀 Step 0 �?要找刀 `vcvarsall.bat` 来初始化 MSVC 编译玀�。不同机器上 Visual Studio 安�跀��叀��不同（� VS 2017/2019/2022、Community/Professional/Enterprise），框架采用**三级优先级自动�浀**＀

| 优先纀 | 来源 | 说明 |
|--------|------|------|
| 1 | 当前 PATH | 如果 `cl.exe` 已在 PATH 一��如手动打�? VS Dev Cmd），直接跳过 vcvarsall |
| 2 | `config.yml` | 在项盀��盀�� `config.yml` 一��罀 `vcvarsall` 销��显式指定跀�� |
| 3 | 臀��搜索 | 递归搜索 `C:\Program Files\Microsoft Visual Studio\` 下所最 `vcvarsall.bat`，取笀��一 |

**配置示例**（`config.yml`）：

```yaml
# 家目录电脀 VS 2022 Community
vcvarsall: C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat

# 笔�最 VS 2019 Professional（注释掉不需要的行）
# vcvarsall: C:\Program Files\Microsoft Visual Studio\2019\Professional\VC\Auxiliary\Build\vcvarsall.bat
```

> **提示**：绝大�数情况下**无需配置**，自动搜索即叀�盀 VS 2017/2019/2022 的所有版最?�只有在臀��搜索失败或需要指定特定版最��才需要手动配罀?�

### 8.5 config.yml 配置文件

`config.yml` 昀��建系统的核心配置文件，必须位于项盀��盀��。�次使用时叀��模板复制＀

```bash
cp config.example.yml config.yml
```

**配置项�昀**＀

| 配置顀 | 说明 | 示例 |
|--------|------|------|
| `swoole_compiler` | Swoole Compiler 工具链目彀 | `F:\work\swoole_compiler` |
| `vcvarsall` | MSVC 玀�初�化脚最��叀?�） | `C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat` |

**配置示例**＀

```yaml
# Swoole Compiler 跀��（必�?＀
swoole_compiler: F:\work\swoole_compiler

# MSVC 跀��（可选，通常臀���?测即叀��
# vcvarsall: C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat
```

**常�闀�**＀

| 错�信息 | 原因 | 解决 |
|----------|------|------|
| `swoole_compiler path not found in config.yml` | config.yml 不存在或跀��错� | 什 `config.example.yml` 复制并修改路往 |
| `swoole_compiler directory not found` | 跀��指向的目录不存在 | �?查并俀� `swoole_compiler` 配置 |

---

## 九�?�常见开发任劀

### 9.1 新建应用

1. 圀 `apps/` 下创建目彀
2. 创建 `main.php`＀4 一��釀 + main()）：
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
3. 创建 `App.vue`（template + script + style＀
4. 创建 `project.yml`＀
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

### 9.2 添加帀 bind 的属怀

圀 `.vue` script 一��明属性：
```php
public string $myValue = "0";
```

在模板中使用＀
```html
<span :bind="myValue">{{ myValue }}</span>
<div :scroll-top="myValue" style="overflow:auto;...">
```

SFC 编译器会臀��一 `myValue` 生成 `getBindValue` / `setBindValue` 皀 case 分支　

### 9.3 添加点击事件

模板一��
```html
<button @click="handleAction" click-arg="someId">Click</button>
```

script 一��
```php
public function handleAction(string $id): void {
    // 俀��状�?�...
    $this->markDirty();  // 编译器自动注兀
}
```

### 9.4 使用 v-for

攀�� **Vue 3 风格**：`v-for` 叀��写在 `<template>` 或任愀 HTML 元素（`<div>`、`<span>` 等）上�?�

**`<template v-for>`**   仅重复子节点，不产生额�包�元素＀

```html
<template v-for="item in items" :key="item.id">
  <div @click="handleClick(item.id)">
    <span>{{ item.text }}</span>
  </div>
</template>
```

**元素 v-for**（Vue 3 风格＀   元素最��参与往��＀

```html
<div v-for="item in items" :key="item.id" @click="handleClick(item.id)">
  <span>{{ item.text }}</span>
</div>
```

两�写法均会袀��译器提取为独立的 render 辅助方法，`{{ item.text }}` 等循玀��量会袀�
础�理为�?部变量�?�非组件纀 bind key　

### 9.5 使用 v-if / v-else-if / v-else

攀�� Vue 3 风格的条件渲染链＀

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

**注意**＀
- `v-else-if` 咀 `v-else` 必须紧跟圀 `v-if` 之后，中间不能有其他非条件元紀
- 编译器使甀 `ExpressionParser` 解析条件表达式，攀��三元表达式�?�比较运算�?��?�辑运算

### 9.6 使用 :class 动�?�类绑定

攀��三元表达式动态绑宀 CSS 类：

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

通过 `visibility:hidden` 控制元素叀�性：

```html
<div v-show="isVisible" style="background:#2196F3">
  Toggle Me
</div>
```

编译为：
```php
['style' => ($this->isVisible) ? '...' : '...;visibility:hidden']
```

### 9.8 使用子组什

1. 创建子组什 `.vue` 文件
2. 在父组件模板一��甀��
```html
<my-component :my-prop="parentValue"></my-component>
```
3. SFC 编译器自动发现�?�编译�?�生成占佀 VNode
4. Application 在运行时展开

### 9.9 重新编译 SFC（修攀 .vue 后）

俀�� `.vue` 文件后，必须重新编译才能生效。关销�则：

- **编译根组什 App.vue**（�?�非子组件），编译器伀 BFS 发现�?有有变更的子组件并自动重新编诀
- 输出盀��甀 .vue 文件跀��决定：`dirname($vueFile) + '/gen/'`
  - 编译 `apps/<name>/App.vue` ↀ 输出刀 `apps/<name>/gen/`（�础��罀��
  - 编译 `apps/<name>/components/MyComp.vue` ↀ 输出刀 `apps/<name>/components/gen/`（错诀��罀��
- 命令：`php sfc-compiler.php apps/<name>/App.vue`
- **禁�手动编辑 `gen/*.php` 文件**（会袀��译器覆盖＀

### 9.10 调试�?巀

- **�?柀 VNode 栀**：在 `render()` 返回剀 `var_dump` VNode 结构（需在开发环墀 PHP 而非 AOT 一��行）
- **�?查布�?**：查眀 `LayoutResolver::resolve()` 返回皀 `scrollContainers` 列表
- **�?查渲染元紀**：在 `collectElements` 一��區 `$elementsByLayer`
- **formatted 输出**：在 `Application::render()` 一��甀 `var_dump` 输出 activeVNodeTree

### 9.11 在模板使甀 `$` 前编变量

圀 `.vue` 模板丼�`$word` 形式的变量（靀 v-for �?部变量）会�?编译器转一 `$this->word`，因此可以直接引用组件属性�?�

```html
<div>{{ $myValue }}</div>
<!-- 编译为：$this->myValue -->
```

**注意**＀`$` 前编圀 v-for 往��变量（� `$item`、s$idx`）中不能使用，编译器会�础�分　

### 9.12 AI 臀��戀��测试

在进血 UI 渲染测试时，叀��使用 PowerShell 脚本臀��戀��验证布局效果　

**戀��脚本模板**（保存到 `apps/<app-name>/test_screen.ps1`）：

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

**使用流程**＀

1. 俀�� `.vue` 文件测试布局
2. 运�构建＀
   ```bash
   cd f:/work/Px
   Remove-Item 'apps/<app-name>/gen/*.php' -Force  # 清理旧生成文什
   .\build.bat <app-name>
   ```
3. 运�戀��脚本＀
   ```bash
   powershell -ExecutionPolicy Bypass -File "f:/work/Px/apps/<app-name>/test_screen.ps1"
   ```
4. 查看 `screenshot.png` 验证渲染结果

**注意事项**＀

- 戀��前需础�� `gen/` 盀��袀��理，否则叀��使用旧代砀
- 每个应用盀��应只保留�?一 `.vue` 文件（按字母顺序编译＀
- 窗口定位使用 `EnumWindows` 匹配进程 PID，避免捕获错诀��叀

---

## 十�?�已知问题与设�债务

### 10.1 SOLID 违反：Application 持有 scrollDragTarget   ✀ 已解冀

> `ScrollManager` 服务已抽取（`framework/Core/ScrollManager.php`）�?�Application 仅负责事件路由，
> �?有滚动状态（drag target、drag start 坐标、drag start scroll 位置）和逻辑（滚轀?�拖拽�?�clamp＀
> 归属 ScrollManager。横向滚动状态同样由 ScrollManager 统一管理　

### 10.2 多滚动�器限刀

`scrollDragTarget` 昀��引用，同�?时刻叀��拖拽�?一��动条（鼠标操作天然�此，暂不影响使用）�?�但如果最��增加销��滚动，需要改为�噀 ID 索引皀 Map　

### 10.3 VNode 悀��引用风险

拖拽过程一�� VNode 树�重建（例如定时器触发 markDirty），`scrollDragTarget` 指向旧的对象。当前�?�过 `directRender` 避免重建，但长期�?改为 stable identifier　

### 10.4 Bind 值同步延迀

LayoutResolver clamp 后，组件皀 bind 值（妀 scrollTop）保持旧值�?�下�?欀 render 时先恢�旧�?��?�再袀 LayoutResolver 重新 clamp—�?�每帧一欀"错�→修歀"往��。需覀 `setBindValueSilent` 方法　

### 10.5 最��现的功能

- 销��滚动（PgUp/PgDn/Home/End/Arrow＀
- 编程式滚动到指定 item
- 窗口 resize 时的动�?�重布局（当前需要手动触发渲染）
- 文字输入旀 IME 攀��

### 10.6 近期已实现的功能＀2026-06-05~06-06＀

| 功能 | 描述 | Commit |
|------|--------|--------|
| `display: inline-flex` | LayoutResolver 新�? inline-flex �?�� | d734b3f |
| `border-radius` | CssMappings 新�? CSS 属�?�解析，渲染管道传�?� | d734b3f |
| `object-fit` | CssMappings 新�? CSS 属�?�解枀 | d734b3f |
| `img` 元素 CSS 标准 | VNodeRenderer 实现 box-shadow/border/alt 回推 | d734b3f |
| Grid `width: auto` | block-level grid 容器臀���?��包含址 | d734b3f |
| Flex `height: auto` | 臀��尺�?计算�?? | d734b3f |
| `shiftDescendantsY/X` | 子节点偏移翻倍bug �?? | d734b3f |
| Grid 臀��高度 | 从内容�?算格子自动高庀 | d734b3f |
| Config 配置管理籀 | px_debug.yml 解析，由 Application 初�?匀 | 3c93f78 |
| RenderTreeManager | 渲染树�?理，VNode 缓存/�?��追踪 | 3c93f78 |
| SFC 编译噀 `$` 前缀处理 | `$word`（非v-for＀ↀ `$this->word` | d7cd2bc |

---

## 十一、编码约宀

### 11.1 PHP 版本要求

- 源文件：PHP 8.0+（使甀 `match` 表达式）
- AOT 编译：swoole_compiler 内置 PHP 8.x
- **系统 PATH 一�� PHP 叀��昀 7.4，仅用于�?发调试，不能用于编译**

### 11.2 代码风格

- 使用 4 空格缩进
- 类属性使甀 `protected` 戀 `private`（AOT 友好＀
- `public` 属�?�用于组件状态（甀 SFC 编译器生成）
- 方法吀 camelCase
- VNode factory 统一使用 `VNode::h()` 咀 `VNode::hComponent()`

### 11.3 VNode 树�茀

- 每个组件皀 `render()` 返回什 `#root` 为根皀 VNode 栀
- `#root` 皀 style 设置 `width` 咀 `height`
- `#component` 昀��行时展开的占位节点，不产生渲柀
- `#text` 用于纀��最��炀
- children 叀��昀 `null`、`string`、`VNode`、`VNode[]`

---

## 十二、测诀

Px 框架使用**三层测试策略**＀
1. **单元测试**（PHP）�?� dispatchClick 模拟点击 + 组件树�义验诀
2. **状�?�快照测诀**（PHP）�?� 文字牀"戀��"，将组件状�?�序列化为可读文最
3. **戀��测试**（PowerShell）�?� 吀��真实 exe 抓取窗口戀��，用于�觉回彀

### 测试设�原则

| 原则 | 说明 |
|------|------|
| **不依赖�部服劀** | �?有测试在内存一��行，无文什/网络/数据库依赀 |
| **dispatchClick 驱动** | 模拟用户点击，直接调用组什 handler 方法 |
| **状�?�断�? + 忀��** | 既校验具体属性�?�，乀 dump 完整状�?�用于调诀 |
| **组件树�义�栀 Vue 3** | 测试 parent 链�?�事件冒泡�?�VNode 缓存、patchComponentTree |
| **AOT polyfill** | bootstrap.php 提供 `toObject()`、`any()` 筀 AOT 函数 polyfill |

### 运�测试

```bash
# 运�全部单元测试（推荐）
D:\swoole_compiler\php.exe tests/run_all_tests.php

# 运�单个测试文件
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

# 运�完整渲染管道测试（快照差异分析）
D:\swoole_compiler\php.exe tests/unit/RenderingPipelineTest.php

# 运�内存压力测试（�帧累秀�测）
D:\swoole_compiler\php.exe tests/unit/MemoryStressTest.php
```

### 测试文件

| 文件 | 覆盖范围 | 用例敀 |
|------|---------|--------|
| `CalculatorAppTest.php` | 计算器全郀 18 类操佀 + 状�?�快煀 + 边界情况 | 107 |
| `ComponentTreeTest.php` | 组件 parent 链�?�事件冒泡�?�实例独立�?�生命周期�?�VNode 缓存、hComponent 工厂、patchComponentTree、组件定位保畀 | 26 |
| `ReactiveComponentTest.php` | dirty 标�、VNode 缓存、组件更斀 | 9 |
| `HitTestTest.php` | 命中测试、事件路甀 | 10 |
| `LayoutResolverTest.php` | block/flex/grid/scroll 布局 + min/max/auto/美分毀/relative/flex-basis/shrink/order/align-self/inline-flex/grid-auto-width/shiftDescendants | 69 |
| `VNodeRendererTest.php` | 元素收集、layer 分组、clip（scroll + overflow:hidden）�?�button 边�渲染、render 完整流程 | 20 |
| `SfcCompilerPartsTest.php` | 编译噀 parts 元数捀��collectVNodeBindKeys 提取、generateVNodeExpr 代码生成 | 8 |
| `SfcCompilerVIfTest.php` | v-if 编译期优化（吀��绀��同条件合并） | 9 |
| `CssMappingsBorderTest.php` | border �?冀/狀��属�?�解析�?�parseInlineStyle/parseStyleBlock 边�处理、hexToBgr/borderColor 辅助函数 | 14 |
| `PlatformTest.php` | Platform 接口 SOLID/DIP 合� | 10 |
| `MemoryStressTest.php` | 内存增长�?测（9 模块 28+ 场景＀ | 28+ |
| `RenderingPipelineTest.php` | 完整渲染管道忀��巀��分析＀100 次循玀��净 + 5 类�则校骀 + 异常存档＀ | 5 |
| `ListTestPipelineTest.php` | list-test 渲染管道测试＀30 次点净 + 增长规则 + clip 有效怀 + 滚动拖动＀ | 8 |
| `GdiRenderContextTest.php` | GDI 渲染上下文直接测试（clip 栀 + drawText 戀�� + 参数守卫＀ | 15 |

### CalculatorAppTest 测试清单

覆盖以下 18 类场晀��107 一��试用例）＀

| # | 类别 | 用例敀 | 说明 |
|---|------|--------|------|
| 1 | Digit Input | 7 | 初�显示、数字输入�?�去除前导零、运算�后新输入 |
| 2 | Decimal Input | 6 | 小数点输入�?�防重�、运算�后新输入　15 位限制（2 一�� |
| 3 | Clear/Reset | 2 | C 清除输入、AC 完全重置 |
| 4 | Backspace | 4 | 删除最��、归零�?�newInput 保护、删除小数点 |
| 5 | Toggle Sign | 3 | 正负切换、零值保技 |
| 6 | Percentage | 2 | 50%ↀ0.5　200%ↀ2 |
| 7 | Basic Arithmetic | 6 | ±×÷、除以零 Error、空操作笀 |
| 8 | Operator Chaining | 3 | 链式计算、运算�覆盖、混合运简 |
| 9 | Scientific Functions | 14 | sin/cos/tan/log/ln/x²/x³/∀/inv/π/e + Error 分支 |
| 10 | Memory Functions | 6 | MS/MR/MC/M+/M∀/空�忀 |
| 11 | Parentheses | 4 | openParen/closeParen 显示 |
| 12 | History | 5 | 历史记录生成、切换面板�?�清除�?�加轀 |
| 13 | Error Recovery | 3 | Error 后数孀/C/= 恢� |
| 14-16 | Routing | 26 | ScientificPad/BasicPad/HistoryPanel 冒泡跀�� |
| 17 | State Snapshot | 3 | 视�化状态跟踀��完整会话、Error→恢复�?�括号表达式 |
| 18 | Edge Cases | 13 | 超大数字、运算�链�?�重复等号�?�带符号运算、连绀��除�?��轀��力测试等 |

### ComponentTreeTest 测试清单

覆盖 8 籀 Vue 3 组件诀��＀26 一��试用例）＀

| # | 类别 | 说明 |
|---|------|------|
| 1 | Parent Chain | setParent/getParent、addChild 双向绑定、�立组什 |
| 2 | Event Bubbling | dispatchClick 沀 parent 冒泡、stop 消费、null parent、dispatchKey |
| 3 | Instance Identity | 同类型不同实例�?�唯�? ID |
| 4 | Lifecycle | mount/unmount、重夀 mount |
| 5 | VNode Caching | 首� render()、缓存�用�?�dirty 重建、markDirty 清缓孀 |
| 6 | VNode Factory | hComponent 占位、componentProps 映射、groupId 递归 |
| 7 | Patch Component Tree | 晀?�节炀 groupId　#component 展开、实例�甀��吀 class+同位罀�� |
| 8 | Component Positioning | matchComponentNode 实例重用吀 transferComponentPositioning 保留定位 |

### 戀��测试

提供 PowerShell 脚本用于视�回归＀

```powershell
# 直接戀��（使用已最 exe＀
powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1

# 先构建再戀��
powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1 -BuildFirst $true
```

戀��保存圀 `tests/screenshot/output/<timestamp>/`，并臀��生成 HTML 报告　

### 测试�?佳实践（经验总结＀

1. **dispatchClick 昀�选测试方开**   直接调用组件 handler，不依赖布局坐标和渲染�道，速度忀?�结果确宀
2. **测试 helper 函数匀**   `createApp()`、`runCalculation()`、`assertDisplay()`、`captureState()` 筀 helper 提高叀�性和叀��护�?�
3. **避免过度模拟**   测试真实组件行为毀 mock 更有价�?��?�只在需要隔离时才用 test double
4. **状�?�快煀 vs 具体斀��**   关键跀��用具体断�?（`assertDisplay('42')`），调试用状态快照（`captureState()`＀
5. **Application 私有方法通过反射测试**   `newInstanceWithoutApp()` + `ReflectionMethod` 访问 private 方法
6. **先修复测试再提交**   失败的测试比没有测试更糟。每次修改后运�全部测试础��回归
7. **组件树测试验证�架�乀**   ComponentTreeTest 验证框架层面皀 Vue 3 诀��对齐，不依赖具体应用
8. **Mock 渲染上下文暴需 GDI 不可测漏洀**   `_MockRenderContext` 叀�彀 `drawElement()` 调用，不执�真实 GDI。Bug 发生圀 GDI 实现层（clip 边界绘制紀��损坏 HDC 状�?�），纯元素局 Mock 无法捕获。补偿策略：
   - Mock �?模拟 clip 栈追踀 + 文本戀��（`applyClipTruncation()` 一 `GdiRenderContext::drawText()` 逻辑�?致）
   - 流水线测试必须包吀 clip 溢出规则（Rule E：任何溢净 ≀1px 即告警）
   - GDI 层�为必须�?�过 `GdiRenderContextTest.php` 直接验证（stub GDI C++ 函数记录调用参数＀
9. **clip-aware drawText 昀��最 text 输出跀��的必选守區**   任何新�皀 GDI text 调用点都必须经过 `drawText()`（含 clip 戀��），禁�直接谀 `vue_draw_text()`
10. **新应用接入时必须添加对应的流水线测试**   至少包含：N 次循玀��击稳定�?�测诀 + A/B/C 规则（不叀/条件/约束＀ + clip 有效性�刀
11. **粗体文本字�宽度昀��规体皀 1.35 倀**   `drawText()` 戀��逻辑必须区分 `$bold` 参数。粗佀 36px 实际宽度 ~28px/char，�?� `fontSize * 0.6` 叀��净 21px/char。未区分粗体会�致截斀��仍然溢出
12. **测试必须覆盖完整的用户操作链**   仅测诀"�?直按 1"不�，必须包吀"大量操作 ↀ 清除/重置 ↀ 验证 UI 完整怀"的�到�场景。每一��管道测试都应包含 clear-after-corruption 验证
13. **按钮标�提取测试**   使用 `<button><span :bind="label">{{ label }}</span></button>` 模板时，`makeButtonElement()` 必须提取到标签�?��道测试中 `ltCheckButtonLabel()` 应断�? label 非空，不再标记为"known bug"
14. **滚动拖动测试必须验证 auto-stacked 位置**   仅测诀"添加 item 后布�?正确"不�。必须模拟滚动拖劀��直接设置 scrollTop + directRender），验证 auto-stacked items 皀 y 坐标保持严格递�不折叠�?�洁�?跀��一 `style` 无显开 `top` 的节点不应�重算 y

---

## 十三、修改�架代码时的�查清區

1. **PHP 诀��**：`D:\swoole_compiler\php.exe -l <file>`
2. **AOT 兼�**：无 `->$var`、无动�?�调甀
3. **布局职责**：LayoutResolver 管位罀��VNodeRenderer 管�切，互不越界
4. **负�高防往**：LayoutResolver 一��最 `$node->w`/`$node->h` 赋�?�用 `max(0, (int)$val)`
5. **GDI 调用保护**：GdiRenderContext 一��最 GDI 调用前�柀 `$w > 0 && $h > 0`
6. **drawText clip 戀��**：所最 text 绘制必须经过 `drawText()`（含 `clipStack` 追踪 + 粗体感知溢出戀��），禁�直接谀 `vue_draw_text()`。新墀 text 输出跀��时必须同步添加截斀?�辑。截斀��式：`charWidth = (int)(fontSize * 0.6 * ($bold ? 1.35 : 1.0))`，并保留 4px 安全余量
7. **clip 栈平血**：clip-push/clip-pop 必须成�出现，每帧结束时 clip 栈应为空。`GdiRenderContextTest` 一��最 `clip stack push and pop balanced` 测试
8. **Mock clip 追踪**：修攀 `_MockRenderContext`/`_LTMockRenderContext` 时必须同步添劀 clip 栈追踀 + `applyClipTruncation()`（含粗体因子咀 4px 安全余量），础�� mock 的可见�为接近真宀 GDI
9. **overflow:hidden 裁切**：需要�切子内�的�器必须�罀 `overflow:hidden`，VNodeRenderer 会为其生戀 clip-push/clip-pop
10. **数�?�输入限刀**：所有数值输入方法（inputDigit、inputDecimal 等）必须最 15 字�长度限制
11. **Bind 同�**：新墀 bind 属�?�后在组件中声明 `public string`，编译器臀��生成 get/set
12. **事件冒泡**：子组件 dispatchClick 皀 default 分支调用 `parent::dispatchClick`
13. **SFC 编译**：仅编译根组什 App.vue，不直接编译子组什 .vue；不手动编辑 gen/*.php
14. **构建验证**：`build.bat <app-name>` 全流程�?�过
15. **测试完整闀��**：新增�道测试必须�盖完整的用户操作链（不限于一直按同一按钮），包括：大量操作后 ↀ 清除/重置 ↀ 验证�?最 UI 元素完整的�到�场景
16. **按钮标�提取**：`makeButtonElement()` 必须遍历孀 RenderNode 提取标�（`<button><span :bind="x">{{ x }}</span></button>`），仅�柀 `node->content`(string) 咀 `props[':bind']` 不�，还要�查子节点皀 content 咀 bind 引用
17. **LayoutResolver 洁净跀��保留 auto-stack 位置**：洁�?跀��（`layoutDirty=false`）中，只有显开 `top`/`left` 定位的节点才重算 x/y。auto-stacked 子节点应保留脏路径�定的位置，仅由快速滚动路径（`shiftChildrenY`）平移�?�修攀 `resolveNode()` 一 `$node->x = ($style['left'] ?? 0) + $parentX` 这类无条件赋值时必须改用 `array_key_exists` 保护
18. **`$` 前编表达式意诀**：在 .vue 模板丽�甀 `$variable` 时确保�?变量在组件中有�?应的 `public` 属�?�声明，编译器会�?�� `$this->variable`
19. **LayoutResolver 洁净�?��保护**：修攀 `resolveNode()` 一 `$node->x = ($style['left'] ?? 0) + $parentX` 类代码时，必须使甀 `array_key_exists` 守卫仅�?有显开 `left`/`top` 的节点做绝�?赋�?�（参� `shiftDescendantsY/X` bug �??经验＀
20. **RenderTreeManager 集成**：新增渲染树管理类时�?同�?更新 `Application::render()` �?��染树构建/�?��更新�?��

---

## 十四、新墀 CSS 布局属�?�（LayoutResolver v2＀

以下 CSS 布局属�?�已圀 LayoutResolver 一��现支持：

### 显示模式
| 属�?� | 说明 | 默�倀 |
|------|------|--------|
| `inline-flex` | 内联弹簧盒布�? | block |

### 图像与替换元紀
| 属�?� | 说明 | 默�倀 |
|------|------|--------|
| `object-fit` | 替换元素内�?适配方式（fill/contain/cover/none＀ | fill |
| `img` 元素 | CSS 标准实现（cox-shadow/border/alt 回推＀ | - |

### 圆�?
| 属�?� | 说明 | 默�倀 |
|------|------|--------|
| `border-radius` | 圆�?矩形半径（px＀ | 0 |

### 尺�约束
| 属�?� | 说明 | 默�倀 |
|------|------|--------|
| `min-width` | �?小�庀 (px) | 0 |
| `max-width` | �?大�庀 (px) | 0 |
| `min-height` | �?小高庀 (px) | 0 |
| `max-height` | �?大高庀 (px) | 0 |

CSS 规范：当 `min > max` 时，`max` 袀��略�?�

### 百分比尺寀
| 属�?� | 说明 |
|------|------|
| `width: 50%` | 相�于父容器 content width |
| `height: 50%` | 相�于父容器 content height |

百分比在尺�解析**之后**、min/max 约束**之前**应用。百分比也在 flex 咀 grid 容器上生效�?�

### position:relative
- 圀 auto-stack 一��`position:relative` 的子节点**不�歀** auto-stack
- `top` 圀 auto-stacked 位置基�上做额�偏移，不影响兄弟节点定位
- `left` 通过 resolveBlockLayout 皀 relative 跀��正确处理

### Flex 扩展
| 属�?� | 说明 | 默�倀 |
|------|------|--------|
| `order` | 排列顺序（冒泡排序，稳定＀ | 0 |
| `flex-basis` | 初�主轴尺� (`auto` 回�??刀 `width`/`height`) | `auto` |
| `flex-shrink` | 收缩因子 | 1 |
| `align-self` | 单项交叉轴�齀 (`auto`/`flex-start`/`flex-end`/`center`/`stretch`) | `auto` |

### Flex-shrink 算法
```
overflow = totalMain - containerMain  (彀 overflow > 0)
totalShrinkWeight = Σ(item.mainSize × item.shrink)
item.mainSize -= overflow × (item.mainSize × item.shrink) / totalShrinkWeight
min-width/min-height 约束在收缩后应用
```

### Grid 扩展
| 属�?� | 说明 | 默�倀 |
|------|------|--------|
| `align-self` | 垂直方向对齐 (`stretch`/`center`/`start`/`end`) | `stretch`(auto) |
| `justify-self` | 水平方向对齐 (`stretch`/`center`/`start`/`end`) | `stretch`(auto) |

### 内联样式百分数��?浀
`CssMappings::parseInlineStyle()` 在解析时臀���?浀 `width`、`height`、`min-width`、`max-width`、`min-height`、`max-height` 的百分比值，存入 `*Percent` 销��妀 `widthPercent`），LayoutResolver 在父容器尺�已知时据此解析实际像素�?��?�

---

## ʮ�塢��Ⱦ����л���GDI / Skia��

Px ���֧��������Ⱦ��ˣ�**Ĭ�� GDI ��ع�**��ͨ�� `const APP_RENDERER` �л� Skia ·����

### 15.1 Ĭ����Ϊ

δ���� `APP_RENDERER` ����ʱ��`framework/Platform/Win32Platform.php` �� `GdiRenderContext`��368 �У�9 �� `vue_*` ԭ������� 7 ��Ӧ�ã�calculator-ng / design-guide / list-test / multi-scroll / aot-property-test / aot-syntax-test / video-platform��**ȫ������Ķ�**��

### 15.2 ���� Skia ģʽ

�� `apps/<app-name>/main.php` ����׷��һ�У�

```php
<?php
const APP_PLATFORM  = 'win32';
const APP_RENDERER  = 'skia';   // <-- ���������� Skia ·��
const WINDOW_WIDTH  = 400;
const WINDOW_HEIGHT = 300;
```

�����޸� `App.vue` / `components/*.vue` / `project.yml`��`Win32Platform::init()` �Զ����� `APP_RENDERER` ѡ�� `SkiaRenderContext` �� `GdiRenderContext`��

### 15.3 ��֤ Skia ·���Ѽ���

����Ӧ��ʱ�۲� stderr / ������־��Ӧ���֣�

```
PHP Notice:  SKIA PATH ACTIVE in framework/Rendering/SkiaRenderContext.php
```

���� R6 ���նԲߣ�"������������ʵ���� GDI" �����з���������δ���ִ� notice��˵�� `APP_RENDERER` ����δ���ݵ� `Win32Platform::init()`������ԭ��

- `APP_RENDERER` ƴд�������ִ�Сд��
- `main.php` δ�� SFC ��������������� `gen/` Ŀ¼��
- �ɰ� AOT EXE ���棨`build.bat <app>` ǿ���رࣩ

### 15.4 �л��� GDI

ɾ�� `const APP_RENDERER = 'skia';` �л��Ϊ `'gdi'`�����¹������ɡ����������κ� C++ ������

### 15.5 �׶ζ���

| �׶� | ״̬ | `sk_*` �ײ� | ���ó��� |
|------|------|-------------|----------|
| �׶�һ��POC�� | [OK] ����� | Win32 GDI���� vue_* ���룩 | ��֤ AOT ������· |
| �׶ζ���GDI ���ݲ㣩 | [OK] ����� | Win32 GDI������ 12 ·�� | calculator-ng ȫ UI ���� |
| �׶������� Skia�� | [OK] spike ͨ�� ?? with limitations | Skia `SkBitmap + SkCanvas` + `SkCanvas::drawRect` / `drawRRect` | ����� + Բ�� + ��ƽ̨���ı���Ĭ�������� DirectWrite�� |

> **�׶�������**���� aseprite m148 FCI ���Ƴ� �� �ı����ƾ�Ĭ������**�׶��ļ��� DirectWrite**���� MSVC 17.10+ STL helpers 8 �� `__std_*` �Ѽ� `_MSC_VER` �汾��������1939 �²��ṩ stub������ �� skia-poc �� `/MT` ��̬ CRT���� ����·������Ի���cpp/fonts/ + fonts/ ��·�����ˣ���build.bat Step 3 �Զ��������塣��� `docs/skia-render-context-guide.md` ��9��

### 15.6 �ؼ��ļ�

- `cpp/skia_render.cc`��~250 �У�C++ ԭ���㣩
- `stub/skia.stub.php`��21 �У�stub ������
- `framework/Rendering/SkiaRenderContext.php`��~250 �У�PHP �ࣩ
- `apps/skia-poc/`��POC Ӧ�ã�������ɫ���Σ�
- `framework/Platform/Win32Platform.php:30-37`������ע���֧��
- `framework/aot-checker.php:138-144`��`excludedFiles` �� `SkiaRenderContext.php`��
- `framework/Rendering/RenderContext.php`��`use native_types;`��
- `docs/skia-render-context-guide.md`��ʵʩָ�ϣ���ʵԴ�ĵ���

### 15.7 ��֪����

1. **������**��`g_skHwnd/g_skHdc/g_skSurface` ��ģ�龲̬�������ര���³�ͻ��Phase 6 ͨ�� `php::Box` �ع�
2. **�ı���Ĭ������Skia �׶������ƣ�**��aseprite m148 fork ���Ƴ� `SkFontMgr_New_FCI`��`skEnsureFont()` ���� `false` �߿�·������������/��ǩΪ�հס�**�׶��ļ��� `SkFontMgr_New_DirectWrite` ���� Segoe UI**������Ԫ�أ�����/Բ��/����/λͼ��������Ⱦ
3. **MSVC 17.10+ �ڲ� STL ���� stub��8 ����**��aseprite m148 Ԥ�������� `__std_min_element_f` / `__std_max_element_f` / `__std_minmax_element_f` / `__std_max_element_2` / `__std_max_element_1` / `__std_find_trivial_1` / `__std_find_trivial_8` / `__std_search_1`������ MSVC 14.x ���ṩ��`cpp/skia_render.cc` ���� `extern "C" { void __std_xxx() {} }` ռλ��**�Ѽ� _MSC_VER �汾������f55c347��**��MSVC �� 17.10��_MSC_VER �� 1939���� CRT ��������Щ���ţ�stubs �������Ա�������ض��塣
4. **��̬ CRT ǿ�� `/MT`**��Skia Ԥ������ `/MT`�������ԭ `/MD`��skia-poc cxx-flags �� `/MT` ���ǣ�`cl warning D9025`����������Ŀ��Ч
5. **GPU backend δ����**����ǰ���� CPU `SkBitmap + SkCanvas::MakeRasterDirectN32` + `SetDIBitsToDevice`��δ���� Direct3D 12 / Vulkan�������Ż����� Phase 4

### 15.8 �޸Ŀ�ܴ���ʱ�ļ���嵥����

�ڵ�ʮ����"�޸Ŀ�ܴ���ʱ�ļ���嵥"�����ϣ�������

18. **��Ⱦ����л�**������ `sk_*` ԭ������ʱ����ͬʱ���� stub��`stub/skia.stub.php`��+ PHP �ˣ�`framework/Rendering/SkiaRenderContext.php`��+ C++ �ˣ�`cpp/skia_render.cc`���������β��ϸ�һ�¡����� SkiaRenderContext ���󷽷�ʱͬ���� `framework/Rendering/RenderContext.php` �� abstract �������޸� `Win32Platform.php` �� `init()` ��֧ʱ���� `GdiRenderContext` ΪĬ�ϣ���ع�Լ����