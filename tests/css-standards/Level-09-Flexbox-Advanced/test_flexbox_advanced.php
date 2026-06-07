<?php
/**
 * Level 9: Flexbox Advanced / 高级 Flexbox
 *
 * 测试目标：
 *   1. align-items flex-start
 *   2. align-items flex-end
 *   3. align-items stretch (默认)
 *   4. align-content center 多行居中
 *   5. align-content flex-end 多行底部
 *   6. align-content space-between
 *   7. align-content space-around
 *   8. align-self center 单个居中
 *   9. align-self flex-end 单个底部
 *   10. justify-self center 单个水平居中
 *   11. flex-basis 显式基础尺寸
 *   12. flex-basis auto 回退到 width
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: align-items flex-start ──
$tests['align-items flex-start 顶部对齐'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:flex-start;width:300px;height:100px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:60px;height:50px'], 'B'),
        ])
    );
    assert_contains($result, 'div (0,0 60x30)', 'align-items:flex-start: A at cross-axis y=0');
    assert_contains($result, 'div (60,0 60x50)', 'align-items:flex-start: B at cross-axis y=0');
    return $result;
};

// ── Test 2: align-items flex-end ──
$tests['align-items flex-end 底部对齐'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:flex-end;width:300px;height:100px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:60px;height:50px'], 'B'),
        ])
    );
    assert_contains($result, 'div (0,70 60x30)', 'align-items:flex-end: A y=100-30=70');
    assert_contains($result, 'div (60,50 60x50)', 'align-items:flex-end: B y=100-50=50');
    return $result;
};

// ── Test 3: align-items stretch ──
$tests['align-items stretch 拉伸'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:stretch;width:300px;height:100px'], [
            VNode::h('div', ['style' => 'width:60px'], 'A'),
            VNode::h('div', ['style' => 'width:60px'], 'B'),
        ])
    );
    assert_contains($result, 'div (0,0 60x100)', 'align-items:stretch: A stretched to container height=100');
    assert_contains($result, 'div (60,0 60x100)', 'align-items:stretch: B stretched to container height=100');
    return $result;
};

// ── Test 4: align-content center 多行居中 ──
$tests['align-content center 多行垂直居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-wrap:wrap;align-content:center;width:200px;height:200px;gap:4px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'B'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'C'),
        ])
    );
    assert_contains($result, 'div (0,0 80x40)', 'align-content:center wrap first row A');
    assert_contains($result, 'div (84,0 80x40)', 'align-content:center wrap second item B at x=80+4=84');
    return $result;
};

// ── Test 5: align-content flex-end ──
$tests['align-content flex-end 多行底部'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-wrap:wrap;align-content:flex-end;width:200px;height:200px;gap:4px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'B'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'C'),
        ])
    );
    assert_contains($result, 'div (0,0 80x40)', 'align-content:flex-end wrap layout');
    return $result;
};

// ── Test 6: align-content space-between ──
$tests['align-content space-between 等距'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-wrap:wrap;align-content:space-between;width:200px;height:200px;gap:4px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'B'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'C'),
        ])
    );
    assert_contains($result, 'div (0,0 80x40)', 'align-content:space-between wrap layout');
    return $result;
};

// ── Test 7: align-content space-around ──
$tests['align-content space-around 均匀分布'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-wrap:wrap;align-content:space-around;width:200px;height:200px;gap:4px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'B'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'C'),
        ])
    );
    assert_contains($result, 'div (0,0 80x40)', 'align-content:space-around wrap layout');
    return $result;
};

// ── Test 8: align-self center ──
$tests['align-self center 单个居中覆盖'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:flex-start;width:300px;height:100px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:60px;height:40px;align-self:center'], 'Center'),
        ])
    );
    assert_contains($result, 'div (60,30 60x40)', 'align-self:center: y=(100-40)/2=30');
    return $result;
};

// ── Test 9: align-self flex-end ──
$tests['align-self flex-end 单个底部覆盖'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:flex-start;width:300px;height:100px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:60px;height:40px;align-self:flex-end'], 'Bottom'),
        ])
    );
    assert_contains($result, 'div (60,60 60x40)', 'align-self:flex-end: y=100-40=60');
    return $result;
};

// ── Test 10: justify-self center ──
$tests['justify-self center 单个水平居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:300px;height:50px'], [
            VNode::h('div', ['style' => 'width:80px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:80px;height:30px;justify-self:center'], 'Center'),
        ])
    );
    assert_contains($result, 'div (80,0 80x30)', 'justify-self:center: second item after first at x=80');
    return $result;
};

// ── Test 11: flex-basis 显式固定尺寸 ──
$tests['flex-basis 200px 固定基础尺寸'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:500px;height:50px'], [
            VNode::h('div', ['style' => 'flex-basis:200px;height:30px'], 'Basis 200'),
            VNode::h('div', ['style' => 'flex:1;height:30px'], 'Flex 1'),
        ])
    );
    assert_contains($result, 'div (0,0 200x30)', 'flex-basis=200px gives item width=200');
    assert_contains($result, 'div (200,0 300x30)', 'Flex 1 gets remaining 500-200=300');
    return $result;
};

// ── Test 12: flex-basis auto 回退到 width ──
$tests['flex-basis auto 回退到 width'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:500px;height:50px'], [
            VNode::h('div', ['style' => 'width:150px;height:30px;flex:1'], 'W 150 + f1'),
            VNode::h('div', ['style' => 'width:100px;height:30px'], 'W 100'),
        ])
    );
    assert_contains($result, 'fg=1', 'flex:1 item grows to fill remaining space');
    assert_contains($result, 'div (400,0 100x30)', 'Second item keeps its width=100');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-09-Flexbox-Advanced.snap';
run_css_tests('Level 9 - Flexbox Advanced', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
