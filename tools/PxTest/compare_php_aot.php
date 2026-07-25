<?php
// CLI(php) vs AOT(exe) 双模式基线对比：逐 case 对比 engine_ref_level_0_php.json vs _aot.json
// 用法：php tools/PxTest/compare_php_aot.php [--case=case-xxx] [--verbose]
$root = dirname(__DIR__, 2);
$caseFilter = null; $verbose = false;
foreach ($argv as $a) {
    if (str_starts_with($a, '--case=')) $caseFilter = substr($a, 7);
    if ($a === '--verbose') $verbose = true;
}
$dirs = glob($root . '/apps/css-test/test_case/case-*', GLOB_ONLYDIR);
sort($dirs);
$totCases = 0; $identical = 0; $diffCases = [];
foreach ($dirs as $dir) {
    $case = basename($dir);
    if ($caseFilter !== null && $case !== $caseFilter) continue;
    $phpF = "$dir/ref/engine_ref_level_0_php.json";
    $aotF = "$dir/ref/engine_ref_level_0_aot.json";
    if (!file_exists($phpF) || !file_exists($aotF)) {
        $diffCases[$case] = ['missing' => (!file_exists($phpF) ? 'php' : '') . (!file_exists($aotF) ? ' aot' : '')];
        continue;
    }
    $totCases++;
    $p = json_decode(file_get_contents($phpF), true)['elements'] ?? [];
    $a = json_decode(file_get_contents($aotF), true)['elements'] ?? [];
    // 按 pxId 索引
    $pById = []; foreach ($p as $el) { $id = $el['dataset']['pxId'] ?? null; if ($id) $pById[$id] = $el; }
    $aById = []; foreach ($a as $el) { $id = $el['dataset']['pxId'] ?? null; if ($id) $aById[$id] = $el; }
    $geo = 0; $style = 0; $onlyP = 0; $onlyA = 0; $samples = [];
    foreach ($pById as $id => $pe) {
        if (!isset($aById[$id])) { $onlyP++; if (count($samples) < 5) $samples[] = "$id only-in-php"; continue; }
        $ae = $aById[$id];
        foreach (['x', 'y', 'w', 'h'] as $f) {
            if ((int)($pe[$f] ?? 0) !== (int)($ae[$f] ?? 0)) {
                $geo++;
                if (count($samples) < 8) $samples[] = "$id.$f php=" . ($pe[$f] ?? '?') . " aot=" . ($ae[$f] ?? '?');
            }
        }
        $ps = $pe['styles'] ?? []; $as = $ae['styles'] ?? [];
        foreach (array_unique(array_merge(array_keys($ps), array_keys($as))) as $k) {
            if ((string)($ps[$k] ?? '') !== (string)($as[$k] ?? '')) {
                $style++;
                if (count($samples) < 8) $samples[] = "$id.styles.$k php=" . ($ps[$k] ?? '(none)') . " aot=" . ($as[$k] ?? '(none)');
            }
        }
    }
    foreach ($aById as $id => $ae) if (!isset($pById[$id])) $onlyA++;
    if ($geo === 0 && $style === 0 && $onlyP === 0 && $onlyA === 0 && count($pById) === count($aById)) {
        $identical++;
    } else {
        $diffCases[$case] = ['geo' => $geo, 'style' => $style, 'onlyPhp' => $onlyP, 'onlyAot' => $onlyA,
            'nPhp' => count($pById), 'nAot' => count($aById), 'samples' => $samples];
    }
}
printf("compared: %d cases | identical: %d | diff: %d\n", $totCases, $identical, count($diffCases));
foreach ($diffCases as $case => $d) {
    if (isset($d['missing'])) { echo "  $case: MISSING(" . trim($d['missing']) . ")\n"; continue; }
    printf("  %s: geo=%d style=%d onlyPhp=%d onlyAot=%d (n %d vs %d)\n", $case, $d['geo'], $d['style'], $d['onlyPhp'], $d['onlyAot'], $d['nPhp'], $d['nAot']);
    if ($verbose) foreach ($d['samples'] as $s) echo "      $s\n";
}
