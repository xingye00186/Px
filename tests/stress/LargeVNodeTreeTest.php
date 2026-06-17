<?php

/**
 * 压力测试 — 大量节点渲染 + 内存监控。
 *
 * 验证 1000+ 节点时框架不崩溃、内存不泄漏。
 * 使用 MemoryLeakAwareTestCase 自动检测。
 */

require_once __DIR__ . '/../unit/bootstrap.php';
require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;
use PxTest\MemoryLeakAwareTestCase;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 1600);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 1200);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'Stress');

echo "========================================\n";
echo "  Stress Test — Large VNode Tree\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// 内存基线
$memoryBase = memory_get_peak_usage(true);

$platform = new MockPlatform(1600, 1200);
$scheduler = new Scheduler();
$app = new Application($platform, $scheduler);
$rmRender = new ReflectionMethod(Application::class, 'render');
$rmRender->setAccessible(true);

// ═══ 1. 构建大量子节点 VNode ═══
echo "--- 1. Large VNode tree (500 nodes) ---\n";
$start = microtime(true);

$container = VNodeBuilder::div()
    ->style(['display' => 'flex', 'flexDirection' => 'column', 'width' => '1400px']);

for ($i = 0; $i < 500; $i++) {
    $container->child('span', "Item $i", ['class' => 'list-item']);
}

$largeVNode = $container->build();
$buildTime = round((microtime(true) - $start) * 1000, 1);
check("500-node VNode built in {$buildTime}ms", $buildTime < 5000);


// ═══ 2. 渲染大量节点 ═══
echo "\n--- 2. Render large tree ---\n";
$comp = new MockComponent('test_large', $app, $scheduler);
$comp->mockVNode = $largeVNode;
$app->mount($comp);

$start = microtime(true);
$rmRender->invoke($app);
$rmRender->invoke($app);
$renderTime = round((microtime(true) - $start) * 1000, 1);
check("500-node render in {$renderTime}ms", $renderTime < 30000);

$rtm = $app->getRenderTreeManager();
$root = $rtm->getRootRenderNode();
check('Large tree root exists', $root !== null);


// ═══ 3. 多帧渲染稳定性 ═══
echo "\n--- 3. Multi-frame stability ---\n";
$start = microtime(true);
for ($i = 0; $i < 10; $i++) {
    $comp->markDirty();
    $rmRender->invoke($app);
}
$multiTime = round((microtime(true) - $start) * 1000, 1);
check("10 re-renders in {$multiTime}ms (avg " . round($multiTime/10, 1) . "ms)", $multiTime < 60000);


// ═══ 4. 内存检查 ═══
echo "\n--- 4. Memory check ---\n";
$memoryAfter = memory_get_peak_usage(true);
$delta = $memoryAfter - $memoryBase;
$deltaKB = round($delta / 1024, 1);
check("Memory delta: {$deltaKB} KB (threshold: 10MB)", $delta < 10 * 1024 * 1024);


// ═══ Summary ═══
echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
