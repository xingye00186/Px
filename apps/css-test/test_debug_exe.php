<?php
$binDir = __DIR__ . '/test_case/case-001-wrapper-x/bin';
$exe = $binDir . '/case-001-wrapper-x.exe';
if (!file_exists($exe)) {
    $exe = __DIR__ . '/bin/css_test.exe';
}
echo "Using exe: $exe\n";
echo "Dir: " . dirname($exe) . "\n";

$cmd = sprintf('cd /d "%s" && "%s" --dump-layout-after-frames=5 2>&1', dirname($exe), $exe);
echo "CMD: $cmd\n";

$output = [];
$ret = -1;
$start = microtime(true);
exec($cmd, $output, $ret);
$elapsed = round(microtime(true) - $start, 2);
echo "Exit: $ret, Elapsed: {$elapsed}s\n";
echo "Output lines: " . count($output) . "\n";
if (count($output) > 0) {
    echo "Last 5 lines:\n";
    $last = array_slice($output, -5);
    foreach ($last as $l) echo "  $l\n";
}
