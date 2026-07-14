<?php
/**
 * Level 13: Overflow Advanced / 高级溢出
 *
 * 测试目标：
 *   1. overflow-x:scroll 在 flex 行中
 *   2. overflow-y:auto 在 grid 中
 *   3. text-overflow ellipsis 在 flex item 中
 *   4. overflow:hidden 在 grid cell 中裁剪
 *   5. overflow-x:scroll + overflow-y:hidden 独立方向
 *   6. 嵌套滚动容器
 *   7. overflow:visible 在 flex 中内容溢出
 *   8. overflow:clip 裁剪
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: overflow-x:scroll 在 flex 行中 ──
$tests['overflow-x:scroll 在 flex row 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:300px;height:80px;overflow-x:scroll'], [
            VNode::h('div', ['style' => 'width:200px;height:60px;flex-shrink:0;background:#F00'], 'Item 1'),
            VNode::h('div', ['style' => 'width:200px;height:60px;flex-shrink:0;background:#0F0'], 'Item 2'),
            VNode::h('div', ['style' => 'width:200px;height:60px;flex-shrink:0;background:#00F'], 'Item 3'),
        ])
    );
    assert_contains($result, 'scroll ch=60 cw=300', 'overflow-x:scroll creates scroll container cw=300 > ch=60');
    return $result;
};

// ── Test 2: overflow-y:auto 在 grid 中 ──
$tests['overflow-y:auto 在 grid cell 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 200px;width:400px;height:150px;gap:8px'], [
            VNode::h('div', ['style' => 'height:60px'], 'Normal'),
            VNode::h('div', ['style' => 'height:60px;overflow-y:auto'], 'Normal 2'),
        ])
    );
    assert_contains($result, 'div (0,0 200x60) text="Normal"', 'First grid cell contains Normal');
    return $result;
};

// ── Test 3: text-overflow ellipsis 在 flex item 中 ──
$tests['text-overflow ellipsis 在 flex item 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:200px;height:40px'], [
            VNode::h('div', ['style' => 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap'], 'Very long text that should be clipped with ellipsis'),
        ])
    );
    assert_contains($result, 'text="Very long text that should be clipped wi..."', 'text-overflow:ellipsis truncates with ellipsis mark');
    return $result;
};

// ── Test 4: overflow:hidden 在 grid cell 中裁剪 ──
$tests['overflow:hidden 在 grid cell 中裁剪内容'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:150px;width:150px;height:80px'], [
            VNode::h('div', ['style' => 'overflow:hidden;height:80px'], [
                VNode::h('div', ['style' => 'width:300px;height:60px;background:#F00'], 'Overflowing content'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 150x80) ov=hidden', 'overflow:hidden clip container at 150x80');
    return $result;
};

// ── Test 5: overflow-x:scroll + overflow-y:hidden 独立方向 ──
$tests['overflow-x:scroll + overflow-y:hidden 独立方向'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:100px;overflow-x:scroll;overflow-y:hidden'], [
            VNode::h('div', ['style' => 'width:600px;height:100px'], [
                VNode::h('div', ['style' => 'display:inline-block;width:150px;height:80px;background:#F00'], 'A'),
                VNode::h('div', ['style' => 'display:inline-block;width:150px;height:80px;background:#0F0'], 'B'),
                VNode::h('div', ['style' => 'display:inline-block;width:150px;height:80px;background:#00F'], 'C'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 200x100) scroll ch=100 cw=600', 'overflow-x:scroll overflow-y:hidden creates single-direction scroll');
    return $result;
};

// ── Test 6: 嵌套滚动容器 ──
$tests['嵌套滚动容器 外层+Y 内层+X'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:200px;overflow-y:auto'], [
            VNode::h('div', ['style' => 'height:100px;background:#EEE'], 'Header'),
            VNode::h('div', ['style' => 'width:250px;height:100px;overflow-x:scroll'], [
                VNode::h('div', ['style' => 'width:500px;height:80px;background:#DDD'], 'Horizontal scroll content'),
            ]),
            VNode::h('div', ['style' => 'height:200px;background:#CCC'], 'Footer'),
        ])
    );
    assert_contains($result, 'div (0,0 300x200) scroll ch=400 cw=300', 'outer Y-scroll ch=400 > container h=200, inner X-scroll at (0,100)');
    return $result;
};

// ── Test 7: overflow:visible 在 flex 中内容溢出 ──
$tests['overflow:visible 在 flex 中内容溢出'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:150px;height:50px;overflow:visible;background:#EEE'], [
            VNode::h('div', ['style' => 'width:250px;height:40px;background:#F00'], 'Overflowing'),
        ])
    );
    assert_contains($result, 'div (0,0 150x50) [dsp=flex]', 'overflow:visible flex container at 150x50 allows content overflow');
    return $result;
};

// ── Test 8: overflow:clip 裁剪 ──
$tests['overflow:clip 裁剪内容'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:100px;height:60px;overflow:clip;left:10px;top:10px'], [
            VNode::h('div', ['style' => 'position:absolute;left:50px;top:-10px;width:80px;height:40px;background:#F00'], 'Clipped'),
        ])
    );
    assert_contains($result, 'div (0,0 100x60) ov=clip', 'overflow:clip container at 100x60 clips absolute child');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-13-Overflow-Advanced.snap';
run_css_tests('Level 13 - Overflow Advanced', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
