<?php
/**
 * Level 7: Typography / 文字排版
 *
 * 测试目标：
 *   1. color 文字颜色
 *   2. font-size 字号
 *   3. font-weight bold 加粗
 *   4. text-align right/center 对齐
 *   5. white-space nowrap 不折行
 *   6. text-overflow ellipsis 省略号
 *   7. 字体属性组合不影响布局
 *   8. 多行文本不同字体属性
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: 文字颜色 ──
$tests['color:#FF0000 红色文字'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;color:#FF0000'], 'Red text')
    );
    assert_contains($result, 'div (0,0 200x30)', 'Explicit width=200 height=30 for text');
    return $result;
};

// ── Test 2: font-size 32px 大字号 ──
$tests['font-size 32px 大字'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:50px;font-size:32px'], 'Big text')
    );
    assert_contains($result, 'div (0,0 200x50)', 'height=50 accommodates font-size=32px');
    return $result;
};

// ── Test 3: font-weight bold 加粗 ──
$tests['font-weight bold 加粗'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;font-weight:bold'], 'Bold text')
    );
    assert_contains($result, 'div (0,0 200x30)', 'Explicit width=200 height=30 font-weight:bold');
    return $result;
};

// ── Test 4: text-align right 右对齐 ──
$tests['text-align right 右对齐'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:300px;height:30px;text-align:right'], 'Right aligned')
    );
    assert_contains($result, 'div (0,0 300x30)', 'text-align:right width=300px explicit');
    return $result;
};

// ── Test 5: text-align center 居中 ──
$tests['text-align center 居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:300px;height:30px;text-align:center'], 'Center aligned')
    );
    assert_contains($result, 'div (0,0 300x30)', 'text-align:center width=300px explicit');
    return $result;
};

// ── Test 6: white-space nowrap 不折行 ──
$tests['white-space nowrap 不折行'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:80px;height:30px;white-space:nowrap'], 'Long text that should not wrap')
    );
    assert_contains($result, 'text="Long text that should not wrap"', 'white-space:nowrap prevents line break in 80px');
    return $result;
};

// ── Test 7: text-overflow ellipsis ──
$tests['text-overflow ellipsis 省略号'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:80px;height:30px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap'], 'Very long text clipped')
    );
    assert_contains($result, 'ov=hidden', 'text-overflow:ellipsis requires overflow:hidden');
    return $result;
};

// ── Test 8: 字体属性组合 ──
$tests['字体属性组合 (color+font-size+bold)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:400px;height:50px;color:#3366FF;font-size:24px;font-weight:bold;text-align:center'], 'Styled text')
    );
    assert_contains($result, 'div (0,0 400x50)', 'Combined typography props width=400 height=50');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-07-Typography.snap';
run_css_tests('Level 7 - Typography', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
