<?php
// 临时探针：envcheck 三角（vs menulist_run2 同码 + vs multicol 稳定基线，用后即删）
$new = json_decode(file_get_contents('tests/perf/bench_envcheck.json'), true);
foreach (['bench_menulist_run2.json' => 'same-code', 'bench_multicol.json' => 'stable-base'] as $bf => $tag) {
    $base = json_decode(file_get_contents("tests/perf/$bf"), true);
    $s = 0.0; $c = 0; $w = -100.0; $wn = '';
    foreach ($new['results'] as $k => $v) {
        $bv = (float)($base['results'][$k]['total_sec'] ?? 0);
        if ($bv <= 0) continue;
        $d = ((float)$v['total_sec'] - $bv) / $bv * 100;
        $s += $d; $c++;
        if ($d > $w) { $w = $d; $wn = $k; }
    }
    printf("%s vs %s: AVG %+.2f%% worst %+.2f%% (%s)\n", 'envcheck', $tag, $s / max(1, $c), $w, $wn);
}
