<?php
$j = json_decode(file_get_contents(__DIR__ . '/engine_layout.json'), 1);

// j is a single root element, not an array of elements
$root = $j;

function findGrid($ns, $d = 0) {
    global $seen;
    if ($d === 2 && isset($ns['style']['display']) && $ns['style']['display'] === 'grid') {
        $seen[] = $ns;
    }
    if (isset($ns['children'])) {
        foreach ($ns['children'] as $v) {
            findGrid($v, $d + 1);
        }
    }
}
$seen = [];
findGrid($root);
foreach ($seen as $idx => $g) {
    echo 'Grid ' . $idx . ': w=' . $g['w'] . ' h=' . $g['h'] . ' x=' . $g['x'] . ' y=' . $g['y'] . "\n";
    $gtc = isset($g['style']['gridTemplateColumns']) ? $g['style']['gridTemplateColumns'] : 'MISSING';
    echo '  gridTemplateColumns=' . $gtc . "\n";
    echo '  display=' . (isset($g['style']['display']) ? $g['style']['display'] : '?') . "\n";
    echo '  style keys: ' . implode(', ', array_keys($g['style'])) . "\n";
    $w = isset($g['style']['width']) ? $g['style']['width'] : 'not set';
    echo '  width=' . $w . "\n";
    $box = isset($g['style']['boxSizing']) ? $g['style']['boxSizing'] : 'not set';
    echo '  boxSizing=' . $box . "\n";
    echo '  node.w=' . $g['w'] . "\n";
    
    if (isset($g['children'])) {
        foreach ($g['children'] as $ci => $c) {
            $cd = isset($c['style']['display']) ? $c['style']['display'] : '?';
            echo "  Child $ci: type={$c['type']} w={$c['w']} h={$c['h']} display=$cd\n";
        }
    }
    echo "\n";
}

echo "=== Find outer container (w=1100px) ===\n";
function findOuter($ns, $d = 0) {
    $sw = isset($ns['style']['width']) ? $ns['style']['width'] : '';
    if ($ns['type'] === 'div' && $sw === '1100px') {
        echo 'Outer container depth=' . $d . ': w=' . $ns['w'] . ' h=' . $ns['h'] . "\n";
        echo '  style: ' . json_encode($ns['style']) . "\n\n";
    }
    if (isset($ns['children'])) {
        foreach ($ns['children'] as $v) {
            findOuter($v, $d + 1);
        }
    }
}
findOuter($root);

echo "=== Check parent of first grid container ===\n";
function findParentOfGrid($ns, $d = 0, $parent = null) {
    if ($d === 2 && isset($ns['style']['display']) && $ns['style']['display'] === 'grid' && $parent !== null) {
        echo 'Parent of grid at depth ' . ($d-1) . ": type={$parent['type']} w={$parent['w']} h={$parent['h']}\n";
        echo '  parent style: ' . json_encode($parent['style']) . "\n\n";
    }
    if (isset($ns['children'])) {
        foreach ($ns['children'] as $v) {
            findParentOfGrid($v, $d + 1, $ns);
        }
    }
}
findParentOfGrid($root);
