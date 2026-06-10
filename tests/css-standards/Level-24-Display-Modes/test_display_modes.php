<?php
/**
 * Level 24: Display Modes Advanced / 高级显示模式
 *
 * 测试目标：
 *   1. display:table 基本表格容器
 *   2. display:table-row 行 + table-cell 单元格
 *   3. display:table 含多行多列的自动布局
 *   4. display:table-caption 标题
 *   5. column-count 多列等分
 *   6. column-width 弹性列数
 *   7. column-gap 列间距
 *   8. display:inline 多个行内元素并排
 *   9. display:inline-block 并行布局（多行）
 *   10. display:inline 自动换行
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: display:table 基本容器 ──
$tests['display:table 基本容器'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:table;width:400px;height:auto'], [
            VNode::h('div', ['style' => 'display:table-row'], [
                VNode::h('div', ['style' => 'display:table-cell;padding:10px'], 'Cell1'),
                VNode::h('div', ['style' => 'display:table-cell;padding:10px'], 'Cell2'),
            ]),
        ])
    );
    assert_contains($result, 'dsp=table', 'display:table container');
    assert_contains($result, 'dsp=table-row', 'display:table-row');
    assert_contains($result, 'dsp=table-cell', 'display:table-cell present');
    return $result;
};

// ── Test 2: display:table 含多行多列 ──
$tests['display:table 2x2 网格布局'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:table;width:300px;height:auto'], [
            VNode::h('div', ['style' => 'display:table-row'], [
                VNode::h('div', ['style' => 'display:table-cell'], 'R1C1'),
                VNode::h('div', ['style' => 'display:table-cell'], 'R1C2'),
            ]),
            VNode::h('div', ['style' => 'display:table-row'], [
                VNode::h('div', ['style' => 'display:table-cell'], 'R2C1'),
                VNode::h('div', ['style' => 'display:table-cell'], 'R2C2'),
            ]),
        ])
    );
    assert_contains($result, 'dsp=table', 'table 2x2 container');
    assert_contains($result, 'text="R1C1"', 'first cell present');
    assert_contains($result, 'text="R2C2"', 'last cell present');
    return $result;
};

// ── Test 3: display:table-cell 内的嵌套内容 ──
$tests['table-cell 嵌套 block 内容'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:table;width:400px;height:auto'], [
            VNode::h('div', ['style' => 'display:table-row'], [
                VNode::h('div', ['style' => 'display:table-cell;padding:5px'], [
                    VNode::h('div', ['style' => 'width:100%;height:30px'], 'Nested'),
                ]),
            ]),
        ])
    );
    assert_contains($result, 'dsp=table-cell', 'table-cell with nested block');
    return $result;
};

// ── Test 4: display:table-caption 标题 ──
$tests['display:table-caption 标题'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:table;width:300px;height:auto'], [
            VNode::h('div', ['style' => 'display:table-caption'], 'Caption'),
            VNode::h('div', ['style' => 'display:table-row'], [
                VNode::h('div', ['style' => 'display:table-cell'], 'Data'),
            ]),
        ])
    );
    assert_contains($result, 'dsp=table-caption', 'table-caption present');
    assert_contains($result, 'dsp=table', 'table container');
    return $result;
};

// ── Test 5: column-count 多列等分 ──
// column-count:3, width=300, gap=16 → colW=(300-2*16)/3=268/3=89 (整数)
// Child x positions: col0=0, col1=89+16=105, col2=89*2+32=210
$tests['column-count:3 三列等分'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'column-count:3;width:300px;height:auto'], [
            VNode::h('div', ['style' => 'height:40px'], 'Item 1'),
            VNode::h('div', ['style' => 'height:40px'], 'Item 2'),
            VNode::h('div', ['style' => 'height:40px'], 'Item 3'),
        ])
    );
    assert_contains($result, 'text="Item 1"', 'Item 1 present');
    assert_contains($result, 'text="Item 3"', 'Item 3 in column 3');
    return $result;
};

// ── Test 6: column-width 弹性列数 ──
// column-width=100, gap=16, width=350 → cols = floor((350+16)/(100+16)) = floor(3.15) = 3
// colW=100, children at x=0, x=116, x=232
$tests['column-width:100px 自适应列数'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'column-width:100px;width:350px;height:auto'], [
            VNode::h('div', ['style' => 'height:30px'], 'A'),
            VNode::h('div', ['style' => 'height:30px'], 'B'),
            VNode::h('div', ['style' => 'height:30px'], 'C'),
        ])
    );
    assert_contains($result, 'text="A"', 'Item A present');
    assert_contains($result, 'text="C"', 'Item C present');
    return $result;
};

// ── Test 7: column-gap 列间距 ──
// 230px container, 2 cols, gap=30px → colW=(230-30)/2=100
$tests['column-count:2 + column-gap:30px'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'column-count:2;column-gap:30px;width:230px;height:auto'], [
            VNode::h('div', ['style' => 'height:30px'], 'Left'),
            VNode::h('div', ['style' => 'height:30px'], 'Right'),
        ])
    );
    assert_contains($result, 'text="Left"', 'Left column present');
    assert_contains($result, 'text="Right"', 'Right column present');
    return $result;
};

// ── Test 8: display:inline 多个行内元素并排 ──
$tests['display:inline 三个行内元素并排'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:auto'], [
            VNode::h('span', ['style' => 'display:inline;font-size:14px'], 'AA '),
            VNode::h('span', ['style' => 'display:inline;font-size:14px'], 'BB '),
            VNode::h('span', ['style' => 'display:inline;font-size:14px'], 'CC'),
        ])
    );
    assert_contains($result, 'dsp=inline', 'inline elements');
    assert_contains($result, 'text="AA "', 'first inline text');
    assert_contains($result, 'text="CC"', 'last inline text');
    return $result;
};

// ── Test 9: display:inline-block 并行排列 ──
$tests['display:inline-block 三个等高元素'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:auto'], [
            VNode::h('div', ['style' => 'display:inline-block;width:100px;height:60px'], 'A'),
            VNode::h('div', ['style' => 'display:inline-block;width:100px;height:60px'], 'B'),
            VNode::h('div', ['style' => 'display:inline-block;width:100px;height:60px'], 'C'),
        ])
    );
    assert_contains($result, 'dsp=inline-block', 'inline-block elements');
    assert_contains($result, 'text="A"', 'inline-block A');
    assert_contains($result, 'text="C"', 'inline-block C');
    return $result;
};

// ── Test 10: display:inline 自动换行（溢出）──
$tests['display:inline 溢出换行'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:120px;height:auto'], [
            VNode::h('span', ['style' => 'display:inline;font-size:14px'], 'LongTextA'),
            VNode::h('span', ['style' => 'display:inline;font-size:14px'], ' LongTextB'),
        ])
    );
    // 120px 窄容器，行内文本应该换行
    assert_contains($result, 'dsp=inline', 'inline with wrapping');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-24-Display-Modes.snap';
run_css_tests('Level 24 - Display Modes Advanced', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
