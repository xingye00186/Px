<?php
/**
 * BlockLayoutTest — CSS Block 布局标准测试
 *
 * 覆盖:
 *   - auto-stack 垂直堆叠
 *   - margin 间距
 *   - width/height 基础尺寸
 *   - width:100% 填满容器
 *   - height:auto 自动调整
 *   - padding 影响子节点
 *   - min/max 尺寸约束
 *
 * Usage: php tests/unit/Layout/BlockLayoutTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

echo "========================================\n";
echo " Block 布局标准测试\n";
echo "========================================\n\n";

// ============================================================
// Group 1: auto-stack 基础垂直堆叠
// ============================================================
echo "--- Group 1: auto-stack ---\n";

test('block 子节点垂直堆叠（auto-stack）', function () {
    $c1 = makeNode('div', ['width' => 100, 'height' => 40], [], 'c1');
    $c2 = makeNode('div', ['width' => 100, 'height' => 50], [], 'c2');
    $c3 = makeNode('div', ['width' => 100, 'height' => 60], [], 'c3');
    $root = makeNode('div', [
        'width' => 200, 'height' => 300,
    ], [$c1, $c2, $c3]);

    runResolver($root);

    assert_eq($c1->y, 0, 'c1 y=0');
    assert_eq($c2->y, 40, 'c2 y=40 (below c1)');
    assert_eq($c3->y, 90, 'c3 y=90 (below c2)');
    assert_eq($c1->x, 0, 'c1 x=0');
    assert_eq($c2->x, 0, 'c2 x=0');
});

test('auto-stack 子节点宽度默认填满容器', function () {
    $c1 = makeNode('div', ['height' => 30], [], 'c1');
    $c2 = makeNode('div', ['height' => 30], [], 'c2');
    $root = makeNode('div', [
        'width' => 400, 'height' => 200,
    ], [$c1, $c2]);

    runResolver($root);

    assert_eq($c1->w, 400, 'c1 w=400 (fills container)');
    assert_eq($c2->w, 400, 'c2 w=400 (fills container)');
});

test('auto-stack 带 margin 的兄弟间距', function () {
    $c1 = makeNode('div', ['width' => 100, 'height' => 30, 'marginBottom' => 10], [], 'c1');
    $c2 = makeNode('div', ['width' => 100, 'height' => 30], [], 'c2');
    $root = makeNode('div', [
        'width' => 200, 'height' => 200,
    ], [$c1, $c2]);

    runResolver($root);

    assert_eq($c1->y, 0, 'c1 y=0');
    assert_eq($c2->y, 40, 'c2 y=30+10=40');
});

// ============================================================
// Group 2: 尺寸基础
// ============================================================
echo "\n--- Group 2: 尺寸基础 ---\n";

test('显式 width/height', function () {
    $child = makeNode('div', ['width' => 150, 'height' => 80], [], 'child');
    $root = makeNode('div', ['width' => 500, 'height' => 300], [$child]);

    runResolver($root);

    assert_eq($child->w, 150, 'child w=150');
    assert_eq($child->h, 80, 'child h=80');
});

test('width:100% 填满父容器宽度', function () {
    $child = makeNode('div', ['widthPercent' => 100, 'height' => 50], [], 'child');
    $parent = makeNode('div', [
        'width' => 300, 'height' => 200,
    ], [$child]);
    $root = makeNode('div', ['width' => 500, 'height' => 400], [$parent]);

    runResolver($root);

    assert_eq($child->w, 300, 'child w=300 (100% of parent content width 300)');
});

test('width:100% 在父容器有 padding 时使用 content width', function () {
    $child = makeNode('div', ['widthPercent' => 100, 'height' => 50], [], 'child');
    $parent = makeNode('div', [
        'width' => 300, 'height' => 200,
        'paddingLeft' => 20, 'paddingRight' => 30,
    ], [$child]);
    $root = makeNode('div', ['width' => 500, 'height' => 400], [$parent]);

    runResolver($root);

    // CSS content-box: content width = CSS width = 300 (padding added outside)
    assert_eq($child->w, 300, 'child w=300 (100% of parent CSS width 300)');
});

test('height:auto 适应内容高度（无固定高度时）', function () {
    $c1 = makeNode('div', ['height' => 40], [], 'c1');
    $c2 = makeNode('div', ['height' => 60], [], 'c2');
    // parent has no explicit height → auto from children
    $parent = makeNode('div', ['width' => 200], [$c1, $c2]);
    $root = makeNode('div', ['width' => 400, 'height' => 300], [$parent]);

    runResolver($root);

    // auto-height = sum of children = 40 + 60 = 100
    assert_eq($parent->h, 100, 'parent h=100 (auto from children 40+60)');
});

// ============================================================
// Group 3: padding 影响
// ============================================================
echo "\n--- Group 3: padding ---\n";

test('padding 偏移子节点', function () {
    $child = makeNode('div', ['width' => 50, 'height' => 30], [], 'child');
    $root = makeNode('div', [
        'width' => 200, 'height' => 150,
        'paddingLeft' => 15, 'paddingTop' => 10,
    ], [$child]);

    runResolver($root);

    assert_eq($child->x, 15, 'child x = paddingLeft = 15');
    assert_eq($child->y, 10, 'child y = paddingTop = 10');
});

test('padding 减少子节点可用宽度（auto-stack 时）', function () {
    $child = makeNode('div', ['height' => 30], [], 'child');
    $parent = makeNode('div', [
        'width' => 200, 'height' => 100,
        'paddingLeft' => 20, 'paddingRight' => 30,
    ], [$child]);
    $root = makeNode('div', ['width' => 400, 'height' => 300], [$parent]);

    runResolver($root);

    // CSS content-box: child fills parent's CSS width (content area) = 200
    // padding is OUTSIDE the content area and does not reduce child width
    assert_eq($child->w, 200, 'child w=200 (parent CSS width, padding does not reduce content area)');
});

// ============================================================
// Group 4: min/max 约束
// ============================================================
echo "\n--- Group 4: min/max ---\n";

test('min-width 防止过窄', function () {
    $child = makeNode('div', ['width' => 30, 'minWidth' => 80, 'height' => 40], [], 'child');
    $root = makeNode('div', ['width' => 200, 'height' => 100], [$child]);

    runResolver($root);

    assert_eq($child->w, 80, 'child w=80 (min-width clamps 30->80)');
});

test('max-width 防止过宽', function () {
    $child = makeNode('div', ['width' => 500, 'maxWidth' => 200, 'height' => 40], [], 'child');
    $root = makeNode('div', ['width' => 600, 'height' => 100], [$child]);

    runResolver($root);

    assert_eq($child->w, 200, 'child w=200 (max-width clamps 500->200)');
});

test('min-height 防止过矮', function () {
    $child = makeNode('div', ['height' => 10, 'minHeight' => 60], [], 'child');
    $root = makeNode('div', ['width' => 200, 'height' => 100], [$child]);

    runResolver($root);

    assert_eq($child->h, 60, 'child h=60 (min-height clamps 10->60)');
});

test('max-height 防止过高', function () {
    $child = makeNode('div', ['height' => 300, 'maxHeight' => 100], [], 'child');
    $root = makeNode('div', ['width' => 200, 'height' => 400], [$child]);

    runResolver($root);

    assert_eq($child->h, 100, 'child h=100 (max-height clamps 300->100)');
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
