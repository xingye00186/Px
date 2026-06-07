<?php
/**
 * Level 16: Transform / Visual / 变换与视觉效果
 *
 * 测试目标：
 *   1. translateX 水平偏移
 *   2. translateY 垂直偏移
 *   3. object-fit cover 图片填充
 *   4. object-fit contain 图片适应
 *   5. cursor pointer 指针样式
 *   6. transform rotate 旋转
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: translateX 水平偏移 ──
$tests['transform translateX(30px) 水平偏移'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;transform:translateX(30px)'], 'Translate X')
    );
    assert_contains($result, '(0,0 100x50)', 'transform does NOT affect layout position');
    return $result;
};

// ── Test 2: translateY 垂直偏移 ──
$tests['transform translateY(20px) 垂直偏移'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;transform:translateY(20px)'], 'Translate Y')
    );
    assert_contains($result, '(0,0 100x50)', 'transform does NOT affect layout position');
    return $result;
};

// ── Test 3: object-fit cover ──
$tests['object-fit cover 覆盖填充'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:100px;object-fit:cover'], 'Cover')
    );
    assert_contains($result, '(0,0 200x100)', 'object-fit:cover dimensions unchanged');
    return $result;
};

// ── Test 4: object-fit contain ──
$tests['object-fit contain 适应'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:100px;object-fit:contain'], 'Contain')
    );
    assert_contains($result, '(0,0 200x100)', 'object-fit:contain dimensions unchanged');
    return $result;
};

// ── Test 5: cursor pointer ──
$tests['cursor pointer 指针样式'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;cursor:pointer'], 'Clickable')
    );
    assert_contains($result, '(0,0 100x50)', 'cursor does not affect layout');
    return $result;
};

// ── Test 6: transform rotate 旋转 ──
$tests['transform rotate(45deg) 旋转'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;transform:rotate(45deg)'], 'Rotated')
    );
    assert_contains($result, '(0,0 100x50)', 'transform does NOT affect layout position');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-16-Transform-Visual.snap';
run_css_tests('Level 16 - Transform/Visual', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
