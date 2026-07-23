
根据代码库和文档分析，SFC 编译器改进主要聚焦在以下几个方向：

---

## SFC 编译器改进方向

### 一、模块化拆分（架构债）

`sfc-compiler.php` 当前 **3042 行、50+ 全局函数**，是巨型过程式脚本。架构评审已标记为反模式：

| 问题 | 建议 |
|------|------|
| 单文件 3000+ 行，混合了解析/生成/验证/注册 | 拆分为独立模块：`TemplateCompiler`、`StyleCompiler`、`ScriptCompiler`、`CodeGenerator` |
| 全局函数满天飞，无命名空间 | 归入 `Px\Compiler` 命名空间 |
| `require_once` 手动加载 | 改为 Composer autoload PSR-4 |

### 二、Vue 3 特性缺失

| 特性 | 当前状态 | 依赖方 |
|------|---------|--------|
| **v-for 嵌套** | 已支持基本 v-for，嵌套场景近期修复过 | 列表组件 |
| **v-model** | 仅基础绑定，缺少 `.lazy`/`.number`/`.trim` 修饰符 | 表单组件 |
| **slot（插槽）** | 仅有默认 slot 文本提取，无具名/作用域插槽 | 库组件复用 |
| **computed（计算属性）** | ⚠️ 在运行时手动实现，无编译器生成支持 | 复杂组件 |
| **watch（侦听器）** | ❌ 未实现 | 状态联动 |
| **生命周期钩子** | ❌ `mounted`/`created`/`updated` 等未编译生成 | 组件生命周期 |
| **scoped style** | ❌ 样式无作用域隔离 | 大型应用 |
| **`:style` 绑定对象** | 仅 `:style="..."` 字符串，无对象语法 | 动态样式 |
| **事件修饰符** | `.prevent`/`.stop`/`.self` 等未实现 | 事件处理 |
| **动态组件 `<component :is="">`** | ❌ 未实现 | 页面路由 |

### 三、缓存和构建

| 改进 | 当前问题 | 收益 |
|------|---------|------|
| **缓存失效增强** | 只检查 `.vue` 文件 mtime，框架文件变更后缓存不失效 | 避免"改框架后必须手动清除缓存" |
| **Watch 模式** | 每次手动执行，无文件监听 | 开发体验提升 |
| **增量编译** | 全量重编译，大项目慢 | 编译速度 |

### 四、表达式解析器

当前支持三元/比较/逻辑/连接表达式，但缺少：
- 可选链 `?.` 操作符
- 展开运算符 `...`
- 数组/对象字面量
- 箭头函数表达式

---


远程已经完成了 Transform Pipeline 拆分（`7221e1e refactor(framework): 编译器模块化`）。以下是全盘分析和方案设计：

---

## 一、当前 Px 与 Vue 3 的差距总览

| 维度 | Vue 3 | Px 现状 | 编译器适配需求 |
|---|---|---|---|
| **根组件** | `App.vue` + `main.js` | `App.vue` + `main.php` | ✅ 一致 |
| **组件** | `<script setup>`, SFC | `lang="php"` script | ✅ 已有 |
| **子组件目录** | `components/` | `components/` | ✅ 一致 |
| **页面/视图** | `views/` | ❌ 无约定 | 需编译器新增 |
| **路由** | `vue-router` (`router-view`, `router-link`) | ❌ 无 | 需 `RouterViewComponent` + `RouterLinkComponent` 编译器内建组件 |
| **状态管理** | Pinia (`store/`) | ❌ 无 | 需 `provide/inject` 编译器支持 |
| **插槽** | `<slot>`, `v-slot` | ❌ 无 | **最大编译器缺口**——需模板解析器和代码生成改造 |
| **动态组件** | `<component :is="...">` | ❌ 部分 | 已有 `__dynamic__` 标记但未完整实现 |
| **Teleport** | `<Teleport to="...">` | ❌ 无 | 渲染层需支持多目标 |
| **KeepAlive** | `<KeepAlive>` | ❌ 无 | RenderTreeManager 缓存扩展 |
| **Suspense** | `<Suspense>` | ❌ 无 | 依赖异步组件 |
| **异步组件** | `defineAsyncComponent` | ❌ 无 | 加载器 + 骨架屏 |
| **Composables** | `composables/` | ❌ 无约定 | 代码组织约定，不需编译支持 |
| **Scoped CSS** | `<style scoped>` | ❌ 无 | 编译器属性选择器注入 |
| **CSS Modules** | `<style module>` | ❌ 无 | 编译器 `$style` 映射 |
| **HMR** | 热更新 | ❌ 无 | 运行时模块替换 |

---

## 二、目录结构对齐方案

### Vue 3 标准 vs Px 对齐

```
Vue 3 标准                          Px 对齐（apps/<app>/下）
───                                  ───
src/
  ├── main.js                         ├── main.php (已有)
  ├── App.vue                         ├── App.vue (已有)
  ├── components/                     ├── components/ (已有)
  │   ├── MyButton.vue                │   ├── MyButton.vue
  │   └── TheHeader.vue               │   └── TheHeader.vue
  ├── views/                          ├── views/ (新增)
  │   ├── Home.vue                    │   ├── Home.vue
  │   └── About.vue                   │   └── About.vue
  ├── router/                         ├── router/ (新增)
  │   └── index.js                    │   └── routes.php
  ├── store/                          ├── store/ (新增)
  │   └── index.js                    │   └── store.php
  ├── composables/                    ├── composables/ (新增)
  │   └── useCounter.js               │   └── useCounter.php
  ├── layouts/                        ├── layouts/ (新增)
  │   └── DefaultLayout.vue           │   └── DefaultLayout.vue
  ├── assets/                         ├── assets/ (已有)
  └── utils/                          └── utils/ (新增)
```

### project.yml 配置扩展

```yaml
# 当前（已有）
app_name: my-app
window_title: My App
window_size: [1280, 720]
Px_debug_*: ...

# 扩展（新增）
vue:
  router:
    base: /
    mode: history     # hash | history
  store:
    driver: px-state  # px-state | local
  pages: views/         # pages 目录名
  global_components: true  # 自动注册 components/ 下的组件
  scoped_css: true        # 启用 scoped CSS
```

---

## 三、需要编译器适配的关键功能

### 1. `router-view` / `router-link` — 内建组件

这是最高优先级，因为路由是所有非单页应用的基础。

**编译器需要**：

```php
// 模板: <router-link to="/about">About</router-link>
// 编译为:
VNode::hComponent('RouterLinkComponent',
    ['to' => '/about', 'style' => '...'],
    ['to' => 'to']      // bind 映射
);

// 模板: <router-view />
// 编译为:
VNode::hComponent('RouterViewComponent',
    ['style' => '...'],
    []
);
```

**运行时需要**：
- `RouterViewComponent` — 监听路由变化，渲染匹配的页面组件
- `RouterLinkComponent` — 带 `@click` 事件的路由导航
- `RouteMatcher` — URL 模式匹配（支持参数、嵌套）

**`main.php` 初始化**：
```php
use Px\Router\Router;

function main(): void {
    $router = Router::create([
        '/' => HomeComponent::class,
        '/about' => AboutComponent::class,
        '/user/:id' => UserDetailComponent::class,
    ]);

    $app = Application::create();
    $app->mount(new AppComponent($router));
    $app->run();
}
```

**Compiler 新 Transform**：`RouterTransform.php` — 在模板解析后，将 `router-view`/`router-link` 标签替换为对应的 `hComponent` 调用。这类似于 `ComponentResolveTransform` 的现有模式。

### 2. Slot（插槽）—— 最大缺口

**Vue 3 的 Slot 模型**：

```html
<!-- 定义 -->
<div class="card">
  <slot name="header" />
  <slot />
</div>

<!-- 使用 -->
<Card>
  <template #header>
    <h1>Title</h1>
  </template>
  <p>Body content</p>
</Card>
```

**Px 当前的组件模型**：`hComponent` 将子组件 VNode 树替换为组件自己的 `render()` 输出。没有 Slot 的概念。

**实施方案**：
- **VNode 扩展**：`VNode::$slots` 字段，存储具名插槽的 VNode 子树
- **编译器**：`<template #name>` → `$slots['name']`；`<slot name="header"/>` → 从 `$slots['header']` 读取
- **ReactiveComponent**：新增 `$slots` 属性，在 mount/update 时透传
- **Compiler Transform**：`SlotTransform.php` — 收集 `<template #name>` 和 `<slot>` 并转换

### 3. Provide/Inject — 状态管理基础设施

**Vue 3**：`provide(key, value)` → 子组件 `inject(key)` 沿组件树获取。

**Px 实现**：
- `Application` 扩展：`provide<T>(key, value)` / `inject<T>(key)`
- 使用 `SplObjectStorage` 按组件实例存储
- 编译器无需改造——纯运行时 API

Pinia 风格的 store 可以基于 provide/inject 实现，不作为编译器功能。

### 4. 动态组件 `<component :is="">`

当前已有 `__dynamic__` 标记（sfc-compiler 中的 `$isDynamic`），但需要完整实现 `resolveComponent` 方法。

**编译器**：已有 `hComponent('__dynamic__', props, ...)` 支持 ✅
**运行时**：`ReactiveComponent::resolveComponent(string $name)` 需要实现按注册名查找

### 5. Scoped CSS

**Vue 3**：`<style scoped>` → 编译器注入 `data-v-xxxxx` 属性选择器到所有 CSS 选择器 + 对应 HTML 元素。

**Px 实现**：
- 编译器生成唯一 scope ID（哈希 `App.vue` 路径）
- P 模板解析时给每个 VNode 添加 `props['data-v-xxxxx']`
- CSS 选择器（class 选择器）在解析时加 `[data-v-xxxxx]` 后缀
- **Compiler Transform**：`ScopedCssTransform.php` — 在 `mergeClassStylesIntoNode` 之后注入 scope ID

### 6. CSS Modules — 优先级最低

`<style module>` → `$style.red` 映射。编译器解析 class 名 → 生成唯一哈希映射。`VNode` 的 class 属性从映射表取值。可选功能，可以不做。

---

## 四、路由架构设计

```
@click on router-link
    ↓
RouterLinkComponent::navigate(to)
    ↓
Router::push(url)
    ↓
URL 更新 + RouteMatcher 匹配
    ↓
RouterViewComponent::dirty → setRouteComponent(pageComponentClass)
    ↓
pageComponent render() → 渲染页面 VNode 树
    ↓
正常渲染管线
```

**文件结构**：
```
framework/Router/
  ├── Router.php              — 路由注册 + 匹配 + 导航
  ├── RouteMatcher.php        — URL 模式匹配（/user/:id → ['id'=>42]）
  ├── RouterViewComponent.php — <router-view> 的组件代码
  └── RouterLinkComponent.php — <router-link> 的组件代码

apps/<app>/router/
  └── routes.php              — 应用路由表
```

---

## 五、实施路线（按优先级）

| 阶段 | 功能 | 编译器改动 | 运行时改动 | 预估 |
|---|---|---|---|---|
| **1** | `router-view`/`router-link` 内建组件 | `RouterTransform.php` + 注册标签名 | `Router.php`, `RouteMatcher`, 两个组件类 | 1-2 天 |
| **2** | Slot 插槽 | `SlotTransform.php` + VNode 扩展 | `ReactiveComponent` 插槽透传 | 2-3 天 |
| **3** | Provide/Inject + 状态管理 | 无 | `Application` 扩展 `provide/inject` | 0.5 天 |
| **4** | Scoped CSS | `ScopedCssTransform.php` | 无 | 1 天 |
| **5** | 动态组件完整实现 | 已有 | `resolveComponent` 方法实现 | 0.5 天 |
| **6** | Teleport | 标签变换 | 渲染层多目标支持 | 2 天 |
| **7** | KeepAlive | `KeepAliveTransform.php` | RenderTreeManager 缓存策略 | 1 天 |
| **8** | 异步组件 + Suspense | 加载器代码生成 | 异步加载器 + 骨架屏 | 2 天 |

**核心原则**：每一步都是可选、非侵入的。不引入新功能不会破坏现有应用。`project.yml` 的 `vue.*` 配置控制是否启用——零配置默认关闭，启用后才在 CompilerPipeline 中注册对应 Transform。