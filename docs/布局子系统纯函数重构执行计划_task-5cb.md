# 布局子系统纯函数重构 — 执行计划

## 总览

- **模式**：零兼容（旧接口在替换的同一提交中删除）
- **工时**：14-20 天
- **分 4 个 Phase**，每个 Phase 完成后代码可编译、测试通过

---

## Phase 0 — 准备：安全网（2-3 天）

**原则**：只新增不删除，建立基线。

### Task 0.1: 记录测试基线

```bash
php tests/unit/LayoutResolverTest.php > _baseline_layout_resolver.txt
php tests/unit/LayoutEngineTest.php > _baseline_layout_engine.txt
```

### Task 0.2: 新增文件

#### `framework/Rendering/Layout/LayoutResult.php`

从 LayoutFragment 演进，全字段 `public readonly`。

```
namespace Px\Rendering\Layout;

class LayoutResult {
    public readonly int $x, $y, $w, $h;
    public readonly int $visualW, $visualH;
    public readonly int $layer;
    public readonly int $contentWidth, $contentHeight;
    public readonly ?ComputedStyle $style;
    public readonly array $children;  // LayoutResult[]

    public function __construct(...) { /* 同 LayoutFragment 构造函数 */ }
    public function toArray(): array;       // 测试断言用
}
```

> 注意：`$children` 类型改为 `LayoutResult[]`，不再引用 LayoutFragment。

#### `framework/Rendering/Layout/LayoutInput.php`

```
namespace Px\Rendering\Layout;

class LayoutInput {
    public readonly LayoutConstraints $constraints;
    public readonly ComputedStyle $style;
    public readonly string $textContent;
    public readonly array $childResults;  // LayoutResult[]

    // 定位祖先信息（AbsoluteStrategy 需要）
    public readonly ?string $position;
    public readonly ?int $ancestorX;
    public readonly ?int $ancestorY;
    public readonly ?int $ancestorW;
    public readonly ?int $ancestorH;

    public function __construct(...) { /* 全部 readonly 赋值 */ }
}
```

#### `framework/Rendering/Layout/LayoutApplicator.php`

```
namespace Px\Rendering\Layout;

class LayoutApplicator {
    /** 单向同步，返回被修改的节点列表 */
    public function apply(LayoutResult $result, RenderNode $node): array;
}
```

apply() 逐字段比较并赋值，递归处理 children。不做任何计算。

#### `tests/verify_layout_pure.php` — 纯函数测试框架

```php
function assertLayoutResult(string $label, LayoutResult $r, array $expected): void {
    foreach ($expected as $k => $v) {
        assert($r->$k === $v, "[FAIL] $label.$k: expected $v, got {$r->$k}");
    }
}

// 测试示例（Phase 1 实现后可用）：
function testBlockWidthFillsContainer(): void {
    $strategy = new BlockLayoutStrategy();
    $input = new LayoutInput(
        constraints: new LayoutConstraints(containerWidth: 200),
        style: new ComputedStyle(['width' => '100%']),
    );
    $result = $strategy->layout($input);
    assertLayoutResult('Block fill', $result, ['w' => 200]);
}
```

### Phase 0 验证

- 现有全部测试通过，输出 hash 与基线匹配
- 新增文件可被 `require_once` 加载不报错

---

## Phase 1 — 布局核心重写（8-10 天）

**原则**：一次性替换接口 + 全部策略 + LayoutResolver，旧文件/旧方法在同一次操作中删除。

### Task 1.1: 接口层 — 3 个接口变更

#### `framework/Rendering/Layout/LayoutStrategyInterface.php`

```php
// ── 删除 ──
public function resolveWithBuilder(RenderNode, LayoutConstraints, ?ComputedStyle, FragmentBuilder): void;

// ── 新增 ──
public function layout(LayoutInput $input): LayoutResult;
```

接口无 Import `RenderNode`、`FragmentBuilder` 的引用。

#### `framework/Rendering/Layout/AbsoluteStrategy.php`

```php
// ── 删除 ──
public function resolveAbsolutePositioning(RenderNode, LayoutConstraints, ?ComputedStyle, FragmentBuilder): void;
public function resolveMarginAuto(RenderNode, ?ComputedStyle, int, int): void;

// ── 新增 ──
public function absoluteLayout(LayoutInput $input): LayoutResult;
```

#### 删除 `framework/Rendering/Layout/FragmentBuilder.php`

整文件删除。

### Task 1.2: 策略层 — 7 个策略重写

每个策略的模式相同：
- 删除旧入口方法 `resolveXxxLayout()` / `resolveWithBuilder()`
- 新增 `layout(LayoutInput $input): LayoutResult`
- 内部所有 `$node->x = ...` 改为局部变量
- 不再接收 `LayoutResolver $resolver` 构造参数（通过 LayoutInput 传入所需信息）

#### `BlockLayoutStrategy.php`

```
改动：
  删除：resolveWithBuilder(), resolveBlockLayout()
  新增：layout(LayoutInput): LayoutResult
  新增辅助：computeWidth(), computeHeight(), stackBlockChildren() （纯局部变量，不碰 RenderNode）
  保留自用的 clampWidth / clampHeight 闭包 → 改为普通方法

细节：
  - 文本测量所需 $node->content 从 LayoutInput.textContent 获取
  - 子节点 auto-stack 基于 LayoutInput.childResults 计算
  - 布局结果坐标全部通过局部变量计算，最后 new LayoutResult(...)

行数：约 530 行 → 约 450 行（删除 builder 相关 + RenderNode 写入）
```

#### `FlexLayoutStrategy.php`

```
改动：
  删除：resolveWithBuilder(), resolveFlexLayout()
  新增：layout(LayoutInput): LayoutResult

细节：
  - FlexItem 构造不再需要 RenderNode，直接使用 childResults 的数据
  - FlexItemCollector / FlexLineBreaker / FlexDistributor 保持纯数据操作
  - FlexFragmentMapper 改为产出 LayoutResult[] 而非 LayoutFragment[]

行数：约 676 行 → 约 580 行
```

#### `FlexFragmentMapper.php`

```
改动：
  toFragments() 返回类型从 LayoutFragment[] 改为 LayoutResult[]
  内部 new LayoutResult(...) 替代 new LayoutFragment(...)
  rebuildChildren() 同更改

注意：此文件仍引用 LayoutFragment（在类型标注中），Phase 3 最终删除
```

#### `GridLayoutStrategy.php`

```
改动：
  删除：resolveWithBuilder(), resolveGridLayout()
  新增：layout(LayoutInput): LayoutResult

行数：约 756 行 → 约 650 行
```

#### `InlineLayoutStrategy.php`

```
改动：
  删除：resolveWithBuilder(), resolveInlineLayout()
  新增：layout(LayoutInput): LayoutResult

行数：约 197 行 → 约 160 行
```

#### `TableLayoutStrategy.php`

```
改动：
  删除：resolveWithBuilder(), resolveTableLayout()
  新增：layout(LayoutInput): LayoutResult

行数：约 226 行 → 约 180 行
```

#### `MultiColumnLayoutStrategy.php`

```
改动：
  删除：resolveWithBuilder(), resolveMultiColumnLayout()
  新增：layout(LayoutInput): LayoutResult

行数：约 196 行 → 约 150 行
```

#### `AbsolutePositioning.php`

```
改动：
  删除：AbsoluteStrategy 的两个方法
  新增：absoluteLayout(LayoutInput): LayoutResult

重点：
  - 不再持有 LayoutResolver $resolver 引用
  - 定位祖先坐标通过 LayoutInput.ancestorX/Y/W/H 传入
  - fixed 定位所需 viewport 尺寸通过 LayoutInput 传入
  - resolveMarginAuto 改为私有纯辅助方法，不写 RenderNode，返回偏移量

行数：约 277 行 → 约 240 行
```

### Task 1.3: LayoutResolver 重写

`framework/Rendering/LayoutResolver.php`

```
删除的方法：
  resolveNodeInternal()       → 被 resolveFragment() 替代
  resolveChildren()           → 在 resolveFragment 内联
  resolveCurrentChildren()    → 不再需要
  rebuildChildFragments()     → 不再需要
  getRootNode()               → 不再需要

新增/保留的方法：
  resolve(RenderNode): LayoutResult     ← 入口（signature 不变）
  resolveFragment(RenderNode, Constraints): LayoutResult  ← 纯函数递归

resolve() 内部结构：
  Phase A: result = resolveFragment(root, constraints)
  Phase B: applicator.apply(result, root)
  Phase C: postProcessScrollContainers(root)  ← 原有滚动/postProcess
  Phase D: root.layoutDirty = false
  return result

resolveFragment() 内部结构：
  1. 递归子节点 → LayoutResult[]
  2. 构建 LayoutInput
  3. selectStrategy(display, position)
  4. return strategy.layout(input)     ← 纯函数调用

构造器变更：
  不再给策略传 $this（策略不再需要 LayoutResolver 引用）
  AbsolutePositioning 也不再需要 LayoutResolver 引用
```

### 变更涉及的外部文件

以下文件引用了 `FragmentBuilder` 或旧方法，需同步修改：

| 文件 | 改动 |
|------|------|
| `Application.php` | 检查是否直接操作 LayoutResolver |
| `RenderTreeManager.php` | 确保无 FragmentBuilder 引用 |
| `VNodeRenderer.php` | 确保无 FragmentBuilder 引用 |
| `tests/unit/LayoutResolverTest.php` | 测试函数直接断言 LayoutResult |
| `tests/unit/LayoutEngineTest.php` | 同上 |

### Phase 1 验证

```bash
# 1. 编译检查（无 FragmentBuilder 残留）
php -l framework/Rendering/Layout/*.php

# 2. AOT 检查
php framework/aot-checker.php --project .   # 0 error

# 3. 布局测试 hash 与基线一致
php tests/unit/LayoutResolverTest.php | sha256sum -> 匹配 Phase 0 基线
php tests/unit/LayoutEngineTest.php    | sha256sum -> 匹配 Phase 0 基线

# 4. 策略内无 $node->x/y/w/h 写入
grep -n '\$node->\(x\|y\|w\|h\|visualW\|visualH\)\s*=' \
  framework/Rendering/Layout/BlockLayoutStrategy.php \
  framework/Rendering/Layout/FlexLayoutStrategy.php \
  framework/Rendering/Layout/GridLayoutStrategy.php \
  framework/Rendering/Layout/InlineLayoutStrategy.php \
  framework/Rendering/Layout/TableLayoutStrategy.php \
  framework/Rendering/Layout/MultiColumnLayoutStrategy.php
  # 每一行输出都应是布局相关表达式（如 $node->computedStyle 读取），不应是赋值

# 5. grep 确认 LayoutResolver 中无 FragmentBuilder/rebuildChildFragments
grep -n 'FragmentBuilder\|rebuildChildFragments\|resolveNodeInternal\|resolveChildren' \
  framework/Rendering/LayoutResolver.php
  # 输出应为空
```

---

## Phase 2 — RenderNode 瘦身 + 状态剥离（3-5 天）

### Task 2.1: 新增状态类

#### `framework/Rendering/ScrollState.php`

```php
namespace Px\Rendering;

class ScrollState {
    public int $scrollTop = 0;
    public int $scrollLeft = 0;
    public int $lastScrollTop = 0;
    public int $contentWidth = 0;
    public int $contentHeight = 0;
    public bool $isScrollContainer = false;
}
```

#### `framework/Rendering/InteractionState.php`

```php
namespace Px\Rendering;

class InteractionState {
    public bool $hovered = false;
    public bool $focused = false;
    public bool $active = false;
}
```

### Task 2.2: RenderNode 删除字段

从 `RenderNode.php` 删除以下 10 个字段：

| 字段 | 移到 |
|------|------|
| scrollTop | ScrollState |
| scrollLeft | ScrollState |
| lastScrollTop | ScrollState |
| renderOffsetX | VNodeRenderer 局部累加 |
| renderOffsetY | VNodeRenderer 局部累加 |
| hovered | InteractionState |
| focused | InteractionState |
| active | InteractionState |
| textRenderInfo | VNodeRenderer 局部数组 |
| lastPaintFrame | VNodeRenderer SplObjectStorage |
| animatedStyle | AnimationManager 已独立 |
| isAnimating | AnimationManager 已独立 |
| lastX | AnimationManager 已独立 |
| lastY | AnimationManager 已独立 |

RenderNode 从 17 个业务字段减为 **10 个**：
`type, content, key, dataset, groupId, sourceVNode, computedStyle, parent, children, x, y, w, h, visualW, visualH, layer, contentWidth, contentHeight, isScrollContainer, layoutDirty`

### Task 2.3: 更新外部引用

按下表更新所有引用已删除字段的代码：

| 字段 | 旧读取方式 | 新读取方式 |
|------|-----------|-----------|
| `node->scrollTop` | `$node->scrollTop` | `$scrollManager->getScrollState($node)->scrollTop` |
| `node->hovered` | `$node->hovered` | `$app->getInteractionState($node)->hovered` |
| `node->renderOffsetX` | `$node->renderOffsetX` | VNodeRenderer 遍历时局部累加 |

受影响文件列表：

```
ScrollManager.php           → 持有 Map<RenderNode, ScrollState>
Application.php             → 持有 Map<RenderNode, InteractionState>
VNodeRenderer.php           → renderOffsetX/Y 改为走遍历栈
AnimationManager.php        → 已独立，确认无冗余引用
```

### Phase 2 验证

```bash
# 1. 确认字段已删除
grep -r '\->scrollTop' framework/Rendering/RenderNode.php   # 输出为空
grep -r '\->hovered' framework/Rendering/RenderNode.php     # 输出为空

# 2. 编译和 AOT 检查
php framework/aot-checker.php --project .   # 0 error

# 3. 模块独立测试
php tests/unit/ScrollManagerTest.php        # 全部 PASS
```

---

## Phase 3 — 脏标记上移 + 扫尾（1-2 天）

### Task 3.1: 脏标记上移

`LayoutResolver.php` 修改：

```php
// ── 之前：脏标记检查在 resolveFragment 内部 ──
private function resolveFragment(...): LayoutResult {
    if (!$node->layoutDirty) {
        // 洁净路径混合在纯函数中
    }
    // 脏路径
}

// ── 之后：脏标记在 resolve() 入口 ──
public function resolve(RenderNode $root): LayoutResult {
    if (!$root->layoutDirty) {
        // 仅递归处理子树中脏的节点，不做完整重算
        $this->resolveDirtyDescendantsOnly($root);
        return LayoutResult::fromNode($root);  // 从现有值构造 Result
    }

    $constraints = new LayoutConstraints($root->w, $root->h, ...);
    $result = $this->resolveFragment($root, $constraints);  // 纯计算
    $this->applicator->apply($result, $root);               // 回写
    $this->postProcess($root, $result);                     // 后处理
    $root->layoutDirty = false;
    return $result;
}

// resolveFragment() 不再有 if (layoutDirty) 分支
private function resolveFragment(...): LayoutResult {
    // 永远是完整计算
}
```

`LayoutResult` 新增工厂方法：

```php
public static function fromNode(RenderNode $node): self {
    $children = [];
    foreach ($node->children as $ch) {
        $children[] = self::fromNode($ch);
    }
    return new self(
        x: $node->x, y: $node->y,
        w: $node->w, h: $node->h,
        visualW: $node->visualW, visualH: $node->visualH,
        layer: $node->layer,
        contentWidth: $node->contentWidth,
        contentHeight: $node->contentHeight,
        style: $node->computedStyle,
        children: $children,
    );
}
```

### Task 3.2: 删除 `LayoutFragment.php`

grep 确认无引用后整文件删除。

```bash
grep -r 'LayoutFragment' framework/ --include="*.php" | grep -v 'archive'
# 若能找到任何引用，判断是否可替换为 LayoutResult。
# 删除后，LayoutFragment 不再存在于代码库（除 archive 目录外）。
```

### Task 3.3: 最终状态确认

```bash
# 无 FragmentBuilder 引用
grep -r 'FragmentBuilder' framework/       # 输出为空

# 无 LayoutFragment 引用（archive 目录除外）
grep -r 'LayoutFragment' framework/ tests/  # 输出为空

# 布局测试全部通过
php tests/unit/LayoutResolverTest.php | sha256sum -> 匹配 Phase 0 基线

# 纯函数测试
php tests/verify_layout_pure.php            # 全部 PASS
```

---

## 文件变更汇总

### 删除文件（3 个）

| 文件 | Phase |
|------|-------|
| `framework/Rendering/Layout/FragmentBuilder.php` | Phase 1 |
| `framework/Rendering/Layout/LayoutFragment.php` | Phase 3 |
| `tests/verify_layout_pure.php` | —（新增后保留） |

### 新增文件（5 个）

| 文件 | Phase |
|------|-------|
| `framework/Rendering/Layout/LayoutResult.php` | Phase 0 |
| `framework/Rendering/Layout/LayoutInput.php` | Phase 0 |
| `framework/Rendering/Layout/LayoutApplicator.php` | Phase 0 |
| `framework/Rendering/ScrollState.php` | Phase 2 |
| `framework/Rendering/InteractionState.php` | Phase 2 |

### 重写文件（9 个）

| 文件 | 行数变化 |
|------|---------|
| `LayoutStrategyInterface.php` | 33 → 20 |
| `AbsoluteStrategy.php` | 40 → 20 |
| `BlockLayoutStrategy.php` | 531 → 450 |
| `FlexLayoutStrategy.php` | 676 → 580 |
| `GridLayoutStrategy.php` | 756 → 650 |
| `InlineLayoutStrategy.php` | 197 → 160 |
| `TableLayoutStrategy.php` | 226 → 180 |
| `MultiColumnLayoutStrategy.php` | 196 → 150 |
| `AbsolutePositioning.php` | 277 → 240 |
| `LayoutResolver.php` | 600 → 250 |
| `Flex/FlexFragmentMapper.php` | 84 → 80 |

### 修改文件（4 个）

| 文件 | 改动 |
|------|------|
| `RenderNode.php` | 删除 10 个字段 |
| `ScrollManager.php` | 新增 `ScrollState` 映射 |
| `Application.php` | 新增 `InteractionState` 映射 |
| `VNodeRenderer.php` | renderOffset 改为局部计算，lastPaintFrame 改为 SplObjectStorage |

---

## 验收标准

```bash
# 1. AOT 编译零错误
php framework/aot-checker.php --project .   # 0 error

# 2. 布局测试与 Phase 0 基线一致
php tests/unit/LayoutResolverTest.php | sha256sum
php tests/unit/LayoutEngineTest.php | sha256sum

# 3. 纯函数布局测试
php tests/verify_layout_pure.php            # 全部 PASS

# 4. 无陈旧代码残留
grep -r 'FragmentBuilder' framework/        # 输出为空
grep -r 'LayoutFragment' framework/ tests/  # 输出为空
grep -n '\$node->\(x\|y\|w\|h\)\s*=' framework/Rendering/Layout/*.php
    # 仅 AbsolutePositioning 可保留（通过 LayoutInput 间接引用）

# 5. RenderNode 删除的字段无引用
grep -r '\->scrollTop\b' framework/Rendering/RenderNode.php     # 空
grep -r '\->hovered\b' framework/Rendering/RenderNode.php       # 空
grep -r '\->renderOffsetX\b' framework/Rendering/RenderNode.php  # 空
```
