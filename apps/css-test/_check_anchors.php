<?php
$json = json_decode(file_get_contents(__DIR__ . '/test_case/case-001-wrapper-x/engine_layout.json'), true);

function walk(array $node, int $depth = 0): void {
    $bg = $node['style']['bg'] ?? 0;
    $x = $node['x'] ?? 0;
    $y = $node['y'] ?? 0;
    $w = $node['w'] ?? 0;
    $h = $node['h'] ?? 0;
    
    if ($bg === 16711935 || $bg === 16776960) {
        $label = $bg === 16711935 ? 'TL(洋红)' : 'BR(青)';
        $right = $x + $w;
        $bottom = $y + $h;
        echo "  $label: x=$x y=$y w=$w h=$h (右下角=($right,$bottom))\n";
    }
    
    $c = substr(trim($node['content'] ?? ''), 0, 30);
    if ($c !== '') {
        $type = $node['type'] ?? '?';
        echo "  子元素($type): x=$x y=$y w=$w h=$h content='$c'\n";
    }
    
    foreach ($node['children'] ?? [] as $ch) {
        walk($ch, $depth + 1);
    }
}

walk($json);
