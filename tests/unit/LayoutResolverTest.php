<?php
/**
 * LayoutResolver 单元测试（RenderNode 版）
 *
 * 测试目标:
 *   1. Layer 继承: 子节点继承父节点的 z-index layer
 *   2. 自身 z-index 覆盖继承的 layer
 *   3. Block 布局: 基本定位
 *   4. Flex 布局: 子节点定位
 *   5. Grid 布局: 子节点网格定位
 *   6. 脏标记路径: layoutDirty=true 执行完整布局
 *   7. 洁净路径: layoutDirty=false 仅传递父坐标（含 margin）
 *
 * Usage: php tests/unit/LayoutResolverTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\RenderNode;
use Px\Rendering\LayoutResolver;
use Px\Rendering\CssMappings;

echo "========================================\n";
echo " LayoutResolver 单元测试（RenderNode）\n";
echo "========================================\n\n";

// 辅助函数：构建 RenderNode 树
function makeNode(string $type, array $style = [], array $children = [], ?string $content = null): RenderNode
{
    $node = new RenderNode($type, $style, $content);
    foreach ($children as $child) {
        $node->addChild($child);
    }
    return $node;
}

echo "--- 1. Layer 继承与 Z-Index ---\n";

test('子节点继承父节点的 layer', function () {
    $child = makeNode('div', ['width' => 100, 'height' => 50]);
    $parent = makeNode('div', ['zIndex' => 5, 'width' => 200, 'height' => 100], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($parent->layer, 5, '父节点 layer');
    assert_eq($child->layer, 5, '子节点应继承父节点的 layer');
});

test('父节点无 zIndex 时子节点保持 layer 0', function () {
    $child = makeNode('div', ['width' => 100]);
    $parent = makeNode('div', ['width' => 200], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($parent->layer, 0, '父节点无 zIndex 时 layer 为 0');
    assert_eq($child->layer, 0, '子节点 layer 也为 0');
});

test('子节点自身的 zIndex 覆盖继承的 parent layer', function () {
    $child = makeNode('div', ['zIndex' => 10, 'width' => 100]);
    $parent = makeNode('div', ['zIndex' => 5, 'width' => 200], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($parent->layer, 5, '父节点 layer=5');
    assert_eq($child->layer, 10, '子节点自己的 zIndex=10 覆盖继承的 5');
});

test('子节点有更小 zIndex 时不覆盖 parent layer', function () {
    $child = makeNode('div', ['zIndex' => 2, 'width' => 100]);
    $parent = makeNode('div', ['zIndex' => 5, 'width' => 200], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($child->layer, 5, '子节点 zIndex=2 小于 parent layer=5，保持 parent layer');
});

test('只有正数 zIndex 才影响 layer 属性', function () {
    $node = makeNode('div', ['zIndex' => 0, 'width' => 100]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($node->layer, 0, 'zIndex:0 不应改变 layer');
});

echo "\n--- 2. Block 布局 ---\n";

test('block 布局：left/top 绝对定位', function () {
    $node = makeNode('div', ['left' => 50, 'top' => 30, 'width' => 100, 'height' => 60]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($node->x, 50, 'x = left');
    assert_eq($node->y, 30, 'y = top');
    assert_eq($node->w, 100, 'width');
    assert_eq($node->h, 60, 'height');
});

test('子节点相对于父节点偏移', function () {
    $child = makeNode('span', ['left' => 20, 'top' => 10, 'width' => 50, 'height' => 30]);
    $parent = makeNode('div', ['left' => 100, 'top' => 50, 'width' => 200, 'height' => 100], [$child]);
    $root = makeNode('#root', ['width' => 500, 'height' => 400], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($parent->x, 100, '父 x');
    assert_eq($parent->y, 50, '父 y');
    assert_eq($child->x, 20 + 100, '子 x = 父 x + child left');
    assert_eq($child->y, 10 + 50, '子 y = 父 y + child top');
});

test('无 style 的节点 x/y/w/h 默认为 0', function () {
    $node = makeNode('div', [], []);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($node->x, 0, '默认 x=0');
    assert_eq($node->y, 0, '默认 y=0');
    assert_eq($node->w, 0, '默认 w=0');
    assert_eq($node->h, 0, '默认 h=0');
});

echo "\n--- 3. Flex 布局 ---\n";

test('flex 布局：子节点水平排列', function () {
    $child1 = makeNode('div', ['width' => 50, 'height' => 30], [], 'A');
    $child2 = makeNode('div', ['width' => 50, 'height' => 30], [], 'B');

    $flex = makeNode('div', [
        'display' => 'flex',
        'flexDirection' => 'row',
        'width' => 200,
        'height' => 100,
        'gap' => 10,
        'left' => 10,
        'top' => 10,
    ], [$child1, $child2]);

    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$flex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($flex->x, 10);
    assert_eq($flex->y, 10);
    assert_eq($child1->x, 10, 'child1 x=flex x');
    assert_eq($child2->x, 10 + 50 + 10, 'child2 x = child1.x + child1.w + gap');
});

test('flex 布局：列排列', function () {
    $child1 = makeNode('div', ['width' => 60, 'height' => 30], [], 'A');
    $child2 = makeNode('div', ['width' => 60, 'height' => 30], [], 'B');

    $flex = makeNode('div', [
        'display' => 'flex',
        'flexDirection' => 'column',
        'width' => 200,
        'height' => 100,
        'gap' => 5,
        'left' => 20,
        'top' => 20,
    ], [$child1, $child2]);

    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$flex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($child1->y, 20, 'child1 y=flex y');
    assert_eq($child2->y, 20 + 30 + 5, 'child2 y = child1.y + child1.h + gap');
});

echo "\n--- 4. Grid 布局 ---\n";

test('grid 布局：子节点按格子排列', function () {
    $child1 = makeNode('div', ['width' => 60, 'height' => 40], [], 'A');
    $child2 = makeNode('div', ['width' => 60, 'height' => 40], [], 'B');
    $child3 = makeNode('div', ['width' => 60, 'height' => 40], [], 'C');
    $child4 = makeNode('div', ['width' => 60, 'height' => 40], [], 'D');

    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(2, 80px)',
        'gridTemplateRows' => 'repeat(2, 50px)',
        'left' => 10,
        'top' => 10,
        'width' => 200,
        'height' => 150,
        'gap' => 4,
    ], [$child1, $child2, $child3, $child4]);

    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$grid]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // 第1个: 列0 行0 (gap:4)
    assert_eq($child1->x, 10 + 4, 'grid child1 col 0');
    assert_eq($child1->y, 10 + 4, 'grid child1 row 0');

    // 第2个: 列1 行0
    assert_eq($child2->x, 10 + 80 + 4, 'grid child2 col 1');
    assert_eq($child2->y, 10 + 4, 'grid child2 row 0');

    // 第3个: 列0 行1
    assert_eq($child3->x, 10 + 4, 'grid child3 col 0');
    assert_eq($child3->y, 10 + 50 + 4, 'grid child3 row 1');

    // 第4个: 列1 行1
    assert_eq($child4->x, 10 + 80 + 4, 'grid child4 col 1');
    assert_eq($child4->y, 10 + 50 + 4, 'grid child4 row 1');
});

echo "\n--- 5. 脏标记路径 ---\n";

test('layoutDirty=true 时执行完整布局', function () {
    $node = makeNode('div', ['left' => 10, 'top' => 20, 'width' => 100, 'height' => 50]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);

    assert_true($node->layoutDirty, '新建节点 layoutDirty 应为 true');

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($node->x, 10, 'dirty 节点正确计算 x');
    assert_eq($node->y, 20, 'dirty 节点正确计算 y');
    assert_false($node->layoutDirty, '布局完成后 layoutDirty 应为 false');
});

test('layoutDirty=false 时洁净路径仍传递父坐标', function () {
    $child = makeNode('div', ['left' => 5, 'top' => 5, 'width' => 50, 'height' => 30]);
    $parent = makeNode('div', ['left' => 100, 'top' => 100, 'width' => 200, 'height' => 150], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_false($child->layoutDirty, '子节点布局后 layoutDirty 应为 false');
    assert_eq($child->x, 5 + 100, '洁净子节点 x = left + parentX');
    assert_eq($child->y, 5 + 100, '洁净子节点 y = top + parentY');
});

test('洁净路径包含 margin 计算', function () {
    $child = makeNode('div', [
        'left' => 10,
        'top' => 10,
        'marginLeft' => 5,
        'marginTop' => 3,
        'width' => 50,
        'height' => 30,
    ]);
    $parent = makeNode('div', ['left' => 50, 'top' => 50, 'width' => 200, 'height' => 150], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // 父坐标=50, child left=10, marginLeft=5 → x=50+10+5=65
    // 父坐标=50, child top=10, marginTop=3 → y=50+10+3=63
    assert_eq($child->x, 50 + 10 + 5, 'x 包含 marginLeft');
    assert_eq($child->y, 50 + 10 + 3, 'y 包含 marginTop');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
