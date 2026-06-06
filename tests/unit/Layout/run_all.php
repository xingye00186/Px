<?php
/**
 * run_all.php — 一键运行所有 Layout 布局标准测试
 *
 * Usage: php tests/unit/Layout/run_all.php
 */

echo "╔══════════════════════════════════════╗\n";
echo "║   CSS 布局标准测试 — 全集            ║\n";
echo "╚══════════════════════════════════════╝\n\n";

$tests = [
    'FlexLayoutTest',
    'BlockLayoutTest',
    'PositionLayoutTest',
    'GridLayoutTest',
    'ScrollLayoutTest',
    'ComboLayoutTest',
];

$totalPass = 0;
$totalFail = 0;
$totalFiles = 0;

foreach ($tests as $testFile) {
    $filePath = __DIR__ . '/' . $testFile . '.php';
    if (!file_exists($filePath)) {
        echo "[SKIP] {$testFile}.php not found\n";
        continue;
    }

    echo "────────────────────────────────────\n";
    echo "  [{$testFile}.php]\n";
    echo "────────────────────────────────────\n";

    // Run the test file in a separate process
    $output = [];
    $exitCode = 0;
    $cmd = PHP_BINARY . ' ' . escapeshellarg($filePath) . ' 2>&1';
    exec($cmd, $output, $exitCode);

    $stdout = implode("\n", $output);
    echo $stdout . "\n";

    // 解析 "Results: X/Y passed" 行获取通过数（子进程的 globals 不会传回父进程）
    $parsedPass = 0;
    $parsedFail = 0;
    foreach ($output as $line) {
        if (preg_match('/Results:\s*(\d+)\/(\d+)\s+passed/', $line, $m)) {
            $parsedPass = (int)$m[1];
            $total = (int)$m[2];
            $parsedFail = $total - $parsedPass;
            break;
        }
    }

    if ($parsedFail === 0 && $exitCode !== 0) {
        $parsedFail = 99; // exit code 非零但正则未匹配到 FAILED，标记严重错误
    }

    $totalPass += $parsedPass;
    $totalFail += $parsedFail;
    $totalFiles++;
}

echo "\n";
echo "╔══════════════════════════════════════╗\n";
echo "║   最终报告                           ║\n";
echo "╠══════════════════════════════════════╣\n";
echo "║  文件: {$totalFiles}                          ║\n";
echo "║  通过: {$totalPass}                          ║\n";
echo "║  失败: {$totalFail}                          ║\n";
echo "╚══════════════════════════════════════╝\n";

if ($totalFail > 0) {
    exit(1);
}
echo "✅ 全部通过!\n";
