# 集成测试：基于快照差异分析的渲染管道压力测试

## Context

当前测试体系的问题：
- CalculatorAppTest 只测组件逻辑（state），不涉及渲染管道
- VNodeRendererTest 只测渲染管道，但脱离 Application 上下文
- 之前 overflow:hidden 黑屏 bug 是跨层问题（显示溢出 → GDI 污染），需要多步操作 + 完整管道来复现

用户提出的方案：**不是写硬编码断言，而是录制状态快照 + 分类规则检测 + 大量迭代验证**。

## 核心架构

```
for (100+ 次迭代) {
    录制快照A（迭代前的全部状态）
    通过 Application 事件管道模拟点击
    渲染
    录制快照B（迭代后的全部状态）
    按分类规则对比 A→B
    即时断言：违反规则 → 测试失败 + 存档异常快照
}
```

## 快照数据

一个快照包含三个维度的数据：

```php
class RenderSnapshot {
    // 维度1：组件状态（从 AppComponent public 字段直接读取）
    public string $display;        // 显示文本（如 "42"）
    public string $expression;     // 表达式文本（如 "5 + 3 ="）
    public string $acLabel;        // "AC" 或 "C"
    public bool $showHistory;      // 历史面板是否可见
    public bool $hasMemory;        // 是否有记忆
    public int $historyCount;      // 历史条目数
    public string $viewMode;       // "scientific" 或 "basic"

    // 维度2：渲染输出（从 _MockRenderContext.drawnElements 提取）
    /** @var array<string, array> key=元素标识, value={x,y,w,h,layer,type,color,...} */
    public array $buttons;         // 所有按钮元素（按 click-arg 索引）
    public array $containers;      // 所有容器元素（按 type+位置 索引）
    public array $textElements;    // 所有文本元素（按 位置+内容 索引）
    public int $totalDrawnCount;   // 元素总数
    public array $clipRegions;     // clip-push/clip-pop 区域

    // 维度3：布局结构（从 RenderNode 树提取）
    public int $treeDepth;         // 树深度
    public int $nodeCount;         // RenderNode 节点数
}
```

## 分类规则

规则分为三类，覆盖用户提到的场景：

### 规则 A：绝对不变（任何一次点击后都不应该变）

| 被检查对象 | 检查项 | 理论依据 |
|-----------|--------|----------|
| 所有按钮的 x/y | 与第 1 帧一致 | grid 布局固定，点击不改变布局 |
| 所有按钮的 w/h | 与第 1 帧一致 | grid-template-columns/rows 固定 |
| 所有按钮的 layer | 与第 1 帧一致 | z-index 固定 |
| 所有按钮的 color/bg | 与第 1 帧一致 | CSS 样式不因点击改变 |
| CalculatorDisplay 容器 x/y/w/h | 与第 1 帧一致 | 根组件样式固定 |
| 各子容器（BasicPad、ScientificPad 等）位置/尺寸 | 与第 1 帧一致 | 模板固定 |
| clip-push/clip-pop 的 x/y/w/h | 与第 1 帧一致 | overflow:hidden 容器固定 |
| 帧内元素总数 | 与第 1 帧一致 | 不新增/减少 UI 元素 |
| RenderNode 树深度 | 与第 1 帧一致 | 组件树结构不变 |
| RenderNode 节点数 | 与第 1 帧一致 | 没有组件被动态创建/销毁 |

### 规则 B：条件不变（特定条件下不变）

| 条件 | 被检查对象 | 检查项 |
|------|-----------|--------|
| display = "0" | AC 按钮文本 | "AC" |
| display ≠ "0" | AC 按钮文本 | "C" |
| hasMemory = false | Memory 指示器 | 不可见 |
| hasMemory = true | Memory 指示器 | 显示 "M" |
| showHistory = false | 历史面板容器 | 不存在于 drawnElements |
| showHistory = true | 历史面板容器 | 存在且 x/y/w/h 固定 |

### 规则 C：变化有约束（可以变，但不能随便变）

| 被检查对象 | 约束规则 |
|-----------|----------|
| display 文本 | 长度 ≤ 15，仅含数字和小数点 |
| expression 文本 | 符合「数字 运算符 数字 =」格式，或空 |
| historyCount | 单调不减（只追加不减少，除非 clearHistory） |
| display 文本变化 | 每次点击只追加一位数字（或替换 0） |
| 按钮高亮（选中运算符） | 只有一个运算符高亮 |

## 检测流程

```php
test('100 次不断点击 "1" 按钮的稳定性', function () {
    $ctx = createTestApp(); // return ['app', 'component', 'platform', 'context']
    $app = $ctx['app'];
    $component = $ctx['component'];
    
    // 录制第 1 帧作为参考基线
    $refSnapshot = captureSnapshot($ctx);
    
    // 找到 "1" 按钮的位置
    $btnNode = findClickableNode($app, 'inputDigit', '1');
    
    for ($i = 0; $i < 100; $i++) {
        // 点击 + 渲染
        clickAndRender($app, $btnNode);
        
        // 录制当前快照
        $current = captureSnapshot($ctx);
        
        // 规则 A 检查：所有不变属性 vs 参考基线
        $violations = checkInvariantRules($refSnapshot, $current, $i);
        
        // 规则 B 检查：条件相关属性
        $violations = array_merge($violations, checkConditionalRules($current, $i));
        
        // 规则 C 检查：变化约束
        $violations = array_merge($violations, checkChangeRules($refSnapshot, $current, $i));
        
        // 如果有违规：存档 + 抛出
        if (count($violations) > 0) {
            archiveAnomalyFrame($i, $current, $violations);
            assert_eq(0, count($violations), 
                "Iteration $i:\n  " . implode("\n  ", $violations));
        }
    }
});
```

## 存档机制

### 什么情况存档
只要任何规则被违反，就把异常快照写入文件。

### 存什么
- 违规的迭代编号
- 违反的规则列表
- 触发违规时的快照内容（全量）
- 运行上下文（APP_PLATFORM、WINDOW_WIDTH 等）

### 存在哪

```
tests/anomaly/<timestamp>/
├── manifest.json          # 本次运行的概要
├── frame_042.json         # 第 42 次迭代的异常快照
├── frame_073.json         # 第 73 次迭代的异常快照
└── ...
```

### 格式
JSON，便于人工阅读和后续自动化分析。

## Rules 辅助函数

```php
// 规则 A：绝对不变
function checkInvariantRules(RenderSnapshot $ref, RenderSnapshot $current, int $iter): array {
    $v = [];
    foreach ($ref->buttons as $id => $btn) {
        $c = $current->buttons[$id];
        if ($btn['x'] !== $c['x']) $v[] = "Button[$id].x changed {$btn['x']}→{$c['x']}";
        if ($btn['y'] !== $c['y']) $v[] = "Button[$id].y changed {$btn['y']}→{$c['y']}";
        if ($btn['w'] !== $c['w']) $v[] = "Button[$id].w changed {$btn['w']}→{$c['w']}";
        if ($btn['h'] !== $c['h']) $v[] = "Button[$id].h changed {$btn['h']}→{$c['h']}";
        if ($btn['layer'] !== $c['layer']) $v[] = "Button[$id].layer changed";
    }
    foreach ($ref->containers as $id => $ct) {
        // 同样检查 x/y/w/h/layer
    }
    if ($ref->totalDrawnCount !== $current->totalDrawnCount)
        $v[] = "Element count changed {$ref->totalDrawnCount}→{$current->totalDrawnCount}";
    return $v;
}

// 规则 B：条件不变
function checkConditionalRules(RenderSnapshot $snap, int $iter): array {
    $v = [];
    $acBtn = $snap->buttons['ac'] ?? $snap->buttons['AC'] ?? null;
    if ($acBtn) {
        if ($snap->display === '0' && $acBtn['text'] !== 'AC')
            $v[] = "display=0 but AC label is '{$acBtn['text']}'";
        if ($snap->display !== '0' && $acBtn['text'] !== 'C')
            $v[] = "display≠0 but AC label is '{$acBtn['text']}'";
    }
    return $v;
}

// 规则 C：变化约束
function checkChangeRules(RenderSnapshot $ref, RenderSnapshot $current, int $iter): array {
    $v = [];
    if (strlen($current->display) > 15)
        $v[] = "display exceeds 15 chars: '{$current->display}'";
    if (!preg_match('/^[0-9.]*$/', $current->display))
        $v[] = "display contains non-numeric chars: '{$current->display}'";
    return $v;
}
```

## 测试场景

### 测试 1：100 次点击 "1" 按钮
- **目标**：复现之前的显示溢出 bug、GDI 黑屏 bug
- **操作**：循环 100 次 clickAndRender('1')
- **预期**：所有规则通过。display 第 16 次起不再变化（15 位限制），其他所有属性不变

### 测试 2：50 次随机混合操作
- **目标**：验证复杂操作序列不破坏状态
- **操作**：从预定义的随机序列中选取操作（"1"×5 → "+" → "2"×3 → "=" → "C" 等）
- **预期**：所有规则通过

### 测试 3：开历史面板后点击
- **目标**：验证 v-if/v-for 交互
- **操作**：toggleHistory → 点击数字 → render
- **预期**：规则 A 对历史面板内的条目位置/大小不变

## 需要实现的函数

```php
// 快照
function captureSnapshot(array $ctx): RenderSnapshot;

// 规则检查
function checkInvariantRules(RenderSnapshot $ref, RenderSnapshot $cur, int $iter): array;
function checkConditionalRules(RenderSnapshot $snap, int $iter): array;
function checkChangeRules(RenderSnapshot $ref, RenderSnapshot $cur, int $iter): array;

// 存档
function archiveAnomalyFrame(int $iter, RenderSnapshot $snap, array $violations): void;
function cleanAnomalyDir(): void;                // 测试开始前清理旧存档
function printAnomalySummary(): void;           // 测试结束后打印存档路径

// 事件
function findClickableNode(Application $app, string $handler, string $arg): ?RenderNode;
function clickAndRender(Application $app, RenderNode $node): void;

// 反射
function invokeRender(Application $app): void;
function invokeDoFirstRender(Application $app): void;
function invokeHandleMouseEvent(Application $app, $event): void;
function getRenderContext(Application $app): _MockRenderContext;
function getRenderTreeManager(Application $app): RenderTreeManager;
function resetThemeProvider(): void;
```

## 文件结构

`tests/unit/RenderingPipelineTest.php` — 唯一的新增文件

包含：Mock 类、反射辅助函数、快照类、规则函数、存档函数、测试场景、汇总输出。

## 验证方法

1. `D:\swoole_compiler\php.exe tests/unit/RenderingPipelineTest.php` → 0 FAIL
2. 移除 VNodeRenderer 中的 overflow:hidden 逻辑 → 测试应因 clip-push 缺失而失败
3. 移除 inputDigit 的 15 位限制 → 测试应因 display 超长而失败
4. `D:\swoole_compiler\php.exe tests/run_all_tests.php` → 全部通过（回归测试）
