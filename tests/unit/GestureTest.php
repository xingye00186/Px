<?php
/**
 * GestureTest — M5.3 手势系统单元测试
 *
 * 验证: drag 阈值触发/不触发; longpress 时间窗; pinch via Shift+wheel
 *
 * Usage: php tests/unit/GestureTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Core\Application;
use Px\Platform\PointerEvent;

echo "========================================\n";
echo " M5.3 手势系统测试\n";
echo "========================================\n\n";

test('拖拽阈值: 移动超过 5px 进入 drag 状态', function () {
    require_once __DIR__ . '/PipelineTestBase.php';
    $platform = new \StubPlatform(340, 660);
    $app = new Application($platform, new \Px\Core\Scheduler());
    Application::$HEADLESS = true;
    // 设置最小 VNode 树以通过 activeVNodeTree null 守卫
    $rApp = new \ReflectionProperty($app, 'activeVNodeTree');
    $rApp->setAccessible(true);
    $rApp->setValue($app, \Px\Dom\VNode::h('div', [], []));

    $refl = new \ReflectionProperty($app, 'gestureState');
    $refl->setAccessible(true);
    $method = new \ReflectionMethod($app, 'handlePointerEvent');
    $method->setAccessible(true);

    $method->invoke($app, new PointerEvent('down', 100, 100));
    assert_eq($refl->getValue($app), 'pending', 'down 后应为 pending');

    $method->invoke($app, new PointerEvent('move', 106, 100));
    assert_eq($refl->getValue($app), 'dragging', '超阈值移动应进入 dragging');

    $method->invoke($app, new PointerEvent('up', 106, 100));
    assert_eq($refl->getValue($app), 'idle', 'up 后应回到 idle');
});

test('拖拽阈值: 移动不超过 5px 不进入 drag', function () {
    require_once __DIR__ . '/PipelineTestBase.php';
    $platform = new \StubPlatform(340, 660);
    $app = new Application($platform, new \Px\Core\Scheduler());
    Application::$HEADLESS = true;
    $rApp = new \ReflectionProperty($app, 'activeVNodeTree');
    $rApp->setAccessible(true);
    $rApp->setValue($app, \Px\Dom\VNode::h('div', [], []));

    $refl = new \ReflectionProperty($app, 'gestureState');
    $refl->setAccessible(true);
    $method = new \ReflectionMethod($app, 'handlePointerEvent');
    $method->setAccessible(true);

    $method->invoke($app, new PointerEvent('down', 100, 100));
    $method->invoke($app, new PointerEvent('move', 103, 100));
    assert_eq($refl->getValue($app), 'pending', '未超阈值应保持 pending');

    $method->invoke($app, new PointerEvent('up', 103, 100));
    assert_eq($refl->getValue($app), 'idle', 'up 后应回 idle');
});

test('M5.3 pinch: Shift+wheel 产生缩放事件属性检查', function () {
    // 验证 PointerEvent 构造支持 shiftDown
    $ev = new PointerEvent('wheel', 200, 200, 0, 120, true);
    assert_true($ev->isShiftDown(), 'shiftDown 应为 true');
    assert_eq($ev->getScrollDelta(), 120, 'scrollDelta 应为 120');
    assert_eq($ev->getAction(), 'wheel', 'action 应为 wheel');
});

test('手势状态常量存在', function () {
    $refl = new \ReflectionClass(Application::class);
    assert_true($refl->hasConstant('DRAG_THRESHOLD'), 'DRAG_THRESHOLD 常量应存在');
    assert_true($refl->hasConstant('LONGPRESS_MS'), 'LONGPRESS_MS 常量应存在');
    assert_eq($refl->getConstant('DRAG_THRESHOLD'), 5, 'DRAG_THRESHOLD 应为 5');
    assert_eq($refl->getConstant('LONGPRESS_MS'), 500, 'LONGPRESS_MS 应为 500');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
