<?php

/**
 * PxTest 统一测试运行器。
 *
 * 替代项目原有的硬编码 glob/$scripts 扫描，提供：
 *   - 自动发现所有测试文件 (TestDiscovery)
 *   - 按 group 标签过滤 (@group fast/slow/stress)
 *   - 多格式报告 (console/json/tap)
 *
 * Usage:
 *   php tests/run_all.php                    # 运行所有测试
 *   php tests/run_all.php --group=fast       # 仅快速测试
 *   php tests/run_all.php --exclude=slow,stress  # 排除慢速
 *   php tests/run_all.php --format=json      # JSON 输出
 *   php tests/run_all.php --format=tap       # TAP 输出
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/tools/PxTest/bootstrap.php';

use PxTest\Core\TestDiscovery;

// ── CLI 参数解析 ──
$options = [
    'group'   => null,     // --group=fast,slow
    'exclude' => null,     // --exclude=stress
    'format'  => 'console',// --format=json|tap|console
    'help'    => false,
];

$args = $argv ?? [];
array_shift($args); // script name
foreach ($args as $arg) {
    if (str_starts_with($arg, '--group=')) {
        $options['group'] = explode(',', substr($arg, 8));
    } elseif (str_starts_with($arg, '--exclude=')) {
        $options['exclude'] = explode(',', substr($arg, 10));
    } elseif (str_starts_with($arg, '--format=')) {
        $options['format'] = substr($arg, 9);
    } elseif ($arg === '--help') {
        $options['help'] = true;
    }
}

if ($options['help']) {
    echo "PxTest 统一测试运行器\n\n";
    echo "用法: php tests/run_all.php [options]\n\n";
    echo "选项:\n";
    echo "  --group=fast,slow   仅运行指定 group 的测试\n";
    echo "  --exclude=slow,stress  排除指定 group\n";
    echo "  --format=console|json|tap  输出格式 (默认 console)\n";
    echo "  --help              显示帮助\n\n";
    echo "Group 标签:\n";
    echo "  fast    — 单元测试 + 单元集成 (< 3min)\n";
    echo "  slow    — 系统集成 + E2E\n";
    echo "  stress  — 压力 + 稳定性\n";
    exit(0);
}

// ── 测试发现 ──
$discovery = new TestDiscovery($projectRoot);

// 单元测试 (fast)
$discovery->addScanDir('tests/unit', 'fast', true);
// 集成测试 (slow)
$discovery->addScanDir('tests/integration', 'slow', true);
// E2E 入口
$discovery->addScanDir('tests/e2e', 'slow', true);
// 压力测试 (stress)
$discovery->addScanDir('tests/stress', 'stress', true);
// CSS 标准测试 (slow)
$discovery->addScanDir('tests/css-standards', 'slow', false);
// 基础设施测试也用新的 TestDiscovery
$discovery->addScanDir('tests/unit/PxTest', 'fast', false);

// 根目录 -test.php 文件
$rootDir = $projectRoot . '/tests';
$rootFiles = glob($rootDir . '/*.php');
foreach ($rootFiles as $rf) {
    $name = basename($rf);
    if (preg_match('/(?:Test|-test|_test)\.php$/', $name)) {
        // 已在单元目录中扫描的跳过
        // (已通过 addScanDir 递归处理)
    }
}

// 过滤
if ($options['group'] !== null) {
    $tests = $discovery->discoverByGroups($options['group']);
} elseif ($options['exclude'] !== null) {
    $tests = $discovery->discoverExcluding($options['exclude']);
} else {
    $tests = $discovery->discover();
}

if (empty($tests)) {
    echo "没有发现测试文件。\n";
    exit(0);
}

// ── 运行测试 ──
$phpBin = PHP_BINARY;
$startTime = microtime(true);
$results = [];
$totalPassed = 0;
$totalFailed = 0;

// 控制台输出头
if ($options['format'] === 'console') {
    echo "╔══════════════════════════════════════════════════╗\n";
    echo "║        PxTest — 统一测试套件                     ║\n";
    echo "╠══════════════════════════════════════════════════╣\n";
    echo "║  测试文件: " . count($tests) . str_repeat(' ', 40 - strlen((string)count($tests))) . "║\n";
    $groups = array_unique(array_column($tests, 'group'));
    echo "║  Groups: " . implode(', ', $groups) . str_repeat(' ', 40 - strlen(implode(', ', $groups))) . "║\n";
    echo "╚══════════════════════════════════════════════════╝\n\n";
}

foreach ($tests as $test) {
    $file = $test['file'];
    $name = $test['name'];
    $group = $test['group'];

    // 子进程执行
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($file) . ' 2>&1';
    $output = shell_exec($cmd);

    // 解析结果
    $casePassed = 0; $caseFailed = 0;
    if (preg_match('/(\d+) passed,\s*(\d+) failed/', $output ?? '', $m)) {
        $casePassed = (int)$m[1];
        $caseFailed = (int)$m[2];
    } elseif (preg_match('/Results?:\s*(\d+)\/(\d+)/', $output ?? '', $m)) {
        $casePassed = (int)$m[1];
        $caseFailed = (int)$m[2] - $casePassed;
    } elseif (preg_match('/(\d+) passed,\s*0 failed/', $output ?? '', $m)) {
        $casePassed = (int)$m[1];
    } elseif ($output === null || str_contains($output ?? '', 'Fatal error')) {
        $caseFailed = 1;
    }

    $passed = ($caseFailed === 0 && $casePassed > 0);
    $results[] = [
        'name'   => $name,
        'group'  => $group,
        'passed' => $passed,
        'p'      => $casePassed,
        'f'      => $caseFailed,
    ];

    if ($passed) {
        $totalPassed++;
    } else {
        $totalFailed++;
    }

    if ($options['format'] === 'console') {
        $icon = $passed ? '✅' : '❌';
        echo str_pad("  $icon $name", 50) . " [$group]";
        if ($casePassed > 0 || $caseFailed > 0) {
            echo " {$casePassed}/" . ($casePassed + $caseFailed);
        }
        echo "\n";
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
$total = count($results);

// ── 输出格式 ──
switch ($options['format']) {
    case 'json':
        echo json_encode([
            'summary'  => ['total' => $total, 'passed' => $totalPassed, 'failed' => $totalFailed, 'elapsed_s' => $elapsed],
            'groups'   => array_unique(array_column($tests, 'group')),
            'results'  => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        break;

    case 'tap':
        echo "1..{$total}\n";
        foreach ($results as $i => $r) {
            $ok = $r['passed'] ? 'ok' : 'not ok';
            $desc = $r['name'] . " [{$r['group']}]";
            echo "{$ok} " . ($i + 1) . " - {$desc}\n";
        }
        break;

    default: // console
        echo "\n";
        echo "╔══════════════════════════════════════════════════╗\n";
        $summaryLine = "  通过: {$totalPassed}/{$total}  失败: {$totalFailed}  耗时: {$elapsed}s";
        echo "║" . str_pad($summaryLine, 50) . "║\n";
        if ($totalFailed > 0) {
            echo "║  存在失败测试！                                ║\n";
        } else {
            echo "║  全部通过 ✓                                    ║\n";
        }
        echo "╚══════════════════════════════════════════════════╝\n";
}

exit($totalFailed > 0 ? 1 : 0);
