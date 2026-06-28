<?php
$dir = 'f:/work/Px/apps/php-rt-test/test_case';
$results = [];

foreach (glob("$dir/prt-*/ref/element_compare_report.json") as $f) {
    $data = json_decode(file_get_contents($f), true);
    if (!$data) continue;
    
    $case = $data['case'] ?? basename(dirname($f));
    $summary = $data['summary'] ?? [];
    
    $total = $summary['total'] ?? 0;
    $geo = $summary['GEOMETRY'] ?? 0;
    $mis = $summary['MISMATCH'] ?? 0;
    $missing = $summary['MISSING'] ?? 0;
    $struct = $summary['STRUCTURE'] ?? 0;
    $overflow = ($summary['TEXT_OVERFLOW'] ?? 0) + ($summary['CONTAINER_OVERFLOW'] ?? 0);
    
    // Count CRITICAL geometry diffs
    $critical = 0;
    $major = 0;
    if (isset($data['categories']['GEOMETRY'])) {
        foreach ($data['categories']['GEOMETRY'] as $g) {
            $sev = $g['severity'] ?? '';
            if ($sev === 'CRITICAL') $critical++;
            if ($sev === 'MAJOR') $major++;
        }
    }
    
    $results[] = [
        'case' => $case,
        'total' => $total,
        'geo' => $geo,
        'mis' => $mis,
        'missing' => $missing,
        'struct' => $struct,
        'overflow' => $overflow,
        'critical' => $critical,
        'major' => $major,
    ];
}

// Sort by total desc
usort($results, function($a, $b) { return $b['total'] - $a['total']; });

echo str_pad('Case', 30) . ' TOT GEO MIS MISG STR OVF CRI MAJ' . "\n";
echo str_repeat('-', 80) . "\n";
foreach ($results as $r) {
    printf("%-30s %3d %3d %3d %4d %3d %3d %3d %3d\n",
        $r['case'], $r['total'], $r['geo'], $r['mis'],
        $r['missing'], $r['struct'], $r['overflow'],
        $r['critical'], $r['major']);
}

echo "\n--- Summary ---\n";
$totalGeo = array_sum(array_column($results, 'geo'));
$totalMis = array_sum(array_column($results, 'mis'));
$totalMissing = array_sum(array_column($results, 'missing'));
$totalStruct = array_sum(array_column($results, 'struct'));
$totalAll = array_sum(array_column($results, 'total'));
$totalCritical = array_sum(array_column($results, 'critical'));
$totalMajor = array_sum(array_column($results, 'major'));
echo "Total across all cases: $totalAll\n";
echo "  GEOMETRY=$totalGeo MISMATCH=$totalMis MISSING=$totalMissing STRUCTURE=$totalStruct\n";
echo "  CRITICAL=$totalCritical MAJOR=$totalMajor\n";

echo "\n--- Worst 20 cases (by total) ---\n";
for ($i = 0; $i < min(20, count($results)); $i++) {
    $r = $results[$i];
    echo "  {$r['case']}: total={$r['total']} geo={$r['geo']} mis={$r['mis']} crit={$r['critical']}\n";
}

echo "\n--- Best candidates for iteration (STRUCTURE=0, sorted by GEO asc) ---\n";
usort($results, function($a, $b) { return $a['geo'] - $b['geo']; });
$count = 0;
foreach ($results as $r) {
    if ($r['struct'] !== 0 || $r['missing'] > 0) continue;
    printf("  %-30s total=%3d geo=%3d mis=%3d crit=%3d\n", $r['case'], $r['total'], $r['geo'], $r['mis'], $r['critical']);
    if (++$count >= 30) break;
}
