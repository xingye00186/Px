<?php
/**
 * Level 2: Flexbox 布局
 *
 * 测试目标：
 *   1. flex-direction:row 水平排列
 *   2. flex-direction:column 垂直排列
 *   3. flex:1 自动填充剩余空间
 *   4. gap 间距
 *   5. justify-content:center/space-between
 *   6. align-items:center
 *   7. 嵌套 flex（行内列）
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: 简单 flex row ──
$tests['flex row 三个子项水平排列'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:60px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'B'),
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'C'),
        ])
    );
};

// ── Test 2: flex column ──
$tests['flex column 三个子项垂直排列'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:200px;height:200px'], [
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'B'),
        ])
    );
};

// ── Test 3: flex:1 自动填充 ──
$tests['flex:1 两个子项平分空间'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:300px;height:50px'], [
            VNode::h('div', ['style' => 'flex:1;height:50px'], 'Left'),
            VNode::h('div', ['style' => 'flex:1;height:50px'], 'Right'),
        ])
    );
};

// ── Test 4: gap 间距 ──
$tests['flex row with gap=16px'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:16px;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:100px;height:50px'], 'A'),
            VNode::h('div', ['style' => 'width:100px;height:50px'], 'B'),
        ])
    );
};

// ── Test 5: justify-content:center ──
$tests['flex row justify-content=center'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;justify-content:center;width:400px;height:60px'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'Center'),
        ])
    );
};

// ── Test 6: 嵌套 flex（row 内的 column） ──
$tests['嵌套 flex (row > column)'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:100px;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;flex:1;height:100px'], [
                VNode::h('div', ['style' => 'height:40px'], 'Top'),
                VNode::h('div', ['style' => 'height:40px'], 'Bottom'),
            ]),
            VNode::h('div', ['style' => 'width:100px;height:100px'], 'Side'),
        ])
    );
};

// ── Test 7: flex-wrap 折行 ──
$tests['flex-wrap 子项超出折行'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;flex-wrap:wrap;width:200px;height:auto;gap:4px'], [
            VNode::h('div', ['style' => 'width:100px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:100px;height:30px'], 'B'),
            VNode::h('div', ['style' => 'width:100px;height:30px'], 'C'),
        ])
    );
};

// ── Test 8: align-items:center ──
$tests['align-items center 交叉轴居中'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:center;width:300px;height:80px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:60px;height:40px'], 'B'),
        ])
    );
};

// ── Test 9: align-items:stretch ──
$tests['align-items stretch 交叉轴拉伸'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;align-items:stretch;width:300px;height:80px'], [
            VNode::h('div', ['style' => 'width:60px'], 'A'),
            VNode::h('div', ['style' => 'width:60px'], 'B'),
        ])
    );
};

// ── Test 10: flex-shrink ──
$tests['flex-shrink 子项超出时收缩'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:200px;height:50px'], [
            VNode::h('div', ['style' => 'width:150px;height:50px;flex-shrink:1'], 'Wide'),
            VNode::h('div', ['style' => 'width:100px;height:50px;flex-shrink:1'], 'Narrow'),
        ])
    );
};

// ── Test 11: order 重排 ──
$tests['order 属性改变视觉顺序'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:100px;height:50px;order:2'], 'Third'),
            VNode::h('div', ['style' => 'width:100px;height:50px;order:1'], 'Second'),
            VNode::h('div', ['style' => 'width:100px;height:50px;order:0'], 'First'),
        ])
    );
};

// ── Test 12: justify-content space-between ──
$tests['justify-content space-between 两端对齐'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;justify-content:space-between;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'L'),
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'R'),
        ])
    );
};

// ── Test 13: justify-content space-around ──
$tests['justify-content space-around 均匀分布'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;justify-content:space-around;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'B'),
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'C'),
        ])
    );
};

// ── Test 14: flex-direction row-reverse ──
$tests['flex-direction row-reverse 反向行'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row-reverse;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:80px;height:30px'], 'A'),
            VNode::h('div', ['style' => 'width:80px;height:30px'], 'B'),
        ])
    );
};

// ── Test 15: flex-direction column-reverse ──
$tests['flex-direction column-reverse 反向列'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column-reverse;width:200px;height:200px'], [
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'A'),
            VNode::h('div', ['style' => 'width:100%;height:40px'], 'B'),
        ])
    );
};

// ── Test 16: flex item 固定宽度 ──
$tests['flex item 显式固定宽度'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:100px;height:30px;flex-shrink:0'], 'Fixed'),
            VNode::h('div', ['style' => 'flex:1;height:30px'], 'Flex'),
        ])
    );
};

// ── Test 17: flex 内 auto margin ──
$tests['auto margin-left 在 flex 中右推'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:50px'], [
            VNode::h('div', ['style' => 'width:60px;height:30px'], 'Left'),
            VNode::h('div', ['style' => 'width:60px;height:30px;margin-left:auto'], 'Right'),
        ])
    );
};

// ── Test 18: flex 内 padding 影响子项 ──
$tests['flex 容器 padding 影响子项位置'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:100px;padding:10px'], [
            VNode::h('div', ['style' => 'width:80px;height:50px'], 'Item'),
        ])
    );
};

$snapFile = __DIR__ . '/../__snapshots__/Level-02-Flexbox.snap';
run_css_tests('Level 2 - Flexbox 基础', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
