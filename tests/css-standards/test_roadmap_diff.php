<?php
/**
 * Roadmap 应用 CSS 差异检测测试
 *
 * 对比 architecture-roadmap.html 中使用的 CSS 特性在 Px 框架中的支持情况。
 * 通过 RenderNode 快照 + run_minimal_pipeline() 检测布局/样式差异。
 */

require_once __DIR__ . '/../unit/PipelineTestBase.php';
require_once __DIR__ . '/CssTestBase.php';

use Px\Dom\VNode;

$tests = [];

// ============================================================
// 测试 1: 应用全量渲染快照
// ============================================================
$tests['Roadmap App 完整渲染快照'] = function() {
    $appDir = realpath(__DIR__ . '/../../apps/roadmap');
    // 使用反射调用 protected captureSnapshot
    $rm = new \ReflectionMethod(PipelineTestBase::class, 'captureSnapshot');
    $rm->setAccessible(true);
    $snapshot = $rm->invoke(null, $appDir);
    if (empty($snapshot)) {
        throw new \RuntimeException("Roadmap snapshot is empty");
    }
    return $snapshot;
};

// ============================================================
// 测试 2: Flex row + gap 模拟 grid-2 两列布局
// HTML: .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
// ============================================================
$tests['Flex row + gap 模拟 Grid-2 两列'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;height:200px;display:flex;flex-direction:row;gap:16px'], [
            VNode::h('div', ['style' => 'flex:1;height:200px;background:#161b22;border:1px solid #30363d'], 'Card 1'),
            VNode::h('div', ['style' => 'flex:1;height:200px;background:#161b22;border:1px solid #30363d'], 'Card 2'),
        ])
    );
    return $result;
};

// ============================================================
// 测试 3: CSS Grid 3列布局
// HTML: .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; }
// ============================================================
$tests['Grid 3列等宽布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px'], [
            VNode::h('div', ['style' => 'background:#161b22;padding:16px'], 'Col 1'),
            VNode::h('div', ['style' => 'background:#161b22;padding:16px'], 'Col 2'),
            VNode::h('div', ['style' => 'background:#161b22;padding:16px'], 'Col 3'),
        ])
    );
    return $result;
};

// ============================================================
// 测试 4: border-left 彩色左边框卡片
// HTML: .card-accent { border-left: 3px solid #7c3aed; }
// ============================================================
$tests['border-left 彩色左边框卡片'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:100px;background:#161b22;border:1px solid #30363d;border-left:3px solid #7c3aed;border-radius:8px;padding:20px'], 'Accent Card')
    );
    return $result;
};

// ============================================================
// 测试 5: border-radius 圆角
// HTML: .card { border-radius: 8px; }
// ============================================================
$tests['border-radius 圆角'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:100px;background:#161b22;border:1px solid #30363d;border-radius:8px'], 'Rounded')
    );
    return $result;
};

// ============================================================
// 测试 6: 多层嵌套 flex 布局（模拟 TOC 区域）
// HTML: .toc-grid { display:flex; flex-wrap:wrap; gap:8px; }
//       .toc-grid > div { width: 50% }
// ============================================================
$tests['flex-wrap 换行布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;display:flex;flex-direction:row;flex-wrap:wrap;gap:8px'], [
            VNode::h('div', ['style' => 'width:290px;height:20px'], 'Left 1'),
            VNode::h('div', ['style' => 'width:290px;height:20px'], 'Right 1'),
            VNode::h('div', ['style' => 'width:290px;height:20px'], 'Left 2'),
            VNode::h('div', ['style' => 'width:290px;height:20px'], 'Right 2'),
        ])
    );
    return $result;
};

// ============================================================
// 测试 7: 滚动容器
// HTML: .main { overflow-y: auto; }
// ============================================================
$tests['overflow-y:auto 滚动容器'] = function() {
    $children = [];
    for ($i = 0; $i < 20; $i++) {
        $children[] = VNode::h('div', ['style' => 'height:40px;width:300px'], 'Item ' . $i);
    }
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:200px;overflow-y:auto', ':scroll-top' => '0'], $children)
    );
    return $result;
};

// ============================================================
// 测试 8: 表格布局（flex row 模拟 table）
// HTML: table 布局
// ============================================================
$tests['Flex row 模拟表格布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;background:#161b22;border:1px solid #30363d;border-radius:8px;overflow:hidden'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;border-bottom:1px solid #30363d'], [
                VNode::h('div', ['style' => 'width:150px;padding:8px 12px;font-weight:600;font-size:13px;border-right:1px solid #30363d'], 'Key'),
                VNode::h('div', ['style' => 'width:350px;padding:8px 12px;font-size:13px;border-right:1px solid #30363d'], 'Current'),
                VNode::h('div', ['style' => 'flex:1;padding:8px 12px;font-size:13px'], 'Target'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:row'], [
                VNode::h('div', ['style' => 'width:150px;padding:8px 12px;font-size:13px;border-right:1px solid #30363d'], 'GDI'),
                VNode::h('div', ['style' => 'width:350px;padding:8px 12px;font-size:13px;border-right:1px solid #30363d'], '5'),
                VNode::h('div', ['style' => 'flex:1;padding:8px 12px;font-size:13px'], '13'),
            ]),
        ])
    );
    return $result;
};

// ============================================================
// 测试 9: 时间线圆形节点
// HTML: .milestone-dot { width:24px; height:24px; border-radius:50%; }
// ============================================================
$tests['border-radius 圆形节点'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:24px;height:24px;border-radius:12px;background:rgba(34,197,94,0.15);border:2px solid #22c55e'], '●')
    );
    return $result;
};

// ============================================================
// 测试 10: 代码块 (white-space:pre)
// HTML: pre { white-space: pre; font-family: Consolas, monospace; }
// ============================================================
$tests['white-space:pre 代码块'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px 20px'], [
            VNode::h('span', ['style' => 'font-size:12px;font-family:Consolas,monospace;white-space:pre'], "abstract class RenderContext {\n    abstract public function save(): void;\n}")
        ])
    );
    return $result;
};

// ============================================================
// 测试 11: 不支持的 CSS 特性 - rgba()
// HTML: background: rgba(124,58,237,0.06)
// ============================================================
$tests['rgba() 半透明背景'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:400px;height:60px;background:rgba(124,58,237,0.06);border:1px solid rgba(124,58,237,0.2);border-radius:8px;padding:16px 20px'], 'RGBA Background')
    );
    return $result;
};

// ============================================================
// 测试 12: 不支持的 CSS 特性 - margin:0 auto 居中
// HTML: footer { margin: 0 auto; }
// ============================================================
$tests['margin:0 auto 居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;height:100px;border-top:1px solid #30363d'], [
            VNode::h('div', ['style' => 'margin:0 auto;width:300px;height:20px'], 'Centered Footer')
        ])
    );
    return $result;
};

// ============================================================
// 测试 13: justify-content:center (Footer 居中)
// HTML: display:flex; justify-content:center
// ============================================================
$tests['justify-content:center 居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;height:60px;display:flex;justify-content:center;align-items:center;border-top:1px solid #30363d'], 'Centered')
    );
    return $result;
};

// ============================================================
// 测试 14: 不支持的 CSS 特性 - box-sizing:border-box
// ============================================================
$tests['box-sizing:border-box'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:100px;padding:20px;border:2px solid #30363d;box-sizing:border-box;background:#161b22'], 'Bordered')
    );
    return $result;
};

// ============================================================
// 测试 15: align-items:center 垂直居中
// ============================================================
$tests['align-items:center 垂直居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:300px;height:60px;display:flex;align-items:center;gap:8px'], [
            VNode::h('div', ['style' => 'width:24px;height:24px;border-radius:12px;background:#22c55e'], ''),
            VNode::h('span', ['style' => 'font-size:14px'], 'Vertically Centered')
        ])
    );
    return $result;
};

// ============================================================
// 测试 16: 深层嵌套布局
// ============================================================
$tests['深层嵌套布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;padding:32px'], [
            VNode::h('div', ['style' => 'background:#161b22;border:1px solid #30363d;border-left:3px solid #7c3aed;border-radius:8px;padding:20px'], [
                VNode::h('div', ['style' => 'font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px'], 'Title'),
                VNode::h('div', ['style' => 'background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:16px'], [
                    VNode::h('span', ['style' => 'font-size:12px;color:#e6edf3'], 'Inner code block')
                ])
            ])
        ])
    );
    return $result;
};

// ============================================================
// 运行所有测试
// ============================================================
$snapFile = __DIR__ . '/__snapshots__/roadmap-css-diff.snap';
run_css_tests('Roadmap CSS 差异检测', $snapFile, $tests);
