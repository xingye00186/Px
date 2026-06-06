<?php
/**
 * CSS 标准布局测试 — 统一运行器
 *
 * 运行所有 Level 的 CSS 布局测试，并报告结果。
 *
 * Usage:
 *   php tests/css-standards/run_all.php                     # 运行所有测试（对比基线）
 *   php tests/css-standards/run_all.php --update-snapshots  # 更新所有基线快照
 */

$rootDir = __DIR__;
$scripts = [
    'Level-01-Box-Model'    => 'test_basic_box.php',
    'Level-02-Flexbox'      => 'test_flexbox.php',
    'Level-03-Grid'         => 'test_grid.php',
    'Level-04-Positioning'  => 'test_positioning.php',
    'Level-05-Overflow'     => 'test_overflow.php',
    'Level-06-Complex'      => 'test_complex_layouts.php',
];

$updateFlag = '';
foreach (($_SERVER['argv'] ?? []) as $arg) {
    if ($arg === '--update-snapshots') {
        $updateFlag = ' --update-snapshots';
        break;
    }
}

$totalPassed = 0;
$totalFailed = 0;

echo "========================================\n";
echo " CSS Standards Layout Test Suite\n";
echo "========================================\n";
if ($updateFlag) {
    echo " Mode: UPDATE SNAPSHOTS\n";
}
echo "\n";

$failedSuites = [];

foreach ($scripts as $dir => $file) {
    $path = "$rootDir/$dir/$file";
    if (!file_exists($path)) {
        echo "  [SKIP] $dir/$file (not found)\n";
        continue;
    }

    echo "----------------------------------------\n";
    echo " Running $dir...\n";
    echo "----------------------------------------\n";

    // Execute the test script
    $cmd = sprintf(
        'F:\work\swoole_compiler_v1054\php.exe -d extension_dir=F:\work\swoole_compiler_v1054\ext "%s"%s 2>nul',
        $path,
        $updateFlag
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    echo implode("\n", $output) . "\n";

    if ($exitCode !== 0) {
        $failedSuites[] = $dir;
    }
}

echo "\n";
echo "========================================\n";
echo " Suite Summary\n";
echo "========================================\n";

$total = count($scripts);
$passed = $total - count($failedSuites);
echo "  Suites: {$passed}/{$total} passed\n";

if (!empty($failedSuites)) {
    echo "  Failed suites:\n";
    foreach ($failedSuites as $suite) {
        echo "    - $suite\n";
    }
    exit(1);
}

echo "  All suites passed.\n";
exit(0);
