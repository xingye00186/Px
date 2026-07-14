<?php
/**
 * 纯函数布局测试框架（Phase 5 更新版）
 *
 * 使用 PhysicalFragment / ConstraintSpace 替代已删除的旧 DTO。
 * Phase 5 前使用 layout(LayoutInput): LayoutResult，现使用 LayoutAlgorithm 接口。
 *
 * Usage: php tests/verify_layout_pure.php
 */

require_once __DIR__ . '/unit/bootstrap.php';

use Px\Layout\PhysicalFragment;
use Px\Layout\ConstraintSpace;

$pass = 0;
$fail = 0;

/**
 * 断言 PhysicalFragment 字段值与期望一致。
 */
function assertFragment(string $label, PhysicalFragment $f, array $expected): void
{
    global $pass, $fail;
    $ok = true;
    foreach ($expected as $k => $v) {
        $actual = $f->$k ?? null;
        if ($actual !== $v) {
            echo "  [FAIL] $label.$k: expected $v, got " . var_export($actual, true) . "\n";
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
// 框架自检：PhysicalFragment 可构造
// ============================================================
echo "--- 0. 框架自检 ---\n";

// 空 PhysicalFragment
$empty = new PhysicalFragment(0, 0, 0, 0);
assertFragment('empty fragment', $empty, [
    'x' => 0, 'y' => 0, 'w' => 0, 'h' => 0,
]);

// PhysicalFragment with children
$child = new PhysicalFragment(10, 20, 100, 50);
$parent = new PhysicalFragment(0, 0, 200, 100, 0, 0, 0, 0, 0, null, [$child]);
assertFragment('parent w', $parent, ['w' => 200]);
assertFragment('child x', $parent->children[0], ['x' => 10, 'y' => 20, 'w' => 100]);

// ConstraintSpace 可构造
$space = new ConstraintSpace(
    containerWidth: 200,
    containerHeight: 100,
    contentWidth: 200,
    contentHeight: 100,
);
assertFragment('ConstraintSpace width', new PhysicalFragment($space->contentWidth, 0, 0, 0), [
    'x' => 200,
]);

// ============================================================
// 汇总
// ============================================================
echo "\n========================================\n";
echo "Results: $pass/$pass passed, $fail FAILED\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
