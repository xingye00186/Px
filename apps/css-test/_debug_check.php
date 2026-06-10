<?php
$j = json_decode(file_get_contents(__DIR__ . '/engine_layout.json'), 1);
echo 'type: ' . gettype($j) . "\n";
if (is_array($j)) {
    echo 'keys: ' . implode(', ', array_keys($j)) . "\n";
    $tf = isset($j['type']) ? $j['type'] : 'none';
    echo 'type field: ' . $tf . "\n";
}
echo "---\n";
