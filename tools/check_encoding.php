<?php
$dir = 'f:/work/Px/apps/css-test/test_case';
$cases = glob($dir . '/case-*');
foreach ($cases as $caseDir) {
    $vueFiles = glob($caseDir . '/*.vue');
    foreach ($vueFiles as $vf) {
        $content = file_get_contents($vf);
        $encoding = mb_detect_encoding($content, ['UTF-8', 'GB2312', 'GBK', 'GB18030', 'ISO-8859-1', 'Windows-1252', 'CP936'], true);
        echo basename($caseDir) . '/' . basename($vf) . ': ' . $encoding;
        $first3 = strtoupper(bin2hex(substr($content, 0, 3)));
        echo ' BOM=' . $first3;
        
        // Check for non-ASCII bytes
        $nonAscii = false;
        for ($i = 0; $i < strlen($content); $i++) {
            $b = ord($content[$i]);
            if ($b > 127) {
                $nonAscii = true;
                // Check if it's a valid UTF-8 sequence
                if ($encoding !== 'UTF-8') {
                    echo ' [NON-UTF8]';
                }
                break;
            }
        }
        if (!$nonAscii) echo ' [ASCII-ONLY]';
        echo PHP_EOL;
    }
}
