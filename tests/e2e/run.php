<?php

/**
 * E2E 瘦身编排器 — 替代 run.php 的 Pipeline 入口。
 *
 * Usage:
 *   php tests/e2e/run.php
 *   php tests/e2e/run.php --skip-build
 *   php tests/e2e/run.php --format=json
 */

$projectRoot = dirname(__DIR__, 1);

// 委托给统一运行器，附加 e2e group
$args = implode(' ', array_map('escapeshellarg', array_slice($argv ?? [], 1)));
$cmd = PHP_BINARY . ' ' . escapeshellarg($projectRoot . '/tests/run_all.php') . ' --group=slow ' . $args . ' 2>&1';
passthru($cmd, $exitCode);
exit($exitCode);
