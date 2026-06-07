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
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr 1fr 1fr;width:800px;height:100px;gap:8px'], [
            VNode::h('div', ['style' => 'height:80px;background:#FF0000'], 'A'),
            VNode::h('div', ['style' => 'height:80px;background:#00FF00'], 'B'),
            VNode::h('div', ['style' => 'height:80px;background:#0000FF'], 'C'),
            VNode::h('div', ['style' => 'height:80px;background:#FFFF00'], 'D'),
        ])
    );
};

// ── Test 2: auto-fill 自适应列数 ──
$tests['grid auto-fill minmax(300px,1fr)'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));width:1392px;gap:16px'], [
            VNode::h('div', ['style' => 'height:140px;width:100%'], 'Card 1'),
            VNode::h('div', ['style' => 'height:140px;width:100%'], 'Card 2'),
            VNode::h('div', ['style' => 'height:140px;width:100%'], 'Card 3'),
            VNode::h('div', ['style' => 'height:140px;width:100%'], 'Card 4'),
            VNode::h('div', ['style' => 'height:140px;width:100%'], 'Card 5'),
        ])
    );
};

// ── Test 3: auto-fill 带 gap 的列宽计算 ──
$tests['grid auto-fill 列宽和数量验证'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));width:672px;gap:16px'], [
            VNode::h('div', ['style' => 'height:50px'], 'Item 1'),
            VNode::h('div', ['style' => 'height:50px'], 'Item 2'),
        ])
    );
};

// ── Test 4: Grid 子项的 width:100% —— 验证 bug #1 ──
$tests['grid cell 内 width=100% 应为 cell 宽而非容器宽'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:300px 300px;width:632px;gap:16px'], [
            VNode::h('div', ['style' => 'width:100%;height:100px;display:flex'], 
                VNode::h('div', ['style' => 'width:100%;height:50px'], 'inner')
            ),
            VNode::h('div', ['style' => 'width:100%;height:100px'], 'Card 2'),
        ])
    );
};

// ── Test 5: 显式固定列宽 (px) ──
$tests['grid 显式列宽 100px 200px 100px'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:100px 200px 100px;width:400px;height:80px'], [
            VNode::h('div', ['style' => 'height:60px'], 'A'),
            VNode::h('div', ['style' => 'height:60px'], 'B'),
            VNode::h('div', ['style' => 'height:60px'], 'C'),
        ])
    );
};

// ── Test 6: 混合 fr + px 列 ──
$tests['grid 混合单位 1fr 200px 1fr'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 200px 1fr;width:800px;height:80px;gap:8px'], [
            VNode::h('div', ['style' => 'height:60px'], 'Left'),
            VNode::h('div', ['style' => 'height:60px'], 'Center'),
            VNode::h('div', ['style' => 'height:60px'], 'Right'),
        ])
    );
};

// ── Test 7: grid 内 padding 影响子项 ──
$tests['grid 容器 padding 影响子项位置'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:400px;height:100px;padding:10px;gap:8px'], [
            VNode::h('div', ['style' => 'height:60px'], 'Left'),
            VNode::h('div', ['style' => 'height:60px'], 'Right'),
        ])
    );
};

// ── Test 8: 不同 gap 值 ──
$tests['grid gap 32px 大间距'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:432px;height:80px;gap:32px'], [
            VNode::h('div', ['style' => 'height:50px'], 'A'),
            VNode::h('div', ['style' => 'height:50px'], 'B'),
        ])
    );
};

// ── Test 9: grid-template-rows 固定行高 ──
$tests['grid-template-rows 固定行高'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;grid-template-rows:100px 60px;width:400px;height:200px;gap:8px'], [
            VNode::h('div', ['style' => ''], 'Tall'),
            VNode::h('div', ['style' => ''], 'Tall2'),
            VNode::h('div', ['style' => ''], 'Short'),
            VNode::h('div', ['style' => ''], 'Short2'),
        ])
    );
};

// ── Test 10: grid auto-flow implicit rows ──
$tests['grid auto-flow 隐式行高'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr 1fr;width:600px;gap:8px;grid-auto-rows:80px'], [
            VNode::h('div', ['style' => ''], 'A'),
            VNode::h('div', ['style' => ''], 'B'),
            VNode::h('div', ['style' => ''], 'C'),
            VNode::h('div', ['style' => ''], 'D'),
            VNode::h('div', ['style' => ''], 'E'),
        ])
    );
};

// ── Test 11: grid 内 flex 混合 ──
$tests['grid item 内部 flex column'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:400px;height:auto;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;height:auto'], [
                VNode::h('div', ['style' => 'height:40px'], 'Top'),
                VNode::h('div', ['style' => 'height:30px'], 'Bottom'),
            ]),
            VNode::h('div', ['style' => 'height:80px'], 'Side'),
        ])
    );
};

// ── Test 12: grid 内百分比宽度子项 ──
$tests['grid cell 内 width=50%'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:300px 300px;width:632px;gap:16px'], [
            VNode::h('div', ['style' => 'width:50%;height:50px;background:red'], 'Half'),
            VNode::h('div', ['style' => 'width:100%;height:50px'], 'Full'),
        ])
    );
};

// ── Test 13: grid column-gap vs row-gap 分离设置 ──
$tests['column-gap row-gap 分别设置'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr 1fr;width:500px;gap:16px 8px;grid-auto-rows:60px'], [
            VNode::h('div', [], 'A'),
            VNode::h('div', [], 'B'),
            VNode::h('div', [], 'C'),
            VNode::h('div', [], 'D'),
        ])
    );
};

// ── Test 14: 单列 grid (1fr) ──
$tests['grid 单列 1fr'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr;width:600px;height:auto;gap:8px'], [
            VNode::h('div', ['style' => 'height:40px'], 'Row 1'),
            VNode::h('div', ['style' => 'height:40px'], 'Row 2'),
            VNode::h('div', ['style' => 'height:40px'], 'Row 3'),
        ])
    );
};

// ── Test 15: grid 内 min-content / max-content 子项 ──
$tests['grid 子项文本自动宽度'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:auto auto auto;width:600px;height:auto;gap:8px'], [
            VNode::h('div', ['style' => ''], 'Short'),
            VNode::h('div', ['style' => ''], 'Medium text here'),
            VNode::h('div', ['style' => ''], 'Longer text content here'),
        ])
    );
};

// ── Test 16: 百分比列宽 50% 50% ──
$tests['grid 百分比列宽 50% 50%'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:50% 50%;width:600px;height:80px;gap:8px'], [
            VNode::h('div', ['style' => 'height:60px'], 'Left'),
            VNode::h('div', ['style' => 'height:60px'], 'Right'),
        ])
    );
};

// ── Test 17: Grid cell 内 flex column 子项 stretch 填充高度 ──
$tests['grid cell 内 flex column 高度填充'] = function() {
    return run_minimal_pipeline(
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
};

$snapFile = __DIR__ . '/../__snapshots__/Level-03-Grid.snap';
run_css_tests('Level 3 - Grid 基础', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
