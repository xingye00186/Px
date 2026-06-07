<?php
/**
 * Level 10: Grid Advanced / 高级 Grid
 *
 * 测试目标：
 *   1. grid-column span 2 跨越两列
 *   2. grid-row span 2 跨越两行
 *   3. grid-auto-flow column 列优先
 *   4. grid 百分比行高
 *   5. grid auto-fit + repeat
 *   6. grid repeat 固定次数 3 列
 *   7. grid 混合 fr+px+auto 列
 *   8. grid 内 flex column 高度填充
 *   9. grid column-gap 单独设置
 *   10. grid 内 margin auto 居中
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: grid-column span 2 ──
$tests['grid-column span 2 跨越两列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:100px 100px 100px;width:300px;gap:4px;grid-auto-rows:60px'], [
            VNode::h('div', ['style' => ''], 'A'),
            VNode::h('div', ['style' => ''], 'B'),
            VNode::h('div', ['style' => 'grid-column:span 2'], 'C span 2'),
        ])
    );
    assert_contains($result, 'div (104,0 100x60) text="B"', 'B x=104 = col1(100)+gap(4)');
    return $result;
};

// ── Test 2: grid-row span 2 ──
$tests['grid-row span 2 跨越两行'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:100px 100px;width:204px;gap:4px;grid-auto-rows:50px'], [
            VNode::h('div', ['style' => ''], 'A'),
            VNode::h('div', ['style' => 'grid-row:span 2'], 'B span 2'),
            VNode::h('div', ['style' => ''], 'C'),
        ])
    );
    assert_contains($result, 'div (104,0 100x60) text="B span 2"', 'B x=104 = col1(100)+gap(4), row span 2');
    return $result;
};

// ── Test 3: grid-auto-flow column ──
$tests['grid-auto-flow column 列优先'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:100px 100px;grid-template-rows:50px 50px;width:200px;gap:4px;grid-auto-flow:column'], [
            VNode::h('div', ['style' => ''], 'A'),
            VNode::h('div', ['style' => ''], 'B'),
            VNode::h('div', ['style' => ''], 'C'),
        ])
    );
    assert_contains($result, 'div (0,64 100x60) text="C"', 'C flows to row2 col1, y=60(row1)+4(gap)=64');
    return $result;
};

// ── Test 4: grid 百分比行高 ──
$tests['grid 百分比行高 25%'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;grid-template-rows:25% 75%;width:400px;height:200px;gap:8px'], [
            VNode::h('div', ['style' => ''], 'Top 25%'),
            VNode::h('div', ['style' => ''], 'Top R'),
            VNode::h('div', ['style' => ''], 'Bottom 75%'),
            VNode::h('div', ['style' => ''], 'Bottom R'),
        ])
    );
    assert_contains($result, 'div (0,0 196x60) text="Top 25%"', '1fr col=(400-8)/2=196 with gap=8');
    return $result;
};

// ── Test 5: grid auto-fit 自适应 ──
$tests['grid auto-fit repeat(autofit, minmax(150px,1fr))'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));width:500px;gap:8px'], [
            VNode::h('div', ['style' => 'height:60px'], 'A'),
            VNode::h('div', ['style' => 'height:60px'], 'B'),
            VNode::h('div', ['style' => 'height:60px'], 'C'),
        ])
    );
    assert_contains($result, 'div (0,0 161x60) text="A"', 'auto-fit col>=150px minmax constraint');
    return $result;
};

// ── Test 6: grid repeat 固定 3 列 ──
$tests['grid repeat(3, 1fr) 三列等宽'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(3, 1fr);width:600px;height:80px;gap:8px'], [
            VNode::h('div', ['style' => 'height:60px'], 'A'),
            VNode::h('div', ['style' => 'height:60px'], 'B'),
            VNode::h('div', ['style' => 'height:60px'], 'C'),
        ])
    );
    assert_contains($result, 'div (202,0 194x60) text="B"', 'B x=202 = 194(col)+8(gap), 3 equal fr cols');
    return $result;
};

// ── Test 7: grid 混合 fr+px+auto 列 ──
$tests['grid 混合 1fr 200px auto'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 200px auto;width:700px;height:60px;gap:8px'], [
            VNode::h('div', ['style' => ''], 'Flex'),
            VNode::h('div', ['style' => ''], 'Fixed'),
            VNode::h('div', ['style' => ''], 'Auto'),
        ])
    );
    assert_contains($result, 'div (492,0 200x60) text="Fixed"', 'Fixed 200px column at x=484+gap(8)');
    return $result;
};

// ── Test 8: grid cell 内 flex column 高度填充 ──
$tests['grid cell 内 flex column 高度填充'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:600px;height:200px;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column'], [
                VNode::h('div', ['style' => 'height:40px'], 'Header'),
                VNode::h('div', ['style' => 'flex:1'], 'Fill'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:column'], [
                VNode::h('div', ['style' => 'height:60px'], 'Header'),
                VNode::h('div', ['style' => 'flex:1'], 'Fill'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,40 296x20) fg=1', 'Flex fill: remaining cell h=60 - header 40 = 20');
    return $result;
};

// ── Test 9: column-gap 单独设置 ──
$tests['column-gap 16px row-gap 8px 分开'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;grid-template-rows:60px 60px;width:400px;height:140px;column-gap:16px;row-gap:8px'], [
            VNode::h('div', ['style' => ''], 'A'),
            VNode::h('div', ['style' => ''], 'B'),
            VNode::h('div', ['style' => ''], 'C'),
            VNode::h('div', ['style' => ''], 'D'),
        ])
    );
    assert_contains($result, 'div (0,0 200x60) text="A"', 'A in first grid cell with separate column/row-gap');
    return $result;
};

// ── Test 10: grid 内 margin auto 居中 ──
$tests['grid cell 内 margin auto 居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 200px;width:400px;height:120px;gap:8px'], [
            VNode::h('div', ['style' => 'width:100px;height:50px;margin:auto'], 'Center'),
            VNode::h('div', ['style' => 'width:180px;height:80px'], 'Normal'),
        ])
    );
    assert_contains($result, 'div (0,0 200x80) text="Center"', 'margin:auto centers within 200px grid cell');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-10-Grid-Advanced.snap';
run_css_tests('Level 10 - Grid Advanced', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
