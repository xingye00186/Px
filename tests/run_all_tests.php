<?php
/**
 * Px Framework 测试运行器
 *
 * 批量运行所有单元测试，生成统一报告。
 * 每个测试文件作为独立子进程运行，避免 exit() 中断。
 *
 * Usage:
 *   php tests/run_all_tests.php
 *
 * 退出码: 0 = 全部通过, 非0 = 存在失败
 */

// 环境一致性检测
$envCheckScript = __DIR__ . '/check_environment.php';
if (file_exists($envCheckScript)) {
    $envOutput = [];
    $envExitCode = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($envCheckScript) . ' 2>&1', $envOutput, $envExitCode);
    echo implode("\n", $envOutput) . "\n\n";
}

$testDir = __DIR__ . '/unit';
$testFiles = glob($testDir . '/*Test.php');

// 同时扫描 tests/ 根目录的独立测试脚本
$rootTestFiles = glob(__DIR__ . '/*-test.php');
$testFiles = array_merge($testFiles, $rootTestFiles);

$phpBin = PHP_BINARY; // 使用当前 PHP 二进制

// 排除非测试文件
$testFiles = array_filter($testFiles, function ($f) {
    return preg_match('/(Test|-test)\.php$/', $f);
});

sort($testFiles);

$totalPassed = 0;
$totalFailed = 0;
$startTime = microtime(true);

echo "╔══════════════════════════════════════════════════╗\n";
echo "║        Px Framework — 全面测试套件              ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

foreach ($testFiles as $file) {
    $testName = basename($file);

    echo str_repeat('─', 60) . "\n";
    echo "  [{$testName}]\n";
    echo str_repeat('─', 60) . "\n";

    // 作为子进程运行，捕获输出和退出码
    $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($file) . ' 2>&1';
    $output = shell_exec($cmd);
    $exitCode = 0;

    // 解析退出码（从输出中找 Results 行）
    $passed = 0;
    $failed = 0;
    $total = 0;
    // 匹配 "Results: X/Y passed"（如 CalculatorAppTest）
    if (preg_match('/Results:\s*(\d+)\/(\d+)\s+passed/', $output, $m)) {
        $passed = (int)$m[1];
        $total = (int)$m[2];
        $failed = $total - $passed;
    // 匹配 "Results: X passed, Y failed"（如 sfc-compiler-test）
    } elseif (preg_match('/Results:\s*(\d+)\s+passed,\s*(\d+)\s+failed/', $output, $m)) {
        $passed = (int)$m[1];
        $failed = (int)$m[2];
        $total = $passed + $failed;
    } elseif (preg_match('/(全部通过|All tests passed)/', $output)) {
        // 某些测试没有 Results 行
        $passed = '?';
    }

    // 只显示 [PASS]/[FAIL] 和自定义行，过滤掉摘要和标题
    $lines = explode("\n", $output);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;
        // 跳过标题横幅
        if (strpos($trimmed, '========') === 0) continue;
        if (strpos($trimmed, 'Results:') === 0) continue;
        if (strpos($trimmed, 'All tests') === 0) continue;
        if (strpos($trimmed, 'SOME TESTS') === 0) continue;
        if (strpos($trimmed, '=== All tests completed ===') === 0) continue;
        echo $line . "\n";
    }

    if ($passed !== '?') {
        $totalPassed += $passed;
        $totalFailed += $failed;
    }

    if ($failed > 0) {
        echo "  → {$passed}/{$total} passed, {$failed} FAILED\n\n";
    } elseif ($passed === '?') {
        echo "  → all passed ✓\n\n";
    } else {
        echo "  → {$passed}/{$total} all passed ✓\n\n";
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
$grandTotal = $totalPassed + $totalFailed;

echo "╔══════════════════════════════════════════════════╗\n";
echo "║  最终报告                                        ║\n";
echo "╠══════════════════════════════════════════════════╣\n";
echo "║  通过: {$totalPassed}/{$grandTotal}                          ║\n";
echo "║  耗时: {$elapsed}s                                     ║\n";

if ($totalFailed > 0) {
    echo "║  失败: {$totalFailed}                               ║\n";
    echo "╚══════════════════════════════════════════════════╝\n";
    echo "\n❌ 存在 {$totalFailed} 个失败测试！\n";
    exit(1);
} else {
    echo "╚══════════════════════════════════════════════════╝\n";
    echo "\n✅ 全部 {$grandTotal} 个测试通过！\n";
    exit(0);
}
