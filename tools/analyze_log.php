<?php
// Analyze the layout log to find the ghost node's origin
$c = file_get_contents('C:/Users/87677/.qoder/cache/projects/Px-5a02c36b/agent-tools/task-15c/87f1f9f2.txt');
$lines = explode("\n", $c);

// Find the ghost (x=20, parent=div@(0,52)) and trace div@(0,52)'s own LAYOUT log
echo "=== NODES WITH parent=div@(0,52) ===\n";
foreach ($lines as $l) {
    if (strpos($l, 'parent=div@(0,52)') !== false) {
        echo $l . "\n";
    }
}

echo "\n=== THE div@(0,52) node itself ===\n";
foreach ($lines as $l) {
    if (strpos($l, '[LAYOUT]') !== false) {
        // Extract x and y
        preg_match('/x=(\d+).*?y=(\d+)/', $l, $m);
        if (isset($m[1]) && $m[1] === '0' && isset($m[2]) && $m[2] === '52') {
            echo $l . "\n";
        }
    }
}

echo "\n=== ALL divs sorted by position ===\n";
$divs = [];
foreach ($lines as $l) {
    if (strpos($l, '[LAYOUT]') !== false && strpos($l, 'type=div') !== false) {
        preg_match('/x=(\d+).*?y=(\d+).*?parent=(\S+)/', $l, $m);
        if (isset($m[1])) {
            $divs[] = ['line' => $l, 'x' => (int)$m[1], 'y' => (int)$m[2]];
        }
    }
}
usort($divs, fn($a, $b) => $a['x'] <=> $b['x'] ?: $a['y'] <=> $b['y']);
foreach ($divs as $d) {
    echo 'pos=(' . $d['x'] . ',' . $d['y'] . ') ' . substr($d['line'], 0, 120) . "\n";
}
