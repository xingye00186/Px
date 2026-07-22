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

### 编译器更新
最新 Swoole Compiler 已解决以下相关问题：
- **`readonly` 属性带默认值**：`public readonly int $val = 0;` 直接编译通过（PHP 8.4 原生语法不允许，编译器已放宽限制）。
- **跨类 `readonly` 直接访问**：`use native_types` 类的 `readonly` 属性在另一 `native_types` 类中直接读取正常（无需 getter）。

验证方式：
- `apps/aot-cross-readonly/` — exe 运行输出"所有方式正常 ✅"
- `apps/aot-syntax-test/` G17 — `public readonly int $intVal = 42` 4/4 PASS

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

## 十八、`ComputedStyle` 150+ readonly 属性构造成本（性能约束，非 bug）

### 现象

L4「分离 Style Recalc」尝试中，将 `:style` 合并 + `new ComputedStyle` 从 RenderTreeManager 上提到 SRP，使得 400 cell/frame（TextHeavy）每帧全部走 `new ComputedStyle`，观察到帧时长从 baseline 0.7s 涨至 3.0s（4x 回归）。

### 根因

`ComputedStyle` 在 `use native_types` 下：
- 150+ readonly 属性 + CssValue 封装的构造函数
- 每个属性带类型断言与默认值初始化
- 单次 `new ComputedStyle` 在 AOT 下 ≈ 250μs（相比 PHP 原生 ~50μs 有 ~5x 开销，因每个 readonly 属性都走一次类型检查 + memory-fence 写）
- 400 cell × 250μs ≈ 100ms/frame

### 编码约束

1. **`ComputedStyle` 不宜在每帧热路径批量构造**——单实例开销固定不可优化，唯一路径是 Flyweight 复用（按 `(className, inlineStyleHash, parentStyleHash)` memoize）
2. **不要把 lazy 合并路径提升为 eager 全量**——baseline 只在有 inline `:style` 的 cell 才构造新 `ComputedStyle`，SRP 层走 class cache 命中直接复用；反之全量构造会击穿 lazy 分工
3. **不要在 `ComputedStyle` 上再套一层 pass 缓存**——只会增加分支/字段访问开销，而不减少构造本身

### 影响范围

- `framework/Css/StyleResolver.php` — L62 无条件 `new ComputedStyle(...)`（当前 baseline 已通过 class cache 缩小到必要的 cell）
- 任何试图「先构造再比较是否需要更新」的路径都会触发此约束——**先比较后构造**才是正确顺序

---

## 十九、PHP 数组 `===` 结构化比较在 `use native_types` 类中的 O(n) 成本

### 现象

L4 脏门控方案中，在 SRP 出口对新旧 `:style` 数组（typically 6-10 键）做 `if ($newStyle === $oldStyle) skip`，实测 skip 路径 5-6μs/node，**高于 baseline 完整 `StyleResolver::resolve()` 本身的 0.44μs/node**（class cache 命中情形），反而拉高总耗时。

> **先行辨析**：本节 O(n) 仅限 **PHP 数组** `===`（结构化递归比较）。**PHP 对象** `===` 本身就是 O(1) 指针比较，AOT `use native_types` 下与 C++ pointer 比较等价。L4 需对数组而非对象做门控，是因为当前 `StyleResolver::resolve()` 每次 `new ComputedStyle` 产新指针，无规范对象身份可供门控，只能退回到输入侧数组。详见 `docs/Vue3_Blink_融合架构决策追踪.md` §4.4。

### 根因

PHP 数组 `===` 是结构化比较：
- 对 array 的 key 与 value 逐项递归比较（O(n)，n = 键数）
- AOT `use native_types` 下每项走类型断言 + php::Var 桥接
- 6-10 键 `:style` array 每次 `===` ≈ 1μs
- 加上 PerfCounter::inc 递归开销 + 分支预测失效 ≈ 5-6μs/node

### 编码约束

1. **对小数组（<50 键）的 `===` 门控不划算**——门控成本可能超过被门控的原始成本，L4 postmortem 已确认
2. **区分 PHP 数组与 PHP 对象**：对象 `===` 是 O(1) 指针比较（与 C++ 等价），可安全用于热路径门控；仅数组 `===` 是 O(n)。优先把门控层从数组上移到对象，而非避开 `===`
3. **门控前先估算 baseline 单节点成本**：若 baseline ≤ 5μs/node，加任何形式的数组 `===` 门控都会回归
4. **改用 hash 比较**：若必须对数组门控，用 `spl_object_hash` 或 pre-computed integer hash（O(1)），而非结构化 `===`
5. **优先复用引用（Flyweight）**：让上游产出引用等价的对象（如 ComputedStyle Flyweight），门控从「对输入侧数组 O(n) 比较」升级为「对输出侧对象 O(1) `===`」，此时才有正收益

### 影响范围

- 任何形如 `if ($newProps === $oldProps) { skip }` 的热路径门控——`patchVNodeTree` / `patchKeyedChildren` / `updateFromVNode` 内部都不采用此模式，改用 `patchFlags` 位标记跳过
- `Effect::schedule()` 中的重入检查用 `bool $pending`（O(1)），不用数组比较

---

## 二十、`PerfCounter::inc` 在 `use native_types` 递归函数中的调用开销

### 现象

L4 postmortem 分析中确认 `PerfCounter::inc` / `PerfCounter::start` / `PerfCounter::end` 在递归 layout / DFS 遍历中每次调用 ≈ 0.3-0.5μs（含 `PX_PERF` 检查 + 数组写 + microtime 调用）。在 400 cell × 5 hook（enter/exit each phase）= 2000 次/frame 场景下 ≈ 600μs-1ms/frame，非零开销。

### 根因

- `PerfCounter::inc` 内部走 `$counters[$name] ??= 0; $counters[$name]++;` 数组访问（php::Var → int 转换 + 数组桥接）
- `PerfCounter::start/end` 附加 `microtime(true)` 系统调用（几百 ns）
- `use native_types` 类中调用静态方法有轻微 vtable 开销

### 编码约束

1. **热路径（>100 次/frame）避免 unconditional `PerfCounter::inc`**——用 `if (PX_PERF) PerfCounter::inc(...)` 或包在 debug 分支
2. **PerfCounter 不要放在深递归中每节点触发**——放在 phase 级（enter/exit BlockAlgorithm 一次）而非 node 级（enter/exit each child）
3. **诊断阶段用完立即拆除**：`PerfCounter` 是诊断工具，不是永久监控。L4 postmortem 揭示 debug 桩埋在热路径会掩盖真实 baseline，必要时改用 `PX_PERF=1` 环境变量门控整个诊断代码块
4. **避免在 recursive `use native_types` 类中打点**：`LayoutOrchestrator` / `StyleRecalcPass` / `PaintPipeline` 的递归 DFS 内不打 node 级 PerfCounter；只在 top-level 入口打 stage 级

### 影响范围

- 所有 `framework/Layout/*Algorithm.php` — layout() 递归内不放 PerfCounter（只在 orchestrator 入口）
- `framework/Css/StyleRecalcPass.php` — DFS 内不放 PerfCounter（只在入口）
- `framework/Reactive/Effect.php` — schedule() 是高频入口，任何 hook 都需评估

---

## 二十一、低成本项优化的通用铁律（L4 postmortem 归纳）

### 铁律

**当一个 stage/pass 的 baseline < 200μs/frame 时，任何形式的门控/缓存/Flyweight 都需先验证「门控开销 < 被跳过部分开销」**，否则触发 3-50x 回归。

### 根因（AOT 下三条系统性开销）

1. **PHP 数组 `===` O(n) 比较** — 见 §十九
2. **`PerfCounter::inc` 递归调用开销** — 见 §二十
3. **CPU pipeline / I-cache 破坏** — baseline 简单线性 walk 对 I-cache / branch predictor 最友好；引入分支 + 字段访问 + 自定义缓存字段的门控代码反而使 IPC 下降

### 已验证会触发回归的模式

| 模式 | 试验对象 | 回归幅度 |
|---|---|---|
| SRP 出口按 `:style` 数组 `===` 门控 | L4 v3/v4/v5 | +330% (0.7s → 3.0s) |
| `patchChildrenArray` 无 key 位置匹配（对齐 Vue 3 patchUnkeyedChildren） | ReactiveComponent | +25~48% (6 case 全回归) |
| Node 级 PerfCounter 在 layout 递归内 | L4 诊断桩 | +30~60% |

### 已验证不触发回归的模式（正例）

| 模式 | 试验对象 | 收益 |
|---|---|---|
| 组件级 dirty 门控（skip 整棵 RN 子树 rebuild） | L1 (`fb05d4bb`) | -30~80% |
| Keyed children O(n) diff（head/tail 双端 + key map） | L3 (`ba49097c`) | -20~50% |

### 决策规则

1. **门控设计要覆盖粗粒度**：至少覆盖 100+ node 的子树才划算，node 级门控几乎必回归
2. **门控 key 必须 O(1)**：整型 hash / bool flag / patchFlag 位，而非数组 `===` / string 比较
3. **门控在 baseline 数据支持下才做**：先测 baseline < 200μs 的 stage，直接放弃门控路线；转向 Flyweight（-125μs style_recalc）或结构改造（Block Tree -1500μs vnode_tree）

### 影响范围

- 所有热路径（>100 次/frame）的优化提案必须先经过 baseline 测量与门控成本估算
- `docs/rendering-optimization-strategy.md` §八四大恒定地板 已按此规则筛选后续优化方向

---

## 总结：AOT 编码规范（完整版）

### 正确性规则（触发编译错误或运行时错误）

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

### 性能规则（触发 3-50x 回归但不报错）

| 规则 | 说明 | 相关章节 |
|------|------|------|
| **热路径不批量 `new ComputedStyle`** | 250μs/次 × 400 cell = 100ms/frame；只在必要 cell 走 lazy 合并；跨节点 memoize | §十八 |
| **热路径不用数组 `===` 做门控** | 6-10 键 array `===` ≈ 1μs，加桩后 5-6μs/node，高于 baseline 0.44μs/node；必要时用整型 hash 或 `patchFlags` | §十九 |
| **递归函数内不放 node 级 `PerfCounter`** | 0.3-0.5μs/次 × 2000 次 = 600μs-1ms/frame；只在 stage/phase 入口打点 | §二十 |
| **baseline <200μs 的 stage 不加门控** | 门控开销可能大于被跳过部分；优先 Flyweight 或结构改造 | §二十一 |
| **门控粒度 ≥ 100 node** | 组件级/子树级门控（L1/L3 已验证）；node 级门控几乎必回归 | §二十一 |
| **`ComputedStyle` 层要 Flyweight 化** | 按 `(className, inlineStyleHash, parentStyleHash)` memoize，让 `===` 门控退化为 O(1) 引用比较 | §十八, §十九 |
