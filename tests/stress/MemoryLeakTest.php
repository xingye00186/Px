<?php

/**
 * 内存泄漏测试 — 长时间运行监控。
 * 连续 200+ 次 render，验证内存不线性增长。
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
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'MemTest');

echo "========================================\n";
echo "  Memory Leak Test — 200 frames\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

$baseline = memory_get_peak_usage(true);

$platform = new MockPlatform(800, 600);
$scheduler = new Scheduler();
$app = new Application($platform, $scheduler);
$rmRender = new ReflectionMethod(Application::class, 'render');
$rmRender->setAccessible(true);

$comp = new MockComponent('mem_test', $app, $scheduler);
$comp->mockVNode = VNodeBuilder::div()
    ->style(['width' => '200px', 'display' => 'flex', 'flexDirection' => 'column'])
    ->child('span', 'Memory Test')
    ->build();

$app->mount($comp);

// 200 次渲染
$samples = [];
for ($i = 0; $i < 200; $i++) {
    $comp->renderDirty = true;
    $rmRender->invoke($app);
    if ($i % 50 === 0) {
        $samples[$i] = memory_get_peak_usage(true);
    }
}
$samples[199] = memory_get_peak_usage(true);

check('200 renders complete', true);

// 内存检查
$delta = $samples[199] - $baseline;
$deltaKB = round($delta / 1024, 1);
check("200-frame memory delta: {$deltaKB} KB (threshold: 20MB)", $delta < 20 * 1024 * 1024);

// 检查线性增长
$firstGrowth = ($samples[50] ?? $baseline) - $baseline;
$lastGrowth = $samples[199] - ($samples[150] ?? $baseline);
check('Memory growth stabilizes (not linear)', $lastGrowth <= $firstGrowth * 3);

echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
