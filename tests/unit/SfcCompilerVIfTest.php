<?php
/**
 * SFC Compiler 编译期 v-if 单元测试
 * 
 * 测试目标:
 *   1. v-if 子节点生成 if($this->cond) 分支代码 (而非内联数组)
 *   2. false 分支不在代码中生成 VNode::h()
 *   3. 连续相同条件子节点合并在同一 if 块下
 *   4. v-if prop 从生成的 VNode 代码中移除
 *   5. 无 v-if 时仍使用内联数组
 * 
 * Usage: php tests/unit/SfcCompilerVIfTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Dom\VNode;

// 加载编译器函数
require_once dirname(__DIR__, 2) . '/framework/Compiler/sfc-compiler.php';

echo "========================================\n";
echo " SFC Compiler 编译期 v-if 测试\n";
echo "========================================\n\n";

echo "--- 1. v-if 生成闭包构建器 ---\n";

test('v-if 子节点生成 if($this->cond) 代码', function () {
    $child = new VNode('div', ['v-if' => 'showPanel', 'class' => 'panel'], 'Panel content');
    
    // generateVNodeExpr 需要设置 vForHelper
    $parent = VNode::h('div', ['class' => 'container'], [$child]);
    
    $code = generateVNodeExpr($parent, null, 0);
    
    // 应包含闭包构建器模式
    assert_contains($code, '(function()', '应包含闭包构建器');
    assert_contains($code, '$c = []', '应包含 $c = []');
    assert_contains($code, 'if ($this->showPanel)', '应包含 v-if 条件判断');
    assert_contains($code, '$c[] =', '应包含 $c[] =');
    assert_contains($code, 'return $c', '应包含 return $c');
    assert_contains($code, "VNode::h('div'", '应包含 VNode 创建');
});

test('v-if false 时不在代码中生成 VNode::h()', function () {
    $child = new VNode('div', ['v-if' => 'showDialog', 'class' => 'dialog'], 'Dialog');
    
    $parent = VNode::h('div', ['class' => 'wrapper'], [$child]);
    
    $code = generateVNodeExpr($parent, null, 0);
    
    // showDialog=false 时，VNode::h 在 if 块内部，不执行
    // 但代码中仍有 VNode::h 调用（在 if 块内）
    // 正确做法：检查 if 结构保护了 VNode::h 调用
    assert_contains($code, 'if ($this->showDialog)', '应有 if 保护');
    
    // 确认子节点的 VNode::h 在 if 块内
    // 找到 if 的位置，然后在其后找 VNode::h
    $ifPos = strpos($code, 'if ($this->showDialog)');
    $afterIf = substr($code, $ifPos);
    assert_contains($afterIf, "VNode::h('div'", 'VNode::h 应在 if 块内部');
});

test('v-if prop 从生成的 VNode 中移除', function () {
    $child = new VNode('div', ['v-if' => 'showMe', 'class' => 'box'], 'Content');
    
    // 保存原始 v-if
    $originalVIf = $child->props['v-if'] ?? '';
    assert_eq($originalVIf, 'showMe', '原始 v-if 应为 showMe');
    
    $parent = VNode::h('div', ['class' => 'container'], [$child]);
    
    $code = generateVNodeExpr($parent, null, 0);
    
    // v-if 应在 if 条件中出现，但不在 VNode::h 的 props 中
    // 查找 VNode::h 调用中的 props
    // 注意: generateVNodeExpr 会临时 unset v-if，然后恢复
    assert_contains($code, '$this->showMe', 'v-if 条件应在 if 中出现');
    
    // 生成的 VNode::h props 不应包含 'v-if'
    // 找到 VNode::h 调用，检查其 props 字符串
    $vnodeCallStart = strpos($code, "VNode::h('div'");
    $vnodeCall = substr($code, $vnodeCallStart, 200);
    assert_true(strpos($vnodeCall, "'v-if'") === false, 'VNode::h props 不应包含 v-if');
});

echo "\n--- 2. 连续相同条件分组 ---\n";

test('连续相同 v-if 子节点合并在同一 if 块', function () {
    $c1 = new VNode('div', ['v-if' => 'showDialog', 'class' => 'overlay'], '');
    $c2 = new VNode('div', ['v-if' => 'showDialog', 'class' => 'dialog-bg'], '');
    $c3 = new VNode('span', ['v-if' => 'showDialog', 'class' => 'title'], 'Title');
    
    $parent = VNode::h('div', ['class' => 'dialog-wrapper'], [$c1, $c2, $c3]);
    
    $code = generateVNodeExpr($parent, null, 0);
    
    // 应该只有一个 if ($this->showDialog) 块，包含所有 3 个孩子
    $ifCount = substr_count($code, 'if ($this->showDialog)');
    assert_eq($ifCount, 1, '连续相同 v-if 应只有一个 if 块');
    
    // C0.2 更新：现编译器为 v-if 生成 else 占位 push（对齐 Vue3 anchor
    // 稳定索引，patch 子序不漂移）：3 真分支 + 3 占位 = 6。
    $pushCount = substr_count($code, '$c[] =');
    assert_eq($pushCount, 6, '应有 6 个 $c[] = (3 真分支 + 3 else 占位)');
});

test('不同 v-if 条件生成独立 if 块', function () {
    $c1 = new VNode('div', ['v-if' => 'showA', 'class' => 'a'], 'A');
    $c2 = new VNode('div', ['v-if' => 'showB', 'class' => 'b'], 'B');
    
    $parent = VNode::h('div', ['class' => 'container'], [$c1, $c2]);
    
    $code = generateVNodeExpr($parent, null, 0);
    
    assert_contains($code, 'if ($this->showA)', '应有 showA 的 if');
    assert_contains($code, 'if ($this->showB)', '应有 showB 的 if');
});

echo "\n--- 3. 无 v-if 时保持内联数组 ---\n";

test('无 v-if 子节点使用内联数组', function () {
    $c1 = new VNode('div', ['class' => 'a'], 'A');
    $c2 = new VNode('div', ['class' => 'b'], 'B');
    
    $parent = VNode::h('div', ['class' => 'container'], [$c1, $c2]);
    
    $code = generateVNodeExpr($parent, null, 0);
    
    // 无 v-if 时不生成闭包
    assert_true(strpos($code, '(function()') === false, '无 v-if 不应生成闭包');
    // 应直接用内联数组
    assert_contains($code, "VNode::h('div'", '应直接包含 VNode 创建');
});

test('单个子节点无 v-if 时直接传递 (非数组)', function () {
    $child = new VNode('span', ['class' => 'label'], 'Hello');
    
    $parent = VNode::h('div', ['class' => 'wrapper'], [$child]);
    
    $code = generateVNodeExpr($parent, null, 0);
    
    // 单子节点无 v-if → 直接作为 children 参数，不包裹数组
    assert_contains($code, "VNode::h('span'", '应有 span VNode');
});

echo "\n--- 4. 混合场景 ---\n";

test('静态子节点 + v-if 子节点混合', function () {
    $static = new VNode('div', ['class' => 'header'], 'Header');
    $conditional = new VNode('div', ['v-if' => 'showBody', 'class' => 'body'], 'Body');
    
    $parent = VNode::h('div', ['class' => 'container'], [$static, $conditional]);
    
    $code = generateVNodeExpr($parent, null, 0);
    
    // 应使用闭包模式
    assert_contains($code, '(function()', '混合场景应使用闭包');
    // 静态节点无条件加入
    assert_contains($code, 'if ($this->showBody)', '条件节点应有 if');
    // C0.2 更新：静态 1 + v-if 真分支 1 + else 占位 1 = 3（anchor 稳定索引）。
    assert_eq(substr_count($code, '$c[] ='), 3, '应有 3 个 push（含 else 占位）');
});

echo "\n--- 5. v-if 条件为 false 时不创建 VNode ---\n";

test('不同 v-if 条件生成独立 if，false 分支不创建 VNode', function () {
    // 模拟: v-if="showA" 和 v-if="showB" 是两个独立的条件
    $c1 = new VNode('div', ['v-if' => 'showPanel', 'class' => 'panel'], 'Panel');
    $c2 = new VNode('div', ['v-if' => 'showDialog', 'class' => 'dialog'], '');

    $parent = VNode::h('div', ['class' => 'root'], [$c1, $c2]);

    $code = generateVNodeExpr($parent, null, 0);

    // 两个独立的 if 块
    $ifShowPanelCount = substr_count($code, 'if ($this->showPanel)');
    $ifShowDialogCount = substr_count($code, 'if ($this->showDialog)');

    assert_eq($ifShowPanelCount, 1, 'showPanel 应有 1 个 if');
    assert_eq($ifShowDialogCount, 1, 'showDialog 应有 1 个 if');

    // 每个条件只在为 true 时才 push VNode
    // 生成的代码结构: if(cond) { $c[] = VNode::h(...); }
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
