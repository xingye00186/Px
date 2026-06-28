<?php
$dir = 'f:/work/Px/apps/php-rt-test/test_case';
$results = [];
foreach (glob("$dir/prt-*/ref/element_compare_report.json") as $f) {
    $d = json_decode(file_get_contents($f), true);
    if (!$d) continue;
    $s = $d['summary'] ?? [];
    $critical = 0;
    if (isset($d['categories']['GEOMETRY'])) {
        foreach ($d['categories']['GEOMETRY'] as $g) {
            if (($g['severity'] ?? '') === 'CRITICAL') $critical++;
        }
    }
    $results[] = [
        'case' => $d['case'] ?? basename(dirname($f)),
        'total' => $s['total'] ?? 0,
        'geo' => $s['GEOMETRY'] ?? 0,
        'mis' => $s['MISMATCH'] ?? 0,
        'str' => $s['STRUCTURE'] ?? 0,
        'missing' => $s['MISSING'] ?? 0,
        'critical' => $critical,
    ];
}

// Filter: STRUCTURE=0, MISSING=0
$filtered = array_filter($results, fn($r) => $r['str'] === 0 && $r['missing'] === 0);
usort($filtered, fn($a, $b) => $a['geo'] - $b['geo']);

echo "Best starting points: STRUCTURE=0, MISSING=0, sorted by GEOMETRY asc\n";
echo str_pad('Case', 30) . ' TOT GEO MIS CRIT' . "\n" . str_repeat('-', 55) . "\n";
foreach ($filtered as $r) {
    printf("%-30s %3d %3d %3d %4d\n", $r['case'], $r['total'], $r['geo'], $r['mis'], $r['critical']);
}
