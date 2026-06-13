<?php
$json = json_decode(file_get_contents(__DIR__ . '/test_case/case-001-wrapper-x/engine_layout.json'), true);

function dumpTree(array $node, int $depth = 0, string $prefix = ''): void {
    $indent = str_repeat('  ', $depth);
    $type = $node['type'] ?? '?';
    $x = $node['x'] ?? 0;
    $y = $node['y'] ?? 0;
    $w = $node['w'] ?? 0;
    $h = $node['h'] ?? 0;
    $visualW = $node['visualW'] ?? $w;
    $visualH = $node['visualH'] ?? $h;
    $bg = $node['style']['bg'] ?? 0;
    
    $label = '';
    if ($bg === 16711935) $label = ' <-- TL锚点';
    if ($bg === 16776960) $label = ' <-- BR锚点';
    
    $c = substr(trim($node['content'] ?? ''), 0, 40);
    if ($c !== '') $c = " text='$c'";
    
    echo "{$indent}{$type} x={$x} y={$y} w={$w} h={$h} visual={$visualW}x{$visualH}{$c}{$label}\n";
    
    foreach ($node['children'] ?? [] as $ch) {
        dumpTree($ch, $depth + 1);
    }
}

dumpTree($json);
