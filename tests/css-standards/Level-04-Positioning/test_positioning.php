<?php
/**
 * Level 4: 复合定位 (position + z-index)
 *
 * 测试目标：
 *   1. position:relative + left/top 偏移（不影响其他元素布局）
 *   2. position:absolute + left/top 绝对定位（脱离文档流）
 *   3. z-index 层级顺序
 *   4. Padding/border 对盒模型尺寸的影响
 *   5. position:relative 容器内 absolute 子元素
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: position:relative + left/top ──
$tests['position:relative left=10 top=5'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:10px;top:5px;width:100px;height:50px'], 'Relative')
    );
};

// ── Test 2: position:absolute ──
$tests['position:absolute left=10 top=20'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:absolute;left:10px;top:20px;width:100px;height:50px'], 'Absolute')
    );
};

// ── Test 3: relative 容器 + absolute 子元素 ──
$tests['relative 容器内 absolute 子元素'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:0;top:0;width:400px;height:300px'], [
            VNode::h('div', ['style' => 'position:absolute;left:50px;top:30px;width:100px;height:80px'], 'Child'),
        ])
    );
};

// ── Test 4: 两个 div 自动堆叠（block 流） ──
$tests['Block 流堆叠 + padding'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto;padding:10px'], [
            VNode::h('div', ['style' => 'width:auto;height:30px'], 'Line 1'),
            VNode::h('div', ['style' => 'width:auto;height:30px;margin-top:8px'], 'Line 2'),
        ])
    );
};

// ── Test 5: border 对布局的影响 ──
$tests['border 对布局的影响'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'border:2px solid #000;width:200px;height:100px'], 'Bordered')
    );
};

// ── Test 6: z-index 层级 ──
$tests['z-index 层级顺序'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:300px;height:200px'], [
            VNode::h('div', ['style' => 'position:absolute;left:0;top:0;width:200px;height:100px;z-index:2;background:red'], 'Top'),
            VNode::h('div', ['style' => 'position:absolute;left:20;top:20;width:200px;height:100px;z-index:1;background:blue'], 'Bottom'),
        ])
    );
};

// ── Test 7: position:absolute 使用 right/bottom 定位 ──
$tests['absolute right=20 bottom=10 从右下角定位'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:0;top:0;width:400px;height:300px'], [
            VNode::h('div', ['style' => 'position:absolute;right:20px;bottom:10px;width:100px;height:50px'], 'BR'),
        ])
    );
};

// ── Test 8: position:fixed 相对于视口定位 ──
$tests['position:fixed 相对于视口定位'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:fixed;left:100px;top:200px;width:120px;height:60px'], 'Fixed')
    );
};

// ── Test 9: absolute 居中 (left+right=0 + margin auto) ──
$tests['absolute 居中 left=0 right=0 margin=auto'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:0;top:0;width:400px;height:200px'], [
            VNode::h('div', ['style' => 'position:absolute;left:0;right:0;top:50px;width:200px;height:60px;margin:auto'], 'Center'),
        ])
    );
};

// ── Test 10: 多个 absolute 元素叠加（z-index 顺序） ──
$tests['多 absolute 叠加 z-index 控制层级'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:0;top:0;width:400px;height:300px'], [
            VNode::h('div', ['style' => 'position:absolute;left:10px;top:10px;width:200px;height:100px;z-index:2'], 'Top'),
            VNode::h('div', ['style' => 'position:absolute;left:30px;top:30px;width:200px;height:100px;z-index:1'], 'Bottom'),
            VNode::h('div', ['style' => 'position:absolute;left:50px;top:50px;width:200px;height:100px;z-index:3'], 'Front'),
        ])
    );
};

// ── Test 11: position:relative 负偏移，不影响其他元素 ──
$tests['relative 负偏移 left=-10 top=-5 不影响流'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:auto'], [
            VNode::h('div', ['style' => 'height:40px'], 'Normal 1'),
            VNode::h('div', ['style' => 'position:relative;left:-10px;top:-5px;height:40px'], 'Relative'),
            VNode::h('div', ['style' => 'height:40px'], 'Normal 2'),
        ])
    );
};

// ── Test 12: relative 容器嵌套 absolute 深度层级 ──
$tests['多级 relative 嵌套 absolute 深度'] = function() {
    return run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:0;top:0;width:500px;height:400px'], [
            VNode::h('div', ['style' => 'position:relative;left:20px;top:20px;width:400px;height:300px'], [
                VNode::h('div', ['style' => 'position:absolute;left:10px;top:10px;width:100px;height:80px'], 'Deep'),
            ]),
        ])
    );
};

$snapFile = __DIR__ . '/../__snapshots__/Level-04-Positioning.snap';
run_css_tests('Level 4 - 复合定位', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
