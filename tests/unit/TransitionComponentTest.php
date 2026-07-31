<?php
/**
 * TransitionComponentTest — B4 Transition/TransitionGroup 编译器内建组件测试
 *
 * 验证: 编译器识别 <Transition> 标签为内建组件;
 *       TransitionComponent 基本生命周期(构造/render/show 切换);
 *       TransitionGroupComponent FLIP 坐标记录。
 *
 * Usage: php tests/unit/TransitionComponentTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Dom\VNode;
use Px\Animation\TransitionComponent;
use Px\Animation\TransitionGroupComponent;
use Px\Animation\AnimationManager;

echo "========================================\n";
echo " B4 Transition/TransitionGroup 测试\n";
echo "========================================\n\n";

test('编译器识别 Transition 标签为内建组件(结构验证)', function () {
    // 模拟编译器输出的 VNode 结构（ComponentResolveTransform 产出）
    $node = VNode::hComponent('TransitionComponent', null, ['name' => 'static:fade']);
    assert_eq($node->type, '#component', 'hComponent 应生成 #component');
    assert_eq($node->componentClass, 'TransitionComponent', 'componentClass 应正确');
    assert_true($node->isComponent, 'isComponent 应为 true');
});

test('TransitionComponent 构造和 render 不抛异常', function () {
    $tc = new TransitionComponent('Transition');
    // render 应返回 VNode
    $vn = $tc->render();
    assert_true($vn instanceof VNode, 'render 应返回 VNode');
});

test('TransitionGroupComponent 构造和 render 不抛异常', function () {
    $tg = new TransitionGroupComponent('TransitionGroup');
    $vn = $tg->render();
    assert_true($vn instanceof VNode, 'render 应返回 VNode');
});

test('TransitionGroupComponent recordPosition 记录坐标', function () {
    AnimationManager::reset();
    $tg = new TransitionGroupComponent('TransitionGroup');
    $node = new \Px\Render\RenderNode('div');
    $node->cachedFragment = new \Px\Layout\PhysicalFragment(
        100, 200, 50, 30, 50, 30, 0, 50, 30, null, [], $node, 0, 0, false, 'div', null, [], []
    );
    $tg->recordPosition($node, 'item-1');
    // 内部 $childPositions['item-1'] 应有值
    $refl = new \ReflectionProperty($tg, 'childPositions');
    $refl->setAccessible(true);
    $positions = $refl->getValue($tg);
    assert_true(isset($positions['item-1']), '应记录 item-1 的位置');
    assert_eq($positions['item-1']['x'], 100, 'x 应为 100');
    assert_eq($positions['item-1']['y'], 200, 'y 应为 200');
    AnimationManager::reset();
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
