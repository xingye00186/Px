<?php
/**
 * Level 5: 溢出与滚动 (overflow / scroll)
 *
 * 测试目标：
 *   1. overflow:hidden 子元素超出部分被剪裁
 *   2. overflow-y:auto 出现滚动容器
 *   3. 滚动容器内子元素定位（scrollTop 偏移）
 *   4. 多层嵌套滚动
 *   5. overflow-x:auto 水平滚动
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: overflow:hidden 剪裁 ──
$tests['overflow:hidden 子元素超出容器'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow:hidden;left:0;top:0;width:200px;height:100px'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:400px;height:50px'], 'Wide content'),
        ])
    );
    assert_contains($result, 'div (0,0 200x100) ov=hidden', 'overflow:hidden clips content to 200x100 box');
    return $result;
};

// ── Test 2: overflow-y:auto 垂直滚动 ──
$tests['overflow-y:auto 滚动容器'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow-y:auto;left:0;top:0;width:300px;height:150px;:scroll-top="0"'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:280px;height:50px'], 'Item 1'),
            VNode::h('div', ['style' => 'left:0;top:50;width:280px;height:50px'], 'Item 2'),
            VNode::h('div', ['style' => 'left:0;top:100;width:280px;height:50px'], 'Item 3'),
            VNode::h('div', ['style' => 'left:0;top:150;width:280px;height:50px'], 'Item 4'),
        ])
    );
    assert_contains($result, 'scroll ch=200 cw=300 maxScroll=50', 'overflow-y:auto: content height 200 > container 150, maxScroll=50');
    return $result;
};

// ── Test 3: overflow:auto 双轴滚动 ──
$tests['overflow:auto 双轴滚动'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow:auto;left:0;top:0;width:300px;height:200px;:scroll-top="0";:scroll-left="0"'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:500px;height:400px'], 'Large content'),
        ])
    );
    assert_contains($result, 'scroll ch=400 cw=500 maxScroll=200', 'overflow:auto: content 500x400 exceeds 300x200, dual axis');
    return $result;
};

// ── Test 4: 无滚动溢出（内容小于容器） ──
$tests['内容小于容器 无滚动'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow-y:auto;left:0;top:0;width:300px;height:200px;:scroll-top="0"'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:280px;height:50px'], 'Small'),
        ])
    );
    assert_contains($result, 'scroll ch=50 cw=300 maxScroll=0', 'overflow-y:auto: content 50 < container 200, no scroll needed');
    return $result;
};

// ── Test 5: overflow-y:scroll 始终显示滚动条 ──
$tests['overflow-y:scroll 始终显示滚动条'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow-y:scroll;left:0;top:0;width:300px;height:200px'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:280px;height:50px'], 'Not enough to scroll'),
        ])
    );
    assert_contains($result, 'scroll ch=50 cw=300 maxScroll=0', 'overflow-y:scroll always shows scrollbar even when content fits');
    return $result;
};

// ── Test 6: overflow-x:hidden + overflow-y:auto 混合轴 ──
$tests['overflow-x hidden + overflow-y auto 混合'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow-x:hidden;overflow-y:auto;left:0;top:0;width:300px;height:150px;:scroll-top="0"'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:400px;height:50px'], 'Wide'),
            VNode::h('div', ['style' => 'left:0;top:50;width:280px;height:50px'], 'Normal'),
            VNode::h('div', ['style' => 'left:0;top:100;width:280px;height:50px'], 'Normal2'),
        ])
    );
    assert_contains($result, 'scroll ch=150 cw=300 maxScroll=0 st=0 sl=0 ov=hidden', 'overflow-x hidden clips wide content, overflow-y auto');
    return $result;
};

// ── Test 7: 嵌套滚动容器 ──
$tests['嵌套滚动容器 (外内均滚动)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow-y:auto;left:0;top:0;width:400px;height:200px;:scroll-top="0"'], [
            VNode::h('div', ['style' => 'height:60px'], 'Header'),
            VNode::h('div', ['style' => 'overflow-y:auto;height:150px;:scroll-top="0"'], [
                VNode::h('div', ['style' => 'height:50px'], 'Inner 1'),
                VNode::h('div', ['style' => 'height:50px'], 'Inner 2'),
                VNode::h('div', ['style' => 'height:50px'], 'Inner 3'),
                VNode::h('div', ['style' => 'height:50px'], 'Inner 4'),
            ]),
            VNode::h('div', ['style' => 'height:60px'], 'Footer'),
        ])
    );
    assert_contains($result, 'div (0,60 400x150) scroll ch=200 cw=400 maxScroll=50', 'nested scroll: inner scroll container');
    return $result;
};

// ── Test 8: 溢出 visible 默认行为 ──
$tests['overflow visible 默认超出可见'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow:visible;left:0;top:0;width:200px;height:60px'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:400px;height:50px'], 'Overflowing content visible'),
        ])
    );
    assert_contains($result, 'div (0,0 400x50) text="Overflowing content visible"', 'overflow:visible: child content wider than parent still renders in full');
    return $result;
};

// ── Test 9: 滚动容器带 padding ──
$tests['scroll 容器 padding 影响子项'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow-y:auto;left:0;top:0;width:300px;height:150px;padding:10px;:scroll-top="0"'], [
            VNode::h('div', ['style' => 'height:40px'], 'Item 1'),
            VNode::h('div', ['style' => 'height:40px;margin-top:8px'], 'Item 2'),
            VNode::h('div', ['style' => 'height:40px;margin-top:8px'], 'Item 3'),
        ])
    );
    assert_contains($result, 'div (10,10 280x40) text="Item 1"', 'scroll padding: first child at (10,10), width 280 = 300 - 2*10 padding (content-box)');
    return $result;
};

// ── Test 10: overflow-x:auto 水平滚动 ──
$tests['overflow-x auto 水平滚动'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow-x:auto;left:0;top:0;width:300px;height:100px;:scroll-left="0"'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:600px;height:80px'], 'Wide content that requires horizontal scrolling'),
        ])
    );
    assert_contains($result, 'scroll ch=80 cw=600', 'overflow-x:auto: content width 600 > container 300, horizontal scroll');
    return $result;
};

// ── Test 11: 滚动容器内新元素滚动位置计算 ──
$tests['滚动容器中元素定位'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow-y:auto;left:0;top:0;width:300px;height:150px;:scroll-top="0"'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:280px;height:50px'], 'Start'),
            VNode::h('div', ['style' => 'left:0;top:60;width:280px;height:50px'], 'Middle'),
            VNode::h('div', ['style' => 'left:0;top:120;width:280px;height:50px'], 'End'),
        ])
    );
    assert_contains($result, 'div (0,0 280x50) text="Start"', 'scroll container: items positioned normally within scroll area');
    return $result;
};

// ── Test 12: overflow:auto 内容小于容器高度不显示滚动条 ──
$tests['overflow auto 内容较少无滚动'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'overflow:auto;left:0;top:0;width:400px;height:300px;:scroll-top="0";:scroll-left="0"'], [
            VNode::h('div', ['style' => 'left:0;top:0;width:380px;height:50px'], 'Small content'),
        ])
    );
    assert_contains($result, 'scroll ch=50 cw=400 maxScroll=0', 'overflow:auto: content 50 < container 300, maxScroll=0');
    return $result;
};

// ── Test 13: flex row + overflow-x:auto 不扩展容器宽度 ──
$tests['flex row overflow-x auto 不撑大容器'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;overflow-x:auto;width:300px;height:50px;gap:8px;:scroll-left="0"'], [
            VNode::h('div', ['style' => 'flex-shrink:0;width:120px;height:30px'], 'Tab 1'),
            VNode::h('div', ['style' => 'flex-shrink:0;width:120px;height:30px'], 'Tab 2'),
            VNode::h('div', ['style' => 'flex-shrink:0;width:120px;height:30px'], 'Tab 3'),
            VNode::h('div', ['style' => 'flex-shrink:0;width:120px;height:30px'], 'Tab 4'),
        ])
    );
    // cw=scrollWidth 语义（与 T10 cw=600 断言一致）：4×120+3×8gap=504；容器保持 300
    assert_contains($result, 'div (0,0 300x50) [dsp=flex] scroll ch=30 cw=504', 'flex+overflow-x: container 300, scrollWidth 504 (4 tabs + gaps)');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-05-Overflow.snap';
run_css_tests('Level 5 - 溢出与滚动', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
