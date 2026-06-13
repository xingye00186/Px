<?php
$caseDir = __DIR__ . '/test_case';
$cases = glob($caseDir . '/case-*', GLOB_ONLYDIR);
sort($cases);

$total = 0;
$pass = 0;
$fail = 0;

foreach ($cases as $dir) {
    $name = basename($dir);
    $layoutFile = $dir . '/engine_layout.json';
    if (!file_exists($layoutFile)) {
        echo "⏭️  $name -- 无 engine_layout.json\n";
        continue;
    }
    
    $json = json_decode(file_get_contents($layoutFile), true);
    if ($json === null) {
        echo "❌ $name -- JSON 解析失败\n";
        $fail++;
        continue;
    }
    
    // Find container (TL anchor's parent) and anchor positions
    $tlNode = null;
    $brNode = null;
    $container = null;
    
    $walker = function(array $node, $parent = null) use (&$tlNode, &$brNode, &$container, &$walker) {
        $bg = $node['style']['bg'] ?? 0;
        if ($bg === 16711935) { // TL
            $tlNode = $node;
            $container = $parent;
        }
        if ($bg === 16776960) { // BR
            $brNode = $node;
        }
        foreach ($node['children'] ?? [] as $ch) {
            $walker($ch, $node);
        }
    };
    $walker($json);
    
    if (!$tlNode || !$brNode || !$container) {
        echo "⏭️  $name -- 锚点不完整 (tl=" . ($tlNode?'y':'n') . " br=" . ($brNode?'y':'n') . " c=" . ($container?'y':'n') . ")\n";
        $fail++;
        continue;
    }
    
    $cx = $container['x'] ?? 0;
    $cy = $container['y'] ?? 0;
    $cw = $container['w'] ?? 0;
    $ch = $container['h'] ?? 0;
    $cVisW = $container['visualW'] ?? $cw;
    $cVisH = $container['visualH'] ?? $ch;
    
    $tlx = $tlNode['x'] ?? 0;
    $tly = $tlNode['y'] ?? 0;
    $brx = $brNode['x'] ?? 0;
    $bry = $brNode['y'] ?? 0;
    $brw = $brNode['w'] ?? 8;
    $brh = $brNode['h'] ?? 8;
    
    // TL should be at container top-left padding box corner
    $tlPass = ($tlx === $cx) && ($tly === $cy);
    // BR should be at container bottom-right padding box corner - 8px
    $brPassX = abs($brx - ($cx + $cVisW - $brw)) <= 1;
    $brPassY = abs($bry - ($cy + $cVisH - $brh)) <= 1;
    $brPass = $brPassX && $brPassY;
    
    if ($tlPass && $brPass) {
        echo "✅ $name -- TL($tlx,$tly) BR($brx,$bry) container($cx,$cy {$cw}x{$ch} vis={$cVisW}x{$cVisH})\n";
        $pass++;
    } else {
        echo "❌ $name -- TL($tlx,$tly)期望($cx,$cy) BR($brx,$bry)期望(" . ($cx+$cVisW-$brw) . "," . ($cy+$cVisH-$brh) . ") container($cx,$cy {$cw}x{$ch} vis={$cVisW}x{$cVisH})\n";
        $fail++;
    }
    $total++;
}

echo "\n汇总: $total cases, $pass ✅, $fail ❌\n";
