<?php
$json = json_decode(file_get_contents(__DIR__ . '/engine_layout.json'), true);

function findGrids($ns, $d = 0) {
    $r = [];
    foreach ($ns as $n) {
        if ($d === 1 && isset($n['style']['display']) && $n['style']['display'] === 'grid') {
            $r[] = $n;
        }
        if (isset($n['children'])) {
            $r = array_merge($r, findGrids($n['children'], $d + 1));
        }
    }
    return $r;
}

$grids = findGrids([$json]);
echo 'Found ' . count($grids) . " grid containers\n";
foreach ($grids as $i => $gr) {
    echo "Grid $i: x={$gr['x']} y={$gr['y']} w={$gr['w']} h={$gr['h']}\n";
    echo "  style keys: " . implode(', ', array_keys($gr['style'] ?? [])) . "\n";
    echo "  display=" . ($gr['style']['display'] ?? 'none') . "\n";
    if (isset($gr['style']['gridTemplateColumns'])) echo "  gridTemplateColumns={$gr['style']['gridTemplateColumns']}\n";
    if (isset($gr['style']['gap'])) echo "  gap={$gr['style']['gap']}\n";
    echo "  children: " . count($gr['children'] ?? []) . "\n";
    if (isset($gr['children'])) {
        foreach ($gr['children'] as $ci => $c) {
            echo "  Child $ci: type={$c['type']} x={$c['x']} y={$c['y']} w={$c['w']} h={$c['h']}\n";
            $txt = substr($c['content'] ?? '', 0, 40);
            if ($txt) echo "    text: " . str_replace("\n", ' ', $txt) . "\n";
            echo "    style: " . implode(', ', array_keys($c['style'] ?? [])) . "\n";
        }
    }
}

echo "\n=== Display values at depth>=2 (entire tree) ===\n";
function findAllDisplays($ns, $d = 0) {
    $r = [];
    foreach ($ns as $n) {
        if ($d >= 2) {
            $disp = $n['style']['display'] ?? 'NONE';
            $r[] = $disp;
        }
        if (isset($n['children'])) {
            $r = array_merge($r, findAllDisplays($n['children'], $d + 1));
        }
    }
    return $r;
}
$disps = findAllDisplays([$json]);
$counts = array_count_values($disps);
foreach ($counts as $k => $v) {
    echo "  $k: $v\n";
}

echo "\n=== Checking if ANY element has display=grid ===\n";
function findAnyGrid($ns, $d = 0) {
    foreach ($ns as $n) {
        if (isset($n['style']['display']) && $n['style']['display'] === 'grid') {
            echo "  Found at depth=$d: x={$n['x']} y={$n['y']} w={$n['w']} h={$n['h']}\n";
        }
        if (isset($n['children'])) {
            findAnyGrid($n['children'], $d + 1);
        }
    }
}
findAnyGrid([$json]);
echo "  (done)\n";

echo "\n=== Top-level depth structure ===\n";
function showDepth($ns, $d = 0) {
    foreach ($ns as $i => $n) {
        $disp = $n['style']['display'] ?? '?';
        echo str_repeat('  ', $d) . "  [$d] type={$n['type']} display=$disp w={$n['w']} h={$n['h']}\n";
        if (isset($n['children'])) {
            showDepth($n['children'], $d + 1);
        }
    }
}
showDepth([$json]);
