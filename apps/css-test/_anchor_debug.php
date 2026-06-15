<?php
$json = json_decode(file_get_contents(__DIR__ . '/engine_layout.json'), true);
function findNode($node, $depth = 0) {
    $style = $node['style'] ?? [];
    if (isset($style['boxSizing']) && $style['boxSizing'] === 'border-box') {
        echo 'WRAPPER-TEST STYLE: ' . json_encode($style, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo 'x=' . $node['x'] . ' y=' . $node['y'] . ' w=' . $node['w'] . ' h=' . $node['h'] . PHP_EOL;
    }
    if ($node['w'] === 8 && $node['h'] === 8) {
        echo 'ANCHOR x=' . $node['x'] . ' y=' . $node['y'] . PHP_EOL;
        echo 'ANCHOR STYLE: ' . json_encode($style, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    if (isset($node['children'])) {
        foreach ($node['children'] as $c) findNode($c, $depth + 1);
    }
}
findNode($json);
