<?php

/**
 * PHP RT Test Pipeline — PxTest 全流程编排器
 *
 * 委托给通用管线 (apps/css-test/test_pipeline.php)，
 * 传入 --app=php-rt-test 参数切换应用上下文。
 *
 * Usage:
 *   php apps/php-rt-test/test_pipeline.php                              # 全量
 *   php apps/php-rt-test/test_pipeline.php --case=prt-30-flex-grow      # 单 case
 *   php apps/php-rt-test/test_pipeline.php --format=md                  # MD 报告
 *   php apps/php-rt-test/test_pipeline.php --skip-build                 # 跳过编译
 *   php apps/php-rt-test/test_pipeline.php --skip-browser               # 跳过浏览器
 */

$projectRoot = dirname(__DIR__, 2);

// Windows 进程清理
if (PHP_OS_FAMILY === 'Windows') {
    exec('taskkill /F /IM php-cgi.exe /T 2>NUL');
    exec('taskkill /F /IM msedge.exe /T 2>NUL');
}

// 构造参数：添加 --app=php-rt-test --php-runtime，默认开启浏览器对比
$args = $argv ?? [];
$script = array_shift($args);
$passthruArgs = [escapeshellarg(__DIR__ . '/../css-test/test_pipeline.php')];

// 添加固定参数
$passthruArgs[] = '--app=php-rt-test';
$passthruArgs[] = '--php-runtime';

// 默认开启浏览器对比（除非显式 --skip-browser）
$hasSkipBrowser = false;
foreach ($args as $a) {
    if ($a === '--skip-browser') { $hasSkipBrowser = true; continue; }
    $passthruArgs[] = escapeshellarg($a);
}
if (!$hasSkipBrowser) {
    $passthruArgs[] = '--browser-engine-el-compare';
}

$cmd = PHP_BINARY . ' ' . implode(' ', $passthruArgs) . ' 2>&1';
passthru($cmd, $exitCode);
exit($exitCode);
