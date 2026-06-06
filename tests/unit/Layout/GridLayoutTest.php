<?php
/**
 * GridLayoutTest — CSS Grid 布局标准测试
 *
 * 覆盖:
 *   - auto-fill + minmax 自动计算列数
 *   - grid gap 间距
 *   - 子节点自动换行
 *   - 显式列: 固定 px、百分比、fr
 *
 * Usage: php tests/unit/Layout/GridLayoutTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

echo "========================================\n";
echo " Grid 布局标准测试\n";
echo "========================================\n\n";

// ============================================================
// Group 1: auto-fill + minmax
// ============================================================
echo "--- Group 1: auto-fill + minmax ---\n";

test('grid auto-fill 自动计算列数并排列子节点', function () {
    $cells = [];
    for ($i = 0; $i < 6; $i++) {
        $cells[] = makeNode('div', [], [], 'cell' . $i);
    }
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(auto-fill, minmax(100px, 1fr))',
        'gap' => 10,
        'width' => 340, 'height' => 200,
    ], $cells);
    $root = makeNode('div', ['width' => 500, 'height' => 400], [$grid]);

    runResolver($root);

    // floor((340+10)/(100+10)) = floor(350/110) = 3 columns
    assert_eq($cells[0]->x, 0, 'cell0 x=0 (col1)');
    assert_eq($cells[3]->x, 0, 'cell3 x=0 (wraps to row2)');
    assert_true($cells[3]->y > $cells[0]->y, 'cell3 y > cell0 y (different rows)');
});

test('grid gap 产生列间距', function () {
    $cells = [];
    for ($i = 0; $i < 4; $i++) {
        $cells[] = makeNode('div', [], [], 'cell' . $i);
    }
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(auto-fill, minmax(100px, 1fr))',
        'gap' => 20,
        'width' => 340, 'height' => 150,
    ], $cells);
    $root = makeNode('div', ['width' => 500, 'height' => 300], [$grid]);

    runResolver($root);

    // 有 gap 时列间距应大于 0
    if (count($grid->children) >= 2) {
        assert_true($grid->children[1]->x > $grid->children[0]->x,
            'cell1.x > cell0.x (gap creates spacing)');
    }
});

test('grid 网格换行（超过列数后自动折行）', function () {
    $cells = [];
    for ($i = 0; $i < 10; $i++) {
        $cells[] = makeNode('div', [], [], 'cell' . $i);
    }
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(auto-fill, minmax(80px, 1fr))',
        'gap' => 8,
        'width' => 280, 'height' => 300,
    ], $cells);
    $root = makeNode('div', ['width' => 400, 'height' => 400], [$grid]);

    runResolver($root);

    // 所有子节点 x 都在容器内
    foreach ($grid->children as $i => $child) {
        assert_true($child->x >= 0, "cell{$i} x >= 0");
        assert_true($child->w > 0, "cell{$i} w > 0");
    }
    // 至少有一行折行
    assert_true($grid->children[4]->y > $grid->children[0]->y,
        'cell4.y > cell0.y (wrapping occurred)');
});

// ============================================================
// Group 2: 显式列
// ============================================================
echo "\n--- Group 2: 显式列 ---\n";

test('grid-template-columns: 100px 200px 产生固定宽度列', function () {
    $cells = [];
    for ($i = 0; $i < 3; $i++) {
        $cells[] = makeNode('div', [], [], 'cell' . $i);
    }
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => '100px 200px',
        'width' => 400, 'height' => 100,
    ], $cells);
    $root = makeNode('div', ['width' => 500, 'height' => 400], [$grid]);

    runResolver($root);

    assert_eq($grid->children[0]->w, 100, 'cell0 w=100');
    assert_eq($grid->children[1]->w, 200, 'cell1 w=200');
    assert_eq($grid->children[0]->x, 0, 'cell0 x=0');
    assert_eq($grid->children[1]->x, 100, 'cell1 x=100 (after cell0)');
});

test('grid-template-columns: 50% 50% 产生百分比宽度列', function () {
    $cells = [];
    for ($i = 0; $i < 2; $i++) {
        $cells[] = makeNode('div', [], [], 'cell' . $i);
    }
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => '50% 50%',
        'width' => 400, 'height' => 100,
    ], $cells);
    $root = makeNode('div', ['width' => 500, 'height' => 400], [$grid]);

    runResolver($root);

    assert_eq($grid->children[0]->w, 200, 'cell0 w=200 (50% of 400)');
    assert_eq($grid->children[1]->w, 200, 'cell1 w=200 (50% of 400)');
});

test('grid-template-columns: 100px 1fr 产生混合列', function () {
    $cells = [];
    for ($i = 0; $i < 2; $i++) {
        $cells[] = makeNode('div', [], [], 'cell' . $i);
    }
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => '100px 1fr',
        'width' => 500, 'height' => 100,
    ], $cells);
    $root = makeNode('div', ['width' => 600, 'height' => 400], [$grid]);

    runResolver($root);

    assert_eq($grid->children[0]->w, 100, 'cell0 w=100 (fixed)');
    assert_eq($grid->children[0]->x, 0, 'cell0 x=0');
    assert_eq($grid->children[1]->x, 100, 'cell1 x=100 (after fixed col)');
    // 1fr 占剩余: 500 - 100 = 400
    assert_eq($grid->children[1]->w, 400, 'cell1 w=400 (1fr = remaining 400)');
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
