<?php
/**
 * SFC Compiler `parts` 元数据测试
 *
 * 验证编译器对混合文本+绑定表达式（如 `{{ arrow }} History`）的处理：
 *   1. collectVNodeBindKeys 能从 parts 元数据中提取绑定键
 *   2. generateVNodeExpr 能用 parts 生成正确的串联表达式
 *   3. 纯文本不生成 parts
 *   4. 纯绑定表达式不生成 parts（使用 bind 代替）
 *
 * Usage: php tests/unit/SfcCompilerPartsTest.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/framework/compiler/sfc-compiler.php';

use Px\Rendering\VNode;

echo "========================================\n";
echo " SFC Compiler parts 元数据测试\n";
echo "========================================\n\n";

// ============================================================
// 1. collectVNodeBindKeys — parts 中的绑定键
// ============================================================
echo "--- 1. collectVNodeBindKeys parts 提取 ---\n";

test('从 parts 中提取 bind key', function () {
    $span = VNode::h('span', [
        'style' => 'left:8px;top:6px',
        'parts' => [
            ['type' => 'bind', 'expr' => 'arrow'],
            ['type' => 'text', 'value' => ' History'],
        ],
    ], '{{ arrow }} History');

    $bindKeys = [];
    collectVNodeBindKeys($span, $bindKeys);
    assert(isset($bindKeys['arrow']), "应收集到 key 'arrow'");
    assert(!isset($bindKeys['History']), "不应收集 'History' 为 bind key");
});

test('多个 parts bind key 全部收集', function () {
    $span = VNode::h('span', [
        'parts' => [
            ['type' => 'bind', 'expr' => 'name'],
            ['type' => 'text', 'value' => ': '],
            ['type' => 'bind', 'expr' => 'value'],
        ],
    ], '{{ name }}: {{ value }}');

    $bindKeys = [];
    collectVNodeBindKeys($span, $bindKeys);
    assert(isset($bindKeys['name']), "应收集到 key 'name'");
    assert(isset($bindKeys['value']), "应收集到 key 'value'");
    assert(count($bindKeys) >= 2, "应收集到 2 个 bind key");
});

test('无 parts 时不收集额外 key', function () {
    $span = VNode::h('span', ['style' => 'color:red'], 'Plain Text');

    $bindKeys = [];
    collectVNodeBindKeys($span, $bindKeys);
    assert(count($bindKeys) === 0, "纯文本不应产生 bind key");
});

test('纯绑定表达式不使用 parts', function () {
    $span = VNode::h('span', [
        'bind' => 'arrow',
    ], '>');

    $bindKeys = [];
    collectVNodeBindKeys($span, $bindKeys);
    assert(isset($bindKeys['arrow']), "应通过 bind 属性收集 arrow");
    assert(!isset($bindKeys['parts']), "不应有 parts key");
});

// ============================================================
// 2. generateVNodeExpr — parts 生成的表达式
// ============================================================
echo "\n--- 2. generateVNodeExpr parts 代码生成 ---\n";

test('parts 生成串联表达式: $this->arrow . \' History\'', function () {
    $span = VNode::h('span', [
        'style' => 'left:8px;top:6px',
        'parts' => [
            ['type' => 'bind', 'expr' => 'arrow'],
            ['type' => 'text', 'value' => ' History'],
        ],
    ], '{{ arrow }} History');

    $code = generateVNodeExpr($span, null, 0);
    $expected = "\$this->arrow . ' History'";
    assert(
        strpos($code, $expected) !== false,
        "预期代码包含 [$expected]，实际: " . substr($code, 0, 100)
    );
});

test('parts 生成 text+bind+text 串联', function () {
    $span = VNode::h('span', [
        'parts' => [
            ['type' => 'text', 'value' => 'Value: '],
            ['type' => 'bind', 'expr' => 'count'],
            ['type' => 'text', 'value' => ' items'],
        ],
    ], 'Value: {{ count }} items');

    $code = generateVNodeExpr($span, null, 0);
    assert(
        strpos($code, "'Value: '") !== false,
        "应包含文本部分 'Value: '"
    );
    assert(
        strpos($code, "\$this->count") !== false,
        "应包含绑定 \$this->count"
    );
    assert(
        strpos($code, "' items'") !== false,
        "应包含文本部分 ' items'"
    );
});

test('纯绑定 {{ var }} 不使用 parts', function () {
    $span = VNode::h('span', [
        'bind' => 'arrow',
    ], '>');

    $code = generateVNodeExpr($span, null, 0);
    assert(
        strpos($code, "\$this->arrow") !== false,
        "纯绑定应生成 \$this->arrow"
    );
});

test('纯文本不使用 $this->', function () {
    $span = VNode::h('span', [], 'Static Text');

    $code = generateVNodeExpr($span, null, 0);
    assert(
        strpos($code, 'Static Text') !== false,
        "纯文本应保留字面值"
    );
    assert(
        strpos($code, '$this->') === false,
        "纯文本不应有 \$this->"
    );
});

// ============================================================
// Summary
// ============================================================
exit(print_summary());
