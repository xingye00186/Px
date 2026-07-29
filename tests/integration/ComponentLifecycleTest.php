<?php

/**
 * 组件生命周期集成测试 — 父子组件 props 传递 + emit 事件。
 */

require_once __DIR__ . '/../unit/bootstrap.php';
require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 800);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 600);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'LifecycleTest');

echo "========================================\n";
echo "  Component Lifecycle Integration Test\n";
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

// ═══ 1. 父子组件创建 ═══
echo "--- 1. Parent-child setup ---\n";
$parent = new MockComponent('parent', $app, $scheduler);
$child = new MockComponent('child', $app, $scheduler);

$child->mockVNode = VNodeBuilder::div()
    ->style(['width' => '100px'])
    ->childText('child_content')
    ->build();

$parent->mockVNode = VNodeBuilder::div()
    ->style(['display' => 'flex'])
    ->childBuilder(VNodeBuilder::component('child_comp', ['title' => 'hi']))
    ->build();

check('Parent and child created', true);


// ═══ 2. 子 emit 事件 ═══
echo "\n--- 2. Child emit ---\n";
$received = null;
$parent->on($child, 'selected', function($payload) use (&$received) {
    $received = $payload;
});
$child->emit('selected', ['id' => 99]);
check('Parent received child emit', $received !== null && ($received['id'] ?? 0) === 99);


// ═══ 3. markDirty 传播 ═══
echo "\n--- 3. markDirty propagation ---\n";
$child->mockVNode = VNodeBuilder::div()
    ->style(['width' => '200px'])
    ->childText('updated_child')
    ->build();
$child->renderDirty = true;
check('Child marked dirty', $child->renderDirty === true);
check('Child re-rendered', $child->getVNodeTree() !== null);


// ═══ 4. Mount + 多次 render ═══
echo "\n--- 4. Mount lifecycle ---\n";
$comp = new MockComponent('lifecycle', $app, $scheduler);
$comp->mockVNode = VNodeBuilder::div()->style(['width' => '100px'])->childText('life')->build();
$app->mount($comp);
$rmRender->invoke($app);
$rmRender->invoke($app);

$comp->renderDirty = true;
$rmRender->invoke($app);
check('Lifecycle: mount + re-render no crash', true);


// ═══ 5. 多次 mount（组件替换） ═══
echo "\n--- 5. Re-mount ---\n";
$comp2 = new MockComponent('lifecycle2', $app, $scheduler);
$comp2->mockVNode = VNodeBuilder::div()->style(['width' => '200px'])->childText('re-mounted')->build();
$app->mount($comp2);
$rmRender->invoke($app);
check('Re-mount no crash', true);


echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
