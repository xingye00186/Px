# 多阶段布局架构 — 实施计划

## 总览

- **基础**：纯函数化布局架构已就绪（LayoutResult + LayoutInput + LayoutApplicator）
- **模式**：零侵入——策略接口 `layout(LayoutInput): LayoutResult` 不改变
- **能力注入**：通过 LayoutInput 新增回调字段
- **分 3 个 Phase**，每个 Phase 完成后代码可编译、测试通过

---

## Phase 0 — 接口增强（2-3 天）

**原则**：只新增字段/方法，不修改现有接口签名。

### Task 0.1: LayoutInput 新增字段

`framework/Rendering/Layout/LayoutInput.php`

```php
// 新增字段（已有字段不变）
public readonly array $childNodes;           // RenderNode[] 子节点引用
public readonly ?Closure $reResolveChild;    // fn(RenderNode, Constraints) → LayoutResult
public readonly ?Closure $measureIntrinsic;  // fn(RenderNode) → LayoutResult
public readonly int $iteration = 0;          // 当前迭代轮次
```

构造参数尾部增加可选参数，默认 null/0，不破坏现有调用方。

### Task 0.2: LayoutResult 新增元数据字段

`framework/Rendering/Layout/LayoutResult.php`

```php
// 新增字段（已有字段不变）
public readonly bool $needsAnotherPass = false;
public readonly int $minContentWidth = 0;
public readonly int $maxContentWidth = 0;
public readonly int $preferredContentWidth = 0;
public readonly int $minContentHeight = 0;
public readonly int $maxContentHeight = 0;
public readonly int $preferredContentHeight = 0;
```

构造参数尾部增加可选参数，默认 false/0。

### Task 0.3: LayoutConstraints 新增测量模式

`framework/Rendering/Layout/LayoutConstraints.php`

```php
// 新增字段
public readonly bool $isIntrinsicMeasurement = false;
```

### Task 0.4: LayoutResolver 新增 callbacks

`framework/Rendering/LayoutResolver.php`

```php
// 新增公有方法
public function reResolveChild(RenderNode $child, LayoutConstraints $newConstraints): LayoutResult {
    return $this->resolveFragment($child, $newConstraints);
}

public function measureIntrinsic(RenderNode $node): LayoutResult {
    $constraints = new LayoutConstraints(
        containerWidth: PHP_INT_MAX,
        containerHeight: PHP_INT_MAX,
        isIntrinsicMeasurement: true,
    );
    return $this->resolveFragment($node, $constraints);
}
```

在 `resolveFragment` 中构造 LayoutInput 时注入回调：

```php
private function resolveFragment(...): LayoutResult {
    // ... 子节点递归 ...
    $input = new LayoutInput(
        constraints: $constraints,
        style: $style,
        childResults: $childResults,
        childNodes: $node->children,
        reResolveChild: fn($c, $nc) => $this->reResolveChild($c, $nc),
        measureIntrinsic: fn($c) => $this->measureIntrinsic($c),
        iteration: 0,
        // ... 已有字段不变 ...
    );
    $result = $strategy->layout($input);
    return $result;
}
```

### Phase 0 验证

```bash
php -l framework/Rendering/Layout/LayoutInput.php
php -l framework/Rendering/Layout/LayoutResult.php
php -l framework/Rendering/Layout/LayoutConstraints.php
php -l framework/Rendering/LayoutResolver.php
php tests/unit/LayoutResolverTest.php  # hash 与当前基线一致
```

---

## Phase 1 — 迭代调度 + Flex 补齐（4-5 天）

### Task 1.1: LayoutResolver 迭代调度

`framework/Rendering/LayoutResolver.php`

```php
private function resolveFragment(RenderNode $node, LayoutConstraints $constraints,
    int $inheritedLayer = 0, ?int $parentX = null, ?int $parentY = null,
    int $iteration = 0): LayoutResult      // 新增 iteration 参数
{
    // ... 现有逻辑 ...

    // 策略调用后检查 needsAnotherPass
    if ($result->needsAnotherPass && $iteration < 5) {
        // 用更新后的 childResults 再次调用
        return $this->resolveFragment($node, $constraints,
            $inheritedLayer, $parentX, $parentY, $iteration + 1);
    }

    return $result;
}

public function reResolveChild(RenderNode $child, LayoutConstraints $c): LayoutResult {
    return $this->resolveFragment($child, $c, iteration: 1);  // 直接重新计算
}
```

### Task 1.2: FlexLayoutStrategy 恢复完整算法

`framework/Rendering/Layout/FlexLayoutStrategy.php`

当前简化版（115 行）跳过 FlexDistributor。需要恢复完整算法：

```php
public function layout(LayoutInput $input): LayoutResult {
    // 1. 构建 FlexItems（已有）
    // 2. FlexItemCollector 收集属性（从 childResults 读取 style）
    // 3. FlexLineBreaker 按行分组
    // 4. FlexDistributor::distributeLine 分配空间
    // 5. FlexFragmentMapper::toResults 映射回 LayoutResult[]
    // 6. 返回 LayoutResult
}
```

需要持有的依赖：
- `FlexDistributor` 实例（构造参数 null 即可，纯数值计算不需要 resolver）
- `FlexLineBreaker`（静态调用）

### Task 1.3: BlockLayoutStrategy 场景 B 支持

`framework/Rendering/Layout/BlockLayoutStrategy.php`

增加百分比高度子节点检测 + 回溯：

```php
public function layout(LayoutInput $input): LayoutResult {
    $children = $input->childResults;

    // 检测是否有百分比高度子节点且父容器高度 auto
    if ($input->constraints->containerHeight <= 0 && $this->hasPercentHeightChild($input)) {
        // Pass 1: 百分比子节点视为 auto，计算父容器高度
        $pass1 = $this->stackChildren($input, $this->percentAsZero($children));
        $parentH = $this->computeParentHeight($pass1);

        // Pass 2: 用父容器高度重新解析百分比子节点
        if ($input->reResolveChild !== null) {
            $adjusted = [];
            foreach ($input->childNodes as $i => $child) {
                if ($this->isPercentHeight($child)) {
                    $newC = $input->constraints->withContainerHeight($parentH);
                    $adjusted[] = ($input->reResolveChild)($child, $newC);
                } else {
                    $adjusted[] = $children[$i];
                }
            }
            $children = $adjusted;
        }
    }

    // 正常布局
    return new LayoutResult(..., children: $children);
}
```

### Phase 1 验证

```bash
# Flex 测试通过量
php tests/unit/LayoutResolverTest.php | grep "Flex"  # 预期 30 个中 25+

# 百分比测试通过
php tests/unit/LayoutResolverTest.php | grep "百分"  # 预期全部通过

# LayoutBase 测试套件
php tests/unit/Layout/run_all.php                    # 预期 80%+ 通过
```

---

## Phase 2 — Grid 迭代 + 表格/多列（4-5 天）

### Task 2.1: GridLayoutStrategy 恢复完整算法

`framework/Rendering/Layout/GridLayoutStrategy.php`

当前简化版（99 行）跳过 GridPlacer。需要恢复：

```php
public function layout(LayoutInput $input): LayoutResult {
    // 1. 解析 grid-template-columns/rows → 轨道定义
    // 2. 构建 GridItems
    // 3. GridPlacer::placeItems 放置
    // 4. 检查是否有 fr/auto 轨道 → needsAnotherPass = true
    // 5. 第二轮：用确定的轨道宽度重新放置
    // 6. GridFragmentMapper::toResults 映射
    return new LayoutResult(..., needsAnotherPass: $needsMore, children: $mapped);
}
```

需要恢复的能力：
- `CssMappings` / `CssValueParser` 解析 `grid-template-columns: repeat(2, 80px)`
- `GridPlacer::placeItems` 的正确调用（传入 cols/rows 数组）
- `GridTracker` 轨道尺寸计算

### Task 2.2: Intrinsic Measurement 支持

所有策略实现 `isIntrinsicMeasurement` 分支：

```php
public function layout(LayoutInput $input): LayoutResult {
    if ($input->constraints->isIntrinsicMeasurement) {
        // 在宽松约束下计算自身自然尺寸
        $w = $this->measureNaturalWidth($input);
        $h = $this->measureNaturalHeight($input);
        return new LayoutResult(
            w: $w, h: $h,
            minContentWidth: $w, maxContentWidth: $w,
            preferredContentWidth: $w,
            minContentHeight: $h, maxContentHeight: $h,
            preferredContentHeight: $h,
        );
    }
    // 正常布局
}
```

### Task 2.3: TableLayoutStrategy / MultiColumnLayoutStrategy 迭代接入

复用 Phase 1 的迭代调度机制：
- `TableLayoutStrategy`: 多行单元格列宽协商 → `needsAnotherPass`
- `MultiColumnLayoutStrategy`: 列平衡 → `needsAnotherPass`

### Phase 2 验证

```bash
# Grid 测试
php tests/unit/LayoutResolverTest.php | grep "Grid"  # 预期全部通过
php tests/unit/Layout/GridLayoutTest.php              # 预期全部通过

# Intrinsic measurement
php tests/verify_layout_pure.php                      # 预期全部 PASS

# 全量回归
php tests/unit/LayoutResolverTest.php                 # 预期 80+/89
```

---

## 文件变更汇总

### 修改文件

| 文件 | Phase | 变更 |
|------|-------|------|
| `LayoutInput.php` | 0 | 新增 4 个字段 |
| `LayoutResult.php` | 0 | 新增 7 个字段 |
| `LayoutConstraints.php` | 0 | 新增 1 个字段 |
| `LayoutResolver.php` | 0+1 | 新增 reResolveChild/measureIntrinsic + 迭代调度 |
| `FlexLayoutStrategy.php` | 1 | 恢复 FlexDistributor 完整算法 |
| `BlockLayoutStrategy.php` | 1 | 比例高度回溯 |
| `GridLayoutStrategy.php` | 2 | 恢复 GridPlacer + 迭代收敛 |
| `TableLayoutStrategy.php` | 2 | 迭代接入 |
| `MultiColumnLayoutStrategy.php` | 2 | 迭代接入 |

### 不需修改

| 文件 | 原因 |
|------|------|
| `LayoutStrategyInterface.php` | 接口签名不变 |
| `AbsoluteStrategy.php` | 接口签名不变 |
| `AbsolutePositioning.php` | 不涉及多阶段 |
| `InlineLayoutStrategy.php` | 场景有限 |
| `LayoutApplicator.php` | 无变化 |

---

## 验收标准

```bash
# 1. 所有修改文件语法正确
php -l framework/Rendering/Layout/LayoutInput.php
php -l framework/Rendering/Layout/LayoutResult.php
php -l framework/Rendering/Layout/LayoutConstraints.php
php -l framework/Rendering/Layout/LayoutResolver.php
php -l framework/Rendering/Layout/*Strategy.php

# 2. 现有测试不退化
php tests/unit/LayoutResolverTest.php | sha256sum  # 不倒退
php tests/unit/LayoutEngineTest.php | sha256sum    # 不倒退

# 3. 功能验证
php tests/verify_layout_pure.php                   # 全部 PASS
```

**总计工时**：10-13 天（Phase 0: 2-3d + Phase 1: 4-5d + Phase 2: 4-5d）
