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

    // content width = 500 - 20 - 20 = 460
    assert_eq($child->w, 460, 'child w = content width = 460');
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
// Group 6: Bilibili 骨架
// ============================================================
echo "\n--- Group 6: Bilibili 页面骨架 ---\n";

test('Bilibili 完整页面骨架精确坐标', function () {
    $navbar = makeNode('div', [
        'width' => 1440, 'height' => 56,
        'display' => 'flex', 'alignItems' => 'center',
    ]);
    $catTabs = makeNode('div', [
        'width' => 1440, 'height' => 74,
        'display' => 'flex', 'flexDirection' => 'column',
    ]);
    $mainContent = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'flex' => '1',
        'paddingLeft' => 24, 'paddingRight' => 24, 'paddingTop' => 16,
    ]);
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 1440, 'height' => 900,
    ], [$navbar, $catTabs, $mainContent]);

    runResolver($root);

    assert_eq($navbar->x, 0, 'navbar x=0');
    assert_eq($navbar->y, 0, 'navbar y=0');
    assert_eq($navbar->w, 1440, 'navbar w=1440');
    assert_eq($navbar->h, 56, 'navbar h=56');

    assert_eq($catTabs->x, 0, 'catTabs x=0');
    assert_eq($catTabs->y, 56, 'catTabs y=56 (below navbar)');
    assert_eq($catTabs->w, 1440, 'catTabs w=1440');
    assert_eq($catTabs->h, 74, 'catTabs h=74');

    assert_eq($mainContent->x, 0, 'mainContent x=0');
    assert_eq($mainContent->y, 130, 'mainContent y=56+74=130');
    assert_eq($mainContent->w, 1440, 'mainContent w=1440');
    assert_eq($mainContent->h, 770, 'mainContent h=900-130=770');
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
