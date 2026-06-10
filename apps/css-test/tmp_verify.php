<?php
$appDir = 'f:/work/Px/apps/css-test';
$layoutFile = $appDir . '/engine_layout.json';
$refDir = $appDir . '/ref';

$json = file_get_contents($layoutFile);
$layout = json_decode($json, true);

function flattenEngineTree($node, $depth = 0) {
    if ($node === null) return [];
    $result = [];
    $result[] = [
        'content' => $node['content'] ?? '',
        'x' => $node['x'] ?? 0,
        'y' => $node['y'] ?? 0,
        'w' => $node['w'] ?? 0,
        'h' => $node['h'] ?? 0,
        'style' => $node['style'] ?? []
    ];
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $c) {
            $result = array_merge($result, flattenEngineTree($c, $depth + 1));
        }
    }
    return $result;
}

$flat = flattenEngineTree($layout);
echo "Engine nodes: " . count($flat) . "\n";

$textNodes = 0;
foreach ($flat as $el) {
    if (trim($el['content'] ?? '') !== '') $textNodes++;
}
echo "Engine text nodes: $textNodes\n";

// Load refs
for ($level = 0; $level <= 7; $level++) {
    $path = $refDir . '/browser_ref_level_' . $level . '.json';
    if (!file_exists($path)) { echo "Level $level: missing\n"; continue; }
    $d = json_decode(file_get_contents($path), true);
    $elements = $d['elements'] ?? [];
    
    $textElements = 0;
    foreach ($elements as $el) {
        if (trim($el['text'] ?? '') !== '') $textElements++;
    }
    
    echo "Level $level: " . count($elements) . " total, $textElements with text\n";
}
