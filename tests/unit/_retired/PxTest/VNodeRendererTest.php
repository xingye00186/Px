<?php

/**
 * VNodeRenderer 单元测试 — 使用 MockPlatform 验证元素收集。
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 400);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 300);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'RendererTest');

echo "========================================\n";
echo "  VNodeRenderer Unit Test (PxTest)\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

function totalElements(MockPlatform $p): int {
    return count($p->renderContext->drawnElements)
        + count($p->renderContext->texts)
        + count($p->renderContext->fillRects);
}

// ═══ 1. div + text 绘制收集 ═══
echo "--- 1. div + text ---\n";
$platform = new MockPlatform(400, 300);
$app = new Application($platform, new Scheduler());
$comp = new MockComponent('renderer_test', $app, new Scheduler());
$comp->mockVNode = VNodeBuilder::div()
    ->style(['width' => '200px', 'height' => '100px', 'background' => '#ff0000'])
    ->child('span', 'Hello Renderer')
    ->build();
$app->mount($comp);
$rm = new ReflectionMethod(Application::class, 'render');
$rm->setAccessible(true);
$rm->invoke($app);
$rm->invoke($app);
check('div+text produced elements', totalElements($platform) > 0);


// ═══ 2. button 绘制 ═══
echo "\n--- 2. button ---\n";
$platform2 = new MockPlatform(400, 300);
$app2 = new Application($platform2, new Scheduler());
$comp2 = new MockComponent('btn_test', $app2, new Scheduler());
$comp2->mockVNode = VNodeBuilder::button('Click')->build();
$app2->mount($comp2);
$rm2 = new ReflectionMethod(Application::class, 'render');
$rm2->setAccessible(true);
$rm2->invoke($app2);
$rm2->invoke($app2);
check('Button produced elements', totalElements($platform2) > 0);


// ═══ 3. 多层嵌套 ═══
echo "\n--- 3. Nested ---\n";
$platform3 = new MockPlatform(400, 300);
$app3 = new Application($platform3, new Scheduler());
$comp3 = new MockComponent('nested_test', $app3, new Scheduler());
$comp3->mockVNode = VNodeBuilder::div()
    ->style(['display' => 'flex'])
    ->childBuilder(VNodeBuilder::div()->childText('A'))
    ->childBuilder(VNodeBuilder::div()->childText('B'))
    ->childBuilder(VNodeBuilder::div()->childText('C'))
    ->build();
$app3->mount($comp3);
$rm3 = new ReflectionMethod(Application::class, 'render');
$rm3->setAccessible(true);
$rm3->invoke($app3);
$rm3->invoke($app3);
check('Nested produced elements', totalElements($platform3) > 0);


echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
