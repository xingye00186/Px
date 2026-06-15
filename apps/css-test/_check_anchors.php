<?php
$json = json_decode(file_get_contents('D:\\Px\\apps\\css-test\\test_case\\case-001-wrapper-x\\ref\\engine_layout.json'), true);
if (!$json) { die("Failed to parse engine_layout.json\n"); }

// Find anchor nodes (8x8)
$it = new RecursiveIteratorIterator(new RecursiveArrayIterator($json));
foreach ($it as $key => $val) {
    if ($key === 'w' && $val === 8) {
        $depth = $it->getDepth();
        $parent = $it->getSubIterator();
        echo "Node w=8: x={$parent['x']} y={$parent['y']} type={$parent['type']} bg={$parent['bg']} containerOffset_x={$parent['containerOffset']['x']} containerOffset_y={$parent['containerOffset']['y']}" . PHP_EOL;
    }
}

// Also check wrapper-test
echo PHP_EOL . "--- Checking wrapper-test ---" . PHP_EOL;
$it2 = new RecursiveIteratorIterator(new RecursiveArrayIterator($json));
foreach ($it2 as $key => $val) {
    if ($key === 'id' && $val === 'wrapper-test') {
        $parent = $it2->getSubIterator();
        echo "wrapper-test: x={$parent['x']} y={$parent['y']} w={$parent['w']} h={$parent['h']}" . PHP_EOL;
        echo "  borderLeftWidth={$parent['borderLeftWidth']} paddingLeft={$parent['paddingLeft']}" . PHP_EOL;
        echo "  borderTopWidth={$parent['borderTopWidth']} paddingTop={$parent['paddingTop']}" . PHP_EOL;
        echo "  bg={$parent['bg']} position={$parent['position']}" . PHP_EOL;
        echo "  visualW={$parent['visualW']} visualH={$parent['visualH']}" . PHP_EOL;
    }
}

// Check content-body (may have position:relative)
echo PHP_EOL . "--- Checking parent chain for position ---" . PHP_EOL;
$it3 = new RecursiveIteratorIterator(new RecursiveArrayIterator($json));
foreach ($it3 as $key => $val) {
    if ($key === 'position' && ($val === 'relative' || $val === 'absolute' || $val === 'fixed')) {
        $parent = $it3->getSubIterator();
        $id = $parent['id'] ?? '?';
        echo "Node with position={$val}: type={$parent['type']} id={$id} x={$parent['x']} y={$parent['y']}" . PHP_EOL;
    }
}
