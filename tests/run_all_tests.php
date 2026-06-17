<?php

/**
 * Px Framework 测试运行器 (PxTest 统一入口)
 *
 * 委托给 tests/run_all.php (PxTest 统一运行器)。
 *
 * Usage:
 *   php tests/run_all_tests.php                    # 全部测试
 *   php tests/run_all_tests.php --group=fast       # 快速测试
 *   php tests/run_all_tests.php --exclude=slow     # 排除慢速
 *   php tests/run_all_tests.php --format=json      # JSON 输出
 *   php tests/run_all_tests.php --format=tap       # TAP 输出
 */

// ── 环境一致性检测（保持不变）──
$envCheckScript = __DIR__ . '/check_environment.php';
if (file_exists($envCheckScript)) {
    $envOutput = [];
    $envExitCode = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($envCheckScript) . ' 2>&1', $envOutput, $envExitCode);
    echo implode("\n", $envOutput) . "\n\n";
}

// ── 委托给 PxTest 统一运行器 ──
$newRunner = __DIR__ . '/run_all.php';

// 透传所有 CLI 参数
$args = '';
foreach (array_slice($argv ?? [], 1) as $arg) {
    $args .= ' ' . escapeshellarg($arg);
}

$cmd = PHP_BINARY . ' ' . escapeshellarg($newRunner) . $args . ' 2>&1';
passthru($cmd, $exitCode);
exit($exitCode);
