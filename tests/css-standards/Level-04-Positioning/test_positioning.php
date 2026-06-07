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
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:10px;top:5px;width:100px;height:50px'], 'Relative')
    );
    assert_contains($result, 'div (10,5 100x50) [pos=relative] text="Relative"', 'relative offsets: left=10, top=5 from normal position');
    return $result;
};

// ── Test 2: position:absolute ──
$tests['position:absolute left=10 top=20'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:absolute;left:10px;top:20px;width:100px;height:50px'], 'Absolute')
    );
    assert_contains($result, 'div (10,20 100x50) [pos=absolute] text="Absolute"', 'absolute at (10,20) relative to containing block');
    return $result;
};

// ── Test 3: relative 容器 + absolute 子元素 ──
$tests['relative 容器内 absolute 子元素'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:0;top:0;width:400px;height:300px'], [
            VNode::h('div', ['style' => 'position:absolute;left:50px;top:30px;width:100px;height:80px'], 'Child'),
        ])
    );
    assert_contains($result, 'div (50,30 100x80) [pos=absolute] text="Child"', 'absolute child at (50,30) inside relative parent');
    return $result;
};

// ── Test 4: 两个 div 自动堆叠（block 流） ──
$tests['Block 流堆叠 + padding'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:auto;padding:10px'], [
            VNode::h('div', ['style' => 'width:auto;height:30px'], 'Line 1'),
            VNode::h('div', ['style' => 'width:auto;height:30px;margin-top:8px'], 'Line 2'),
        ])
    );
    assert_contains($result, 'div (10,10 280x30) text="Line 1"', 'padding 10 offsets block flow children to (10,10)');
    assert_contains($result, 'div (10,48 280x30) text="Line 2"', 'block flow: Line 2.y = 10+30+8 = 48');
    return $result;
};

// ── Test 5: border 对布局的影响 ──
$tests['border 对布局的影响'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'border:2px solid #000;width:200px;height:100px'], 'Bordered')
    );
    assert_contains($result, 'div (0,0 200x100) bw=2 text="Bordered"', 'border-width 2px rendered as bw=2');
    return $result;
};

// ── Test 6: z-index 层级 ──
$tests['z-index 层级顺序'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:300px;height:200px'], [
            VNode::h('div', ['style' => 'position:absolute;left:0;top:0;width:200px;height:100px;z-index:2;background:red'], 'Top'),
            VNode::h('div', ['style' => 'position:absolute;left:20;top:20;width:200px;height:100px;z-index:1;background:blue'], 'Bottom'),
        ])
    );
    assert_contains($result, 'div (0,0 200x100) [pos=absolute] text="Top"', 'z-index:2 renders first, absolute positioned');
    assert_contains($result, 'div (20,20 200x100) [pos=absolute] text="Bottom"', 'z-index:1 with offset (20,20)');
    return $result;
};

// ── Test 7: position:absolute 使用 right/bottom 定位 ──
$tests['absolute right=20 bottom=10 从右下角定位'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:0;top:0;width:400px;height:300px'], [
            VNode::h('div', ['style' => 'position:absolute;right:20px;bottom:10px;width:100px;height:50px'], 'BR'),
        ])
    );
    assert_contains($result, 'div (280,240 100x50) [pos=absolute] text="BR"', 'right=20,bottom=10: x=400-100-20=280, y=300-50-10=240');
    return $result;
};

// ── Test 8: position:fixed 相对于视口定位 ──
$tests['position:fixed 相对于视口定位'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:fixed;left:100px;top:200px;width:120px;height:60px'], 'Fixed')
    );
    assert_contains($result, 'div (100,200 120x60) [pos=fixed] text="Fixed"', 'fixed at (100,200) relative to viewport');
    return $result;
};

// ── Test 9: absolute 居中 (left+right=0 + margin auto) ──
$tests['absolute 居中 left=0 right=0 margin=auto'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:0;top:0;width:400px;height:200px'], [
            VNode::h('div', ['style' => 'position:absolute;left:0;right:0;top:50px;width:200px;height:60px;margin:auto'], 'Center'),
        ])
    );
    assert_contains($result, 'div (300,120 200x60) [pos=absolute] text="Center"', 'absolute centered with left=0 right=0 margin=auto');
    return $result;
};

// ── Test 10: 多个 absolute 元素叠加（z-index 顺序） ──
$tests['多 absolute 叠加 z-index 控制层级'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:0;top:0;width:400px;height:300px'], [
            VNode::h('div', ['style' => 'position:absolute;left:10px;top:10px;width:200px;height:100px;z-index:2'], 'Top'),
            VNode::h('div', ['style' => 'position:absolute;left:30px;top:30px;width:200px;height:100px;z-index:1'], 'Bottom'),
            VNode::h('div', ['style' => 'position:absolute;left:50px;top:50px;width:200px;height:100px;z-index:3'], 'Front'),
        ])
    );
    assert_contains($result, 'div (50,50 200x100) [pos=absolute] text="Front"', 'multiple absolute with z-index: Front at (50,50)');
    return $result;
};

// ── Test 11: position:relative 负偏移，不影响其他元素 ──
$tests['relative 负偏移 left=-10 top=-5 不影响流'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:auto'], [
            VNode::h('div', ['style' => 'height:40px'], 'Normal 1'),
            VNode::h('div', ['style' => 'position:relative;left:-10px;top:-5px;height:40px'], 'Relative'),
            VNode::h('div', ['style' => 'height:40px'], 'Normal 2'),
        ])
    );
    assert_contains($result, 'div (-10,35 400x40) [pos=relative] text="Relative"', 'relative neg offset: left=-10,top=-5 from normal flow (0,40)');
    assert_contains($result, 'div (0,80 400x40) text="Normal 2"', 'relative offset does NOT affect subsequent elements flow: Normal 2 at y=80');
    return $result;
};

// ── Test 12: relative 容器嵌套 absolute 深度层级 ──
$tests['多级 relative 嵌套 absolute 深度'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;left:0;top:0;width:500px;height:400px'], [
            VNode::h('div', ['style' => 'position:relative;left:20px;top:20px;width:400px;height:300px'], [
                VNode::h('div', ['style' => 'position:absolute;left:10px;top:10px;width:100px;height:80px'], 'Deep'),
            ]),
        ])
    );
    assert_contains($result, 'div (30,30 100x80) [pos=absolute] text="Deep"', 'nested relative+absolute: Deep at (20+10, 20+10) = (30,30)');
    return $result;
};

// ── Test 13: overflow:hidden + border-radius 子元素裁切 ──
$tests['overflow:hidden + border-radius 裁切子元素'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow:hidden;border-radius:8px;left:0;top:0;width:200px;height:100px'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:400px;height:200px'], 'Overflowing content'),
        ])
    );
    assert_contains($result, 'div (0,0 200x100) ov=hidden', 'overflow hidden and border-radius creates clip context');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-04-Positioning.snap';
run_css_tests('Level 4 - 复合定位', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
