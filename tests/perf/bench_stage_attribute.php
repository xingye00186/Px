<?php
// 逐阶段归因对比：任意两份 bench JSON。
// 用法: php tests/perf/bench_stage_attribute.php <before.json> <after.json> [case]
//
// 与 tests/perf/bench_compare.php 的分工：
//   bench_compare  —— base **硬编码**为 bench_phase4_complete.json，只看
//                     stage:full_render 的 avg，适合「对历史固定基线」的横向对比。
//   本脚本        —— 任选两份数据，给出 wall-clock + **全部分阶段** + C4.1 计数器
//                     的差异，适合归因「时间到哪去了」。
// 注：bench 输出首行有 backend 选择日志，归档前需剥掉前导（否则
//     bench_compare 的 json_decode 得 null，全部报 MISSING）。本脚本容错处理。
function load(string $p): array {
    if (!is_file($p)) { fwrite(STDERR, "missing: $p\n"); exit(1); }
    $raw = file_get_contents($p);
    $pos = strpos($raw, '{');
    $j = json_decode($pos === false ? $raw : substr($raw, $pos), true);
    return $j['results'] ?? [];
}
$beforePath = $argv[1] ?? 'tests/perf/bench_before_0722.json';
$afterPath  = $argv[2] ?? 'tests/perf/bench_head_c4opt.json';
$case       = $argv[3] ?? '';

$B = load($beforePath);
$A = load($afterPath);
echo "before: $beforePath\nafter : $afterPath\n";

// 无 case 参数 → 输出全部 case 的 wall-clock 总览
if ($case === '') {
    printf("\n%-18s %10s %10s %9s   %10s %10s\n",
        'case', 'b_avg_ms', 'a_avg_ms', 'delta%', 'b_full_us', 'a_full_us');
    foreach ($A as $name => $a) {
        if (!isset($B[$name])) continue;
        $b = $B[$name];
        $bv = (float)($b['avg_ms'] ?? 0);
        $av = (float)($a['avg_ms'] ?? 0);
        $d  = $bv != 0 ? ($av - $bv) / $bv * 100 : 0;
        $bf = (float)($b['perf_snapshot']['stage:full_render']['total'] ?? 0);
        $af = (float)($a['perf_snapshot']['stage:full_render']['total'] ?? 0);
        printf("%-18s %10.3f %10.3f %+8.1f%%   %10.0f %10.0f\n",
            substr($name, 0, 18), $bv, $av, $d, $bf, $af);
    }
    exit(0);
}

if (!isset($A[$case]) || !isset($B[$case])) { echo "case $case 缺失\n"; exit(1); }
$a = $A[$case]; $b = $B[$case];

printf("\n== %s ==  cycles b=%s a=%s\n", $case, $b['cycles'] ?? '?', $a['cycles'] ?? '?');
printf("  %-28s %10s %10s %9s\n", 'metric', 'before', 'after', 'delta%');
foreach (['avg_ms','steady_avg_ms','p95_ms','fps','renders','microtasks','dirty_sets'] as $k) {
    if (!isset($b[$k]) || !isset($a[$k])) continue;
    $bv = (float)$b[$k]; $av = (float)$a[$k];
    $d = $bv != 0 ? ($av - $bv) / $bv * 100 : 0;
    printf("  %-28s %10s %10s %+8.1f%%\n", $k, (string)$b[$k], (string)$a[$k], $d);
}

echo "\n  -- 分阶段累计耗时 (total us, 按差异绝对值排序) --\n";
$sa = $a['perf_snapshot'] ?? []; $sb = $b['perf_snapshot'] ?? [];
$rows = [];
foreach (array_unique(array_merge(array_keys($sb), array_keys($sa))) as $k) {
    if (!str_starts_with($k, 'stage:') && !str_starts_with($k, 'sub:')) continue;
    $bt = (float)($sb[$k]['total'] ?? 0);
    $at = (float)($sa[$k]['total'] ?? 0);
    if ($bt == 0 && $at == 0) continue;
    $rows[] = [$k, $bt, $at, $at - $bt];
}
usort($rows, fn($x, $y) => abs($y[3]) <=> abs($x[3]));
printf("  %-30s %11s %11s %11s\n", 'stage', 'before_us', 'after_us', 'diff_us');
foreach (array_slice($rows, 0, 12) as $r) {
    printf("  %-30s %11.0f %11.0f %+11.0f\n", $r[0], $r[1], $r[2], $r[3]);
}

echo "\n  -- C4.1 增量重算观测点 --\n";
foreach (['style_recalc_node_skip','style_recalc_subtree_skip','style_recalc_miss_dirty',
          'style_recalc_miss_nostyle','style_sig_check','style_sig_skip',
          'style_pool_hit','style_pool_miss'] as $k) {
    printf("  %-30s before=%-10s after=%s\n", $k,
        (string)($sb[$k]['count'] ?? '-'), (string)($sa[$k]['count'] ?? '-'));
}
