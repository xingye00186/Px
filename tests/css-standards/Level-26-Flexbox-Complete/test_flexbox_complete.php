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

use Px\Dom\VNode;

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

// ── Test 9: CSS Flexbox §7.1 flex-basis:0 与 grow 分配 ──
// 旧 bug：toPx() > 0 将 flex-basis:0 错误归为 auto → 使用 child.w 作 fallback
// 修复后：basis=0 顯式设置，3 个 flex:1 1 0 子项各占 300/3=100
$tests['CSS §7.1 flex:1 1 0 三均分布'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:300px;height:60px'], [
            VNode::h('div', ['style' => 'flex:1 1 0;height:40px'], 'A'),
            VNode::h('div', ['style' => 'flex:1 1 0;height:40px'], 'B'),
            VNode::h('div', ['style' => 'flex:1 1 0;height:40px'], 'C'),
        ])
    );
    // 每个子项 basis=0，grow=1 → 300/3=100
    assert_contains($result, 'div (0,0 100x40)', 'flex:1 1 0 首项宽 100');
    assert_contains($result, 'div (100,0 100x40)', 'flex:1 1 0 中项宽 100 @ x=100');
    assert_contains($result, 'div (200,0 100x40)', 'flex:1 1 0 末项宽 100 @ x=200');
    return $result;
};

// ── Test 10: CSS Flexbox §7.1 flex-basis 关键字 min-content ──
// min-content/max-content/fit-content 读为 intrinsic，降级为 content size 代理
$tests['CSS §7.1 flex-basis:min-content 关键字'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:300px;height:60px'], [
            VNode::h('div', ['style' => 'flex-basis:min-content;height:40px;width:80px'], 'A'),
            VNode::h('div', ['style' => 'flex:1;height:40px'], 'B'),
        ])
    );
    // A basis=intrinsic 降级到 child.w=80，B flex:1 占剩余 220
    assert_contains($result, 'div (0,0 80x40) text="A"', 'flex-basis:min-content 降级到 content size = 80');
    assert_contains($result, 'div (80,0 220x40)', 'flex:1 B 占剩余 220 @ x=80');
    return $result;
};

// ── Test 11: CSS Flexbox §7.1.1 flex-basis 百分比基于主轴 ──
$tests['CSS §7.1.1 flex-basis:50% 百分比解析'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:400px;height:60px'], [
            VNode::h('div', ['style' => 'flex-basis:50%;flex-shrink:0;height:40px'], 'A'),
            VNode::h('div', ['style' => 'flex-basis:25%;flex-shrink:0;height:40px'], 'B'),
        ])
    );
    // 400 * 50% = 200, 400 * 25% = 100
    assert_contains($result, 'div (0,0 200x40) text="A"', 'flex-basis:50% 基于主轴 400 = 200');
    assert_contains($result, 'div (200,0 100x40) text="B"', 'flex-basis:25% 基于主轴 400 = 100');
    return $result;
};

// ── Test 12: CSS Flexbox §4.1 absolute flex items 不占主轴空间 ──
$tests['CSS §4.1 absolute flex items 不占主轴空间'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;position:relative;width:400px;height:100px'], [
            VNode::h('div', ['style' => 'flex:1;height:40px'], 'A'),
            VNode::h('div', ['style' => 'position:absolute;left:10px;top:10px;width:50px;height:50px'], 'ABS'),
            VNode::h('div', ['style' => 'flex:1;height:40px'], 'B'),
        ])
    );
    assert_contains($result, 'div (0,0 200x40) fg=1 text="A"', 'flex:1 A 占 200（absolute 不占空间）');
    assert_contains($result, 'div (200,0 200x40) fg=1 text="B"', 'flex:1 B 占 200 @ x=200');
    assert_contains($result, '[pos=absolute] text="ABS"', 'absolute 子项保留且 passthrough');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-26-Flexbox-Complete.snap';
run_css_tests('Level 26 - Flexbox Complete', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
