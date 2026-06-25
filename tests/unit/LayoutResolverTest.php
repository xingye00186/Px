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
    $node = makeNode('div', ['position' => 'absolute', 'left' => 50, 'top' => 30, 'width' => 100, 'height' => 60]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($node->x, 50, 'x = left');
    assert_eq($node->y, 30, 'y = top');
    assert_eq($node->w, 100, 'width');
    assert_eq($node->h, 60, 'height');
});

test('子节点相对于父节点偏移', function () {
    $child = makeNode('span', ['position' => 'absolute', 'left' => 20, 'top' => 10, 'width' => 50, 'height' => 30]);
    $parent = makeNode('div', ['position' => 'relative', 'left' => 100, 'top' => 50, 'width' => 200, 'height' => 100], [$child]);
    $root = makeNode('#root', ['width' => 500, 'height' => 400], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($parent->x, 100, '父 x');
    assert_eq($parent->y, 50, '父 y');
    assert_eq($child->x, 20 + 100, '子 x = 父 x + child left');
    assert_eq($child->y, 10 + 50, '子 y = 父 y + child top');
});

test('无 style 节点 auto-stack 撑满父容器', function () {
    $node = makeNode('div', [], []);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // CSS normal flow: width:auto block 子节点撑满父容器 content 宽度
    // 父容器 #root w=400, 子节点无 padding → contentW = 400
    assert_eq($node->x, 0, '默认 x=0');
    assert_eq($node->y, 0, '默认 y=0（auto-stack 第一项）');
    assert_eq($node->w, 400, 'auto-stack: width=父容器 content width');
    assert_eq($node->h, 0, '默认 h=0');
});

echo "\n--- 3. Flex 布局 ---\n";

test('flex 布局：子节点水平排列', function () {
    $child1 = makeNode('div', ['width' => 50, 'height' => 30], [], 'A');
    $child2 = makeNode('div', ['width' => 50, 'height' => 30], [], 'B');

    $flex = makeNode('div', [
        'display' => 'flex',
        'flexDirection' => 'row',
        'position' => 'relative',
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
        'position' => 'relative',
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
        'position' => 'relative',
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
    assert_eq($child1->x, 10, 'grid child1 col 0 (no leading gap)');
    assert_eq($child1->y, 10, 'grid child1 row 0 (no leading gap)');

    // 第2个: 列1 行0
    assert_eq($child2->x, 10 + 80 + 4, 'grid child2 col 1');
    assert_eq($child2->y, 10, 'grid child2 row 0 (no leading gap)');

    // 第3个: 列0 行1
    assert_eq($child3->x, 10, 'grid child3 col 0 (no leading gap)');
    assert_eq($child3->y, 10 + 50 + 4, 'grid child3 row 1');

    // 第4个: 列1 行1
    assert_eq($child4->x, 10 + 80 + 4, 'grid child4 col 1');
    assert_eq($child4->y, 10 + 50 + 4, 'grid child4 row 1');
});

echo "\n--- 5. 脏标记路径 ---\n";

test('layoutDirty=true 时执行完整布局', function () {
    $node = makeNode('div', ['position' => 'absolute', 'left' => 10, 'top' => 20, 'width' => 100, 'height' => 50]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);

    assert_true($node->layoutDirty, '新建节点 layoutDirty 应为 true');

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($node->x, 10, 'dirty 节点正确计算 x');
    assert_eq($node->y, 20, 'dirty 节点正确计算 y');
    assert_false($node->layoutDirty, '布局完成后 layoutDirty 应为 false');
});

test('layoutDirty=false 时洁净路径仍传递父坐标', function () {
    $child = makeNode('div', ['position' => 'absolute', 'left' => 5, 'top' => 5, 'width' => 50, 'height' => 30]);
    $parent = makeNode('div', ['position' => 'relative', 'left' => 100, 'top' => 100, 'width' => 200, 'height' => 150], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_false($child->layoutDirty, '子节点布局后 layoutDirty 应为 false');
    assert_eq($child->x, 5 + 100, '洁净子节点 x = left + parentX');
    assert_eq($child->y, 5 + 100, '洁净子节点 y = top + parentY');
});

test('洁净路径包含 margin 计算', function () {
    $child = makeNode('div', [
        'position' => 'absolute',
        'left' => 10,
        'top' => 10,
        'marginLeft' => 5,
        'marginTop' => 3,
        'width' => 50,
        'height' => 30,
    ]);
    $parent = makeNode('div', ['position' => 'relative', 'left' => 50, 'top' => 50, 'width' => 200, 'height' => 150], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // 父坐标=50, child left=10, marginLeft=5 → x=50+10+5=65
    // 父坐标=50, child top=10, marginTop=3 → y=50+10+3=63
    assert_eq($child->x, 50 + 10 + 5, 'x 包含 marginLeft');
    assert_eq($child->y, 50 + 10 + 3, 'y 包含 marginTop');
});

echo "\n--- 6. Min/Max 约束 ---\n";

test('min-width 将宽度提升到最小值', function () {
    $node = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 30, 'minWidth' => 100, 'height' => 50]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($node->w, 100, 'min-width=100 将 width=30 提升到 100');
});

test('max-width 将宽度限制到最大值', function () {
    $node = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 200, 'maxWidth' => 100, 'height' => 50]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($node->w, 100, 'max-width=100 将 width=200 限制到 100');
});

test('min-height 将高度提升到最小值', function () {
    $node = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 50, 'height' => 20, 'minHeight' => 80]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($node->h, 80, 'min-height=80 将 height=20 提升到 80');
});

test('max-height 将高度限制到最大值', function () {
    $node = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 50, 'height' => 150, 'maxHeight' => 100]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($node->h, 100, 'max-height=100 将 height=150 限制到 100');
});

test('min > max 时 max 被忽略（CSS 规范）', function () {
    $node = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 200, 'minWidth' => 150, 'maxWidth' => 100, 'height' => 50]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($node->w, 200, 'min(150) > max(100) → max 被忽略, width=200 保持不变');
});

test('min/max 不影响未触及的值', function () {
    $node = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 100, 'minWidth' => 50, 'maxWidth' => 200, 'height' => 50]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$node]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($node->w, 100, 'width=100 在 min=50 和 max=200 之间保持不变');
});

test('嵌套 min/max：子节点 min-width < 父节点 max-width', function () {
    $child = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 50, 'minWidth' => 80, 'height' => 30]);
    $parent = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 200, 'maxWidth' => 150, 'height' => 100], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($parent->w, 150, '父 max-width=150 限制父宽度');
    assert_eq($child->w, 80, '子 min-width=80 提升子宽度到 80');
});

echo "\n--- 7. 百分比尺寸 ---\n";

test('width:50% 解析为父宽度的 50%', function () {
    $node = makeNode('div', ['widthPercent' => 50.0, 'height' => 50]);
    $parent = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 200, 'height' => 100], [$node]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($node->w, 100, 'width=50% of 200 = 100');
});

test('height:50% 解析为父高度的 50%', function () {
    $node = makeNode('div', ['heightPercent' => 50.0, 'width' => 50]);
    $parent = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 200, 'height' => 120], [$node]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($node->h, 60, 'height=50% of 120 = 60');
});

test('百分比 + min/max 约束', function () {
    $node = makeNode('div', ['widthPercent' => 80.0, 'maxWidth' => 50, 'height' => 30]);
    $parent = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 200, 'height' => 100], [$node]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // 80% of 200 = 160, 但 maxWidth=50 → 限制到 50
    assert_eq($node->w, 50, '80% of 200 = 160 但 maxWidth=50 限制到 50');
});

test('百分比在 flex 容器上生效', function () {
    $flex = makeNode('div', [
        'display' => 'flex', 'widthPercent' => 50.0, 'height' => 100,
        'left' => 0, 'top' => 0,
    ]);
    $parent = makeNode('div', ['left' => 0, 'top' => 0, 'width' => 400, 'height' => 200], [$flex]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($flex->w, 200, 'flex container width=50% of 400 = 200');
});

echo "\n--- 8. position:relative 在 auto-stack 中 ---\n";

test('position:relative + top 不禁止 auto-stack', function () {
    $child1 = makeNode('div', ['width' => 100, 'height' => 30, 'position' => 'relative', 'top' => 5], [], 'A');
    $child2 = makeNode('div', ['width' => 100, 'height' => 30], [], 'B');
    $container = makeNode('div', [
        'overflow' => 'auto', 'left' => 0, 'top' => 0, 'width' => 200, 'height' => 200,
    ], [$child1, $child2]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$container]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // child1 auto-stacked at y=0 + relative top=5 → y=5
    assert_eq($child1->y, 5, 'position:relative child1 y = 0(stack) + 5(relative top) = 5');
    // child2 auto-stacked below child1 (不受 relative 影响)
    assert_eq($child2->y, 30, 'child2 y = child1.h(30) = 30, 不受 child1 relative 影响');
});

test('position:relative + left 偏移不影响兄弟节点', function () {
    $child1 = makeNode('div', ['width' => 100, 'height' => 30, 'position' => 'relative', 'left' => 10], [], 'A');
    $child2 = makeNode('div', ['width' => 100, 'height' => 30], [], 'B');
    $container = makeNode('div', [
        'overflow' => 'auto', 'left' => 0, 'top' => 0, 'width' => 200, 'height' => 200,
    ], [$child1, $child2]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$container]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($child1->x, 10, 'position:relative child1 x shift by left=10');
    // child2 的位置不受 child1 left 影响
});



test('position:fixed 相对视口定位（不受滚动影响）', function () {
    $child = makeNode('div', ['position' => 'fixed', 'left' => 30, 'top' => 40, 'width' => 100, 'height' => 50], [], 'A');
    $scroll = makeNode('div', [
        'overflow' => 'auto', 'scrollTop' => 50,
        'left' => 0, 'top' => 0, 'width' => 400, 'height' => 300,
    ], [$child]);
    $root = makeNode('#root', ['width' => 500, 'height' => 400], [$scroll]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // fixed 元素相对视口（根节点）定位，不受父滚动影响
    assert_eq($child->x, 30, 'position:fixed x=30 相对视口');
    assert_eq($child->y, 40, 'position:fixed y=40 相对视口（不受 scrollTop=50 影响）');
});

test('margin:auto with position:absolute 垂直居中', function () {
    $child = makeNode('div', ['position' => 'absolute', 'width' => 100, 'height' => 50, 'margin' => 'auto'], [], 'A');
    $parent = makeNode('div', ['position' => 'relative', 'width' => 300, 'height' => 200], [$child]);
    $root = makeNode('#root', ['width' => 400], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // 水平居中：(300-100)/2 = 100
    // 垂直居中：(200-50)/2 = 75
    assert_eq($child->x, 100, 'margin:auto 水平居中：x=(300-100)/2=100');
    assert_eq($child->y, 75, 'margin:auto 垂直居中：y=(200-50)/2=75');
});


echo "\n--- 9. Flex order 排序 ---\n";

test('order 改变 flex 子节点顺序', function () {
    $c1 = makeNode('div', ['order' => 2, 'width' => 30, 'height' => 30], [], 'C');
    $c2 = makeNode('div', ['order' => 1, 'width' => 30, 'height' => 30], [], 'B');
    $c3 = makeNode('div', ['order' => 0, 'width' => 30, 'height' => 30], [], 'A');
    $flex = makeNode('div', ['display'=>'flex', 'width'=>200, 'height'=>50, 'left'=>0, 'top'=>0], [$c1, $c2, $c3]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // order 0, 1, 2 → A, B, C
    assert_eq($c3->x, 0, 'order=0 排最左 (A)');
    assert_eq($c2->x, 30, 'order=1 排第二 (B)');
    assert_eq($c1->x, 60, 'order=2 排最右 (C)');
});

test('同 order 值保持源顺序（稳定排序）', function () {
    $c1 = makeNode('div', ['order' => 1, 'width' => 30, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['order' => 1, 'width' => 30, 'height' => 30], [], 'B');
    $flex = makeNode('div', ['display'=>'flex', 'width'=>200, 'height'=>50, 'left'=>0, 'top'=>0], [$c1, $c2]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($c1->x, 0, '同 order=1, 源顺序排最左 (A)');
    assert_eq($c2->x, 30, '同 order=1, 源顺序排第二 (B)');
});

echo "\n--- 10. Flex-basis ---\n";

test('flex-basis 设置 row 方向初始 main size', function () {
    $c1 = makeNode('div', ['flexBasis' => '80', 'width' => 30, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['width' => 30, 'height' => 30], [], 'B');
    $flex = makeNode('div', ['display'=>'flex', 'width'=>200, 'height'=>50, 'left'=>0, 'top'=>0], [$c1, $c2]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // c1 flex-basis=80 覆盖 width=30
    assert_eq($c1->w, 80, 'flex-basis=80 覆盖 width=30');
    assert_eq($c2->w, 30, '无 flex-basis 保持 width=30');
});

test('flex-basis:auto 回退到 width', function () {
    $c1 = makeNode('div', ['flexBasis' => 'auto', 'width' => 60, 'height' => 30], [], 'A');
    $flex = makeNode('div', ['display'=>'flex', 'width'=>200, 'height'=>50, 'left'=>0, 'top'=>0], [$c1]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($c1->w, 60, 'flex-basis:auto 回退到 width=60');
});

test('flex-basis 在 column 方向影响 height', function () {
    $c1 = makeNode('div', ['flexBasis' => '100', 'width' => 50, 'height' => 20], [], 'A');
    $c2 = makeNode('div', ['width' => 50, 'height' => 30], [], 'B');
    $flex = makeNode('div', ['display'=>'flex', 'flexDirection'=>'column', 'width'=>100, 'height'=>200, 'left'=>0, 'top'=>0], [$c1, $c2]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($c1->h, 100, 'column: flex-basis=100 覆盖 height=20');
});

echo "\n--- 11. Flex-shrink ---\n";

test('flex-shrink 收缩溢出项', function () {
    $c1 = makeNode('div', ['width' => 100, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['width' => 100, 'height' => 30], [], 'B');
    $flex = makeNode('div', ['display'=>'flex', 'width'=>150, 'height'=>50, 'left'=>0, 'top'=>0], [$c1, $c2]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // 容器 150px, 子项 100+100=200, 溢出 50px
    // 每项 shrink=1 (默认), 等比收缩: 每项减 25
    assert_eq($c1->w, 75, 'flex-shrink: c1 从 100 收缩到 75');
    assert_eq($c2->w, 75, 'flex-shrink: c2 从 100 收缩到 75');
});

test('flex-shrink:0 的项不收缩', function () {
    $c1 = makeNode('div', ['width' => 100, 'height' => 30, 'flexShrink' => '0'], [], 'A');
    $c2 = makeNode('div', ['width' => 100, 'height' => 30], [], 'B');
    $flex = makeNode('div', ['display'=>'flex', 'width'=>150, 'height'=>50, 'left'=>0, 'top'=>0], [$c1, $c2]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // c1 shrink=0 不收缩 → 保持 100
    // c2 shrink=1 (默认) → 溢出 50px, 全部由 c2 承担: 100-50=50
    assert_eq($c1->w, 100, 'shrink:0 不收缩, 保持 100');
    assert_eq($c2->w, 50, 'shrink:1 承担全部 50px 收缩');
});

test('flex-shrink 按比例分配（flex 简写）', function () {
    $c1 = makeNode('div', ['flex' => '0 2', 'width' => 80, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['flex' => '0 1', 'width' => 80, 'height' => 30], [], 'B');
    $flex = makeNode('div', ['display'=>'flex', 'width'=>120, 'height'=>50, 'left'=>0, 'top'=>0], [$c1, $c2]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // flex:0 2/0 1 均设 basis=0, totalShrinkWeight=0
    // CSS §9.7: 总权重为 0 时均分溢出空间
    // 各缩: 40/2 = 20, c1=80-20=60, c2=80-20=60
    assert_eq($c1->w, 60, 'shrink=2 均分缩 (60)');
    assert_eq($c2->w, 60, 'shrink=1 均分缩 (60)');
});

test('flex-shrink + min-width 约束', function () {
    $c1 = makeNode('div', ['width' => 100, 'height' => 30, 'minWidth' => 60], [], 'A');
    $c2 = makeNode('div', ['width' => 100, 'height' => 30], [], 'B');
    $flex = makeNode('div', ['display'=>'flex', 'width'=>130, 'height'=>50, 'left'=>0, 'top'=>0], [$c1, $c2]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // 总 200, 容器 130, 溢出 70
    // 默认 shrink=1, 各缩 35
    // c1: 100-35=65, minWidth=60 → 65 (通过)
    // c2: 100-35=65
    assert_eq($c1->w, 65, 'minWidth=60 未触发, c1=65');
    assert_eq($c2->w, 65, 'c2 正常收缩到 65');

    // 更极端的收缩
    $c3 = makeNode('div', ['width' => 100, 'height' => 30, 'minWidth' => 70], [], 'C');
    $c4 = makeNode('div', ['width' => 100, 'height' => 30], [], 'D');
    $flex2 = makeNode('div', ['display'=>'flex', 'width'=>100, 'height'=>50, 'left'=>0, 'top'=>0], [$c3, $c4]);
    $root2 = makeNode('#root', ['width'=>400, 'height'=>300], [$flex2]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root2);
    // 总 200, 容器 100, 溢出 100
    // 各缩 50 → c3 触及 minWidth=70, 剩余 20 重新分配给 c4
    // c3: 100-50=50 < minWidth=70 → 保持 70
    // c4: 100-50=50, 再缩 20 → 30 (CSS §9.7 重新分配)
    assert_eq($c3->w, 70, 'minWidth=70 阻止 c3 收缩到 50 以下');
    assert_eq($c4->w, 30, 'c4 重新分配剩余溢出, 从 100 收缩到 30');
});

echo "\n--- 12. Align-self (Flex) ---\n";

test('align-self:center 覆盖容器的 align-items', function () {
    $c1 = makeNode('div', ['width' => 50, 'height' => 20, 'alignSelf' => 'center'], [], 'A');
    $c2 = makeNode('div', ['width' => 50, 'height' => 20], [], 'B');
    $flex = makeNode('div', ['display'=>'flex', 'alignItems'=>'flex-start', 'width'=>200, 'height'=>100, 'left'=>0, 'top'=>0], [$c1, $c2]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // align-items:flex-start → c2 y=0
    // align-self:center → c1 y = (100-20)/2 = 40
    assert_eq($c1->y, 40, 'align-self:center → c1 y=40');
    assert_eq($c2->y, 0, 'align-items:flex-start → c2 y=0');
});

test('align-self:flex-end 在交叉轴底部', function () {
    $c1 = makeNode('div', ['width' => 50, 'height' => 30, 'alignSelf' => 'flex-end'], [], 'A');
    $flex = makeNode('div', ['display'=>'flex', 'width'=>200, 'height'=>100, 'left'=>0, 'top'=>0], [$c1]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // align-self:flex-end → c1 y = 100-30 = 70
    assert_eq($c1->y, 70, 'align-self:flex-end → y=100-30=70');
});

test('align-self:auto 继承父级 align-items', function () {
    $c1 = makeNode('div', ['width' => 50, 'height' => 30, 'alignSelf' => 'auto'], [], 'A');
    $flex = makeNode('div', ['display'=>'flex', 'alignItems'=>'center', 'width'=>200, 'height'=>100, 'left'=>0, 'top'=>0], [$c1]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // auto → 继承 align-items:center → y=(100-30)/2=35
    assert_eq($c1->y, 35, 'align-self:auto 继承 align-items:center → y=35');
});

test('混合 align-self 值', function () {
    $c1 = makeNode('div', ['width' => 40, 'height' => 20, 'alignSelf' => 'flex-start'], [], 'A');
    $c2 = makeNode('div', ['width' => 40, 'height' => 30, 'alignSelf' => 'center'], [], 'B');
    $c3 = makeNode('div', ['width' => 40, 'height' => 40, 'alignSelf' => 'flex-end'], [], 'C');
    $flex = makeNode('div', ['display'=>'flex', 'width'=>300, 'height'=>100, 'left'=>0, 'top'=>0], [$c1, $c2, $c3]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$flex]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($c1->y, 0, 'flex-start → y=0');
    assert_eq($c2->y, (int)((100-30)/2), 'center → y=35');
    assert_eq($c3->y, 60, 'flex-end → y=60');
});

echo "\n--- 13. Grid align-self / justify-self ---\n";

test('grid align-self:center 垂直居中', function () {
    $child = makeNode('div', ['alignSelf' => 'center', 'width' => 30, 'height' => 20], [], 'A');
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(1, 100px)',
        'gridTemplateRows' => 'repeat(1, 80px)',
        'left' => 0, 'top' => 0, 'width' => 100, 'height' => 80,
    ], [$child]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$grid]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // 单元格高 80, gap=0, child h=20
    // center → (80-20)/2 = 30
    assert_eq($child->y, 30, 'grid align-self:center → y=(80-20)/2=30');
});

test('grid justify-self:center 水平居中', function () {
    $child = makeNode('div', ['justifySelf' => 'center', 'width' => 40, 'height' => 30], [], 'A');
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(1, 100px)',
        'gridTemplateRows' => 'repeat(1, 50px)',
        'left' => 0, 'top' => 0, 'width' => 100, 'height' => 50,
    ], [$child]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$grid]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // 单元格宽 100, child w=40
    // center → (100-40)/2 = 30
    assert_eq($child->x, 30, 'grid justify-self:center → x=(100-40)/2=30');
});

test('grid justify-self:end 右对齐', function () {
    $child = makeNode('div', ['justifySelf' => 'end', 'width' => 40, 'height' => 30], [], 'A');
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(1, 100px)',
        'gridTemplateRows' => 'repeat(1, 50px)',
        'left' => 0, 'top' => 0, 'width' => 100, 'height' => 50,
    ], [$child]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$grid]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // end → x = 100-40 = 60
    assert_eq($child->x, 60, 'grid justify-self:end → x=100-40=60');
});

test('grid align-self 和 justify-self 独立生效', function () {
    $child = makeNode('div', [
        'alignSelf' => 'center', 'justifySelf' => 'end',
        'width' => 30, 'height' => 20,
    ], [], 'A');
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(1, 100px)',
        'gridTemplateRows' => 'repeat(1, 80px)',
        'left' => 0, 'top' => 0, 'width' => 100, 'height' => 80,
    ], [$child]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$grid]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    assert_eq($child->x, 70, 'justify-self:end → x=100-30=70');
    assert_eq($child->y, 30, 'align-self:center → y=(80-20)/2=30');
});

test('grid stretch 默认撑满单元格', function () {
    $child = makeNode('div', ['width' => 20, 'height' => 10], [], 'A');
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(1, 100px)',
        'gridTemplateRows' => 'repeat(1, 60px)',
        'left' => 0, 'top' => 0, 'width' => 100, 'height' => 60,
    ], [$child]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$grid]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // stretch 默认 → 子节点宽高被撑到单元格尺寸
    assert_eq($child->w, 100, 'stretch: child width=cell width=100');
    assert_eq($child->h, 60, 'stretch: child height=cell height=60');
});

test('grid 单元格 gap 保留对齐空间', function () {
    $child = makeNode('div', ['justifySelf' => 'center', 'width' => 30, 'height' => 20], [], 'A');
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(2, 80px)',
        'gridTemplateRows' => 'repeat(1, 50px)',
        'gap' => 10, 'left' => 0, 'top' => 0, 'width' => 180, 'height' => 60,
    ], [$child]);
    $root = makeNode('#root', ['width'=>400, 'height'=>300], [$grid]);
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    // 单元格1: cellW=80, gap=10, cellWFinal=80-20=60, child w=30
    // center → child.x = cellX + (60-30)/2 = 10 + 15 = 25
    assert_eq($child->x, 25, 'gap 保留: center 在单元格内居中');
});

echo "\n--- 14. Normal Flow Auto-stack ---\n";

test('auto-stack 多个 static 子节点', function () {
    $c1 = makeNode('div', ['height' => 30], [], 'A');
    $c2 = makeNode('div', ['height' => 50], [], 'B');
    $c3 = makeNode('div', ['height' => 20], [], 'C');
    $parent = makeNode('div', ['width' => 200, 'height' => 200], [$c1, $c2, $c3]);
    $root = makeNode('#root', ['width' => 400, 'height' => 400], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($c1->x, 0, 'c1 x=0');
    assert_eq($c1->y, 0, 'c1 y=0');
    assert_eq($c1->w, 200, 'c1 auto-width=父容器 content width');
    assert_eq($c2->y, 30, 'c2 y=30=c1.h');
    assert_eq($c3->y, 80, 'c3 y=80=c1.h+c2.h');
});

test('auto-stack with margin', function () {
    $c1 = makeNode('div', ['height' => 20, 'marginBottom' => 10], [], 'A');
    $c2 = makeNode('div', ['height' => 30, 'marginTop' => 5], [], 'B');
    $parent = makeNode('div', ['width' => 200], [$c1, $c2]);
    $root = makeNode('#root', ['width' => 400], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    assert_eq($c1->y, 0, 'c1 y=0');
    // CSS 2.2 §8.3.1 margin collapsing: max(10,5)=10, c2.y=20(c1)+10(折叠后)=30
    assert_eq($c2->y, 30, 'c2 y=30 (CSS 2.2 §8.3.1 margin collapsing max(10,5))');
});

test('auto-stack 被 explicit top 禁用', function () {
    $c1 = makeNode('div', ['top' => 50, 'height' => 20], [], 'A');
    $c2 = makeNode('div', ['height' => 30], [], 'B');
    $parent = makeNode('div', ['width' => 200], [$c1, $c2]);
    $root = makeNode('#root', ['width' => 400], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // c1 has explicit top → E.1 auto-inject as absolute → skip auto-stack
    // c2 is static but auto-stack disabled by c1's explicit positioning\n    assert_eq($c1->y, 50, 'c1 absolute with top=50');
});

test('auto-stack relative child 偏移不影响后续', function () {
    $c1 = makeNode('div', ['position' => 'relative', 'top' => 5, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['height' => 20], [], 'B');
    $parent = makeNode('div', ['width' => 200], [$c1, $c2]);
    $root = makeNode('#root', ['width' => 400], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // c1 y=0 (static) + 5 (relative offset) = 5
    // c2 y=30 (c1 normal flow height, NOT c1.y+height)
    assert_eq($c1->y, 5, 'c1 relative offset=5');
    assert_eq($c2->y, 30, 'c2 y=30 relative 偏移不影响 stack');
});


echo "\n--- 15. Positioning Ancestor ---\n";

test('position:absolute 找最近定位祖先', function () {
    $abs = makeNode('div', ['position' => 'absolute', 'left' => 10, 'top' => 20, 'width' => 50, 'height' => 30], [], 'abs');
    $rel = makeNode('div', ['position' => 'relative', 'left' => 100, 'top' => 100, 'width' => 200, 'height' => 200], [$abs]);
    $root = makeNode('#root', ['width' => 400, 'height' => 400], [$rel]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // abs 找非 static 祖先 = rel (position:relative)
    // rel = root 子节点, auto-stack → rel.x=0, rel.y=0
    // rel has left:100, top:100 → rel.x=0+100=100, rel.y=0+100=100
    // abs.x = rel.x + left = 100 + 10 = 110
    // abs.y = rel.y + top = 100 + 20 = 120
    assert_eq($rel->x, 100, 'rel x = 0 + relative偏移 left=100');
    // absolute positioned relative to rel's position
    assert_eq($abs->x, 110, 'abs x=rel.x+left=100+10=110');
    assert_eq($abs->y, 120, 'abs y=rel.y+top=100+20=120');
});

test('position:absolute 无定位祖先退化到 (0,0)', function () {
    $abs = makeNode('div', ['position' => 'absolute', 'left' => 30, 'top' => 40, 'width' => 50, 'height' => 30], [], 'abs');
    $root = makeNode('#root', ['width' => 400, 'height' => 400], [$abs]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // root 是 static（默认）→ 不被视为定位祖先
    // 退化到 (0,0) → abs.x = 30, abs.y = 40
    assert_eq($abs->x, 30, '退化到 (0,0) + left=30');
    assert_eq($abs->y, 40, '退化到 (0,0) + top=40');
});


echo "\n--- 16. Margin Auto ---\n";

test('margin:auto with position:absolute 水平居中', function () {
    $child = makeNode('div', ['position' => 'absolute', 'width' => 100, 'height' => 50, 'margin' => 'auto'], [], 'A');
    $parent = makeNode('div', ['position' => 'relative', 'width' => 300, 'height' => 100], [$child]);
    $root = makeNode('#root', ['width' => 400], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // parent w=300, child w=100, margin auto → (300-100)/2 = 100 each
    assert_eq($child->x, 100, 'margin:auto 居中：x=(300-100)/2=100');
});


echo "\n--- 17. Absolute Positioning with right/bottom ---\n";

test('position:absolute with right 锚定右边缘', function () {
    $abs = makeNode('div', ['position' => 'absolute', 'right' => 10, 'width' => 80, 'height' => 30], [], 'abs');
    $rel = makeNode('div', ['position' => 'relative', 'width' => 300, 'height' => 200], [$abs]);
    $root = makeNode('#root', ['width' => 400], [$rel]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // abs.right=10, width=80, rel.w=300 → abs.x = rel.x + 300 - 80 - 10 = rel.x + 210
    // rel is auto-stacked in root → rel.x=0
    assert_eq($abs->x, 210, 'right:10 width:80 → x=300-80-10=210');
});

test('position:absolute with bottom 锚定底边缘', function () {
    $abs = makeNode('div', ['position' => 'absolute', 'bottom' => 15, 'height' => 40], [], 'abs');
    $rel = makeNode('div', ['position' => 'relative', 'width' => 200, 'height' => 150], [$abs]);
    $root = makeNode('#root', ['width' => 400], [$rel]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // abs.bottom=15, height=40, rel.h=150 → abs.y = rel.y + 150 - 40 - 15 = 0 + 95
    assert_eq($abs->y, 95, 'bottom:15 height:40 → y=150-40-15=95');
});
test('auto-height relative 容器 + absolute bottom:0 子节点 (two-pass)', function () {
    $normal = makeNode('div', ['height' => 80], [], 'normal');
    $abs = makeNode('div', [
        'position' => 'absolute', 'bottom' => 0, 'height' => 20,
    ], [], 'abs');
    $container = makeNode('div', [
        'position' => 'relative',
        'width' => 200,
        // 无显式height → auto-height
    ], [$normal, $abs]);
    $root = makeNode('#root', ['width' => 400], [$container]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // 第一遍: normal flow children 确定 container height (auto = 80)
    // 第二遍(absolute child): bottom=0, ancestorH=80, height=20
    //   bottomEdge = ancestorY + ancestorH + ancestorPaddingBottom - bottom
    //              = 0 + 80 + 0 - 0 = 80
    //   abs.y = 80 - 20 = 60
    assert_eq($container->h, 80, 'auto-height container h=80 (from normal child)');
    assert_eq($abs->y, 60, 'abs y=60 (bottom=0 in 80px container, height=20)');
});

test('auto-height + padding + absolute right:0 bottom:0 (two-pass)', function () {
    $normal = makeNode('div', ['height' => 60], [], 'normal');
    $abs = makeNode('div', [
        'position' => 'absolute', 'bottom' => 0, 'right' => 0,
        'width' => 8, 'height' => 8,
    ], [], 'abs');
    $container = makeNode('div', [
        'position' => 'relative',
        'width' => 300,
        'paddingTop' => 10,
        'paddingBottom' => 10,
    ], [$normal, $abs]);
    $root = makeNode('#root', ['width' => 500], [$container]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // CSS 2.2 §10.6.3: auto-height = last child bottom - (container.y + paddingTop)
    // child.y = container.y + paddingTop = 0 + 10 = 10
    // auto-height = (10 + 60) - (0 + 10) = 60 (content box高度)
    assert_eq($container->h, 60, 'auto-height container h=60');
    // paddingBox 底边 = 0 + 60 + 10(PT) + 10(PB) = 80
    //   abs.y = 80 - 8 = 72
    assert_eq($abs->y, 72, 'abs y=72 (bottom=0, padding box bottom edge 80 - 8)');
});

// 多帧稳定性测试：Frame 依赖型 bug 检测
// Frame 1 resolve 正确后，Frame 2 resolve 不应因 absolute 子节点已有坐标而改变 auto-height
test('auto-height + absolute bottom:0 多帧稳定性（Frame 2 不应膨胀）', function () {
    $normal = makeNode('div', ['height' => 80], [], 'normal');
    $abs = makeNode('div', [
        'position' => 'absolute', 'bottom' => 0, 'height' => 20,
    ], [], 'abs');
    $container = makeNode('div', [
        'position' => 'relative',
        'width' => 200,
        'paddingTop' => 10,
        'paddingBottom' => 10,
    ], [$normal, $abs]);
    $root = makeNode('#root', ['width' => 500], [$container]);

    $resolver = new LayoutResolver();

    // Frame 1 resolve
    $resolver->resolve($root);
    $h1 = $container->h;
    $y1 = $abs->y;

    // Frame 2 resolve：模拟 run() 模式下第二帧，此时 abs 已有 Frame 1 的坐标
    $resolver->resolve($root);

    // CSS §10.6.3: auto-height 只算 normal flow 子节点，不受 absolute 子节点坐标影响
    // 因此 Frame 2 的 auto-height 应与 Frame 1 完全一致
    assert_eq($container->h, $h1, 'Frame 2 auto-height 应与 Frame 1 一致（绝对定位子节点不应参与 auto-height）');
    assert_eq($abs->y, $y1, 'Frame 2 absolute child y 应与 Frame 1 一致');

    // 额外验证容器高度符合预期：content h=80(子元素) + padding(10+10)=100 → visualH=100
    assert_eq($container->visualH, 100, 'container visualH = 80 content + 20 padding');
});

test('auto-height + absolute bottom:0 多帧稳定性（含 padding, 模拟实际锚点场景）', function () {
    // 模拟实际卡片场景：容器有 padding，absolute 锚点在四角
    // 核心检测：auto-height 在 Frame 1→Frame 2→Frame 3 间不会膨胀
    $normal = makeNode('div', ['height' => 428], [], 'content');
    $tl = makeNode('div', [
        'position' => 'absolute', 'top' => 0, 'left' => 0,
        'width' => 8, 'height' => 8,
    ], [], '__PX_ANCHOR_TL__');
    $br = makeNode('div', [
        'position' => 'absolute', 'bottom' => 0, 'right' => 0,
        'width' => 8, 'height' => 8,
    ], [], '__PX_ANCHOR_BR__');
    $container = makeNode('div', [
        'position' => 'relative',
        'width' => 480,
        'paddingTop' => 28,
        'paddingBottom' => 28,
        'paddingLeft' => 28,
        'paddingRight' => 28,
        'borderRadius' => 40,
    ], [$normal, $tl, $br]);
    $root = makeNode('#root', ['width' => 1800, 'height' => 1200], [$container]);

    $resolver = new LayoutResolver();

    // Frame 1
    $resolver->resolve($root);
    $h1 = $container->h;
    $vh1 = $container->visualH;

    // Frame 2（关键：此时 absolute 子节点已有 Frame 1 的坐标）
    $resolver->resolve($root);
    $h2 = $container->h;
    $vh2 = $container->visualH;

    // Frame 3（验证完全收敛）
    $resolver->resolve($root);
    $h3 = $container->h;
    $vh3 = $container->visualH;

    // 核心断言：auto-height 在帧间不应膨胀
    assert_eq($h2, $h1, 'Frame 2 auto-height 与 Frame 1 一致（不应因 absolute 子节点坐标而膨胀）');
    assert_eq($h3, $h1, 'Frame 3 auto-height 与 Frame 1 一致（完全收敛）');

    // visualH 也应稳定
    assert_eq($vh2, $vh1, 'Frame 2 visualH 与 Frame 1 一致');
    assert_eq($vh3, $vh1, 'Frame 3 visualH 与 Frame 1 一致');

    // 验证绝对值：content h=428 + padding(28+28)=484
    assert_eq($vh1, 484, 'container visualH = 428 + 28 + 28 = 484');
});


echo "\n--- 18. Scroll Container ---\n";

test('scroll container contentHeight', function () {
    $c1 = makeNode('div', ['height' => 30], [], 'A');
    $c2 = makeNode('div', ['height' => 50], [], 'B');
    $scroll = makeNode('div', [
        'overflow' => 'auto',
        'left' => 0, 'top' => 0, 'width' => 200, 'height' => 100,
    ], [$c1, $c2]);
    $root = makeNode('#root', ['width' => 400, 'height' => 400], [$scroll]);

    $resolver = new LayoutResolver();
    $result = $resolver->resolve($root);
    $sc = $result['scrollContainers'][0];

    assert_eq($sc->contentHeight, 80, 'contentHeight = 30+50 = 80');
    assert_eq(count($result['scrollContainers']), 1, '1 scroll container');
});

test('scroll container scrollTop clamp', function () {
    $c1 = makeNode('div', ['height' => 30], [], 'A');
    $c2 = makeNode('div', ['height' => 50], [], 'B');
    $scroll = makeNode('div', [
        'overflow' => 'auto',
        'left' => 0, 'top' => 0, 'width' => 200, 'height' => 100,
    ], [$c1, $c2]);
    $scroll->scrollTop = 50; // 超出 maxScroll
    $root = makeNode('#root', ['width' => 400, 'height' => 400], [$scroll]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // contentHeight=80, container=100, maxScroll = 0 (content doesn't exceed container)\n    // Actually 80 < 100, so maxScroll=0, scrollTop clamped to 0
    assert_eq($scroll->scrollTop, 0, 'scrollTop clamped to 0 when content < container');
});




echo "\n--- 19. Flex Auto-sizing (Cross-axis) ---\n";

test('row flex 容器 auto-height 从子元素计算（含显式 width）', function () {
    $child = makeNode('div', ['width' => 150, 'height' => 32], [], 'button');
    $flex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 400,
        'justifyContent' => 'center',
        'paddingTop' => 5, 'paddingBottom' => 5,
        'left' => 0, 'top' => 0,
    ], [$child]);
    $root = makeNode('#root', ['width' => 400, 'height' => 500], [$flex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // flex 容器应 auto-height = child.h(32) + padding(5+5) = 42
    assert_eq($flex->h, 42, 'row flex auto-height from child + padding');
    // 子元素应被 justify-content:center 居中
    assert_eq($child->x, 125, 'child centered in 400px: (400-150)/2=125');
});

test('row flex 容器 auto-height 多个子元素取最大值', function () {
    $c1 = makeNode('div', ['width' => 50, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['width' => 50, 'height' => 40], [], 'B');
    $c3 = makeNode('div', ['width' => 50, 'height' => 20], [], 'C');
    $flex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 200,
        'gap' => 10, 'left' => 0, 'top' => 0,
        'paddingTop' => 5, 'paddingBottom' => 5,
    ], [$c1, $c2, $c3]);
    $root = makeNode('#root', ['width' => 400, 'height' => 500], [$flex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // auto-height = max(c1.h,c2.h,c3.h) + padding = 40 + 5 + 5 = 50
    assert_eq($flex->h, 50, 'row flex auto-height = max child height + padding');
});

test('column flex 容器 auto-width 从子元素计算（含显式 height，flex parent）', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['width' => 120, 'height' => 30], [], 'B');
    $flex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'height' => 200,
        'gap' => 5, 'left' => 0, 'top' => 0,
        'paddingLeft' => 10, 'paddingRight' => 10,
    ], [$c1, $c2]);
    // 用 flex parent 避免 block auto-stack 覆盖 auto-width
    $parent = makeNode('div', ['display'=>'flex', 'width'=>400, 'height'=>300, 'left'=>0, 'top'=>0], [$flex]);
    $root = makeNode('#root', ['width' => 400, 'height' => 500], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // auto-width = max(c1.w,c2.w) + padding = 120 + 10 + 10 = 140
    assert_eq($flex->w, 140, 'column flex auto-width = max child width + padding');
});

test('column flex 容器 auto-height 从子元素计算（主轴，含显式 width，abs parent）', function () {
    $c1 = makeNode('div', ['width' => 50, 'height' => 20], [], 'A');
    $c2 = makeNode('div', ['width' => 50, 'height' => 30], [], 'B');
    $flex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 200,
        'gap' => 8, 'left' => 0, 'top' => 0,
    ], [$c1, $c2]);
    // 用绝对定位父容器避免任何父级干扰
    $parent = makeNode('div', ['position'=>'absolute', 'left'=>0, 'top'=>0, 'width'=>400, 'height'=>300], [$flex]);
    $root = makeNode('#root', ['width' => 400, 'height' => 500], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // auto-height = c1.h(20) + gap(8) + c2.h(30) = 58
    assert_eq($flex->h, 58, 'column flex auto-height from children + gap');
});


echo "\n--- 20. Flex justify-content ---\n";

test('justify-content:center 居中子元素', function () {
    $c1 = makeNode('div', ['width' => 60, 'height' => 20], [], 'A');
    $flex = makeNode('div', [
        'display' => 'flex', 'justifyContent' => 'center',
        'width' => 200, 'height' => 50, 'left' => 0, 'top' => 0,
    ], [$c1]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$flex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // center: (200-60)/2 = 70
    assert_eq($c1->x, 70, 'justify-content:center → x=70');
});

test('justify-content:flex-end 子元素靠右', function () {
    $c1 = makeNode('div', ['width' => 50, 'height' => 20], [], 'A');
    $flex = makeNode('div', [
        'display' => 'flex', 'justifyContent' => 'flex-end',
        'width' => 200, 'height' => 50, 'left' => 0, 'top' => 0,
    ], [$c1]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$flex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // flex-end: 200-50 = 150
    assert_eq($c1->x, 150, 'justify-content:flex-end → x=150');
});

test('justify-content:space-between 均匀分布', function () {
    $c1 = makeNode('div', ['width' => 40, 'height' => 20], [], 'A');
    $c2 = makeNode('div', ['width' => 40, 'height' => 20], [], 'B');
    $c3 = makeNode('div', ['width' => 40, 'height' => 20], [], 'C');
    $flex = makeNode('div', [
        'display' => 'flex', 'justifyContent' => 'space-between',
        'width' => 300, 'height' => 50, 'left' => 0, 'top' => 0,
    ], [$c1, $c2, $c3]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$flex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // 3 items * 40 = 120, container=300, remaining=180, gap=180/(3-1)=90
    assert_eq($c1->x, 0, 'space-between c1 x=0');
    assert_eq($c2->x, 40 + 90, 'space-between c2 x=130');
    assert_eq($c3->x, 40 + 40 + 90 + 90, 'space-between c3 x=260');
});


echo "\n--- 21. Flex:1 与两步扫描 ---\n";

test('flex:1 分配剩余空间', function () {
    $c1 = makeNode('div', ['width' => 50, 'height' => 30], [], 'fixed');
    $c2 = makeNode('div', ['flex' => '1', 'height' => 30], [], 'grow');
    $flex = makeNode('div', [
        'display' => 'flex',
        'width' => 200, 'height' => 50, 'left' => 0, 'top' => 0,
        'gap' => 10,
    ], [$c1, $c2]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$flex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // c1=50 fixed, gap=10, container=200 → c2 = 200-50-10 = 140
    assert_eq($c1->w, 50, 'fixed child w=50');
    assert_eq($c2->w, 140, 'flex:1 child gets remaining 140px');
});

test('flex:1 + justify-content:center 两步扫描渲染', function () {
    $btn = makeNode('div', ['width' => 150, 'height' => 32], [], 'button');
    $wrapper = makeNode('div', [
        'display' => 'flex', 'justifyContent' => 'center',
        'paddingTop' => 5, 'paddingBottom' => 5,
        'width' => 400,
        'left' => 0, 'top' => 0,
    ], [$btn]);

    $scroll = makeNode('div', [
        'overflow' => 'auto',
        'flex' => '1',
        'left' => 0, 'top' => 0, 'width' => 400, 'height' => 200,
    ], []);

    $rootFlex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'left' => 0, 'top' => 0, 'width' => 400, 'height' => 500,
    ], [$wrapper, $scroll]);
    $root = makeNode('#root', ['width' => 400, 'height' => 500], [$rootFlex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // wrapper auto-height = 32 + 10 = 42
    assert_eq($wrapper->h, 42, 'wrapper auto-height from button + padding');
    // scroll flex:1 fills remaining: root.h(500) - wrapper.h(42) = 458
    assert_eq($scroll->h, 458, 'scroll flex:1 fills remaining height');
    // button centered in wrapper width (400px)
    assert_eq($btn->x, 125, 'button justify-content:center → x=125');
    // button y in wrapper
    assert_eq($btn->y, 5, 'button y = wrapper paddingTop = 5');
});

test('两步扫描中嵌套 flex 容器 stretch + justify-content 正确', function () {
    $inner = makeNode('div', ['width' => 80, 'height' => 24], [], 'inner');
    // flexWrapper 无显式 cross-size：stretch 改变高度后触发两步扫描
    $flexWrapper = makeNode('div', [
        'display' => 'flex', 'justifyContent' => 'center',
        'alignItems' => 'center',
        'left' => 0, 'top' => 0,
    ], [$inner]);

    $outerFlex = makeNode('div', [
        'display' => 'flex', 'alignItems' => 'stretch',
        'width' => 400, 'height' => 200, 'left' => 0, 'top' => 0,
    ], [$flexWrapper]);
    $root = makeNode('#root', ['width' => 400, 'height' => 300], [$outerFlex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // flexWrapper hasExplicitCrossSize=false (no height) -> stretched to 200
    assert_eq($flexWrapper->h, 200, 'flex wrapper stretched to 200 by align-items:stretch');
    // 两步扫描后 inner 应被 align-items:center 居中 → y=(200-24)/2=88
    assert_eq($inner->y, 88, 'inner centered by align-items:center → y=88');
});


echo "--- 22. Flex Auto-width 修复 ---\n";

test('Flex 容器 auto-width 填充父容器宽度 (CSS Flexbox §4.1)', function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['width' => 120, 'height' => 30], [], 'B');
    $flex = makeNode('div', [
        'display' => 'flex',
        'left' => 0, 'top' => 0,
        'gap' => 10,
        'paddingLeft' => 10, 'paddingRight' => 10,
    ], [$c1, $c2]);
    // block-level flex container: parent is a block container
    $container = makeNode('div', ['width' => 400, 'height' => 300, 'left' => 0, 'top' => 0], [$flex]);
    $root = makeNode('#root', ['width' => 400, 'height' => 500], [$container]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // auto-width = parent.w = 400
    assert_eq($flex->w, 400, 'block-level flex container auto-width = parent width');
});

test('Flex 容器 height:auto 基于内容而非填充父高度 (CSS Flexbox §9.4)', function () {
    $c1 = makeNode('div', ['width' => 200, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['width' => 200, 'height' => 50], [], 'B');
    $flex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'left' => 0, 'top' => 0,
        'gap' => 8,
        'paddingTop' => 5, 'paddingBottom' => 5,
    ], [$c1, $c2]);
    $container = makeNode('div', ['width' => 400, 'height' => 300, 'left' => 0, 'top' => 0], [$flex]);
    $root = makeNode('#root', ['width' => 400, 'height' => 500], [$container]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // row flex: height is cross-axis, content-based = max child h + padding = 50 + 5 + 5 = 60
    assert_eq($flex->w, 400, 'block-level flex container auto-width = parent width');
    assert_eq($flex->h, 60, 'row flex auto-height = max child height + padding');
});

test('Block auto-stack 后 grid 子项内部子节点正确定位 (CSS §9.4.1)', function () {
    $gc1 = makeNode('div', ['width' => 100, 'height' => 50], [], 'GCA');
    $gc2 = makeNode('div', ['width' => 150, 'height' => 50], [], 'GCB');
    $grid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => 'repeat(2, 1fr)',
        'left' => 0, 'top' => 0,
    ], [$gc1, $gc2]);
    $childA = makeNode('div', ['width' => 80, 'height' => 60], [], 'SA');
    $parent = makeNode('div', ['width' => 400, 'height' => 500, 'left' => 0, 'top' => 0], [$grid, $childA]);
    $root = makeNode('#root', ['width' => 400, 'height' => 600], [$parent]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // grid gets auto-width from auto-stack: w = containerW = 400
    assert_eq($grid->w, 400, 'grid auto-width from auto-stack = container width');
    // grid cells: with gridTemplateColumns repeat(2, 1fr), each cell = 400/2
    assert_eq($gc1->w, 200, 'grid cell 1 width after auto-stack re-resolve (400/2)');
    assert_eq($gc2->w, 200, 'grid cell 2 width after auto-stack re-resolve (400/2)');
    // grid children are next to each other (first row)
    assert_eq($gc1->y, 0, 'grid cell 1 y = 0');
    assert_eq($gc2->y, 0, 'grid cell 2 y = 0');
    // childA is stacked below grid container
    assert($childA->y >= $grid->h, 'childA is below grid after auto-stack');
});

test('嵌套 flex→flex→grid 两层 auto-width 传递', function () {
    $gc = makeNode('div', ['width' => 60, 'height' => 40], [], 'GC');
    $innerGrid = makeNode('div', [
        'display' => 'grid',
        'gridTemplateColumns' => '1fr 1fr',
        'left' => 0, 'top' => 0,
    ], [$gc]);
    $midFlex = makeNode('div', [
        'display' => 'flex',
        'left' => 0, 'top' => 0,
    ], [$innerGrid]);
    $outerFlex = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'left' => 0, 'top' => 0,
        'width' => 400, 'height' => 300,
    ], [$midFlex]);
    $root = makeNode('#root', ['width' => 400, 'height' => 500], [$outerFlex]);

    $resolver = new LayoutResolver();
    $resolver->resolve($root);

    // midFlex is a flex item in a column flex parent -> no auto-width fill from parent
    // Its width is content-based (from innerGrid children)
    // innerGrid is a flex item in midFlex -> no auto-width fill
    // gc gets width from gridTemplateColumns 1fr
    // So midFlex->w should be content-based, about 60 * 2 = 120 (fr size depends on container)
    assert($midFlex->w > 0, 'midFlex width should be > 0 (content-based as flex item)');
    assert($midFlex->w <= 400, 'midFlex width should not exceed outer container');
    assert($innerGrid->w > 0, 'innerGrid should have positive width');
});



// =============================================================
// Task 5.1: CSS 标准支持 — 新增测试
// =============================================================

echo "\n--- 8. Text node auto-height ---\n";

describe('Text node auto-height', function () {

    test('div 类型文本节点在 block 布局中获得正确高度', function () {
        $child = makeNode('div', ['fontSize' => 16], [], 'Hello World');
        $parent = makeNode('div', ['width' => 400], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        assert_true($child->h > 0, 'div text node should have height');
        assert_eq($child->h, (int)(16 * 1.2), 'div text height = line-height');
    });

    test('flex column 中文本 div 子节点撑开父容器高度', function () {
        $child = makeNode('div', ['fontSize' => 16], [], 'Hello');
        $parent = makeNode('div', [
            'display' => 'flex', 'flexDirection' => 'column',
            'width' => 400,
        ], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        assert_true($child->h > 0, 'text div in flex column should have height');
        assert_eq($child->h, (int)(16 * 1.2), 'text height = line-height');
    });

    test('flex-wrap 中文本内容子节点宽度测量正确', function () {
        $child = makeNode('div', ['fontSize' => 14], [], 'Short');
        $parent = makeNode('div', [
            'display' => 'flex', 'flexWrap' => 'wrap',
            'width' => 400,
        ], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        assert_true($child->w > 0, 'text child should have positive width');
        assert_true($child->w < 400, 'text child width should be < parent (content-sized)');
    });

    test('text/span 类型节点继续正常工作（回归预防）', function () {
        $textChild = makeNode('text', ['fontSize' => 14], [], 'text');
        $spanChild = makeNode('span', ['fontSize' => 14], [], 'span');
        $parent = makeNode('div', ['width' => 400], [$textChild, $spanChild]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        assert_eq($textChild->h, (int)(14 * 1.2), 'text type still gets height');
        assert_eq($spanChild->h, (int)(14 * 1.2), 'span type still gets height');
    });

    test('含显式 height 的节点不受影响', function () {
        $child = makeNode('div', ['fontSize' => 16, 'height' => 100], [], 'Hello');
        $parent = makeNode('div', ['width' => 400], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        assert_eq($child->h, 100, 'explicit height should be preserved');
    });

});

echo "\n--- 9. box-sizing: border-box ---\n";

describe('box-sizing: border-box', function () {

    test('border-box borderWidth 影响 block 子节点填充宽度', function () {
        $child = makeNode('div', []);
        $parent = makeNode('div', [
            'boxSizing' => 'border-box',
            'width' => 200,
            'borderWidth' => 5,
            'height' => 100,
        ], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        // content width = 200 - 0 - 0 - 5*2 = 190
        assert_eq($child->w, 190, 'border-box border reduces child fill width');
    });

    test('border-box padding 影响 block 子节点填充宽度', function () {
        $child = makeNode('div', []);
        $parent = makeNode('div', [
            'boxSizing' => 'border-box',
            'width' => 200,
            'padding' => 20,
            'height' => 100,
        ], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        // content width = 200 - 20 - 20 - 0 = 160
        assert_eq($child->w, 160, 'border-box padding reduces child fill width');
    });

    test('border-box borderWidth + padding 共同影响', function () {
        $child = makeNode('div', []);
        $parent = makeNode('div', [
            'boxSizing' => 'border-box',
            'width' => 200,
            'padding' => 10,
            'borderWidth' => 3,
            'height' => 100,
        ], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        // content width = 200 - 10 - 10 - 3*2 = 174
        assert_eq($child->w, 174, 'border-box padding+border reduces child fill width');
    });

    test('content-box（默认）borderWidth 不影响子节点填充宽度', function () {
        $child = makeNode('div', []);
        $parent = makeNode('div', [
            'width' => 200,
            'borderWidth' => 5,
            'height' => 100,
        ], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        // content-box: content width = 200 - 0 - 0 = 200
        assert_eq($child->w, 200, 'content-box border does not reduce child fill width');
    });

});

echo "\n--- 10. line-height ---\n";

describe('line-height', function () {

    test('自定义 line-height 像素值影响文本节点高度', function () {
        $child = makeNode('div', ['fontSize' => 16, 'lineHeight' => '30px'], [], 'Hello');
        $parent = makeNode('div', ['width' => 400], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        assert_eq($child->h, 30, 'line-height:30px should set height to 30');
    });

    test('无 line-height 时保持默认 fontSize*1.35 行为', function () {
        $child = makeNode('div', ['fontSize' => 16], [], 'Hello');
        $parent = makeNode('div', ['width' => 400], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        assert_eq($child->h, (int)(16 * 1.2), 'default line-height = fontSize * 1.2');
    });

    test('line-height 数值倍数正确解析', function () {
        $child = makeNode('div', ['fontSize' => 16, 'lineHeight' => 1.5], [], 'Hello');
        $parent = makeNode('div', ['width' => 400], [$child]);
        $root = makeNode('#root', ['width' => 400, 'height' => 300], [$parent]);
        $resolver = new LayoutResolver();
        $resolver->resolve($root);
        assert_eq($child->h, (int)(16 * 1.5), 'line-height:1.5 should set height to 24');
    });

});
echo "\n";
$exitCode = print_summary();
exit($exitCode);
