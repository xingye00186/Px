<?php
$j = json_decode(file_get_contents(__DIR__ . '/engine_layout.json'), 1);

function dumpGrids($n, $d = 0) {
    $pfx = str_repeat('  ', $d);
    $disp = isset($n['style']['display']) ? $n['style']['display'] : '?';
    echo $pfx . "[d=$d] type=" . $n['type'] . " display=$disp w=" . $n['w'] . " h=" . $n['h'] . "\n";
    
    if ($disp === 'grid') {
        $keys = isset($n['style']) ? implode(', ', array_keys($n['style'])) : 'none';
        echo $pfx . "  >>> GRID style keys: " . $keys . "\n";
        if (isset($n['children'])) {
            foreach ($n['children'] as $c) {
                $cd = isset($c['style']['display']) ? $c['style']['display'] : '?';
                $cks = isset($c['style']) ? implode(', ', array_keys($c['style'])) : 'none';
                echo $pfx . "    child: display=$cd w={$c['w']} h={$c['h']} x={$c['x']} y={$c['y']} style=$cks\n";
            }
        }
    }
    
    if (isset($n['children'])) {
        foreach ($n['children'] as $c) {
            dumpGrids($c, $d + 1);
        }
    }
}

dumpGrids($j);
