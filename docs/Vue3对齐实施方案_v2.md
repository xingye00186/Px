# Px 框架 Vue 3 对齐实施方案 v2

> 更新日期：2026-07-31
> 基于：代码库实际状态 + 历史任务经验 + AOT 约束

---

## 一、现状基线（事实，非推测）

### 已实现 ✅

| 特性 | 实现位置 | 验证状态 |
|---|---|---|
| #[Reactive] 属性 (Property Hooks) | `ReactiveComponent` + SFC 编译器生成 | AOT string 类型正常；**array 类型 !== 守卫在 AOT 下失效** |
| Effect 依赖追踪 | `Reactive/DependencyTracker` + `Effect` | 正常 |
| v-if / v-else / v-show | sfc-compiler.php | 正常 |
| v-for (含嵌套、keyed) | sfc-compiler.php | 正常 |
| v-model (基础绑定) | sfc-compiler.php `:bind`/`v-model` | 仅单向 bind，无 .lazy/.number/.trim |
| :style / :class 绑定 | sfc-compiler.php + StyleTransform | 正常 |
| @click / @keydown 事件 | sfc-compiler.php + Application.handlePointerEvent | 正常 |
| 组件 emit/on | ReactiveComponent emit()/on() | 正常 |
| 生命周期 | onMount / onBeforeUpdate / onUpdated / onUnmount | 正常(基类空实现,子类可覆盖) |
| Transform Pipeline | 6 个 Transform (Component/PatchFlag/StaticHoist/Style/Transition-T1) | 正常 |
| patchFlags + Block Tree | PatchFlagTransform + dynamicChildren | 正常 |
| 静态提升 | StaticHoistTransform | 正常 |
| CSS transition 自动触发 | B2: RenderTreeManager + CssAnimationParser | 正常 |
| @keyframes 编译注册 | B3: sfc-compiler + KeyframeResolver | 正常 |
| 几何属性动画 | E4: AnimationManager + LayoutOrchestrator | 正常 |
| `<Transition>` / `<TransitionGroup>` | B4: ComponentResolveTransform + 内建组件 | 编译正常；v-if 提取为 :show prop |
| 手势 (@drag/@longpress/@pinch) | M5.3: Application gesture state machine | 正常 |
| 动态组件 `<component :is="">` | ComponentResolveTransform `__dynamic__` 标记 | 部分(缺 resolveComponent) |

### 未实现 ❌

| 特性 | 优先级 | 阻塞方 | 备注 |
|---|---|---|---|
| **Slot 插槽** (默认/具名/作用域) | P0 | vc-ui 组件库 | 最大缺口 |
| **computed 计算属性** | P0 | 复杂组件 | 需编译器 + Reactive 扩展 |
| **watch / watchEffect** | P1 | 状态联动 | 需 Reactive 扩展 |
| **provide / inject** | P1 | 跨层通信 | 纯运行时 |
| **Router** | P1 | 多页面应用 | 编译器内建组件 + 运行时 |
| **Scoped CSS** | P2 | 大型应用 | 编译器注入 data-v- |
| **事件修饰符** (.stop/.prevent/.self) | P2 | 事件处理 | 编译器代码生成 |
| **Teleport** | P3 | 弹窗 | 渲染层多目标 |
| **KeepAlive** | P3 | 页面缓存 | RenderTreeManager 缓存 |
| **Suspense / 异步组件** | P3 | 按需加载 | 运行时加载器 |

### 已知缺陷（AOT 相关）

| 缺陷 | 根因 | 影响 | 解决方案 |
|---|---|---|---|
| Reactive array setter 不触发 notify | AOT 下 `!==` 对数组值比较行为异常 | array 状态变化不自动重渲染 | SFC 编译器对 array 类型 setter 跳过 !== 守卫 |
| 闭包限制 | AOT 禁止捕获外部变量的匿名函数 | watch/computed callback 需特殊处理 | 用方法引用替代闭包(已有模式) |
| 动态方法调用禁止 | `$obj->$method()` AOT 不支持 | 事件分发需 match 表达式 | 已解决(dispatchClick 用 match) |

---

## 二、架构设计原则

### 1. 编译期最大化、运行时最小化

Vue 3 的 `<script setup>` 语法糖在编译期完全展开。Px 同理：
- **slot** → 编译期将 `<template #name>` 收集为 VNode 子树数组，传入 hComponent
- **computed** → 编译期生成 Property Hook getter(带缓存标志)
- **watch** → 编译期生成 Effect 注册代码(在 onMount 中)

### 2. AOT 安全

所有生成代码必须满足 `aot-constraints.md` 约束：
- 无闭包捕获外部变量
- 无 `$obj->$prop` 动态属性
- 无 eval / 动态 require
- 数组索引赋值给 int 变量时强转 `(int)`

### 3. 非侵入/增量

每个新特性通过新 Transform 引入，不修改现有 Transform 的行为。`project.yml` 配置控制是否启用。

### 4. CLI ≡ AOT

所有功能在 CLI(`php main.php`)和 AOT exe 中行为一致。测试先在 CLI 验证逻辑，再 AOT Build 验证编译兼容。

---

## 三、迭代实施计划（6 个阶段）

### Phase S1: Slot 插槽系统（P0 — 组件库刚需）

#### 目标语法

```html
<!-- 定义 Card.vue -->
<template>
  <div class="card">
    <div class="header"><slot name="header" /></div>
    <div class="body"><slot /></div>
  </div>
</template>

<!-- 使用 -->
<Card>
  <template #header>
    <span>Title</span>
  </template>
  <p>Body content</p>
</Card>
```

#### 架构

```
编译期:
  TemplateParser → VNode{type='template', props:{#header:true}} → 子VNode树
  SlotTransform → 收集 #named 模板 → 编入 hComponent 的 $slots 参数
  CodeGenerator → VNode::hComponent('Card', $props, $binds, $slots)

运行时:
  ReactiveComponent::$slots : array<string, VNode[]>
  在 Card 的 render() 中: $this->slots['header'] ?? VNode::hComment()
  Application::expandComponentNode → 注入 slots 到子组件实例
```

#### 文件改动

| 文件 | 改动 |
|---|---|
| `framework/Dom/VNode.php` | 新增 `public ?array $slots = null` 字段 |
| `framework/Compiler/Transform/SlotTransform.php` | 新建：收集 `<template #name>` + `<slot>` 占位 |
| `framework/Compiler/sfc-compiler.php` | hComponent 调用增加 $slots 参数 |
| `framework/Component/ReactiveComponent.php` | 新增 `public array $slots = []` + 注入点 |
| `framework/Core/Application.php` | expandComponentNode 传递 slots |

#### 测试

- `tests/unit/SlotTest.php`: 默认 slot / 具名 slot / 回退内容 / 嵌套组件 slot
- 编译验证: `php sfc-compiler.php` 生成代码含 $slots
- AOT 验证: calculator-ng 或新 demo 使用 Card 组件 + slot

#### 退出标准

vc-ui 的 `Card.vue` 组件能通过 `<slot name="header"/>` 接收外部内容并正确渲染。

---

### Phase S2: Computed 计算属性（P0）

#### 目标语法

```php
<script lang="php">
    #[Reactive]
    public string $firstName = 'John';
    #[Reactive]
    public string $lastName = 'Doe';

    #[Computed]
    public string $fullName {
        get { return $this->firstName . ' ' . $this->lastName; }
    }
</script>
```

#### 架构

```
编译期:
  ScriptParser 识别 #[Computed] 属性 → 生成缓存 getter:
    public string $fullName {
        get {
            if (!$this->_px_computed_dirty['fullName']) {
                return $this->_px_computed_cache['fullName'];
            }
            DependencyTracker::track($this, 'fullName');
            $val = /* getter body */;
            $this->_px_computed_cache['fullName'] = $val;
            $this->_px_computed_dirty['fullName'] = false;
            return $val;
        }
    }

运行时:
  当依赖属性变化时 → notify → 标记 computed dirty
  下次读取时重新计算(惰性求值)
```

#### 关键约束

- **AOT 安全**: getter body 不是闭包,是方法内联代码
- **依赖追踪**: 首次执行 getter 时 runWithEffect 记录依赖
- **缓存失效**: 依赖属性 notify 时设 `_px_computed_dirty[key] = true`

#### 文件改动

| 文件 | 改动 |
|---|---|
| `framework/Compiler/sfc-compiler.php` | 识别 `#[Computed]` 注解,生成缓存 getter |
| `framework/Reactive/ComputedEffect.php` | 新建：专用 Effect 子类(惰性,无调度) |
| `framework/Component/ReactiveComponent.php` | 新增 `_px_computed_cache` / `_px_computed_dirty` |

#### 测试

- `tests/unit/ComputedTest.php`: 惰性求值 / 缓存命中 / 依赖变化重算 / 链式 computed

---

### Phase S3: Watch / WatchEffect（P1）

#### 目标语法

```php
<script lang="php">
    #[Reactive]
    public int $count = 0;

    #[Watch('count')]
    public function onCountChange(int $newVal, int $oldVal): void
    {
        // 副作用
    }
</script>
```

#### 架构

```
编译期:
  ScriptParser 识别 #[Watch('propName')] 注解
  → 在 onMount 代码中生成:
    $this->_px_watchers[] = ['prop' => 'count', 'handler' => 'onCountChange', 'old' => $this->count];

运行时:
  DependencyTracker::notify($this, 'count') → 检查 _px_watchers
  → 对匹配的 watcher 调用 handler(newVal, oldVal)
  → 更新 old 值
```

#### AOT 安全设计

- handler 是方法名字符串(非闭包)
- 通过 match 表达式分发(与 dispatchClick 同模式)
- oldVal 存储在 `_px_watcher_old` 数组中

#### 文件改动

| 文件 | 改动 |
|---|---|
| `framework/Compiler/sfc-compiler.php` | 识别 #[Watch] 注解,生成 onMount 注册 |
| `framework/Reactive/WatcherRegistry.php` | 新建：watcher 注册/触发 |
| `framework/Component/ReactiveComponent.php` | notify 时检查 watchers |

---

### Phase S4: Provide / Inject（P1）

#### 目标语法

```php
// 祖先组件
public function onMount(): void {
    $this->provide('theme', 'dark');
}

// 后代组件
#[Inject('theme')]
public string $theme = 'light'; // 默认值
```

#### 架构

```
运行时(纯运行时,无编译器改动):
  Application 维护 provide 树:
    $provideMap[componentId][key] = value

  inject 沿组件树向上查找:
    从当前组件的 parent → root 逐层查 provideMap

编译期(可选优化):
  #[Inject('key')] 注解 → 生成 onMount 中的注入代码
```

#### 文件改动

| 文件 | 改动 |
|---|---|
| `framework/Core/Application.php` | 新增 `$provideMap` + `provide()/inject()` |
| `framework/Component/ReactiveComponent.php` | 新增 `provide(key, val)` + `inject(key, default)` |
| `framework/Compiler/sfc-compiler.php` | 识别 #[Inject] 生成 onMount 注入(可选) |

---

### Phase S5: Router 路由系统（P1）

#### 目标语法

```php
// routes.php
return [
    '/' => HomeComponent::class,
    '/about' => AboutComponent::class,
    '/user/:id' => UserComponent::class,
];

// App.vue
<template>
  <router-link to="/">Home</router-link>
  <router-link to="/about">About</router-link>
  <router-view />
</template>
```

#### 架构

```
编译期:
  ComponentResolveTransform 已有内建组件识别机制
  → 新增 'router-view' → 'RouterViewComponent'
  → 新增 'router-link' → 'RouterLinkComponent'

运行时:
  framework/Router/Router.php — 路由注册 + URL 匹配 + 导航
  framework/Router/RouterViewComponent.php — 监听路由变化,渲染匹配页面
  framework/Router/RouterLinkComponent.php — 带 @click 的导航链接

事件流:
  @click → Router::push(url) → RouteMatcher → RouterViewComponent.dirty → render page
```

#### 文件改动

| 文件 | 改动 |
|---|---|
| `framework/Router/Router.php` | 新建 |
| `framework/Router/RouteMatcher.php` | 新建：URL 参数提取 |
| `framework/Router/RouterViewComponent.php` | 新建 |
| `framework/Router/RouterLinkComponent.php` | 新建 |
| `framework/Compiler/Transform/ComponentResolveTransform.php` | builtinMap 新增两项 |

---

### Phase S6: Scoped CSS + 事件修饰符（P2）

#### Scoped CSS

```html
<style scoped>
.title { color: red; }
</style>
```

编译为:
```css
.title[data-v-a1b2c3] { color: red; }
```

VNode 自动注入 `data-v-a1b2c3` 属性。

#### 事件修饰符

```html
<div @click.stop="handler">
```

编译为:
```php
'@click' => 'handler', '@click.modifiers' => ['stop' => true]
```

运行时 Application handlePointerEvent 读 modifiers 决定是否 stopPropagation。

---

## 四、Reactive Array 修复（紧急/前置）

### 问题

AOT 编译器(Swoole Compiler)下,Reactive setter 中 `$this->_px_react_storage['todoItems'] !== $value` 对 array 类型**始终返回 false**(认为"相同"),导致 notify 不触发。

### 修复方案

在 SFC 编译器生成 Reactive setter 时,对 **array** 和 **object** 类型跳过 !== 守卫：

```php
// 当前(string/int/float/bool):
set(string $value) {
    if ($this->_px_react_storage['display'] !== $value) {
        $this->_px_react_storage['display'] = $value;
        \Px\Reactive\DependencyTracker::notify($this, 'display');
    }
}

// array 类型修复:
set(array $value) {
    // AOT 下 array !== 不可靠,直接 notify(Vue 3 同策略)
    $this->_px_react_storage['todoItems'] = $value;
    \Px\Reactive\DependencyTracker::notify($this, 'todoItems');
}
```

### 定位

`sfc-compiler.php` 中生成 Property Hook setter 的代码段(搜索 `_px_react_storage.*!== $value`)。

---

## 五、测试体系

### 每个 Phase 的验证门

| 门 | 标准 |
|---|---|
| 单元测试 | 新特性单测 100% 通过 |
| 回归测试 | 336/336 css-standards 零回归 |
| 编译兼容 | `php sfc-compiler.php` AOT Validation PASSED |
| AOT Build | `build.bat <app>` 成功 |
| exe 验证 | exe alive + hwnd 有值 + autotest report 正常 |
| **新增** exe 手动验证 | 手动点击功能正常响应 |

### 测试文件规划

```
tests/unit/
  ├── SlotTest.php              — Phase S1
  ├── ComputedTest.php          — Phase S2
  ├── WatchTest.php             — Phase S3
  ├── ProvideInjectTest.php     — Phase S4
  ├── RouterTest.php            — Phase S5
  └── ScopedCssTest.php         — Phase S6
```

---

## 六、实施顺序与依赖关系

```
[前置] Reactive Array 修复 ─────────────────────────────────┐
                                                              │
Phase S1: Slot ──→ Phase S5: Router ──→ Phase S6: Scoped CSS  │
    │                                                         │
    ├──→ Phase S2: Computed ──→ Phase S3: Watch               │
    │                                                         │
    └──→ Phase S4: Provide/Inject ────────────────────────────┘
```

- **Reactive Array 修复**是所有后续工作的前置(否则 array 状态应用在 exe 中不可用)
- **Slot(S1)** 是组件库(vc-ui)的基础,阻塞 Router(S5,因为 RouterView 需要 slot)
- **Computed(S2)** 和 **Provide/Inject(S4)** 可并行
- **Watch(S3)** 依赖 Computed 的缓存失效机制
- **Router(S5)** 依赖 Slot(页面组件通过 slot 注入 RouterView)
- **Scoped CSS(S6)** 独立,可随时做

---

## 七、AOT 约束清单（每个 Phase 必读）

| # | 约束 | 影响 | 规避方式 |
|---|---|---|---|
| 1 | 禁止闭包捕获外部变量 | watch/computed callback 不能用匿名函数 | 用 `$this->methodName(...)` 方法引用 |
| 2 | 禁止 `$obj->$prop` | 动态属性访问 | 用 match 表达式或 _px_react_storage 数组 |
| 3 | 禁止 eval/dynamic require | 动态组件加载 | 编译期确定所有组件类(BFS 发现) |
| 4 | array 索引赋值给 int 必须 (int) 强转 | count() 返回值等 | `(int)count(...)` |
| 5 | `use native_types` 必须声明 | 被导入的类 | 框架所有类已有 |
| 6 | Property Hook array !== 不可靠 | Reactive array setter | 跳过守卫直接 notify |

---

## 八、编码规范(新增特性)

### Slot 命名

```html
<!-- 定义端:用 <slot name="xxx" /> -->
<slot name="header" />
<slot />  <!-- 默认 slot = "default" -->

<!-- 使用端:用 <template #xxx> -->
<template #header>...</template>
<template #default>...</template>  <!-- 或直接子内容 -->
```

### Computed 注解

```php
#[Computed]
public string $fullName { get { return ...; } }
// 不允许 set(Vue 3 computed 默认只读)
```

### Watch 注解

```php
#[Watch('propName')]
#[Watch('propA,propB')]  // 多属性
public function onXxxChange(mixed $new, mixed $old): void { }
```

### Provide/Inject

```php
// provide: 在 onMount 中调用
$this->provide('key', $value);

// inject: 注解声明
#[Inject('key')]
public string $injected = 'default';
```

---

## 九、风险与缓解

| 风险 | 概率 | 影响 | 缓解 |
|---|---|---|---|
| Slot 子树在 AOT 下内存泄漏(VNode 树长期持有) | 中 | 性能 | 每帧 slot VNode 重建(与当前 children 同策略) |
| Computed 循环依赖 | 低 | 死循环 | 编译期检测 + 运行时深度限制(maxDepth=10) |
| Router 状态与 Win32 窗口生命周期冲突 | 低 | 路由状态丢失 | Router 状态存 Application 级(非窗口级) |
| AOT 对 match 表达式分支数限制 | 中 | 大组件编译失败 | 分组策略(每 50 个 case 一个 match) |

---

## 十、验收标准(整体)

当以下条件全部满足时,Vue 3 对齐工作完成:

1. vc-ui 的 Card/Dialog/Tabs 等组件使用 slot 正常渲染
2. 至少一个 demo 应用使用 Router 实现多页面导航
3. computed 属性在 AOT exe 中正确缓存和失效
4. watch 在 AOT exe 中正确触发回调
5. provide/inject 跨 3 层组件树正确传递
6. 所有 demo 应用手动点击功能正常
7. 336/336 css-standards 零回归
8. AOT Build 全部成功
