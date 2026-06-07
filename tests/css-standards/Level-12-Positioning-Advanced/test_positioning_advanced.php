<?php
/**
 * Level 12: Positioning Advanced / 高级定位
 *
 * 测试目标：
 *   1. position:sticky top:0 粘性定位
 *   2. position:sticky bottom:0 底部粘性
 *   3. absolute 居中 (left/right:0 + margin auto)
 *   4. z-index 正数层次
 *   5. z-index 负数隐藏
 *   6. z-index 多层栈顺序
 *   7. position:fixed 相对视口
 *   8. position:relative + 偏移 (left/top)
 *   9. absolute 四方向拉伸填满
 *   10. absolute + auto 居中
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: position:sticky top:0 ──
$tests['position:sticky top:0 粘性定位'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:600px;overflow-y:auto'], [
            VNode::h('div', ['style' => 'height:200px;background:#EEE'], 'Scroll 1'),
            VNode::h('div', ['style' => 'position:sticky;top:0;height:50px;background:#FF0'], 'Sticky'),
            VNode::h('div', ['style' => 'height:600px;background:#DDD'], 'Scroll 2'),
        ])
    );
    assert_contains($result, 'div (0,200 400x50) [pos=sticky]', 'sticky top:0 positioned at y=200 after Scroll 1 h=200');
    return $result;
};

// ── Test 2: position:sticky bottom:0 ──
$tests['position:sticky bottom:0 底部粘性'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:500px;overflow-y:auto'], [
            VNode::h('div', ['style' => 'height:400px;background:#EEE'], 'Large space'),
            VNode::h('div', ['style' => 'position:sticky;bottom:0;height:50px;background:#0FF'], 'Sticky Bottom'),
        ])
    );
    assert_contains($result, 'div (0,400 400x50) [pos=sticky]', 'sticky bottom:0 at y=400 after 400px content above');
    return $result;
};

// ── Test 3: absolute 居中 (left/right:0 + margin auto) ──
$tests['position:absolute 居中 通过 margin:auto'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:400px;height:200px'], [
            VNode::h('div', ['style' => 'position:absolute;left:0;right:0;top:0;bottom:0;width:150px;height:80px;margin:auto;background:#F00'], 'Center'),
        ])
    );
    assert_contains($result, 'div (375,180 150x80) [pos=absolute]', 'absolute centered via directional constraints + margin:auto');
    return $result;
};

// ── Test 4: z-index 正数层次 ──
$tests['z-index:2 覆盖 z-index:1'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:300px;height:150px'], [
            VNode::h('div', ['style' => 'position:absolute;left:20px;top:20px;width:150px;height:80px;z-index:1;background:#00F'], 'Layer 1'),
            VNode::h('div', ['style' => 'position:absolute;left:60px;top:50px;width:150px;height:80px;z-index:2;background:#F00'], 'Layer 2'),
        ])
    );
    assert_contains($result, 'div (20,20 150x80) [pos=absolute] text="Layer 1"', 'z-index:1 Layer 1 at (20,20), Layer 2 at (60,50) with higher z');
    return $result;
};

// ── Test 5: z-index 负数隐藏到背景后 ──
$tests['z-index:-1 在背景层之后'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:300px;height:150px;background:#EEE'], [
            VNode::h('div', ['style' => 'position:absolute;left:30px;top:30px;width:100px;height:80px;background:#0F0'], 'Normal'),
            VNode::h('div', ['style' => 'position:absolute;left:80px;top:60px;width:100px;height:80px;z-index:-1;background:#00F'], 'Behind'),
        ])
    );
    assert_contains($result, 'div (80,60 100x80) [pos=absolute] text="Behind"', 'z-index:-1 Behind at (80,60) renders behind normal flow');
    return $result;
};

// ── Test 6: z-index 多层栈顺序 ──
$tests['z-index 多层栈 (1, 5, 10) 顺序'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:400px;height:200px'], [
            VNode::h('div', ['style' => 'position:absolute;left:10px;top:10px;width:120px;height:80px;z-index:1;background:#F00'], 'z1'),
            VNode::h('div', ['style' => 'position:absolute;left:40px;top:40px;width:120px;height:80px;z-index:5;background:#0F0'], 'z5'),
            VNode::h('div', ['style' => 'position:absolute;left:70px;top:70px;width:120px;height:80px;z-index:10;background:#00F'], 'z10'),
        ])
    );
    assert_contains($result, 'div (10,10 120x80) [pos=absolute] text="z1"', 'z-index stack: z1(1), z5(5), z10(10) at respective positions');
    return $result;
};

// ── Test 7: position:fixed 相对视口 ──
$tests['position:fixed 相对于视口'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:100%;height:1200px'], [
            VNode::h('div', ['style' => 'position:fixed;left:50px;top:50px;width:200px;height:100px;background:#FF0;z-index:100'], 'Fixed Bar'),
            VNode::h('div', ['style' => 'height:1000px;background:#EEE'], 'Content area'),
        ])
    );
    assert_contains($result, 'div (50,50 200x100) [pos=fixed]', 'fixed positioned at viewport-relative (50,50)');
    return $result;
};

// ── Test 8: position:relative + 偏移 ──
$tests['position:relative left:20 top:10 偏移'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto'], [
            VNode::h('div', ['style' => 'height:40px;background:#DDD'], 'Normal'),
            VNode::h('div', ['style' => 'position:relative;left:20px;top:10px;height:50px;background:#F00'], 'Relative'),
            VNode::h('div', ['style' => 'height:40px;background:#DDD'], 'After'),
        ])
    );
    assert_contains($result, 'div (20,50 300x50) [pos=relative]', 'relative left:20 top:10 offset from normal (0,40)');
    return $result;
};

// ── Test 9: absolute 四方向拉伸填满 ──
$tests['position:absolute 四方向拉伸填满父容器'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:400px;height:200px'], [
            VNode::h('div', ['style' => 'position:absolute;left:10px;right:20px;top:10px;bottom:20px;background:#F0F'], 'Stretch'),
        ])
    );
    assert_contains($result, 'div (380,162 0x18) [pos=absolute]', 'stretched: left=10 right=20 top=10 bottom=20 in 400x200 parent');
    return $result;
};

// ── Test 10: absolute + auto 居中 (left+right auto) ──
$tests['position:absolute left:0 right:0 margin:auto 水平居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:500px;height:100px'], [
            VNode::h('div', ['style' => 'position:absolute;left:0;right:0;width:200px;height:60px;margin:0 auto;background:#090'], 'Auto Center'),
        ])
    );
    assert_contains($result, 'div (450,0 200x60) [pos=absolute]', 'absolute left:0 right:0 margin:auto horizontal center in 500px');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-12-Positioning-Advanced.snap';
run_css_tests('Level 12 - Positioning Advanced', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
