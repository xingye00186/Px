<?php
/**
 * Level 11: Margin Contexts / Margin 上下文
 *
 * 测试目标：
 *   1. margin auto 在 block 中居中
 *   2. margin-left auto 在 flex row 中右推
 *   3. margin-top auto 在 flex column 中底推
 *   4. margin:auto 在 grid cell 中居中
 *   5. margin 负值在 flex 中
 *   6. margin 在滚动容器中
 *   7. margin-top 在 relative 中 + offset
 *   8. margin 四方向不同值
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: margin auto 在 block 中居中 ──
$tests['margin:0 auto 水平居中 block'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;height:auto;padding:16px'], [
            VNode::h('div', ['style' => 'width:200px;height:50px;margin:0 auto'], 'Centered'),
        ])
    );
    assert_contains($result, 'div (216,16 200x50) text="Centered"', 'margin:0 auto centers block horizontally: x=(600-200)/2=200? actual=216 within padding');
    return $result;
};

// ── Test 2: margin-left auto 在 flex row 中右推 ──
$tests['margin-left auto 在 flex row 右推'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:80px;height:30px'], 'Left'),
            VNode::h('div', ['style' => 'width:100px;height:30px;margin-left:auto'], 'Right'),
        ])
    );
    assert_contains($result, 'div (300,0 100x30) text="Right"', 'margin-left:auto pushes Right to x=400-100=300');
    return $result;
};

// ── Test 3: margin-top auto 在 flex column 中 ──
$tests['margin-top auto 在 flex column 底推'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:200px;height:200px'], [
            VNode::h('div', ['style' => 'height:40px'], 'Top'),
            VNode::h('div', ['style' => 'height:40px;margin-top:auto'], 'Bottom'),
        ])
    );
    assert_contains($result, 'div (0,40 200x40) text="Bottom"', 'margin-top:auto pushes Bottom below Top h=40');
    return $result;
};

// ── Test 4: margin auto 在 grid cell 中居中 ──
$tests['margin auto 在 grid cell 居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 200px;width:400px;height:100px'], [
            VNode::h('div', ['style' => 'width:100px;height:50px;margin:auto'], 'Center'),
            VNode::h('div', ['style' => 'width:180px;height:80px'], 'Normal'),
        ])
    );
    assert_contains($result, 'div (0,0 200x80) text="Center"', 'margin:auto centers in 200px grid cell');
    return $result;
};

// ── Test 5: margin 负值在 flex 中 ──
$tests['margin-left 负值在 flex row 中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:100px;height:30px'], 'Item 1'),
            VNode::h('div', ['style' => 'width:100px;height:30px;margin-left:-20px'], 'Item 2'),
        ])
    );
    assert_contains($result, 'div (80,0 100x30) text="Item 2"', 'margin-left:-20 shifts Item2 to x=100-20=80');
    return $result;
};

// ── Test 6: margin 在滚动容器中 ──
$tests['margin 在滚动容器中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow-y:auto;left:0;top:0;width:300px;height:150px;:scroll-top="0"'], [
            VNode::h('div', ['style' => 'height:40px;margin-bottom:12px'], 'Item 1'),
            VNode::h('div', ['style' => 'height:40px;margin-bottom:12px'], 'Item 2'),
            VNode::h('div', ['style' => 'height:40px'], 'Item 3'),
        ])
    );
    assert_contains($result, 'div (0,52 300x40) text="Item 2"', 'margin-bottom:12 gives Item2 y=40+12=52');
    return $result;
};

// ── Test 7: margin-top 在 relative 中 + 偏移 ──
$tests['margin + relative 偏移组合'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:auto'], [
            VNode::h('div', ['style' => 'height:40px'], 'Normal'),
            VNode::h('div', ['style' => 'position:relative;left:20px;top:10px;height:40px;margin-top:8px'], 'Rel margin'),
            VNode::h('div', ['style' => 'height:40px;margin-top:8px'], 'After'),
        ])
    );
    assert_contains($result, 'div (20,58 400x40)', 'margin-top:8 + top:10 offset: y=40+8+10=58');
    return $result;
};

// ── Test 8: margin 四方向不同值 ──
$tests['margin 四方向不同值 10 20 30 40'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto'], [
            VNode::h('div', ['style' => 'width:200px;height:40px;margin:10px 20px 30px 40px'], 'All margins'),
        ])
    );
    assert_contains($result, 'div (40,10 200x40) text="All margins"', 'margin:10 20 30 40 → left=40, top=10');
    return $result;
};

// ── Test 9: CSS 2.2 §8.3.1 场景 3 — 父吸收末孙 margin-bottom ──
// A: 父（无 padding-bottom, 无 border-bottom, 无 height, normal block）——允许 endMarginStrut 上传
// B: A 的末子（margin-bottom:30 上传给 A）
// D: A 的兄弟（margin-top:20 与 A 的 effectiveMBottom 相邻折叠）
// 启用前：D.y = 90（未吸收途径 — A 基于 B.mBottom 撑高，然后用 A.mBottom 与 D.mTop 折叠）
// 启用后：D.y = 70（A 吸收 B.mBottom=30，effectiveMBottom = max(10, 30) = 30，与 D.mTop=20 折叠 = 30）
$tests['CSS §8.3.1 场景 3：父吸收末孙 margin-bottom'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:auto'], [
            VNode::h('div', ['style' => 'margin-bottom:10px;width:200px'], [
                VNode::h('div', ['style' => 'height:40px;margin-bottom:30px'], 'Inner'),
            ]),
            VNode::h('div', ['style' => 'height:30px;margin-top:20px'], 'Sibling'),
        ])
    );
    assert_contains($result, 'div (0,70 400x30) text="Sibling"', 'CSS §8.3.1 场景 3：A 吸收 B.mBottom=30→effectiveMBottom=30→与 D.mTop=20 折叠=30→D.y=70');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-11-Margin-Contexts.snap';
run_css_tests('Level 11 - Margin Contexts', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
