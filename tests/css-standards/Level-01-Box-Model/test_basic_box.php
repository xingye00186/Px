<?php
/**
 * Level 1: 基本盒子模型 (扩展版)
 *
 * 测试目标：从简单到复杂覆盖盒子模型的核心行为
 *   1-4:   基础定位/自动堆叠/嵌套/百分比
 *   5-8:   padding/margin/min-max 约束
 *   9-12:  负 margin/auto 宽度/多子项堆叠/百分比高度
 *   13-15: border/组合场景
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

// =============================================================
// 测试用例定义
// =============================================================

$tests = [];

// ── Test 1: 绝对定位 div ──
$tests['绝对定位 div (left=10, top=20, w=100, h=50)'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:20px;width:100px;height:50px'], 'Hello')
    );
};

// ── Test 2: Block 容器内两个子项自动堆叠 ──
$tests['Auto-stack 两个 div 垂直排列'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:auto'], [
            VNode::h('div', ['style' => 'width:200px;height:30px'], 'Line 1'),
            VNode::h('div', ['style' => 'width:200px;height:40px'], 'Line 2'),
        ])
    );
};

// ── Test 3: 嵌套 div ──
$tests['嵌套 div (父含子)'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:300px;height:200px'], [
            VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:80px'], 'Child'),
        ])
    );
};

// ── Test 4: 百分比宽度 ──
$tests['百分比宽度 width=50%'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:400px;height:100px'], [
            VNode::h('div', ['style' => 'width:50%;height:50px'], '50% width'),
        ])
    );
};

// ── Test 5: padding 影响内容区 ──
$tests['padding 缩小内容区 (padding=10px)'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:200px;height:100px;padding:10px'], [
            VNode::h('div', ['style' => 'width:100%;height:50px'], 'Padding test'),
        ])
    );
};

// ── Test 6: margin-top 间距 ──
$tests['margin-top 在 block 间间距'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:auto'], [
            VNode::h('div', ['style' => 'width:200px;height:30px;margin-top:0'], 'Item 1'),
            VNode::h('div', ['style' => 'width:200px;height:30px;margin-top:10px'], 'Item 2'),
        ])
    );
};

// ── Test 7: min-width 约束 ──
$tests['min-width 约束限制最小宽度'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:300px;height:100px'], [
            VNode::h('div', ['style' => 'width:10%;height:50px;min-width:100px'], 'min-width'),
        ])
    );
};

// ── Test 8: max-width 约束 ──
$tests['max-width 约束限制最大宽度'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:800px;height:100px'], [
            VNode::h('div', ['style' => 'width:100%;height:50px;max-width:400px'], 'max-width'),
        ])
    );
};

// ── Test 9: 负 margin ──
$tests['margin-left 负值左移'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:auto'], [
            VNode::h('div', ['style' => 'width:100px;height:30px'], 'Item 1'),
            VNode::h('div', ['style' => 'width:100px;height:30px;margin-left:-20px'], 'Item 2'),
        ])
    );
};

// ── Test 10: 宽度 auto 填充包含块 ──
$tests['宽度 auto 填充包含块 (无显式 width)'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:500px;height:100px'], [
            VNode::h('div', ['style' => 'height:50px'], 'auto width'),
        ])
    );
};

// ── Test 11: 三个子项依次 auto-stack ──
$tests['三个子项不同高度依次堆叠'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto'], [
            VNode::h('div', ['style' => 'width:300px;height:20px'], 'A'),
            VNode::h('div', ['style' => 'width:300px;height:30px'], 'B'),
            VNode::h('div', ['style' => 'width:300px;height:40px'], 'C'),
        ])
    );
};

// ── Test 12: 百分比高度 ──
$tests['百分比高度 height=50%'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:400px;height:200px'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:100px;height:50%'], '50% h'),
        ])
    );
};

// ── Test 13: border 对盒模型影响 ──
$tests['border 增加外尺寸'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;border:2px'], 'Border')
    );
};

// ── Test 14: border + padding + width 组合 ──
$tests['border+padding+width 组合'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:200px;height:100px;padding:10px;border:2px'], [
            VNode::h('div', ['style' => 'width:100%;height:50px'], 'Inner'),
        ])
    );
};

// =============================================================
// 运行测试
// =============================================================

$snapFile = __DIR__ . '/../__snapshots__/Level-01-Box-Model.snap';
run_css_tests('Level 1 - 基本盒子模型', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
