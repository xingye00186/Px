<?php
/**
 * Level 27: Box Sizing & Layout Units / 盒尺寸与布局单位
 *
 * 测试目标：
 *   1. box-sizing:border-box width+padding 内容区缩小
 *   2. box-sizing:border-box width+padding+border 进一步缩小
 *   3. box-sizing:content-box 默认行为
 *   4. min-width 约束强制最小宽度
 *   5. max-width 约束上限
 *   6. min-height 约束
 *   7. width:50% 百分比宽度
 *   8. width:calc(100% - 60px) calc表达式
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: box-sizing:border-box 宽度+内边距 ──
$tests['box-sizing:border-box 内容区缩小'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'box-sizing:border-box;width:200px;height:80px;padding:20px;background:#F00'], [
            VNode::h('div', ['style' => 'width:100%;height:30px;background:#0F0'], 'Child'),
        ])
    );
    // 子元素宽度应为 160px (200 - padding:20*2)
    assert_contains($result, '(20,20 160x30)', 'border-box: child width 160 after 20px padding');
    return $result;
};

// ── Test 2: box-sizing:border-box 宽度+内边距+边框 ──
$tests['box-sizing:border-box 边框进一步缩小内容'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'box-sizing:border-box;width:200px;height:80px;padding:10px;border-width:2px;background:#F00'], [
            VNode::h('div', ['style' => 'width:100%;height:30px;background:#0F0'], 'Inner'),
        ])
    );
    // content width = 200 - 10*2 - 2*2 = 176, child placed at padding offset (10,10)
    assert_contains($result, '(10,10 176x30)', 'border-box: child width 176 after 10px padding + 2px border');
    return $result;
};

// ── Test 3: box-sizing:content-box 默认 ──
$tests['box-sizing:content-box 默认内容宽度不受padding影响'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'box-sizing:content-box;width:200px;height:80px;padding:20px;background:#F00'], [
            VNode::h('div', ['style' => 'width:100%;height:30px;background:#0F0'], 'Child'),
        ])
    );
    assert_contains($result, '(20,20 200x30)', 'content-box: child width 200, padding adds to box total');
    return $result;
};

// ── Test 4: min-width 约束 ──
$tests['min-width:300px 覆盖 width:100px'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:400px;height:60px'], [
            VNode::h('div', ['style' => 'min-width:300px;width:100px;height:40px;background:#F00'], 'Min'),
        ])
    );
    assert_contains($result, 'text="Min"', 'min-width:300px child present');
    assert_contains($result, '(0,0 300x40)', 'min-width:300px forces width to at least 300');
    return $result;
};

// ── Test 5: max-width 约束 ──
$tests['max-width:150px 覆盖 width:300px'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:400px;height:60px'], [
            VNode::h('div', ['style' => 'max-width:150px;width:300px;height:40px;background:#0F0'], 'Max'),
        ])
    );
    assert_contains($result, 'text="Max"', 'max-width:150px child present');
    assert_contains($result, '(0,0 150x40)', 'max-width:150px caps width from 300 to 150');
    return $result;
};

// ── Test 6: min-height 约束 ──
$tests['min-height:200px 覆盖 height:50px'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;min-height:200px;height:50px;background:#00F'], [
            VNode::h('div', ['style' => 'height:30px'], 'Tall'),
        ])
    );
    assert_contains($result, '(0,0 200x200)', 'min-height:200px forces height to 200, overriding 50px');
    return $result;
};

// ── Test 7: 百分比宽度 ──
$tests['width:50% 百分比宽度'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:60px;background:#F0F'], [
            VNode::h('div', ['style' => 'width:50%;height:40px;background:#FF0'], 'Half'),
        ])
    );
    assert_contains($result, 'text="Half"', '50% width child present');
    assert_contains($result, '(0,0 200x40)', '50% of 400px container = 200px width');
    return $result;
};

// ── Test 8: calc(100% - 60px) ──
$tests['calc(100% - 60px) 计算表达式'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:60px;background:#F0F'], [
            VNode::h('div', ['style' => 'width:calc(100% - 60px);height:40px;background:#FF0'], 'Calc'),
        ])
    );
    assert_contains($result, 'text="Calc"', 'calc(100% - 60px) child present');
    assert_contains($result, '(0,0 340x40)', 'calc(100% - 60px) = 340px');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-27-Box-Sizing-Units.snap';
run_css_tests('Level 27 - Box Sizing & Layout Units', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
