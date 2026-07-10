<?php
/**
 * Px Framework — 计算器性能基准测试
 *
 * 在原生 PHP CLI 下运行，用于量化优化前后的性能对比。
 * 设置 PX_PERF=1 启用 PerfCounter 内嵌测量。
 *
 * 6 个测试用例：
 *   1. 冷启动渲染 — 组件创建 + render() 全流程
 *   2. 数字连击 — 100 次 dispatchClick('inputDigit', '7')
 *   3. 运算链压力 — 50 次完整运算序列
 *   4. 历史列表压力 — 100 条历史 + render()
 *   5. 连续渲染压力 — 30 帧纯 render()
 *   6. 内存稳定性 — 500 次子组件创建/销毁
 *
 * 输出：tests/perf/results/baseline_<timestamp>.json
 *
 * Usage:
 *   set PX_PERF=1
 *   php tests/perf/benchmark_calculator.php
 */

// ── AOT polyfill (测试环境无 swoole_compiler) ────────
if (!function_exists('objval')) {
    function objval($object, string $class) {
        return $object;
    }
}

if (!function_exists('any')) {
    function any($object) { return $object; }
}

// ── 加载框架核心 ──────────────────────────────────────────
$frameworkDir = dirname(__DIR__, 2) . '/framework';
$appDir       = dirname(__DIR__, 2) . '/apps/calculator-ng';

require_once $frameworkDir . '/Interfaces/ComponentInterface.php';
require_once $frameworkDir . '/Interfaces/ReactiveComponentInterface.php';
require_once $frameworkDir . '/Rendering/CssMappings.php';
require_once $frameworkDir . '/Rendering/VNode.php';
require_once $frameworkDir . '/Rendering/RenderNode.php';
require_once $frameworkDir . '/Rendering/RenderTreeManager.php';
require_once $frameworkDir . '/Rendering/Layout/AbsolutePositioning.php';
require_once $frameworkDir . '/Rendering/LayoutOrchestrator.php';
require_once $frameworkDir . '/Rendering/RenderContext.php';
require_once $frameworkDir . '/Rendering/VNodeRenderer.php';
require_once $frameworkDir . '/Core/Scheduler.php';
require_once $frameworkDir . '/Core/PerfCounter.php';
require_once $frameworkDir . '/BaseComponent.php';
require_once $frameworkDir . '/ReactiveComponent.php';
require_once $frameworkDir . '/Styling/Theme/ColorScheme.php';
require_once $frameworkDir . '/Styling/Theme/TextTheme.php';
require_once $frameworkDir . '/Styling/Theme/ComponentTheme.php';
require_once $frameworkDir . '/Styling/Theme/ThemeData.php';
require_once $frameworkDir . '/Styling/Provider/ThemeProvider.php';

// 加载计算器组件
require_once $appDir . '/gen/AppComponent.php';

use Px\Core\Scheduler;

// ── 工具函数 ──────────────────────────────────────────────

function createApp(): AppComponent
{
    $scheduler = new Scheduler();
    $app = new AppComponent();
    $app->setScheduler($scheduler);
    return $app;
}

/** 毫秒时间 */
function msTime(): float
{
    return microtime(true) * 1000;
}

/**
 * 运行完整的运算序列：a op b =
 */
function runCalculation(AppComponent $app, string $a, string $op, string $b): void
{
    foreach (str_split($a) as $d) {
        $app->dispatchClick('inputDigit', $d);
    }
    $app->dispatchClick('inputOperator', $op);
    foreach (str_split($b) as $d) {
        $app->dispatchClick('inputDigit', $d);
    }
    $app->dispatchClick('calculate');
}

// ── 测试用例 ──────────────────────────────────────────────

$results = [];
$globalStart = microtime(true);

echo "========================================\n";
echo " Px Framework — 性能基准测试\n";
echo "========================================\n\n";

// ── 1. 冷启动渲染 ────────────────────────────────────────
echo "--- 1. 冷启动渲染 ---\n";

$t0 = msTime();
$app = createApp();
$vnodeTree = $app->render();
$t1 = msTime();
$elapsed = round($t1 - $t0, 3);
$results['cold_start'] = ['elapsed_ms' => $elapsed];
echo "  组件创建 + render(): {$elapsed} ms\n";

// ── 2. 数字连击 ──────────────────────────────────────────
echo "--- 2. 数字连击 (100× dispatchClick) ---\n";

$app2 = createApp();
$t0 = msTime();
for ($i = 0; $i < 100; $i++) {
    $app2->dispatchClick('inputDigit', '7');
}
$t1 = msTime();
$elapsed = round($t1 - $t0, 3);
$avg = round($elapsed / 100, 4);
$results['digit_spam'] = [
    'elapsed_ms' => $elapsed,
    'count'       => 100,
    'avg_ms'      => $avg,
];
echo "  100 次 dispatchClick: {$elapsed} ms (平均 {$avg} ms/次)\n";

// ── 3. 运算链压力 ────────────────────────────────────────
echo "--- 3. 运算链压力 (50 次完整运算) ---\n";

$app3 = createApp();
$sequences = [];
for ($i = 0; $i < 50; $i++) {
    $a = (string)(($i % 9) + 1);
    $b = (string)((($i * 3) % 9) + 1);
    $ops = ['+', '−', '×'];
    $op = $ops[$i % 3];
    $sequences[] = [$a, $op, $b];
}

$t0 = msTime();
foreach ($sequences as $seq) {
    runCalculation($app3, $seq[0], $seq[1], $seq[2]);
}
$t1 = msTime();
$elapsed = round($t1 - $t0, 3);
$avg = round($elapsed / 50, 4);
$results['operator_chain'] = [
    'elapsed_ms' => $elapsed,
    'count'       => 50,
    'avg_ms'      => $avg,
];
echo "  50 次运算序列: {$elapsed} ms (平均 {$avg} ms/次)\n";

// ── 4. 历史列表压力 ──────────────────────────────────────
echo "--- 4. 历史列表压力 (100 条 + render) ---\n";

$app4 = createApp();

// 直接操作 historyItems 快速填充
for ($i = 0; $i < 100; $i++) {
    $app4->historyItems[] = [
        'id'     => (string)($i + 1),
        'text'   => ($i + 1) . ' + 1 = ' . ($i + 1),
        'result' => (string)($i + 1),
    ];
}

$t0 = msTime();
for ($i = 0; $i < 10; $i++) {
    $tree = $app4->render();
}
$t1 = msTime();
$elapsed = round($t1 - $t0, 3);
$avg = round($elapsed / 10, 4);
$results['history_stress'] = [
    'elapsed_ms' => $elapsed,
    'count'       => 10,
    'avg_ms'      => $avg,
    'items'       => 100,
];
echo "  100 条历史 × 10 次 render: {$elapsed} ms (平均 {$avg} ms/次)\n";

// ── 5. 连续渲染压力 ──────────────────────────────────────
echo "--- 5. 连续渲染压力 (30 帧 pure render) ---\n";

$app5 = createApp();
$t0 = msTime();
for ($i = 0; $i < 30; $i++) {
    $tree = $app5->render();
}
$t1 = msTime();
$elapsed = round($t1 - $t0, 3);
$avg = round($elapsed / 30, 4);
$results['continuous_render'] = [
    'elapsed_ms' => $elapsed,
    'count'       => 30,
    'avg_ms'      => $avg,
];
echo "  30 帧 render: {$elapsed} ms (平均 {$avg} ms/帧)\n";

// ── 6. 内存稳定性 ────────────────────────────────────────
echo "--- 6. 内存稳定性 (500 次创建/销毁) ---\n";

$instances = [];
$t0 = msTime();
for ($i = 0; $i < 500; $i++) {
    $comp = createApp();
    $tree = $comp->render();
    $instances[] = $comp;
    // 每 50 个释放一次，模拟 GC
    if ($i % 50 === 49) {
        $instances = [];
    }
}
$instances = [];
$elapsed = round(microtime(true) * 1000 - $t0, 3);
$results['memory_stability'] = [
    'elapsed_ms' => $elapsed,
    'count'       => 500,
];
echo "  500 次创建/销毁: {$elapsed} ms\n";

// ── 收集 PerfCounter 快照（如有启用） ────────────────────
$perfSnapshot = [];
if (\Px\Core\PerfCounter::isEnabled()) {
    $perfSnapshot = \Px\Core\PerfCounter::snapshot();
}

// ── 输出 JSON 结果 ──────────────────────────────────────

$globalElapsed = round(microtime(true) - $globalStart, 4);
$results['_meta'] = [
    'timestamp'  => date('Y-m-d H:i:s'),
    'total_sec'  => $globalElapsed,
    'php_version' => PHP_VERSION,
    'type'       => 'baseline',
];

$outputDir = __DIR__ . '/results';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$filename = $outputDir . '/baseline_' . date('Ymd_His') . '.json';
$outputData = [
    'meta'   => $results['_meta'],
    'tests'  => $results,
];

if (!empty($perfSnapshot)) {
    $outputData['perf_counters'] = $perfSnapshot;
    echo "\n--- PerfCounter 快照 ---\n";
    echo \Px\Core\PerfCounter::formatSnapshot($perfSnapshot);
}

file_put_contents(
    $filename,
    json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n\n========================================\n";
echo " 结果已保存: {$filename}\n";
echo "========================================\n";
