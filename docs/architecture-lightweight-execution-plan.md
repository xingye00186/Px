# Px 框架"架构轻量化"重构执行方案

## Context

### 问题背景
当前 `VNode` 类混合了三种职责：
1. **组件占位**（`#component` 类型、`componentInstance` 字段）
2. **元素描述**（`type`、`props`、`children`、`key`）
3. **布局结果**（`x`、`y`、`w`、`h`、`scrollTop`、`contentHeight` 等）

这种混合导致：
- 每次渲染都需递归遍历 VNode 树并反复判断 `isComponent()`
- 布局结果直接附着在 VNode 上，破坏不可变性假设
- 难以实现增量更新（无法区分"描述"变化和"布局"变化）

### 重构目标
1. 引入独立的 `RenderNode` 类，将"可渲染元素"与"组件占位"分离
2. 布局结果仅存在于 `RenderNode`，`VNode` 保持只读描述
3. 支持脏标记机制（`layoutDirty`/`paintDirty`），实现增量更新
4. 保持对外 API 完全兼容（`.vue` 文件、`render()` 方法、`main.php` 不变）

### 用户决策
- **重构范围**：完整重构（所有阶段一次完成）
- **VNode 处理**：立即删除布局字段（不保留 @deprecated）
- **脏标记**：启用增量更新（LayoutResolver/VNodeRenderer 实际使用 dirty 标志）
- **测试策略**：每个步骤完成后运行单元测试，最终运行 `run_all_tests.php`

---

## 一、核心架构设计

### 重构前后对比

```
┌─────────────────────────────────────────────────────────────────────┐
│ 重构前：VNode 混合职责                                               │
├─────────────────────────────────────────────────────────────────────┤
│ VNode { type, props, children, key,                               │
│         isComponent, componentClass, componentInstance,           │
│         x, y, w, h, layer, computedStyle,                        │
│         isScrollContainer, scrollTop, scrollLeft, ... }           │
│                                                                     │
│ Application::render()                                               │
│   ├─ getVNodeTree() → rebuildVNodeTree()                          │
│   ├─ LayoutResolver::resolve() → 写入 VNode.x/y/w/h               │
│   └─ VNodeRenderer::render() → 遍历 VNode → GDI                   │
└─────────────────────────────────────────────────────────────────────┘

                              ↓

┌─────────────────────────────────────────────────────────────────────┐
│ 重构后：职责分离                                                     │
├─────────────────────────────────────────────────────────────────────┤
│ VNode（组件占位 + 元素描述，只读）                                    │
│   { type, props, children, key,                                   │
│     isComponent, componentClass, componentInstance, componentProps }│
│                                                                     │
│ RenderNode（布局结果 + 渲染数据）                                   │
│   { type, style, x, y, w, h, layer, content,                      │
│     isScrollContainer, scrollTop, scrollLeft,                      │
│     contentHeight, contentWidth,                                   │
│     layoutDirty, paintDirty, key, parent, sourceVNode, children }   │
│                                                                     │
│ Application::render()                                               │
│   ├─ getVNodeTree() → VNode 树                                     │
│   ├─ RenderTreeManager::updateFromVNode() → VNode → RenderNode     │
│   ├─ LayoutResolver::resolve() → 计算 RenderNode 坐标              │
│   └─ VNodeRenderer::render() → 遍历 RenderNode → GDI               │
└─────────────────────────────────────────────────────────────────────┘
```

### 数据流设计

```
Component.render() 返回 VNode 树
        ↓
Application.patchComponentTree() 展开子组件
        ↓
Application.resolveVNodeBindings() 同步 bind 值到 VNode
        ↓
RenderTreeManager.updateFromVNode() 将 VNode 转换为 RenderNode
        ↓
LayoutResolver.resolve(RenderNode) 计算 x/y/w/h，利用 layoutDirty 增量
        ↓
VNodeRenderer.render(RenderNode) 遍历树生成 GDI 调用，利用 paintDirty 增量
        ↓
GDI 绘制

滚动优化（directRender）：
ScrollManager 操作 RenderNode.scrollTop
→ 仅触发局部布局 + 直接重绘（跳过 VNode 树重建）
```

---

## 二、新增类定义

### 2.1 RenderNode 类

**文件**：`d:/Px/framework/Rendering/RenderNode.php`

**属性**：

```php
class RenderNode
{
    // ── 类型与内容 ──────────────────────────────────────
    public string $type;              // 'div', 'span', 'button', 'input', 'text'
    public array $style;              // 已解析的 GDI 可用样式
    public mixed $content;            // string | RenderNode[]
    public ?string $key = null;       // v-for key（用于复用匹配）

    // ── 布局结果（由 LayoutResolver 填入）────────────────
    public int $x = 0;
    public int $y = 0;
    public int $w = 0;
    public int $h = 0;
    public int $layer = 0;

    // ── 滚动容器专用字段 ─────────────────────────────────
    public bool $isScrollContainer = false;
    public int $scrollTop = 0;
    public int $scrollLeft = 0;
    public int $contentHeight = 0;
    public int $contentWidth = 0;
    public int $lastScrollTop = 0;    // 上次渲染时的 scrollTop，用于快速滚动路径比较

    // ── 脏标记（用于增量更新）──────────────────────────────
    public bool $layoutDirty = true;
    public int $lastPaintFrame = 0;   // 最后绘制帧号（0 = 未绘制）

    // ── 树关系 ──────────────────────────────────────────
    public ?RenderNode $parent = null;
    public ?VNode $sourceVNode = null; // 来源 VNode（用于 bind 值同步）
    public array $children = [];       // 子 RenderNode 数组

    // ── 组件关联（新增）──────────────────────────────────
    public ?string $groupId = null;   // 所属组件 ID（用于事件路由）
}
```

**新增 groupId 字段的作用**：
1. 从源 VNode.groupId 复制，用于事件路由快速查找
2. 当 sourceVNode 为 null 时（如文本节点），groupId 继承自父节点
3. 当 sourceVNode 存在时，groupId 等于 sourceVNode.groupId

**方法**：

| 方法 | 职责 |
|------|------|
| `__construct(string $type, array $style, mixed $content, ?string $key)` | 初始化基础字段 |
| `markLayoutDirty(bool $propagateUp = true): void` | 标记布局脏，$propagateUp=true 时向上传播 |
| `markSubtreeDirty(): void` | 标记子树为脏（不向上传播，用于滚动等场景） |
| `needsPaint(int $currentFrame): bool` | 判断是否需要绘制（layoutDirty=true 或 lastPaintFrame<currentFrame） |
| `markPainted(int $currentFrame): void` | 标记为已绘制（更新 lastPaintFrame） |
| `addChild(RenderNode $child): void` | 添加子节点，维护双向 parent 引用 |
| `clearChildren(): void` | 清空子节点数组 |

**AOT 注意事项**：
- `markLayoutDirty()` 递归使用循环实现，避免闭包
- 不使用 `compact()`/`extract()`/`eval()`

---

### 2.2 RenderTreeManager 类

**文件**：`d:/Px/framework/Rendering/RenderTreeManager.php`

**属性**：

```php
class RenderTreeManager
{
    private ?RenderNode $rootRenderNode = null;
    private array $vnodeToRenderNodeMap = [];     // spl_object_hash(VNode) => RenderNode
    private array $renderNodeToVNodeMap = [];      // spl_object_hash(RenderNode) => VNode
    private array $sourceVNodeMap = [];        // RenderNode => VNode（反向映射）
    private array $groupIdToRenderNodeMap = []; // groupId => RenderNode[]（组件关联映射）
}
```

**核心方法**：

| 方法 | 职责 |
|------|------|
| `updateFromVNode(VNode $vnode, ?RenderNode $parent, ReactiveComponent $root, array $componentByGroupId): RenderNode` | VNode → RenderNode 转换（跳过组件占位），复制 groupId，包含 bind 值同步 |
| `getRootRenderNode(): ?RenderNode` | 获取当前根 RenderNode |
| `setRootComponent(ReactiveComponent $comp): void` | 设置根组件引用 |
| `setComponentByGroupId(array $map): void` | 注入组件注册表 |
| `clear(): void` | 清空映射（仅在全量重置时调用） |
| `findRenderNodeBySourceVNode(VNode $vnode): ?RenderNode` | 根据 VNode 查找 RenderNode |
| `findRenderNodeByGroupId(string $groupId): array` | 根据 groupId 查找所有匹配的 RenderNode[]（一个 groupId 可对应多个节点） |
| `findFirstRenderNodeByGroupId(string $groupId): ?RenderNode` | 返回第一个匹配的 RenderNode（用于调试） |
| `hitTest(int $x, int $y): ?RenderNode` | 在 RenderNode 树中执行命中测试（public） |
| `findScrollContainerAt(int $x, int $y): ?RenderNode` | 查找鼠标位置下的滚动容器（public） |

**转换算法**（使用 spl_object_hash 映射，无需修改编译器）：

```
updateFromVNode(VNode $vnode, ?RenderNode $parent, ReactiveComponent $root, array $componentByGroupId):
    if $vnode.isComponent():
        // 跳过组件占位节点，递归处理子组件树
        return updateFromVNode($vnode.componentInstance.getVNodeTree(), $parent, $root, $componentByGroupId)

    if $vnode.type === '#root':
        // #root 节点不产生渲染元素，递归处理 children
        foreach $vnode.children as $child:
            $childRenderNode = updateFromVNode($child, null, $root, $componentByGroupId)
            if $childRenderNode !== null:
                $this->rootRenderNode = $childRenderNode
        return $this->rootRenderNode

    // 普通元素节点：使用 spl_object_hash 作为映射键
    $hash = spl_object_hash($vnode)
    if isset($this->vnodeToRenderNodeMap[$hash]):
        // 复用现有 RenderNode
        $renderNode = $this->vnodeToRenderNodeMap[$hash]

        // 检查结构是否变化（type 和 key）
        if ($renderNode->type !== $vnode->type || $renderNode->key !== $vnode->key) {
            // 结构变化，需要重建子节点
            $renderNode->type = $vnode->type;
            $renderNode->key = $vnode->key;
            $renderNode->clearChildren();
        }

        // 更新样式，标记为 dirty
        $renderNode->style = $vnode->computedStyle ?? [];
        $renderNode->layoutDirty = true;
        $renderNode->lastPaintFrame = 0;  // 标记需要重绘
    else:
        // 新建 RenderNode
        $renderNode = new RenderNode(
            $vnode->type,
            $vnode->computedStyle ?? [],
            null,
            $vnode->key
        )
        $renderNode->sourceVNode = $vnode
        $renderNode->groupId = $vnode->groupId
        $this->vnodeToRenderNodeMap[$hash] = $renderNode
        $this->renderNodeToVNodeMap[spl_object_hash($renderNode)] = $vnode

    // 同步 scrollTop/scrollLeft bind 值到 RenderNode
    $component = $componentByGroupId[$vnode->groupId] ?? $root
    $scrollBindKey = $vnode->props[':scroll-top'] ?? ''
    if $scrollBindKey !== '':
        $renderNode->scrollTop = (int) $component->getBindValue($scrollBindKey)
    $scrollLeftBindKey = $vnode->props[':scroll-left'] ?? ''
    if $scrollLeftBindKey !== '':
        $renderNode->scrollLeft = (int) $component->getBindValue($scrollLeftBindKey)

    // groupId 防御性检查
    if $renderNode->groupId === null:
        if $parent !== null && $parent->groupId !== null:
            $renderNode->groupId = $parent->groupId  // 防御性继承
            trigger_error('VNode groupId not set, inheriting from parent', E_USER_WARNING)
        else:
            $renderNode->groupId = 'app'  // 默认值

    // 注册到 groupId 映射
    if $renderNode->groupId !== null:
        $this->groupIdToRenderNodeMap[$renderNode->groupId][] = $renderNode

    $renderNode->parent = $parent
    if $parent !== null:
        $parent->children[] = $renderNode

    // 处理文本内容
    if is_string($vnode.children):
        $renderNode->content = $vnode.children
    else:
        foreach $vnode.children as $child:
            $childRenderNode = updateFromVNode($child, $renderNode, $root, $componentByGroupId)
        // 数组 children 已通过 addChild 添加到 children[]

    return $renderNode
```

**说明**：
- 使用 `spl_object_hash` 作为映射键，无需修改编译器
- 对象哈希在对象生命周期内稳定
- 复用依赖组件实例稳定（ReactiveComponent 实例不变）
- bind 值同步内嵌在 updateFromVNode 中，由 Application.render() 调用一次完成

---

## 三、修改现有类

### 3.1 LayoutResolver 修改

**文件**：`d:/Px/framework/Rendering/LayoutResolver.php`

**修改点**：

| 修改 | 说明 |
|------|------|
| `resolve(RenderNode $root): array` | 参数类型从 `VNode` 改为 `RenderNode` |
| `resolveNode(RenderNode $node, ...)` | 参数类型变更，直接使用 `$node->style` |
| dirty 检查逻辑 | `layoutDirty == false` 时跳过布局计算，仅传递父坐标 |
| 返回值 | `$scrollContainers` 类型改为 `RenderNode[]` |

**dirty 检查逻辑**（resolveNode，集成快速滚动路径）：

```php
private function resolveNode(RenderNode $node, int $parentX, int $parentY, ?RenderNode $parent): void
{
    // 1. 计算当前节点的布局
    if ($node->layoutDirty) {
        // ... 原有完整布局逻辑 ...

        // 布局完成后同步 lastScrollTop（用于下次快速滚动路径比较）
        if ($node->isScrollContainer) {
            $node->lastScrollTop = $node->scrollTop;
        }

        $node->layoutDirty = false;
    } else {
        // 节点未脏，但仍需设置坐标（父布局可能变化，需包含自身 margin）
        $marginLeft = $node->style['marginLeft'] ?? $node->style['margin'] ?? 0;
        $marginTop = $node->style['marginTop'] ?? $node->style['margin'] ?? 0;
        $node->x = ($node->style['left'] ?? 0) + $parentX + $marginLeft;
        $node->y = ($node->style['top'] ?? 0) + $parentY + $marginTop;

        // 快速滚动路径：仅滚动容器且 scrollTop 发生变化
        // 仅平移子节点，不改变滚动容器本身的 y
        if ($node->isScrollContainer && $node->scrollTop !== $node->lastScrollTop) {
            $deltaY = $node->lastScrollTop - $node->scrollTop;  // scrollTop 增大时 deltaY < 0，子节点上移
            foreach ($node->children as $child) {
                $this->shiftChildrenY($child, $deltaY, true);  // skipAbsolute=true 跳过绝对定位子节点
            }
            $node->lastScrollTop = $node->scrollTop;
        }
    }

    // 2. 计算子节点起始偏移（考虑 padding）
    $paddingLeft = $node->style['paddingLeft'] ?? 0;
    $paddingTop = $node->style['paddingTop'] ?? 0;
    $childOffsetX = $node->x + $paddingLeft;
    $childOffsetY = $node->y + $paddingTop;

    if ($node->isScrollContainer) {
        $childOffsetY -= $node->scrollTop;
        $childOffsetX -= $node->scrollLeft;
    }

    foreach ($node->children as $child) {
        $this->resolveNode($child, $childOffsetX, $childOffsetY, $node);
    }
}

private function shiftChildrenY(RenderNode $node, int $deltaY, bool $skipAbsolute = false): void
{
    $node->y += $deltaY;
    foreach ($node->children as $child) {
        // 跳过绝对定位子节点（它们相对于 padding box 定位，不随内容滚动）
        if ($skipAbsolute && ($child->style['position'] ?? '') === 'absolute') {
            continue;
        }
        $this->shiftChildrenY($child, $deltaY, $skipAbsolute);
    }
}
```

**关键改进**：
- 父节点未脏时，仍需递归检查子节点
- 对脏子节点执行完整布局（样式计算、坐标确定）
- 对干净子节点只传递坐标（不重复计算）
- 滚动容器脏时：完整布局后同步 `lastScrollTop`；未脏时：检查 scrollTop 变化走快速滚动路径
- 复用 `resolveNode` 自身逻辑，无需特殊分支

---

### 3.2 VNodeRenderer 修改

**文件**：`d:/Px/framework/Rendering/VNodeRenderer.php`

**修改点**：

| 修改 | 说明 |
|------|------|
| `render(RenderNode $root): void` | 参数类型从 `VNode` 改为 `RenderNode`，递增 $currentPaintFrame |
| `collectElements(RenderNode $node, ...)` | 参数类型变更，使用帧号机制判断是否需要绘制 |
| `renderNodeToElement(RenderNode $node): ?array` | 原 `vnodeToElement` 改名 |
| 增量绘制 | 使用 `needsPaint(currentFrame)` + `markPainted()` 而非布尔值 |

**paintDirty 检查逻辑**（collectElements，使用帧号机制）：

```php
private int $currentPaintFrame = 0;

public function render(RenderNode $root): void
{
    // 检测帧号溢出
    if ($this->currentPaintFrame === PHP_INT_MAX) {
        $this->currentPaintFrame = 1;
        $this->resetAllPaintFlags($root);
    } else {
        $this->currentPaintFrame++;
    }
    // ... collectElements ...
}

private function resetAllPaintFlags(RenderNode $node): void
{
    $node->lastPaintFrame = 0;
    $node->layoutDirty = true;
    foreach ($node->children as $child) {
        $this->resetAllPaintFlags($child);
    }
}

private function collectElements(RenderNode $node, array &$elementsByLayer, int &$maxLayer): void
{
    if (!$node->needsPaint($this->currentPaintFrame)) {
        // 跳过该节点，但处理子节点
        foreach ($node->children as $child) {
            $this->collectElements($child, $elementsByLayer, $maxLayer);
        }
        return;
    }

    // ... 原有逻辑 ...

    // 绘制完成后标记
    $node->markPainted($this->currentPaintFrame);
}
```

**增量绘制优势**：
- 无需遍历重置所有节点的 paintDirty
- `needsPaint()` 自动判断是否需要绘制
- 帧号溢出风险极低（int 范围 2^31）

---

### 3.3 Application 修改

**文件**：`d:/Px/framework/Core/Application.php`

**新增字段**：

```php
private RenderTreeManager $renderTreeManager;
```

**构造函数修改**：

```php
public function __construct(Platform $platform, Scheduler $scheduler)
{
    $this->platform = $platform;
    $this->scheduler = $scheduler;
    $this->layoutResolver = new LayoutResolver();
    $this->renderTreeManager = new RenderTreeManager();  // 新增
    $this->scrollManager = new ScrollManager(
        $this->requestRender(...),
        function () { $this->directRender(); },
        $this->resolveComponent(...),
        $this->renderTreeManager  // 新增：传递给 ScrollManager
    );
    $this->renderer = new VNodeRenderer(...);
}
```

**render() 方法修改**：

```php
private function render(): void
{
    $this->rebuildVNodeTree();  // VNode 树重建（不包含 bind 同步，bind 由 RenderTreeManager 处理）

    // VNode → RenderNode 转换 + bind 值同步（不清空映射，复用已有 RenderNode）
    $rootRenderNode = $this->renderTreeManager->updateFromVNode(
        $this->activeVNodeTree,
        null,
        $this->rootComponent,
        $this->componentByGroupId
    );

    // LayoutResolver 使用 RenderNode（利用 layoutDirty 增量）
    $this->layoutResolver->resolve($rootRenderNode);

    // VNodeRenderer 使用 RenderNode（利用 paintDirty 增量）
    $this->renderer->render($rootRenderNode);
}
```

**directRender() 方法修改**：

```php
public function directRender(): void
{
    $root = $this->renderTreeManager->getRootRenderNode();
    if ($root === null) return;

    $this->layoutResolver->resolve($root);
    $this->renderer->render($root);
}
```

**handleMouseEvent 修改**（hitTest 返回 RenderNode）：

```php
private function handleMouseEvent($event): void
{
    if ($event === null || $this->activeVNodeTree === null) {
        return;
    }

    // ── 鼠标滚轮：驱动滚动容器 ────────────
    if ($event->action === 'wheel') {
        // $root 参数目前未使用（通过 renderTreeManager 查询），保留参数兼容
        $this->scrollManager->handleScrollWheel($event, $this->renderTreeManager->getRootRenderNode());
        return;
    }

    // ── 鼠标拖动：滚动条拖拽 ──────────────
    if ($event->action === 'move') {
        $this->scrollManager->handleScrollbarDrag($event->x, $event->y);
        return;
    }

    // ── 鼠标释放：结束拖拽，持久化滚动位置 ──
    if ($event->action === 'up') {
        $this->scrollManager->handleMouseUp();
        return;
    }

    // ── 鼠标按下：优先检测滚动条，其次 @click ──
    if ($event->action === 'down') {
        // $root 参数目前未使用（通过 renderTreeManager 查询），保留参数兼容
        $sbResult = $this->scrollManager->hitTestScrollbar($event->x, $event->y, $this->renderTreeManager->getRootRenderNode());
        if ($sbResult !== null) {
            $this->scrollManager->handleScrollbarDown(
                $sbResult['scrollNode'],      // RenderNode
                $sbResult['type'],
                $event->x, $event->y,
                $sbResult['isHorizontal']
            );
            return;
        }

        // hitTest 直接返回 RenderNode，通过 sourceVNode 访问 props
        $renderNode = $this->renderTreeManager->hitTest($event->x, $event->y);
        if ($renderNode !== null) {
            $sourceVNode = $renderNode->sourceVNode;
            if ($sourceVNode !== null && isset($sourceVNode->props['@click'])) {
                $handler = $sourceVNode->props['@click'];
                $arg = $sourceVNode->props['click-arg'] ?? null;
                $target = $this->resolveComponent($sourceVNode);
                $target->dispatchClick($handler, $arg);
            }
        }
    }
}
```

**保留的方法**：
- `expandComponentNode()` — 保持不变（展开子组件时直接设置 componentInstance）
- `patchComponentTree()` — 保持不变（设置 groupId、匹配组件实例）
- `resolveVNodeBindings()` — **已删除**（bind 同步移至 RenderTreeManager.updateFromVNode）

**删除的方法**：
- `hitTest()` — 已移至 RenderTreeManager.hitTest()
- `hitTestRenderNode()` — 已移至 RenderTreeManager
- `hitTestRenderNodeRecursive()` — 已移至 RenderTreeManager
- `findSourceVNode()` — 使用 `RenderNode.sourceVNode` 直接访问

**保留的方法**：
- `resolveComponent(VNode $node)` — 保持不变，接受 VNode 并通过 groupId 查找组件（用于事件路由）

**API 变更说明**：`hitTest()` 返回类型从 `?VNode` 改为 `?RenderNode`。所有调用方需要相应修改以使用 `sourceVNode` 获取原始 VNode（如需访问 props 等）。

---

### 3.4 ScrollManager 修改

**文件**：`d:/Px/framework/Core/ScrollManager.php`

**核心修改**：

| 修改 | 说明 |
|------|------|
| `scrollDragTarget` 类型改为 `?RenderNode` | 拖拽目标改为 RenderNode |
| `handleScrollWheel()` 参数改为 `?RenderNode $root` | 使用 RenderNode 树进行容器查找 |
| `hitTestScrollbar()` 参数改为 `?RenderNode $root` | 使用 RenderNode 树进行命中测试 |
| `applyScrollTop/Left()` 操作 RenderNode | 修改 `$node->scrollTop`，标记 dirty |

**构造函数修改**（注入 RenderTreeManager）：

```php
public function __construct(
    callable $requestRender,
    callable $directRender,
    callable $resolveComponent,
    RenderTreeManager $renderTreeManager  // 新增
) {
    $this->requestRender = $requestRender;
    $this->directRender = $directRender;
    $this->resolveComponent = $resolveComponent;
    $this->renderTreeManager = $renderTreeManager;  // 新增
}
```

**handleScrollWheel 修改后**：

```php
public function handleScrollWheel($event, ?RenderNode $root): void
{
    $scrollNode = $this->renderTreeManager->findScrollContainerAt($event->x, $event->y);
    if ($scrollNode === null) return;
    // ... 其余逻辑不变，操作目标改为 RenderNode ...
}
```
**注意**：`$root` 参数保留（目前传入但内部通过 `renderTreeManager` 查询），调用方仍传 `activeVNodeTree`，后续可移除此参数。

**hitTestScrollbar 修改后**：

```php
public function hitTestScrollbar(int $x, int $y, ?RenderNode $root): ?array
{
    $scrollNode = $this->renderTreeManager->findScrollContainerAt($x, $y);
    if ($scrollNode === null) return null;
    // ... 其余逻辑不变，操作目标改为 RenderNode ...
}
```
**注意**：同上，`$root` 参数保留但未使用。
```

**applyScrollTop 修改后**：

```php
public function applyScrollTop(RenderNode $node, int $newScrollTop, bool $persist): void
{
    $node->scrollTop = $newScrollTop;
    // 不标记 layoutDirty，LayoutResolver 使用快速滚动路径

    if ($persist) {
        // 持久化到组件 bind
        if ($node->sourceVNode !== null) {
            $bindKey = $node->sourceVNode->props[':scroll-top'] ?? '';
            if ($bindKey !== '') {
                $target = ($this->resolveComponent)($node->sourceVNode);
                $target->setBindValue($bindKey, (string) $newScrollTop);
            }
        }
        ($this->requestRender)();
    } else {
        ($this->directRender)();
    }
}
```

**关键改动点**：
1. `scrollDragTarget` 从 `?VNode` 改为 `?RenderNode`
2. `handleScrollWheel()` / `hitTestScrollbar()` 参数改为 `?RenderNode $root`
3. 内部通过 `renderTreeManager->findScrollContainerAt()` 获取 RenderNode
4. `applyScrollTop/Left()` 操作 RenderNode 属性，不再同步回 VNode

---

### 3.5 VNode 修改（删除布局字段）

**文件**：`d:/Px/framework/Rendering/VNode.php`

**删除的字段**：

```php
// 删除以下字段（移至 RenderNode）
public int $x = 0;
public int $y = 0;
public int $w = 0;
public int $h = 0;
public int $layer = 0;
public array $computedStyle = [];
public bool $isScrollContainer = false;
public int $scrollTop = 0;
public int $scrollLeft = 0;
public int $contentHeight = 0;
public int $contentWidth = 0;
```

**保留的字段**（组件占位 + 元素描述）：

```php
// 树结构
public string $type;
public ?array $props;
public mixed $children;
public ?string $key;

// 组件占位
public bool $isComponent;
public ?string $componentClass;
public ?ReactiveComponent $componentInstance;
public ?array $componentProps;

// 事件路由
public string $groupId = 'app';
```

---

## 四、实施步骤

### 执行前检查
**运行现有测试，建立基准**：
```bash
D:\swoole_compiler\php.exe tests/run_all_tests.php
```
确保全部通过后再开始实施。

### Step 1: 创建 RenderNode 类
**文件**：`d:/Px/framework/Rendering/RenderNode.php`

**实现**：
- 所有属性声明（含 `lastScrollTop` 字段）
- `__construct()` 构造函数
- `markLayoutDirty()` 方法（循环实现递归）
- `markSubtreeDirty()` 方法（仅标记子树，不向上传播）
- `needsPaint()` / `markPainted()` 方法
- `addChild()` / `clearChildren()` 方法

**测试**：新增 `tests/unit/RenderNodeTest.php`

**验证**：`D:\swoole_compiler\php.exe tests/unit/RenderNodeTest.php`

---

### Step 2: 创建 RenderTreeManager 类
**文件**：`d:/Px/framework/Rendering/RenderTreeManager.php`

**实现**：
- 基础属性和构造函数
- `updateFromVNode()` 方法（**包含复用逻辑，非可选**）
- `getRootRenderNode()` 方法
- `clear()` 方法（仅在需要强制全量重建时调用）
- `findRenderNodeBySourceVNode()` 方法
- `findRenderNodeByGroupId()` 方法
- `hitTest()` 方法

**复用策略（核心，非可选）**：

每次 `rebuildVNodeTree` 时：
1. 调用 `updateFromVNode()` 遍历 VNode 树
2. 通过 `VNode.id`（编译器分配，生命周期内稳定）查找已存在的 RenderNode
3. 如果 RenderNode 存在且 VNode 结构未变化 → 复用，仅标记 dirty
4. 如果 RenderNode 不存在 → 新建
5. **不要**调用 `clear()`，除非收到明确的全量重建请求

```php
private function updateFromVNode(VNode $vnode, ?RenderNode $parent): RenderNode
{
    // ...

    // 关键复用逻辑：使用 spl_object_hash（无需修改编译器）
    $hash = spl_object_hash($vnode);
    if (isset($this->vnodeToRenderNodeMap[$hash])) {
        // 复用现有 RenderNode
        $renderNode = $this->vnodeToRenderNodeMap[$hash];

        // 检查结构是否变化（type 和 key）
        if ($renderNode->type !== $vnode->type || $renderNode->key !== $vnode->key) {
            // 结构变化，需要重建子节点
            $renderNode->type = $vnode->type;
            $renderNode->key = $vnode->key;
            $renderNode->clearChildren();
        }

        // 更新样式，标记为 dirty
        $renderNode->style = $vnode->computedStyle ?? [];
        $renderNode->layoutDirty = true;
        $renderNode->lastPaintFrame = 0;  // 标记需要重绘
    } else {
        // 新建 RenderNode
        $renderNode = new RenderNode(
            $vnode->type,
            $vnode->computedStyle ?? [],
            null,
            $vnode->key
        );
        $renderNode->sourceVNode = $vnode;
        $renderNode->groupId = $vnode->groupId;
        $this->vnodeToRenderNodeMap[$hash] = $renderNode;
    }

    // 继续处理 children 和 parent 关系...
    return $renderNode;
}
```

**何时调用 clear()**：
- 仅在 `Application::reset()` 或类似的全量重置场景
- 正常渲染循环中 **不要** 调用 clear()

**测试**：新增 `tests/unit/RenderTreeManagerTest.php`

**验证**：`D:\swoole_compiler\php.exe tests/unit/RenderTreeManagerTest.php`

---

### Step 3: 修改 LayoutResolver
**文件**：`d:/Px/framework/Rendering/LayoutResolver.php`

**修改**：
- 参数类型从 `VNode` 改为 `RenderNode`
- 直接使用 `$node->style`（不调用 `getInlineStyle()`）
- 实现 dirty 检查逻辑（含快速滚动路径，仅平移子节点）
- 未脏节点坐标计算含 margin
- `shiftChildrenY()` 支持跳过绝对定位子节点
- 返回值类型变更

**测试**：复用 `LayoutResolverTest.php`（需调整测试用例创建 RenderNode）

**验证**：`D:\swoole_compiler\php.exe tests/unit/LayoutResolverTest.php`

---

### Step 4: 修改 VNodeRenderer
**文件**：`d:/Px/framework/Rendering/VNodeRenderer.php`

**修改**：
- 参数类型从 `VNode` 改为 `RenderNode`
- `vnodeToElement` 改名为 `renderNodeToElement`
- 实现 paintDirty 检查逻辑（帧号机制）
- 从 `$node->sourceVNode->props` 获取 bind 值

**测试**：复用 `VNodeRendererTest.php`

**验证**：`D:\swoole_compiler\php.exe tests/unit/VNodeRendererTest.php`

---

### Step 5: 修改 Application
**文件**：`d:/Px/framework/Core/Application.php`

**修改**：
- 新增 `$renderTreeManager` 字段
- 构造函数初始化（传入 renderTreeManager 给 ScrollManager）
- `render()` 调用新流程（通过 renderTreeManager.updateFromVNode 同步 bind 值）
- `directRender()` 改为操作 RenderNode
- `hitTest()` 改为基于 RenderTreeManager（返回 ?RenderNode）
- `handleMouseEvent()` 通过 `sourceVNode` 访问 props
- 保留 `resolveComponent()` 方法（接受 VNode，通过 groupId 查找组件）

**测试**：`D:\swoole_compiler\php.exe tests/unit/ComponentTreeTest.php`、`HitTestTest.php`

**验证**：`D:\swoole_compiler\php.exe tests/unit/ComponentTreeTest.php && D:\swoole_compiler\php.exe tests/unit/HitTestTest.php`

---

### Step 6: 修改 ScrollManager
**文件**：`d:/Px/framework/Core/ScrollManager.php`

**修改**：
- `$scrollDragTarget` 类型改为 `?RenderNode`
- 构造函数新增 `RenderTreeManager $renderTreeManager` 参数
- `handleScrollWheel()` / `hitTestScrollbar()` 参数改为 `?RenderNode $root`（保留但未使用）
- 所有方法操作 RenderNode 属性
- `applyScrollTop/Left()` 操作 RenderNode

**测试**：运行 calculator-ng 应用测试滚动

**验证**：`build.bat calculator-ng --run`（手动测试滚动）

---

### Step 7: 修改 VNode（删除布局字段）
**文件**：`d:/Px/framework/Rendering/VNode.php`

**修改**：
- 删除布局相关字段（x, y, w, h, layer, computedStyle, isScrollContainer, scrollTop, scrollLeft, contentHeight, contentWidth）
- 仅保留组件占位 + 元素描述字段
- 不新增 VNode.id 字段（使用 spl_object_hash 替代）

**测试**：运行全部单元测试 + AOT 检查

**验证**：
```bash
D:\swoole_compiler\php.exe tests/run_all_tests.php
D:\swoole_compiler\php.exe framework\aot-checker.php --project apps/calculator-ng --skip direct_cpp_call
```

---

### Step 8: 已整合至 Step 2
RenderNode 复用逻辑已在 Step 2 中作为必要实现，无需单独步骤。

---

## 五、测试策略

### 5.1 测试运行顺序

```
Step 1 完成后：
  D:\swoole_compiler\php.exe tests/unit/RenderNodeTest.php

Step 2 完成后：
  D:\swoole_compiler\php.exe tests/unit/RenderTreeManagerTest.php

Step 3-4 完成后：
  D:\swoole_compiler\php.exe tests/unit/LayoutResolverTest.php
  D:\swoole_compiler\php.exe tests/unit/VNodeRendererTest.php

Step 5 完成后：
  D:\swoole_compiler\php.exe tests/unit/ComponentTreeTest.php
  D:\swoole_compiler\php.exe tests/unit/HitTestTest.php

Step 6 完成后：
  D:\swoole_compiler\php.exe tests/unit/ReactiveComponentTest.php
  D:\swoole_compiler\php.exe tests/unit/CalculatorAppTest.php

Step 7 完成后（全部测试）：
  D:\swoole_compiler\php.exe tests/run_all_tests.php
  D:\swoole_compiler\php.exe framework\aot-checker.php --project apps/calculator-ng
```

### 5.2 新增测试文件

| 文件 | 测试范围 | 预估用例数 |
|------|----------|------------|
| `RenderNodeTest.php` | RenderNode 属性、方法、脏标记传播 | 8 |
| `RenderTreeManagerTest.php` | VNode→RenderNode 转换、复用、clear、groupId 映射、hitTest | 15 |

### 5.3 AOT 兼容性检查

每个步骤完成后运行：
```bash
D:\swoole_compiler\php.exe framework\aot-checker.php --project apps/calculator-ng --skip direct_cpp_call
```

---

## 六、风险与应对

| 风险 | 概率 | 影响 | 应对措施 |
|------|------|------|----------|
| 重构后性能下降 | 中 | 高 | 实施前建立基准；Step 8 实现 RenderNode 复用优化 |
| ScrollManager 与 VNode 解耦不彻底 | 低 | 高 | 确保 `sourceVNode` 引用正确维护 |
| directRender 优化失效 | 低 | 中 | 保留回调签名；充分测试滚动场景 |
| 内存泄漏 | 中 | 高 | `clear()` 在每次 rebuild 前调用 |
| AOT 编译失败 | 低 | 高 | 严格遵守 AOT 约束；逐步编译验证 |
| VNode 布局字段删除导致测试失败 | 中 | 高 | 保留 VNode 仅读字段，逐步迁移依赖代码 |

---

## 七、关键文件清单

| 文件 | 操作 | 说明 |
|------|------|------|
| `d:/Px/framework/Rendering/RenderNode.php` | 新增 | 渲染专用节点类 |
| `d:/Px/framework/Rendering/RenderTreeManager.php` | 新增 | VNode→RenderNode 转换管理 |
| `d:/Px/framework/Rendering/VNode.php` | 修改 | 删除布局字段 |
| `d:/Px/framework/Rendering/LayoutResolver.php` | 修改 | 接受 RenderNode，实现 dirty 检查 |
| `d:/Px/framework/Rendering/VNodeRenderer.php` | 修改 | 接受 RenderNode，实现 paintDirty |
| `d:/Px/framework/Core/Application.php` | 修改 | 集成 RenderTreeManager，hitTest 委托给 RenderTreeManager |
| `d:/Px/framework/Core/ScrollManager.php` | 修改 | 操作 RenderNode，findScrollContainerAt/hitTestScrollbar 使用 RenderTreeManager |
| `d:/Px/tests/unit/RenderTreeManagerTest.php` | 修改 | 新增 groupId 映射和继承的测试用例 |
| `tests/unit/RenderNodeTest.php` | 新增 | RenderNode 测试 |
| `tests/unit/RenderTreeManagerTest.php` | 新增 | RenderTreeManager 测试 |

---

## 八、验证标准

### 8.1 基础功能验证
- [ ] `tests/run_all_tests.php` 全部通过
- [ ] `aot-checker.php` 无警告
- [ ] `build.bat calculator-ng` 构建成功
- [ ] calculator-ng 应用所有按钮响应正确

### 8.2 滚动性能验证
- [ ] 滚动时 CPU 占用无明显升高（对比重构前）
- [ ] 滚动容器拖拽平滑
- [ ] 快速滚动路径正常工作（`layoutDirty=false` 时仅平移子节点坐标）

### 8.3 内存与命中测试验证
- [ ] 内存泄漏测试：连续执行 1000 次 `requestRender()`，内存增长 < 10%
- [ ] 命中测试正确性：点击滚动容器内的子元素，能正确触发对应组件的事件

### 8.4 API 兼容性
- [ ] `Application::hitTest()` 返回 `?RenderNode`（不兼容变更）
- [ ] 调用方通过 `sourceVNode` 访问原始 VNode（如 props）