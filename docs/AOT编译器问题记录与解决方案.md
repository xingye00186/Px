# AOT 编译器问题记录与解决方案

> 记录 Phase 5 布局引擎重构过程中遇到的 AOT 编译器问题及解决方案。
> 编译器版本：Swoole Compiler v1084（版本标记 `_v1084----------------------------------.txt`）

---

## 一、命名参数（Named Arguments）被静默丢弃

### 问题
PHP 命名参数在 AOT 编译时被静默丢弃，导致调用参数全部为默认值。

```php
// 错误：AOT 下所有命名参数被丢弃
return new PhysicalFragment(
    x: $result->x,
    y: $result->y,
    w: $result->w,
    h: $result->h
);

// 实际 AOT 执行效果 ≈ new PhysicalFragment()
// 所有参数为默认值 → 坐标全零
```

### 解决方案
所有构造函数调用使用**位置参数**，禁止使用命名参数。

```php
// 正确
return new PhysicalFragment(
    (int)$result->x, (int)$result->y, (int)$result->w, (int)$result->h,
    (int)$result->visualW, (int)$result->visualH, (int)$result->layer,
    (int)$result->contentWidth, (int)$result->contentHeight,
    $result->style, $resultChildren, $node
);
```

### 影响范围
- `PhysicalFragment` 构造函数
- `ConstraintSpace` 构造函数
- `ConstraintSpaceBuilder` 链式调用
- `IntrinsicSizes` 构造函数
- `LayoutCacheKey::fromSpace()`
- `LayoutResult` 构造函数（已删除）

---

## 二、`toArray()` 方法名参数传递 Bug

### 问题
方法名为 `toArray()` 时，AOT 编译器将其识别为特殊内部方法，导致参数传递被静默丢弃。

```php
// RenderNodeSerializer.php
public function toArray(RenderNode $node): array  // ← 方法名 toArray 触发 AOT bug
{
    // $node 参数在 AOT 下为 null
}
```

### 解决方案
避免使用 `toArray` 作为方法名，改用其他名称。

```php
public function serializeNode(RenderNode $node): array  // ✓ 正常工作
```

### 影响范围
- `RenderNodeSerializer::toArray()` → 更名为 `serializeNode()`
- `Application::dumpLayoutToFile()` 调用处同步修改

---

## 三、抽象类方法返回类型崩溃

### 问题
AOT 编译器对抽象基类中定义的方法，当子类通过 `parent::` 调用默认实现时，触发虚方法派发崩溃。

```
Fatal error: Abstract method Px\Rendering\Layout\LayoutAlgorithm::layout() must be implemented
```

### 解决方案
- 确保每个抽象方法在子类中有**完整实现**，不在抽象基类中提供非纯虚（有默认实现）的方法
- 子类不能通过 `parent::methodName()` 调用抽象基类的默认实现

### 影响范围
- `LayoutAlgorithm` 抽象类的所有子类
- 每个子类必须显式实现 `layout()` 和 `intrinsicSize()`

---

## 四、动态属性创建不可用（PHP 8.2+ / AOT）

### 问题
RenderNode 几何字段（`x`, `y`, `w`, `h`, `visualW`, `visualH`, `layer` 等）已从类声明中删除，但仍被旧代码路径写入。

```php
// RenderNode 不再声明 $x，但以下写入创建动态属性
$node->x = $frag->x;  // Deprecated: Creation of dynamic property
```

在 AOT 下：
- 动态属性创建触发 `Deprecated` 警告
- 读取动态属性返回 `null`（即使已写入）

### 解决方案
1. 清除所有写入动态属性的代码——`applyFragmentToNode` 不再写几何到 RenderNode
2. 所有读取使用 `?? 0` / `?? null` 保护
3. 状态外置：
   - 几何 → `PhysicalFragment`
   - 滚动状态 → `ScrollManager` Map
   - 交互状态 → `InteractionState` Map
   - 渲染偏移 → `VNodeRenderer::$paintFlags` / `$renderOffsetsX/Y`
   - 文本渲染信息 → `VNodeRenderer` 局部变量

```php
// 正确：使用 paintFlags 存储
private array $paintFlags = [];
private array $renderOffsetsX = [];
private array $renderOffsetsY = [];

private function setRenderOffsetX(RenderNode $node, int $value): void
{
    $this->renderOffsetsX[spl_object_id($node)] = $value;
}

private function getRenderOffsetX(RenderNode $node): int
{
    return $this->renderOffsetsX[spl_object_id($node)] ?? 0;
}
```

### 影响范围
- `LayoutOrchestrator::applyFragmentToNode()` — 移除几何写回
- `VNodeRenderer` — renderOffsetX/Y 改用 `$renderOffsetsX/Y` 数组
- `VNodeRenderer` — `textRenderInfo` 不再写入 RenderNode
- `VNodeRenderer` — `lastPaintFrame` 改用 `$paintFlags` SplObjectStorage
- `RenderNodeSerializer` — 所有几何字段读取加 `?? 0` 保护

---

## 五、`??` 操作符在 AOT 中的行为差异

### 问题
`??` 操作符在 AOT 下对已声明但未初始化的 typed property 返回 `null` 而非默认值。

```php
public readonly int $lineHeight;  // 声明但未初始化

// PHP 原生：$lineHeight 在访问前必须初始化，否则报错
// AOT：$lineHeight 返回 null
```

### 解决方案
所有 typed property 必须**始终初始化**：

```php
public readonly int $lineHeight = 0;  // ✓ 显式初始化
```

在读取可能为 `null` 的 typed property 时使用 `??` 保护：

```php
$h = (int)($node->h ?? 0);
$width = (int)($s->width?->toPx() ?? 0);
```

### 影响范围
- `ComputedStyle` — `$lineHeight` 声明处
- 所有 Algorithm 类中的几何计算
- `ConstraintSpace` 属性访问
- `PhysicalFragment` 创建时的 null 参数问题

---

## 六、空安全链（`?->`）深度限制

### 问题
AOT 编译器对超过 2 层的空安全链行为不一致。

```php
// 超过 2 层 ?->，AOT 下可能返回 null
$value = $cs->margin?->left?->toPx();  // 2 层 ?->，Ok
$value = $a?->b?->c->method();          // 超过 2 层，问题
```

### 解决方案
拆解为多步：

```php
$margin = $cs->margin;
$left = $margin?->left;
$value = $left !== null ? $left->toPx() : 0;
```

### 影响范围
- `BlockAlgorithm` 中的多层访问
- `FlexAlgorithm` 中的样式读取
- `Orchestrator` 中的约束构建

---

## 七、接口方法必须 100% 实现

### 问题
AOT 编译器要求接口中声明的所有方法必须在实现类中定义。未实现的方法导致编译错误。

```
Fatal error: Class AbsolutePositioning must implement method
AbsoluteStrategy::positionInViewport()
```

### 解决方案
确保接口定义的方法数量 = 实现类中实现的方法数量。删除接口中不存在对应实现的方法：

```php
// 接口定义
interface AbsoluteStrategy
{
    public function absoluteLayout(LayoutInput $input): LayoutResult;
    // 删除 positionInViewport() — 无实现
}
```

### 影响范围
- `AbsoluteStrategy` 接口 — 移除 `positionInViewport()`
- `LayoutAlgorithm` 子类 — 所有抽象方法必须有完整实现

---

## 八、`PhysicalFragment` 构造参数类型严格

### 问题
`PhysicalFragment` 构造函数参数类型为 `int`，但调用时传入 `null` 导致类型错误。

```
Fatal error: PhysicalFragment::__construct(): Argument #5 ($visualW) must be of type int, null given
```

### 原因
创建 Fragment 时代码使用了 `null` 表示"不需要 visualW"：

```php
new PhysicalFragment($x, $y, $w, $h, null, null, ...);
//                          visualW ↑  visualH ↑ → 类型错误
```

### 解决方案
将所有 `null` 参数改为 `0`（构造函数已有 `int $visualW = 0` 默认值）：

```php
new PhysicalFragment($x, $y, $w, $h, 0, 0, ...);
```

### 影响范围
- `BlockAlgorithm::stackBlockChildren()` — 子节点 Fragment 创建
- `GridAlgorithm` — grid 单元格 Fragment 创建
- `InlineAlgorithm` — IFC 子节点 Fragment 创建

---

## 九、`ConstraintSpaceBuilder` 类名与 `ConstraintSpace` 混淆

### 问题
AOT 编译器将 `ConstraintSpaceBuilder` 和 `ConstraintSpace` 视为同一类，导致类型推断错误。

### 解决方案
避免在同一个文件中同时引用这两个类。使用内联构造替代 Builder 模式：

```php
// 避免 Builder 链式调用
// 直接构造
$space = new ConstraintSpace(
    (int)$contentW, (int)$contentH,
    (int)$offX, (int)$offY,
    percentageWidth: $percW,
    percentageHeight: $percH,
);
```

### 影响范围
- `LayoutOrchestrator::buildChildSpace()` — 使用 `ConstraintSpace::forChild()` 静态工厂
- 避免在 Orchestrator 热路径中使用 `ConstraintSpaceBuilder`

---

## 十、`spl_object_id()` 与动态数组的兼容性

### 问题
`spl_object_id()` 在 AOT 下返回的值用于数组键时，可能与 PHP 原生不同。但基本使用（作为数组键）是安全的。

### 已验证安全的使用模式

```php
$this->paintFlags[spl_object_id($node)] = $frame;     // ✓
$this->renderOffsetsX[spl_object_id($node)] = $value;  // ✓
```

---

## 十一、`count()` 在循环条件中类型冲突

### 问题
`count()` 在 AOT `use native_types` 模式下返回 `php::Var` 类型，
而循环变量如 `$i`、`$ri` 被推断为 `php::Int`，两者比较时编译失败。

```
Fatal error: Cannot assign value to variable $ri of type php::Int with type php::Var
in LayoutOrchestrator.php:223
```

### 解决方案
将 `count()` 返回值预缓存为 `(int)` 变量，再用于循环条件：

```php
// 错误：count() 返回 php::Var
for ($i = 0; $i < count($items); $i++) { ... }

// 正确：预缓存为 (int)
$itemCount = (int)count($items);
for ($i = 0; $i < $itemCount; $i++) { ... }
```

同样适用于 `foreach` 中的数组索引比较：

```php
$itemCount = (int)count($items);
for ($ri = 0; $ri < $itemCount; $ri++) {
    $child = $items[$ri];
    // ...
}
```

### 影响范围
- `LayoutOrchestrator` — Phase C 重布局循环
- 所有使用 `count()` 在 `for`/`foreach` 条件中的热路径

---

## 十二、`$GLOBALS` 在 `use native_types` 类中编译失败

### 问题
在标记了 `use native_types` 的类中直接使用 `$GLOBALS["key"]`，
AOT 编译器将其编译为 C++ 标识符 `$GLOBALS`，但该标识符在生成的
C++ 代码中未声明，导致编译错误：

```
error C2065: '$GLOBALS': undeclared identifier
```

### 解决方案
- **首选**：直接删除调试性全局变量使用（纯诊断日志无功能作用）
- **替代**：使用 `static` 方法局部变量（AOT 兼容）

```php
// 错误：$GLOBALS 在 use native_types 类中编译失败
if (($GLOBALS["_LL"]??0) < 300) { ... }

// 正确 1：删除该行（纯调试日志时）

// 正确 2：static 局部变量
static $_ll = 0;
if ($_ll < 300) { $_ll++; /* ... */ }
```

### 影响范围
- `LayoutOrchestrator` — `$GLOBALS["_LL"]` 调试计数器（已移除）

---

## 十三、`foreach` 在未类型化 `array` 属性上的键类型推断失败

### 问题
当 `foreach` 遍历一个类型为 `array`（无元素类型标注）的类属性时，
AOT 编译器无法推断键和值的类型。结合 `count()` 比较时触发类型冲突。

```php
// $children 声明为 array（无元素类型），AOT 无法推断
foreach ($node->children as $ri => $child) {
    if ($ri < count($items)) { ... }  // 类型冲突
}
```

### 解决方案
将 `foreach` 改为 `for` + 索引访问，避免键类型推断不确定性：

```php
$len = (int)count($node->children);
for ($ri = 0; $ri < $len; $ri++) {
    $child = $node->children[$ri];
    // ...
}
```

### 影响范围
- `LayoutOrchestrator` — Phase C 中的 `foreach ($node->children as $ri => $child)`

---

## 十四、属性缺少类型标注导致编译中断

### 问题
在 `use native_types` 类中，未标注类型的属性（或仅标注为 `array` 无元素类型）
导致 AOT 编译器在 `prepare` 阶段抛出 `TypePhp\CompilerBase->checkVar` 异常。

```
Fatal error: Cannot assign value to variable $ri of type php::Int with type php::Var
```

或表现为 `prepare` 阶段对 `foreach` 的 `checkVar` 调用失败。

### 解决方案
所有属性必须标注完整类型。特别关注：

```php
// 错误：缺少类型
private $_sourceNode = null;

// 正确
private ?RenderNode $_sourceNode = null;

// 错误：方法参数缺少类型
public function sourceNode($v): self { ... }

// 正确
public function sourceNode(?RenderNode $v): self { ... }
```

### 影响范围
- `PhysicalFragmentBuilder` — `$_sourceNode` 增加 `?RenderNode` 标注

---

## 总结：AOT 编码规范

| 规则 | 说明 |
|------|------|
| **禁止命名参数** | 构造函数/方法始终使用位置参数 |
| **禁止 `toArray()` 方法名** | 使用 `serializeNode()` 等替代名称 |
| **抽象方法必须完整实现** | 每个子类必须显式实现所有抽象方法 |
| **typed property 必须初始化** | `int $x = 0` 而非 `int $x` |
| **深度空安全链拆解** | `?->` 不超过 2 层 |
| **null 不能传给 `int` 参数** | 使用默认值 `0` 而非 `null` |
| **动态属性不可用** | 状态外置到 Map/Array/SplObjectStorage |
| **读取加 `??` 保护** | `$node->x ?? 0` 而非 `$node->x` |
| **避免 Builder 模式** | 直接构造 DTO，不链式调用 |
| **`count()` 预缓存为 `(int)`** | 循环外 `$n = (int)count($arr)` 再用 `$n` 比较 |
| **禁用 `$GLOBALS`** | 使用 `static` 局部变量或删除调试代码 |
| **`foreach` 键类型问题** | 遍历 `array` 属性时用 `for` + 索引替代 |
| **属性必须完整标注** | 所有属性和方法参数必须有类型声明 |

---

## 十五、Property Hook 触发 `set`：`setBindValue` 必须显式转型

### 问题

在 `use native_types` 模式下，`setBindValue(string $bindKey, string $value)` 将字符串直接赋值给 `int`/`bool` 类型的 Property Hook 属性，触发 AOT 类型错误：

```
Fatal error: Cannot assign string to property AppComponent::$counter of type int
```

### 根因

SFC 编译器生成的 `setBindValue` 未根据属性的 PHP 类型添加显式转型。当父组件传递 `:counter="..."` 绑定值时，产生的 `$this->counter = $value` 赋值在 AOT 下被严格类型检查拦截。

### 解决方案

`generateSetBindValue()` 必须根据 `$reactiveProps` 中收集的属性类型添加转型：

```php
// generateSetBindValue 中的类型转型逻辑
if (isset($reactiveTypes[$key])) {
    $t = $reactiveTypes[$key];
    if ($t === 'int')   { $cast = '(int)'; }
    elseif ($t === 'bool')  { $cast = '(bool)'; }
    elseif ($t === 'float') { $cast = '(float)'; }
}
// 生成: if ($this->counter !== (int)$value) { $this->counter = (int)$value; }
```

### 影响范围

- `framework/Compiler/sfc-compiler.php` — `generateSetBindValue()` 函数
- 所有带有 `#[Reactive] public int $prop` 声明且通过 `:prop="..."` 传递值的组件

---

## 十六、SFC 编译器：`extractReactiveProperties()` 必须在 `generateSetBindValue()` 之前调用

### 问题

`compileOneComponent()` 中 `generateSetBindValue()` 在第 2479 行调用，但 `extractReactiveProperties()` 在第 2489 行才执行。顺序倒置导致 `$reactiveProps` 为空，上文第 十五 节的类型转型代码无法生效，所有 int/bool 属性在 `setBindValue` 中缺少转型。

```php
// 错误顺序（SFC 编译器 v1）
$setBindValue = generateSetBindValue($bindKeys, $arrayBindKeys, $reactiveProps ?? []);  // line 2479
// ... 10 行代码 ...
$reactiveProps = $analyzer->extractReactiveProperties($script);  // line 2489
```

### 解决方案

调整代码顺序，确保 `extractReactiveProperties()` 在 `generateSetBindValue()` 之前执行：

```php
// 正确顺序（SFC 编译器 v2）
$reactiveProps = $analyzer->extractReactiveProperties($script);  // 先提取
$setBindValue = generateSetBindValue($bindKeys, $arrayBindKeys, $reactiveProps);  // 再生成（带转型）
```

### 影响范围

- `framework/Compiler/sfc-compiler.php` — `compileOneComponent()` 函数内部代码重组（commit `dbf77691`）
- 同样影响 inline pipeline 路径（`$reactiveProps` 提取在 3182 行，需验证顺序）

---

## 十七、`Effect::schedule()`：`dirty=true` 必须在 pending 防重入之前执行

### 问题

`Effect::schedule()` 中 `$this->pending` 防重入检查在 `$this->component->dirty = true` 之前，导致同一微任务中连续多次属性赋值只触发一次 dirty：

```php
// 错误顺序：pending 守卫在前
public function schedule(): void {
    if ($this->pending) { return; }  // 先防重入
    $this->pending = true;
    $this->component->dirty = true;   // 后标记 dirty
    // 连续两次 set 时，第二次因 pending=true 直接 return，dirty 未被覆盖
}
```

AOT 编译时此问题表现为：部分属性修改后不触发 VNode 重建，UI 状态停留在中间值。

### 解决方案

将 `dirty=true` 移到 pending 检查之前，确保每次 set 都覆盖 dirty 标记：

```php
public function schedule(): void {
    // 同步失效 VNode 缓存 — 必须在 pending 检查之前执行
    if ($this->component !== null) {
        $this->component->dirty = true;
    }
    if ($this->pending) { return; }  // pending 仅控制微任务入队，不控制 dirty
    $this->pending = true;
    // ... 入队微任务 ...
}
```

### 影响范围

- `framework/Reactive/Effect.php` — `schedule()` 方法
- `framework/Reactive/DependencyTracker.php` — `notify()` 调用 `schedule()` 的路径
- 任何通过 Property Hook `set` → `DependencyTracker::notify()` → `Effect::schedule()` 的更新链

---

## 总结：AOT 编码规范（完整版）

| 规则 | 说明 |
|------|------|
| **禁止命名参数** | 构造函数/方法始终使用位置参数 |
| **禁止 `toArray()` 方法名** | 使用 `serializeNode()` 等替代名称 |
| **抽象方法必须完整实现** | 每个子类必须显式实现所有抽象方法 |
| **typed property 必须初始化** | `int $x = 0` 而非 `int $x` |
| **深度空安全链拆解** | `?->` 不超过 2 层 |
| **null 不能传给 `int` 参数** | 使用默认值 `0` 而非 `null` |
| **动态属性不可用** | 状态外置到 Map/Array/SplObjectStorage |
| **读取加 `??` 保护** | `$node->x ?? 0` 而非 `$node->x` |
| **避免 Builder 模式** | 直接构造 DTO，不链式调用 |
| **`count()` 预缓存为 `(int)`** | 循环外 `$n = (int)count($arr)` 再用 `$n` 比较 |
| **禁用 `$GLOBALS`** | 使用 `static` 局部变量或删除调试代码 |
| **`foreach` 键类型问题** | 遍历 `array` 属性时用 `for` + 索引替代 |
| **属性必须完整标注** | 所有属性和方法参数必须有类型声明 |
| **Property Hook `set` 转型** | `setBindValue` 中对 int/bool 属性加 `(int)`/`(bool)` 转型 |
| **`dirty` 标记先于 pending 检查** | `Effect::schedule()` 中 `dirty=true` 必须在 `pending` 守卫之前 |
