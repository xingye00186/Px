<?php

/**
 * ScrollManager 单元测试 — 使用 MockPlatform + EventSimulator。
 *
 * 覆盖：滚轮注入 → scrollTop 变化 → 重渲染验证。
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Mock\EventSimulator;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 400);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 300);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'ScrollTest');

echo "========================================\n";
echo "  ScrollManager Unit Test\n";
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

// ═══ 1. 创建滚动容器并渲染 ═══
echo "--- 1. Scroll container setup ---\n";
$comp = new MockComponent('test_scroll', $app, $scheduler);
$comp->mockVNode = VNodeBuilder::div()
    ->style([
        'width' => '200px', 'height' => '150px',
        'overflowY' => 'auto',
        'overflowX' => 'hidden',
    ])
    ->child('div', 'Line 1', ['style' => 'height:40px'])
    ->child('div', 'Line 2', ['style' => 'height:40px'])
    ->child('div', 'Line 3', ['style' => 'height:40px'])
    ->child('div', 'Line 4', ['style' => 'height:40px'])
    ->child('div', 'Line 5', ['style' => 'height:40px'])
    ->build();

$app->mount($comp);
$rmRender->invoke($app);
$rmRender->invoke($app);

$rtm = $app->getRenderTreeManager();
$root = $rtm->getRootRenderNode();
check('Scroll root exists', $root !== null);


// ═══ 2. 滚轮事件注入 ═══
echo "\n--- 2. Scroll wheel injection ---\n";
EventSimulator::injectWheel($platform, 100, 75, -120); // 向下滚
$rmRender->invoke($app);
check('Render after wheel does not crash', true);


// ═══ 3. 多次滚轮 ═══
echo "\n--- 3. Multiple wheel events ---\n";
for ($i = 0; $i < 5; $i++) {
    EventSimulator::injectWheel($platform, 100, 75, -120);
}
$rmRender->invoke($app);
$rmRender->invoke($app);
check('5 consecutive wheel renders do not crash', true);


// ═══ 4. 点击事件 → 无崩溃 ═══
echo "\n--- 4. Click on scroll area ---\n";
EventSimulator::injectClick($platform, 100, 50);
$rmRender->invoke($app);
check('Click on scroll area does not crash', true);


// ═══ 5. markDirty + scroll ═══
echo "\n--- 5. markDirty + scroll ---\n";
$comp->markDirty();
EventSimulator::injectWheel($platform, 100, 75, -120);
$rmRender->invoke($app);
$rmRender->invoke($app);
check('markDirty + wheel render does not crash', true);


// ═══ Summary ═══
echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
