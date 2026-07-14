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

use Px\Dom\VNode;

// =============================================================
// 测试用例定义
// =============================================================

$tests = [];

// ── Test 1: 绝对定位 div ──
$tests['绝对定位 div (left=10, top=20, w=100, h=50)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:20px;width:100px;height:50px'], 'Hello')
    );
    // CSS规范：left/top 仅在 position≠static 时生效，默认 position:static → 忽略 left/top
    assert_contains($result, 'div (0,0 100x50)', 'left/top 在 static 定位下无效 → div 应位于 (0,0)');
    return $result;
};

// ── Test 2: Block 容器内两个子项自动堆叠 ──
// CSS规范：block 子元素垂直排列，下一子元素的 y = 上一子元素的 y + 上一子元素 height
$tests['Auto-stack 两个 div 垂直排列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:auto'], [
            VNode::h('div', ['style' => 'width:200px;height:30px'], 'Line 1'),
            VNode::h('div', ['style' => 'width:200px;height:40px'], 'Line 2'),
        ])
    );
    // 父容器高度 = 30 + 40 = 70
    // Line 1 从 (0,0) 开始，高 30 → Line 2 从 (0,30) 开始
    assert_contains($result, 'div (0,0 200x70)', '容器高度应撑开为 30+40=70');
    assert_contains($result, 'div (0,0 200x30) text="Line 1"', 'Line 1 在顶部，高 30');
    assert_contains($result, 'div (0,30 200x40) text="Line 2"', 'Line 2 在 Line 1 下方 30px 处，高 40');
    return $result;
};

// ── Test 3: 嵌套 div ──
$tests['嵌套 div (父含子)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:300px;height:200px'], [
            VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:80px'], 'Child'),
        ])
    );
    // CSS规范：left/top 在 static 定位下无效 → child 默认为 (0,0)
    assert_contains($result, 'div (0,0 300x200)', '父容器尺寸 300x200');
    assert_contains($result, '  div (0,0 100x80) text="Child"', '子元素 left/top 被忽略，默认位于 (0,0)');
    return $result;
};

// ── Test 4: 百分比宽度 ──
// CSS规范：百分比宽度 = 包含块宽度 × 百分比
$tests['百分比宽度 width=50%'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:400px;height:100px'], [
            VNode::h('div', ['style' => 'width:50%;height:50px'], '50% width'),
        ])
    );
    // 50% of 400 = 200
    assert_contains($result, 'div (0,0 200x50) text="50% width"', '50% of 400 = 200');
    return $result;
};

// ── Test 5: padding 影响内容区 ──
// CSS规范：padding 在内容区四周创建内边距，子元素从 padding-box 左上角开始
$tests['padding 缩小内容区 (padding=10px)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:200px;height:100px;padding:10px'], [
            VNode::h('div', ['style' => 'width:100%;height:50px'], 'Padding test'),
        ])
    );
    // padding=10 → 子元素从 padding-box 的 (10,10) 开始
    // CSS: width:100% 相对于包含块内容宽度，content-box 下内容宽度=200
    assert_contains($result, 'div (10,10 200x50) text="Padding test"', 'padding=10 → child 从 (10,10) 开始');
    return $result;
};

// ── Test 6: margin-top 间距 ──
// CSS规范：margin-top 在相邻 block 间产生间距，Item2 的 y = Item1.y + Item1.h + Item2.margin-top
//           注：margin collapsing 是相邻 margin 取最大值，此处 Item1.margin-top=0 → 无 collapsing 问题
$tests['margin-top 在 block 间间距'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:auto'], [
            VNode::h('div', ['style' => 'width:200px;height:30px;margin-top:0'], 'Item 1'),
            VNode::h('div', ['style' => 'width:200px;height:30px;margin-top:10px'], 'Item 2'),
        ])
    );
    // Item2.y = 0 + 30 + 10 = 40。容器高度 = 30 + 10 + 30 = 70
    assert_contains($result, 'div (0,40 200x30) text="Item 2"', 'Item2 的 y = Item1(0)+h(30)+margin-top(10) = 40');
    return $result;
};

// ── Test 7: min-width 约束 ──
// CSS规范：计算宽度后与 min-width 取较大值
$tests['min-width 约束限制最小宽度'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:300px;height:100px'], [
            VNode::h('div', ['style' => 'width:10%;height:50px;min-width:100px'], 'min-width'),
        ])
    );
    // 10% of 300 = 30 < 100(min-width) → 最终宽度 = 100
    assert_contains($result, 'div (0,0 100x50) text="min-width"', '10%(30px) < min-width(100px) → 最终宽度=100');
    return $result;
};

// ── Test 8: max-width 约束 ──
// CSS规范：计算宽度后与 max-width 取较小值
$tests['max-width 约束限制最大宽度'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:800px;height:100px'], [
            VNode::h('div', ['style' => 'width:100%;height:50px;max-width:400px'], 'max-width'),
        ])
    );
    // 100% of 800 = 800 > 400(max-width) → 最终宽度 = 400
    assert_contains($result, 'div (0,0 400x50) text="max-width"', '100%(800px) > max-width(400px) → 最终宽度=400');
    return $result;
};

// ── Test 9: 负 margin ──
// CSS规范：负 margin-left 使元素向左偏移
$tests['margin-left 负值左移'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:auto'], [
            VNode::h('div', ['style' => 'width:100px;height:30px'], 'Item 1'),
            VNode::h('div', ['style' => 'width:100px;height:30px;margin-left:-20px'], 'Item 2'),
        ])
    );
    // Item2.y = 0 + 30 = 30, 负 margin-left=-20 → x=-20
    assert_contains($result, 'div (-20,30 100x30) text="Item 2"', '负 margin-left=-20 → Item2 x 偏移至 -20');
    return $result;
};

// ── Test 10: 宽度 auto 填充包含块 ──
// CSS规范：block 级元素不设 width → auto 填充包含块宽度
$tests['宽度 auto 填充包含块 (无显式 width)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:500px;height:100px'], [
            VNode::h('div', ['style' => 'height:50px'], 'auto width'),
        ])
    );
    assert_contains($result, 'div (0,0 500x50) text="auto width"', '无显式 width → auto 填充含块宽度 500');
    return $result;
};

// ── Test 11: 三个子项依次 auto-stack ──
// CSS规范：block 子元素按顺序垂直堆叠，y 坐标累加
$tests['三个子项不同高度依次堆叠'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto'], [
            VNode::h('div', ['style' => 'width:300px;height:20px'], 'A'),
            VNode::h('div', ['style' => 'width:300px;height:30px'], 'B'),
            VNode::h('div', ['style' => 'width:300px;height:40px'], 'C'),
        ])
    );
    // 容器总高 = 20+30+40 = 90
    // A.y=0, B.y=20, C.y=50
    assert_contains($result, 'div (0,0 300x20) text="A"', 'A 在顶部 (0,0)');
    assert_contains($result, 'div (0,20 300x30) text="B"', 'B 在 A 下方 20px 处');
    assert_contains($result, 'div (0,50 300x40) text="C"', 'C 在 B 下方 30px 处 (20+30=50)');
    return $result;
};

// ── Test 12: 百分比高度 ──
// CSS规范：百分比高度 = 包含块高度 × 百分比
$tests['百分比高度 height=50%'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:400px;height:200px'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:100px;height:50%'], '50% h'),
        ])
    );
    // 50% of 200 = 100
    assert_contains($result, 'div (0,0 100x100) text="50% h"', '50% of 200 = 100');
    return $result;
};

// ── Test 13: border 对盒模型影响 ──
// CSS规范：border 在元素外围绘制，bw 跟踪边框宽度
$tests['border 增加外尺寸'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:10px;top:10px;width:100px;height:50px;border:2px'], 'Border')
    );
    // left/top 在 static 下无效，但 border:bw=2 应显示
    assert_contains($result, 'bw=2', 'border:2px → bw 应为 2');
    assert_contains($result, 'div (0,0 100x50) bw=2 text="Border"', 'border=2 记录在 bw，left/top 被忽略');
    return $result;
};

// ── Test 14: border + padding + width 组合 ──
// CSS规范：padding 影响内容区偏移，border 在 padding 外围
$tests['border+padding+width 组合'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'left:0;top:0;width:200px;height:100px;padding:10px;border:2px'], [
            VNode::h('div', ['style' => 'width:100%;height:50px'], 'Inner'),
        ])
    );
    // padding=10 → 子元素从 padding-box 的 (10,10) 开始
    // CSS: width:100% 相对于包含块内容宽度，content-box 下内容宽度=200
    // border=2 在 padding 外围
    assert_contains($result, 'div (0,0 200x100) bw=2', '父容器 200x100 + bw=2');
    assert_contains($result, 'div (10,10 200x50) text="Inner"', 'padding=10 → 子元素从 (10,10) 开始');
    return $result;
};

// =============================================================
// 运行测试
// =============================================================

$snapFile = __DIR__ . '/../__snapshots__/Level-01-Box-Model.snap';
run_css_tests('Level 1 - 基本盒子模型', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
