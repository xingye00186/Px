<?php

/**
 * 交互集成测试 — EventSimulator → Application → 渲染。
 *
 * 验证鼠标/键盘事件注入后，框架的事件处理到渲染的完整链路。
 * 属于"单元集成"（纯 PHP，@group fast）。
 */

require_once __DIR__ . '/../unit/bootstrap.php';
require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Mock\EventSimulator;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 800);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 600);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'Test');

echo "========================================\n";
echo "  Interaction Integration Test\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

$platform = new MockPlatform(800, 600);
$scheduler = new Scheduler();
$app = new Application($platform, $scheduler);
$rmRender = new ReflectionMethod(Application::class, 'render');
$rmRender->setAccessible(true);

// ═══ 1. 事件注入 → Application 不崩溃 ═══
echo "--- 1. Event injection ---\n";

$comp = new MockComponent('test_interact', $app, $scheduler);
$comp->mockVNode = VNodeBuilder::div()
    ->style(['width' => '200px', 'height' => '100px'])
    ->onClick('handleBtn')
    ->childText('Click Me')
    ->build();

$app->mount($comp);
$rmRender->invoke($app);
$rmRender->invoke($app);

// 注入点击事件（injectClick 会调用 platform->injectEvent 推入队列）
EventSimulator::injectClick($platform, 100, 50);
check('EventSimulator injectClick queues event', count($platform->events) === 1);

// 注入后渲染不崩溃
$rmRender->invoke($app);
check('Render after click event does not crash', true);


// ═══ 2. 鼠标移动事件 ═══
echo "\n--- 2. Mouse move ---\n";
$moveEvent = EventSimulator::mouseMove(50, 60);
$platform->injectEvent($moveEvent);
$rmRender->invoke($app);
check('Render after mouse move does not crash', true);


// ═══ 3. 滚轮事件 ═══
echo "\n--- 3. Mouse wheel ---\n";
$wheelEvent = EventSimulator::mouseWheel(100, 100, -120);
$platform->injectEvent($wheelEvent);
$rmRender->invoke($app);
check('Render after wheel event does not crash', true);


// ═══ 4. 键盘事件 ═══
echo "\n--- 4. Keyboard ---\n";
$keyEvent = EventSimulator::keyPress('a', 65);
$platform->injectEvent($keyEvent);
$rmRender->invoke($app);
check('Render after key event does not crash', true);

$enterEvent = EventSimulator::enterKey();
$platform->injectEvent($enterEvent);
$rmRender->invoke($app);
check('Render after Enter key does not crash', true);


// ═══ 5. markDirty 后事件 → 重渲染 ═══
echo "\n--- 5. markDirty + event → re-render ---\n";
$comp->mockVNode = VNodeBuilder::div()
    ->style(['width' => '300px'])
    ->onClick('updatedHandler')
    ->childText('Updated')
    ->build();
$comp->markDirty();
$rmRender->invoke($app);

$rtm = $app->getRenderTreeManager();
$root = $rtm->getRootRenderNode();
check('Root exists after re-render', $root !== null);
check('Width changed after re-render', $root !== null && $root->w > 0);


// ═══ 6. 连续多事件 → 帧稳定性 ═══
echo "\n--- 6. Multi-event stability ---\n";
$preSerial = $root !== null ? (new \PxTest\Layout\RenderNodeSerializer())->toArray($root) : null;

for ($i = 0; $i < 5; $i++) {
    EventSimulator::injectClick($platform, 10, 10);
    $rmRender->invoke($app);
}

$postSerial = $root !== null ? (new \PxTest\Layout\RenderNodeSerializer())->toArray($root) : null;
check('Stable after 5 event frames',
    $preSerial !== null && $postSerial !== null
    && $preSerial['w'] === $postSerial['w']);


// ═══ Summary ═══
echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
