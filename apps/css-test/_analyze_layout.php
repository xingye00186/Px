<?php
$j = json_decode(file_get_contents('d:/Px/apps/css-test/test_case/case-002-auto-height/ref/engine_layout.json'), true);
function walk($n, $d = 0) {
    if (!$n || $d > 5) return;
    $s = $n['style'] ?? [];
    $text = substr($n['content'] ?? ($n['text'] ?? ''), 0, 40);
    echo str_repeat('  ', $d)
        . "type={$n['type']} x={$n['x']} y={$n['y']} w={$n['w']} h={$n['h']}"
        . " bs=" . ($s['boxSizing'] ?? '?')
        . " pad=" . ($s['padding'] ?? '-')
        . " padL=" . ($s['paddingLeft'] ?? 0)
        . " padR=" . ($s['paddingRight'] ?? 0)
        . " bw=" . ($s['borderWidth'] ?? 0)
        . " text=[$text]\n";
    if (!empty($n['children'])) foreach ($n['children'] as $c) walk($c, $d + 1);
}
walk($j);
