<?php
/**
 * Level 31 - Flex Shorthand Expansion（CSS Flexbox §7.1 / §4.5 / §9.3）
 *
 * flex 简写展开启用批次（§9.2）的回归护栏。断言值来自真实 Chromium/Blink
 * getBoundingClientRect（_gt_flexshorthand.html，BFC 隔离测量，body margin:0）。
 *
 * 关键规范语义（Blink-measured 2026-07-25）：
 *   - flex:<number> 展开为 <number> 1 0%（§7.1.1——0% 非 0px，CssFlex::fromString）
 *   - F2/F3：indefinite column 主轴下 flex:1（0%）与 flex:1 1 0px 的 item
 *     均受 §4.5 automatic minimum size 保护（min-height:auto → content 高），
 *     hypothetical main size = clamp(basis, min, max)（§9.3）——不塌 0
 *   - 已知一致维度差异：文本行高 Blink 22.77（浮点）vs Px 19（估算公式整数），
 *     断言取 Px 稳定值并核对结构（y 序、grow 分配、容器合计）与 Blink 一致
 *   - F6 亚像素：Blink 183.33/116.67 vs Px 183/116（§5 决策树整数余数处理）
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: flex:1 在 definite column 主轴 → 填满剩余空间 ──
$tests['flex:1 definite column 填满'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;height:200px;width:300px'], [
                VNode::h('div', ['style' => 'flex:1;background:#F88'], 'A'),
                VNode::h('div', ['style' => 'height:50px;background:#88F'], 'B'),
            ]),
        ])
    );
    // Blink: a h=150=200-50（grow 吸收全部剩余），b y=150
    assert_contains($result, 'div (0,0 300x150) fg=1 text="A"', 'flex:1 definite column: h=150=200-50 (Blink-measured)');
    assert_contains($result, 'div (0,150 300x50) text="B"', 'B stacked after grown A at y=150 (Blink-measured)');
    return $result;
};

// ── Test 2: flex:1（basis 0%）在 indefinite column → automatic minimum size 保 content ──
$tests['flex:1 indefinite column 保 content 高'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:300px'], [
                VNode::h('div', ['style' => 'flex:1;background:#F88'], 'A'),
                VNode::h('div', ['style' => 'height:50px;background:#88F'], 'B'),
            ]),
        ])
    );
    // Blink: a h=content(22.77)、b y=content——Px 行高估算 19（一致维度断言）。
    // §4.5 automatic minimum size：basis 0% 解析为 0 后被 min=content clamp，不塌 0
    assert_contains($result, 'div (0,0 300x19) fg=1 text="A"', 'flex:1 indefinite column: content height kept, not 0 (CSS Flexbox s4.5, Blink-verified structure)');
    assert_contains($result, 'div (0,19 300x50) text="B"', 'B at y=contentH (Blink structure: y=22.77, Px line-height 19)');
    return $result;
};

// ── Test 3: flex:1 1 0px 在 indefinite column → 同受 automatic minimum 保护 ──
$tests['flex:1 1 0px indefinite column 同保护'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:300px'], [
                VNode::h('div', ['style' => 'flex:1 1 0px;background:#F88'], 'A'),
                VNode::h('div', ['style' => 'height:50px;background:#88F'], 'B'),
            ]),
        ])
    );
    // Blink: 与 Test 2 完全相同（0px 长度 basis 同样被 min-height:auto clamp）
    assert_contains($result, 'div (0,0 300x19) fg=1 text="A"', 'flex:1 1 0px: automatic minimum applies to length basis too (Blink-measured F3==F2)');
    return $result;
};

// ── Test 4: flex:1 row definite 主轴 → 吸收剩余宽 ──
$tests['flex:1 row 吸收剩余宽'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'display:flex;width:300px'], [
                VNode::h('div', ['style' => 'flex:1;background:#F88'], 'A'),
                VNode::h('div', ['style' => 'width:100px;background:#88F'], 'B'),
            ]),
        ])
    );
    // Blink: a w=200=300-100, b x=200
    assert_contains($result, 'div (0,0 200x19) fg=1 text="A"', 'flex:1 row: w=200=300-100 (Blink-measured)');
    assert_contains($result, 'div (200,0 100x19) text="B"', 'B at x=200 (Blink-measured)');
    return $result;
};

// ── Test 5: flex:none（0 0 auto）+ flex:auto（1 1 auto）──
$tests['flex:none 与 flex:auto 关键字'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'display:flex;width:300px'], [
                VNode::h('div', ['style' => 'flex:none;width:80px;background:#F88'], 'A'),
                VNode::h('div', ['style' => 'flex:auto;background:#88F'], 'B'),
            ]),
        ])
    );
    // Blink: a w=80（none=0 0 auto 不伸缩），b w=220=300-80（auto=1 1 auto 吸收）
    assert_contains($result, 'div (0,0 80x19) text="A"', 'flex:none keeps width 80 (Blink-measured)');
    assert_contains($result, 'div (80,0 220x19) fg=1 text="B"', 'flex:auto grows to 220=300-80 (Blink-measured)');
    return $result;
};

// ── Test 6: flex:2 1 50px vs flex:1 1 50px 多值展开保真 ──
$tests['flex 三值简写按权重分配'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'display:flex;width:300px'], [
                VNode::h('div', ['style' => 'flex:2 1 50px;background:#F88'], 'A'),
                VNode::h('div', ['style' => 'flex:1 1 50px;background:#88F'], 'B'),
            ]),
        ])
    );
    // Blink: free=300-100=200；a=50+200*2/3=183.33→Px 183；b=50+200/3=116.67→Px 116
    assert_contains($result, 'div (0,0 183x19) fg=2 text="A"', 'flex:2 1 50px: w=183=50+floor(200*2/3) (Blink 183.33, integer remainder)');
    assert_contains($result, 'div (183,0 116x19) fg=1 text="B"', 'flex:1 1 50px: w=116=50+floor(200/3) (Blink 116.67)');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-31-Flex-Shorthand.snap';
run_css_tests('Level 31 - Flex Shorthand Expansion (CSS Flexbox s7.1/s4.5/s9.3)', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
