<?php
$j = json_decode(file_get_contents('d:/Px/apps/css-test/test_case/case-002-auto-height/ref/engine_layout.json'), true);
function walk($n, $d = 0) {
    if (!$n) return;
    $t = $n['type'] ?? '?';
    $text = $n['content'] ?? $n['text'] ?? '';
    echo str_repeat('  ', $d) . "{$t} x={$n['x']} y={$n['y']} w={$n['w']} h={$n['h']} text=" . substr($text, 0, 50) . "\n";
    if (!empty($n['children'])) foreach ($n['children'] as $c) walk($c, $d + 1);
}
walk($j);
