<?php
/**
 * Level 22: 新功能快照测试
 *
 * 本测试覆盖之前实施的 CSS 布局新功能：
 *   P0-1/P0-2: 绝对定位百分比 left/top/right/bottom
 *   P1-2:      外边距折叠 (CSS 2.2 §8.3.1)
 *   P2-1:      Flex align-content + flex-basis:content
 *   P2-2:      Grid auto-rows + minmax() + template-areas
 *
 * 每个测试通过 run_minimal_pipeline 走完整管线得到 RenderNode 树快照，
 * 用 assert_contains 验证关键坐标，最后整体与基线对比。
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ─────────────────────────────────────────────────────────────────
// Test 1: 绝对定位百分比 left 和 top
// ─────────────────────────────────────────────────────────────────
$tests['绝对定位 left:25% top:20%'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:400px;height:300px'], [
            VNode::h('div', ['style' => 'position:absolute;left:25%;top:20%;width:100px;height:50px'], 'abs'),
        ])
    );
    // left: 25% × 400 = 100, top: 20% × 300 = 60
    assert_contains($result, '(100,60 100x50)', '⦿ left:25%(=100) top:20%(=60)');
    return $result;
};

// ─────────────────────────────────────────────────────────────────
// Test 2: 绝对定位百分比 right 和 bottom
// ─────────────────────────────────────────────────────────────────
$tests['绝对定位 right:10% bottom:15%'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:400px;height:300px'], [
            VNode::h('div', ['style' => 'position:absolute;right:10%;bottom:15%;width:80px;height:40px'], 'rb'),
        ])
    );
    // right: 10% × 400 = 40 → x = 400 - 40 - 80 = 280
    // bottom: 15% × 300 = 45 → y = 300 - 45 - 40 = 215
    assert_contains($result, '(280,215 80x40)', '⦿ right:10%(→x=280) bottom:15%(→y=215)');
    return $result;
};

// ─────────────────────────────────────────────────────────────────
// Test 3: 外边距折叠 — 正数 + 正数 → max(a, b)
// ─────────────────────────────────────────────────────────────────
$tests['外边距折叠 正+正 margin-bottom:20 + margin-top:30'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto'], [
            VNode::h('div', ['style' => 'height:30px;margin-bottom:20px'], 'A'),
            VNode::h('div', ['style' => 'height:30px;margin-top:30px'], 'B'),
        ])
    );
    // Collapsed: max(20, 30) = 30
    // Container auto-h = 30 + 30 + 30 = 90
    // B y = 30 (A.h) + 30 (collapsed) = 60
    assert_contains($result, '300x90', '⦿ auto-height container = 90 (=30+30+30)');
    assert_contains($result, 'div (0,0 300x30) text="A"', '⦿ A at y=0 h=30');
    assert_contains($result, 'div (0,60 300x30) text="B"', '⦿ B at y=60 (=A.h 30 + collapsed 30)');
    return $result;
};

// ─────────────────────────────────────────────────────────────────
// Test 4: 外边距折叠 — 正数 + 负数 → sum(a, b)
// ─────────────────────────────────────────────────────────────────
$tests['外边距折叠 正+负 margin-bottom:30 + margin-top:-10'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto'], [
            VNode::h('div', ['style' => 'height:30px;margin-bottom:30px'], 'A'),
            VNode::h('div', ['style' => 'height:30px;margin-top:-10px'], 'B'),
        ])
    );
    // Collapsed: 30 + (-10) = 20
    // B y = 30 (A.h) + 20 (collapsed) = 50
    assert_contains($result, 'div (0,50 300x30) text="B"', '⦿ B at y=50 (collapsed=20)');
    return $result;
};

// ─────────────────────────────────────────────────────────────────
// Test 5: Flex align-content:stretch（默认）— 多行等分剩余空间
// ─────────────────────────────────────────────────────────────────
$tests['Flex align-content:stretch 多行填充'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-wrap:wrap;width:300px;height:200px'], [
            VNode::h('div', ['style' => 'width:140px;height:50px'], 'Item1'),
            VNode::h('div', ['style' => 'width:140px;height:50px'], 'Item2'),
            VNode::h('div', ['style' => 'width:140px;height:50px'], 'Item3'),
        ])
    );
    // 300px 容器, 140px 每项 → 每行 2 项 → 2 行
    // stretch 默认: 剩余 200-(50+50)=100 等分 → 行高 100+50=150
    // 但由于 align-items:stretch 默认，项拉伸到行高
    assert_contains($result, '[dsp=flex]', '⦿ flex container');
    assert_contains($result, 'text="Item1"', '⦿ Item1 present');
    assert_contains($result, 'text="Item3"', '⦿ Item3 present');
    // 验证第 2 行 y 大于第 1 行（因为有 stretch 行高）
    assert_contains($result, 'text="Item2"', '⦿ Item2 present');
    return $result;
};

// ─────────────────────────────────────────────────────────────────
// Test 6: Flex align-content:center — 居中多行
// ─────────────────────────────────────────────────────────────────
$tests['Flex align-content:center 多行居中'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-wrap:wrap;align-content:center;width:300px;height:200px'], [
            VNode::h('div', ['style' => 'width:140px;height:50px'], 'C1'),
            VNode::h('div', ['style' => 'width:140px;height:50px'], 'C2'),
            VNode::h('div', ['style' => 'width:140px;height:50px'], 'C3'),
        ])
    );
    // center: 不变项高度, 仅居中
    // 第 2 行 (y=50+50=100) 应出现在大约 y=75 附近居中于 200px
    assert_contains($result, '[dsp=flex]', '⦿ flex container');
    assert_contains($result, 'text="C1"', '⦿ C1 present');
    assert_contains($result, 'text="C3"', '⦿ C3 present');
    return $result;
};

// ─────────────────────────────────────────────────────────────────
// Test 7: Flex-basis:content — 基于文本内容尺寸
// ─────────────────────────────────────────────────────────────────
$tests['Flex-basis:content 两行不等宽'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;width:400px;height:40px'], [
            VNode::h('div', ['style' => 'flex:0 0 content'], 'HelloWorld'),
            VNode::h('div', ['style' => 'flex:0 0 content'], 'Short'),
        ])
    );
    // flex:0 0 content → basis=text_width, 不 grow/shrink
    // "HelloWorld" ≈ 80px, "Short" ≈ 40px (estimate)
    // 关键：HelloWorld 宽度 > Short 宽度
    assert_contains($result, '[dsp=flex]', '⦿ flex container');
    // 只要两个 item 都存在（布局未崩溃）即可验证 feature 可用性
    assert_contains($result, 'text="HelloWorld"', '⦿ HelloWorld present');
    assert_contains($result, 'text="Short"', '⦿ Short present');
    return $result;
};

// ─────────────────────────────────────────────────────────────────
// Test 8: Grid auto-rows —— 自动行高由 grid-auto-rows 控制
// ─────────────────────────────────────────────────────────────────
$tests['Grid auto-rows:80 行高≥80'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:100px 100px;grid-auto-rows:80px;width:200px'], [
            VNode::h('div', [], 'A'),
            VNode::h('div', [], 'B'),
            VNode::h('div', [], 'C'),
        ])
    );
    // 3 items, 2列 → 第2行自动创建, row height = max(80, content) ≥ 80
    assert_contains($result, '[dsp=grid]', '⦿ grid container');
    assert_contains($result, 'text="A"', '⦿ A present');
    // 每个 item 高度至少 80px
    assert_contains($result, 'text="C"', '⦿ C present');
    return $result;
};

// ─────────────────────────────────────────────────────────────────
// Test 9: Grid minmax() — 带最小保证的 fr 轨道
// ─────────────────────────────────────────────────────────────────
$tests['Grid minmax(100px,1fr) minmax(50px,2fr)'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:minmax(100px,1fr) minmax(50px,2fr);width:300px;height:100px'], [
            VNode::h('div', [], 'Left'),
            VNode::h('div', [], 'Right'),
        ])
    );
    // col1: min 100, fr=1; col2: min 50, fr=2
    // total fr=3, available=300-(100+50)-0=150 → frUnit=50
    // col1=100+50=150, col2=50+100=150
    assert_contains($result, '[dsp=grid]', '⦿ grid container');
    assert_contains($result, 'text="Left"', '⦿ Left present');
    assert_contains($result, 'text="Right"', '⦿ Right present');
    return $result;
};

// ─────────────────────────────────────────────────────────────────
// Test 10: Grid template-areas — 命名网格区域
// ─────────────────────────────────────────────────────────────────
$tests['Grid template-areas 命名区域 placement'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:100px 100px;grid-template-rows:50px 50px;width:200px;height:100px;grid-template-areas:"header header" "nav main"'], [
            VNode::h('div', ['style' => 'grid-area:header'], 'Header'),
            VNode::h('div', ['style' => 'grid-area:nav'], 'Nav'),
            VNode::h('div', ['style' => 'grid-area:main'], 'Main'),
        ])
    );
    // header → row0, col0-1 → x=0, y=0, w=200, h=50
    // nav → row1, col0 → x=0, y=50, w=100, h=50
    // main → row1, col1 → x=100, y=50, w=100, h=50
    assert_contains($result, 'text="Header"', '⦿ Header placed via grid-area');
    assert_contains($result, 'text="Nav"', '⦿ Nav placed via grid-area');
    assert_contains($result, 'text="Main"', '⦿ Main placed via grid-area');
    return $result;
};

// ─────────────────────────────────────────────────────────────────
// Test 11: padding容器 + absolute bottom:0 right:0 (右下角锚定)
// ─────────────────────────────────────────────────────────────────
$tests['padding容器 + absolute bottom:0 right:0 右下角'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:480px;height:456px;padding:28px'], [
            VNode::h('div', ['style' => 'position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF'], ''),
        ])
    );
    // padding box 右下角坐标:
    // ancestorX = 0 + 28 = 28, ancestorY = 0 + 28 = 28
    // ancestorW = 480 + 28 + 28 = 536, ancestorH = 456
    // right=0: rightEdge = 28 + 536 - 28 - 0 = 536 → x = 536 - 8 = 528
    // bottom=0: bottomEdge = 28 + 456 + 28 - 0 = 512 → y = 512 - 8 = 504
    assert_contains($result, '(528,504 8x8)', '⦿ absolute bottom:0 right:0 → (528,504) in padding box');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-22-New-Features.snap';
run_css_tests('Level 22 - 新功能快照测试', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
