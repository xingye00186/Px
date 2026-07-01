<?php
$json = json_decode(file_get_contents('f:/work/Px/apps/css-test/test_case/case-001-wrapper-x/ref/engine_layout.json'), true);
function walk($node, $depth = 0) {
    if ($depth > 15) return;
    $type = $node['type'] ?? '?';
    $x = $node['x'] ?? 0;
    $y = $node['y'] ?? 0;
    $w = $node['w'] ?? 0;
    $h = $node['h'] ?? 0;
    echo str_repeat('  ', $depth) . "type=$type x=$x y=$y w=$w h=$h\n";
    if (isset($node['children'])) {
        foreach ($node['children'] as $c) walk($c, $depth + 1);
    }
}
walk($json);
