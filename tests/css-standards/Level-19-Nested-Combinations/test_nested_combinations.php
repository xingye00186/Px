<?php
/**
 * Level 19: Nested Combinations / 嵌套组合
 *
 * 测试目标：
 *   1. Grid 包含 Flex Row
 *   2. Flex Row 包含 Grid
 *   3. Grid 包含 Grid
 *   4. Flex Column 包含 Flex Row
 *   5. Grid 包含 Scroll 容器
 *   6. Flex 包含 Absolute 定位
 *   7. Grid 包含 Flex Column + Fixed
 *   8. 三层嵌套 Grid > Flex > Grid
 *   9. Scroll 容器包含 Grid
 *   10. Flex 容器包含 Grid 包含 Flex
 *   11. Absolute 定位在 Grid cell 内
 *   12. Grid auto-fill 嵌套 Flex
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: Grid 包含 Flex Row ──
$tests['Grid 内 Flex Row 排列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:500px;height:100px;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:row'], [
                VNode::h('div', ['style' => 'width:40px;height:30px;background:#F00'], 'A'),
                VNode::h('div', ['style' => 'width:40px;height:30px;background:#0F0'], 'B'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;justify-content:center'], [
                VNode::h('div', ['style' => 'width:40px;height:30px;background:#00F'], 'C'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 500x100) [dsp=grid]', 'Grid container 500x100 with 2-column track');
    assert_contains($result, 'div (0,0 246x100) [dsp=flex]', 'Flex row fills first grid cell, stretched to container height 100 (Blink-verified align-content:stretch)');
    return $result;
};

// ── Test 2: Flex Row 包含 Grid ──
$tests['Flex Row 内 Grid 布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:600px;height:120px;gap:8px'], [
            VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;flex:1;gap:4px'], [
                VNode::h('div', ['style' => 'height:40px;background:#F00'], '1'),
                VNode::h('div', ['style' => 'height:40px;background:#0F0'], '2'),
            ]),
            VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr 1fr;flex:2;gap:4px'], [
                VNode::h('div', ['style' => 'height:40px;background:#00F'], '3'),
                VNode::h('div', ['style' => 'height:40px;background:#FF0'], '4'),
                VNode::h('div', ['style' => 'height:40px;background:#F0F'], '5'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 600x120) [dsp=flex]', 'Flex row 600px wide containing grid children');
    assert_contains($result, 'div (205,0 394x120) [dsp=grid] fg=2', 'Second grid flex:2 at x=205 (197+8 gap)');
    return $result;
};

// ── Test 3: Grid 包含 Grid ──
$tests['Grid 内 Grid 嵌套'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:500px;height:150px;gap:8px'], [
            VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;gap:4px'], [
                VNode::h('div', ['style' => 'height:40px;background:#F00'], 'A'),
                VNode::h('div', ['style' => 'height:40px;background:#0F0'], 'B'),
            ]),
            VNode::h('div', ['style' => 'display:grid;grid-template-rows:1fr 2fr;gap:4px'], [
                VNode::h('div', ['style' => 'background:#00F'], 'C'),
                VNode::h('div', ['style' => 'background:#FF0'], 'D'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 500x150) [dsp=grid]', 'Outer grid 500x150 with 2-column track');
    assert_contains($result, 'div (254,0 246x100) [dsp=grid]', 'Second nested grid at x=254 after 8px gap, stretched to 100 (Blink-verified stretch)');
    return $result;
};

// ── Test 4: Flex Column 包含 Flex Row ──
$tests['Flex Column 内 Flex Row 嵌套'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:400px;height:200px;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:8px'], [
                VNode::h('div', ['style' => 'flex:1;height:40px;background:#F00'], 'A'),
                VNode::h('div', ['style' => 'flex:2;height:40px;background:#0F0'], 'B'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;justify-content:space-around'], [
                VNode::h('div', ['style' => 'width:60px;height:40px;background:#00F'], 'C'),
                VNode::h('div', ['style' => 'width:60px;height:40px;background:#FF0'], 'D'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 400x200) [dsp=flex]', 'Flex column container 400x200');
    assert_contains($result, 'div (0,48 400x40) [dsp=flex]', 'Second flex row at y=48 after 40px+8px gap');
    return $result;
};

// ── Test 5: Grid 包含 Scroll 容器 ──
$tests['Grid 内 overflow 滚动容器'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 200px;width:400px;height:150px;gap:8px'], [
            VNode::h('div', ['style' => 'height:60px'], 'Normal'),
            VNode::h('div', ['style' => 'height:80px;overflow-y:auto'], 'Scroll'),
        ])
    );
    assert_contains($result, 'div (208,0 200x80) scroll', 'Scroll container at x=208 (200px col + 8px gap)');
    return $result;
};

// ── Test 6: Flex 包含 Absolute 定位 ──
$tests['Flex 内 absolute 定位元素'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:400px;height:100px;position:relative'], [
            VNode::h('div', ['style' => 'width:80px;height:40px'], 'Item'),
            VNode::h('div', ['style' => 'position:absolute;right:10px;top:10px;width:100px;height:50px;background:#F00'], 'Absolute'),
        ])
    );
    assert_contains($result, 'div (290,10 100x50) [pos=absolute]', 'Absolute at right:10 top:10 = x=400-100-10=290');
    return $result;
};

// ── Test 7: Grid 包含 Flex Column + Fixed ──
$tests['Grid 内 Flex Column 嵌套'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 2fr;width:600px;height:150px;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;gap:4px'], [
                VNode::h('div', ['style' => 'height:40px;background:#F00'], 'Top'),
                VNode::h('div', ['style' => 'flex:1;background:#0F0'], 'Fill'),
                VNode::h('div', ['style' => 'height:30px;background:#00F'], 'Bottom'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;justify-content:center;align-items:center'], [
                VNode::h('div', ['style' => 'width:80px;height:50px;background:#FF0'], 'Center'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 600x150) [dsp=grid]', 'Grid 600x150 with 1fr/2fr columns');
    assert_contains($result, 'div (205,0 197x150) [dsp=flex]', 'Second flex column at x=205 (1fr=197 + 8 gap), stretched to grid row height 150 (Blink-verified)');
    return $result;
};

// ── Test 8: 三层嵌套 Grid > Flex > Grid ──
$tests['三层嵌套 Grid > Flex > Grid'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr;width:500px;height:200px;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;gap:8px'], [
                VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;gap:4px'], [
                    VNode::h('div', ['style' => 'height:40px;background:#F00'], 'A'),
                    VNode::h('div', ['style' => 'height:40px;background:#0F0'], 'B'),
                ]),
                VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px'], [
                    VNode::h('div', ['style' => 'height:40px;background:#00F'], 'C'),
                    VNode::h('div', ['style' => 'height:40px;background:#FF0'], 'D'),
                    VNode::h('div', ['style' => 'height:40px;background:#F0F'], 'E'),
                ]),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 500x200) [dsp=grid]', 'Outer grid 500x200 containing flex');
    assert_contains($result, 'div (0,48 500x40) [dsp=grid]', 'Second nested grid at y=48 (40px+8px gap) inside flex column, content-height rows (no stretch, height:auto flex column)');
    return $result;
};

// ── Test 9: Scroll 容器包含 Grid ──
$tests['Scroll 容器内 Grid 布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:150px;overflow-y:auto'], [
            VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;gap:8px'], [
                VNode::h('div', ['style' => 'height:60px;background:#F00'], 'A'),
                VNode::h('div', ['style' => 'height:60px;background:#0F0'], 'B'),
                VNode::h('div', ['style' => 'height:60px;background:#00F'], 'C'),
                VNode::h('div', ['style' => 'height:60px;background:#FF0'], 'D'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 300x150) scroll', 'Scroll container 300x150 with overflow-y:auto');
    assert_contains($result, 'div (0,0 300x128) [dsp=grid]', 'Grid inside scroll fills 300px width');
    return $result;
};

// ── Test 10: Flex 包含 Grid 包含 Flex ──
$tests['Flex > Grid > Flex 链式嵌套'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:500px;height:200px;gap:8px'], [
            VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;flex:1;gap:8px'], [
                VNode::h('div', ['style' => 'display:flex;flex-direction:row;align-items:center;justify-content:center;background:#F00'], 'Center 1'),
                VNode::h('div', ['style' => 'display:flex;flex-direction:row;align-items:center;justify-content:center;background:#0F0'], 'Center 2'),
            ]),
        ])
    );
    assert_contains($result, 'div (254,0 246x200) [dsp=flex] text="Center 2"', 'Nested flex in second grid cell (x=254), stretched to grid height 200 via is_fixed_block_size chain (Blink-verified 246x200)');
    return $result;
};

// ── Test 11: Absolute 定位在 Grid cell 内 ──
$tests['Grid cell 内 position:absolute'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 200px;width:400px;height:120px;gap:8px'], [
            VNode::h('div', ['style' => 'position:relative;height:80px'], [
                VNode::h('div', ['style' => 'position:absolute;right:10px;top:10px;width:60px;height:30px;background:#F00'], 'Badge'),
                VNode::h('div', ['style' => 'height:40px'], 'Content'),
            ]),
            VNode::h('div', ['style' => 'height:80px'], 'Normal'),
        ])
    );
    assert_contains($result, 'div (130,10 60x30) [pos=absolute] text="Badge"', 'Absolute at right:10 within 200px cell (200-60-10=130)');
    return $result;
};

// ── Test 12: Grid auto-fill 嵌套 Flex ──
$tests['Grid auto-fill 嵌套 Flex 项目'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fill, minmax(150px, 1fr));width:500px;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;align-items:center;padding:8px'], [
                VNode::h('div', ['style' => 'width:40px;height:40px;background:#F00'], 'Icon'),
                VNode::h('div', ['style' => 'height:20px'], 'Label'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;align-items:center;padding:8px'], [
                VNode::h('div', ['style' => 'width:40px;height:40px;background:#0F0'], 'Icon'),
                VNode::h('div', ['style' => 'height:20px'], 'Label'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;align-items:center;padding:8px'], [
                VNode::h('div', ['style' => 'width:40px;height:40px;background:#00F'], 'Icon'),
                VNode::h('div', ['style' => 'height:20px'], 'Label'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 161x76) [dsp=flex]', 'First auto-fill item at 161px width (minmax(150,1fr))');
    assert_contains($result, 'div (338,0 161x76) [dsp=flex]', 'Third auto-fill item at x=338 (161+8+161+8=338)');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-19-Nested-Combinations.snap';
run_css_tests('Level 19 - Nested Combinations', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
