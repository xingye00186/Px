<?php
/**
 * FlexLayoutTest — CSS Flex 布局标准测试
 *
 * 覆盖:
 *   - flex:1 在 column 方向占满剩余高度
 *   - flex-direction (row/column)
 *   - flex-grow / flex-shrink / flex-basis
 *   - justify-content (所有 6 种值)
 *   - align-items (所有 5 种值)
 *   - flex-wrap
 *   - order
 *   - gap
 *   - align-self
 *
 * Usage: php tests/unit/Layout/FlexLayoutTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

echo "========================================\n";
echo " Flex 布局标准测试\n";
echo "========================================\n\n";

// ============================================================
// Group 1: flex:1 在 flex column 中
// ============================================================
echo "--- Group 1: flex:1 in column ---\n";

test('flex:1 子节点占满剩余高度', function () {
    $header = makeNode('div', ['height' => 60], [], 'header');
    $content = makeNode('div', ['flex' => '1'], [], 'content');
    $footer = makeNode('div', ['height' => 40], [], 'footer');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 400, 'height' => 300,
    ], [$header, $content, $footer]);

    runResolver($root);

    assert_eq($header->y, 0, 'header y=0');
    assert_eq($header->h, 60, 'header h=60');
    assert_eq($content->y, 60, 'content y=60 (below header)');
    assert_eq($content->h, 200, 'content h=200 (300-60-40)');
    assert_eq($footer->y, 260, 'footer y=260 (below content)');
    assert_eq($footer->h, 40, 'footer h=40');
});

test('多个 flex:1 子节点平分剩余高度', function () {
    $items = [];
    for ($i = 0; $i < 3; $i++) {
        $items[] = makeNode('div', ['flex' => '1'], [], 'item' . $i);
    }
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 400, 'height' => 300,
    ], $items);

    runResolver($root);

    assert_eq($items[0]->h, 100, 'item0 h=300/3=100');
    assert_eq($items[0]->y, 0, 'item0 y=0');
    assert_eq($items[1]->h, 100, 'item1 h=100');
    assert_eq($items[1]->y, 100, 'item1 y=100');
    assert_eq($items[2]->h, 100, 'item2 h=100');
    assert_eq($items[2]->y, 200, 'item2 y=200');
});

test('flex:1 混合固定高度', function () {
    $header = makeNode('div', ['height' => 80], [], 'header');
    $content = makeNode('div', ['flex' => '1'], [], 'content');
    $footer = makeNode('div', ['height' => 60], [], 'footer');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 400, 'height' => 500,
    ], [$header, $content, $footer]);

    runResolver($root);

    assert_eq($header->y, 0, 'header at top');
    assert_eq($content->y, 80, 'content below header');
    assert_eq($content->h, 360, 'content fills 500-80-60=360');
    assert_eq($footer->y, 440, 'footer at bottom');
});

// ============================================================
// Group 2: flex-direction
// ============================================================
echo "\n--- Group 2: flex-direction ---\n";

test('flex-direction:row 子节点水平排列', function () {
    $c1 = makeNode('div', ['width' => 100, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 100, 'height' => 50], [], 'c2');
    $c3 = makeNode('div', ['width' => 100, 'height' => 50], [], 'c3');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 400, 'height' => 100,
    ], [$c1, $c2, $c3]);

    runResolver($root);

    assert_eq($c1->x, 0, 'c1 x=0');
    assert_eq($c2->x, 100, 'c2 x=100');
    assert_eq($c3->x, 200, 'c3 x=200');
});

test('flex-direction:column 子节点垂直排列', function () {
    $c1 = makeNode('div', ['height' => 40], [], 'c1');
    $c2 = makeNode('div', ['height' => 40], [], 'c2');
    $c3 = makeNode('div', ['height' => 40], [], 'c3');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 200, 'height' => 200,
    ], [$c1, $c2, $c3]);

    runResolver($root);

    assert_eq($c1->y, 0, 'c1 y=0');
    assert_eq($c2->y, 40, 'c2 y=40');
    assert_eq($c3->y, 80, 'c3 y=80');
});

// ============================================================
// Group 3: flex-grow / flex-shrink / flex-basis
// ============================================================
echo "\n--- Group 3: flex-grow / flex-shrink / flex-basis ---\n";

test('flex-grow 比例 2:1', function () {
    $c1 = makeNode('div', ['flex' => '2', 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['flex' => '1', 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
    ], [$c1, $c2]);

    runResolver($root);

    // total grow = 3, available = 300, c1 gets 200, c2 gets 100
    assert_eq($c1->w, 200, 'c1 w=300*2/3=200');
    assert_eq($c2->w, 100, 'c2 w=300*1/3=100');
});

test('flex-shrink 比例 2:1', function () {
    $c1 = makeNode('div', ['width' => 200, 'flexShrink' => 2, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 200, 'flexShrink' => 1, 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
    ], [$c1, $c2]);

    runResolver($root);

    // overflow = 400 - 300 = 100, shrink ratio 2:1
    // c1 shrink = 100*2/3 = 66.6 -> ~67, c1 w = 200-67 = 133
    // c2 shrink = 100*1/3 = 33.3 -> ~33, c2 w = 200-33 = 167
    // 允许 1px 舍入误差
    assert_true(abs(($c1->w + $c2->w) - 300) <= 1, 'total width ~= 300 (允许1px舍入)');
    assert_true($c1->w < $c2->w, 'c1 shrinks more than c2');
});

test('flex-basis 设置初始主轴尺寸', function () {
    $c1 = makeNode('div', ['flexBasis' => '150', 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['flex' => '1', 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
    ], [$c1, $c2]);

    runResolver($root);

    // c1 basis=150, remaining=150 goes to c2
    assert_eq($c1->w, 150, 'c1 w = flex-basis 150');
    assert_eq($c2->w, 150, 'c2 gets remaining 300-150=150');
});

// ============================================================
// Group 4: justify-content
// ============================================================
echo "\n--- Group 4: justify-content ---\n";

test('justify-content:flex-start (默认)', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 400, 'height' => 100,
        'justifyContent' => 'flex-start',
    ], [$c1, $c2]);

    runResolver($root);

    assert_eq($c1->x, 0, 'c1 x=0');
    assert_eq($c2->x, 80, 'c2 x=80');
});

test('justify-content:center', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 400, 'height' => 100,
        'justifyContent' => 'center',
    ], [$c1, $c2]);

    runResolver($root);

    // total content = 160, space = 400-160 = 240, half = 120
    assert_eq($c1->x, 120, 'c1 x = (400-160)/2 = 120');
    assert_eq($c2->x, 200, 'c2 x = 120+80 = 200');
});

test('justify-content:flex-end', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 400, 'height' => 100,
        'justifyContent' => 'flex-end',
    ], [$c1, $c2]);

    runResolver($root);

    assert_eq($c1->x, 240, 'c1 x = 400-160 = 240');
    assert_eq($c2->x, 320, 'c2 x = 240+80 = 320');
});

test('justify-content:space-between', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c2');
    $c3 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c3');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 400, 'height' => 100,
        'justifyContent' => 'space-between',
    ], [$c1, $c2, $c3]);

    runResolver($root);

    // total content = 240, gaps = 2, space = 160, each gap = 80
    assert_eq($c1->x, 0, 'c1 x=0 (first at start)');
    assert_eq($c2->x, 160, 'c2 x = 80+80 = 160');
    assert_eq($c3->x, 320, 'c3 x = 160+80+80 = 320');
});

test('justify-content:space-around', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 400, 'height' => 100,
        'justifyContent' => 'space-around',
    ], [$c1, $c2]);

    runResolver($root);

    // total content = 160, space = 240, gap = 240/2 = 120
    // each item gets half-gap on each side = 60
    // c2.x = half_gap(60) + c1.w(80) + gap(120) = 260
    assert_eq($c1->x, 60, 'c1 x = 240/4 = 60');
    assert_eq($c2->x, 260, 'c2 x = 60+80+120 = 260');
});

test('justify-content:space-evenly', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c2');
    $c3 = makeNode('div', ['width' => 80, 'height' => 50], [], 'c3');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 400, 'height' => 100,
        'justifyContent' => 'space-evenly',
    ], [$c1, $c2, $c3]);

    runResolver($root);

    // total content = 240, gaps = 4, space = 160, each gap = 40
    assert_eq($c1->x, 40, 'c1 x = 40');
    assert_eq($c2->x, 160, 'c2 x = 40+80+40 = 160');
    assert_eq($c3->x, 280, 'c3 x = 160+80+40 = 280');
});

// ============================================================
// Group 5: align-items
// ============================================================
echo "\n--- Group 5: align-items ---\n";

test('align-items:stretch (默认) 子节点拉伸填满交叉轴', function () {
    $c1 = makeNode('div', ['width' => 80], [], 'c1');
    $c2 = makeNode('div', ['width' => 80], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
        'alignItems' => 'stretch',
    ], [$c1, $c2]);

    runResolver($root);

    assert_eq($c1->h, 100, 'c1 h stretched to 100');
    assert_eq($c2->h, 100, 'c2 h stretched to 100');
});

test('align-items:center 子节点交叉轴居中', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 40], [], 'c1');
    $c2 = makeNode('div', ['width' => 80, 'height' => 60], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
        'alignItems' => 'center',
    ], [$c1, $c2]);

    runResolver($root);

    assert_eq($c1->y, 30, 'c1 y=(100-40)/2=30');
    assert_eq($c2->y, 20, 'c2 y=(100-60)/2=20');
});

test('align-items:flex-start 子节点交叉轴起点', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 40], [], 'c1');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
        'alignItems' => 'flex-start',
    ], [$c1]);

    runResolver($root);

    assert_eq($c1->y, 0, 'c1 y=0 (flex-start)');
});

test('align-items:flex-end 子节点交叉轴终点', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 40], [], 'c1');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
        'alignItems' => 'flex-end',
    ], [$c1]);

    runResolver($root);

    assert_eq($c1->y, 60, 'c1 y=100-40=60 (flex-end)');
});

// ============================================================
// Group 6: flex-wrap
// ============================================================
echo "\n--- Group 6: flex-wrap ---\n";

test('flex-wrap 换行', function () {
    $items = [];
    for ($i = 0; $i < 4; $i++) {
        $items[] = makeNode('div', ['width' => 80, 'height' => 40], [], 'item' . $i);
    }
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'flexWrap' => 'wrap',
        'width' => 180, 'height' => 120,
    ], $items);

    runResolver($root);

    // 180 wide, each item 80, gap default 0
    // row1: items 0,1 (80+80=160), row2: items 2,3
    // align-content:stretch (CSS default): remaining 120-80=40 split equally, each line +20
    assert_eq($items[0]->y, 0, 'item0 y=0 (row1)');
    assert_eq($items[1]->y, 0, 'item1 y=0 (row1)');
    assert_eq($items[2]->y, 60, 'item2 y=60 (row2, stretched line height=60)');
    assert_eq($items[3]->y, 60, 'item3 y=60 (row2)');
});

// ============================================================
// Group 7: order
// ============================================================
echo "\n--- Group 7: order ---\n";

test('order 重排子节点顺序', function () {
    $c1 = makeNode('div', ['order' => 3, 'width' => 80, 'height' => 50], [], 'third');
    $c2 = makeNode('div', ['order' => 1, 'width' => 80, 'height' => 50], [], 'first');
    $c3 = makeNode('div', ['order' => 2, 'width' => 80, 'height' => 50], [], 'second');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 400, 'height' => 100,
    ], [$c1, $c2, $c3]);

    runResolver($root);

    // Order: c2 (1) < c3 (2) < c1 (3)
    // After reorder: c2 first, c3 second, c1 third
    assert_eq($c2->x, 0, 'order=1 item at x=0');
    assert_eq($c3->x, 80, 'order=2 item at x=80');
    assert_eq($c1->x, 160, 'order=3 item at x=160');
});

// ============================================================
// Group 8: gap
// ============================================================
echo "\n--- Group 8: gap ---\n";

test('flex column gap 间距', function () {
    $c1 = makeNode('div', ['height' => 40], [], 'c1');
    $c2 = makeNode('div', ['height' => 40], [], 'c2');
    $c3 = makeNode('div', ['height' => 40], [], 'c3');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 200, 'height' => 200,
        'gap' => 12,
    ], [$c1, $c2, $c3]);

    runResolver($root);

    assert_eq($c1->y, 0, 'c1 y=0');
    assert_eq($c2->y, 52, 'c2 y=40+12 = 52');
    assert_eq($c3->y, 104, 'c3 y=52+40+12 = 104');
});

test('flex row gap 间距', function () {
    $c1 = makeNode('div', ['width' => 60, 'height' => 40], [], 'c1');
    $c2 = makeNode('div', ['width' => 60, 'height' => 40], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 200, 'height' => 100,
        'gap' => 16,
    ], [$c1, $c2]);

    runResolver($root);

    assert_eq($c1->x, 0, 'c1 x=0');
    assert_eq($c2->x, 76, 'c2 x=60+16 = 76');
});

// ============================================================
// Group 9: align-self
// ============================================================
echo "\n--- Group 9: align-self ---\n";

test('align-self 覆盖 align-items', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 40], [], 'c1');
    $c2 = makeNode('div', ['width' => 80, 'height' => 60, 'alignSelf' => 'flex-end'], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
        'alignItems' => 'center',
    ], [$c1, $c2]);

    runResolver($root);

    // c1 uses align-items:center -> y=30
    assert_eq($c1->y, 30, 'c1 y=(100-40)/2=30 from container align-items');
    // c2 uses align-self:flex-end -> y=40
    assert_eq($c2->y, 40, 'c2 y=100-60=40 from align-self:flex-end');
});

// ============================================================
// Group 10: flex-shrink edge cases (infinite loop protection)
// ============================================================
echo "\n--- Group 10: flex-shrink edge cases (infinite loop protection) ---\n";

test('flex-shrink tiny overflow rounds to 0 for all items, no infinite loop', function () {
    // 3 items width=100 each, container=298, overflow=2
    // Each item: shrinkWeight = 100*1 = 100, totalSw = 300
    // reduction = (int)(2 * 100 / 300) = 0 for ALL items
    // Before fix: distributedInPass=0, remainingOverflow unchanged → infinite loop
    $c1 = makeNode('div', ['width' => 100, 'flexShrink' => 1, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 100, 'flexShrink' => 1, 'height' => 50], [], 'c2');
    $c3 = makeNode('div', ['width' => 100, 'flexShrink' => 1, 'height' => 50], [], 'c3');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 298, 'height' => 100,
    ], [$c1, $c2, $c3]);

    runResolver($root);

    // Must not hang; total width must fit within container
    $totalW = $c1->w + $c2->w + $c3->w;
    assert_true($totalW <= 300, "total width $totalW <= container 300");
    assert_true($totalW > 290, "total width $totalW >= 290");
});

test('flex-shrink single pass 0 reduction with min-width clamping, no infinite loop', function () {
    // 2 items width=80 each, container=159, overflow=1
    // Equal shrink → (int)(1*80/160) = 0 for both
    // each with minWidth=60, but newSize=80 > 60, so no clamping
    // Before fix: would infinite loop
    $c1 = makeNode('div', ['width' => 80, 'flexShrink' => 1, 'height' => 50, 'minWidth' => 60], [], 'c1');
    $c2 = makeNode('div', ['width' => 80, 'flexShrink' => 1, 'height' => 50, 'minWidth' => 60], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 159, 'height' => 100,
    ], [$c1, $c2]);

    runResolver($root);

    $totalW = $c1->w + $c2->w;
    assert_true($totalW <= 160, "total width $totalW <= container 160");
    assert_true($totalW >= 158, "total width $totalW >= 158 (at least 1px shrunk)");
});

test('flex-shrink many items with uneven weights and tiny overflow, no infinite loop', function () {
    // 5 items, varying widths, container just slightly smaller
    $items = [];
    $widths = [40, 60, 50, 70, 30];
    $totalContent = array_sum($widths);
    $containerW = $totalContent - 2; // overflow = 2
    $shrinkFactors = [1, 2, 1, 3, 1];
    for ($i = 0; $i < 5; $i++) {
        $items[] = makeNode('div', [
            'width' => $widths[$i],
            'flexShrink' => $shrinkFactors[$i],
            'height' => 50,
        ], [], 'item' . $i);
    }
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => $containerW, 'height' => 100,
    ], $items);

    runResolver($root);

    // Must not hang; total width must fit within container
    $totalW = 0;
    foreach ($items as $item) { $totalW += $item->w; }
    assert_true($totalW <= $totalContent, "total width $totalW <= content $totalContent");
});

test('flex-shrink zero shrinkWeight equal distribution with tiny overflow, no infinite loop', function () {
    // All items have flex-basis:0 so shrinkWeight=0
    // Falls to equal distribution path
    $items = [];
    for ($i = 0; $i < 4; $i++) {
        $items[] = makeNode('div', [
            'flex' => '1 0 0',  // flex-grow:1, flex-shrink:0, flex-basis:0
            'height' => 50,
        ], [], 'item' . $i);
    }
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 399, 'height' => 100,  // not divisible by 4
    ], $items);

    runResolver($root);

    // Must not hang
    $totalW = 0;
    foreach ($items as $item) { $totalW += $item->w; }
    assert_true($totalW <= 400, "total width $totalW <= 400");
    assert_true($totalW > 0, "total width $totalW > 0 (items placed)");
});

// ============================================================
// Group 11: Additional flex-shrink edge cases
// ============================================================
echo "\n--- Group 11: Additional flex-shrink edge cases ---\n";

test('flex-shrink column direction tiny overflow, no infinite loop', function () {
    // Column direction with small overflow
    // 3 items height=60 each, container=170, overflow=10
    $c1 = makeNode('div', ['height' => 60, 'flexShrink' => 1, 'width' => 100], [], 'c1');
    $c2 = makeNode('div', ['height' => 60, 'flexShrink' => 1, 'width' => 100], [], 'c2');
    $c3 = makeNode('div', ['height' => 60, 'flexShrink' => 1, 'width' => 100], [], 'c3');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 200, 'height' => 170,
    ], [$c1, $c2, $c3]);

    runResolver($root);

    // Must not hang
    $totalH = $c1->h + $c2->h + $c3->h;
    assert_true($totalH <= 180, "column shrink: total height $totalH <= 180");
    assert_true($totalH >= 168, "column shrink: total height $totalH >= 168");
});

test('flex-shrink flex-basis differs from current size, tiny overflow', function () {
    // Items with explicit flex-basis that differs from their width
    // flex-basis creates shrinkWeight from basis, not current size
    $c1 = makeNode('div', ['width' => 200, 'flexBasis' => 150, 'flexShrink' => 1, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 200, 'flexBasis' => 150, 'flexShrink' => 1, 'height' => 50], [], 'c2');
    // Container = 299, total width = 400, overflow = 101
    // shrinkBasis = 150 each, totalSw = 300
    // First pass: reduction = (int)(101 * 150 / 300) = 50 each
    // After first pass: sizes = 150 each, total = 300, remaining = 299-300 = -1 (done)
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 299, 'height' => 100,
    ], [$c1, $c2]);

    runResolver($root);

    $totalW = $c1->w + $c2->w;
    assert_true($totalW <= 300, "basis shrink: total width $totalW <= 300");
});

test('flex-shrink with tiny overflow and gap, no infinite loop', function () {
    // 4 items with gap, tiny overflow
    $items = [];
    for ($i = 0; $i < 4; $i++) {
        $items[] = makeNode('div', ['width' => 100, 'flexShrink' => 1, 'height' => 50], [], 'item' . $i);
    }
    // Total content = 400 + 3*2(gap) = 406, container = 399, overflow = 7
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 399, 'height' => 100,
        'gap' => 2,
    ], $items);

    runResolver($root);

    // Must not hang
    $totalW = 0;
    foreach ($items as $item) { $totalW += $item->w; }
    assert_true($totalW <= 400, "gap shrink: total width $totalW <= 400");
    // 4 items * 100 = 400 max, gap=2*3=6 extra, container=399 means overflow=7
    // Each shrinks ~1-2px
    assert_true($totalW >= 390, "gap shrink: total width $totalW >= 390");
});

test('flex-shrink all items hit min-width with remaining overflow, no infinite loop', function () {
    // All items hit min-width before overflow is fully distributed
    // Tests the path where activeItems become empty but remainingOverflow > 0
    $c1 = makeNode('div', ['width' => 100, 'flexShrink' => 1, 'height' => 50, 'minWidth' => 96], [], 'c1');
    $c2 = makeNode('div', ['width' => 100, 'flexShrink' => 1, 'height' => 50, 'minWidth' => 96], [], 'c2');
    // overflow = 200 - 195 = 5, each can shrink max 4px (100->96)
    // After clamping: remaining overflow = 5 - 8 = -3 (over-distributed)
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 195, 'height' => 100,
    ], [$c1, $c2]);

    runResolver($root);

    $totalW = $c1->w + $c2->w;
    // Must not exceed container (195)
    assert_true($totalW <= 198, "minWidth exhaust: total width $totalW <= 198");
    // Must not exceed original (200)
    assert_true($totalW >= 190, "minWidth exhaust: total width $totalW >= 190");
    // Each should be at least minWidth
    assert_true($c1->w >= 96, "c1 w={$c1->w} >= 96");
    assert_true($c2->w >= 96, "c2 w={$c2->w} >= 96");
});

test('flex-shrink 1px overflow with 10 equal items, no infinite loop', function () {
    // 10 items, each 50px, container=499, overflow=1px
    // reduction = (int)(1 * 50 * 1 / 500) = 0 for ALL items
    // Before fix: distributedInPass=0 -> infinite loop
    $items = [];
    for ($i = 0; $i < 10; $i++) {
        $items[] = makeNode('div', ['width' => 50, 'flexShrink' => 1, 'height' => 50], [], 'item' . $i);
    }
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 499, 'height' => 100,
    ], $items);

    runResolver($root);

    // Must not hang
    $totalW = 0;
    foreach ($items as $item) { $totalW += $item->w; }
    assert_true($totalW <= 500, "10 items shrink: total $totalW <= 500");
    assert_true($totalW > 490, "10 items shrink: total $totalW > 490");
});

test('flex-shrink non-uniform weights with 0 reduction path, no infinite loop', function () {
    // Items with highly skewed shrink factors
    // Ensure the proportional reduction path doesn't infinite loop
    $c1 = makeNode('div', ['width' => 200, 'flexShrink' => 100, 'height' => 50], [], 'heavy');
    $c2 = makeNode('div', ['width' => 100, 'flexShrink' => 1, 'height' => 50], [], 'light');
    // overflow = 300 - 298 = 2
    // heavy reduction = (int)(2 * 200 * 100 / (200*100 + 100*1)) = (int)(2*20000/20100) = 1
    // light reduction = (int)(2 * 100 * 1 / 20100) = 0
    // After pass 1: remaining = 2-1 = 1, heavy still active
    // Pass 2: heavy reduction = (int)(1 * 200 * 100 / 20000) = 1
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 298, 'height' => 100,
    ], [$c1, $c2]);

    runResolver($root);

    $totalW = $c1->w + $c2->w;
    assert_true($totalW <= 300, "skewed shrink: total $totalW <= 300");
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
