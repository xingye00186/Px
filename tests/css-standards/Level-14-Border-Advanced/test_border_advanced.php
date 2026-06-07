<?php
/**
 * Level 14: Border Advanced / 高级边框
 *
 * 测试目标：
 *   1. border-top 顶部边框
 *   2. border-bottom 底部边框
 *   3. border-left 左边框
 *   4. border-right 右边框
 *   5. 不同方向不同颜色
 *   6. border + border-radius 圆角边框
 *   7. border:0 / border:none 无影响
 *   8. border-width 独立设置
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: border-top ──
$tests['border-top 顶部边框'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:80px;border-top:2px solid #FF0000'], 'Top border')
    );
    assert_contains($result, 'bw=2', 'border-top:2px solid -> bw=2');
    return $result;
};

// ── Test 2: border-bottom ──
$tests['border-bottom 底部边框'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:80px;border-bottom:3px solid #00FF00'], 'Bottom border')
    );
    assert_contains($result, 'bw=3', 'border-bottom:3px solid -> bw=3');
    return $result;
};

// ── Test 3: border-left ──
$tests['border-left 左边框'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:80px;border-left:4px solid #0000FF'], 'Left border')
    );
    assert_contains($result, 'bw=4', 'border-left:4px solid -> bw=4');
    return $result;
};

// ── Test 4: border-right ──
$tests['border-right 右边框'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:80px;border-right:5px solid #FF00FF'], 'Right border')
    );
    assert_contains($result, 'bw=5', 'border-right:5px solid -> bw=5');
    return $result;
};

// ── Test 5: 不同方向不同颜色 ──
$tests['四方向不同颜色边框组合'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:100px;border-top:2px solid #FF0000;border-right:3px solid #00FF00;border-bottom:4px solid #0000FF;border-left:5px solid #FF00FF'], 'Multi border')
    );
    assert_contains($result, 'bw=4', 'four borders 2+3+4+5px');
    return $result;
};

// ── Test 6: border + border-radius ──
$tests['border + border-radius 圆角边框'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:150px;height:80px;border:2px solid #333333;border-radius:10px'], 'Round border')
    );
    assert_contains($result, 'bw=2', 'border:2px -> bw=2');
    assert_contains($result, '(0,0 150x80)', '150x80 dimensions unchanged');
    return $result;
};

// ── Test 7: border:0 ──
$tests['border:0 无边框'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;border:0'], 'No border')
    );
    assert_contains($result, '(0,0 100x50)', 'border:0 preserves 100x50');
    return $result;
};

// ── Test 8: border-width 独立设置 ──
$tests['border-width 独立设置 6px'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;border-width:6px;border-color:#FF6600'], 'Border width')
    );
    assert_contains($result, 'bw=6', 'border-width:6px -> bw=6');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-14-Border-Advanced.snap';
run_css_tests('Level 14 - Border Advanced', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
