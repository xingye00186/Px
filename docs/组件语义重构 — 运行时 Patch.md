# 组件语义重构 — 运行时 Patch（对标 Vue 3）

## Context

当前 `expandComponentTree/expandComponentNode` 存在以下问题：

1. **parent 链断裂**：`expandComponentNode` 始终将 `$this->rootComponent` 设为父组件，导致事件冒泡链错误
2. **实例 ID 冲突**：`ComponentFactory::create()` 不传递唯一 ID，同类型组件实例 ID 相同，`componentByGroupId` 注册表被覆盖
3. **内存泄漏**：组件被移除后仍被 `componentByGroupId` 持有引用，无法 GC
4. **缺少缓存复用**：每次父组件 re-render 都重建全部子组件实例

需要引入**运行时 patch**，当新旧 `#component` 节点匹配时复用实例。

## 核心设计

- **父级匹配**：`patchComponentTree` 接收旧树节点参数，在父树的 `#component` 级别通过 `componentClass + key` 匹配新旧节点，复用或创建实例
- **子树信任**：匹配成功后，信任子组件自身的 `vnodeCache`，对子树递归执行 `patchComponentTree`：
  - `#component` 已有 `componentInstance`（缓存命中）→ 仅更新 parent、同步 props、重新注册
  - `#component` 无 `componentInstance`（子组件 dirty 产生新树）→ 从旧树对应节点匹配或创建
- **位置并行走访**：在父组件层面做位置对应的旧/新树遍历（父组件模板结构由 render 函数产出，key 匹配兜底）

## 修改文件

| 文件 | 改动 |
|------|------|
| `framework/BaseComponent.php` | 新增 `setId()` |
| `framework/Core/Application.php` | 运行时 patch + parent 链 + 唯一 ID + 泄漏修复 + 冗余删除 |

## 改动详情

### Step 1: `BaseComponent.php` — 新增 `setId()`

```php
// 第 21 行 getId() 之后
public function setId(string $id): void
{
    $this->id = $id;
}
```

AOT 安全，不添加 `ComponentInterface`。

### Step 2: `Application.php` — 唯一 ID 生成

在 `expandComponentNode` 中生成，使用计数器保证唯一性（AOT 安全）：

```php
// Application 新增属性
private int $nextComponentId = 1;

// 在 expandComponentNode 中：
$instanceId = $node->componentClass . '_' . $this->nextComponentId++;
$instance->setId($instanceId);
```

- 计数器方案：整数运算，AOT 完全兼容
- 生命周期内全局唯一
- ID 仅用于 `componentByGroupId` 注册表键和事件路由，无需关联 VNode 内存地址

### Step 3: `Application.php` — 新增属性 + `rebuildVNodeTree()` 重写

新增属性：
```php
private bool $isRendering = false;
```

`rebuildVNodeTree()` 重写：保存旧树 + 重入保护 + 传递旧树给 `patchComponentTree`。

```php
private function rebuildVNodeTree(): void
{
    if ($this->isRendering) {
        return; // 防止重入
    }
    $this->isRendering = true;

    // 保存旧树和旧注册表
    $oldTree = $this->activeVNodeTree;
    $oldRegistry = $this->componentByGroupId;

    // 清空注册表
    $this->componentByGroupId = [];
    $this->registerComponent('app', $this->rootComponent);

    // 渲染新树
    $this->activeVNodeTree = $this->rootComponent->getVNodeTree();

    // Patch：传递旧树用于匹配
    $this->patchComponentTree(
        $this->activeVNodeTree,
        $this->rootComponent,
        $oldTree
    );

    $this->resolveVNodeBindings($this->activeVNodeTree);

    // 卸载不再存在的旧实例
    foreach ($oldRegistry as $id => $instance) {
        if ($id !== 'app' && !isset($this->componentByGroupId[$id])) {
            $instance->unmount();
        }
    }

    $this->isRendering = false;
}
```

### Step 4: `Application.php` — `patchComponentTree()` 统一子树遍历 + 旧树匹配

核心函数。遍历新树，对 `#component` 节点做匹配复用或创建。接收可选的旧树节点进行匹配。

替换原有的 `expandComponentTree`。

```php
/**
 * 遍历子树，处理所有 #component 节点。
 *
 * @param VNode            $newNode  新树当前节点
 * @param ReactiveComponent $owner   当前子树的拥有者组件
 * @param VNode|null       $oldNode  旧树对应节点（用于匹配）
 */
private function patchComponentTree(
    VNode $newNode,
    ReactiveComponent $owner,
    ?VNode $oldNode = null
): void {
    // ── 非 #component 节点：确保 groupId 归属于正确的组件 ──
    if (!$newNode->isComponent()) {
        $newNode->groupId = $owner->getId();
    }

    // ── #component 节点：匹配或创建 ──
    if ($newNode->isComponent()) {
        $this->matchComponentNode($newNode, $owner, $oldNode);
        return; // 子树已在 matchComponentNode 中递归处理
    }

    // ── 普通节点：位置并行走访子节点 ──
    // 位置匹配在此处安全：父组件模板结构由 render() 产出，
    // componentClass + key 在 matchComponentNode 中兜底
    $oldChildren = $oldNode !== null
        ? $this->vnodeChildrenToArray($oldNode->children)
        : [];
    $newChildren = $this->vnodeChildrenToArray($newNode->children);

    $count = min(count($oldChildren), count($newChildren));
    for ($i = 0; $i < $count; $i++) {
        $this->patchComponentTree(
            $newChildren[$i],
            $owner,
            $oldChildren[$i]
        );
    }

    // 新树多出的子节点
    for ($i = $count; $i < count($newChildren); $i++) {
        $this->patchComponentTree($newChildren[$i], $owner, null);
    }
}

/**
 * 匹配单个 #component 节点。
 * 优先级：① 新节点已有实例（缓存）→ 复用
 *         ② 旧节点匹配（同 componentClass + 同 key）→ 转移实例
 *         ③ 均不满足 → 创建新实例
 */
private function matchComponentNode(
    VNode $newNode,
    ReactiveComponent $owner,
    ?VNode $oldNode = null
): void {
    $instance = null;

    // 优先级 1：新节点已有实例（来自父组件缓存树）
    if ($newNode->componentInstance !== null) {
        $instance = $newNode->componentInstance;
    }
    // 优先级 2：从旧树匹配（父组件 dirty，新树无实例）
    elseif ($oldNode !== null && $oldNode->isComponent()) {
        $sameClass = $oldNode->componentClass === $newNode->componentClass;
        $sameKey   = ($oldNode->key ?? '') === ($newNode->key ?? '');
        if ($sameClass && $sameKey) {
            $instance = $oldNode->componentInstance;
        }
    }

    if ($instance !== null) {
        // ── 复用实例 ──
        $instance->setParent($owner);

        // 同步 props
        if ($newNode->componentProps !== null) {
            foreach ($newNode->componentProps as $childKey => $parentExpr) {
                if (is_string($parentExpr) && substr($parentExpr, 0, 7) === 'static:') {
                    $instance->setBindValue($childKey, substr($parentExpr, 7));
                } else {
                    $instance->setBindValue($childKey, $owner->getBindValue($parentExpr));
                }
            }
        }

        // 获取子树（解析 dirty 状态）
        $newNode->componentInstance = $instance;
        $newNode->children = $instance->getVNodeTree();

        // 注册事件路由
        $this->setGroupIdRecursive($newNode->children, $instance->getId());
        $this->registerComponent($instance->getId(), $instance);

        // 递归处理子树的 #component 节点
        // 传入旧节点对应的子树作为匹配参考
        // #component 节点的 children 始终为 VNode（render 返回的 #root），
        // 因此直接取 children 作为 patchComponentTree 的 $oldNode
        $this->patchComponentTree(
            $newNode->children,
            $instance,
            $oldNode !== null ? $oldNode->children : null
        );
    } else {
        // ── 创建新实例 ──
        if ($oldNode !== null && $oldNode->componentInstance !== null) {
            $oldNode->componentInstance->unmount();
        }
        $this->expandComponentNode($newNode, $owner);
    }
}
```

### Step 5: `Application.php` — `expandComponentNode()` 接收 `$owner`

签名从 `private function expandComponentNode(VNode $node)` 改为：

```php
private function expandComponentNode(VNode $node, ReactiveComponent $owner): void
```

改动点：

| 行 | 当前 | 改为 |
|----|------|------|
| 296 | `$instance->setParent($this->rootComponent)` | `$instance->setParent($owner)` |
| 300-301 | `$instanceId = $instance->getId(); register...` | `$instanceId = $node->componentClass . '_' . $this->nextComponentId++; $instance->setId($instanceId); register...` |
| 304 | `if (... && $this->rootComponent !== null)` | `if (... && $owner !== null)` |
| 313 | `$this->rootComponent->getBindValue(...)` | `$owner->getBindValue(...)` |
| 339 | `$this->expandComponentTree($childRoot);` | `$this->patchComponentTree($node->children, $instance, null);` — 在 `expandComponentNode` 末尾自行递归处理新组件的子树，实现自包含 |

### Step 6: 辅助方法 `vnodeChildrenToArray`

```php
/**
 * 将 VNode 的 children 统一为数组，用于位置并行走访。
 */
private function vnodeChildrenToArray(mixed $children): array
{
    if ($children === null) return [];
    if ($children instanceof VNode) return [$children];
    if (is_array($children)) {
        return array_values(array_filter($children, fn($c) => $c instanceof VNode));
    }
    return [];
}
```

### Step 7: 删除 `render()` 中的重复展开

```php
private function render(): void
{
    $this->rebuildVNodeTree();
    // $this->expandComponentTree(...);  ← 第 406 行，删除
    $this->layoutResolver->resolve($this->activeVNodeTree);
    $this->renderer->render($this->activeVNodeTree);
}
```

### Step 8: 删除 `expandComponentTree` — 调用点审计

删除整个 `expandComponentTree` 方法（`Application.php` 第 249-277 行）。所有调用点处理如下：

| 行号 | 调用点 | 处理方式 |
|------|--------|---------|
| 241 | `rebuildVNodeTree()` 内 | 改为 `patchComponentTree(tree, root, oldTree)`（Step 3） |
| 256 | 内部调用 `expandComponentNode` | 随方法删除 |
| 260 | 内部递归 `expandComponentTree` | 随方法删除 |
| 268 | 内部调用 `expandComponentNode` | 随方法删除 |
| 273 | 内部递归 `expandComponentTree` | 随方法删除 |
| 339 | `expandComponentNode` 内展开子树 | 改为 `patchComponentTree($node->children, $instance, null)` — `expandComponentNode` 自包含递归 |
| 406 | `render()` 内冗余展开 | 直接删除（Step 7） |

`expandComponentNode` 的存根保持不变，但签名增加 `$owner` 参数（Step 5）。

其他文件（测试、文档、编译器注释）均无实际代码调用，仅 `framework/Core/Application.php` 一个文件涉及。

## 流程总结

```
rebuildVNodeTree()
  ├─ 保存 oldTree, oldRegistry
  ├─ 清空注册表，注册 root 为 'app'
  ├─ rootComponent->getVNodeTree() → activeVNodeTree
  ├─ patchComponentTree(newTree, root, oldTree)
  │    ├─ 非 #component → 设 groupId + 并行走访子节点
  │    ├─ #component 有 instance → 复用 + 递归
  │    ├─ #component 无 instance + 旧树匹配 → 转移实例 + 复用
  │    └─ #component 无 instance + 无匹配 → expandComponentNode
  ├─ resolveVNodeBindings()
  └─ oldRegistry 中未注册的 → unmount()
```

**匹配优先级**：① 新节点自身已有 `componentInstance`（缓存树）→ 直接复用
② 旧树对应节点 `componentClass + key` 匹配 → 转移实例
③ 均不满足 → `expandComponentNode` 创建

## 场景验证

| 场景 | patch 行为 |
|------|-----------|
| **首次渲染** | `oldTree = null` → 全部无匹配 → `expandComponentNode` 创建 |
| **父 dirty，子未 dirty** | 父 newTree 中 `#component` 无实例 → 旧树匹配(class+key) → 转移实例 → `getVNodeTree()` 返回缓存树 |
| **父 dirty，子 dirty** | 父 newTree 中 `#component` 无实例 → 旧树匹配 → 转移实例 → `getVNodeTree()` 返回新树 → 子树递归 |
| **v-if 添加** | 新树多出 `#component`（无旧树对应）→ 无匹配 → `expandComponentNode` 创建 |
| **v-if 移除** | 旧树 `#component` 无新树对应 → oldRegistry 清理 → unmount |
| **v-for key 不变** | 位置对应 + `componentClass + key` 匹配 → 实例复用 |
| **v-for key 变化** | `key` 不同 → `matchComponentNode` 匹配失败 → 旧 unmount，新创建 |
| **父未 dirty（缓存命中）** | `getVNodeTree()` 返回缓存树 → `#component` 已有 instance → 优先级 1 复用 |
| **子组件自身 dirty** | `matchComponentNode` 复用实例 → `getVNodeTree()` 解析 dirty → 子树递归 |
| **多层嵌套** 根→A→B | A 匹配后递归 `patchComponentTree(A->children, A, oldA->children)` → 继续匹配 B |

## 向后兼容性

calculator-ng 首次渲染：`oldTree = null` → 全部无匹配 → 全量创建 → 与当前行为一致。

## 验证方法

### 编译检查

```bash
# 1. PHP 语法检查
D:\swoole_compiler\php.exe -l framework/BaseComponent.php
D:\swoole_compiler\php.exe -l framework/Core/Application.php

# 2. 构建
Remove-Item 'apps/calculator-ng/gen/*.php' -Force
.\build.bat calculator-ng

# 3. 运行
.\build.bat calculator-ng --run
```

### 测试场景清单

| # | 场景 | 预期行为 | 验证方式 |
|---|------|---------|---------|
| 1 | **基础复用**：父组件状态变化（如计数器+1），子组件不 dirty | 子组件实例被 `matchComponentNode` 匹配复用，`getParent()` 正确 | `matchComponentNode` 复用分支加 `error_log`；观察 `instance->getId()` 不变 |
| 2 | **子组件 dirty**：子组件内部状态变化 | `matchComponentNode` 复用实例 → `getVNodeTree()` 返回新树 → `#component` 无 instance → 子树内 matchComponentNode 继续匹配或创建 | 触发操作，观察内部 `#component` 行为 |
| 3 | **v-if 从 false 变 true** | 新树多出 `#component`，无旧树对应 → `expandComponentNode` 创建新实例 | 切换开关，观察组件挂载 |
| 4 | **v-if 从 true 变 false** | `#component` 从树中消失 → oldRegistry 清理 → `unmount()` | 切换开关，观察 `onUnmount` |
| 5 | **v-for key 不变** | 位置对应 + `componentClass + key` 匹配 → 实例复用 | 操作触发重渲染，实例 ID 不变 |
| 6 | **v-for key 变化** | `key` 不匹配 → 新实例创建，旧实例 unmount | 观察旧 `onUnmount` 调用 |
| 7 | **多层嵌套**：根 → A → B，A 重新渲染但 B 未 dirty | A 匹配 → `getVNodeTree()` → B 在 A 的新树中有 instance → 复用；B->getParent() 为 A | 观察 parent 链正确性 |
| 8 | **内存泄漏**：反复切换 v-if | `componentByGroupId` 大小不增长；旧实例 `onUnmount` 被调用 | 切换前后比较注册表大小；查看 `onUnmount` 日志 |

### 调试日志建议

```php
// matchComponentNode 复用分支（优先级 1 缓存命中）
// error_log("[MATCH] Cache hit: {$className} id={$instance->getId()}");

// matchComponentNode 复用分支（优先级 2 旧树匹配）
// error_log("[MATCH] Old tree match: {$className} key={$key}");

// matchComponentNode 创建分支
// error_log("[MATCH] No match, create: {$className}");

// expandComponentNode
// error_log("[EXPAND] Create: {$className}");

// oldRegistry 清理
// error_log("[CLEANUP] Unmount stale: {$id}");
```
