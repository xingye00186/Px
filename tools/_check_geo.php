<?php
$j = json_decode(file_get_contents('f:/work/Px/apps/css-test/test_case/case-001-wrapper-x/ref/engine_layout.json'), true);
echo "Root: x={$j['x']} y={$j['y']} w={$j['w']} h={$j['h']}\n";
echo "Size: " . strlen(json_encode($j)) . " bytes\n";
echo "Keys: " . implode(', ', array_keys($j)) . "\n";

// Walk tree counting nodes and non-zero coordinates
$total = 0; $nonZeroX = 0; $nonZeroY = 0; $nonZeroW = 0; $nonZeroH = 0;
$walk = function($n) use (&$walk, &$total, &$nonZeroX, &$nonZeroY, &$nonZeroW, &$nonZeroH) {
    $total++;
    if (isset($n['x']) && $n['x'] != 0) $nonZeroX++;
    if (isset($n['y']) && $n['y'] != 0) $nonZeroY++;
    if (isset($n['w']) && $n['w'] > 0) $nonZeroW++;
    if (isset($n['h']) && $n['h'] > 0) $nonZeroH++;
    foreach ($n['children'] ?? [] as $ch) $walk($ch);
};
$walk($j);
echo "\nTree: $total nodes\n";
echo "  x != 0: $nonZeroX\n";
echo "  y != 0: $nonZeroY\n";
echo "  w > 0: $nonZeroW\n";
echo "  h > 0: $nonZeroH\n";
