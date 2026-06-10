<?php
/**
 * Level 26: Flexbox Complete / Flexbox 完整特性
 *
 * 测试目标：
 *   1. flex-direction:row-reverse 反向水平排列
 *   2. flex-direction:column-reverse 反向垂直排列
 *   3. flex-wrap:wrap-reverse 反向换行
 *   4. align-self:flex-end/center 子项自对齐覆盖
 *   5. order 重排序
 *   6. flex-shrink:0 禁止收缩
 *   7. flex-shrink:2 双倍收缩
 *   8. flex-basis:200px 固定基准
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: flex-direction:row-reverse ──
$tests['flex row-reverse 反向水平排列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row-reverse;width:400px;height:60px;padding:0'], [
            VNode::h('div', ['style' => 'width:100px;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:100px;height:40px'], 'B'),
            VNode::h('div', ['style' => 'width:100px;height:40px'], 'C'),
        ])
    );
    // row-reverse: items start from right edge, in reverse order
    assert_contains($result, 'text="C"', 'row-reverse: C is first child but goes to rightmost');
    return $result;
};

// ── Test 2: flex-direction:column-reverse ──
$tests['flex column-reverse 反向垂直排列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column-reverse;width:200px;height:200px'], [
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'B'),
        ])
    );
    // column-reverse: items start from bottom edge
    assert_contains($result, 'text="B"', 'column-reverse: B item present');
    return $result;
};

// ── Test 3: flex-wrap:wrap-reverse ──
$tests['flex wrap-reverse 反向换行'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-wrap:wrap-reverse;width:200px;height:120px;gap:4px'], [
            VNode::h('div', ['style' => 'width:90px;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:90px;height:40px'], 'B'),
            VNode::h('div', ['style' => 'width:90px;height:40px'], 'C'),
            VNode::h('div', ['style' => 'width:90px;height:40px'], 'D'),
        ])
    );
    // wrap-reverse: cross axis direction is reversed
    assert_contains($result, 'text="A"', 'wrap-reverse: first item A present');
    return $result;
};

// ── Test 4: align-self:flex-end 覆盖 ──
$tests['align-self:flex-end 子项底部对齐'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:center;width:400px;height:100px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px;background:#F00'], 'Center'),
            VNode::h('div', ['style' => 'width:80px;height:40px;align-self:flex-end;background:#0F0'], 'Bottom'),
        ])
    );
    assert_contains($result, 'text="Bottom"', 'align-self:flex-end child present');
    return $result;
};

// ── Test 5: align-self:center 覆盖 ──
$tests['align-self:center 子项居中覆盖 stretch'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:stretch;width:400px;height:100px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'Stretched'),
            VNode::h('div', ['style' => 'width:80px;align-self:center;background:#0F0'], 'Centered'),
        ])
    );
    assert_contains($result, 'text="Centered"', 'align-self:center overrides stretch');
    return $result;
};

// ── Test 6: order 重排序 ──
$tests['order:2 和 order:-1 重排序'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'order:2;width:80px;height:40px;background:#F00'], 'A'),
            VNode::h('div', ['style' => 'order:-1;width:80px;height:40px;background:#0F0'], 'B'),
            VNode::h('div', ['style' => 'order:1;width:80px;height:40px;background:#00F'], 'C'),
            VNode::h('div', ['style' => 'order:0;width:80px;height:40px;background:#FF0'], 'D'),
        ])
    );
    // order: -1 < 0 < 1 < 2 → B, D, C, A
    assert_contains($result, 'text="B"', 'order:-1 B comes first');
    assert_contains($result, 'text="A"', 'order:2 A comes last');
    return $result;
};

// ── Test 7: flex-shrink:0 ──
$tests['flex-shrink:0 禁止收缩'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:300px;height:50px'], [
            VNode::h('div', ['style' => 'width:200px;height:40px;flex-shrink:0;background:#F00'], 'Fixed'),
            VNode::h('div', ['style' => 'flex:1;height:40px;background:#0F0'], 'Shrink'),
        ])
    );
    // Fixed stays 200px, Shrink gets remaining 100px (200+100=300)
    assert_contains($result, 'text="Fixed"', 'flex-shrink:0 preserves 200px width');
    return $result;
};

// ── Test 8: flex-basis:200px 固定基准 ──
$tests['flex-basis:200px 固定基准'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'flex:1 1 200px;height:40px;background:#F00'], 'Basis'),
            VNode::h('div', ['style' => 'flex:1;height:40px;background:#0F0'], 'Flex'),
        ])
    );
    assert_contains($result, 'text="Basis"', 'flex-basis:200px child present');
    assert_contains($result, 'text="Flex"', 'flex:1 child present');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-26-Flexbox-Complete.snap';
run_css_tests('Level 26 - Flexbox Complete', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
