<?php
/**
 * GridDiagTest — Grid布局诊断测试
 * 
 * 验证 CSS Grid auto-fill 布局对子元素的处理
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

use Px\Rendering\RenderNode;

echo "========================================\n";
echo " Grid 布局诊断测试\n";
echo "========================================\n\n";

// ============================================================
// Test 1: Grid auto-fill 基本布局
// ============================================================
echo "--- Test 1: Grid auto-fill 基本布局 ---\n";

test('Grid auto-fill 计算列数和单元格宽度', function () {
    $card1 = makeNode('div', ['height' => 140], [], 'card1');
    $card2 = makeNode('div', ['height' => 140], [], 'card2');
    $card3 = makeNode('div', ['height' => 140], [], 'card3');
    $card4 = makeNode('div', ['height' => 140], [], 'card4');
    $card5 = makeNode('div', ['height' => 140], [], 'card5');
    
    $root = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(auto-fill, minmax(300px, 1fr))',
        'gap' => 16,
        'width' => 1392,
        'height' => 'auto',
    ], [$card1, $card2, $card3, $card4, $card5]);
    
    runResolver($root);
    
    echo "  grid root: x={$root->x} y={$root->y} w={$root->w} h={$root->h}\n";
    echo "  children count: " . count($root->children) . "\n";
    
    // Check: should have 4 columns (1392/(300+16) = ~4)
    // First child should have correct width and x
    if (count($root->children) > 0) {
        $c0 = $root->children[0];
        echo "  child[0]: type={$c0->type} x={$c0->x} y={$c0->y} w={$c0->w} h={$c0->h}\n";
        echo "  child[0] children count: " . count($c0->children) . "\n";
        
        assert_true($c0->w > 0, 'child[0] width should be > 0, got ' . $c0->w);
        assert_true($c0->h > 0, 'child[0] height should be > 0 (has explicit height=140), got ' . $c0->h);
    }
    if (count($root->children) > 1) {
        $c1 = $root->children[1];
        echo "  child[1]: type={$c1->type} x={$c1->x} y={$c1->y} w={$c1->w} h={$c1->h}\n";
    }
    if (count($root->children) > 4) {
        $c4 = $root->children[4];
        echo "  child[4]: type={$c4->type} x={$c4->x} y={$c4->y} w={$c4->w} h={$c4->h}\n";
        assert_true($c4->h > 0, 'child[4] height should be > 0 (has explicit height=140), got ' . $c4->h);
    }
    
    echo "  grid root h: {$root->h} (should be > 0 since children have heights)\n";
    assert_true($root->h > 0, 'grid root height should be > 0 when children have heights, got ' . $root->h);
});

// ============================================================
// Test 2: Grid 中 flex column 子节点 (模拟视频卡片)
// ============================================================
echo "\n--- Test 2: Grid + flex column 子节点 (模拟视频卡片) ---\n";

test('Grid 中 flex column 子节点能正确获得高度', function () {
    // 模拟视频卡片: flex column 容器，包含固定高度封面区
    $cover = makeNode('div', ['height' => 140], [], 'cover');
    $title = makeNode('span', ['fontSize' => 14], [], '测试视频标题');
    $meta  = makeNode('span', ['fontSize' => 12], [], '▶ 100万播放');
    
    $card = makeNode('div', [
        'display' => 'flex',
        'flexDirection' => 'column',
        'width' => '100%',
        'height' => 'auto',
    ], [$cover, $title, $meta]);
    
    $root = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(auto-fill, minmax(300px, 1fr))',
        'gap' => 16,
        'width' => 1392,
        'height' => 'auto',
    ], [$card]);
    
    runResolver($root);
    
    echo "  grid root: x={$root->x} y={$root->y} w={$root->w} h={$root->h}\n";
    
    if (count($root->children) > 0) {
        $c0 = $root->children[0];
        echo "  child[0] (card): type={$c0->type} x={$c0->x} y={$c0->y} w={$c0->w} h={$c0->h}\n";
        echo "  child[0] children count: " . count($c0->children) . "\n";
        
        foreach ($c0->children as $i => $ch) {
            echo "    child[0].children[$i]: type={$ch->type} x={$ch->x} y={$ch->y} w={$ch->w} h={$ch->h}\n";
        }
        
        assert_true($c0->w > 0, 'card width should be > 0, got ' . $c0->w);
        // Cover has explicit height=140, so card should have at least 140
        assert_true($c0->h >= 140, 'card height should be >= 140 (cover height), got ' . $c0->h);
    }
    
    assert_true($root->h > 0, 'grid root height should be > 0, got ' . $root->h);
});

// ============================================================
// Test 3: Grid 中 flex column 子节点 - 多列
// ============================================================
echo "\n--- Test 3: Grid + flex column 子节点(多列) ---\n";

test('Grid 多列 flex column 子节点全部有正确高度', function () {
    $cards = [];
    for ($i = 0; $i < 8; $i++) {
        $cover = makeNode('div', ['height' => 140], [], 'cover_' . $i);
        $title = makeNode('span', ['fontSize' => 14], [], '视频标题_' . $i);
        $card = makeNode('div', [
            'display' => 'flex',
            'flexDirection' => 'column',
            'width' => '100%',
            'height' => 'auto',
        ], [$cover, $title]);
        $cards[] = $card;
    }
    
    $root = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(auto-fill, minmax(300px, 1fr))',
        'gap' => 16,
        'width' => 1392,
        'height' => 'auto',
    ], $cards);
    
    runResolver($root);
    
    echo "  grid root: w={$root->w} h={$root->h}\n";
    echo "  children: " . count($root->children) . "\n";
    
    $allHaveWidth = true;
    $allHaveHeight = true;
    foreach ($root->children as $i => $ch) {
        if ($ch->w <= 0) $allHaveWidth = false;
        if ($ch->h <= 0) $allHaveHeight = false;
        if ($ch->w <= 0 || $ch->h <= 0) {
            echo "  child[$i]: x={$ch->x} y={$ch->y} w={$ch->w} h={$ch->h}\n";
        }
    }
    
    assert_true($allHaveWidth, 'All children should have width > 0');
    assert_true($allHaveHeight, 'All children should have height > 0');
    assert_true($root->h > 0, 'Grid root height should be > 0');
});

echo "\n========================================\n";
echo " 诊断测试完成\n";
echo "========================================\n";
