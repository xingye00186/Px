<?php
/**
 * ComboLayoutTest — CSS 布局组合场景标准测试
 *
 * 覆盖跨属性组合场景（嵌套 flex、fixed 定位、scroll + flex、padding 影响等）
 *
 * Usage: php tests/unit/Layout/ComboLayoutTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

echo "========================================\n";
echo " 组合布局标准测试\n";
echo "========================================\n\n";

// ============================================================
// Group 1: 嵌套 flex column
// ============================================================
echo "--- Group 1: 嵌套 flex column ---\n";

test('flex:1 传递到内层 flex column', function () {
    $header = makeNode('div', ['height' => 60], [], 'header');
    $innerHeader = makeNode('div', ['height' => 40], [], 'inner-header');
    $innerContent = makeNode('div', ['flex' => '1'], [], 'inner-content');
    $outerContent = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column', 'flex' => '1',
    ], [$innerHeader, $innerContent]);
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 400, 'height' => 400,
    ], [$header, $outerContent]);

    runResolver($root);

    assert_eq($outerContent->y, 60, 'outerContent below header');
    assert_eq($outerContent->h, 340, 'outerContent h=400-60=340');
    assert_eq($innerContent->y, 60 + 40, 'innerContent below innerHeader');
    assert_eq($innerContent->h, 300, 'innerContent h=340-40=300');
});

test('flex column 内嵌 flex row', function () {
    $left = makeNode('div', ['flex' => '1', 'height' => 50], [], 'left');
    $right = makeNode('div', ['flex' => '1', 'height' => 50], [], 'right');
    $content = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row', 'flex' => '1',
    ], [$left, $right]);
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 400, 'height' => 200,
    ], [$content]);

    runResolver($root);

    assert_eq($content->w, 400, 'content fills width');
    assert_eq($content->h, 200, 'content fills height');
    assert_eq($left->w, 200, 'left gets half (200)');
    assert_eq($left->x, 0, 'left at x=0');
    assert_eq($right->w, 200, 'right gets half (200)');
    assert_eq($right->x, 200, 'right at x=200');
});

test('三层嵌套 flex column 传递 flex:1', function () {
    $grandchild = makeNode('div', ['flex' => '1'], [], 'grandchild');
    $child = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column', 'flex' => '1',
    ], [$grandchild]);
    $parent = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column', 'flex' => '1',
    ], [$child]);
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 200, 'height' => 300,
    ], [$parent]);

    runResolver($root);

    assert_eq($parent->h, 300, 'parent fills 300');
    assert_eq($child->h, 300, 'child fills 300');
    assert_eq($grandchild->h, 300, 'grandchild fills 300');
});

// ============================================================
// Group 2: position:fixed 在 flex 中
// ============================================================
echo "\n--- Group 2: position:fixed 在 flex 中 ---\n";

test('fixed 元素相对视口定位', function () {
    $fixedChild = makeNode('div', [
        'position' => 'fixed', 'left' => 20, 'top' => 30,
        'width' => 100, 'height' => 50,
    ], [], 'fixed');
    $scrollContent = makeNode('div', ['height' => 1000]);
    $flexContainer = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 400, 'height' => 300,
        'overflowY' => 'auto',
    ], [$scrollContent, $fixedChild]);
    $root = makeNode('div', [
        'width' => 800, 'height' => 600,
    ], [$flexContainer]);

    $flexContainer->scrollTop = 100;
    runResolver($root);

    assert_eq($fixedChild->x, 20, 'fixed x=20 (相对视口)');
    assert_eq($fixedChild->y, 30, 'fixed y=30 (不受 scrollTop=100 影响)');
});

test('fixed bottom/right 定位', function () {
    $fixedBtn = makeNode('div', [
        'position' => 'fixed', 'bottom' => 40, 'right' => 40,
        'width' => 44, 'height' => 44,
    ], [], 'fab');
    $content = makeNode('div', ['height' => 2000]);
    $main = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column', 'flex' => '1',
    ], [$content, $fixedBtn]);
    $header = makeNode('div', ['height' => 56]);
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 1440, 'height' => 900,
    ], [$header, $main]);

    runResolver($root);

    // bottom=40, right=40 -> x=1440-44-40=1356, y=900-44-40=816
    assert_eq($fixedBtn->x, 1356, 'fixed x = 1440-44-40 = 1356');
    assert_eq($fixedBtn->y, 816, 'fixed y = 900-44-40 = 816');
});

// ============================================================
// Group 3: scroll + flex column
// ============================================================
echo "\n--- Group 3: scroll + flex column ---\n";

test('overflow-y:auto 在 flex column 中高度受限', function () {
    $tallContent = makeNode('div', ['height' => 600, 'flexShrink' => 0], [], 'tall');
    $scrollArea = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'flex' => '1', 'overflowY' => 'auto',
    ], [$tallContent]);
    $header = makeNode('div', ['height' => 50]);
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 400, 'height' => 400,
    ], [$header, $scrollArea]);

    runResolver($root);

    assert_eq($scrollArea->y, 50, 'scrollArea below header');
    assert_eq($scrollArea->h, 350, 'scrollArea h = 400-50 = 350');
    assert_true($scrollArea->isScrollContainer, 'scrollArea is scroll container');
    assert_true($scrollArea->contentHeight >= 600, 'contentHeight >= 600');
});

// ============================================================
// Group 4: padding 影响 flex child
// ============================================================
echo "\n--- Group 4: padding 影响 flex child ---\n";

test('padding 偏移 flex 子节点位置', function () {
    $child = makeNode('div', ['width' => 50, 'height' => 30], [], 'child');
    $flex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
        'paddingLeft' => 20, 'paddingTop' => 15,
    ], [$child]);
    $root = makeNode('div', ['width' => 400, 'height' => 300], [$flex]);

    runResolver($root);

    assert_eq($child->x, 20, 'child x = paddingLeft = 20');
    assert_eq($child->y, 15, 'child y = paddingTop = 15');
});

test('padding 影响 flex column 子节点可用宽度', function () {
    $child = makeNode('div', ['height' => 50], [], 'child');
    $flex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 500, 'height' => 200,
        'paddingLeft' => 20, 'paddingRight' => 20,
    ], [$child]);
    $root = makeNode('div', ['width' => 600, 'height' => 400], [$flex]);

    runResolver($root);

    // CSS content-box standard: content width = CSS width = 500 (padding added outside)
    assert_eq($child->w, 500, 'child w = content width = 500 (CSS content-box standard)');
});

// ============================================================
// Group 5: gap 组合场景
// ============================================================
echo "\n--- Group 5: gap 组合 ---\n";

test('flex column with gap + flex:1', function () {
    $c1 = makeNode('div', ['flex' => '1'], [], 'c1');
    $c2 = makeNode('div', ['height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 200, 'height' => 300,
        'gap' => 10,
    ], [$c1, $c2]);

    runResolver($root);

    // c2=50, gap=10, c1 gets remaining 300-50-10 = 240
    assert_eq($c1->h, 240, 'c1 h = 300-50-10 = 240');
    assert_eq($c1->y, 0, 'c1 y=0');
    assert_eq($c2->y, 250, 'c2 y = 240+10 = 250');
});

// ============================================================
// Group 6: visualW/visualH — 严格 CSS 盒模型
// ============================================================
echo "\n--- Group 6: visualW/visualH ---\n";

test('content-box padding 使 visualW > w', function () {
    $child = makeNode('div', ['width' => 50, 'height' => 30], [], 'child');
    $flex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 200, 'height' => 100,
        'paddingLeft' => 20, 'paddingRight' => 20,
        'paddingTop' => 10, 'paddingBottom' => 10,
    ], [$child]);
    $root = makeNode('div', ['width' => 400, 'height' => 300], [$flex]);

    runResolver($root);

    // 容器 content-box: visualW = 200 + 20 + 20 = 240, visualH = 100 + 10 + 10 = 120
    assert_eq($flex->w, 200, 'flex w = 200 (CSS width)');
    assert_eq($flex->visualW, 240, 'flex visualW = 200+20+20 = 240');
    assert_eq($flex->h, 100, 'flex h = 100 (CSS height)');
    assert_eq($flex->visualH, 120, 'flex visualH = 100+10+10 = 120');
});

test('border-box padding 使 visualW = w', function () {
    $child = makeNode('div', ['width' => 50, 'height' => 30], [], 'child');
    $flex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 200, 'height' => 100,
        'paddingLeft' => 20, 'paddingRight' => 20,
        'paddingTop' => 10, 'paddingBottom' => 10,
        'boxSizing' => 'border-box',
    ], [$child]);
    $root = makeNode('div', ['width' => 400, 'height' => 300], [$flex]);

    runResolver($root);

    // 容器 border-box: visualW = 200 (CSS width = border-box)
    assert_eq($flex->w, 200, 'flex w = 200 (CSS width)');
    assert_eq($flex->visualW, 200, 'flex visualW = w (border-box)');
    assert_eq($flex->h, 100, 'flex h = 100 (CSS height)');
    assert_eq($flex->visualH, 100, 'flex visualH = h (border-box)');
});

test('flex-wrap 使用 visualW 正确计算行总宽度', function () {
    $c1 = makeNode('div', ['width' => 120, 'height' => 30], [], 'c1');
    $c2 = makeNode('div', ['width' => 120, 'height' => 30], [], 'c2');
    $flex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'flexWrap' => 'wrap',
        'width' => 200, 'height' => 100,
        'paddingLeft' => 10, 'paddingRight' => 10,
    ], [$c1, $c2]);
    $root = makeNode('div', ['width' => 400, 'height' => 300], [$flex]);

    runResolver($root);

    // 可用宽度 = 200 - 10 - 10 = 180，c1=120 < 180 → 第一行
    // c2=120 > 剩余 60 → 换行
    // align-content:stretch (CSS 默认): 剩余 100-60=40 均分, 每行+20
    assert_eq($c1->x, 10, 'c1 x = paddingLeft = 10');
    assert_eq($c2->y, 50, 'c2 y 换行在第二行 (stretch, 行高=30+20=50)');
});

test('block 子项 padding 影响父容器 stackY', function () {
    $c1 = makeNode('div', ['width' => 100, 'height' => 40, 'paddingTop' => 5], [], 'c1');
    $c2 = makeNode('div', ['width' => 100, 'height' => 30], [], 'c2');
    $container = makeNode('div', [
        'width' => 200,
    ], [$c1, $c2]);
    $root = makeNode('div', ['width' => 400, 'height' => 300], [$container]);

    runResolver($root);

    // c1 visualH = 40 + 5 = 45 (paddingTop)
    // c2 y = c1.y + c1.visualH = 0 + 45 = 45
    assert_eq($c2->y, 45, 'c2 y = c1.y + c1.visualH = 0 + 45 = 45');
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
echo "All tests passed.\n";
