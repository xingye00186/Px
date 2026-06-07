<?php
/**
 * Level 3: Grid 布局
 *
 * 测试目标：
 *   1. grid-template-columns: 1fr 1fr 1fr 1fr 四列等宽
 *   2. grid-template-columns: repeat(4, 1fr)
 *   3. repeat(auto-fill, minmax(300px, 1fr)) 自适应列数
 *   4. 使用 gap 间距
 *   5. Grid 子项的 width:100% 应解析为 grid cell 宽度
 *   6. Grid + 内部 flex 子项混合
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: 四列等宽 grid ──
$tests['grid 四列等宽 (1fr 1fr 1fr 1fr)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr 1fr 1fr;width:800px;height:100px;gap:8px'], [
            VNode::h('div', ['style' => 'height:80px;background:#FF0000'], 'A'),
            VNode::h('div', ['style' => 'height:80px;background:#00FF00'], 'B'),
            VNode::h('div', ['style' => 'height:80px;background:#0000FF'], 'C'),
            VNode::h('div', ['style' => 'height:80px;background:#FFFF00'], 'D'),
        ])
    );
    assert_contains($result, 'div (202,0 194x80) text="B"', '1fr: each col = (800-3*8)/4 = 194, B.x = 0+194+8(gap) = 202');
    return $result;
};

// ── Test 2: auto-fill 自适应列数 ──
$tests['grid auto-fill minmax(300px,1fr)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));width:1392px;gap:16px'], [
            VNode::h('div', ['style' => 'height:140px;width:100%'], 'Card 1'),
            VNode::h('div', ['style' => 'height:140px;width:100%'], 'Card 2'),
            VNode::h('div', ['style' => 'height:140px;width:100%'], 'Card 3'),
            VNode::h('div', ['style' => 'height:140px;width:100%'], 'Card 4'),
            VNode::h('div', ['style' => 'height:140px;width:100%'], 'Card 5'),
        ])
    );
    assert_contains($result, 'div (352,0 336x140) text="Card 2"', 'auto-fill: col 336 = (1392-3*16)/4, gap 16, Card 2 at x=336+16=352');
    assert_contains($result, 'div (0,156 336x140) text="Card 5"', 'auto-fill: Card 5 wraps to row 2, y = 140+16 = 156');
    return $result;
};

// ── Test 3: auto-fill 带 gap 的列宽计算 ──
$tests['grid auto-fill 列宽和数量验证'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));width:672px;gap:16px'], [
            VNode::h('div', ['style' => 'height:50px'], 'Item 1'),
            VNode::h('div', ['style' => 'height:50px'], 'Item 2'),
        ])
    );
    assert_contains($result, 'div (344,0 328x60) text="Item 2"', 'auto-fill: each col = (672-16)/2 = 328, gap 16');
    return $result;
};

// ── Test 4: Grid 子项的 width:100% —— 验证 bug #1 ──
$tests['grid cell 内 width=100% 应为 cell 宽而非容器宽'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:300px 300px;width:632px;gap:16px'], [
            VNode::h('div', ['style' => 'width:100%;height:100px;display:flex'], 
                VNode::h('div', ['style' => 'width:100%;height:50px'], 'inner')
            ),
            VNode::h('div', ['style' => 'width:100%;height:100px'], 'Card 2'),
        ])
    );
    assert_contains($result, 'div (0,0 300x50) text="inner"', 'grid cell width=300, 100% child matches cell width');
    return $result;
};

// ── Test 5: 显式固定列宽 (px) ──
$tests['grid 显式列宽 100px 200px 100px'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:100px 200px 100px;width:400px;height:80px'], [
            VNode::h('div', ['style' => 'height:60px'], 'A'),
            VNode::h('div', ['style' => 'height:60px'], 'B'),
            VNode::h('div', ['style' => 'height:60px'], 'C'),
        ])
    );
    assert_contains($result, 'div (100,0 200x60) text="B"', 'explicit px: column widths 100+200+100=400');
    return $result;
};

// ── Test 6: 混合 fr + px 列 ──
$tests['grid 混合单位 1fr 200px 1fr'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 200px 1fr;width:800px;height:80px;gap:8px'], [
            VNode::h('div', ['style' => 'height:60px'], 'Left'),
            VNode::h('div', ['style' => 'height:60px'], 'Center'),
            VNode::h('div', ['style' => 'height:60px'], 'Right'),
        ])
    );
    assert_contains($result, 'div (508,0 292x60) text="Right"', 'fr col = (800-200-2*8)/2 = 292, Right.x = 292+8+200+8 = 508');
    return $result;
};

// ── Test 7: grid 内 padding 影响子项 ──
$tests['grid 容器 padding 影响子项位置'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:400px;height:100px;padding:10px;gap:8px'], [
            VNode::h('div', ['style' => 'height:60px'], 'Left'),
            VNode::h('div', ['style' => 'height:60px'], 'Right'),
        ])
    );
    assert_contains($result, 'div (204,0 196x60) text="Right"', 'grid with padding: each col = (400-8)/2 = 196');
    return $result;
};

// ── Test 8: 不同 gap 值 ──
$tests['grid gap 32px 大间距'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:432px;height:80px;gap:32px'], [
            VNode::h('div', ['style' => 'height:50px'], 'A'),
            VNode::h('div', ['style' => 'height:50px'], 'B'),
        ])
    );
    assert_contains($result, 'div (232,0 200x60) text="B"', 'gap 32: each col = (432-32)/2 = 200, B.x = 0+200+32 = 232');
    return $result;
};

// ── Test 9: grid-template-rows 固定行高 ──
$tests['grid-template-rows 固定行高'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;grid-template-rows:100px 60px;width:400px;height:200px;gap:8px'], [
            VNode::h('div', ['style' => ''], 'Tall'),
            VNode::h('div', ['style' => ''], 'Tall2'),
            VNode::h('div', ['style' => ''], 'Short'),
            VNode::h('div', ['style' => ''], 'Short2'),
        ])
    );
    assert_contains($result, 'div (0,68 196x60) text="Short"', 'grid-template-rows: Short in row 2, y = row1_height(100) - child_h + gap(8)...');
    return $result;
};

// ── Test 10: grid auto-flow implicit rows ──
$tests['grid auto-flow 隐式行高'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr 1fr;width:600px;gap:8px;grid-auto-rows:80px'], [
            VNode::h('div', ['style' => ''], 'A'),
            VNode::h('div', ['style' => ''], 'B'),
            VNode::h('div', ['style' => ''], 'C'),
            VNode::h('div', ['style' => ''], 'D'),
            VNode::h('div', ['style' => ''], 'E'),
        ])
    );
    assert_contains($result, 'div (0,68 194x60) text="D"', 'auto-flow: D in row 2, y = implicit_row + gap');
    return $result;
};

// ── Test 11: grid 内 flex 混合 ──
$tests['grid item 内部 flex column'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:400px;height:auto;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;height:auto'], [
                VNode::h('div', ['style' => 'height:40px'], 'Top'),
                VNode::h('div', ['style' => 'height:30px'], 'Bottom'),
            ]),
            VNode::h('div', ['style' => 'height:80px'], 'Side'),
        ])
    );
    assert_contains($result, 'div (0,0 196x40) text="Top"', 'grid+flex: cell 196 = (400-8)/2, flex column layout inside grid');
    return $result;
};

// ── Test 12: grid 内百分比宽度子项 ──
$tests['grid cell 内 width=50%'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:300px 300px;width:632px;gap:16px'], [
            VNode::h('div', ['style' => 'width:50%;height:50px;background:red'], 'Half'),
            VNode::h('div', ['style' => 'width:100%;height:50px'], 'Full'),
        ])
    );
    assert_contains($result, 'div (316,0 300x60) text="Full"', 'grid: column 2 at x = 300+16(gap) = 316');
    return $result;
};

// ── Test 13: grid column-gap vs row-gap 分离设置 ──
$tests['column-gap row-gap 分别设置'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr 1fr;width:500px;gap:16px 8px;grid-auto-rows:60px'], [
            VNode::h('div', [], 'A'),
            VNode::h('div', [], 'B'),
            VNode::h('div', [], 'C'),
            VNode::h('div', [], 'D'),
        ])
    );
    assert_contains($result, 'div (222,0 54x60) text="B"', 'column-gap 16: B.x = 0+54+16 = ...');
    assert_contains($result, 'div (0,228 54x60) text="D"', 'row-gap 8: D in row 2, column 1');
    return $result;
};

// ── Test 14: 单列 grid (1fr) ──
$tests['grid 单列 1fr'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr;width:600px;height:auto;gap:8px'], [
            VNode::h('div', ['style' => 'height:40px'], 'Row 1'),
            VNode::h('div', ['style' => 'height:40px'], 'Row 2'),
            VNode::h('div', ['style' => 'height:40px'], 'Row 3'),
        ])
    );
    assert_contains($result, 'div (0,0 600x60) text="Row 1"', '1fr single col: full container width = 600');
    assert_contains($result, 'div (0,136 600x60) text="Row 3"', 'single col: row 3 at y = 2*(60+8) = 136');
    return $result;
};

// ── Test 15: grid 内 min-content / max-content 子项 ──
$tests['grid 子项文本自动宽度'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:auto auto auto;width:600px;height:auto;gap:8px'], [
            VNode::h('div', ['style' => ''], 'Short'),
            VNode::h('div', ['style' => ''], 'Medium text here'),
            VNode::h('div', ['style' => ''], 'Longer text content here'),
        ])
    );
    assert_contains($result, 'div (0,0 0x60) text="Short"', 'auto col: Short at origin');
    assert_contains($result, 'div (16,0 0x60) text="Longer text content here"', 'auto col: third item at x with 2*gap = 16');
    return $result;
};

// ── Test 16: 百分比列宽 50% 50% ──
$tests['grid 百分比列宽 50% 50%'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:50% 50%;width:600px;height:80px;gap:8px'], [
            VNode::h('div', ['style' => 'height:60px'], 'Left'),
            VNode::h('div', ['style' => 'height:60px'], 'Right'),
        ])
    );
    assert_contains($result, 'div (308,0 300x60) text="Right"', '50% of 600 = 300, Right.x = 0+300+8 = 308');
    return $result;
};

// ── Test 17: Grid cell 内 flex column 子项 stretch 填充高度 ──
$tests['grid cell 内 flex column 高度填充'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:600px;height:200px;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column'], [
                VNode::h('div', ['style' => 'height:30px;background:red'], 'Header'),
                VNode::h('div', ['style' => 'flex:1;background:green'], 'Fill'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;justify-content:center;align-items:center'], [
                VNode::h('div', ['style' => ''], 'Centered'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,30 296x30) fg=1 text="Fill"', 'grid+flex: fill remaining 60-30 = 30');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-03-Grid.snap';
run_css_tests('Level 3 - Grid 基础', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
