<?php
/**
 * Level 29 - Float 布局（CSS 2.2 §9.5 / §9.5.2）
 *
 * 断言值全部来自真实 Chromium/Blink getBoundingClientRect 测量
 * （_gt_float.html，容器 overflow:hidden 建 BFC 隔离 float 跨块传播）。
 *
 *   1. float:left + 后续 block（盒重叠，BFC 容器含 float 高度）
 *   2. float:left + float:right 同行
 *   3. clear:both 下移到 float 底部
 *   4. 两个 float:left 依次并排
 *   5. float 超宽换行到下一 float 行
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: float:left + 后续 block ──
$tests['float:left 后续 block 盒'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;overflow:hidden'], [
            VNode::h('div', ['style' => 'float:left;width:100px;height:60px;background:#F88'], 'F'),
            VNode::h('div', ['style' => 'height:30px;background:#8F8'], 'B'),
        ])
    );
    // Blink: float (0,0 100x60)；block 盒 (0,0 400x30)（盒与 float 重叠，仅行盒避让）；
    // BFC 容器高 = max(float 60, flow 30) = 60
    assert_contains($result, 'div (0,0 100x60)', 'float:left box at (0,0) 100x60 (Blink-measured)');
    assert_contains($result, 'div (0,0 400x60)', 'BFC container height 60 contains float (overflow:hidden establishes BFC)');
    return $result;
};

// ── Test 2: float:left + float:right 同行 ──
$tests['float left+right 同行'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;overflow:hidden'], [
            VNode::h('div', ['style' => 'float:left;width:100px;height:60px;background:#F88'], 'L'),
            VNode::h('div', ['style' => 'float:right;width:80px;height:40px;background:#88F'], 'R'),
        ])
    );
    // Blink: L(0,0 100x60), R(320,0 80x40)=400-80；容器 60
    assert_contains($result, 'div (0,0 100x60)', 'float:left at origin (Blink-measured)');
    assert_contains($result, 'div (320,0 80x40)', 'float:right at x=400-80=320 (Blink-measured)');
    return $result;
};

// ── Test 3: clear:both ──
$tests['clear:both 下移到 float 底'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;overflow:hidden'], [
            VNode::h('div', ['style' => 'float:left;width:100px;height:60px;background:#F88'], 'F'),
            VNode::h('div', ['style' => 'clear:both;height:20px;background:#FF8'], 'C'),
        ])
    );
    // Blink: clear 元素 y=60（float 底）；容器 80
    assert_contains($result, 'div (0,60 400x20)', 'clear:both moves below float bottom y=60 (Blink-measured)');
    return $result;
};

// ── Test 4: 两个 float:left 并排 ──
$tests['两个 float:left 并排'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;overflow:hidden'], [
            VNode::h('div', ['style' => 'float:left;width:100px;height:60px;background:#F88'], 'A'),
            VNode::h('div', ['style' => 'float:left;width:100px;height:60px;background:#8F8'], 'B'),
            VNode::h('div', ['style' => 'clear:both;height:20px'], ''),
        ])
    );
    // Blink: A(0,0), B(100,0) 并排；clear 后容器 80
    assert_contains($result, 'div (100,0 100x60)', 'second float:left stacks beside first at x=100 (Blink-measured)');
    return $result;
};

// ── Test 5: float 超宽换行 ──
$tests['float 超宽换到下一行'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;overflow:hidden'], [
            VNode::h('div', ['style' => 'float:left;width:250px;height:60px;background:#F88'], 'A'),
            VNode::h('div', ['style' => 'float:left;width:250px;height:60px;background:#8F8'], 'B'),
            VNode::h('div', ['style' => 'clear:both;height:20px'], ''),
        ])
    );
    // Blink: A(0,0 250x60)；B 不够宽（250+250>400）换行 (0,60 250x60)；clear 在 y=120
    assert_contains($result, 'div (0,60 250x60)', 'second float wraps to next float line y=60 (Blink-measured)');
    assert_contains($result, 'div (0,120 400x20)', 'clear:both below both float lines y=120 (Blink-measured)');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-29-Float.snap';
run_css_tests('Level 29 - Float (CSS 2.2 §9.5)', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
