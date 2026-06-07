<?php
/**
 * HTML 移植差异检测测试
 *
 * 对比 architecture-roadmap.html 中使用的 CSS 特性在 Px 框架中的支持情况。
 * 通过 RenderNode 快照对比发现布局/样式差异。
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: Flex row + gap 模拟两列布局 ──
$tests['Flex row + gap 两列等宽'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;height:200px;display:flex;flex-direction:row;gap:16px'], [
            VNode::h('div', ['style' => 'flex:1;height:200px;background:#161b22;border:1px solid #30363d'], 'Card1'),
            VNode::h('div', ['style' => 'flex:1;height:200px;background:#161b22;border:1px solid #30363d'], 'Card2'),
        ])
    );
    assert_contains($result, 'div (0,0 600x200)', '父容器 600x200');
    return $result;
};

// ── Test 2: CSS Grid 3列 ──
$tests['Grid 3列等宽'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px'], [
            VNode::h('div', ['style' => 'background:#161b22;padding:16px'], 'C1'),
            VNode::h('div', ['style' => 'background:#161b22;padding:16px'], 'C2'),
            VNode::h('div', ['style' => 'background:#161b22;padding:16px'], 'C3'),
        ])
    );
    return $result;
};

// ── Test 3: border-left 彩色左边框 ──
$tests['border-left 彩色左边框'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:100px;background:#161b22;border:1px solid #30363d;border-left:3px solid #7c3aed;border-radius:8px;padding:20px'], 'Accent')
    );
    return $result;
};

// ── Test 4: border-radius 圆角 ──
$tests['border-radius 圆角'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:100px;background:#161b22;border:1px solid #30363d;border-radius:8px'], 'Rounded')
    );
    return $result;
};

// ── Test 5: 嵌套 flex 模拟 TOC 多列 ──
// HTML用 columns:2 实现，Px用 flex-wrap 模拟
$tests['嵌套 flex 模拟 CSS columns'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;display:flex;flex-direction:row;flex-wrap:wrap;gap:8px'], [
            VNode::h('div', ['style' => 'width:290px;height:20px'], 'Item 1'),
            VNode::h('div', ['style' => 'width:290px;height:20px'], 'Item 2'),
            VNode::h('div', ['style' => 'width:290px;height:20px'], 'Item 3'),
            VNode::h('div', ['style' => 'width:290px;height:20px'], 'Item 4'),
        ])
    );
    return $result;
};

// ── Test 6: flex-shrink:0 固定宽度元素 ──
$tests['flex-shrink:0 固定宽度'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:28px;height:28px;border-radius:14px;background:#22c55e;display:flex;align-items:center;justify-content:center'], '1')
    );
    return $result;
};

// ── Test 7: flex row + align-items:center 垂直居中 ──
$tests['flex row + align-items:center'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:40px;display:flex;flex-direction:row;align-items:center;gap:8px'], [
            VNode::h('div', ['style' => 'width:28px;height:28px;border-radius:14px;background:#22c55e'], ''),
            VNode::h('span', ['style' => 'font-size:14px;font-weight:600;color:#e6edf3'], 'Title'),
        ])
    );
    return $result;
};

// ── Test 8: overflow-y:auto 滚动容器 ──
$tests['overflow-y:auto 滚动容器'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:100px;overflow-y:auto;background:#0d1117', ':scroll-top' => '0'], [
            VNode::h('div', ['style' => 'width:300px;height:50px'], 'Line1'),
            VNode::h('div', ['style' => 'width:300px;height:50px'], 'Line2'),
            VNode::h('div', ['style' => 'width:300px;height:50px'], 'Line3'),
            VNode::h('div', ['style' => 'width:300px;height:50px'], 'Line4'),
        ])
    );
    return $result;
};

// ── Test 9: 表格布局模拟 (flex row 表头+行) ──
$tests['flex row 模拟表格'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;display:flex;flex-direction:row;background:#161b22;border-bottom:1px solid #30363d'], [
            VNode::h('div', ['style' => 'width:200px;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d'], 'Column'),
            VNode::h('div', ['style' => 'flex:1;padding:8px 12px;font-size:13px;color:#e6edf3'], 'Value'),
        ])
    );
    return $result;
};

// ── Test 10: 深色背景+前景色文本 ──
$tests['深色背景前景色文本'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:30px;background:#0d1117;color:#e6edf3;font-size:14px;padding:4px 8px'], 'Dark theme text')
    );
    return $result;
};

// ── Test 11: 多层嵌套卡片 ──
$tests['多层嵌套卡片'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:500px;background:#0d1117;padding:32px'], [
            VNode::h('div', ['style' => 'background:#161b22;border:1px solid #30363d;border-left:3px solid #ef4444;border-radius:8px;padding:20px'], [
                VNode::h('div', ['style' => 'font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px'], 'Card Title'),
                VNode::h('div', ['style' => 'font-size:14px;color:#e6edf3'], 'Card content text'),
            ]),
        ])
    );
    return $result;
};

// ── Test 12: badge 标签样式 (inline-block 模拟) ──
// HTML: .badge { display: inline-block; font-size: 11px; ... }
// Px: 用固定宽高的 div 模拟
$tests['badge 标签样式'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:24px;display:flex;flex-direction:row;align-items:center;gap:6px'], [
            VNode::h('div', ['style' => 'font-size:11px;font-weight:600;padding:2px 8px;border-radius:4px;background:#22c55e;color:#22c55e'], 'Active'),
            VNode::h('span', ['style' => 'font-size:14px;font-weight:600;color:#e6edf3'], 'v5 M3'),
        ])
    );
    return $result;
};

// ── Test 13: 代码块 (white-space:pre) ──
$tests['代码块 white-space:pre'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:500px;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px 20px'], [
            VNode::h('div', ['style' => 'font-size:12px;color:#e6edf3;white-space:pre'], 'abstract class RenderContext {
    abstract public function save(): void;
    abstract public function restore(): void;
}'),
        ])
    );
    return $result;
};

// ── Test 14: 模拟 timeline 里程碑 ──
$tests['timeline 里程碑 flex row'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;display:flex;flex-direction:row;gap:8px;align-items:flex-start'], [
            VNode::h('div', ['style' => 'width:24px;height:24px;border-radius:12px;border:2px solid #22c55e;background:#0d1117;flex-shrink:0'], ''),
            VNode::h('div', ['style' => 'flex:1'], [
                VNode::h('div', ['style' => 'font-size:14px;font-weight:600;color:#e6edf3'], 'v5 M3: Flat + Layer'),
                VNode::h('div', ['style' => 'font-size:12px;color:#8b949e;margin-top:4px'], '~100 行 / 7 文件'),
            ]),
        ])
    );
    return $result;
};

// ── Test 15: rgba() 颜色支持 ──
$tests['rgba 颜色背景'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:40px;background:rgba(34,197,94,0.15);border-radius:8px;padding:8px 16px'], 'Rgba bg')
    );
    return $result;
};

// ── Test 16: text-align:center 居中 ──
$tests['text-align:center 居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;height:30px;text-align:center;font-size:12px;color:#8b949e'], 'Centered footer text')
    );
    return $result;
};

// ── Test 17: 模拟 dep-box (overflow:hidden + header + body) ──
$tests['dep-box 分区布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:500px;border:1px solid #30363d;border-radius:8px;overflow:hidden'], [
            VNode::h('div', ['style' => 'background:#161b22;padding:8px 14px;font-weight:600;font-size:13px;color:#e6edf3;border-bottom:1px solid #30363d'], 'v5 M3: Flat + Layer'),
            VNode::h('div', ['style' => 'padding:12px 14px'], [
                VNode::h('div', ['style' => 'font-size:13px;color:#e6edf3'], '框架提供:'),
            ]),
        ])
    );
    return $result;
};

// ── Test 18: flex-wrap 标签云 ──
$tests['flex-wrap 标签云'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;display:flex;flex-direction:row;flex-wrap:wrap;gap:4px'], [
            VNode::h('div', ['style' => 'background:#1c2128;padding:2px 6px;border-radius:3px;font-size:11px;color:#8b949e'], 'rect'),
            VNode::h('div', ['style' => 'background:#1c2128;padding:2px 6px;border-radius:3px;font-size:11px;color:#8b949e'], 'text'),
            VNode::h('div', ['style' => 'background:#1c2128;padding:2px 6px;border-radius:3px;font-size:11px;color:#8b949e'], 'button'),
            VNode::h('div', ['style' => 'background:#1c2128;padding:2px 6px;border-radius:3px;font-size:11px;color:#8b949e'], 'grid'),
            VNode::h('div', ['style' => 'background:#1c2128;padding:2px 6px;border-radius:3px;font-size:11px;color:#8b949e'], 'label'),
        ])
    );
    return $result;
};

// ── Test 19: 水平滚动容器 (overflow-x:auto) ──
$tests['overflow-x:auto 水平滚动'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:40px;overflow-x:auto;background:#0d1117'], [
            VNode::h('div', ['style' => 'width:800px;height:40px'], 'Wide content'),
        ])
    );
    return $result;
};

// ── Test 20: 组合场景 - 完整卡片行 ──
$tests['完整卡片行组合场景'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:1100px;height:900px;background:#0d1117;color:#e6edf3;font-size:14px;padding:32px'], [
            VNode::h('div', ['style' => 'font-size:28px;font-weight:700;color:#e6edf3;margin-bottom:4px'], 'Title'),
            VNode::h('div', ['style' => 'font-size:15px;color:#8b949e;margin-bottom:24px'], 'Subtitle text'),
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:16px'], [
                VNode::h('div', ['style' => 'flex:1;background:#161b22;border:1px solid #30363d;border-left:3px solid #ef4444;border-radius:8px;padding:20px'], 'Danger Card'),
                VNode::h('div', ['style' => 'flex:1;background:#161b22;border:1px solid #30363d;border-left:3px solid #22c55e;border-radius:8px;padding:20px'], 'Success Card'),
            ]),
        ])
    );
    return $result;
};

// =============================================================
// 运行所有测试
// =============================================================
run_css_tests(
    'HTML Migration CSS Diff Detection',
    __DIR__ . '/../__snapshots__/HtmlMigrationDiff.snap',
    $tests
);