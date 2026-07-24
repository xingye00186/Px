<?php
/**
 * Level 2: Flexbox 布局
 *
 * 测试目标：
 *   1. flex-direction:row 水平排列
 *   2. flex-direction:column 垂直排列
 *   3. flex:1 自动填充剩余空间
 *   4. gap 间距
 *   5. justify-content:center/space-between
 *   6. align-items:center
 *   7. 嵌套 flex（行内列）
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: 简单 flex row ──
$tests['flex row 三个子项水平排列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:60px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'B'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'C'),
        ])
    );
    assert_contains($result, 'div (80,0 80x40) text="B"', 'flex row: B.x = A.x + A.w = 0 + 80 = 80, same y');
    return $result;
};

// ── Test 2: flex column ──
$tests['flex column 三个子项垂直排列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:200px;height:200px'], [
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'B'),
        ])
    );
    assert_contains($result, 'div (0,40 200x40) text="B"', 'flex column: B.y = A.y + A.h = 0 + 40 = 40');
    return $result;
};

// ── Test 3: flex:1 自动填充 ──
$tests['flex:1 两个子项平分空间'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:300px;height:50px'], [
            VNode::h('div', ['style' => 'flex:1;height:50px'], 'Left'),
            VNode::h('div', ['style' => 'flex:1;height:50px'], 'Right'),
        ])
    );
    assert_contains($result, 'div (0,0 150x50) fg=1 text="Left"', 'flex:1 each child 300/2 = 150 wide (equal distribution)');
    return $result;
};

// ── Test 4: gap 间距 ──
$tests['flex row with gap=16px'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:16px;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:100px;height:50px'], 'A'),
            VNode::h('div', ['style' => 'width:100px;height:50px'], 'B'),
        ])
    );
    assert_contains($result, 'div (116,0 100x50) text="B"', 'gap 16px: B.x = A.x + A.w + gap = 0 + 100 + 16 = 116');
    return $result;
};

// ── Test 5: justify-content:center ──
$tests['flex row justify-content=center'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;justify-content:center;width:400px;height:60px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'Center'),
        ])
    );
    assert_contains($result, 'div (160,0 80x40) text="Center"', 'justify-content center: (400-80)/2 = 160');
    return $result;
};

// ── Test 6: 嵌套 flex（row 内的 column） ──
$tests['嵌套 flex (row > column)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:100px;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;flex:1;height:100px'], [
                VNode::h('div', ['style' => 'height:40px'], 'Top'),
                VNode::h('div', ['style' => 'height:40px'], 'Bottom'),
            ]),
            VNode::h('div', ['style' => 'width:100px;height:100px'], 'Side'),
        ])
    );
    assert_contains($result, 'div (0,0 292x100) [dsp=flex] fg=1', 'flex:1 child width = 400-8-100 = 292');
    return $result;
};

// ── Test 7: flex-wrap 折行 ──
$tests['flex-wrap 子项超出折行'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;flex-wrap:wrap;width:200px;height:auto;gap:4px'], [
            VNode::h('div', ['style' => 'width:100px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:100px;height:30px'], 'B'),
            VNode::h('div', ['style' => 'width:100px;height:30px'], 'C'),
        ])
    );
    assert_contains($result, 'div (0,34 100x30) text="B"', 'flex-wrap: B wraps to next line y=34 = 30+4');
    return $result;
};

// ── Test 8: align-items:center ──
$tests['align-items center 交叉轴居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:center;width:300px;height:80px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:60px;height:40px'], 'B'),
        ])
    );
    assert_contains($result, 'div (0,25 60x30) text="A"', 'align-items center: A.y = (80-30)/2 = 25');
    assert_contains($result, 'div (60,20 60x40) text="B"', 'align-items center: B.y = (80-40)/2 = 20');
    return $result;
};

// ── Test 9: align-items:stretch ──
$tests['align-items stretch 交叉轴拉伸'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:stretch;width:300px;height:80px'], [
            VNode::h('div', ['style' => 'width:60px'], 'A'),
            VNode::h('div', ['style' => 'width:60px'], 'B'),
        ])
    );
    assert_contains($result, 'div (0,0 60x80) text="A"', 'align-items stretch: child height = container cross size = 80');
    return $result;
};

// ── Test 10: flex-shrink ──
$tests['flex-shrink 子项超出时收缩'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:200px;height:50px'], [
            VNode::h('div', ['style' => 'width:150px;height:50px;flex-shrink:1'], 'Wide'),
            VNode::h('div', ['style' => 'width:100px;height:50px;flex-shrink:1'], 'Narrow'),
        ])
    );
    assert_contains($result, 'div (0,0 120x50) text="Wide"', 'flex-shrink: Wide shrinks 150->120, shrink factor applied');
    assert_contains($result, 'div (120,0 80x50) text="Narrow"', 'flex-shrink: total 120+80=200 fits container');
    return $result;
};

// ── Test 11: order 重排 ──
$tests['order 属性改变视觉顺序'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:100px;height:50px;order:2'], 'Third'),
            VNode::h('div', ['style' => 'width:100px;height:50px;order:1'], 'Second'),
            VNode::h('div', ['style' => 'width:100px;height:50px;order:0'], 'First'),
        ])
    );
    assert_contains($result, 'div (0,0 100x50) text="First"', 'order 0 renders before order 1 and 2');
    assert_contains($result, 'div (200,0 100x50) text="Third"', 'order 2 renders last at x=200');
    return $result;
};

// ── Test 12: justify-content space-between ──
$tests['justify-content space-between 两端对齐'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;justify-content:space-between;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'L'),
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'R'),
        ])
    );
    assert_contains($result, 'div (0,0 60x30) text="L"', 'space-between: first item flush at start');
    assert_contains($result, 'div (340,0 60x30) text="R"', 'space-between: last item flush at end = 400-60 = 340');
    return $result;
};

// ── Test 13: justify-content space-around ──
$tests['justify-content space-around 均匀分布'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;justify-content:space-around;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'B'),
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'C'),
        ])
    );
    assert_contains($result, 'div (36,0 60x30) text="A"', 'space-around: equal spacing on each side of items');
    return $result;
};

// ── Test 14: flex-direction row-reverse ──
$tests['flex-direction row-reverse 反向行'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row-reverse;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:80px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:80px;height:30px'], 'B'),
        ])
    );
        assert_contains($result, 'div (320,0 80x30) text="A"', 'row-reverse: A 包 main-start=right → A.x=400-80=320 (Chrome/CSS Flexbox §5.3)');
        assert_contains($result, 'div (240,0 80x30) text="B"', 'row-reverse: B 在 A 左侧 → B.x=320-80=240');
    return $result;
};

// ── Test 15: flex-direction column-reverse ──
$tests['flex-direction column-reverse 反向列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column-reverse;width:200px;height:200px'], [
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'B'),
        ])
    );
        assert_contains($result, 'div (0,160 200x40) text="A"', 'column-reverse: A 包 main-start=bottom → A.y=200-40=160 (Chrome/CSS Flexbox §5.3)');
        assert_contains($result, 'div (0,120 200x40) text="B"', 'column-reverse: B 在 A 上方 → B.y=160-40=120');
    return $result;
};

// ── Test 16: flex item 固定宽度 ──
$tests['flex item 显式固定宽度'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:100px;height:30px;flex-shrink:0'], 'Fixed'),
            VNode::h('div', ['style' => 'flex:1;height:30px'], 'Flex'),
        ])
    );
    assert_contains($result, 'div (100,0 300x30) fg=1 text="Flex"', 'flex:1 fills remaining space: 400-100 = 300');
    return $result;
};

// ── Test 17: auto margin ──
$tests['auto margin-left 在 flex 中右推'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'Left'),
            VNode::h('div', ['style' => 'width:60px;height:30px;margin-left:auto'], 'Right'),
        ])
    );
    assert_contains($result, 'div (340,0 60x30) text="Right"', 'margin-left:auto pushes to end: 400-60 = 340');
    return $result;
};

// ── Test 18: flex 内 padding 影响子项 ──
$tests['flex 容器 padding 影响子项位置'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:100px;padding:10px'], [
            VNode::h('div', ['style' => 'width:80px;height:50px'], 'Item'),
        ])
    );
    assert_contains($result, 'div (10,10 80x50) text="Item"', 'padding 10px offsets child by padding edge');
    return $result;
};

// ── Test 19: flex row gap + padding 混合 ──
$tests['flex row gap + padding 组合'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:16px;padding:12px;width:400px;height:80px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'B'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'C'),
        ])
    );
    assert_contains($result, 'div (108,12 80x40) text="B"', 'gap+padding: B.x = 12(padding) + 80 + 16(gap) = 108');
    return $result;
};

// ── Test 20: flex column 内 flex:1 高度填充跨嵌套 ──
$tests['flex column 内 flex:1 高度填充跨嵌套'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:300px;height:200px'], [
            VNode::h('div', ['style' => 'height:40px;flex-shrink:0'], 'Header'),
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;flex:1'], [
                VNode::h('div', ['style' => 'height:30px;flex-shrink:0'], 'SubHeader'),
                VNode::h('div', ['style' => 'flex:1;background:green'], 'Fill'),
            ]),
            VNode::h('div', ['style' => 'height:30px;flex-shrink:0'], 'Footer'),
        ])
    );
    assert_contains($result, 'div (0,70 300x100) fg=1 text="Fill"', 'flex:1 Fill h = 200-40-30-30 = 100 remaining');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-02-Flexbox.snap';
run_css_tests('Level 2 - Flexbox 基础', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
