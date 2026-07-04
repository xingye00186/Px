<?php
/**
 * 纯函数布局测试框架
 *
 * 用于验证布局策略的纯函数接口 layout(LayoutInput): LayoutResult。
 * Phase 1 完成后，此处可以添加逐条 CSS 规范的纯函数测试。
 *
 * Usage: php tests/verify_layout_pure.php
 */

require_once __DIR__ . '/unit/bootstrap.php';

use Px\Rendering\Layout\LayoutResult;
use Px\Rendering\Layout\LayoutInput;
use Px\Rendering\Layout\LayoutConstraints;
use Px\Rendering\ComputedStyle;

$pass = 0;
$fail = 0;

/**
 * 断言 LayoutResult 字段值与期望一致。
 */
function assertLayoutResult(string $label, LayoutResult $r, array $expected): void
{
    global $pass, $fail;
    $ok = true;
    foreach ($expected as $k => $v) {
        $actual = $r->$k;
        if ($actual !== $v) {
            echo "  [FAIL] $label.$k: expected $v, got $actual\n";
            $ok = false;
        }
    }
    if ($ok) {
        echo "  [PASS] $label\n";
        $pass++;
    } else {
        $fail++;
    }
}

// ============================================================
// 框架自检：LayoutResult 可构造
// ============================================================
echo "--- 0. 框架自检 ---\n";

// 空 LayoutResult
$empty = new LayoutResult(0, 0, 0, 0);
assertLayoutResult('empty result', $empty, [
    'x' => 0, 'y' => 0, 'w' => 0, 'h' => 0,
    'visualW' => 0, 'visualH' => 0,
    'layer' => 0,
    'contentWidth' => 0, 'contentHeight' => 0,
]);

// LayoutResult with children
$child = new LayoutResult(10, 20, 100, 50);
$parent = new LayoutResult(0, 0, 200, 100, children: [$child]);
assertLayoutResult('parent w', $parent, ['w' => 200]);
assertLayoutResult('child x', $parent->children[0], ['x' => 10, 'y' => 20, 'w' => 100]);

// toArray
$arr = $empty->toArray();
assert(is_array($arr), 'toArray returns array');
assert(isset($arr['x']), 'toArray has x');
assert(isset($arr['children']), 'toArray has children');

// LayoutInput 可构造
$constraints = new LayoutConstraints(containerWidth: 200, containerHeight: 100);
$style = new ComputedStyle(['width' => '100%']);
$input = new LayoutInput(
    constraints: $constraints,
    style: $style,
    textContent: 'hello',
);
assertLayoutResult('LayoutInput constraints', new LayoutResult($input->constraints->containerWidth, 0, 0, 0), [
    'x' => 200,
]);

// ============================================================
// 汇总
// ============================================================
echo "\n========================================\n";
echo "Results: $pass/$pass passed, $fail FAILED\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
