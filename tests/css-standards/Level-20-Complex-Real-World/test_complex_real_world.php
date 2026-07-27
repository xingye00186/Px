<?php
/**
 * Level 20: Complex Real-World / 复杂真实场景
 *
 * 测试目标：
 *   1. Dashboard 布局 (sidebar + main + header)
 *   2. Article 页面 (标题 + 内容 + 侧边栏)
 *   3. Tab 切换组件
 *   4. Pricing card 价格卡片
 *   5. 垂直居中 Hero Section
 *   6. 响应式卡片网格
 *   7. 工具栏 + 内容区
 *   8. 表单布局 (label + input)
 *   9. 通知列表 (icon + text + time)
 *   10. 分割面板 (left + right)
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ── Test 1: Dashboard 布局 ──
$tests['Dashboard 侧边栏+头部+主内容'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:200px 1fr;grid-template-rows:60px 1fr;width:900px;height:600px'], [
            VNode::h('div', ['style' => 'background:#333;color:#FFF;padding:16px'], 'Sidebar'),
            VNode::h('div', ['style' => 'background:#EEE;padding:16px'], 'Header'),
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:16px;padding:16px;grid-column:2'], [
                VNode::h('div', ['style' => 'flex:2;background:#FFF;padding:16px;border-radius:8px'], 'Main Content'),
                VNode::h('div', ['style' => 'flex:1;background:#FFF;padding:16px;border-radius:8px'], 'Side Panel'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 200x60) text="Sidebar"', 'Grid sidebar spans first col at 200px width');
    assert_contains($result, 'div (200,0 700x60) text="Header"', 'Header at x=200 spans second col 700px');
    return $result;
};

// ── Test 2: Article 页面 ──
$tests['Article 页面 标题+内容+侧边栏'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'max-width:800px;width:800px'], [
            VNode::h('div', ['style' => 'font-size:28px;font-weight:bold;padding:16px 0'], 'Article Title'),
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:20px'], [
                VNode::h('div', ['style' => 'flex:3;line-height:1.6'], 'Article content paragraph here.'),
                VNode::h('div', ['style' => 'flex:1;background:#F5F5F5;padding:16px;border-radius:8px'], 'Sidebar Widget'),
            ]),
        ])
    );
    // Blink 真值：Article Title 文本高 65（28px bold + padding 16*2）；flex 行在 y=65；
    // Sidebar flex:1 于 (585+20gap)=605，高 51（padding16*2+行高19）
    assert_contains($result, 'div (605,65 195x51) fg=1 text="Sidebar Widget"', 'Sidebar at x=605 (585 content + 20 gap), y=65 below title, flex:1 width 195');
    return $result;
};

// ── Test 3: Tab 组件 ──
$tests['Tab 切换组件 标签页头+内容'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;border-bottom:2px solid #DDD'], [
                VNode::h('div', ['style' => 'padding:10px 20px;border-bottom:2px solid #F00;color:#F00;cursor:pointer'], 'Tab 1'),
                VNode::h('div', ['style' => 'padding:10px 20px;color:#999;cursor:pointer'], 'Tab 2'),
                VNode::h('div', ['style' => 'padding:10px 20px;color:#999;cursor:pointer'], 'Tab 3'),
            ]),
            VNode::h('div', ['style' => 'padding:20px;background:#FFF;min-height:100px'], 'Tab Content 1'),
        ])
    );
    assert_contains($result, 'div (0,0 600x41) [dsp=flex]', 'Tab header flex row: height 41 = padding 10*2 + line-height 19 + 2px border-bottom (Tab1); row takes max item height');
    assert_contains($result, 'div (0,41 600x100) text="Tab Content 1"', 'Tab content at y=41 (flex row height, border included); border-box width 600 fills containing block (CSS 2.2 §10.3.3 auto width; Blink getBoundingClientRect=600, content 560 excludes 2*20 padding); height 100 = min-height');
    return $result;
};

// ── Test 4: Pricing card ──
$tests['Pricing Card 价格卡片'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:16px;width:700px;padding:20px'], [
            VNode::h('div', ['style' => 'flex:1;border:1px solid #DDD;border-radius:12px;padding:24px;text-align:center'], [
                VNode::h('div', ['style' => 'font-size:20px;font-weight:bold'], 'Basic'),
                VNode::h('div', ['style' => 'font-size:36px;color:#F00;padding:12px 0'], '$9'),
                VNode::h('div', ['style' => 'padding:8px;background:#F00;color:#FFF;border-radius:6px;cursor:pointer'], 'Sign Up'),
            ]),
            VNode::h('div', ['style' => 'flex:1;border:2px solid #00F;border-radius:12px;padding:24px;text-align:center'], [
                VNode::h('div', ['style' => 'font-size:20px;font-weight:bold'], 'Pro'),
                VNode::h('div', ['style' => 'font-size:36px;color:#00F;padding:12px 0'], '$29'),
                VNode::h('div', ['style' => 'padding:8px;background:#00F;color:#FFF;border-radius:6px;cursor:pointer'], 'Sign Up'),
            ]),
        ])
    );
    assert_contains($result, 'div (20,20 322x178) bw=1 fg=1', 'First pricing card flex:1 width=(700-40padding-16gap)/2=322, at (20,20)');
    return $result;
};

// ── Test 5: 垂直居中 Hero ──
$tests['垂直居中 Hero Section'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;align-items:center;justify-content:center;width:800px;height:400px;background:#F0F8FF'], [
            VNode::h('div', ['style' => 'font-size:42px;font-weight:bold;text-align:center'], 'Welcome'),
            VNode::h('div', ['style' => 'font-size:18px;color:#666;text-align:center;padding:12px'], 'Subtitle here'),
            VNode::h('div', ['style' => 'padding:12px 32px;background:#00F;color:#FFF;border-radius:8px;cursor:pointer'], 'Get Started'),
        ])
    );
    // Blink 真值（浏览器 getBoundingClientRect 验证机制）：align-items:center → fit-content 宽（非全宽 800）；
    // 42px 字体行高 50；三子项总高 138 → y=(400-138)/2=131
    assert_contains($result, 'div (312,131 175x50) text="Welcome"', 'Hero: Welcome fit-content 175x50 centered (x=(800-175)/2=312, y=131) per align-items:center + justify-content:center');
    return $result;
};

// ── Test 6: 响应式卡片网格 ──
$tests['响应式卡片网格 auto-fill'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));width:800px;gap:16px;padding:16px'], [
            VNode::h('div', ['style' => 'border:1px solid #DDD;border-radius:8px;padding:16px;background:#FFF'], 'Card 1'),
            VNode::h('div', ['style' => 'border:1px solid #DDD;border-radius:8px;padding:16px;background:#FFF'], 'Card 2'),
            VNode::h('div', ['style' => 'border:1px solid #DDD;border-radius:8px;padding:16px;background:#FFF'], 'Card 3'),
        ])
    );
    // Blink 真值：grid 高度 auto → 行高 = card 高 53（padding 16*2 + 行高 21）
    assert_contains($result, 'div (0,0 800x53) [dsp=grid]', 'Card grid auto height 53 (padding 32 + text line-height 21)');
    assert_contains($result, 'div (272,0 256x53) bw=1 text="Card 2"', 'Second card at x=272 (256+16 gap)');
    return $result;
};

// ── Test 7: 工具栏 + 内容区 ──
$tests['工具栏+内容区 flex 布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:800px;height:500px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;align-items:center;padding:8px 16px;background:#F5F5F5;gap:8px'], [
                VNode::h('div', ['style' => 'font-weight:bold;font-size:18px'], 'Title'),
                VNode::h('div', ['style' => 'flex:1'], ''),
                VNode::h('div', ['style' => 'padding:6px 16px;background:#00F;color:#FFF;border-radius:4px;cursor:pointer'], 'Save'),
                VNode::h('div', ['style' => 'padding:6px 16px;border:1px solid #DDD;border-radius:4px;cursor:pointer'], 'Cancel'),
            ]),
            VNode::h('div', ['style' => 'flex:1;padding:16px;overflow-y:auto'], 'Content area with scroll.'),
        ])
    );
    assert_contains($result, 'div (0,0 800x49) [dsp=flex]', 'Toolbar flex row: height 49 = padding 8*2 + max item height 33 (Cancel with border)');
    assert_contains($result, 'div (0,49 800x451) scroll', 'Content area at y=49 below toolbar (flex:1 fills remaining 500-49=451) with scroll');
    return $result;
};

// ── Test 8: 表单布局 ──
$tests['表单布局 label+input 两列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:120px 1fr;width:500px;gap:12px 8px;padding:16px'], [
            VNode::h('div', ['style' => 'text-align:right;padding:6px 0'], 'Username:'),
            VNode::h('input', ['style' => 'padding:6px;border:1px solid #DDD;border-radius:4px'], ''),
            VNode::h('div', ['style' => 'text-align:right;padding:6px 0'], 'Password:'),
            VNode::h('input', ['style' => 'padding:6px;border:1px solid #DDD;border-radius:4px'], ''),
            VNode::h('div', ['style' => 'text-align:right;padding:6px 0'], 'Bio:'),
            VNode::h('textarea', ['style' => 'padding:6px;border:1px solid #DDD;border-radius:4px;height:60px'], ''),
        ])
    );
    // Blink 真值：label 行高 31（padding 6*2 + 行高 19）；第二行 y=159（第一行 31 + gap 12 + ... grid 行定位）
    assert_contains($result, 'div (0,0 120x31) text="Username:"', 'Label first grid column 120px, height 31 (padding 12 + line-height 19)');
    assert_contains($result, 'div (0,159 120x31) text="Password:"', 'Password label second grid row at y=159');
    return $result;
};

// ── Test 9: 通知列表 ──
$tests['通知列表 icon+text+time'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;display:flex;flex-direction:column;gap:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;align-items:center;padding:12px;background:#FFF;border-radius:8px;gap:12px'], [
                VNode::h('div', ['style' => 'width:40px;height:40px;border-radius:50%;background:#F00;flex-shrink:0'], ''),
                VNode::h('div', ['style' => 'flex:1'], [
                    VNode::h('div', ['style' => 'font-weight:bold'], 'Notification title'),
                    VNode::h('div', ['style' => 'font-size:12px;color:#999'], 'Notification description text.'),
                ]),
                VNode::h('div', ['style' => 'font-size:12px;color:#CCC'], '2m ago'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;align-items:center;padding:12px;background:#FFF;border-radius:8px;gap:12px'], [
                VNode::h('div', ['style' => 'width:40px;height:40px;border-radius:50%;background:#0F0;flex-shrink:0'], ''),
                VNode::h('div', ['style' => 'flex:1'], [
                    VNode::h('div', ['style' => 'font-weight:bold'], 'Another notification'),
                    VNode::h('div', ['style' => 'font-size:12px;color:#999'], 'Some longer description here.'),
                ]),
                VNode::h('div', ['style' => 'font-size:12px;color:#CCC'], '1h ago'),
            ]),
        ])
    );
    // Blink 真值：flex 行高 64（icon 40 + padding 12*2）
    assert_contains($result, 'div (0,0 400x64) [dsp=flex]', 'First notification row: height 64 = icon 40 + padding 12*2');
    return $result;
};

// ── Test 10: 分割面板 ──
$tests['分割面板 left+right 拖拽分隔'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:800px;height:400px;border:1px solid #DDD'], [
            VNode::h('div', ['style' => 'flex:1;padding:16px;overflow-y:auto;background:#F9F9F9'], 'Left Panel'),
            VNode::h('div', ['style' => 'width:4px;background:#DDD;cursor:col-resize'], ''),
            VNode::h('div', ['style' => 'flex:2;padding:16px;overflow-y:auto'], 'Right Panel'),
        ])
    );
    assert_contains($result, 'div (0,0 265x400) scroll', 'Left panel with scroll at flex:1 = 265px in 800px row');
    // Blink 真值：left flex:1 = (800-4divider)/3 = 265；divider 4px 在 x=265；right flex:2 从 x=269，宽 530；
    // cw=498 (530-padding16*2)；ch=51 (文本内容高)
    assert_contains($result, 'div (269,0 530x400) scroll ch=51 cw=498 maxScroll=0 st=0 sl=0 ov=auto fg=2 text="Right Panel"', 'Right panel flex:2=530 at x=269 (after left 265 + divider 4)');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-20-Complex-Real-World.snap';
run_css_tests('Level 20 - Complex Real-World', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
