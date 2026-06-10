<?php
/**
 * Level 6: 复合布局场景 (Bilibili 子场景)
 *
 * 测试目标：
 *   1. Grid 内嵌 flex —— VideoGrid 场景
 *   2. 图像容器（占位）在 Grid 中
 *   3. 嵌套 flex 实现 header 导航（NavBar 场景）
 *   4. 多列 Grid + 每列内部 flex column（CategoryTab 场景）
 *   5. 浮动按钮 absolute 定位
 *   6. 组合场景：Grid + flex + scroll 混合
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Rendering\VNode;

$tests = [];

// ── Test 1: Grid 内嵌 flex（Bilibili VideoGrid 场景） ──
$tests['Grid 内嵌 flex 实现卡片网格'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));width:964px;gap:16px'], [
            // VideoCard 1: flex column
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:100%;height:auto'], [
                VNode::h('div', ['style' => 'width:100%;height:140px;background:#E3E5E7'], 'Cover'),
                VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:8px;width:100%;padding:8px'], [
                    VNode::h('div', ['style' => 'width:36px;height:36px;border-radius:50%;background:#ccc'], ''),
                    VNode::h('div', ['style' => 'flex:1'], 'Title text'),
                ]),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 964x208)', 'Grid container explicit width=964');
    return $result;
};

// ── Test 2: NavBar 水平导航 ──
$tests['NavBar 水平导航栏 (flex row)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;align-items:center;width:1440px;height:56px;padding:0 24px;background:#fff;border-bottom:1px solid #E3E5E7'], [
            VNode::h('div', ['style' => 'width:24px;height:24px;margin-right:16px'], ''),
            VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:24px;flex:1'], [
                VNode::h('div', ['style' => 'font-size:16px;font-weight:bold'], 'Bilibili'),
                VNode::h('div', ['style' => 'color:#666'], '推荐'),
                VNode::h('div', ['style' => 'color:#666'], '热门'),
                VNode::h('div', ['style' => 'color:#666'], '关注'),
            ]),
            VNode::h('div', ['style' => 'width:80px;height:32px;border-radius:16px;background:#FB7299'], ''),
        ])
    );
    assert_contains($result, 'div (0,0 1440x56)', 'NavBar explicit 1440x56 border-bottom=1px');
    return $result;
};

// ── Test 3: 浮动按钮（position:fixed/fixed 定位） ──
$tests['浮动按钮 (position:fixed)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:fixed;left:700px;top:400px;width:48px;height:48px;border-radius:50%;background:#FB7299'], 'Top')
    );
    assert_contains($result, 'div (700,400 48x48)', 'position:fixed left=700 top=400 48x48');
    return $result;
};

// ── Test 4: CategoryTabs 滚动标签栏 ──
$tests['CategoryTabs 可滚动标签栏'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:1440px;height:44px;overflow-x:auto;align-items:center;gap:12px;padding:0 16px'], [
            VNode::h('div', ['style' => 'flex-shrink:0;padding:2px 14px;height:28px;line-height:28px;border-radius:14px;background:#FB7299;color:#fff;font-size:13px'], '推荐'),
            VNode::h('div', ['style' => 'flex-shrink:0;padding:2px 14px;height:28px;line-height:28px;border-radius:14px;color:#666;font-size:13px'], '热门'),
            VNode::h('div', ['style' => 'flex-shrink:0;padding:2px 14px;height:28px;line-height:28px;border-radius:14px;color:#666;font-size:13px'], '游戏'),
            VNode::h('div', ['style' => 'flex-shrink:0;padding:2px 14px;height:28px;line-height:28px;border-radius:14px;color:#666;font-size:13px'], '科技'),
            VNode::h('div', ['style' => 'flex-shrink:0;padding:2px 14px;height:28px;line-height:28px;border-radius:14px;color:#666;font-size:13px'], '生活'),
        ])
    );
    assert_contains($result, 'scroll ch=40', 'CategoryTabs scroll container content height=40');
    return $result;
};

// ── Test 5: 综合视频卡片（含封面比例） ──
$tests['视频卡片 (封面+信息) flex column'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:300px;height:auto;border-radius:8px;overflow:hidden'], [
            VNode::h('div', ['style' => 'width:100%;height:168px;background:#E3E5E7'], ''),
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;gap:4px;padding:8px'], [
                VNode::h('div', ['style' => 'font-size:14px;line-height:20px;color:#18191C'], '视频标题最多两行'),
                VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:8px;align-items:center;font-size:12px;color:#9499A0'], [
                    VNode::h('div', ['style' => 'display:flex;align-items:center;gap:4px'], 'UP主名称'),
                    VNode::h('div', [], '·'),
                    VNode::h('div', [], '1.2万'),
                ]),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 300x0)', 'Video card width=300px explicit');
    return $result;
};

// ── Test 6: 完整视频网格区域（多卡片 auto-fill） ──
$tests['视频网格 3列 auto-fill 混合布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));width:964px;gap:16px;padding:16px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:100%'], [
                VNode::h('div', ['style' => 'width:100%;height:140px;background:#ccc'], 'Cover'),
                VNode::h('div', ['style' => 'padding:8px;font-size:14px'], 'Video 1'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:100%'], [
                VNode::h('div', ['style' => 'width:100%;height:140px;background:#ccc'], 'Cover'),
                VNode::h('div', ['style' => 'padding:8px;font-size:14px'], 'Video 2'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:100%'], [
                VNode::h('div', ['style' => 'width:100%;height:140px;background:#ccc'], 'Cover'),
                VNode::h('div', ['style' => 'padding:8px;font-size:14px'], 'Video 3'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 964x174)', 'Grid 964px 3col auto-fill gap=16');
    return $result;
};

// ── Test 7: Bilibili 顶层布局（flex column + sticky） ──
$tests['Bilibili 顶层 flex column 布局'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:1440px;height:900px'], [
            // NavBar
            VNode::h('div', ['style' => 'display:flex;align-items:center;height:56px;padding:0 24px;flex-shrink:0'], [
                VNode::h('div', ['style' => 'width:24px;height:24px'], ''),
                VNode::h('div', ['style' => 'flex:1;display:flex;gap:24px'], [
                    VNode::h('div', [], 'Bilibili'), VNode::h('div', [], '推荐'),
                ]),
            ]),
            // CategoryTabs
            VNode::h('div', ['style' => 'display:flex;align-items:center;height:44px;overflow-x:auto;gap:12px;padding:0 16px;flex-shrink:0'], [
                VNode::h('div', ['style' => 'flex-shrink:0;padding:2px 14px;border-radius:14px'], '推荐'),
                VNode::h('div', ['style' => 'flex-shrink:0;padding:2px 14px;border-radius:14px'], '热门'),
            ]),
            // MainContent (flex:1)
            VNode::h('div', ['style' => 'flex:1;overflow-y:auto;padding:16px'], [
                VNode::h('div', ['style' => 'height:200px'], 'Content Area'),
            ]),
            // FloatingButton
            VNode::h('div', ['style' => 'position:fixed;left:700px;top:400px;width:48px;height:48px;border-radius:50%'], ''),
        ])
    );
    assert_contains($result, 'div (0,100 1440x800)', 'Main content y=56+44 NavBar+Tab height');
    return $result;
};

// ── Test 8: Banner + 视频网格 ──
$tests['Banner + 视频网格组合'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:964px;height:auto;gap:16px'], [
            VNode::h('div', ['style' => 'width:100%;height:200px;border-radius:8px'], 'Banner'),
            VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px'], [
                VNode::h('div', ['style' => 'display:flex;flex-direction:column'], [
                    VNode::h('div', ['style' => 'width:100%;height:140px;background:#ccc'], 'Cover'),
                    VNode::h('div', ['style' => 'padding:8px'], 'Title'),
                ]),
                VNode::h('div', ['style' => 'display:flex;flex-direction:column'], [
                    VNode::h('div', ['style' => 'width:100%;height:140px;background:#ccc'], 'Cover'),
                    VNode::h('div', ['style' => 'padding:8px'], 'Title'),
                ]),
            ])
        ])
    );
    assert_contains($result, 'div (0,0 964x200)', 'Banner explicit 964x200');
    return $result;
};

// ── Test 9: 侧边栏 + 主内容布局 ──
$tests['侧边栏 + 主内容 flex row'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:1440px;height:900px'], [
            VNode::h('div', ['style' => 'width:240px;height:100%;flex-shrink:0;padding:16px'], [
                VNode::h('div', ['style' => 'height:40px'], 'Nav 1'),
                VNode::h('div', ['style' => 'height:40px;margin-top:8px'], 'Nav 2'),
            ]),
            VNode::h('div', ['style' => 'flex:1;padding:16px;overflow-y:auto'], [
                VNode::h('div', ['style' => 'height:200px'], 'Main Content'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 240x900)', 'Sidebar flex-shrink=0 width=240px');
    return $result;
};

// ── Test 10: 全屏遮罩 + 居中弹窗 ──
$tests['全屏遮罩 + 居中弹窗 (fixed overlay)'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'position:relative;width:1440px;height:900px'], [
            VNode::h('div', ['style' => 'position:absolute;left:0;top:0;width:1440px;height:900px;background:rgba(0,0,0,0.5)'], ''),
            VNode::h('div', ['style' => 'position:absolute;left:50%;top:50%;width:400px;height:300px;margin-left:-200px;margin-top:-150px;background:#fff;border-radius:8px'], 'Modal'),
        ])
    );
    assert_contains($result, 'div (0,0 1440x900)', 'Overlay covers full relative container');
    return $result;
};

// ── Test 11: Grid + 内部 flex:1 链式填充 ──
$tests['grid + flex:1 链式填充'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:1fr 1fr;width:800px;height:300px;gap:16px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column'], [
                VNode::h('div', ['style' => 'height:40px;background:#f00'], 'Header'),
                VNode::h('div', ['style' => 'flex:1;background:#0f0'], 'Fill'),
            ]),
            VNode::h('div', ['style' => 'display:flex;flex-direction:column'], [
                VNode::h('div', ['style' => 'height:60px;background:#00f'], 'Header'),
                VNode::h('div', ['style' => 'flex:1;background:#ff0'], 'Fill'),
            ]),
        ])
    );
    assert_contains($result, 'div (0,0 800x300)', '2-col grid 800px gap=16 cols=392 each');
    return $result;
};

// ── Test 12: 多列响应式网格 + 不同卡片高度 ──
$tests['响应式网格 不同卡片高度 auto-fill'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));width:964px;gap:16px'], [
            VNode::h('div', ['style' => 'height:180px'], 'Tall'),
            VNode::h('div', ['style' => 'height:120px'], 'Medium'),
            VNode::h('div', ['style' => 'height:200px'], 'Taller'),
            VNode::h('div', ['style' => 'height:100px'], 'Short'),
        ])
    );
    assert_contains($result, 'div (0,0 964x316)', 'Responsive grid 964px auto-fill');
    return $result;
};

// ── Test 13: 嵌套 flex row > column > row ──
$tests['三层嵌套 flex row>column>row'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:row;width:800px;height:200px;gap:8px;padding:8px'], [
            VNode::h('div', ['style' => 'display:flex;flex-direction:column;flex:1;gap:4px'], [
                VNode::h('div', ['style' => 'display:flex;flex-direction:row;gap:4px'], [
                    VNode::h('div', ['style' => 'flex:1;height:40px'], 'A'),
                    VNode::h('div', ['style' => 'flex:1;height:40px'], 'B'),
                ]),
                VNode::h('div', ['style' => 'flex:1'], 'C'),
            ]),
            VNode::h('div', ['style' => 'width:200px'], 'Side'),
        ])
    );
    assert_contains($result, 'div (0,0 800x200)', '3-level nested flex 800x200');
    return $result;
};

// ── Test 14: 水平居中布局 (margin auto) ──
$tests['margin auto 水平居中'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:600px;height:auto;padding:16px'], [
            VNode::h('div', ['style' => 'width:300px;height:60px;margin:0 auto'], 'Centered'),
        ])
    );
    assert_contains($result, 'div (166,16 300x60)', 'margin:0 auto centers: (600-300)/2=166');
    return $result;
};

// ── Test 15: flex:1 列填充 + 内部滚动 ──
$tests['flex column flex:1 + overflow 填充'] = function() {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'display:flex;flex-direction:column;width:600px;height:400px'], [
            VNode::h('div', ['style' => 'height:50px;flex-shrink:0'], 'Header'),
            VNode::h('div', ['style' => 'flex:1;overflow-y:auto'], [
                VNode::h('div', ['style' => 'height:80px'], 'Item 1'),
                VNode::h('div', ['style' => 'height:80px'], 'Item 2'),
                VNode::h('div', ['style' => 'height:80px'], 'Item 3'),
            ]),
            VNode::h('div', ['style' => 'height:30px;flex-shrink:0'], 'Footer'),
        ])
    );
    assert_contains($result, 'div (0,50 600x320)', 'Scroll fill height=400-50-30=320px');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Level-06-Complex.snap';
run_css_tests('Level 6 - 复合布局场景', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
