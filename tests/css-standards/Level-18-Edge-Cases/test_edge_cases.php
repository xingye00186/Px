<?php
/**
 * Level 18: Edge Cases / 边界情况
 *
 * 测试目标：
 *   1. width:0 height:0 零尺寸
 *   2. 负边距 margin 重叠
 *   3. 极大宽度 (10000px) 裁边
 *   4. overflow:hidden 零尺寸容器
 *   5. 空子元素容器
 *   6. 单一子项 margin auto
 *   7. border 在零尺寸上
 *   8. padding 在零尺寸上
 *   9. opacity 0 子项在 flex 中
 *   10. 深度嵌套空 div
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: width:0 height:0 零尺寸 ──
$tests['width:0 height:0 零尺寸元素'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:0;height:0;overflow:hidden'], 'Zero')
    );
    assert_contains($result, '(0,0 0x0)', 'zero dimensions element at (0,0 0x0)');
    return $result;
};

// ── Test 2: 负边距 margin 重叠 ──
$tests['margin 负值重叠 上负下负'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto'], [
            VNode::h('div', ['style' => 'height:50px;margin-bottom:-10px;background:#F00'], 'Bottom -10'),
            VNode::h('div', ['style' => 'height:50px;margin-top:-10px;background:#0F0'], 'Top -10'),
        ])
    );
    assert_contains($result, '(0,40 300x50)', 'negative margin overlap: y=50-10-10=40? actual=40');
    return $result;
};

// ── Test 3: 极大宽度裁边 ──
$tests['width:10000px 极大宽度 裁边'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:10000px;height:30px'], 'Very wide element')
    );
    assert_contains($result, '10000x30', 'width:10000px preserved in output');
    return $result;
};

// ── Test 4: overflow:hidden 零尺寸容器 ──
$tests['overflow:hidden 零尺寸容器'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:0;height:0;overflow:hidden'], [
            VNode::h('div', ['style' => 'width:200px;height:100px;background:#F00'], 'Hidden content'),
        ])
    );
    assert_contains($result, 'ov=hidden', 'overflow:hidden container');
    assert_contains($result, '(0,0 0x0)', 'zero-size container');
    return $result;
};

// ── Test 5: 空子元素容器 ──
$tests['空子元素容器'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;min-height:40px'], [])
    );
    assert_contains($result, '200x40', 'empty children: parent renders with min-height 40');
    return $result;
};

// ── Test 6: 单一子项 margin auto ──
$tests['单一子项 margin auto 居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:100px'], [
            VNode::h('div', ['style' => 'width:100px;height:50px;margin:auto;background:#F00'], 'Only child'),
        ])
    );
    assert_contains($result, '(150,0 100x50)', 'margin auto centers child at x=(400-100)/2=150');
    return $result;
};

// ── Test 7: border 在零尺寸上 ──
$tests['border 在零尺寸元素上'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:0;height:0;border:2px solid #F00;overflow:hidden'], '')
    );
    assert_contains($result, 'bw=2', 'border:2px on zero-size element -> bw=2');
    return $result;
};

// ── Test 8: padding 在零尺寸上 ──
$tests['padding 在零尺寸元素上'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:0;height:0;padding:10px;overflow:hidden'], 'Pad')
    );
    assert_contains($result, 'ov=hidden', 'overflow:hidden with padding on zero-size');
    return $result;
};

// ── Test 9: opacity 0 子项在 flex 中 ──
$tests['opacity:0 子项在 flex row 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:300px;height:50px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:60px;height:30px;opacity:0'], 'Invisible'),
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'C'),
        ])
    );
    assert_contains($result, 'text="Invisible"', 'opacity:0 element still participates in flex layout');
    return $result;
};

// ── Test 10: 深度嵌套空 div ──
$tests['深度嵌套 10 层空 div'] = function() {
    $inner = VNode::h('div', ['style' => 'background:#F00;width:10px;height:10px'], 'Deep');
    for ($i = 0; $i < 9; $i++) {
        $inner = VNode::h('div', ['style' => ''], [$inner]);
    }
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:100px'], [$inner])
    );
    assert_contains($result, '10x10) text="Deep"', '10-level nesting reaches deepest child at 10x10');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-18-Edge-Cases.snap';
run_css_tests('Level 18 - Edge Cases', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
