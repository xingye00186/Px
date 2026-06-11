<?php
/**
 * 诊断 EXE 截图高度异常：对比 engine_layout 预期间距与截图实际间距
 */
require_once __DIR__ . '/../../tools/shared_test_lib.php';

echo "=== 1. 检查 engine_layout ===" . PHP_EOL;
$layout = json_decode(file_get_contents(__DIR__ . '/engine_layout.json'), true);

function findNodeByContent($nodes, $needle) {
    foreach ($nodes as $n) {
        if (!empty($n['content']) && strpos($n['content'], $needle) !== false) return $n;
        if (!empty($n['children'])) {
            $r = findNodeByContent($n['children'], $needle);
            if ($r) return $r;
        }
    }
    return null;
}

$card = $layout['children'][0];
echo "Card container: {$card['x']},{$card['y']} {$card['w']}x{$card['h']} (visual {$card['visualW']}x{$card['visualH']})" . PHP_EOL;

// Find TL and BR anchors
$tlNode = $card['children'][0];
$brNode = end($card['children']);
echo "TL anchor: {$tlNode['x']},{$tlNode['y']} {$tlNode['w']}x{$tlNode['h']}" . PHP_EOL;
echo "BR anchor: {$brNode['x']},{$brNode['y']} {$brNode['w']}x{$brNode['h']}" . PHP_EOL;

$expTop = $tlNode['y'];
$expBottom = $brNode['y'] + $brNode['h'];
$expHeight = $expBottom - $expTop;
$contentHeight = $card['h'];

// Find topmost and bottommost child content
$minY = PHP_INT_MAX;
$maxY = 0;
function scanBounds($nodes, &$minY, &$maxY) {
    foreach ($nodes as $n) {
        if (isset($n['y']) && $n['y'] < $minY) $minY = $n['y'];
        if (isset($n['y']) && isset($n['h']) && $n['y']+$n['h'] > $maxY) $maxY = $n['y']+$n['h'];
        if (!empty($n['children'])) scanBounds($n['children'], $minY, $maxY);
    }
}
scanBounds($card['children'], $minY, $maxY);
echo "Engine content bounds: y={$minY} to y={$maxY}, height=" . ($maxY-$minY) . PHP_EOL;
echo "Expected TL→BR span: {$expTop}→{$expBottom} = {$expHeight}px" . PHP_EOL;

echo PHP_EOL . "=== 2. 分析 captured 截图 ===" . PHP_EOL;
$cap = imagecreatefrompng(__DIR__ . '/test_log/captured_screenshot.png');
$w = imagesx($cap); $h = imagesy($cap);
echo "Captured image: {$w}x{$h}" . PHP_EOL;

// Find anchors in captured
$tl = findColorAnchor($cap, 255, 0, 255);
$br = findColorAnchor($cap, 0, 255, 255);
if ($tl) echo "Captured TL: ({$tl['x']},{$tl['y']})" . PHP_EOL;
if ($br) echo "Captured BR: ({$br['x']},{$br['y']})" . PHP_EOL;

// Check actual content bounds by scanning for non-background pixels
$bgColor = 1183246; // #0e0e12 in BGR
echo PHP_EOL . "=== 3. 扫描内容实际边界 ===" . PHP_EOL;
// Scan from top to find first non-background row
$firstContentY = -1;
$lastContentY = -1;

// Check a few columns to find card edges
$checkX = [688, 838, 900];
for ($y = 0; $y < $h; $y++) {
    for ($xi = 0; $xi < count($checkX); $xi++) {
        $c = imagecolorat($cap, $checkX[$xi], $y);
        if ($c !== 0 && $c !== $bgColor) {
            if ($firstContentY === -1) $firstContentY = $y;
            if ($y > $lastContentY) $lastContentY = $y;
            break;
        }
    }
}
echo "First non-bg row: y={$firstContentY}" . PHP_EOL;
echo "Last non-bg row: y={$lastContentY}" . PHP_EOL;
echo "Content height: " . ($lastContentY - $firstContentY) . "px" . PHP_EOL;

// Check the TL anchor area in detail
echo PHP_EOL . "=== 4. 锚点区域精细采样 ===" . PHP_EOL;
echo "--- TL anchor region (688,186) ---" . PHP_EOL;
for ($dy = -2; $dy <= 10; $dy++) {
    $colors = [];
    for ($dx = -2; $dx <= 10; $dx++) {
        $x = 688 + $dx;
        $y = 186 + $dy;
        if ($x >= 0 && $x < $w && $y >= 0 && $y < $h) {
            $c = imagecolorat($cap, $x, $y);
            $colors[] = sprintf("#%06x", $c);
        }
    }
    echo "  y=" . ($dy+186) . ": " . implode("|", $colors) . PHP_EOL;
}

echo PHP_EOL . "--- BR anchor region (1188,1090) ---" . PHP_EOL;
for ($dy = -2; $dy <= 10; $dy++) {
    $colors = [];
    for ($dx = -2; $dx <= 10; $dx++) {
        $x = 1188 + $dx;
        $y = 1090 + $dy;
        if ($x >= 0 && $x < $w && $y >= 0 && $y < $h) {
            $c = imagecolorat($cap, $x, $y);
            $colors[] = sprintf("#%06x", $c);
        }
    }
    echo "  y=" . ($dy+1090) . ": " . implode("|", $colors) . PHP_EOL;
}

// Check: is the card content being stretched? 
// By comparing specific text element positions
echo PHP_EOL . "=== 5. 关键元素位置对比 ===" . PHP_EOL;
// "Stay" text should be at a specific position
$stayNode = findNodeByContent($card['children'], 'Stay');
echo "Engine 'Stay': x={$stayNode['x']}, y={$stayNode['y']}, w={$stayNode['w']}, h={$stayNode['h']}" . PHP_EOL;

// Scan for "Stay" text pixels in captured
$stayFound = [];
for ($y = 0; $y < $h; $y++) {
    for ($x = 688; $x < 688+480 && $x < $w; $x++) {
        $c = imagecolorat($cap, $x, $y);
        $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
        // White text: R=255, G=255, B=255 (on dark background)
        if ($r > 200 && $g > 200 && $b > 200) {
            $stayFound[] = ['x'=>$x, 'y'=>$y];
        }
    }
}
if (!empty($stayFound)) {
    $minY = PHP_INT_MAX; $maxY = 0;
    foreach ($stayFound as $p) {
        if ($p['y'] < $minY) $minY = $p['y'];
        if ($p['y'] > $maxY) $maxY = $p['y'];
    }
    echo "Captured 'Stay' y-range: {$minY}→{$maxY} (engine expects " . $stayNode['y'] . "→" . ($stayNode['y']+$stayNode['h']) . ")" . PHP_EOL;
    echo "  Vertical offset: engine y=" . $stayNode['y'] . " → captured " . $minY . " (diff: " . ($stayNode['y'] - $minY) . ")" . PHP_EOL;
}

// Compare actual card background bounds
echo PHP_EOL . "=== 6. 卡片背景颜色边界扫描 ===" . PHP_EOL;
$cardBg = 2366492; // #1c1c24 in BGR
$cardStart = -1; $cardEnd = -1;
for ($y = 0; $y < $h; $y++) {
    $c = imagecolorat($cap, 660+20, $y); // peek inside card left edge
    if ($c === $cardBg || abs(($c&0xFF)-36) <= 10) { // approximate match
        if ($cardStart === -1) $cardStart = $y;
        $cardEnd = $y;
    }
}
echo "Card bg starts at y={$cardStart}, ends at y={$cardEnd}" . PHP_EOL;
echo "Engine expects card y={$card['y']} to y={$card['y']}+{$card['h']}=" . ($card['y']+$card['h']) . PHP_EOL;

imagedestroy($cap);
