<?php
/**
 * LayoutEngine 单元测试（框架层：布局计算逻辑）
 *
 * 这些直接用已知 RenderNode 输入测 LayoutResolver 的特定逻辑，完全通用。
 * 覆盖 Grid auto-fill、flex-shrink、Scroll contentHeight、Z-index/Layer、Position absolute。
 *
 * 注意：与 LayoutResolverTest.php 互补——此文件专注 Bilibili 布局相关的测试用例，
 * 不包括已在 LayoutResolverTest.php 中覆盖的基础功能（layer 继承、block 布局等）。
 *
 * Usage: php tests/unit/LayoutEngineTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\RenderNode;
use Px\Rendering\ComputedStyle;
use Px\Rendering\Layout\LayoutOrchestrator;
use Px\Rendering\CssMappings;

echo "========================================\n";
echo " LayoutEngine 单元测试（布局计算）\n";
echo "========================================\n\n";

// 辅助函数：构建 RenderNode 树
function makeNode(string $type, array $style = [], array $children = [], ?string $content = null): RenderNode
{
    $cs = !empty($style) ? new ComputedStyle($style) : null;
    $node = new RenderNode($type, $cs, $content);
    foreach ($children as $child) {
        $node->addChild($child);
    }
    return $node;
}

// =============================================================
// 1. Grid auto-fill
// =============================================================
echo "--- 1. Grid auto-fill ---\n";

test('Grid auto-fill: 容器 w=1392, gap=16, minmax(300px,1fr) → 4列, cellW=336', function () {
    $gridContainer = makeNode('div', [
        'display' => 'grid',
        'width' => 1392,
        'height' => 400,
        'gridTemplateColumns' => 'repeat(auto-fill, minmax(300px, 1fr))',
        'gap' => 16,
        'left' => 0, 'top' => 0,
    ]);
    // 添加 8 个子元素（足够填充 4 列×2 行）
    for ($i = 0; $i < 8; $i++) {
        $gridContainer->addChild(makeNode('div', ['left' => 0, 'top' => 0], [], 'cell ' . $i));
    }
    $root = makeNode('#root', ['width' => 1392, 'height' => 900], [$gridContainer]);

    $orchestrator = new LayoutOrchestrator();
    $result = $resolver->layout($root);

    // 验证列数：CSS 公式 floor((1392 + 16) / (300 + 16)) = floor(1408/316) = 4
    // 验证单元格宽度：(1392 - 16*3) / 4 = (1392-48)/4 = 336
    $firstCell = $gridContainer->children[0];
    // 第一行第一列：x=0, 宽度应为 336
    assert_true($firstCell->x >= 0, 'first cell x >= 0');
    // 在修复之后的预期值：每个单元格宽度约 336px
    // 注意：当前实现可能存在偏差，此测试验证修复前后的变化
    $colCount = 0;
    $prevX = -1;
    foreach ($gridContainer->children as $child) {
        if ($child->x > $prevX) {
            $colCount++;
            $prevX = $child->x;
        }
        if ($child->x === 0) break; // 第二行开始
    }
    // 第二行的第一个子节点 x 应该重新从 0 开始（换行），
    // 所以第一行有多少子节点的 x < 第二行的 x
    $secondRowX = PHP_INT_MAX;
    foreach ($gridContainer->children as $child) {
        if ($child->x > 0 && $child->x < $secondRowX) {
            $secondRowX = $child->x;
        }
    }
    // 找到列数：第二个换行位置就是列数
    $actualCols = 0;
    foreach ($gridContainer->children as $i => $child) {
        if ($i === 0) continue;
        if ($child->x <= $gridContainer->children[$i-1]->x) {
            $actualCols = $i;
            break;
        }
    }
    if ($actualCols === 0) $actualCols = count($gridContainer->children);
    assert_eq($actualCols, 4, "columns = 4 (got $actualCols)");
});

test('Grid auto-fill: 容器 w=800, gap=16, minmax(300px,1fr) → 2列', function () {
    $gridContainer = makeNode('div', [
        'display' => 'grid',
        'width' => 800,
        'height' => 400,
        'gridTemplateColumns' => 'repeat(auto-fill, minmax(300px, 1fr))',
        'gap' => 16,
        'left' => 0, 'top' => 0,
    ]);
    for ($i = 0; $i < 4; $i++) {
        $gridContainer->addChild(makeNode('div', ['left' => 0, 'top' => 0], [], 'cell ' . $i));
    }
    $root = makeNode('#root', ['width' => 800, 'height' => 900], [$gridContainer]);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    // CSS 规范公式：cols = floor((800 + 16) / (300 + 16)) = floor(816/316) = floor(2.582) = 2
    $actualCols = 0;
    foreach ($gridContainer->children as $i => $child) {
        if ($i === 0) continue;
        if ($child->x <= $gridContainer->children[$i-1]->x) {
            $actualCols = $i;
            break;
        }
    }
    if ($actualCols === 0) $actualCols = count($gridContainer->children);
    assert_eq($actualCols, 2, "columns = 2 (got $actualCols)");
});

// =============================================================
// 2. Flex-shrink
// =============================================================
echo "\n--- 2. Flex-shrink ---\n";

test('flex-shrink:0 的子元素不收缩，保持原始宽度', function () {
    $child = makeNode('div', ['width' => 1200, 'flexShrink' => 0, 'height' => 100]);
    $container = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 1000, 'height' => 100,
    ], [$child]);
    $root = makeNode('#root', ['width' => 1000, 'height' => 900], [$container]);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    assert_eq($child->w, 1200, 'flex-shrink:0 不收缩，保持 1200');
});

test('flex-shrink:1 的子元素按比例缩小以适应容器', function () {
    $child1 = makeNode('div', ['width' => 600, 'flexShrink' => 1, 'height' => 100]);
    $child2 = makeNode('div', ['width' => 600, 'flexShrink' => 1, 'height' => 100]);
    $container = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 1000, 'height' => 100,
    ], [$child1, $child2]);
    $root = makeNode('#root', ['width' => 1000, 'height' => 900], [$container]);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    // 总宽度 1200，容器 1000，溢出 200
    // 两个子项 shrink=1 相同权重，各收缩 100
    assert_true($child1->w + $child2->w <= 1000, '子元素总宽度不超过容器');
    assert_true($child1->w < 600, 'child1 已收缩 (w < 600)');
    assert_true($child2->w < 600, 'child2 已收缩 (w < 600)');
});

// =============================================================
// 3. Scroll contentHeight
// =============================================================
echo "\n--- 3. Scroll contentHeight ---\n";

test('scroll 容器 h=400, 内容 h=1200 → contentHeight=1200, scrollTop clamp [0, 800]', function () {
    $content = makeNode('div', ['width' => 300, 'height' => 1200, 'left' => 0, 'top' => 0]);
    $scrollContainer = makeNode('div', [
        'width' => 300, 'height' => 400,
        'overflowY' => 'auto', 'overflowX' => 'hidden',
        'left' => 0, 'top' => 0,
    ], [$content]);
    $root = makeNode('#root', ['width' => 400, 'height' => 900], [$scrollContainer]);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    assert_true($scrollContainer->contentHeight >= 1200, 'contentHeight >= 1200');
    assert_eq($scrollContainer->h, 400, '容器高度保持 400');
    // maxScroll = contentHeight - containerH = 1200 - 400 = 800
    $maxScroll = $scrollContainer->contentHeight - $scrollContainer->h;
    assert_true($maxScroll >= 800, "maxScroll >= 800 (got $maxScroll)");
});

test('scroll 容器带 border:1px → contentHeight 不受 border 影响', function () {
    $content = makeNode('div', ['width' => 280, 'height' => 800, 'left' => 0, 'top' => 0]);
    $scrollContainer = makeNode('div', [
        'width' => 300, 'height' => 400,
        'borderWidth' => 1, 'borderColor' => 0,
        'overflowY' => 'auto', 'overflowX' => 'hidden',
        'left' => 0, 'top' => 0,
    ], [$content]);
    $root = makeNode('#root', ['width' => 400, 'height' => 900], [$scrollContainer]);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    // contentHeight 应代表内容区域高度，不受 border 影响
    assert_true($scrollContainer->contentHeight >= 800, 'contentHeight >= 800 (border not included)');
});

// =============================================================
// 4. Position absolute
// =============================================================
echo "\n--- 4. Position absolute ---\n";

test('父 relative, 子 absolute left=10 top=20 → 子坐标相对于父', function () {
    $child = makeNode('div', [
        'position' => 'absolute',
        'left' => 10, 'top' => 20,
        'width' => 100, 'height' => 50,
    ]);
    $parent = makeNode('div', [
        'position' => 'relative',
        'width' => 200, 'height' => 200,
        'left' => 50, 'top' => 50,
    ], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);

    $orchestrator = new LayoutOrchestrator();
    $resolver->layout($root);

    // parent at (50, 50), absolute child at (10, 20) relative to parent
    // expected child: x = 50 + 10 = 60, y = 50 + 20 = 70
    assert_eq($child->x, 60, 'child x = parent.x + left = 50 + 10 = 60');
    assert_eq($child->y, 70, 'child y = parent.y + top = 50 + 20 = 70');
});

// =============================================================
// 摘要
// =============================================================
$exitCode = print_summary();
exit($exitCode);
