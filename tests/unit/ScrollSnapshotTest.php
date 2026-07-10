<?php
/**
 * Scroll Snapshot Test — 使用 RenderNode Tree Snapshot 诊断滚动问题
 *
 * 构建含滚动容器的 RenderNode 树，通过 dumpRenderTree() 获取快照，
 * 程序化验证快照内容，检测坐标系/滚动偏移/ContentHeight 等问题。
 *
 * CSS 标准原则：
 * - left/top 仅对非 static 定位生效（static 忽略）
 * - scrollTop 仅当内容溢出时有效（contentHeight > containerHeight）
 * - 子节点有显式 width/height 时保持原值
 *
 * Usage: php tests/unit/ScrollSnapshotTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\RenderNode;
use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\LayoutOrchestrator;
use Px\Rendering\RenderTreeManager;

echo "========================================\n";
echo " 滚动渲染树快照测试\n";
echo "========================================\n\n";

// ============================================================
// Helper 函数
// ============================================================

function makeScrollNode(string $type, array $style, array $children = [], ?string $key = null): RenderNode
{
        $cs = !empty($style) ? new ComputedStyle($style) : null;
    $node = new RenderNode($type, $cs, null, $key);
    foreach ($children as $child) {
        $node->addChild($child);
    }
    return $node;
}

function makeRN(string $type, array $style = [], mixed $content = null, ?string $key = null): RenderNode
{
    return new RenderNode($type, $style, $content, $key);
}

/**
 * 从快照文本逐行检查所有行的坐标有效性。
 * 仅在非滚动容器上下文中检查负坐标。
 */
function checkCoordinateInvariants(string $snapshot): array
{
    $violations = [];
    $lines = explode("\n", $snapshot);

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;
        if (strpos($trimmed, 'Frame #') === 0) continue;

        // Only check non-scroll-context for negative coords
        $isScrollLine = strpos($trimmed, 'scroll') !== false && strpos($trimmed, 'st=') !== false;
        if ($isScrollLine) continue;

        if (preg_match('/\((-?\d+),(-?\d+)\s+(\d+)x(\d+)\)/', $trimmed, $m)) {
            $x = (int)$m[1];
            $y = (int)$m[2];
            if ($x < 0 || $y < 0) {
                $violations[] = "非滚动容器负坐标: {$trimmed}";
            }
        }
    }
    return $violations;
}

// ============================================================
// 测试 1: 基本垂直滚动容器 — auto-stack + contentHeight
// ============================================================
echo "--- 1. 基本垂直滚动容器 ---\n";

test('垂直滚动: auto-stack + contentHeight + auto-width', function () {
    $c1 = makeRN('div', ['height' => 30], null, 'A');
    $c2 = makeRN('div', ['height' => 50], null, 'B');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 200, 'height' => 100,
    ], [$c1, $c2]);
    $scroll->style['position'] = 'relative';
    $scroll->style['left'] = 10;
    $scroll->style['top'] = 20;
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $result = $resolver->layout($root);

    $sc = $result['scrollContainers'][0] ?? null;
    assert_not_null($sc, '应有 1 个 scroll container');

    assert_eq($sc->x, 10, 'scroll.x = left(10)');
    assert_eq($sc->y, 20, 'scroll.y = top(20)');
    assert_eq($sc->w, 200, 'scroll.w');
    assert_eq($sc->h, 100, 'scroll.h');
    assert_eq($c1->w, 200, 'c1 auto-width = scroll.w');
    assert_eq($c2->w, 200, 'c2 auto-width = scroll.w');

    // childOffsetY = scroll.y(20) + padding(0) - scrollTop(0) = 20
    // c1.y = stackY(20) + 0 = 20, c2.y = 20+30 = 50
    assert_eq($c1->y, 20, 'c1.y = scroll.y');
    assert_eq($c2->y, 50, 'c2.y = c1.y + c1.h');
    assert_eq($sc->contentHeight, 80, 'contentHeight = 30+50 = 80');
});

test('垂直滚动: dumpRenderTree 快照含滚动信息', function () {
    $c1 = makeRN('div', ['height' => 30], null, 'A');
    $c2 = makeRN('div', ['height' => 50], null, 'B');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 200, 'height' => 100,
    ], [$c1, $c2]);
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    $rtm = new RenderTreeManager();
    $snapshot = $rtm->dumpRenderTree($root, 1, [], 'normal');

    assert_contains($snapshot, 'Frame #1', '应包含帧号');
    assert_contains($snapshot, 'scroll', 'scroll container 快响应含 scroll 关键字');
    assert_contains($snapshot, 'ch=80', 'contentHeight=80');
    assert_contains($snapshot, 'st=0', 'scrollTop=0');

    $violations = checkCoordinateInvariants($snapshot);
    assert_eq(count($violations), 0, '不应有负坐标违规: ' . implode('; ', $violations));
});

// ============================================================
// 测试 2: 滚动偏移 — 需要内容溢出才能生效
// ============================================================
echo "\n--- 2. 滚动偏移 ---\n";

test('scrollTop 偏移：内容溢出时子节点上移', function () {
    $c1 = makeRN('div', ['height' => 40], null, 'A');
    $c2 = makeRN('div', ['height' => 100], null, 'B');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 200, 'height' => 100,
    ], [$c1, $c2]);
    $scroll->scrollTop = 30;
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    // A1 重构: 布局坐标不再包含 scrollTop 偏移，偏移在 VNodeRenderer 绘制层叠加
    // scrollTop=30, maxScroll=140-100=40, 30≤40 → 不clamp
    // childOffsetY = scroll.y(0) + 0 = 0
    // auto-stack: c1.y = 0, c2.y = 0+40=40
    assert_eq($c1->y, 0, 'c1.y = 0 (A1: scrollOffset 在绘制层)');
    assert_eq($c2->y, 40, 'c2.y = c1.y + c1.h = 40');
    assert_eq($scroll->scrollTop, 30, 'scrollTop 保持 30（未 clamp）');
    assert_eq($scroll->contentHeight, 140, 'contentHeight = 40+100');
});

test('scrollTop clamp：内容不溢出时 clamp 到 0', function () {
    $c1 = makeRN('div', ['height' => 30], null, 'A');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 200, 'height' => 100,
    ], [$c1]);
    $scroll->scrollTop = 200;
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    assert_eq($scroll->scrollTop, 0, 'scrollTop clamped to 0');
    // A1 重构: 布局坐标不包含 scrollOffset，clamp 不影响 child.y
    assert_eq($c1->y, 0, 'c1.y = 0 (布局坐标不变)');
});

test('scrollTop clamp：超出 maxScroll 但仍有溢出', function () {
    $c1 = makeRN('div', ['height' => 150], null, 'A');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 200, 'height' => 100,
    ], [$c1]);
    $scroll->scrollTop = 80;
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    // A1 重构: scrollTop clamp 后，子节点布局坐标不变
    assert_eq($scroll->scrollTop, 50, 'scrollTop clamped to maxScroll=50');
    assert_eq($c1->y, 0, 'c1.y = 0 (布局坐标不变)');
});

// ============================================================
// 测试 3: 滚动容器内的 padding
// ============================================================
echo "\n--- 3. 滚动容器 + Padding ---\n";

test('padding 影响 childOffsetY 和 auto-width', function () {
    $c1 = makeRN('div', ['height' => 30], null, 'A');
    $c2 = makeRN('div', ['height' => 50], null, 'B');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'paddingTop' => 10, 'paddingLeft' => 20,
        'width' => 200, 'height' => 100,
    ], [$c1, $c2]);
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    // childOffsetY = scroll.y(0) + paddingTop(10) - scrollTop(0) = 10
    assert_eq($c1->y, 10, 'paddingTop=10 → c1.y=10');
    assert_eq($c2->y, 40, 'c2.y = 10+30 = 40');

    // auto-width: containerW = scroll.w(200) (content-box, width IS content width)
    assert_eq($c1->w, 200, 'auto-width=scroll.w=200');

    // contentHeight = paddingTop(10) + children(80) = 90
    assert_eq($scroll->contentHeight, 90, 'contentHeight=padding+children');
});

test('padding + scrollTop 叠加（内容溢出）', function () {
    $c1 = makeRN('div', ['height' => 120], null, 'A');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'paddingTop' => 10,
        'width' => 200, 'height' => 100,
    ], [$c1]);
    $scroll->scrollTop = 20;
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    // A1 重构: 布局坐标不包含 scrollTop 偏移
    // childOffsetY = 0 + 10 = 10 (no scroll subtraction)
    // maxScroll = contentH(130) - h(100) = 30 > 20 → 不clamp
    assert_eq($c1->y, 10, 'paddingTop=10 → c1.y=10 (布局坐标)');
    assert_eq($scroll->scrollTop, 20, 'scrollTop 未 clamp');
});

// ============================================================
// 测试 4: 水平滚动
// ============================================================
echo "\n--- 4. 水平滚动 ---\n";

test('横向滚动: overflow-x:auto → contentWidth', function () {
    $wideChild = makeRN('div', ['width' => 500, 'height' => 50], null, 'wide');
    $scroll = makeScrollNode('div', [
        'overflowX' => 'auto',
        'width' => 200, 'height' => 100,
    ], [$wideChild]);
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    assert_true($scroll->isScrollContainer, 'overflow-x:auto → isScrollContainer=true');
    assert_eq($scroll->contentWidth, 500, 'contentWidth = child.w(500)');
    assert_eq($scroll->contentHeight, 50, 'contentHeight = child.h(50)');
});

test('横向滚动: scrollLeft 偏移', function () {
    $wideChild = makeRN('div', ['width' => 500, 'height' => 50], null, 'wide');
    $scroll = makeScrollNode('div', [
        'overflowX' => 'auto',
        'width' => 200, 'height' => 100,
    ], [$wideChild]);
    $scroll->scrollLeft = 100;
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    // A1 重构: 布局坐标不包含 scrollLeft 偏移
    assert_eq($wideChild->x, 0, 'scrollLeft=100 → child.x=0 (A1: 布局坐标不变)');
});

// ============================================================
// 测试 5: 双向滚动 (overflow:auto 同时两轴)
// ============================================================
echo "\n--- 5. 双向滚动 ---\n";

test('overflow:auto — 垂直+水平同时激活', function () {
    $child = makeRN('div', ['width' => 500, 'height' => 300], null, 'big');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 200, 'height' => 100,
    ], [$child]);
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    assert_true($scroll->isScrollContainer, 'overflow:auto → isScrollContainer=true');
    assert_eq($scroll->contentWidth, 500, 'contentWidth=500');
    assert_eq($scroll->contentHeight, 300, 'contentHeight=300');
});

// ============================================================
// 测试 6: 多个滚动容器（block auto-stack）
// ============================================================
echo "\n--- 6. 多个滚动容器 ---\n";

test('两个独立滚动容器垂直堆叠', function () {
    $c1a = makeRN('div', ['height' => 30], null, 'A1');
    $c1b = makeRN('div', ['height' => 40], null, 'A2');
    $sc1 = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 150, 'height' => 100,
    ], [$c1a, $c1b]);

    $c2a = makeRN('div', ['height' => 50], null, 'B1');
    $sc2 = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 150, 'height' => 100,
    ], [$c2a]);

    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($sc1);
    $root->addChild($sc2);

    $orchestrator = new LayoutOrchestrator();
    $result = $resolver->layout($root);

    assert_eq(count($result['scrollContainers']), 2, '应有 2 个 scroll containers');

    // block auto-stack: sc1 at y=0, sc2 below sc1
    assert_eq($sc1->y, 0, 'SC1.y = 0 (auto-stack start)');
    assert_eq($sc2->y, 100, 'SC2.y = SC1.y + SC1.h = 100');

    // 各自子节点独立 auto-stack
    assert_eq($c1a->y, 0, 'SC1 c1a.y = childOffsetY = sc1.y(0)');
    assert_eq($c1b->y, 30, 'SC1 c1b.y = 30 (auto-stack)');
    assert_eq($c2a->y, 100, 'SC2 c2a.y = sc2.y(100)');
});

test('两个滚动容器用 relative 并排', function () {
    $sc1 = makeScrollNode('div', [
        'position' => 'relative', 'left' => 0,
        'overflow' => 'auto',
        'width' => 150, 'height' => 100,
    ], [makeRN('div', ['height' => 30], null, 'A')]);

    $sc2 = makeScrollNode('div', [
        'position' => 'relative', 'left' => 200,
        'overflow' => 'auto',
        'width' => 150, 'height' => 100,
    ], [makeRN('div', ['height' => 50], null, 'B')]);

    $root = makeRN('div', ['display' => 'flex', 'width' => 400, 'height' => 400], null);
    $root->addChild($sc1);
    $root->addChild($sc2);

    $orchestrator = new LayoutOrchestrator();
    $result = $resolver->layout($root);

    assert_eq(count($result['scrollContainers']), 2, '应有 2 个 scroll containers');
    assert_true($sc2->x > $sc1->x, 'SC2 在 SC1 右侧 (flex)');
});

// ============================================================
// 测试 7: 快照详情级别
// ============================================================
echo "\n--- 7. 快照详情级别 ---\n";

test('minimal 级别仅包含坐标和 scroll 摘要', function () {
    $c1 = makeRN('div', ['height' => 30], 'hello', 'A');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 200, 'height' => 100,
    ], [$c1]);
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    $rtm = new RenderTreeManager();
    $snapshot = $rtm->dumpRenderTree($root, 1, [], 'minimal');

    assert_contains($snapshot, 'scroll', 'minimal 也应有 scroll 标记');
    assert_true(strpos($snapshot, 'ch=') === false, 'minimal 不应包含 contentHeight');
    assert_contains($snapshot, 'st=0', 'minimal 应有 scrollTop');
    assert_true(strpos($snapshot, '[dsp=') === false, 'minimal 不应包含 display');
});

test('normal 级别包含 style/overflow/scroll 详情', function () {
    $c1 = makeRN('div', [
        'display' => 'flex', 'position' => 'relative',
        'width' => 100, 'height' => 50,
    ], null, 'A');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto', 'display' => 'block',
        'width' => 200, 'height' => 100,
    ], [$c1]);
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    $rtm = new RenderTreeManager();
    $snapshot = $rtm->dumpRenderTree($root, 1, [], 'normal');

    assert_contains($snapshot, 'ch=');
    assert_contains($snapshot, 'maxScroll=');
    assert_contains($snapshot, '[dsp=flex pos=relative]');
});

test('verbose 级别包含 layer/gid', function () {
    $c1 = makeRN('div', ['width' => 100, 'height' => 30], null, 'A');
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($c1);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    $rtm = new RenderTreeManager();
    $snapshot = $rtm->dumpRenderTree($root, 1, [], 'verbose');

    assert_contains($snapshot, 'layer=', 'verbose 应包含 layer');
});

// ============================================================
// 测试 8: 快照事件缓冲区
// ============================================================
echo "\n--- 8. 快照事件缓冲区 ---\n";

test('dumpRenderTree 包含事件计数和 detail 级别', function () {
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $rtm = new RenderTreeManager();
    $events = [
        ['frame' => 1, 'type' => 'mouse:wheel', 'time' => 1000, 'x' => 100, 'y' => 50, 'detail' => ''],
        ['frame' => 1, 'type' => 'mouse:down', 'time' => 1001, 'x' => 100, 'y' => 50, 'detail' => ''],
    ];
    $snapshot = $rtm->dumpRenderTree($root, 5, $events, 'normal');
    assert_contains($snapshot, 'events=2', '应显示 2 个事件');
    assert_contains($snapshot, 'detail=normal', '应显示 normal 级别');
});

// ============================================================
// 测试 9: 完整集成 — 多元素布局+滚动容器
// ============================================================
echo "\n--- 9. 快照完整集成 ---\n";

test('全量布局 + 滚动容器 + 快照正确性', function () {
    $items = [];
    for ($i = 1; $i <= 5; $i++) {
        $items[] = makeRN('div', ['height' => 40], "Item {$i}", "i{$i}");
    }

    $scrollArea = makeScrollNode('div', [
        'position' => 'relative', 'left' => 10,
        'overflow' => 'auto',
        'width' => 220, 'height' => 200,
        'paddingTop' => 8, 'paddingLeft' => 10,
    ], $items);

    $header = makeRN('div', ['position' => 'relative', 'width' => 400, 'height' => 60], 'Header');
    $footer = makeRN('div', ['position' => 'relative', 'width' => 400, 'height' => 30], 'Footer');

    $root = makeRN('div', ['width' => 400, 'height' => 500], null);
    $root->addChild($header);
    $root->addChild($scrollArea);
    $root->addChild($footer);

    $orchestrator = new LayoutOrchestrator();
    $result = $resolver->layout($root);

    assert_eq($header->y, 0, 'header 在顶部');
    assert_eq($scrollArea->y, 60, 'scrollArea.y = header.h(60)');
    assert_eq($footer->y, 268, 'footer 在 scrollArea 下方 (scrollArea visualH=200+paddingTop8)');

    $sc = $result['scrollContainers'][0] ?? null;
    assert_not_null($sc, '应有 scroll container');
    assert_eq($sc->contentHeight, 208, 'scrollArea contentHeight = 208');

    assert_eq($items[0]->y, 68, 'item1.y = scrollArea.y + paddingTop = 68');

    $rtm = new RenderTreeManager();
    $snapshot = $rtm->dumpRenderTree($root, 42, [], 'normal');

    assert_contains($snapshot, 'Item 1', '快照应包含 Item 1 文本');
    assert_contains($snapshot, 'ch=208', 'contentHeight 208');
    assert_contains($snapshot, 'st=0', 'scrollTop=0');

    $violations = checkCoordinateInvariants($snapshot);
    assert_eq(count($violations), 0, '非滚动上下文不应有负坐标: ' . implode('; ', $violations));
});

// ============================================================
// 测试 10: 滚动偏移导致负坐标是预期行为
// ============================================================
echo "\n--- 10. 滚动偏移导致负坐标 — 预期行为 ---\n";

test('scrollTop 后子节点 y 为负值（内容溢出时）', function () {
    $item = makeRN('div', ['height' => 300], 'Scrolled Item', 's1');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 220, 'height' => 100,
    ], [$item]);
    $scroll->scrollTop = 30;
    $root = makeRN('div', ['width' => 400, 'height' => 500], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    // A1 重构: 子节点 y 不再为负（布局坐标不变，偏移在绘制层）
    assert_eq($item->y, 0, 'A1: 布局坐标不变, scrollOffset 在绘制层应用: ' . $item->y);

    $rtm = new RenderTreeManager();
    $snapshot = $rtm->dumpRenderTree($root, 99, [], 'normal');
    assert_contains($snapshot, 'st=30', 'scrollTop=30');
});

// ============================================================
// 测试 11: BUG DETECTION — 用快照发现实际滚动 Bug
// ============================================================
echo "\n--- 11. Bug Detection: 快照发现 Bilibili 滚动问题 ---\n";

test('BUG DETECTION: 快照内容与布局一致', function () {
    $video1 = makeRN('div', [
        'position' => 'relative',
        'width' => 180, 'height' => 120,
    ], 'Video 1', 'v1');
    $video2 = makeRN('div', [
        'position' => 'relative',
        'width' => 180, 'height' => 120,
    ], 'Video 2', 'v2');

    $scrollList = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 200, 'height' => 300,
        'paddingTop' => 4,
    ], [$video1, $video2]);

    $root = makeRN('div', ['width' => 400, 'height' => 600], null);
    $root->addChild($scrollList);

    $orchestrator = new LayoutOrchestrator();
    $result = $resolver->layout($root);

    $sc = $result['scrollContainers'][0] ?? null;
    assert_not_null($sc, '应有 scroll container');

    assert_eq($video1->y, 4, 'video1.y = scrollList.y + paddingTop');
    assert_eq($sc->contentHeight, 244, 'contentHeight = 4+120+120');

    $rtm = new RenderTreeManager();
    $snapshot = $rtm->dumpRenderTree($root, 100, [], 'normal');

    assert_contains($snapshot, 'Video 1');
    assert_contains($snapshot, 'st=0');
    assert_contains($snapshot, 'ch=244');

    $violations = checkCoordinateInvariants($snapshot);
    assert_eq(count($violations), 0, '不应有负坐标违规');
});

// ============================================================
// 测试 12: 显式 width 的子节点
// ============================================================
echo "\n--- 12. 显式尺寸子节点 ---\n";

test('显式 width 的子节点不被 auto-width 覆盖', function () {
    $c1 = makeRN('div', ['width' => 150, 'height' => 30], null, 'A');
    $scroll = makeScrollNode('div', [
        'overflow' => 'auto',
        'width' => 200, 'height' => 100,
    ], [$c1]);
    $root = makeRN('div', ['width' => 400, 'height' => 400], null);
    $root->addChild($scroll);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    assert_eq($c1->w, 150, '显式 width=150 保持不变');
    assert_eq($c1->y, 0, 'c1.y = childOffsetY');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
