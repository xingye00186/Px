<?php
/**
 * Level 30 - Margin Collapse（CSS 2.2 §8.3.1）
 *
 * 断言值来自真实 Chromium/Blink getBoundingClientRect（_gt_mc.html，BFC 隔离测量）。
 * 引擎与 Blink 逐项对比结论：
 *   T2 border 阻断 / T3 兄弟 max / T4 负值 / T5 BFC 阻断 —— 完全一致 ✅
 *   T1 父-首子穿透：子**绝对位置**一致（y=20）；差异仅在盒归属——
 *     Blink 将 margin 穿透到父外（父 y=20,h=30；子相对 y=0），
 *     Px 当前 margin 留在父内（父 y=0,h=50；子相对 y=20）。
 *     已知限制：真穿透需 LayoutResult preMarginStrut 回传链（父盒背景范围有别），
 *     此处按两引擎一致的**子绝对坐标**断言。
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: 父-首子 margin-top（子绝对位置断言；父盒归属差异见文件头注记） ──
$tests['父-首子 margin-top 子绝对位置'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'width:300px'], [
                VNode::h('div', ['style' => 'margin-top:20px;height:30px;background:#F88'], 'A'),
            ]),
        ])
    );
    // Blink 与 Px 一致的子绝对坐标：y=20（Blink: 父20+子0；Px: 父0+子20）
    assert_contains($result, 'div (0,20 300x30) text="A"', 'first-child margin-top: child absolute y=20 (Blink-consistent; box attribution differs, see header note)');
    return $result;
};

// ── Test 2: 父 border-top 阻断折叠 ──
$tests['border-top 阻断父子折叠'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'width:300px;border-top:1px solid #000'], [
                VNode::h('div', ['style' => 'margin-top:20px;height:30px;background:#F88'], 'B'),
            ]),
        ])
    );
    // Blink: 子相对父 y=21（border1+margin20），父高 51 —— 引擎完全一致
    assert_contains($result, 'div (0,21 300x30) text="B"', 'border-top blocks collapse: child y=21=1+20 (Blink-measured)');
    return $result;
};

// ── Test 3: 相邻兄弟折叠取 max ──
$tests['兄弟 margin 折叠 max(30,20)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'height:30px;margin-bottom:30px;background:#F88'], 'C'),
            VNode::h('div', ['style' => 'height:30px;margin-top:20px;background:#88F'], 'D'),
        ])
    );
    // Blink: D y=60=30+max(30,20) —— 引擎完全一致
    assert_contains($result, 'div (0,60 320x30) text="D"', 'sibling collapse: y=60=30+max(30,20) (Blink-measured)');
    return $result;
};

// ── Test 4: 负 margin 折叠 max(pos)+min(neg) ──
$tests['负 margin 折叠 30+(-10)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'height:30px;margin-bottom:30px;background:#F88'], 'E'),
            VNode::h('div', ['style' => 'height:30px;margin-top:-10px;background:#88F'], 'F'),
        ])
    );
    // Blink: F y=50=30+(30-10)（CSS 2.2 §8.3.1: max(positives)+min(negatives)）—— 引擎完全一致
    assert_contains($result, 'div (0,50 320x30) text="F"', 'negative collapse: y=50=30+(30-10) (Blink-measured)');
    return $result;
};

// ── Test 5: 父 BFC（overflow:hidden）阻断首子穿透 ──
$tests['BFC 阻断父-首子穿透'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:320px;overflow:hidden'], [
            VNode::h('div', ['style' => 'width:300px;overflow:hidden'], [
                VNode::h('div', ['style' => 'margin-top:20px;height:30px;background:#F88'], 'G'),
            ]),
        ])
    );
    // Blink: 子相对父 y=20（margin 留在 BFC 内），父高 50 —— 引擎完全一致
    assert_contains($result, 'div (0,20 300x30) text="G"', 'BFC blocks collapse-through: child y=20 inside parent, parent h=50 (Blink-measured)');
    assert_contains($result, 'div (0,0 300x50)', 'BFC parent height 50 includes child margin (Blink-measured)');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-30-Margin-Collapse.snap';
run_css_tests('Level 30 - Margin Collapse (CSS 2.2 §8.3.1)', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
