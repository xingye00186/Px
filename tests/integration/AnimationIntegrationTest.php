<?php

/**
 * 动画集成测试 — 验证 AnimationManager + 渲染管线。
 */

require_once __DIR__ . '/../unit/bootstrap.php';
require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;
use Px\Animation\AnimationManager;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 400);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 300);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'AnimTest');

echo "========================================\n";
echo "  Animation Integration Test\n";
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

// ═══ 1. 渲染带动画属性的节点 ═══
echo "--- 1. Render with animation ---\n";
$comp = new MockComponent('anim_test', $app, $scheduler);
$comp->mockVNode = VNodeBuilder::div()
    ->style(['width' => '100px', 'height' => '100px'])
    ->prop('animation', 'fade-in 0.3s ease-out')
    ->childText('Animated')
    ->build();

$app->mount($comp);
$rmRender->invoke($app);
$rmRender->invoke($app);

$rtm = $app->getRenderTreeManager();
$root = $rtm->getRootRenderNode();
check('Animation render succeeds', $root !== null);


// ═══ 2. 多帧动画不崩溃 ═══
echo "\n--- 2. Multi-frame animation ---\n";
for ($i = 0; $i < 20; $i++) {
    $platform->tickAnimation();
    $rmRender->invoke($app);
}
check('20-frame animation no crash', true);


// ═══ 3. transition 属性 ═══
echo "\n--- 3. Transition property ---\n";
$comp2 = new MockComponent('trans_test', $app, $scheduler);
$comp2->mockVNode = VNodeBuilder::div()
    ->style(['width' => '100px', 'transition' => 'width 0.2s ease'])
    ->childText('Transition')
    ->build();
$app->mount($comp2);
$rmRender->invoke($app);
$rmRender->invoke($app);
check('Transition render succeeds', $app->getRenderTreeManager()->getRootRenderNode() !== null);


// ═══ 4. markDirty + 动画 ═══
echo "\n--- 4. markDirty + animation ---\n";
$comp->mockVNode = VNodeBuilder::div()
    ->style(['width' => '200px'])
    ->prop('animation', 'slide 0.5s')
    ->childText('Updated')
    ->build();
$comp->renderDirty = true;
for ($i = 0; $i < 5; $i++) {
    $platform->tickAnimation();
    $rmRender->invoke($app);
}
check('markDirty + 5-frame animation no crash', true);


echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
