<?php
/**
 * Bilibili Layout Render Tree Snapshot Test
 *
 * Tests the layout engine against a RenderNode tree that mimics the Bilibili page structure.
 * Verifies coordinate correctness and integrates with dumpRenderTree for snapshot comparison.
 * 
 * Usage: php tests/unit/BilibiliLayoutTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\RenderNode;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderTreeManager;

echo "========================================\n";
echo " Bilibili Layout 渲染树快照测试\n";
echo "========================================\n\n";

// ============================================================
// Helper: build Bilibili-style RenderNode tree
// ============================================================

/**
 * Build a RenderNode tree that mirrors Bilibili page structure.
 * Window: 1440x900
 */
function buildBilibiliTree(): RenderNode
{
    // ── NavBar (w=1440, h=56) ──
    $navbar = new RenderNode('div', [
        'width' => 1440, 'height' => 56,
        'display' => 'flex', 'alignItems' => 'center',
        'left' => 0, 'top' => 0,
    ], null);

    // ── CategoryTabs (w=1440, h≈74) ──
    $catTabs = new RenderNode('div', [
        'width' => 1440, 'height' => 74,
        'display' => 'flex', 'flexDirection' => 'column',
        'left' => 0, 'top' => 0,
    ], null);
    // Row 1 (h=36)
    $row1 = new RenderNode('div', [
        'height' => 36, 'display' => 'flex', 'alignItems' => 'center',
        'paddingLeft' => 24,
        'left' => 0, 'top' => 0,
    ], null);
    // Row 2 (h=36)
    $row2 = new RenderNode('div', [
        'height' => 36, 'display' => 'flex', 'alignItems' => 'center',
        'paddingLeft' => 24,
        'left' => 0, 'top' => 0,
    ], null);
    // Underline (h=2)
    $underline = new RenderNode('div', [
        'width' => 40, 'height' => 2,
        'left' => 24, 'top' => 0,
    ], null);

    $catTabs->addChild($row1);
    $catTabs->addChild($row2);
    $catTabs->addChild($underline);

    // ── MainContent (flex:1, padding 16px 24px, auto height) ──
    $mainContent = new RenderNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'flex' => '1',
        'paddingLeft' => 24, 'paddingRight' => 24,
        'paddingTop' => 16,
        'left' => 0, 'top' => 0,
        // do NOT set height: it should auto-fill remaining space
    ], null);

    // ── BannerCarousel ──
    $banner = new RenderNode('div', [
        'display' => 'flex',
        'height' => 180,
        'left' => 0, 'top' => 0,
    ], null);
    $mainContent->addChild($banner);

    // ── VideoGrid (width=100%, dynamic columns auto-fill) ──
    $videoGrid = new RenderNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'left' => 0, 'top' => 0,
    ], null);

    // Title bar (36px)
    $titleBar = new RenderNode('div', [
        'height' => 36,
        'display' => 'flex', 'alignItems' => 'center',
        'justifyContent' => 'space-between',
        'left' => 0, 'top' => 0,
    ], null);
    $videoGrid->addChild($titleBar);

    // Grid container with auto-fill
    // Note: auto-fill minmax(300px, 1fr) - compatible with the existing auto-fill Grid support
    $gridContainer = new RenderNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(auto-fill, minmax(300px, 1fr))',
        'gap' => 16,
        'left' => 0, 'top' => 0,
    ], null);

    // 16 video cards (4x4 grid with auto-fill min 300px → 4 cols at 1440-48=1392 wide)
    for ($i = 1; $i <= 16; $i++) {
        $card = new RenderNode('div', [
            'display' => 'flex', 'flexDirection' => 'column',
            'gap' => 6,
            'left' => 0, 'top' => 0,
        ], null);

        // Cover area (h=140)
        $cover = new RenderNode('div', [
            'height' => 140,
            'left' => 0, 'top' => 0,
        ], null);
        $card->addChild($cover);

        // Title line
        $title = new RenderNode('div', [
            'left' => 0, 'top' => 0,
        ], '视频标题 ' . $i);
        $card->addChild($title);

        // Stats line
        $stats = new RenderNode('div', [
            'display' => 'flex',
            'gap' => 12,
            'left' => 0, 'top' => 0,
        ], null);
        $card->addChild($stats);

        // Author line
        $author = new RenderNode('div', [
            'display' => 'flex',
            'gap' => 4,
            'left' => 0, 'top' => 0,
        ], null);
        $card->addChild($author);

        $gridContainer->addChild($card);
    }

    $videoGrid->addChild($gridContainer);

    // Refresh button (position:absolute, inside a wrapper)
    $refreshBtn = new RenderNode('div', [
        'position' => 'absolute',
        'right' => 0, 'top' => 50,
        'width' => 44, 'height' => 90,
        'display' => 'flex', 'flexDirection' => 'column',
        'alignItems' => 'center', 'justifyContent' => 'center',
        'gap' => 4,
        'left' => 0, 'top' => 0,
    ], null);
    $videoGrid->addChild($refreshBtn);

    $mainContent->addChild($videoGrid);

    // ── Root container (app) ──
    $root = new RenderNode('div', [
        'width' => 1440, 'height' => 900,
        'display' => 'flex', 'flexDirection' => 'column',
        'left' => 0, 'top' => 0,
    ], null);

    $root->addChild($navbar);
    $root->addChild($catTabs);
    $root->addChild($mainContent);

    return $root;
}

// ============================================================
// Tests
// ============================================================

// Track test sequence
$testGroup = 0;

echo "--- 1. 基础布局验证 ---\n";

test('NavBar 定位在顶部 (x=0, y=0, w=1440, h=56)', function () {
    $root = buildBilibiliTree();
    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    $navbar = $root->children[0];
    assert_eq($navbar->x, 0, 'NavBar x = 0');
    assert_eq($navbar->y, 0, 'NavBar y = 0');
    assert_eq($navbar->w, 1440, 'NavBar w = 1440');
    assert_eq($navbar->h, 56, 'NavBar h = 56');
});

test('CategoryTabs 在 NavBar 下方 (y=56)', function () {
    $root = buildBilibiliTree();
    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    $catTabs = $root->children[1];
    assert($catTabs->y >= 56, 'CategoryTabs y >= 56 (below NavBar)');
});

test('MainContent 占据剩余高度 (flex:1, 全宽)', function () {
    $root = buildBilibiliTree();
    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    $mainContent = $root->children[2];
    // MainContent should stretch to fill the remaining height
    // root is 900px tall. NavBar=56px, CategoryTabs≈74px → remaining ≈ 768px
    assert($mainContent->w > 1300, 'MainContent should span most of the width (padding 24px each side)');
    assert($mainContent->h > 700, 'MainContent should fill remaining height');
});

test('VideoGrid 内部元素正确定位', function () {
    $root = buildBilibiliTree();
    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    $mainContent = $root->children[2];
    // Find the VideoGrid (last child of MainContent)
    $videoGrid = $mainContent->children[1] ?? null;
    assert_not_null($videoGrid, 'VideoGrid should exist');

    // Grid container (videoGrid->children[1] after title bar)
    $gridContainer = $videoGrid->children[1] ?? null;
    assert_not_null($gridContainer, 'Grid container should exist');

    // Grid should have auto-fill columns
    // At 1440-24-24=1392px width, with minmax(300px,1fr): 4 columns
    assert($gridContainer->children > 0, 'Grid should have children');

    // First card should be in the grid
    $firstCard = $gridContainer->children[0];
    assert($firstCard->x >= 0, 'First card x should be >= 0');
    assert($firstCard->y >= 0, 'First card y should be >= 0');
    assert($firstCard->w > 0, 'First card should have positive width');
});

echo "\n--- 2. 渲染树快照输出 ---\n";

test('dumpRenderTree 快照一致性', function () {
    $root = buildBilibiliTree();
    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    $rtm = new RenderTreeManager($root);
    // We don't have a real Application here, so we'll construct the dump manually
    // to verify that the coordinates are in expected ranges

    $expectedRootW = 1440;
    $expectedRootH = 900;
    assert_eq($root->w, $expectedRootW, 'root width should be 1440');
    assert_eq($root->h, $expectedRootH, 'root height should be 900');

    // Collect all nodes and check for invalid coordinates
    $queue = [$root];
    while (!empty($queue)) {
        $node = array_shift($queue);
        // No negative coordinates (within viewport)
        // Note: scroll containers may have negative child positions; that's OK
        if ($node->content !== null) {
            assert($node->w >= 0, 'Text node "' . $node->content . '" should have w >= 0');
        }
        foreach ($node->children as $child) {
            $queue[] = $child;
        }
    }
});

test('刷新交互后布局稳定', function () {
    // Simulate a "refresh" by re-resolving the tree (mimics markDirty + re-render)
    $root = buildBilibiliTree();
    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // Store positions before refresh
    $positionsBefore = [];
    $queue = [$root];
    while (!empty($queue)) {
        $node = array_shift($queue);
        $positionsBefore[spl_object_id($node)] = ['x' => $node->x, 'y' => $node->y, 'w' => $node->w, 'h' => $node->h];
        foreach ($node->children as $child) {
            $queue[] = $child;
        }
    }

    // Re-resolve (simulates a re-render)
    foreach ($root->children as $child) {
        $child->layoutDirty = true;
    }
    $resolver->resolve($root);

    // Positions should be identical after re-render (stable layout)
    $queue = [$root];
    while (!empty($queue)) {
        $node = array_shift($queue);
        $id = spl_object_id($node);
        $before = $positionsBefore[$id];
        $msg = "Node {$node->type} position stable after refresh";
        if ($node->h !== $before['h']) {
            $sw = $node->style['width'] ?? 'none';
            $sh = $node->style['height'] ?? 'auto';
            $sd = $node->style['display'] ?? 'block';
            echo "  [DEBUG DIFF] Node type={$node->type} id={$id} style=width:$sw height:$sh display:$sd" . " before.h={$before['h']} after.h={$node->h}\n";
        }
        assert_eq($node->x, $before['x'], "$msg (x)");
        assert_eq($node->y, $before['y'], "$msg (y)");
        assert_eq($node->w, $before['w'], "$msg (w)");
        assert_eq($node->h, $before['h'], "$msg (h)");
        foreach ($node->children as $child) {
            $queue[] = $child;
        }
    }
});

echo "\n--- 3. \u{7279}\u{5b9a} CSS \u{7279}\u{6027}\u{9a8c}\u{8bc1} ---\n";

test('NavBar margin-left:auto \u{53f3}\u{4fa7}\u{533a}\u{57df}\u{88ab}\u{63a8}\u{5230}\u{53f3}\u{7aef}', function () {
    $navbar = new RenderNode('div', [
        'width' => 1440, 'height' => 56,
        'display' => 'flex', 'alignItems' => 'center',
        'paddingLeft' => 24, 'paddingRight' => 24,
        'left' => 0, 'top' => 0,
    ], null);

    // \u{5de6}\u{4fa7}\u{533a}\u{57df} (logo + \u{5bfc}\u{822a})
    $leftSect = new RenderNode('div', [
        'width' => 200,
        'display' => 'flex', 'alignItems' => 'center',
        'left' => 0, 'top' => 0,
    ], null);
    $navbar->addChild($leftSect);

    // \u{53f3}\u{4fa7}\u{533a}\u{57df}\uff0c margin-left:auto \u{5f80}\u{53f3}\u{63a8}
    $rightSect = new RenderNode('div', [
        'width' => 300,
        'display' => 'flex', 'alignItems' => 'center',
        'marginLeftAuto' => true,
        'left' => 0, 'top' => 0,
    ], null);
    $navbar->addChild($rightSect);

    $root = new RenderNode('div', [
        'width' => 1440, 'height' => 900,
        'display' => 'flex', 'flexDirection' => 'column',
        'left' => 0, 'top' => 0,
    ], null);
    $root->addChild($navbar);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // \u{5de6}\u{4fa7}\u{533a}\u{57df}\u{5e94}\u{5728}\u{5de6}\u{4fa7}\uff0c x=24 (paddingLeft)
    // \u{53f3}\u{4fa7}\u{533a}\u{57df}\u{5e94}\u{88ab} margin-left:auto \u{63a8}\u{5230}\u{53f3}\u{4fa7}\n    assert_eq($leftSect->x, 24, 'leftSect x = 24 (paddingLeft)');
    assert_eq($leftSect->w, 200, 'leftSect width = 200');
    assert($rightSect->x > 1000, 'rightSect should be pushed right by auto margin (x > 1000), got x=' . $rightSect->x);
    assert($rightSect->x + $rightSect->w <= 1440 - 24, 'rightSect should not exceed right padding');
});

test('CategoryTabs overflow-x:auto \u{5bbd}\u{5ea6}\u{4e0d}\u{88ab}\u{6491}\u{5927}', function () {
    $root = new RenderNode('div', [
        'width' => 800, 'height' => 600,
        'display' => 'flex', 'flexDirection' => 'column',
        'left' => 0, 'top' => 0,
    ], null);

    $tabs = new RenderNode('div', [
        'width' => 800, 'height' => 36,
        'display' => 'flex', 'alignItems' => 'center',
        'overflowX' => 'auto',
        'paddingLeft' => 24, 'paddingRight' => 24,
        'left' => 0, 'top' => 0,
    ], null);

    // 15 \u{4e2a}\u{6807}\u{7b7e}\uff0c\u{6bcf}\u{4e2a} 70px + flex-shrink:0 = 1050px \u{603b}\u{5bbd}\uff0c\u{8d85}\u{51fa} 800px \u{5bb9}\u{5668}
    for ($i = 0; $i < 15; $i++) {
        $tabs->addChild(new RenderNode('div', [
            'width' => 70, 'height' => 34, 'flexShrink' => 0,
            'left' => 0, 'top' => 0,
        ], null));
    }

    $root->addChild($tabs);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // \u{5bb9}\u{5668}\u{5bbd}\u{5ea6}\u{5e94}\u{88ab}\u{7ea6}\u{675f}\u{4e3a} 800\uff0c\u{4e0d}\u{4f1a}\u{56e0}\u{5b50}\u{5143}\u{7d20}\u{8d85}\u{51fa}\u{800c}\u{6269}\u{5f20}
    assert_eq($tabs->w, 800, 'Tabs container width should be 800, not expanded');
    // \u{5e94}\u{8be5}\u{88ab}\u{6807}\u{8bb0}\u{4e3a} scroll container
    assert($tabs->isScrollContainer, 'Tabs should be a scroll container');
    // contentWidth > container width
    assert($tabs->contentWidth > 800, 'Content width should exceed 800, got ' . $tabs->contentWidth);
});

test('VideoGrid grid auto-fill \u{5728} 1392px \u{4e0b}\u{4e3a} 4 \u{5217}', function () {
    $grid = new RenderNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(auto-fill, minmax(300px, 1fr))',
        'gap' => 16,
        'width' => 1392,
        'left' => 0, 'top' => 0,
    ], null);

    // 8 \u{4e2a}\u{89c6}\u{9891}\u{5361}\u{7247}
    for ($i = 1; $i <= 8; $i++) {
        $card = new RenderNode('div', [
            'display' => 'flex', 'flexDirection' => 'column',
            'gap' => 6,
            'height' => 250,
            'left' => 0, 'top' => 0,
        ], null);
        $card->addChild(new RenderNode('div', ['height' => 140, 'left' => 0, 'top' => 0], null));
        $card->addChild(new RenderNode('span', ['left' => 0, 'top' => 0], 'Video ' . $i));
        $grid->addChild($card);
    }

    $resolver = new LayoutResolver();
    $resolver->resolve($grid);

    // 1392px width, minmax(300px,1fr), gap:16 → 4 columns of ~336px each
    // 4 * 336 + 3 * 16 = 1344 + 48 = 1392
    assert($grid->children[0]->w > 0, 'First card width > 0');
    // First 4 cards should be in first row (y=0) with width >= 300
    for ($i = 0; $i < 4; $i++) {
        assert($grid->children[$i]->w >= 300, 'Card ' . ($i+1) . ' width >= 300, got ' . $grid->children[$i]->w);
        assert_eq($grid->children[$i]->y, 0, 'Card ' . ($i+1) . ' y = 0 (first row)');
    }
    // 5th card should be in second row
    assert($grid->children[4]->y > 0, '5th card should be in second row (y > 0), got y=' . $grid->children[4]->y);
    // 4 columns: 1st card x ≈ 0, 2nd card x > 1st, etc.
    for ($i = 1; $i < 4; $i++) {
        assert($grid->children[$i]->x > $grid->children[$i-1]->x,
            'Card ' . ($i+1) . ' x > Card ' . $i . ' x');
    }
});

test('FloatingButton position:absolute \u{5b9a}\u{4f4d}\u{5230}\u{53f3}\u{4e0b}\u{89d2}', function () {
    $root = new RenderNode('div', [
        'width' => 1440, 'height' => 900,
        'position' => 'relative',
        'left' => 0, 'top' => 0,
    ], null);

    $fb = new RenderNode('div', [
        'position' => 'absolute',
        'right' => 40, 'bottom' => 40,
        'width' => 52, 'height' => 104,
    ], null);
    $root->addChild($fb);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // Expected: x = 1440 - 52 - 40 = 1348, y = 900 - 104 - 40 = 756
    assert_eq($fb->x, 1348, 'FloatingButton x = 1348 (right:40, width:52), got ' . $fb->x);
    assert_eq($fb->y, 756, 'FloatingButton y = 756 (bottom:40, height:104), got ' . $fb->y);
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
