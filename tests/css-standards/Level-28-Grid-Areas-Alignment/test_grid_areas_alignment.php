<?php
/**
 * Level 28: Grid Named Areas & Alignment / Grid命名区域与对齐
 *
 * 测试目标：
 *   1. grid-template-areas 三行三列命名区域布局
 *   2. grid-area 引用命名区域
 *   3. justify-self:center 子项水平居中
 *   4. justify-self:end 子项右对齐
 *   5. justify-self:start 子项左对齐
 *   6. grid-area 配合 grid-template-areas 跨列区域
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: grid-template-areas 命名区域布局 ──
$tests['grid-template-areas header-main-footer 三行布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 2fr;grid-template-rows:60px 1fr 40px;width:600px;height:300px;gap:4px;grid-template-areas:"header header" "main sidebar" "footer footer"'], [
            VNode::h('div', ['style' => 'grid-area:header;background:#F00'], 'Header'),
            VNode::h('div', ['style' => 'grid-area:main;background:#0F0'], 'Main'),
            VNode::h('div', ['style' => 'grid-area:sidebar;background:#00F'], 'Sidebar'),
            VNode::h('div', ['style' => 'grid-area:footer;background:#FF0'], 'Footer'),
        ])
    );
    assert_contains($result, 'text="Header"', 'grid-area:header placed in header row');
    assert_contains($result, 'text="Main"', 'grid-area:main in main area');
    assert_contains($result, 'text="Sidebar"', 'grid-area:sidebar in sidebar area');
    assert_contains($result, 'text="Footer"', 'grid-area:footer in footer row');
    return $result;
};

// ── Test 2: justify-self:center 子项水平居中 ──
$tests['justify-self:center grid子项居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 200px;width:400px;height:100px;gap:4px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px;background:#F00'], 'Left'),
            VNode::h('div', ['style' => 'width:80px;height:40px;justify-self:center;background:#0F0'], 'Center'),
        ])
    );
    assert_contains($result, 'text="Center"', 'justify-self:center child present');
    assert_contains($result, '(264,0 80x40)', 'justify-self:center: cell_x=204 + (200-80)/2 = 264; height 40 explicit (not stretched)');
    return $result;
};

// ── Test 3: justify-self:end 子项右对齐 ──
$tests['justify-self:end grid子项右对齐'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 200px;width:400px;height:100px;gap:4px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px;background:#F00'], 'Start'),
            VNode::h('div', ['style' => 'width:80px;height:40px;justify-self:end;background:#0F0'], 'End'),
        ])
    );
    assert_contains($result, 'text="End"', 'justify-self:end child present');
    return $result;
};

// ── Test 4: justify-self:start 子项左对齐 ──
$tests['justify-self:start grid子项左对齐'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 200px;width:400px;height:100px;gap:4px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px;background:#F00'], 'Right'),
            VNode::h('div', ['style' => 'width:80px;height:40px;justify-self:start;background:#0F0'], 'Start'),
        ])
    );
    assert_contains($result, 'text="Start"', 'justify-self:start child present');
    return $result;
};

// ── Test 5: justify-self:stretch 子项拉伸 ──
$tests['justify-self:stretch grid子项拉伸'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 200px;width:400px;height:100px;gap:4px'], [
            VNode::h('div', ['style' => 'height:40px;background:#F00'], 'Auto'),   // default stretch
            VNode::h('div', ['style' => 'height:40px;justify-self:stretch;background:#0F0'], 'Stretch'),
        ])
    );
    assert_contains($result, 'text="Stretch"', 'justify-self:stretch child present');
    return $result;
};

// ── Test 6: grid-area 跨列区域 ──
$tests['grid-area header 跨两列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;grid-template-rows:50px 1fr;width:400px;height:200px;gap:4px;grid-template-areas:"header header" "main sidebar"'], [
            VNode::h('div', ['style' => 'grid-area:header;background:#F00'], 'Header'),
            VNode::h('div', ['style' => 'grid-area:main;background:#0F0'], 'Main'),
            VNode::h('div', ['style' => 'grid-area:sidebar;background:#00F'], 'Sidebar'),
        ])
    );
    assert_contains($result, 'text="Header"', 'grid-area:header spans both columns');
    assert_contains($result, 'text="Main"', 'grid-area:main in left column');
    assert_contains($result, 'text="Sidebar"', 'grid-area:sidebar in right column');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-28-Grid-Areas-Alignment.snap';
run_css_tests('Level 28 - Grid Named Areas & Alignment', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
