<?php

/**
 * ApplicationEventTest — 事件分发单元测试。
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Mock\EventSimulator;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;
use Px\Platform\MouseEvent;
use Px\Platform\KeyboardEvent;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 400);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 300);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'EventTest');

echo "========================================\n";
echo "  Application Event Dispatch Test\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

$platform = new MockPlatform(400, 300);
$scheduler = new Scheduler();
$app = new Application($platform, $scheduler);
$rmRender = new ReflectionMethod(Application::class, 'render');
$rmRender->setAccessible(true);

$comp = new MockComponent('event_test', $app, $scheduler);
$comp->mockVNode = VNodeBuilder::div()
    ->style(['width' => '200px', 'height' => '100px'])
    ->onClick('handleBtn', 'btn-1')
    ->childText('Click Target')
    ->build();

$app->mount($comp);
$rmRender->invoke($app);
$rmRender->invoke($app);

// ═══ 1. 点击事件处理 ═══
echo "--- 1. Click handling ---\n";
EventSimulator::injectClick($platform, 50, 30);
$rmRender->invoke($app);
check('Click event does not crash', true);


// ═══ 2. 鼠标移动 ═══
echo "\n--- 2. Mouse move ---\n";
$platform->injectEvent(EventSimulator::mouseMove(10, 10));
$platform->injectEvent(EventSimulator::mouseMove(50, 50));
$rmRender->invoke($app);
check('Mouse move events do not crash', true);


// ═══ 3. 键盘事件 ═══
echo "\n--- 3. Keyboard ---\n";
$platform->injectEvent(EventSimulator::keyPress('a', 65));
$platform->injectEvent(EventSimulator::enterKey());
$platform->injectEvent(EventSimulator::keyRelease('a', 65));
$rmRender->invoke($app);
check('Keyboard events do not crash', true);


// ═══ 4. 滚轮 ═══
echo "\n--- 4. Wheel ---\n";
for ($i = 0; $i < 5; $i++) {
    $platform->injectEvent(EventSimulator::mouseWheel(50, 50, -120));
}
$rmRender->invoke($app);
check('Wheel events do not crash', true);


// ═══ 5. 事件混合 ═══
echo "\n--- 5. Mixed events ---\n";
EventSimulator::injectClick($platform, 30, 30);
$platform->injectEvent(EventSimulator::mouseMove(60, 60));
$platform->injectEvent(EventSimulator::mouseWheel(60, 60, 120));
EventSimulator::injectClick($platform, 90, 90);
$rmRender->invoke($app);
$rmRender->invoke($app);
check('Mixed event sequence no crash', true);


echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
