<?php
/**
 * PositionLayoutTest — CSS Position 布局标准测试
 *
 * 覆盖:
 *   - position:static 默认流
 *   - position:relative 偏移
 *   - position:absolute 绝对定位
 *   - position:fixed 固定定位
 *   - z-index 层级
 *   - 嵌套定位
 *
 * Usage: php tests/unit/Layout/PositionLayoutTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

echo "========================================\n";
echo " Position 布局标准测试\n";
echo "========================================\n\n";

// ============================================================
// Group 1: position:static (默认)
// ============================================================
echo "--- Group 1: position:static ---\n";

test('static 元素遵循正常流定位', function () {
    $c1 = makeNode('div', ['width' => 100, 'height' => 40], [], 'c1');
    $c2 = makeNode('div', ['width' => 100, 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'width' => 200, 'height' => 200,
    ], [$c1, $c2]);

    runResolver($root);

    assert_eq($c1->y, 0, 'c1 y=0 (normal flow)');
    assert_eq($c2->y, 40, 'c2 y=40 (below c1)');
});

// ============================================================
// Group 2: position:relative
// ============================================================
echo "\n--- Group 2: position:relative ---\n";

test('relative top 偏移不改变 auto-stack 位置', function () {
    $c1 = makeNode('div', [
        'position' => 'relative', 'top' => 10,
        'width' => 100, 'height' => 40,
    ], [], 'c1');
    $c2 = makeNode('div', ['width' => 100, 'height' => 40], [], 'c2');
    $root = makeNode('div', [
        'width' => 200, 'height' => 200,
    ], [$c1, $c2]);

    runResolver($root);

    // relative 偏移不推进 auto-stack 位置
    assert_eq($c1->y, 10, 'c1 y=10 (0+top=10)');
    assert_eq($c2->y, 40, 'c2 y=40 (still below c1 original position 0+40)');
});

test('relative left 水平偏移', function () {
    $child = makeNode('div', [
        'position' => 'relative', 'left' => 20,
        'width' => 80, 'height' => 50,
    ], [], 'child');
    $root = makeNode('div', [
        'width' => 300, 'height' => 200,
    ], [$child]);

    runResolver($root);

    assert_eq($child->x, 20, 'child x=20 (0+left=20)');
});

// ============================================================
// Group 3: position:absolute
// ============================================================
echo "\n--- Group 3: position:absolute ---\n";

test('absolute 脱离正常流，相对定位祖先定位', function () {
    $abs = makeNode('div', [
        'position' => 'absolute', 'left' => 30, 'top' => 20,
        'width' => 100, 'height' => 60,
    ], [], 'abs');
    $container = makeNode('div', [
        'position' => 'relative',
        'width' => 400, 'height' => 300,
    ], [$abs]);
    $root = makeNode('div', ['width' => 800, 'height' => 600], [$container]);

    runResolver($root);

    assert_eq($abs->x, 30, 'abs x=30 (relative to container)');
    assert_eq($abs->y, 20, 'abs y=20 (relative to container)');
});

test('absolute 不参与 auto-stack', function () {
    $abs = makeNode('div', [
        'position' => 'absolute', 'left' => 0, 'top' => 0,
        'width' => 50, 'height' => 50,
    ], [], 'abs');
    $normal = makeNode('div', ['height' => 40], [], 'normal');
    $container = makeNode('div', [
        'position' => 'relative',
        'width' => 200, 'height' => 200,
    ], [$abs, $normal]);
    $root = makeNode('div', ['width' => 400, 'height' => 300], [$container]);

    runResolver($root);

    // 正常流元素不受 absolute 影响
    assert_eq($normal->y, 0, 'normal y=0 (abs skipped in auto-stack)');
    assert_eq($abs->y, 0, 'abs y=0 (absolute positioned)');
});

// ============================================================
// Group 4: position:right/bottom
// ============================================================
echo "\n--- Group 4: right/bottom 定位 ---\n";

test('absolute with right/bottom 定位', function () {
    $abs = makeNode('div', [
        'position' => 'absolute', 'right' => 20, 'bottom' => 10,
        'width' => 80, 'height' => 50,
    ], [], 'abs');
    $container = makeNode('div', [
        'position' => 'relative',
        'width' => 300, 'height' => 200,
    ], [$abs]);
    $root = makeNode('div', ['width' => 500, 'height' => 400], [$container]);

    runResolver($root);

    assert_eq($abs->x, 200, 'abs x=300-80-20=200');
    assert_eq($abs->y, 140, 'abs y=200-50-10=140');
});

// ============================================================
// Group 5: z-index 层级
// ============================================================
echo "\n--- Group 5: z-index ---\n";

test('z-index 影响 layer 值', function () {
    $c1 = makeNode('div', ['width' => 100, 'height' => 50, 'zIndex' => 5], [], 'c1');
    $c2 = makeNode('div', ['width' => 100, 'height' => 50], [], 'c2');
    $root = makeNode('div', ['width' => 200, 'height' => 200], [$c1, $c2]);

    runResolver($root);

    assert_eq($c1->layer, 5, 'c1 layer=5 (zIndex=5)');
    assert_eq($c2->layer, 0, 'c2 layer=0 (no zIndex)');
});

// ============================================================
// Summary
// ============================================================
echo "\n";
$total = $GLOBALS['_test_passed'] + $GLOBALS['_test_failed'];
echo "Results: {$GLOBALS['_test_passed']}/{$total} passed\n";
if ($GLOBALS['_test_failed'] > 0) {
    exit(1);
}
echo "All tests passed.\n";
