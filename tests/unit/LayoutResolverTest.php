<?php
/**
 * LayoutResolver 单元测试
 * 
 * 测试目标:
 *   1. Layer 继承: 子节点继承父节点的 z-index layer
 *   2. 自身 z-index 覆盖继承的 layer
 *   3. setClassStyles(): 运行时注入 CSS class 样式
 *   4. Block 布局: 基本定位
 *   5. Flex 布局: 子节点定位
 *   6. Grid 布局: 子节点网格定位
 * 
 * Usage: php tests/unit/LayoutResolverTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\VNode;
use Px\Rendering\LayoutResolver;
use Px\Rendering\CssMappings;

echo "========================================\n";
echo " LayoutResolver 单元测试\n";
echo "========================================\n\n";

echo "--- 1. Layer 继承与 Z-Index ---\n";

test('子节点继承父节点的 layer', function () {
    $child = VNode::h('div', ['class' => 'box'], 'text');

    $parent = VNode::h('div', ['style' => 'zIndex:5;'], [$child]);
    $parent->layer = 0; // 初始

    $root = VNode::h('#root', ['title' => 'test', 'style' => 'width:400px;height:300px;'], [$parent]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    // 父节点 layer 应为 5 (zIndex from style)
    assert_eq($parent->layer, 5, '父节点 layer');
    // 子节点继承父节点的 layer
    assert_eq($child->layer, 5, '子节点应继承父节点的 layer');
});

test('父节点无 zIndex 时子节点保持 layer 0', function () {
    $child = VNode::h('div', ['class' => 'box'], 'text');

    $parent = VNode::h('div', [], [$child]);
    $parent->layer = 0;

    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$parent]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    assert_eq($parent->layer, 0, '父节点无 zIndex 时 layer 为 0');
    assert_eq($child->layer, 0, '子节点 layer 也为 0');
});

test('子节点自身的 zIndex 覆盖继承的 parent layer', function () {
    $child = VNode::h('div', ['style' => 'zIndex:10;'], 'text');

    $parent = VNode::h('div', ['style' => 'zIndex:5;'], [$child]);

    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$parent]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    assert_eq($parent->layer, 5, '父节点 layer=5');
    assert_eq($child->layer, 10, '子节点自己的 zIndex=10 覆盖继承的 5');
});

test('子节点有更小 zIndex 时不覆盖 parent layer', function () {
    $child = VNode::h('div', ['style' => 'zIndex:2;'], 'text');

    $parent = VNode::h('div', ['style' => 'zIndex:5;'], [$child]);

    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$parent]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    // 子节点继承 5，自己的 zIndex=2 小于 5，所以保持 5
    assert_eq($child->layer, 5, '子节点 zIndex=2 小于 parent layer=5，保持 parent layer');
});

test('只有正数 zIndex 才影响 layer 属性', function () {
    $node = VNode::h('div', ['style' => 'zIndex:0;'], 'text');

    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$node]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    assert_eq($node->layer, 0, 'zIndex:0 不应改变 layer');
});

echo "\n--- 2. setClassStyles() ---\n";

test('setClassStyles() 运行时注入 CSS 类样式', function () {
    $resolver = new LayoutResolver([]);

    $classStyles = [
        'panel' => ['bg' => 0x333333, 'width' => 300],
    ];
    $resolver->setClassStyles($classStyles);

    $node = VNode::h('div', ['class' => 'panel'], 'content');
    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$node]);

    $resolver->resolve($root);

    // Class style 中的 'bg' 应被合并到 computedStyle
    assert_eq($node->computedStyle['bg'], 0x333333, 'class 的 bg 应被合并');
    assert_eq($node->computedStyle['width'], 300, 'class 的 width 应被合并');
});

test('inline style 覆盖 class style', function () {
    $resolver = new LayoutResolver([]);
    $resolver->setClassStyles([
        'panel' => ['bg' => 0x333333, 'width' => 300],
    ]);

    $node = VNode::h('div', ['class' => 'panel', 'style' => 'width:200px;'], 'content');
    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$node]);

    $resolver->resolve($root);

    // inline style 的 width 应覆盖 class style
    assert_eq($node->computedStyle['bg'], 0x333333, 'class bg 应保留');
    assert_eq($node->computedStyle['width'], 200, 'inline width 应覆盖 class width');
});

test('未设置 classStyles 时正常运行', function () {
    $resolver = new LayoutResolver([]);

    $node = VNode::h('div', ['class' => 'unknown-class', 'style' => 'width:100px;'], 'text');
    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$node]);

    $resolver->resolve($root);

    assert_eq($node->computedStyle['width'], 100, 'inline style 正常解析');
});

echo "\n--- 3. Block 布局 ---\n";

test('block 布局：left/top 绝对定位', function () {
    $node = VNode::h('div', ['style' => 'left:50px;top:30px;width:100px;height:60px;'], 'box');

    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$node]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    assert_eq($node->x, 50, 'x = left');
    assert_eq($node->y, 30, 'y = top');
    assert_eq($node->w, 100, 'width');
    assert_eq($node->h, 60, 'height');
});

test('子节点相对于父节点偏移', function () {
    $child = VNode::h('span', ['style' => 'left:20px;top:10px;width:50px;height:30px;'], 'child');

    $parent = VNode::h('div', ['style' => 'left:100px;top:50px;width:200px;height:100px;'], [$child]);

    $root = VNode::h('#root', ['style' => 'width:500px;height:400px;'], [$parent]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    assert_eq($parent->x, 100, '父 x');
    assert_eq($parent->y, 50, '父 y');
    assert_eq($child->x, 20 + 100, '子 x = 父 x + child left');
    assert_eq($child->y, 10 + 50, '子 y = 父 y + child top');
});

test('无 style 的节点 x/y/w/h 默认为 0', function () {
    $node = VNode::h('div', [], 'empty');

    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$node]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    assert_eq($node->x, 0, '默认 x=0');
    assert_eq($node->y, 0, '默认 y=0');
    assert_eq($node->w, 0, '默认 w=0');
    assert_eq($node->h, 0, '默认 h=0');
});

echo "\n--- 4. Flex 布局 ---\n";

test('flex 布局：子节点水平排列', function () {
    $child1 = VNode::h('div', ['style' => 'width:50px;height:30px;'], 'A');
    $child2 = VNode::h('div', ['style' => 'width:50px;height:30px;'], 'B');

    $flex = VNode::h('div', [
        'style' => 'display:flex;flex-direction:row;width:200px;height:100px;gap:10px;left:10px;top:10px;'
    ], [$child1, $child2]);

    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$flex]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    assert_eq($flex->x, 10);
    assert_eq($flex->y, 10);
    // 第一个子节点在 flex 容器起始位置
    assert_eq($child1->x, 10, 'child1 x=flex x');
    // 第二个子节点在 child1 后面 + gap
    assert_eq($child2->x, 10 + 50 + 10, 'child2 x = child1.x + child1.w + gap');
});

test('flex 布局：列排列', function () {
    $child1 = VNode::h('div', ['style' => 'width:60px;height:30px;'], 'A');
    $child2 = VNode::h('div', ['style' => 'width:60px;height:30px;'], 'B');

    $flex = VNode::h('div', [
        'style' => 'display:flex;flex-direction:column;width:200px;height:100px;gap:5px;left:20px;top:20px;'
    ], [$child1, $child2]);

    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$flex]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    assert_eq($child1->y, 20, 'child1 y=flex y');
    assert_eq($child2->y, 20 + 30 + 5, 'child2 y = child1.y + child1.h + gap');
});

echo "\n--- 5. Grid 布局 ---\n";

test('grid 布局：子节点按格子排列', function () {
    $child1 = VNode::h('div', ['style' => 'width:60px;height:40px;'], 'A');
    $child2 = VNode::h('div', ['style' => 'width:60px;height:40px;'], 'B');
    $child3 = VNode::h('div', ['style' => 'width:60px;height:40px;'], 'C');
    $child4 = VNode::h('div', ['style' => 'width:60px;height:40px;'], 'D');

    $grid = VNode::h('div', [
        'style' => 'display:grid;grid-template-columns:repeat(2, 80px);grid-template-rows:repeat(2, 50px);left:10px;top:10px;width:200px;height:150px;gap:4px;'
    ], [$child1, $child2, $child3, $child4]);

    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], [$grid]);

    $resolver = new LayoutResolver([]);
    $resolver->resolve($root);

    // 第1个: 列0 行0 (adjust for gap:4px)
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

echo "\n";
$exitCode = print_summary();
exit($exitCode);
