# Vue 3 SFC 对齐状态（2026-07-20）

## 一、Commit 链

| Commit | 层 | 功能 |
|---|---|---|
| `f4dd1a93` | 前置 | TemplateParser quote-aware（v-if 属性含 `>` 的场景） |
| `7863284c` | 前置 | 3 个 pre-existing bug 联动修复（registry / v-* / setBindValue） |
| `da07a7c7` | **L1** | `<script>` 缺失允许（template-only 组件合法化） |
| `a70cd945` | **L3** | v-if comment placeholder + 移除 IIFE unsafe hack |
| `1cc05a8f` | **L2a** | implicit props 推断（简单绑定：`{{}}` / `:bind` / `v-if` 单 identifier） |
| `e8fa3079` | **L2b** | 复杂表达式 identifier 扫描（`:style` / `:class` / 三目 / 拼接） |
| `6d341e78` | **L2b fix** | 递归 v-for/组件子树 + 3 bug fix（item 误注入 / globalTitle 漏收集 / 属性访问） |
| `8116db8b` | **L2c** | v-for source → array 类型推断（foreach 语义对齐） |
| `86a67925` | **L4** | `<template v-if/v-else-if/v-else>` 透明容器（不产出 DOM） |

---

## 二、已对齐 Vue 3 的核心行为

| Vue 3 行为 | Px 对齐方式 | 层 |
|---|---|---|
| template-only 组件（无 `<script>`）合法 | 删除 fatal error，静默通过 | L1 |
| 无 script 时 props 仍能传值 | implicit props 推断注入 Reactive hooks | L2a/L2b/L2c |
| v-if 假分支产出 `createCommentVNode` | `VNode::hComment()` + patchVNodeTree 早退 | L3 |
| v-for source 是 array 类型 | 类型推断 `array` + `[]` 默认值 | L2c |
| 事件处理器无定义时不崩溃 | dispatchClick/Key 默认冒泡到 parent | L1 |
| `item.label` 属性访问不是独立变量 | scanExpressionIdentifiers 跳过 `.` 后的 identifier | L2b fix |
| 循环变量不泄漏到 props | 作用域级 `$localLoopItems` 过滤 | L2b fix |
| 多根节点（Fragment） | TemplateParser 包裹 `#root`（等同 Vue 3 Fragment root） | 原有 |
| `<template v-if>` 不产出 DOM | 编译为 `VNode::hList([子节点], PATCH_STABLE_LIST)` | L4 |
| `<template v-for>` 不产出 DOM | VForHelperGenerator isTemplate 展开子节点 | 原有 |

---

## 三、Implicit Props 推断覆盖范围（L2 完整）

| 场景 | 数据源 | 层 |
|---|---|---|
| `{{ label }}` 文本插值 | `collectVNodeBindKeys` → `$bindKeys['label']` | L2a |
| `:bind="label"` / `:foo="bar"` 简单绑定 | `collectVNodeBindKeys` | L2a |
| `v-if="cond"` 单 identifier | `collectVNodeBindKeys` | L2a |
| `v-model="field"` | `collectVNodeBindKeys` | L2a |
| `:style="'bg:' + color"` 表达式内 identifier | `collectImplicitIdentifiersFromTemplate` | L2b |
| `:class="{active: isActive}"` 对象表达式内 identifier | `collectImplicitIdentifiersFromTemplate` | L2b |
| `:label="prefix + '.' + suffix"` 拼接表达式 | `collectImplicitIdentifiersFromTemplate` | L2b |
| `:cond="count > 0 ? label : 'none'"` 三目表达式 | `collectImplicitIdentifiersFromTemplate` | L2b |
| `v-if="count > limit"` 复杂表达式 | `collectImplicitIdentifiersFromTemplate` | L2b |
| v-for 子树内的 `{{ globalTitle }}` (bind 属性) | `collectImplicitIdentifiersFromTemplate` 递归 | L2b fix |
| v-for 子树内的 `:style` 表达式 | `collectImplicitIdentifiersFromTemplate` 递归 | L2b fix |
| v-for source（如 `items`） | 类型推断 `array` | L2c |

### 自动排除规则

| 排除项 | 机制 |
|---|---|
| v-for 循环变量（item / index） | `$localLoopItems` 作用域级过滤 |
| 属性访问（`item.label` 中的 `label`） | `scanExpressionIdentifiers` 检测 `.` 前缀 |
| PHP 关键字 / 字面量 | `$keywords` 静态表（true/false/null/if/else/array/string/...） |
| 引号内字符串字面量 | quote-aware scanner 跳过 `"..."` / `'...'` |
| 已在 `<script>` 声明的属性 | `!$hasScript` 前置条件（整个分支不进入） |

---

## 四、`scanExpressionIdentifiers` 核心算法

位于 `framework/Compiler/Helpers/CollectorHelper.php`。

**行为**：从表达式字符串中提取合法 PHP 标识符。

- Quote-aware：跳过 `"..."` 和 `'...'` 包围的字符串字面量（含 `\"` 转义）
- 属性访问排除：identifier 前一个字符是 `.` 时跳过（如 `item.label` 中 `label`）
- PHP 关键字过滤：`true` / `false` / `null` / `and` / `or` / `if` / `else` / `array` / `string` / `int` / `float` / `bool` / `this` / `self` / `new` / `function` / `fn` 等 40+ 关键字
- 返回去重后的 identifier 列表

**示例**：
```
scanExpressionIdentifiers("'bg:' + color") → ['color']
scanExpressionIdentifiers("prefix + '.' + suffix") → ['prefix', 'suffix']
scanExpressionIdentifiers("count > 0 ? label : 'none'") → ['count', 'label']
scanExpressionIdentifiers("item.label") → ['item']  // label 被属性访问排除
```

---

## 五、`collectImplicitIdentifiersFromTemplate` 递归策略

位于 `framework/Compiler/Helpers/CollectorHelper.php`。

### 递归行为

| 场景 | 行为 |
|---|---|
| v-for 子树 | **递归进入**。收集当前 item/index 到 `$localLoopItems`，传入子递归时过滤 |
| 组件占位子树 | **递归进入**。slot 内容属于父作用域，需扫描 |
| 所有普通子树 | 递归进入 |

### 与 `collectVNodeBindKeys` 的关系

两个 collector 各有职责，结果在 sfc-compiler.php 中**合并**：

```php
$allImplicitKeys = [];
foreach ($bindKeys as $k => $_) { $allImplicitKeys[$k] = true; }        // L2a 数据源
foreach ($complexIdentifiers as $k => $_) { $allImplicitKeys[$k] = true; } // L2b 数据源
```

- `collectVNodeBindKeys` 仍然跳过 v-for 子树（L74 `return`）——这是**正确设计**：
  - 有 script 的组件：v-for 体内变量由 VForHelperGenerator 在 codegen 时直接解析为 `$this->xxx`
  - 无 script 的组件：`collectImplicitIdentifiersFromTemplate` 已完整覆盖
- `collectVNodeBindKeys` 仍然跳过组件占位子树（L79 `return`）——componentProps 已单独处理

---

## 六、`<template v-if>` 编译语义（L4）

### Vue 3 用法
```vue
<template v-if="showDetails">
  <h1>{{ title }}</h1>
  <p>{{ description }}</p>
</template>
<template v-else>
  <span>Hidden</span>
</template>
```

### Px 编译产出
```php
if ($this->showDetails) {
    $c[] = VNode::hList([
        VNode::h('h1', [...], $this->title),
        VNode::h('p', [...], $this->description)
    ], VNode::PATCH_STABLE_LIST);
} else {
    $c[] = VNode::hList([VNode::h('span', [], 'Hidden')], VNode::PATCH_STABLE_LIST);
}
```

- `<template>` 不产出 DOM 元素
- 子节点通过 `#list` VNode 包裹（`childrenToArray` 自动展平）
- v-if/v-else 两侧各产出 1 个 VNode（长度稳定）
- 带 v-for 的 `<template>` 仍走 VForHelperGenerator 路径（不受影响）

---

## 七、剩余 Vue 3 差异（刻意不做 / 架构差异）

| Vue 3 特性 | 说明 | 原因 |
|---|---|---|
| `defineProps<T>()` 显式声明 | Px 用更激进的隐式推断 | **设计决策**：零 boilerplate UI 元件 |
| `$emit()` 语法糖 | Px 用 `emit()` + 父 `on()` | **架构差异** |
| `inheritAttrs` 属性透传 | Px 用 `setBindValue` 精确路由 | **架构差异** |
| `v-slot` / 具名插槽 | Px 当前不支持 slot | 超出 SFC 对齐范围 |
| CSS `v-bind()` | 不支持 | 超出范围 |
| `<Suspense>` / `<Teleport>` | 不支持 | 超出范围 |

---

## 八、Known Limitations

### `<template v-for="..." v-if="...">`（v-for + v-if 共存）

- **Vue 3 行为**：v-if 优先级高于 v-for（3.x 变更），先判条件再循环
- **Px 当前行为**：`collectVForLoops` 检查 v-for 存在就走 vForHelper 分支，**忽略 v-if**
- **风险**：极低 — Vue 3 官方 lint (`vue/no-use-v-if-with-v-for`) 直接禁止此写法
- **推荐做法**：拆成嵌套 `<template v-for><div v-if>` 或用 computed 过滤

### v-if/v-else 链后 sibling 被吸入 else 分支

- **问题**：v-else 处理后 `$inConditionalBlock=true`，紧跟的非条件 sibling 被放进 else 块内部
- **影响**：非 template v-if 专属问题，是 v-if codegen 的已有行为
- **状态**：已知，待独立修复（不影响 template v-if 核心功能）

---

## 九、验证 Fixture 清单

| Fixture 文件 | 验证目标 |
|---|---|
| `tests/unit/_fixtures/ImplicitPropsButton.vue` | L2b: `:style` 表达式内 `bgColor` / `textColor` |
| `tests/unit/_fixtures/VForOuterProp.vue` | L2b fix: v-for 子树内 `globalTitle` / `themeBg` / `items`(array) |
| `tests/unit/_fixtures/TemplateVIf.vue` | L4: `<template v-if>` 透明容器展开 |
