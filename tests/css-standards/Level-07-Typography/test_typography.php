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
 *
 *   9-20. text-decoration 渲染输出（通过 run_render_pipeline 捕获绘制元素）
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

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

// ================================================================
// text-decoration 系列测试（通过 run_render_pipeline 验证渲染元素）
// ================================================================

// ── Test 9: text-decoration-line: underline ──
$tests['text-decoration-line: underline 下划线'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:underline;color:#333333'], 'Underlined text')
    );
    assert_contains($result, 'div (0,0 200x30)', 'Underline layout');
    assert_contains($result, 'decorationLine=underline', 'decorationLine is underline');
    assert_contains($result, 'decorationStyle=solid', 'decorationStyle defaults to solid');
    return $result;
};

// ── Test 10: text-decoration-line: overline ──
$tests['text-decoration-line: overline 上划线'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:overline;color:#333333'], 'Overlined text')
    );
    assert_contains($result, 'div (0,0 200x30)', 'Overline layout');
    assert_contains($result, 'decorationLine=overline', 'decorationLine is overline');
    return $result;
};

// ── Test 11: text-decoration-line: line-through ──
$tests['text-decoration-line: line-through 删除线'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:line-through;color:#333333'], 'Strikethrough text')
    );
    assert_contains($result, 'div (0,0 200x30)', 'Line-through layout');
    assert_contains($result, 'decorationLine=line-through', 'decorationLine is line-through');
    return $result;
};

// ── Test 12: text-decoration-color 独立属性 ──
$tests['text-decoration-color 自定义颜色'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:underline;text-decoration-color:#FF0000;color:#333333'], 'Colorful underline')
    );
    assert_contains($result, 'div (0,0 200x30)', 'decoration-color layout');
    assert_contains($result, 'decorationColor=0x0000FF', 'decorationColor is red (BGR 0x0000FF)');
    return $result;
};

// ── Test 13: text-decoration-style: double ──
$tests['text-decoration-style: double 双线'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:underline;text-decoration-style:double;color:#333333'], 'Double underline')
    );
    assert_contains($result, 'decorationStyle=double', 'decorationStyle is double');
    return $result;
};

// ── Test 14: text-decoration-style: dotted ──
$tests['text-decoration-style: dotted 点线'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:underline;text-decoration-style:dotted;color:#333333'], 'Dotted underline')
    );
    assert_contains($result, 'decorationStyle=dotted', 'decorationStyle is dotted');
    return $result;
};

// ── Test 15: text-decoration-style: dashed ──
$tests['text-decoration-style: dashed 虚线'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:underline;text-decoration-style:dashed;color:#333333'], 'Dashed underline')
    );
    assert_contains($result, 'decorationStyle=dashed', 'decorationStyle is dashed');
    return $result;
};

// ── Test 16: text-decoration-style: wavy ──
$tests['text-decoration-style: wavy 波浪线'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:underline;text-decoration-style:wavy;color:#333333'], 'Wavy underline')
    );
    assert_contains($result, 'decorationStyle=wavy', 'decorationStyle is wavy');
    return $result;
};

// ── Test 17: text-decoration-thickness ──
$tests['text-decoration-thickness: 3px 厚度'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:underline;text-decoration-thickness:3px;color:#333333'], 'Thick underline')
    );
    assert_contains($result, 'decorationThickness=3', 'decorationThickness is 3px');
    return $result;
};

// ── Test 18: text-underline-offset ──
$tests['text-underline-offset: 5px 偏移'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:underline;text-underline-offset:5px;color:#333333'], 'Offset underline')
    );
    assert_contains($result, 'underlineOffset=5', 'underlineOffset is 5px');
    return $result;
};

// ── Test 19: 多线组合 ──
$tests['text-decoration: underline overline 多线组合'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;text-decoration:underline overline;color:#333333'], 'Underline and overline')
    );
    assert_contains($result, 'decorationLine=underline overline', 'Multi-line: underline overline');
    return $result;
};

// ── Test 20: 简写语法 ──
$tests['text-decoration 简写 (underline wavy #FF0000 2px)'] = function() {
    $result = run_render_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:200px;height:30px;color:#333333;text-decoration:underline wavy #FF0000 2px'], 'Shorthand text')
    );
    assert_contains($result, 'div (0,0 200x30)', 'Shorthand layout');
    assert_contains($result, 'decorationLine=underline', 'Shorthand: decorationLine=underline');
    assert_contains($result, 'decorationStyle=wavy', 'Shorthand: decorationStyle=wavy');
    // 简写解析器展开后经 parseHexColor 转为 BGR 0x0000FF，parsePixels 转为 int
    assert_contains($result, 'decorationColor=0x0000FF', 'Shorthand: decorationColor=BGR 0x0000FF');
    assert_contains($result, 'decorationThickness=2', 'Shorthand: decorationThickness=2');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-07-Typography.snap';
run_css_tests('Level 7 - Typography', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
