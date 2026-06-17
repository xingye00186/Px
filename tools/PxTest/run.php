<?php

/**
 * PxTest CLI 统一入口。
 *
 * 用法:
 *   php tools/PxTest/run.php                          # 全部测试
 *   php tools/PxTest/run.php --group=fast              # 快速测试
 *   php tools/PxTest/run.php --exclude=slow,stress     # 排除慢速
 *   php tools/PxTest/run.php --format=json             # JSON 输出
 *   php tools/PxTest/run.php --format=tap              # TAP 输出
 *   php tools/PxTest/run.php --format=md               # Markdown 输出
 */

$projectRoot = dirname(__DIR__, 1);

// 委托给统一运行器
$args = implode(' ', array_map('escapeshellarg', array_slice($argv ?? [], 1)));
$cmd = PHP_BINARY . ' ' . escapeshellarg($projectRoot . '/tests/run_all.php') . ' ' . $args . ' 2>&1';
passthru($cmd, $exitCode);
exit($exitCode);
