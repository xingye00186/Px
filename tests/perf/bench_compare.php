<?php
// bench 对比工具：<new.json> vs bench_phase4_complete.json（stage:full_render avg μs）
// 用法：php _bench_cmp.php tests/perf/bench_xxx.json [--stages]
$newPath = $argv[1] ?? '';
if ($newPath === '' || !file_exists($newPath)) { echo "usage: php _bench_cmp.php <new.json>\n"; exit(1); }
$base = json_decode(file_get_contents('tests/perf/bench_phase4_complete.json'), true);
$new = json_decode(file_get_contents($newPath), true);
function idx($j) {
    $m = [];
    foreach (($j['results'] ?? []) as $name => $v) {
        $fr = $v['perf_snapshot']['stage:full_render'] ?? null;
        if ($fr !== null) $m[$name] = (float)$fr['avg'];
    }
    return $m;
}
function stages($j, $case) {
    $out = [];
    foreach (($j['results'][$case]['perf_snapshot'] ?? []) as $k => $v) {
        if (str_starts_with($k, 'stage:') || str_starts_with($k, 'algo:')) $out[$k] = (float)$v['avg'];
    }
    return $out;
}
$b = idx($base); $n = idx($new);
$sumB = 0; $sumN = 0; $worst = 0; $worstName = '';
printf("%-24s %10s %10s %8s\n", 'case', 'base_us', 'new_us', 'delta%');
foreach ($b as $name => $bv) {
    if (!isset($n[$name])) { printf("%-24s %10.1f %10s MISSING\n", $name, $bv, '-'); continue; }
    $nv = $n[$name]; $d = $bv > 0 ? (($nv - $bv) / $bv * 100) : 0;
    $sumB += $bv; $sumN += $nv;
    if ($d > $worst) { $worst = $d; $worstName = $name; }
    printf("%-24s %10.1f %10.1f %+7.1f%%\n", $name, $bv, $nv, $d);
}
$avgD = $sumB > 0 ? (($sumN - $sumB) / $sumB * 100) : 0;
printf("\nAVG delta: %+.2f%%  worst: %s %+.1f%%\n", $avgD, $worstName, $worst);
// --stages <case>：stage 分布归因
if (in_array('--stages', $argv, true) && $worstName !== '') {
    printf("\n== stage breakdown: %s ==\n", $worstName);
    $sb = stages($base, $worstName); $sn = stages($new, $worstName);
    foreach ($sb as $k => $bv2) {
        $nv2 = $sn[$k] ?? 0;
        $d2 = $bv2 > 0 ? (($nv2 - $bv2) / $bv2 * 100) : 0;
        printf("%-28s %9.1f %9.1f %+7.1f%%\n", $k, $bv2, $nv2, $d2);
    }
}
