<?php
/**
 * Debug: Trace block element width calculation for case-002
 * Run: php apps/css-test/_debug_width.php
 */
require_once __DIR__ . '/../../tools/shared_test_lib.php';

// Read the engine layout JSON
$layoutFile = __DIR__ . '/test_case/case-002-auto-height/ref/engine_layout.json';
$layout = json_decode(file_get_contents($layoutFile), true);

// Function to recursively search for a node by type and approximate position
function findNodes($node, $path = '') {
    $results = [];
    $currentPath = $path . '/' . $node['type'];
    
    // Look for wrapper div with w=20
    if (isset($node['w']) && isset($node['style'])) {
        $hasPad = isset($node['style']['paddingLeft']) || isset($node['style']['padding']);
        if ($node['w'] === 20 && $hasPad) {
            $results[] = [
                'path' => $currentPath,
                'x' => $node['x'],
                'y' => $node['y'],
                'w' => $node['w'],
                'h' => $node['h'],
                'visualW' => $node['visualW'],
                'visualH' => $node['visualH'],
                'style' => $node['style'],
                'childCount' => count($node['children'] ?? []),
                'content' => $node['content'] ?? null
            ];
        }
    }
    
    // Also find content-body (scroll container)
    if (isset($node['isScrollContainer']) && $node['isScrollContainer'] && $node['x'] === 280) {
        $results[] = [
            'path' => $currentPath . ' [SCROLL]',
            'x' => $node['x'],
            'y' => $node['y'],
            'w' => $node['w'],
            'h' => $node['h'],
            'visualW' => $node['visualW'],
            'style' => $node['style'],
            'childCount' => count($node['children'] ?? []),
            'content' => null
        ];
    }
    
    // Find nodes with w=0 that should have auto-width
    if (isset($node['w']) && $node['w'] === 0 && isset($node['content']) && is_string($node['content'])) {
        $results[] = [
            'path' => $currentPath . ' [ZERO-W]',
            'x' => $node['x'],
            'y' => $node['y'],
            'w' => $node['w'],
            'h' => $node['h'],
            'visualW' => $node['visualW'],
            'style' => $node['style'],
            'content' => substr($node['content'], 0, 40)
        ];
    }
    
    if (isset($node['children'])) {
        foreach ($node['children'] as $i => $child) {
            $childResults = findNodes($child, $currentPath);
            $results = array_merge($results, $childResults);
        }
    }
    
    return $results;
}

echo "=== Case-002 Layout Debug ===\n\n";

$results = findNodes($layout);

echo "Content-body and affected nodes:\n\n";
foreach ($results as $r) {
    echo "PATH: {$r['path']}\n";
    echo "  POS: x={$r['x']} y={$r['y']} w={$r['w']} h={$r['h']}\n";
    echo "  VIS: w={$r['visualW']} h={$r['visualH']}\n";
    if ($r['content']) echo "  TEXT: \"{$r['content']}\"\n";
    echo "  STYLE: " . json_encode($r['style'], JSON_PRETTY_PRINT) . "\n";
    echo "  CHILDREN: {$r['childCount']}\n";
    echo "---\n\n";
}

// Also print a summary of the tree path from root
echo "\n=== Tree Path to Wrapper ===\n";
function tracePath($node, $targetX, $targetY, $path = '') {
    $currentPath = $path . '/' . $node['type'] . "(x={$node['x']},y={$node['y']},w={$node['w']})";
    
    if ($node['x'] === $targetX && $node['y'] === $targetY && $node['w'] === 20) {
        echo $currentPath . " ← WRAPPER\n";
        return true;
    }
    
    if (isset($node['children'])) {
        foreach ($node['children'] as $child) {
            if (tracePath($child, $targetX, $targetY, $currentPath)) {
                return true;
            }
        }
    }
    return false;
}

tracePath($layout, 300, 73, '');
echo "\n";
