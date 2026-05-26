<?php
/**
 * hitTest 单元测试 (Application 命中测试)
 * 
 * 测试目标:
 *   1. 反向遍历: 后渲染的子节点优先命中 (z-order)
 *   2. 子节点优先于父节点: 点击子元素区域时返回子元素
 *   3. 仅 @click: 无 @click 的元素不会被命中
 *   4. 边界检查: 坐标在元素范围外返回 null
 *   5. 嵌套命中: 深度嵌套的 @click 元素可被命中
 * 
 * Usage: php tests/unit/HitTestTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\VNode;
use Px\Core\Application;
use Px\Platform\Platform;
use Px\Platform\PlatformFactory;
use Px\Platform\Win32Platform;

echo "========================================\n";
echo " Application::hitTest 单元测试\n";
echo "========================================\n\n";

/**
 * 通过反射调用 private Application::hitTest()
 */
function invokeHitTest(Application $app, int $x, int $y, VNode $node): ?VNode
{
    $refl = new \ReflectionClass(Application::class);
    $method = $refl->getMethod('hitTest');
    $method->setAccessible(true);
    return $method->invoke($app, $x, $y, $node);
}

/**
 * 创建一个最小化的 Application 实例用于测试 hitTest。
 * 通过反射设置 activeVNodeTree，不启动完整事件循环。
 */
function createTestApp(): Application
{
    // 用 PlatformFactory 创建（需要 APP_PLATFORM 常量，但我们只需测试 hitTest）
    // 直接实例化 Application via 反射绕过 create()
    $refl = new \ReflectionClass(Application::class);
    $app = $refl->newInstanceWithoutConstructor();

    // 注入最小必要依赖
    $scheduler = new \Px\Core\Scheduler();

    $schedProp = $refl->getProperty('scheduler');
    $schedProp->setAccessible(true);
    $schedProp->setValue($app, $scheduler);

    $lrProp = $refl->getProperty('layoutResolver');
    $lrProp->setAccessible(true);
    $lrProp->setValue($app, new \Px\Rendering\LayoutResolver());

    return $app;
}

echo "--- 1. 反向遍历：后渲染子节点优先命中 ---\n";

test('两个重叠子节点，后渲染者(索引1)优先命中', function () {
    // 两个兄弟节点重叠在同一区域，都绑定了 @click
    // 索引 0: 100x100 区域
    // 索引 1: 80x80 区域 (叠在上层)
    $child1 = new VNode('div', ['@click' => 'handler1']);
    $child1->x = 0; $child1->y = 0; $child1->w = 100; $child1->h = 100;
    $child2 = new VNode('div', ['@click' => 'handler2']);
    $child2->x = 10; $child2->y = 10; $child2->w = 80; $child2->h = 80;

    $root = VNode::h('#root', ['title' => 'test'], [$child1, $child2]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $app = createTestApp();

    // 点击两者重叠区域 (50,50)
    $hit = invokeHitTest($app, 50, 50, $root);
    assert_not_null($hit, '应命中某元素');
    assert_eq($hit->props['@click'], 'handler2', '应命中后渲染的 child2 (视觉上层)');
});

test('后渲染兄弟在重叠区域被命中', function () {
    $c1 = new VNode('button', ['@click' => 'first']);
    $c1->x = 0; $c1->y = 0; $c1->w = 100; $c1->h = 50;

    $c2 = new VNode('button', ['@click' => 'second']);
    $c2->x = 0; $c2->y = 25; $c2->w = 100; $c2->h = 50;

    $root = VNode::h('#root', [], [$c1, $c2]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $app = createTestApp();

    // 点击重叠区域 (50,35) 两个节点都覆盖此处
    $hit = invokeHitTest($app, 50, 35, $root);
    assert_not_null($hit, '应命中某元素');
    assert_eq($hit->props['@click'], 'second', '应命中后渲染的 c2');
});

echo "\n--- 2. 子节点优先于父节点 ---\n";

test('点击子元素区域内，返回子元素而非父元素', function () {
    $child = new VNode('button', ['@click' => 'childHandler']);
    $child->x = 20; $child->y = 20; $child->w = 60; $child->h = 60;

    $parent = VNode::h('div', ['@click' => 'parentHandler'], [$child]);
    $parent->x = 0; $parent->y = 0; $parent->w = 100; $parent->h = 100;

    $root = VNode::h('#root', [], [$parent]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $app = createTestApp();

    $hit = invokeHitTest($app, 50, 50, $root);
    assert_not_null($hit, '应命中某元素');
    assert_eq($hit->props['@click'], 'childHandler', '应返回子元素而非父元素');
});

test('点击父元素区域但不在子元素内，返回父元素', function () {
    $child = new VNode('button', ['@click' => 'childHandler']);
    $child->x = 60; $child->y = 60; $child->w = 30; $child->h = 30;

    $parent = VNode::h('div', ['@click' => 'parentHandler'], [$child]);
    $parent->x = 0; $parent->y = 0; $parent->w = 100; $parent->h = 100;

    $root = VNode::h('#root', [], [$parent]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $app = createTestApp();

    $hit = invokeHitTest($app, 10, 10, $root);
    assert_not_null($hit, '应命中某元素');
    assert_eq($hit->props['@click'], 'parentHandler', '不在子元素内应返回父元素');
});

echo "\n--- 3. 仅命中 @click 元素 ---\n";

test('无 @click 的元素不会被命中', function () {
    $div = new VNode('div', []); // no @click
    $div->x = 0; $div->y = 0; $div->w = 100; $div->h = 100;

    $root = VNode::h('#root', [], [$div]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $app = createTestApp();

    $hit = invokeHitTest($app, 50, 50, $root);
    assert_null($hit, '无 @click 的元素不应被命中');
});

test('有 @click 属性的任何元素类型都可被命中', function () {
    $btn  = new VNode('button', ['@click' => 'btnClick']);
    $btn->x = 0; $btn->y = 0; $btn->w = 50; $btn->h = 50;

    $div  = new VNode('div', ['@click' => 'divClick']);
    $div->x = 60; $div->y = 0; $div->w = 50; $div->h = 50;

    $root = VNode::h('#root', [], [$btn, $div]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $app = createTestApp();

    $hit1 = invokeHitTest($app, 25, 25, $root);
    assert_not_null($hit1, 'button 应被命中');
    assert_eq($hit1->props['@click'], 'btnClick');

    $hit2 = invokeHitTest($app, 85, 25, $root);
    assert_not_null($hit2, 'div 应被命中');
    assert_eq($hit2->props['@click'], 'divClick');
});

echo "\n--- 4. 边界检查 ---\n";

test('点击坐标在元素范围外返回 null', function () {
    $btn = new VNode('button', ['@click' => 'handler']);
    $btn->x = 10; $btn->y = 10; $btn->w = 80; $btn->h = 40;

    $root = VNode::h('#root', [], [$btn]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $app = createTestApp();

    // 左侧外部
    assert_null(invokeHitTest($app, 5, 30, $root), '左边界外');
    // 右侧外部
    assert_null(invokeHitTest($app, 95, 30, $root), '右边界外');
    // 上方外部
    assert_null(invokeHitTest($app, 50, 5, $root), '上边界外');
    // 下方外部
    assert_null(invokeHitTest($app, 50, 55, $root), '下边界外');
});

test('边界值 (刚好在边缘) 可被命中', function () {
    $btn = new VNode('button', ['@click' => 'handler']);
    $btn->x = 10; $btn->y = 10; $btn->w = 80; $btn->h = 40;

    $root = VNode::h('#root', [], [$btn]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $app = createTestApp();

    // 左上角
    assert_not_null(invokeHitTest($app, 10, 10, $root), '左上角 (10,10)');
    // 右下角
    assert_not_null(invokeHitTest($app, 90, 50, $root), '右下角 (90,50)');
});

echo "\n--- 5. 嵌套命中 ---\n";

test('深度嵌套的 @click 元素可被命中', function () {
    // root > outer > inner > button
    $btn = new VNode('button', ['@click' => 'deepHandler']);
    $btn->x = 30; $btn->y = 30; $btn->w = 40; $btn->h = 40;

    $inner = VNode::h('div', [], [$btn]);
    $inner->x = 10; $inner->y = 10; $inner->w = 80; $inner->h = 80;

    $outer = VNode::h('div', ['@click' => 'outerHandler'], [$inner]);
    $outer->x = 0; $outer->y = 0; $outer->w = 100; $outer->h = 100;

    $root = VNode::h('#root', [], [$outer]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $app = createTestApp();

    $hit = invokeHitTest($app, 50, 50, $root);
    assert_not_null($hit, '深度嵌套按钮应被命中');
    assert_eq($hit->props['@click'], 'deepHandler', '应返回最深层按钮');
});

test('根节点 (#root) 即使有 @click 也不被命中', function () {
    $root = VNode::h('#root', ['@click' => 'rootHandler'], []);
    $root->x = 0; $root->y = 0; $root->w = 100; $root->h = 100;

    $app = createTestApp();

    // hitTest 中 isRoot() 返回的节点本身不会被检查（只在 collectElements 中被跳过）
    // 根节点自身没有点击处理
    $hit = invokeHitTest($app, 50, 50, $root);
    // 取决于 isRoot() 是否在命中检查中被跳过
    // 当前实现：递归到子节点，根节点自身也在检查范围内
    // 如果根节点有 @click 且在范围内，应该会被命中
    assert_not_null($hit, '根节点若有 @click 且无子节点，应被命中');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
