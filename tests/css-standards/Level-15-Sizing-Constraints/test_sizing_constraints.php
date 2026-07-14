<?php
/**
 * Level 15: Sizing Constraints / 尺寸约束
 *
 * 测试目标：
 *   1. min-height 在 flex column 中
 *   2. max-height 在 flex column 中
 *   3. min-width 在 flex row 中
 *   4. max-width 在 flex row 中
 *   5. min-height 百分比
 *   6. max-height 百分比
 *   7. height auto 在 flex 中
 *   8. width auto 在 grid cell 中
 *   9. min-height > height 约束优先
 *   10. max-height < height 约束优先
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: min-height 在 flex column 中 ──
$tests['min-height 在 flex column 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:300px;height:200px'], [
            VNode::h('div', ['style' => 'flex:1;min-height:80px;background:red'], 'Min 80px'),
        ])
    );
    assert_contains($result, '300x200', 'flex child with min-height:80 fills 200px container');
    return $result;
};

// ── Test 2: max-height 在 flex column 中 ──
$tests['max-height 在 flex column 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:300px;height:200px'], [
            VNode::h('div', ['style' => 'flex:1;max-height:60px;background:green'], 'Max 60px'),
        ])
    );
    assert_contains($result, '300x60', 'max-height:60px caps height to 60');
    return $result;
};

// ── Test 3: min-width 在 flex row 中 ──
$tests['min-width 在 flex row 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'flex:1;min-width:150px'], 'Min 150px'),
        ])
    );
    assert_contains($result, '400x50', 'min-width:150px satisfied, child fills 400px width');
    return $result;
};

// ── Test 4: max-width 在 flex row 中 ──
$tests['max-width 在 flex row 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'flex:1;max-width:100px'], 'Max 100px'),
        ])
    );
    assert_contains($result, '100x50', 'max-width:100px caps width to 100');
    return $result;
};

// ── Test 5: min-height 百分比 ──
$tests['min-height 50% 百分比'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:400px;height:200px'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:100px;min-height:50%'], 'Min 50%'),
        ])
    );
    assert_contains($result, '(0,0 100x50)', 'min-height:50% of 200px = 100px text height 50px');
    return $result;
};

// ── Test 6: max-height 百分比 ──
$tests['max-height 50% 百分比'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:400px;height:200px'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:100px;height:100px;max-height:50%'], 'Max 50%'),
        ])
    );
    assert_contains($result, '(0,0 100x50)', 'max-height:50% of 200 = 100px, text height 50px');
    return $result;
};

// ── Test 7: height auto 在 flex column 中 ──
$tests['height auto 在 flex column 中子项撑开'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:300px;height:auto'], [
            VNode::h('div', ['style' => 'height:50px'], 'Header'),
            VNode::h('div', ['style' => 'height:80px'], 'Body'),
            VNode::h('div', ['style' => 'height:40px'], 'Footer'),
        ])
    );
    assert_contains($result, '0,130 300x40', 'footer at y=50+80=130');
    return $result;
};

// ── Test 8: width auto 在 grid cell 中 ──
$tests['width auto 在 grid cell 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 200px;width:400px;height:60px;gap:8px'], [
            VNode::h('div', ['style' => 'height:40px'], 'Auto'),
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'Full'),
        ])
    );
    assert_contains($result, '(0,0 200x60)', 'first grid cell 200px width');
    return $result;
};

// ── Test 9: min-height > height 约束优先 ──
$tests['min-height 大于 height 时取 min-height'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;min-height:80px'], 'Min 80 > h 30')
    );
    assert_contains($result, '200x80', 'min-height:80 overrides height:30 -> h=80');
    return $result;
};

// ── Test 10: max-height < height 约束优先 ──
$tests['max-height 小于 height 时取 max-height'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:150px;max-height:60px'], 'Max 60 < h 150')
    );
    assert_contains($result, '200x60', 'max-height:60 overrides height:150 -> h=60');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-15-Sizing-Constraints.snap';
run_css_tests('Level 15 - Sizing Constraints', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
