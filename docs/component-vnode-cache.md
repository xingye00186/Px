# 计划：实现真正的组件化 VNode 树与子组件缓存

## Context

当前 SFC 编译器在编译时通过 `resolveComponentRefsRecursive()` (sfc-compiler.php:647) 将子组件的模板**内联展开**到父组件的 `render()` 中。该函数读取子组件 .vue → 解析 `<template>` → 通过 `remapChildBindProps()` (L791) 将子组件的 bind 表达式 remap 到父作用域 → 将子 VNode 直接插入父树。

结果：
- 只有 `rootComponent->getVNodeTree()` 被调用
- 子组件的 `render()` / `vnodeCache` 是死代码
- 任何状态变更都触发整树重建，即使只有一处文本需要更新

这违背了 Vue 3 / React / Flutter 的组件级缓存原则：每个组件独立渲染自己的子树，状态未变时复用缓存。

本次改造将子组件从"编译时内联"改为"运行时展开"，核心思路：
1. 编译器不再内联子组件模板，改为生成 **`VNode::hComponent()`** 占位节点
2. Application 在 `rebuildVNodeTree()` 中展开占位节点 —— 调子组件的 `getVNodeTree()`
3. 子组件的状态隔离：父组件 re-render 时，子组件若未 dirty 则直接返回缓存
4. Props 通过 `getBindValue/setBindValue` 在运行时传递（非编译时 `remapChildBindProps()`）
5. 事件通过 `dispatchClick`/`dispatchKey` 的 `default` 分支沿 parent 链冒泡

---

## 修改清单（共 6 个文件）

| 文件 | 变更类型 | 说明 |
|------|---------|------|
| `framework/Rendering/VNode.php` | 新增 | 加 `isComponent`、`hComponent()`、组件元数据字段 |
| `framework/compiler/sfc-compiler.php` | 重写部分函数 | `resolveComponentRefsRecursive` 生成占位 VNode，`generateVNodeExpr` 处理 `#component` |
| `framework/Core/Application.php` | 新增方法 | `expandComponentTree()` + 实例管理 |
| `framework/Rendering/VNodeRenderer.php` | 小幅修改 | 跳过 `#component` 节点、组件栈切换 bind 上下文 |
| `framework/ReactiveComponent.php` | 小幅修改 | `dispatchClick`/`dispatchKey` 默认冒泡到 parent |
| `framework/BaseComponent.php` | 小幅修改 | `dispatchClick`/`dispatchKey` 默认实现 |

---

## 步骤 1：VNode 扩展

**文件：** `framework/Rendering/VNode.php`

```php
// 新增属性
public bool $isComponent = false;
public ?string $componentClass = null;      // 'NumPadComponent'
public ?\Px\ReactiveComponent $componentInstance = null;  // 运行时的子组件实例
public ?array $componentProps = null;       // ['value' => 'display']  bind 映射

// 新增工厂方法
public static function hComponent(
    string $componentClass,
    ?array $props = null,
    ?array $componentProps = null
): VNode {
    $node = new VNode('#component', $props, null);
    $node->isComponent = true;
    $node->componentClass = $componentClass;
    $node->componentProps = $componentProps;
    return $node;
}

// 新增辅助方法
public function isComponent(): bool {
    return $this->type === '#component';
}
```

---

## 步骤 2：编译器改造

**文件：** `framework/compiler/sfc-compiler.php`

### 2a. `resolveComponentRefsRecursive()` — 不再内联

当前行为：读取子组件 `.vue` → 解析 `<template>` → 内联 VNode 到父树 → remap bind props。

新行为：
1. **保留** `<style>` 块解析 → 合并 CSS class 到 `$classStyles`（不变）
2. **不再** 解析子组件 `<template>`
3. **不再** 调用 `remapChildBindProps()`
4. **不再** 应用 offset 并插入子 VNode
5. **改为** 将当前 `$child` VNode 原地转为占位节点：
   - `$child->type = '#component'`
   - `$child->componentClass = componentTagToComponentName($tagName)`
   - `$child->componentProps = $bindProps`（从 `:propName` 中提取的映射）
   - 从 props 移除 `__componentFile`
   - 保留 `style`、`class`、`v-if` 等常规 prop
6. 将占位 VNode 本身加入 `$resolvedChildren`（不再展开其子节点）

### 2b. `generateVNodeExpr()` — 处理 `#component` 类型

新增分支（在函数顶部）：

```php
if ($node->isComponent) {
    $propsExpr = generatePropsExpr($node->props, $indent);
    $compPropsExpr = generateComponentPropsExpr($node->componentProps, $indent);
    return "VNode::hComponent('{$node->componentClass}', {$propsExpr}, {$compPropsExpr})";
}
```

`generateComponentPropsExpr()` 将 `['value' => 'display']` 生成为 PHP 数组字面量。

**重要**：`v-if` 的处理不变 —— 编译器已有的 closure-based builder 如果检测到 `v-if`，会将 VNode 包裹在 `if ($this->condition)` 中。占位 VNode 同样走这个路径，因此当 `v-if` 为 false 时占位 VNode 不会出现在树中，子组件也不会被展开。

### 2c. VNode 遍历辅助函数

以下函数递归进入子 VNode，现在遇到 `isComponent()` 应**跳过递归**（子组件内部内容编译时不可见）：

- `collectClickHandlers()` — 占位 VNode 自身无 @click handler，跳过子节点递归
- `collectVNodeBindKeys()` — **需要特殊处理**：遍历 `componentProps` 的 value 侧（即父组件的 bind key），将其加入 bind keys 列表。跳过子节点递归
- `collectVForLoops()` — 跳过
- `hasVForLoops()` — 跳过

### 2d. 子组件 `dispatchClick` 生成 — 默认冒泡

子组件编译时（`compileChildComponents` 中的 `generateDispatchClick`），保持生成 handler 特定的 `case` 分支，并在 `switch` 末尾的 `default` 改为冒泡：

```php
case 'reset':        $this->reset(); break;
case 'handleButton': $this->handleButton($arg); break;
case 'calculate':    $this->calculate(); break;
default:
    // 未识别的 handler → 沿 parent 链冒泡到父组件
    if ($this->parent !== null) {
        $this->parent->dispatchClick($handler, $arg);
    }
    break;
```

**重要约定**：子组件模板中 `@click` 引用的 handler 方法，必须定义在**该子组件自身的 `<script>` 块**中。编译器照常收集 handler → 生成 case → `$this->handler()` 调用。未匹配的 handler（例如子组件临时未实现的方法）通过 `default` 分支冒泡到父组件。

例如 NumPadComponent.vue：
```html
<template>
  <button @click="reset">C</button>
  <button @click="handleButton" click-arg="7">7</button>
</template>
<script>
  // reset、handleButton 等方法必须定义在这里
</script>
```

这样 NumPadComponent 的 `dispatchClick` 把所有已识别的 handler 直接分发给自身方法，其余未识别的沿 parent 链冒泡到 AppComponent。

### 2e. 子组件 `setBindValue` 生成 — 加 dirty 检查

```php
case 'value':
    if ($this->value !== $value) {
        $this->value = $value;
        $this->markDirty();  // ← 新增
    }
    break;
```

确保父组件传来的 props 变更自动触发子组件重渲染。

---

## 步骤 3：Application 运行时展开

**文件：** `framework/Core/Application.php`

### 3a. 实例管理

```php
/** @var array<string, ReactiveComponent> componentClass → instance */
private array $componentInstances = [];
```

### 3b. `expandComponentTree(VNode $node): void`

DFS 递归遍历，当遇到 `$node->isComponent()` 时：

```
1. 获取 / 创建实例（key = componentClass）
   - 不存在：ComponentFactory::create() → setScheduler → setRenderCallback 
     → setParent(rootComponent) → mount() → registerComponent(componentClass, instance) 
     → 存入 componentInstances
   - 已存在：直接复用

   (CSS class styles 无需单独加载 —— 编译器已在步骤 2a 将所有子组件样式合并到根组件的
    getClassStyles() 中，LayoutResolver 已从 initRenderer() 获取全部样式)

2. Props 传递
   for each [childKey => parentBindExpr] in node.componentProps:
       parentValue = $this->rootComponent->getBindValue(parentBindExpr)
       instance->setBindValue(childKey, parentValue)

3. 展开子树
   childRoot = instance->getVNodeTree()  // 利用子组件的 vnodeCache
   node.w = childRoot.w          // 子组件的根尺寸赋给占位节点
   node.h = childRoot.h
   node.componentInstance = instance
   node.children = childRoot     // 用子组件树替换占位节点的 children

4. 设置 groupId
   setGroupIdRecursive(node.children, componentClass)
   // 将所有子 VNode 的 groupId 设置为该组件类名（用于 hitTest 后路由到正确组件）

5. 递归
   expandComponentTree on 新 children（可能包含嵌套组件）
```

### 3c. 修改 `rebuildVNodeTree()`

```php
private function rebuildVNodeTree(): void {
    $this->activeVNodeTree = $this->rootComponent->getVNodeTree();
    $this->expandComponentTree($this->activeVNodeTree);
}
```

### 3d. `resolveComponent()` — 无需修改

已有 fallback：`return $this->componentByGroupId[$node->groupId] ?? $this->rootComponent;`

子组件在展开时注册到 `componentByGroupId`，hitTest 找到的 VNode 有子组件的 groupId，自然路由到子组件实例。子组件的 `dispatchClick` 通过 default 分支冒泡到 parent。

---

## 步骤 4：VNodeRenderer 组件边界

**文件：** `framework/Rendering/VNodeRenderer.php`

### 4a. 跳过 `#component` 节点

`collectElements()` 中已有 `if (!$node->isRoot())` 跳过根节点。扩展为：

```php
if (!$node->isRoot() && !$node->isComponent()) {
    $el = $this->vnodeToElement($node);
```

`#component` 自身不产生渲染元素（它只是一个边界标记）。

### 4b. 组件栈 — 切换 bind 上下文

当前 `$this->component` 始终指向根组件。`makeSpanElement()`、`makeButtonElement()`、`makeInputElement()` 中通过 `$this->component->getBindValue()` 解析 `:bind`。子组件的 VNode 需要用自己的实例来解析。

```php
// 新增属性
private array $componentStack = [];

// collectElements 中，进入/离开 isComponent 节点时
if ($node->isComponent && $node->componentInstance !== null) {
    $this->componentStack[] = $node->componentInstance;
}
// ... 递归子节点 ...
if ($node->isComponent && $node->componentInstance !== null) {
    array_pop($this->componentStack);
}

// 新增方法
private function currentComponent(): ReactiveComponent {
    $n = count($this->componentStack);
    return $n > 0 ? $this->componentStack[$n - 1] : $this->component;
}
```

然后将 `makeSpanElement` / `makeButtonElement` / `makeInputElement` 中所有 `$this->component` 替换为 `$this->currentComponent()`。

### 4c. `vnodeToElement` — `#component` 不应命中 switch

`#component` 节点已在 `collectElements` 中被跳过（步骤 4a），不会进入 `vnodeToElement`。但为安全起见，switch 的 `default` 分支已能处理未知类型（走 `makeDivElement` 返回 null）。

---

## 步骤 5：BaseComponent / ReactiveComponent — 冒泡基础

**文件：** `framework/BaseComponent.php`

添加默认 `dispatchClick` 和 `dispatchKey`（使 parent 链冒泡生效）：

```php
public function dispatchClick(string $handler, ?string $arg = null): void {
    if ($this->parent !== null) {
        $this->parent->dispatchClick($handler, $arg);
    }
}

public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void {
    if ($this->parent !== null) {
        $this->parent->dispatchKey($handler, $action, $keyCode, $char);
    }
}
```

**文件：** `framework/ReactiveComponent.php`

`dispatchClick` / `dispatchKey` 从 `abstract` 移除（继承自 BaseComponent 默认冒泡实现）。保持 `abstract` 的只有 `onMount`、`render`、`setBindValue`、`getBindValue`。

**文件：** `framework/compiler/sfc-compiler.php` 中 `generateDispatchClick` / `generateDispatchKey`

所有组件（根组件和子组件）统一生成模式：
- handler 特定的 `case` 分支 → `$this->handler()`（方法定义在各自 `<script>` 中）
- `default` 分支 → `parent::dispatchClick($handler, $arg)` 冒泡

根组件（AppComponent）由于 `$this->parent === null`，`default` 分支实际上不执行（`BaseComponent` 中 `parent !== null` 检查会跳过）。

---

## 步骤 6：LayoutResolver

**文件：** `framework/Rendering/LayoutResolver.php`

**无需修改**。`#component` 在 `resolveNode()` 的 display switch 中落入 `default` 分支 → `resolveBlockLayout()`，正确处理为带定位的 block 容器。其 `w/h` 已在 `expandComponentTree` 中从子组件根节点复制。

**`#root` 双重嵌套说明**：子组件的 `render()` 返回 `VNode::h('#root', ...)`。`expandComponentTree` 将该 `#root` 整树挂到占位节点的 `children` 上，形成 `#component → #root → 实际元素` 的结构。这无害：
- `LayoutResolver::resolveNode('#root')` 仅递归 `resolveChildren()`，不修改布局
- `VNodeRenderer::collectElements()` 已跳过 `isRoot()` 和 `isComponent()` 节点
- CSS class styles 仍通过编译器合并到根组件的 `getClassStyles()` 中（步骤 2a 保留 `<style>` 解析）

**CSS class styles 合并保持不变**：编译器在 `resolveComponentRefsRecursive()` 中仍然读取子组件 `<style>` 块并合并到 `$classStyles`（最终写入根组件 `getClassStyles()`）。子组件自身也有独立的 `getClassStyles()`（含自身样式），但在 Application 中 LayoutResolver 从根组件一次性获取所有样式即可。

---

## 步骤 7：清理子组件抽象方法

`ReactiveComponent` 中 `dispatchClick` 和 `dispatchKey` 从 `abstract` 变为继承自 `BaseComponent` 的默认冒泡实现。

---

## 步骤 8：重新生成 calculator 组件

运行编译器重新编译 `App.vue`，产出新的 `AppComponent.php`、`DisplayPanelComponent.php`、`NumPadComponent.php`、`AboutDialogComponent.php`。

预期变化：
- `AppComponent::render()` 中原来的内联 `display-panel` / `num-pad` / `about-dialog` 子节点变为 `VNode::hComponent(...)`
- `NumPadComponent` / `AboutDialogComponent` 的 `dispatchClick` 末尾添加 default 冒泡
- 子组件的 `setBindValue` 添加 dirty 检查

---

## 步骤 9：测试

### 现有测试兼容性
- `HitTestTest`：组件边界不影响命中测试（VNode tree 结构在展开后仍完整），无需修改
- `ReactiveComponentTest`：可新增子组件缓存测试

### 新增测试
- 子组件 VNode 缓存命中：父 re-render 但子 props 不变时，子 render() 不被调用
- 子组件 dirty 隔离：子 markDirty 不影响父 render() 调用次数
- 事件冒泡：子组件的未识别 handler 沿 parent 链冒泡到根组件

---

## 验证

1. `php -l` 语法检查所有修改文件
2. 运行编译器重新生成 calculator 组件，确认无编译错误
3. 运行 `ReactiveComponentTest.php` 确认基础功能不受影响
4. 运行 `HitTestTest.php` 确认命中测试不变
5. 手动 review 生成代码中样式/布局是否正确保留
