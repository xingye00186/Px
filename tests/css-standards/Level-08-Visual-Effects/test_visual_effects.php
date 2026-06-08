<?php
/**
 * Level 8: Visual Effects / 视觉效果
 *
 * 测试目标：
 *   1. border-radius 8px 圆角
 *   2. border-radius 50% 圆形
 *   3. border-radius 不同方向值
 *   4. box-shadow 基本投影
 *   5. box-shadow 大模糊扩散
 *   6. opacity 半透明 0.5
 *   7. opacity 完全透明 0
 *   8. background 颜色填充
 *   9. background-size cover
 *   10. 颜色+圆角组合
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: border-radius 8px ──
$tests['border-radius 8px 圆角'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;border-radius:8px'], 'Rounded')
    );
    assert_contains($result, 'div (0,0 100x50)', 'border-radius=8px does not affect layout box 100x50');
    return $result;
};

// ── Test 2: border-radius 50% 圆形 ──
$tests['border-radius 50% 圆形'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:100px;border-radius:50%'], 'Circle')
    );
    assert_contains($result, 'div (0,0 100x100)', 'border-radius=50% on 100x100 square => radius=50px');
    return $result;
};

// ── Test 3: border-radius 不同方向 ──
$tests['border-radius 4值 (10 20 30 40)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:100px;border-radius:10px 20px 30px 40px'], 'Mixed radius')
    );
    assert_contains($result, 'div (0,0 200x100)', 'Multi-value border-radius does not affect layout');
    return $result;
};

// ── Test 4: box-shadow 基本投影 ──
$tests['box-shadow 4px 4px 灰色'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;box-shadow:4px 4px 0 0 #888888'], 'Shadow')
    );
    assert_contains($result, 'div (0,0 100x50)', 'box-shadow does not affect layout 100x50');
    return $result;
};

// ── Test 5: box-shadow 大模糊 ──
$tests['box-shadow 大模糊扩散 8px'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;box-shadow:2px 2px 8px 2px #000000'], 'Blur shadow')
    );
    assert_contains($result, 'div (0,0 100x50)', 'Blurred box-shadow does not affect layout 100x50');
    return $result;
};

// ── Test 6: opacity 0.5 半透明 ──
$tests['opacity 0.5 半透明'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;opacity:0.5'], 'Semi transparent')
    );
    assert_contains($result, 'div (0,0 100x50)', 'opacity=0.5 does not affect layout 100x50');
    return $result;
};

// ── Test 7: opacity 0 完全透明 ──
$tests['opacity 0 完全透明'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;opacity:0'], 'Invisible')
    );
    assert_contains($result, 'div (0,0 100x50)', 'opacity=0 element still occupies layout space 100x50');
    return $result;
};

// ── Test 8: background 颜色填充 ──
$tests['background 颜色填充 #FF6600'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;background:#FF6600'], 'Orange bg')
    );
    assert_contains($result, 'div (0,0 100x50)', 'background-color does not affect layout 100x50');
    return $result;
};

// ── Test 9: background-size cover ──
$tests['background-size cover'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:100px;background-size:cover'], 'Cover')
    );
    assert_contains($result, 'div (0,0 200x100)', 'background-size=cover layout box 200x100');
    return $result;
};

// ── Test 10: 颜色+圆角组合 ──
$tests['background + border-radius 组合'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:120px;height:60px;background:#3366FF;border-radius:12px;opacity:0.8'], 'Blue rounded')
    );
    assert_contains($result, 'div (0,0 120x60)', 'Combined bg+radius+opacity layout 120x60');
    return $result;
};

// ── Test 11: rgba background with alpha ──
$tests['rgba(251,114,153,0.4) 半透明背景'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;background:rgba(251,114,153,0.4)'], 'RGBA')
    );
    assert_contains($result, 'div (0,0 100x50)', 'rgba background layout unchanged');
    return $result;
};

// ── Test 12: background 简写展开 — 颜色 + position/size ──
$tests['background 简写 #3366FF + center/cover'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:100px;height:50px;background:#3366FF center/cover'], 'shorthand')
    );
    assert_contains($result, 'div (0,0 100x50)', 'shorthand layout 100x50');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-08-Visual-Effects.snap';
run_css_tests('Level 8 - Visual Effects', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
