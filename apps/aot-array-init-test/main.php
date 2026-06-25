<?php

function main(): void {
    $passed = 0; $failed = 0;

    try {
        $d = new LikeScheduler();
        $c = $d->countItems();
        echo ($c === 0) ? "[PASS]" : "[FAIL]";
        echo " count={$c} (expected 0)\n";
        $c === 0 ? $passed++ : $failed++;
    } catch (\Throwable $e) {
        echo "[FAIL] count: " . $e->getMessage() . "\n";
        $failed++;
    }

    try {
        $d2 = new LikeScheduler();
        $h = $d2->hasItems();
        echo ($h === false) ? "[PASS]" : "[FAIL]";
        echo " empty=" . ($h ? 'true' : 'false') . " (expected false)\n";
        $h === false ? $passed++ : $failed++;
    } catch (\Throwable $e) {
        echo "[FAIL] empty: " . $e->getMessage() . "\n";
        $failed++;
    }

    echo "\nTotal: $passed pass, $failed fail\n";
    exit($failed > 0 ? 1 : 0);
}
