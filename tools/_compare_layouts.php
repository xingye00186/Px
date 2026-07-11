<?php
$aot = json_decode(file_get_contents('f:/work/Px/apps/css-test/test_case/case-001-wrapper-x/ref/engine_layout_aot.json'), true);
$php = json_decode(file_get_contents('f:/work/Px/apps/css-test/test_case/case-001-wrapper-x/ref/engine_layout_php.json'), true);

function countNodes($arr) { $c = 1; foreach(($arr['children']??[]) as $ch) $c += countNodes($ch); return $c; }

function compareNodes($a, $b, $path = 'root', &$diffs = []) {
    $keys = ['x','y','w','h','visualW','visualH','layer','scrollTop','scrollLeft','contentHeight','contentWidth'];
    foreach ($keys as $k) {
        $va = $a[$k] ?? 'N/A'; $vb = $b[$k] ?? 'N/A';
        if ($va !== $vb) {
            $diffs[] = $path . '.' . $k . ': AOT=' . var_export($va,true) . ' PHP=' . var_export($vb,true);
        }
    }
    $max = max(count($a['children']??[]), count($b['children']??[]));
    for ($i = 0; $i < $max; $i++) {
        $ca = $a['children'][$i] ?? []; $cb = $b['children'][$i] ?? [];
        if (!empty($ca) || !empty($cb)) compareNodes($ca, $cb, $path . '.children[' . $i . ']', $diffs);
    }
    return $diffs;
}

echo 'AOT nodes: ' . countNodes($aot) . "\n";
echo 'PHP nodes: ' . countNodes($php) . "\n";
echo 'AOT size: ' . strlen(json_encode($aot)) . " bytes\n";
echo 'PHP size: ' . strlen(json_encode($php)) . " bytes\n\n";

$diffs = compareNodes($aot, $php);
echo 'Diffs: ' . count($diffs) . "\n";
foreach (array_slice($diffs, 0, 30) as $d) echo "  " . $d . "\n";
