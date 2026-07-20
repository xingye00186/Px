<?php

/**
 * reactive-bench — AOT 响应式改造对比测试项目入口
 *
 * 支持:
 *   --case=SimpleCounter      单组件/单属性 (baseline)
 *   --case=ManyProps         1000属性, render只读1个 (增量收益)
 *   --case=DeepTree           深层递归嵌套 (级联更新)
 *   --case=MixedWorkload      真实混合负载 (items+scroll+click)
 *   --cycles=N                操作循环次数 (默认 100)
 *   --perf                    启用 PerfCounter 计时
 *   --dump-metrics=path.json  导出结构化性能指标
 *   --headless                无GUI模式 (CI用)
 *   --cases-list              遍历所有case (类似 css-test)
 *
 * 改造前/后对比流程:
 *   1. git checkout <pre-refactor> 到独立目录 Px_before/
 *   2. 构建 && 编译 reactive-bench.exe
 *   3. 运行 reactive-bench.exe --case=xxx --cycles=100 --perf --dump-metrics=before.json
 *   4. git checkout <post-refactor> 到独立目录 Px_after/
 *   5. 构建 && 编译 reactive-bench.exe
 *   6. 运行 reactive-bench.exe --case=xxx --cycles=100 --perf --dump-metrics=after.json
 *   7. tools/compare_results.php before.json after.json → 输出对比报告
 */

use Px\Core\Application;
use Px\Core\PerfCounter;
use Px\Core\Scheduler;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 800;
const WINDOW_HEIGHT = 600;
const WINDOW_TITLE  = 'Reactive Bench';

function runCaseIntensive(
    Application $app,
    AppComponent $root,
    string $caseName,
    int $cycles,
    bool $usePerf,
): array {
    $metrics = [
        'case'       => $caseName,
        'cycles'     => $cycles,
        'phase_ms'   => [],
        'renders'    => 0,
        'microtasks' => 0,
        'dirty_sets' => 0,
    ];

    // 切换到目标 case
    $root->selectCase($caseName);
    $app->getScheduler()->flushMicrotasks();
    $app->render();
    $app->getScheduler()->flushMicrotasks();

    $startAll = microtime(true);

    for ($i = 0; $i < $cycles; $i++) {
        $phaseStart = microtime(true);

        switch ($caseName) {
            case 'SimpleCounter':
                $root->increment();
                break;

            case 'ManyProps':
                if ($i % 2 === 0) {
                    $root->changeTracked();
                } else {
                    $root->changeUntracked();
                }
                break;

            case 'DeepTree':
            case 'MixedWorkload':
                $root->runWorkloadCycle();
                break;

            case 'FormDashboard':
                $root->runDashboardCycle();
                break;

            case 'ChatStream':
                $root->runChatCycle();
                break;

            case 'HoverGrid':
                $root->runHoverCycle();
                break;

            case 'DynamicList':
                $root->runDynamicCycle();
                break;

            case 'StaticTemplate':
                $root->runStaticCycle();
                break;

            case 'TextHeavy':
                $root->runTextCycle();
                break;
        }

        // flush 微任务 → 执行完整的渲染管线
        $app->getScheduler()->flushMicrotasks();
        $app->render();
        $metrics['renders']++;

        $metrics['phase_ms'][] = (microtime(true) - $phaseStart) * 1000;
    }

    // 收集计数器
    $metrics['total_sec'] = round(microtime(true) - $startAll, 4);
    if ($usePerf) {
        $metrics['perf_snapshot'] = PerfCounter::snapshot();
    }

    // 统计
    $phases = $metrics['phase_ms'];
    $metrics['avg_ms'] = round(array_sum($phases) / count($phases), 4);
    $metrics['fps'] = round($cycles / $metrics['total_sec'], 1);
    $metrics['min_ms'] = round(min($phases), 4);
    $metrics['max_ms'] = round(max($phases), 4);
    $metrics['p50_ms'] = round(percentile($phases, 0.50), 4);
    $metrics['p95_ms'] = round(percentile($phases, 0.95), 4);
    $metrics['p99_ms'] = round(percentile($phases, 0.99), 4);

    // 首帧 vs 稳态分离
    $metrics['warmup_ms'] = round($phases[0], 4);
    if (count($phases) > 1) {
        $steady = array_slice($phases, 1);
        $metrics['steady_avg_ms'] = round(array_sum($steady) / count($steady), 4);
        $metrics['steady_min_ms'] = round(min($steady), 4);
        $metrics['steady_max_ms'] = round(max($steady), 4);
        $metrics['steady_fps'] = round(($cycles - 1) / ($metrics['total_sec'] - $phases[0] / 1000), 1);
    } else {
        $metrics['steady_avg_ms'] = $metrics['avg_ms'];
        $metrics['steady_min_ms'] = $metrics['min_ms'];
        $metrics['steady_max_ms'] = $metrics['max_ms'];
        $metrics['steady_fps'] = $metrics['fps'];
    }
    unset($metrics['phase_ms']);

    return $metrics;
}

function percentile(array $arr, float $pct): float
{
    sort($arr);
    $idx = (int)ceil($pct * count($arr)) - 1;
    return max($arr[$idx] ?? 0, 0);
}

function main(): int
{
    global $argv;

    // ── 参数解析 ──
    $caseName    = 'SimpleCounter';
    $cycles      = 100;
    $usePerf     = false;
    $dumpPath    = '';
    $listCases   = false;

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--case='))    $caseName = substr($arg, 7);
        if (str_starts_with($arg, '--cycles='))   $cycles   = max(1, (int)substr($arg, 9));
        if ($arg === '--perf')                    $usePerf  = true;
        if (str_starts_with($arg, '--dump-metrics=')) $dumpPath = substr($arg, 15);
        if ($arg === '--cases-list')              $listCases = true;
    }

    // 将相对路径解析为相对于应用目录的绝对路径
    if ($dumpPath !== '' && !preg_match('#^(/|[A-Za-z]:)#', $dumpPath)) {
        $dumpPath = __DIR__ . '/' . ltrim($dumpPath, '/');
    }

    if ($usePerf) {
        putenv('PX_PERF=1');
    }

    \Px\Core\Application::$HEADLESS = true; // 默认 headless

    $root   = ComponentFactory::create(AppComponent::class);
    $appDir = __DIR__;
    $app    = Application::create()->mount($root, $appDir);

    if ($listCases) {
        $cases = ['SimpleCounter', 'ManyProps', 'DeepTree', 'MixedWorkload', 'FormDashboard', 'ChatStream', 'HoverGrid', 'DynamicList', 'StaticTemplate', 'TextHeavy'];
        $allResults = [];
        foreach ($cases as $c) {
            echo "[BENCH] Running case: {$c} cycles={$cycles}\n";
            $allResults[$c] = runCaseIntensive($app, $root, $c, $cycles, $usePerf);
        }

        $output = [
            'meta' => [
                'timestamp'    => date('Y-m-d H:i:s'),
                'php_version'  => PHP_VERSION,
                'mode'         => function_exists('sk_measure_text_width') ? 'AOT' : 'PHP-CLI',
            ],
            'results' => $allResults,
        ];

        if ($dumpPath !== '') {
            file_put_contents($dumpPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo "[BENCH] Results saved to: {$dumpPath}\n";
        } else {
            echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        return 0;
    }

    // 单 case 模式
    echo "[BENCH] Case: {$caseName}  Cycles: {$cycles}  Perf: " . ($usePerf ? 'ON' : 'OFF') . "\n";
    $result = runCaseIntensive($app, $root, $caseName, $cycles, $usePerf);
    print_r($result);

    if ($dumpPath !== '') {
        $output = [
            'meta' => [
                'timestamp'   => date('Y-m-d H:i:s'),
                'php_version' => PHP_VERSION,
                'mode'        => function_exists('sk_measure_text_width') ? 'AOT' : 'PHP-CLI',
            ],
            'results' => [$caseName => $result],
        ];
        file_put_contents($dumpPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "[BENCH] Results saved to: {$dumpPath}\n";
    }

    return 0;
}
