<?php
/**
 * Level 23: CSS Advanced Features / CSS 高级特性
 *
 * 测试目标：
 *   1. outline-width/style/color 轮廓线（属性解析）
 *   2. overflow:hidden 溢出裁剪（clip region padding-box）
 *   3. input placeholder 属性传递
 *   4. 多个 outline 属性的组合
 *
 * 注意：calc()、var()、相对单位(em/rem/vw/vh) 的解析测试放在
 * CssValueParserTest.php（直接单元测试）。
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: outline-width 单独设置 ──
$tests['outline-width:3px 解析'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;outline-width:3px;outline-style:solid;outline-color:#FF0000'], 'Outlined')
    );
    // outline 不影响布局坐标
    assert_contains($result, 'div (0,0 100x50)', 'outline does not affect layout');
    return $result;
};

// ── Test 2: outline-style 不同值 ──
$tests['outline-style:dashed 不影响布局'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:200px;height:80px;outline-style:dashed;outline-width:2px;outline-color:#00FF00'], 'Dashed')
    );
    assert_contains($result, 'div (0,0 200x80)', 'outline:dashed layout unchanged');
    return $result;
};

// ── Test 3: 多 outline 属性组合 ──
$tests['outline 多属性不影响布局'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:150px;height:60px;outline-width:5px;outline-style:double;outline-color:#3366FF'], 'Double outline')
    );
    assert_contains($result, 'div (0,0 150x60)', 'outline combination layout 150x60');
    return $result;
};

// ── Test 4: overflow:hidden 创建 clip region ──
$tests['overflow:hidden 溢出裁剪'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:200px;height:100px;overflow:hidden'], [
            VNode::h('div', ['style' => 'width:300px;height:50px'], 'Wide content'),
        ])
    );
    assert_contains($result, 'ov=hidden', 'overflow:hidden marked');
    assert_contains($result, 'Wide content', 'child content rendered');
    return $result;
};

// ── Test 5: clip region + border 验证 padding-box 偏移 ──
// CSS Overflow Module L3 §3.2: clip region = padding box
$tests['overflow:hidden + border clip region 偏移'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:200px;height:100px;overflow:hidden;border:5px solid #000'], [
            VNode::h('div', ['style' => 'width:400px;height:50px'], 'Oversized'),
        ])
    );
    assert_contains($result, 'ov=hidden', 'overflow:hidden with border');
    assert_contains($result, 'bw=5', 'border-width=5');
    return $result;
};

// ── Test 6: overflow:auto 滚动容器 ──
$tests['overflow:auto 滚动容器'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:200px;height:100px;overflow:auto'], [
            VNode::h('div', ['style' => 'width:400px;height:200px'], 'Large content'),
        ])
    );
    assert_contains($result, 'scroll ch=', 'scroll container with content');
    assert_contains($result, 'ov=auto', 'overflow:auto marked');
    return $result;
};

// ── Test 7: overflow:scroll 强制滚动（水平和垂直）──
$tests['overflow:scroll 强制滚动条'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:150px;height:80px;overflow:scroll'], [
            VNode::h('div', ['style' => 'width:100px;height:40px'], 'Small'),
        ])
    );
    assert_contains($result, 'ov=scroll', 'overflow:scroll marked');
    assert_contains($result, 'scroll ', 'scroll container');
    return $result;
};

// ── Test 8: input placeholder 属性 ──
// VNode type=input 渲染时传递 placeholder
$tests['input placeholder 文本'] = function () {
    // 创建一个带有 placeholder 的 input 元素
    // 使用 run_minimal_pipeline 验证 placeholder 存在
    $result = run_minimal_pipeline(
        VNode::h('input', ['style' => 'left:0;top:0;width:200px;height:36px;font-size:14px', 'placeholder' => '请输入文本'], '')
    );
    // 验证 input 渲染正常
    assert_contains($result, 'input', 'input element rendered');
    return $result;
};

// ── Test 9: 空 input 显示 placeholder ──
$tests['空 input 无 placeholder 不显示'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('input', ['style' => 'left:0;top:0;width:200px;height:36px'], '')
    );
    assert_contains($result, 'input', 'empty input without placeholder');
    return $result;
};

// ── Test 10: border 不影响 overflow clip 区域 ──
$tests['overflow clip 不受 border-radius 影响'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:200px;height:100px;overflow:hidden;border-radius:10px'], [
            VNode::h('div', ['style' => 'width:300px;height:150px'], 'Overflowing'),
        ])
    );
    assert_contains($result, 'ov=hidden', 'overflow:hidden with border-radius');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-23-CSS-Advanced.snap';
run_css_tests('Level 23 - CSS Advanced Features', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
