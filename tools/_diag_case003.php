<?php
$e = json_decode(file_get_contents('f:/work/Px/apps/css-test/test_case/case-003-basic-block/ref/engine_ref_level_0_php.json'), true);
$b = json_decode(file_get_contents('f:/work/Px/apps/css-test/test_case/case-003-basic-block/ref/browser_ref_level_0.json'), true);

// Sample first 10 elements to see positions
echo "Engine first 10 elements:\n";
for ($i = 0; $i < min(10, count($e['elements'])); $i++) {
    $el = $e['elements'][$i];
    echo "  [$i] tag=" . ($el['tag']??'?') . " x=" . ($el['x']??0) . " y=" . ($el['y']??0) . " w=" . ($el['w']??0) . " h=" . ($el['h']??0) . "\n";
}

echo "\nBrowser first 10 elements:\n";
for ($i = 0; $i < min(10, count($b['elements'])); $i++) {
    $el = $b['elements'][$i];
    echo "  [$i] tag=" . ($el['tag']??'?') . " x=" . ($el['x']??0) . " y=" . ($el['y']??0) . " w=" . ($el['w']??0) . " h=" . ($el['h']??0) . "\n";
}

// Diffs by dimension
$critX = 0; $critY = 0; $critW = 0; $critH = 0;
for ($i = 0; $i < min(count($e['elements']), count($b['elements'])); $i++) {
    $dx = abs(($e['elements'][$i]['x']??0) - ($b['elements'][$i]['x']??0));
    $dy = abs(($e['elements'][$i]['y']??0) - ($b['elements'][$i]['y']??0));
    $dw = abs(($e['elements'][$i]['w']??0) - ($b['elements'][$i]['w']??0));
    $dh = abs(($e['elements'][$i]['h']??0) - ($b['elements'][$i]['h']??0));
    if ($dx > 20) $critX++;
    if ($dy > 20) $critY++;
    if ($dw > 20) $critW++;
    if ($dh > 20) $critH++;
}

echo "\nDiff breakdown:\n";
echo "  CRITICAL(>20): x=$critX y=$critY w=$critW h=$critH\n";
echo "  Engine elements: " . count($e['elements']) . "\n";
echo "  Browser elements: " . count($b['elements']) . "\n";
