<?php

/**
 * 资源生命周期集成测试 — ImageManager + RenderNode 循环引用检测。
 */

require_once __DIR__ . '/../unit/bootstrap.php';
require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;
use Px\Paint\ImageManager;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 400);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 300);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'ResLifecycle');

echo "========================================\n";
echo "  Resource Lifecycle Integration Test\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// ═══ 1. ImageManager 高频 load/free ═══
echo "--- 1. ImageManager high-frequency ---\n";
ImageManager::setAppRoot(dirname(__DIR__, 2) . '/apps/css-test');
$baseline = memory_get_peak_usage(true);

for ($i = 0; $i < 20; $i++) {
    ImageManager::loadImage("loop_$i.png");
}
ImageManager::freeAll();
check('20 load+freeAll: cache empty', ImageManager::getCacheSize() === 0);

$delta = memory_get_peak_usage(true) - $baseline;
check('ImageManager no memory leak', $delta < 1024 * 1024);


// ═══ 2. RenderNode 循环引用检测 ═══
echo "\n--- 2. RenderNode cycle check ---\n";
$platform = new MockPlatform(400, 300);
$scheduler = new Scheduler();
$app = new Application($platform, $scheduler);
$rmRender = new ReflectionMethod(Application::class, 'render');
$rmRender->setAccessible(true);

$comp = new MockComponent('res_test', $app, $scheduler);
$comp->mockVNode = VNodeBuilder::div()
    ->style(['width' => '200px'])
    ->childBuilder(VNodeBuilder::div()->style(['width' => '100px'])->childText('Nested'))
    ->build();

$app->mount($comp);

$hashes = [];
for ($i = 0; $i < 50; $i++) {
    $comp->markDirty();
    $rmRender->invoke($app);
    $root = $app->getRenderTreeManager()->getRootRenderNode();
    if ($root !== null) {
        $hashes[] = spl_object_hash($root);
    }
}
$unique = count(array_unique($hashes));
check("50 renders: $unique unique roots (not growing)", $unique <= 5);


// ═══ 3. markDirty 不造成子树膨胀 ═══
echo "\n--- 3. Subtree stability ---\n";
$app2 = new Application(new MockPlatform(400, 300), new Scheduler());
$comp2 = new MockComponent('stable_test', $app2, new Scheduler());
$comp2->mockVNode = VNodeBuilder::div()
    ->style(['width' => '200px'])
    ->childText('Stable')
    ->build();
$app2->mount($comp2);

$rm2 = new ReflectionMethod(Application::class, 'render');
$rm2->setAccessible(true);
$rm2->invoke($app2);
$rm2->invoke($app2);

$root2 = $app2->getRenderTreeManager()->getRootRenderNode();
$childCount1 = $root2 !== null ? count($root2->children) : -1;

for ($i = 0; $i < 20; $i++) {
    $comp2->markDirty();
    $rm2->invoke($app2);
}
$root2b = $app2->getRenderTreeManager()->getRootRenderNode();
$childCount2 = $root2b !== null ? count($root2b->children) : -1;

check("Child count stable: $childCount1 → $childCount2", $childCount1 === $childCount2);


echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
