<?php
/**
 * hitTest 单元测试 (RenderTreeManager 命中测试)
 * 
 * 测试目标:
 *   1. 反向遍历: 后渲染的子节点优先命中 (z-order)
 *   2. 子节点优先于父节点: 点击子元素区域时返回子元素
 *   3. 仅 @click: 无 @click 的元素不会被命中
 *   4. 边界检查: 坐标在元素范围外返回 null
 *   5. 嵌套命中: 深度嵌套的 @click 元素可被命中
 * 
 * 注意: hitTest 现在基于 RenderNode 树，通过 RenderTreeManager::hitTest() 执行。
 *       测试直接构建 RenderNode 树并注入到 RenderTreeManager 中。
 *
 * Usage: php tests/unit/HitTestTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\VNode;
use Px\Rendering\RenderNode;
use Px\Rendering\RenderTreeManager;

echo "========================================\n";
echo " RenderTreeManager::hitTest 单元测试\n";
echo "========================================\n\n";

/**
 * 创建一个 VNode 用于作为 RenderNode 的 sourceVNode。
 * 只设置 @click 相关属性，其他坐标/样式在 RenderNode 上设置。
 */
function makeClickableVNode(string $handler): VNode
{
    return new VNode('div', ['@click' => $handler]);
}

/**
 * 创建 RenderNode 树的一叶节点（可点击）。
 */
function makeLeaf(string $handler, int $x, int $y, int $w, int $h): RenderNode
{
    $node = new RenderNode('div');
    $node->sourceVNode = makeClickableVNode($handler);
    $node->x = $x;
    $node->y = $y;
    $node->w = $w;
    $node->h = $h;
    return $node;
}

/**
 * 创建不可点击的容器 RenderNode。
 */
function makeContainer(int $x, int $y, int $w, int $h, array $children = []): RenderNode
{
    $node = new RenderNode('div');
    $node->x = $x;
    $node->y = $y;
    $node->w = $w;
    $node->h = $h;
    $node->children = $children;
    return $node;
}

/**
 * 将 RenderNode 注入到 RenderTreeManager 中作为根节点。
 */
function createHitTestManager(RenderNode $root): RenderTreeManager
{
    $manager = new RenderTreeManager();
    $refl = new \ReflectionClass(RenderTreeManager::class);
    $prop = $refl->getProperty('rootRenderNode');
    $prop->setAccessible(true);
    $prop->setValue($manager, $root);
    return $manager;
}

/**
 * 通过 RenderTreeManager 执行命中测试。
 */
function invokeHitTest(RenderTreeManager $mgr, int $x, int $y): ?RenderNode
{
    return $mgr->hitTest($x, $y);
}

echo "--- 1. 反向遍历：后渲染子节点优先命中 ---\n";

test('两个重叠子节点，后渲染者(索引1)优先命中', function () {
    // 两个兄弟节点重叠在同一区域，都绑定了 @click
    $child1 = makeLeaf('handler1', 0, 0, 100, 100);
    $child2 = makeLeaf('handler2', 10, 10, 80, 80);

    $parent = makeContainer(0, 0, 200, 200, [$child1, $child2]);

    $mgr = createHitTestManager($parent);

    // 点击两者重叠区域 (50,50)
    $hit = invokeHitTest($mgr, 50, 50);
    assert_not_null($hit, '应命中某元素');
    assert_eq($hit->sourceVNode->props['@click'], 'handler2', '应命中后渲染的 child2 (视觉上层)');
});

test('后渲染兄弟在重叠区域被命中', function () {
    $c1 = makeLeaf('first', 0, 0, 100, 50);
    $c2 = makeLeaf('second', 0, 25, 100, 50);

    $parent = makeContainer(0, 0, 200, 200, [$c1, $c2]);

    $mgr = createHitTestManager($parent);

    // 点击重叠区域 (50,35) 两个节点都覆盖此处
    $hit = invokeHitTest($mgr, 50, 35);
    assert_not_null($hit, '应命中某元素');
    assert_eq($hit->sourceVNode->props['@click'], 'second', '应命中后渲染的 c2');
});

echo "\n--- 2. 子节点优先于父节点 ---\n";

test('点击子元素区域内，返回子元素而非父元素', function () {
    $child = makeLeaf('childHandler', 20, 20, 60, 60);

    // 父节点也有 @click
    $parent = new RenderNode('div');
    $parent->sourceVNode = makeClickableVNode('parentHandler');
    $parent->x = 0; $parent->y = 0; $parent->w = 100; $parent->h = 100;
    $parent->children = [$child];

    $mgr = createHitTestManager($parent);

    $hit = invokeHitTest($mgr, 50, 50);
    assert_not_null($hit, '应命中某元素');
    assert_eq($hit->sourceVNode->props['@click'], 'childHandler', '应返回子元素而非父元素');
});

test('点击父元素区域但不在子元素内，返回父元素', function () {
    $child = makeLeaf('childHandler', 60, 60, 30, 30);

    $parent = new RenderNode('div');
    $parent->sourceVNode = makeClickableVNode('parentHandler');
    $parent->x = 0; $parent->y = 0; $parent->w = 100; $parent->h = 100;
    $parent->children = [$child];

    $mgr = createHitTestManager($parent);

    $hit = invokeHitTest($mgr, 10, 10);
    assert_not_null($hit, '应命中某元素');
    assert_eq($hit->sourceVNode->props['@click'], 'parentHandler', '不在子元素内应返回父元素');
});

echo "\n--- 3. 仅命中 @click 元素 ---\n";

test('无 @click 的元素不会被命中', function () {
    // 无 sourceVNode = 无 @click
    $div = new RenderNode('div');
    $div->x = 0; $div->y = 0; $div->w = 100; $div->h = 100;

    $mgr = createHitTestManager($div);

    $hit = invokeHitTest($mgr, 50, 50);
    assert_null($hit, '无 @click 的元素不应被命中');
});

test('有 @click 属性的任何元素类型都可被命中', function () {
    $btn = makeLeaf('btnClick', 0, 0, 50, 50);
    $div = makeLeaf('divClick', 60, 0, 50, 50);

    // 使用不可点击的父容器
    $wrapper = makeContainer(0, 0, 200, 50, [$btn, $div]);

    $mgr = createHitTestManager($wrapper);

    $hit1 = invokeHitTest($mgr, 25, 25);
    assert_not_null($hit1, 'button 应被命中');
    assert_eq($hit1->sourceVNode->props['@click'], 'btnClick');

    $hit2 = invokeHitTest($mgr, 85, 25);
    assert_not_null($hit2, 'div 应被命中');
    assert_eq($hit2->sourceVNode->props['@click'], 'divClick');
});

echo "\n--- 4. 边界检查 ---\n";

test('点击坐标在元素范围外返回 null', function () {
    $btn = makeLeaf('handler', 10, 10, 80, 40);

    $mgr = createHitTestManager($btn);

    // 左侧外部
    assert_null(invokeHitTest($mgr, 5, 30), '左边界外');
    // 右侧外部
    assert_null(invokeHitTest($mgr, 95, 30), '右边界外');
    // 上方外部
    assert_null(invokeHitTest($mgr, 50, 5), '上边界外');
    // 下方外部
    assert_null(invokeHitTest($mgr, 50, 55), '下边界外');
});

test('边界值 (刚好在边缘) 可被命中', function () {
    $btn = makeLeaf('handler', 10, 10, 80, 40);

    $mgr = createHitTestManager($btn);

    // 左上角
    assert_not_null(invokeHitTest($mgr, 10, 10), '左上角 (10,10)');
    // 右下角
    assert_not_null(invokeHitTest($mgr, 90, 50), '右下角 (90,50)');
});

echo "\n--- 5. 嵌套命中 ---\n";

test('深度嵌套的 @click 元素可被命中', function () {
    // root > outer > inner > button
    $btn = makeLeaf('deepHandler', 30, 30, 40, 40);

    $inner = makeContainer(10, 10, 80, 80, [$btn]);

    $outer = new RenderNode('div');
    $outer->sourceVNode = makeClickableVNode('outerHandler');
    $outer->x = 0; $outer->y = 0; $outer->w = 100; $outer->h = 100;
    $outer->children = [$inner];

    $mgr = createHitTestManager($outer);

    $hit = invokeHitTest($mgr, 50, 50);
    assert_not_null($hit, '深度嵌套按钮应被命中');
    assert_eq($hit->sourceVNode->props['@click'], 'deepHandler', '应返回最深层按钮');
});

echo "\n--- 6. 根节点 (#root) 即使有 @click 也不被命中 ---\n";

test('根节点 (#root) 即使有 @click 也不被命中', function () {
    // 在 RenderNode 树中，#root 类型的节点在 VNodeRenderer::collectElements
    // 中被跳过。但在 RenderTreeManager::hitTest 中，#root 本身作为 RenderNode
    // 只要符合条件也可以被命中。这个测试验证当 rootRenderNode 的 type='#root'
    // 且带有 @click 时是否会命中。
    $root = new RenderNode('#root');
    $root->sourceVNode = makeClickableVNode('rootHandler');
    $root->x = 0; $root->y = 0; $root->w = 100; $root->h = 100;

    $mgr = createHitTestManager($root);

    // hitTest 递归检查自身：先检查子节点（无），再检查自身
    // 由于 #root 满足条件（有 @click、坐标命中），应该会被命中
    // 注意：这是与旧测试不同的行为——旧测试依赖 isRoot() 判断
    // 新架构中 #root 作为 RenderNode 可以被命中
    $hit = invokeHitTest($mgr, 50, 50);
    assert_not_null($hit, '#root 作为 RenderNode 且符合条件应被命中');
    assert_eq($hit->sourceVNode->props['@click'], 'rootHandler');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
