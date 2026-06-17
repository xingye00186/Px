<?php
/** CSS Test Sandbox — 委托给 pipeline.php */
$projectRoot = dirname(__DIR__, 1);
$args = implode(' ', array_map('escapeshellarg', array_slice($argv ?? [], 1)));
$cmd = PHP_BINARY . ' ' . escapeshellarg($projectRoot . '/apps/css-test/pipeline.php') . ' ' . $args . ' 2>&1';
passthru($cmd, $exitCode);
exit($exitCode);
