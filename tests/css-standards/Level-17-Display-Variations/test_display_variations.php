<?php
/**
 * Level 17: Display Variations / 显示模式变化
 *
 * 测试目标：
 *   1. display:none 不渲染
 *   2. visibility:hidden 隐藏但占位
 *   3. display:inline 行内布局
 *   4. display:inline-block 行内块
 *   5. display:none 在 flex 中
 *   6. visibility:visible 子覆盖父 hidden
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: display:none 不渲染 ──
$tests['display:none 不渲染'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:auto'], [
            VNode::h('div', ['style' => 'height:40px'], 'Visible'),
            VNode::h('div', ['style' => 'display:none;height:40px'], 'Hidden'),
            VNode::h('div', ['style' => 'height:40px'], 'After'),
        ])
    );
    assert_contains($result, 'dsp=none', 'display:none element marked as dsp=none');
    assert_contains($result, 'text="Visible"', 'visible element present');
    return $result;
};

// ── Test 2: visibility:hidden ──
$tests['visibility:hidden 隐藏但占位'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto'], [
            VNode::h('div', ['style' => 'height:40px'], 'Item 1'),
            VNode::h('div', ['style' => 'visibility:hidden;height:40px'], 'Hidden but space'),
            VNode::h('div', ['style' => 'height:40px'], 'Item 3'),
        ])
    );
    assert_contains($result, 'text="Hidden but space"', 'visibility:hidden still in tree');
    return $result;
};

// ── Test 3: display:inline ──
$tests['display:inline 行内并排'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto'], [
            VNode::h('span', ['style' => 'display:inline;color:#F00'], 'Red '),
            VNode::h('span', ['style' => 'display:inline;color:#00F'], 'Blue '),
            VNode::h('span', ['style' => 'display:inline;color:#090'], 'Green'),
        ])
    );
    assert_contains($result, 'dsp=inline', 'inline elements have dsp=inline');
    assert_contains($result, 'text="Red "', 'first inline element present');
    return $result;
};

// ── Test 4: display:inline-block ──
$tests['display:inline-block 行内块布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:auto'], [
            VNode::h('div', ['style' => 'display:inline-block;width:100px;height:60px;background:#F00'], 'A'),
            VNode::h('div', ['style' => 'display:inline-block;width:100px;height:80px;background:#0F0'], 'B'),
            VNode::h('div', ['style' => 'display:inline-block;width:100px;height:50px;background:#00F'], 'C'),
        ])
    );
    assert_contains($result, 'dsp=inline-block', 'inline-block elements');
    assert_contains($result, '100x60', 'inline-block A has 100x60 explicit width');
    return $result;
};

// ── Test 5: display:none 在 flex 中 ──
$tests['display:none 在 flex row 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:80px;height:30px;background:#F00'], 'A'),
            VNode::h('div', ['style' => 'display:none;width:200px;height:30px;background:#0F0'], 'Hidden'),
            VNode::h('div', ['style' => 'width:80px;height:30px;background:#00F'], 'C'),
        ])
    );
    assert_contains($result, 'dsp=none', 'display:none in flex marked as dsp=none');
    assert_contains($result, 'text="C"', 'third flex child present');
    return $result;
};

// ── Test 6: visibility:visible 子覆盖父 hidden ──
$tests['visibility:visible 子项覆盖父 hidden'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto'], [
            VNode::h('div', ['style' => 'visibility:hidden;height:60px'], [
                VNode::h('div', ['style' => 'height:30px'], 'Hidden parent'),
                VNode::h('div', ['style' => 'visibility:visible;height:30px;background:#FF0'], 'Visible child'),
            ]),
        ])
    );
    assert_contains($result, 'text="Visible child"', 'visibility:visible child overrides parent hidden');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-17-Display-Variations.snap';
run_css_tests('Level 17 - Display Variations', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
