# Px 框架 AOT 原生响应式系统 — 最终实施方案

> **核心理念**：对标 Vue 3 的 `effect` 栈 + 自动依赖追踪，借鉴 Flutter 的 `ValueNotifier` 精细订阅模型，以 **PHP 8.4 Property Hooks** + **编译时代码生成** 为技术基座，在 AOT 环境下实现零运行时开销的精准响应式更新。
> 
> **设计原则**：`track()` 和 `notify()` 是完全对称的一对操作——get 钩子调用 `track()` 标记依赖，set 钩子调用 `notify()` 触发更新。编译器全权负责这对操作的注入，运行时库仅维护 Effect 拓扑和调度队列。

---

## 总体架构

```
┌─────────────────────────────────────────────────────────────────┐
│  Layer 1: 运行时库 (framework/Reactive/)                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ #[Reactive] Attribute ─── 编译时标记                         ││
│  │ DependencyTracker      ─── track/notify + Effect 栈          ││
│  │ Effect                 ─── 组件渲染副作用 + 微任务调度        ││
│  │ DependentsMap          ─── depId → Effect[] 依赖映射          ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                  │
│  Layer 2: 编译时代码生成 (Compiler/ScriptAnalyzer)                │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ 识别 #[Reactive] 属性                                        ││
│  │ 生成 $_px_react_storage 存储数组                              ││
│  │ 为每个属性生成 get/set 钩子（注入 track/notify）               ││
│  │ 覆盖 getVNodeTree() 包裹 Effect                               ││
│  │ setBindValue → $this->{$key}=$value 直接属性赋值              ││
│  │ 数组变异探测 → 追加 $this->arr=$this->arr 触发 set            ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                  │
│  Layer 3: 基类融合 (Component/ReactiveComponent)                  │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ 移除 $dirty / markDirty() / scheduleUpdate()                 ││
│  │ $vnodeCache + getVNodeTree() 保留但语义调整                   ││
│  │ Effect 懒创建 + onUnmount 自动清理                            ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

---

## Phase A — 创建运行时库（4 个新文件）

### A-1 `framework/Reactive/Reactive.php`

PHP 8.0 Attribute，零运行时开销——仅在编译时被 `ScriptAnalyzer` 读取。

```php
namespace Px\Reactive;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Reactive {}
```

### A-2 `framework/Reactive/DependencyTracker.php`

全局 Effect 栈管理器。Vue 3 的 `activeEffect` 在这里是 `$currentEffect`。

```php
namespace Px\Reactive;

class DependencyTracker
{
    private static ?Effect $currentEffect = null;
    /** @var Effect[] */
    private static array $effectStack = [];

    /**
     * 由生成的属性 get 钩子调用。
     * 将当前 Effect 注册到 [target][property] 的订阅列表。
     * AOT 安全：先 null 检查再操作。
     */
    public static function track(object $target, string $key): void
    {
        $effect = self::$currentEffect;
        if ($effect === null) return;

        $depId = self::depId($target, $key);
        $effect->recordDependency($depId);
        DependentsMap::add($depId, $effect);
    }

    /**
     * 由生成的属性 set 钩子调用。
     * 通知所有订阅了 [target][property] 的 Effect。
     * AOT 安全：先获取数组再 foreach。
     */
    public static function notify(object $target, string $key): void
    {
        $depId = self::depId($target, $key);
        $effects = DependentsMap::get($depId);
        foreach ($effects as $effect) {
            $effect->schedule();
        }
    }

    /**
     * 在 getVNodeTree() 中调用，将 render() 包裹在 Effect 上下文中。
     * Vue 3: effectStack.push(activeEffect) / pop
     * AOT 安全：try/finally 保证栈一致性。
     */
    public static function runWithEffect(Effect $effect, callable $fn): mixed
    {
        array_push(self::$effectStack, $effect);
        self::$currentEffect = $effect;
        try {
            return $fn();
        } finally {
            array_pop(self::$effectStack);
            self::$currentEffect = empty(self::$effectStack)
                ? null
                : self::$effectStack[count(self::$effectStack) - 1];
        }
    }

    /** 计算依赖 ID — spl_object_id 在 AOT 下已验证安全 */
    private static function depId(object $target, string $key): string
    {
        return (string)spl_object_id($target) . '|' . $key;
    }
}
```

### A-3 `framework/Reactive/Effect.php`

每个响应式组件实例持有 1 个 Effect。Flutter 的 `ValueNotifier` 的 listener 在这里是 Effect 的 `schedule()`。

```php
namespace Px\Reactive;

use Px\Core\Scheduler;
use Px\Component\ReactiveComponent;

class Effect
{
    private bool $pending = false;
    private array $depIds = [];
    private ?ReactiveComponent $component;

    public function __construct(ReactiveComponent $component)
    {
        $this->component = $component;
    }

    /**
     * 记录此 Effect 依赖的 depId。
     * 在 track() 中调用。AOT 安全：简单数组 push。
     */
    public function recordDependency(string $depId): void
    {
        $this->depIds[] = $depId;
    }

    /**
     * 由 DependencyTracker::notify() 调用。
     * 防重入 pending 标志确保同一 Effect 在微任务队列中只出现一次。
     * Flutter 的 markNeedsBuild() 逻辑类似——把 widget 标记为 dirty。
     */
    public function schedule(): void
    {
        if ($this->pending) return;
        $this->pending = true;

        Scheduler::getInstance()->addMicrotask(function (): void {
            $this->pending = false;
            if ($this->component !== null) {
                $this->component->performUpdate();
            }
        });
    }

    /**
     * 清除所有依赖订阅（组件 unmount 时调用）。
     * Flutter dispose() + Vue 3 effect cleanup。
     */
    public function cleanup(): void
    {
        foreach ($this->depIds as $depId) {
            DependentsMap::remove($depId, $this);
        }
        $this->depIds = [];
        $this->component = null;
    }
}
```

### A-4 `framework/Reactive/DependentsMap.php`

**`SplObjectStorage`** 是 AOT 下 `WeakMap` 的替代品（已验证兼容）。

```php
namespace Px\Reactive;

class DependentsMap
{
    /** @var array<string, \SplObjectStorage> */
    private static array $map = [];

    /** 注册一个 Effect 到某 depId 的订阅列表 */
    public static function add(string $depId, Effect $effect): void
    {
        if (!isset(self::$map[$depId])) {
            self::$map[$depId] = new \SplObjectStorage();
        }
        self::$map[$depId]->attach($effect);
    }

    /** 获取某 depId 的所有订阅 Effect */
    public static function get(string $depId): array
    {
        if (!isset(self::$map[$depId])) return [];
        $storage = self::$map[$depId];
        $result = [];
        $storage->rewind();
        while ($storage->valid()) {
            $result[] = $storage->current();
            $storage->next();
        }
        return $result;
    }

    /** 移除某 depId 下的指定 Effect 订阅 */
    public static function remove(string $depId, Effect $effect): void
    {
        if (!isset(self::$map[$depId])) return;
        self::$map[$depId]->detach($effect);
        if (self::$map[$depId]->count() === 0) {
            unset(self::$map[$depId]);
        }
    }

    /** 移除某 Effect 的所有订阅（组件 unmount 时）。AOT 安全：遍历 keys。 */
    public static function removeAllFor(Effect $effect): void
    {
        foreach (self::$map as $depId => $storage) {
            if ($storage->contains($effect)) {
                $storage->detach($effect);
                if ($storage->count() === 0) {
                    unset(self::$map[$depId]);
                }
            }
        }
    }
}
```

---

## Phase B — 重写 ScriptAnalyzer

### 改造 `framework/Compiler/ScriptAnalyzer.php`

当前版本的核心职责是 `injectDirty()`。新版本的核心职责转为**提取响应式属性列表**。

```php
class ScriptAnalyzer
{
    private array $reactiveProps = [];  // [{name, type, default}]

    /**
     * 从 SFC <script> 块中提取所有 #[Reactive] 标记的属性。
     *
     * 匹配模式:
     *   #[Reactive]
     *   public int $count = 0;
     *
     * 返回: [['name'=>'count', 'type'=>'int', 'default'=>'0'],
     *         ['name'=>'name', 'type'=>'string', 'default'=>"''"]]
     */
    public function extractReactiveProperties(string $script): array
    {
        $props = [];
        // 匹配 #[Reactive]\npublic (type) $name [= default];
        if (preg_match_all(
            '/#\[Reactive\]\s*\n\s*public\s+(string|int|bool|float|array)\s+\$(\w+)\s*(?:=\s*([^;]+))?\s*;/',
            $script,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $props[] = [
                    'name'    => $m[2],
                    'type'    => $m[1],
                    'default' => isset($m[3]) ? trim($m[3]) : $this->getDefaultForType($m[1]),
                ];
            }
        }
        $this->reactiveProps = $props;
        return $props;
    }

    /**
     * 检测方法体中是否有数组变异操作（$this->xxx[] = ...）。
     * 这是 PHP 属性钩子的盲区——必须追加显式触发。
     *
     * 返回: 需要追加触发语句的属性名数组
     */
    public function detectArrayMutation(string $methodBody): array
    {
        $mutated = [];
        foreach ($this->reactiveProps as $prop) {
            if ($prop['type'] !== 'array') continue;
            $name = $prop['name'];
            // 匹配 $this->xxx[] =  或 $this->xxx[ 表达式 ]
            if (preg_match('/\$this->' . preg_quote($name, '/') . '\s*\[.*?\]\s*=/s', $methodBody)) {
                $mutated[] = $name;
            }
            // 匹配 $this->xxx[] =   (push 模式)
            if (preg_match('/\$this->' . preg_quote($name, '/') . '\s*\[\]\s*=/', $methodBody)) {
                $mutated[] = $name;
            }
        }
        return array_unique($mutated);
    }

    /**
     * 获取旧接口方法 — 当前编译器调用此方法。
     * 在依赖追踪模式下，dirty 注入完全由属性钩子取代。
     * 但保留 AOT specific 的 wrapIntAssignments 处理。
     */
    public function injectDirty(string $script): string
    {
        // ── 依赖追踪模式：不注入任何 dirty 标记 ──
        // 所有更新触发由 property hook 的 set → notify → Effect → schedule 处理
        // 但保留 AOT 兼容性转换（C2440 等）
        return $script;
    }

    /** 保留原有的 extractClassDeclaration / wrapIntAssignments / ... */
    // ... (保持不变)
}
```

**关键变更**：
- 移除 `removeExistingDirty()` 和 `injectDirtyIntoMethods()` 的全部正则逻辑
- 新增 `extractReactiveProperties()` — 提取 `#[Reactive]` 属性列表
- 新增 `detectArrayMutation()` — 给生成器提供追加信息

---

## Phase C — 改造 sfc-compiler 代码生成管道

### C-1 `compileOneComponent()` 的核心变化

修改 `f:\work\Px\framework\Compiler\sfc-compiler.php` 中 `compileOneComponent()` 函数（第 2086 行附近）的代码生成部分。

**生成流程对比：**

```
当前:
  ScriptAnalyzer::injectDirty($script) → 生成 class body
  → wrapIntAssignments
  → 嵌入到 class 模板
  → 生成 render() / dispatchClick / setBindValue / getBindValue / vForHelpers

新:
  ScriptAnalyzer::extractReactiveProperties($script)
  → ScriptAnalyzer::injectDirty($script)  // 空操作，保留为兼容
  → wrapIntAssignments
  → [新增] 生成 $_px_react_storage 数组代码
  → [新增] 为每个 #[Reactive] 属性生成 Property Hook 代码
  → [新增] 生成 $_px_effect 字段
  → [新增] 生成 getVNodeTree() override（包裹 Effect）
  → [新增] 生成 onUnmount() 清理代码
  → [修改] setBindValue → $this->{$key}=$value 直接赋值（不再调 markDirty）
  → [修改] getBindValue → return $this->{$key} 直接读取
  → 嵌入到 class 模板
  → 生成 render() / dispatchClick / vForHelpers
```

### C-2 属性钩子代码生成函数

在 `sfc-compiler.php` 中新增生成函数：

```php
/**
 * 生成带属性钩子的响应式属性声明代码。
 * Vue 3 的 getter/setter 拦截在这里通过 PHP 8.4 原生钩子实现。
 */
function generateReactivePropertyCode(array $prop, int $indent): string
{
    $name    = $prop['name'];
    $type    = $prop['type'];
    $default = $prop['default'];

    $ind = str_repeat('    ', $indent);

    $code  = "{$ind}public {$type} \${$name} {\n";
    $code .= "{$ind}    get {\n";
    $code .= "{$ind}        \\Px\\Reactive\\DependencyTracker::track(\$this, '{$name}');\n";
    $code .= "{$ind}        return \$this->_px_react_storage['{$name}'] ?? {$default};\n";
    $code .= "{$ind}    }\n";

    // array 类型不生成 set 钩子（数组变异通过显式触发模式处理）
    if ($type !== 'array') {
        $code .= "{$ind}    set ({$type} \$value) {\n";
        $code .= "{$ind}        if (\$this->_px_react_storage['{$name}'] !== \$value) {\n";
        $code .= "{$ind}            \$this->_px_react_storage['{$name}'] = \$value;\n";
        $code .= "{$ind}            \\Px\\Reactive\\Notifier::notify(\$this, '{$name}');\n";
        $code .= "{$ind}        }\n";
        $code .= "{$ind}    }\n";
    }

    $code .= "{$ind}}\n";
    return $code;
}
```

> `Notifier::notify()` 实际上是 `DependencyTracker::notify()` 的别名，在 `framework/Reactive/` 中定义：`class Notifier { public static function notify(...) { DependencyTracker::notify(...); } }`。这样保持两篇方案设计的一致性。

### C-3 `getVNodeTree()` 覆写生成

```php
function generateGetVNodeTreeOverride(string $indent): string
{
    $ind = str_repeat('    ', $indent);
    return "{$ind}public function getVNodeTree(): \\Px\\Dom\\VNode\n"
         . "{$ind}{\n"
         . "{$ind}    if (!\$this->dirty && \$this->vnodeCache !== null) {\n"
         . "{$ind}        return \$this->vnodeCache;\n"
         . "{$ind}    }\n"
         . "{$ind}    if (\$this->_px_effect === null) {\n"
         . "{$ind}        \$this->_px_effect = new \\Px\\Reactive\\Effect(\$this);\n"
         . "{$ind}    }\n"
         . "{$ind}    return \\Px\\Reactive\\DependencyTracker::runWithEffect(\n"
         . "{$ind}        \$this->_px_effect,\n"
         . "{$ind}        function (): \\Px\\Dom\\VNode { return parent::getVNodeTree(); }\n"
         . "{$ind}    );\n"
         . "{$ind}}\n";
}
```

### C-4 `setBindValue` 代码生成改造

当前生成（[sfc-compiler.php:1355](file:///f:/work/Px/framework/Compiler/sfc-compiler.php#L1355-L1382)）的每个 case 末尾有 `$this->markDirty()`。新版本**移除**它：

```php
// 新生成模式：
case 'display': if ($this->display !== $value) { $this->display = $value; } break;
//                          赋值本身触发属性钩子 → notify → Effect → schedule → 自动更新
//                          不再需要手动 $this->markDirty()
```

### C-5 数组变异语句追加

在检测到方法体内有 `$this->items[] = $newItem` 时，在方法末尾追加 `$this->items = $this->items;`。在 `generateReactivePropertyCode` 的 array 类型分支中，属性钩子只生成 get（set 无意义——不会被调用），而变异触发通过编译时追加完成。

---

## Phase D — 改造 ReactiveComponent 基类

### `framework/Component/ReactiveComponent.php` 修改

**移除的成员**（不向后兼容）：
```php
protected bool $hasPendingUpdate = false;    // ❌ 移除
public bool $dirty = false;                  // ❌ 移除
protected bool $isUpdating = false;          // ❌ 移除（由 Effect::pending 替代）

protected function markDirty(): void         // ❌ 移除
protected function scheduleUpdate(): void    // ❌ 移除
```

**保留的成员**：
```php
protected ?VNode $vnodeCache = null;         // ✅ 保留 — 缓存 VNode 树
protected ?\Closure $renderCallback = null;  // ✅ 保留 — Application 注入
public ?RenderNode $rootRenderNode = null;   // ✅ 保留 — RenderTreeManager 设置

// 事件通信
protected array $eventHandlers = [];
protected array $listenerIds = [];
```

**新增的成员**：
```php
// ── 响应式系统（由编译器生成的子类使用）──
protected ?\Px\Reactive\Effect $_px_effect = null;
```

**`getVNodeTree()` 修改**——基类保留基本版本（不含 Effect 包裹），编译器生成的子类覆盖并提供 Effect 包裹。

```php
public function getVNodeTree(): VNode
{
    if (!$this->dirty && $this->vnodeCache !== null) {
        return $this->vnodeCache;
    }
    $this->vnodeCache = $this->render();
    $this->dirty = false;
    return $this->vnodeCache;
}
```

但等一下——我们移除了 `$dirty` 字段！所以我们需要一个新的机制来跟踪 "is the cache stale?"。

**重新设计**：让 `$dirty` 字段存在但其语义变化。不再由 `markDirty()` 设置，而是由 `performUpdate()` 设置：

```php
/** 
 * dirty = 需要重新执行 render() 刷新缓存
 * – 由 performUpdate() 在 Effect 调度后设置
 * – 由 getVNodeTree() 在重建缓存后清除
 */
public bool $dirty = false;
public ?VNode $vnodeCache = null;

public function getVNodeTree(): VNode
{
    if (!$this->dirty && $this->vnodeCache !== null) {
        return $this->vnodeCache;
    }
    $this->vnodeCache = $this->render();
    $this->dirty = false;
    return $this->vnodeCache;
}

/**
 * 由 Effect::schedule() 的微任务回调调用。
 * 触发 render callback，最终由 Application::render() 调用 getVNodeTree()。
 */
public function performUpdate(): void
{
    if ($this->isUpdating) return;
    $this->isUpdating = true;

    if ($this->isMounted) $this->onBeforeUpdate();

    $this->dirty = true;        // 标记需要重建 VNode 缓存
    if ($this->renderCallback !== null) {
        ($this->renderCallback)();  // → Application::requestRender()
    }

    if ($this->isMounted) $this->onUpdated();
    $this->isUpdating = false;
}
```

**`unmount()` 修改**——自动清理 Effect：

```php
public function unmount(): void
{
    $this->onUnmount();

    // 清理响应式订阅
    if ($this->_px_effect !== null) {
        $this->_px_effect->cleanup();
        $this->_px_effect = null;
    }

    $this->isMounted = false;
    $this->rootRenderNode = null;

    // 移除注册在子组件上的事件处理器
    foreach ($this->listenerIds as $entry) {
        $entry['child']->removeHandler($entry['handlerId']);
    }
    $this->listenerIds = [];

    // 清空自身事件处理器
    $this->eventHandlers = [];
}
```

**在 `Application::matchComponentNode()` 中**，原有的 props-unchanged 优化继续有效（[行 714-720](file:///f:/work/Px/framework/Core/Application.php#L714-L721)）：

```php
$propsUnchanged = ($newNode->componentProps === ($oldNode?->componentProps ?? null));
if ($propsUnchanged && !$instance->dirty && $oldChildren !== null) {
    // 旧树复用——现在 $instance->dirty 由 Effect 调度自动管理
}
```

### ReactiveComponentInterface 调整

现有接口 `setBindValue` / `getBindValue` 签名保持不变——可被生成的 switch-to-property 实现继续兼容。

---

## Phase E — AOT 安全加固

基于 `docs/AOT编译器问题记录与解决方案.md` 的历史教训：

| AOT 约束 | 检查点 | 实施 |
| :--- | :--- | :--- |
| 命名参数被丢弃 | `new Effect($this)` — 已用位置参数 | ✅ |
| `count()` 类型冲突 | `count(self::$map[$depId])` — 需 `(int)` | 在 `DependentsMap` 中已规避（直接检查 `SplObjectStorage`） |
| 空安全链深度 | `self::$currentEffect?->deps[]` — 已拆分为 `if($effect===null)` + `$effect->deps[]` | ✅ |
| `toArray()` bug | 无此命名 | ✅ |
| 动态属性不可用 | 存储用数组 `$_px_react_storage` | ✅ |
| typed property 必须初始化 | `_px_effect = null` 显式初始化 | ✅ |
| `$GLOBALS` 不可用 | 无全局变量引用 | ✅ |

---

## Phase F — 编译现有应用验证

完成各 Phase 后，选择 calculator-ng 作为验证目标：

1. `php framework/compiler/sfc-compiler.php apps/calculator-ng/App.vue` 编译
2. 检查生成的 `apps/calculator-ng/gen/AppComponent.php`：
   - `$_px_react_storage` 数组完整
   - 所有 `public string $x` 被替换为带钩子的属性
   - `getVNodeTree()` 输出包含 `runWithEffect`
   - `setBindValue` 中无 `markDirty()` 调用
   - `dispatchClick` 方法中无 `markDirty()` 注入
   - `onUnmount()` 包含 Effect 清理
3. `php apps/calculator-ng/main.php` 运行（PHP CLI）
4. 验证：点击按钮 → 属性变更 → 自动更新 UI（无需手动 markDirty）

---

## 文件变更清单

| 操作 | 文件路径 | 说明 |
| :--- | :--- | :--- |
| 🆕 创建 | `framework/Reactive/Reactive.php` | `#[Reactive]` Attribute |
| 🆕 创建 | `framework/Reactive/DependencyTracker.php` | Effect 栈 + track/notify |
| 🆕 创建 | `framework/Reactive/Effect.php` | Effect 调度 + 清理 |
| 🆕 创建 | `framework/Reactive/DependentsMap.php` | depId → Effect 映射 |
| 🆕 创建 | `framework/Reactive/Notifier.php` | `DependencyTracker::notify()` 别名（保持方案一致性） |
| 🔧 改造 | `framework/Compiler/ScriptAnalyzer.php` | 移除 `injectDirty`，新增 `extractReactiveProperties` + `detectArrayMutation` |
| 🔧 改造 | `framework/Compiler/sfc-compiler.php` | `compileOneComponent` 新增属性钩子生成管道 |
| 🔧 改造 | `framework/Component/ReactiveComponent.php` | 移除 `markDirty`，移除 `scheduleUpdate`，移除 `$dirty`/`$hasPendingUpdate`，新增 `$_px_effect` |
| 🔧 调整 | `framework/Component/Contracts/ReactiveComponentInterface.php` | 保留接口不变（实现由编译器生成） |
| ♻️ 重新编译 | `apps/*/gen/*.php` | 所有现成应用的 gen 文件需重新生成 |

---

## Vue 3 和 Flutter 的借鉴映射表

| 概念 | Vue 3 | Flutter | Px (此方案) |
| :--- | :--- | :--- | :--- |
| 依赖跟踪 | `effect()` + `activeEffect` | — | `DependencyTracker` + `$currentEffect` |
| 响应式数据 | `reactive()` / `ref()` Proxy | `ValueNotifier<T>` | `#[Reactive]` + PHP 8.4 Property Hooks |
| 渲染副作用 | `componentEffect` | `build()` | `Effect` + `runWithEffect()` |
| 批量更新 | `nextTick` 微任务队列 | `scheduleFrame` | `Scheduler::addMicrotask()` |
| 脏检查 | `dirty` in Ref | `_dirty` in Element | `$dirty` in ReactiveComponent |
| 计算属性 | `computed()` | — | (V2 规划) |
| 细粒度订阅 | `map[dep][key] → Set<effect>` | `ValueNotifier._listeners` | `DependentsMap[spl_object_id\|key] → SplObjectStorage<Effect>` |
| 组件树重建 | patch/diff VNode | `updateChild` | `matchComponentNode` 复用 (已有) |
| 清理 | `effect.stop()` | `dispose()` | `Effect::cleanup()` + `onUnmount()` |

---

**总结**：在没有向后兼容约束的前提下，总共 **4 个新文件 + 3 个核心改造文件**，约 **800-1000 行** 新增/修改代码，即可让 Px 框架获得 Vue 3 级别的自动依赖追踪响应式能力，同时在 AOT 下保持原生属性访问性能。