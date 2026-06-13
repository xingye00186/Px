<?php
/**
 * Convert all EUC-CN .vue and .html files in test_case/ to UTF-8
 */
$testCaseDir = 'f:/work/Px/apps/css-test/test_case';
$converted = 0;
$errors = [];

// Walk through all case directories
$cases = glob($testCaseDir . '/case-*');
foreach ($cases as $caseDir) {
    // Find .vue and .html files
    $files = array_merge(
        glob($caseDir . '/*.vue'),
        glob($caseDir . '/*.html')
    );
    
    foreach ($files as $fp) {
        $content = file_get_contents($fp);
        if ($content === false) {
            $errors[] = "Cannot read: $fp";
            continue;
        }
        
        // Detect encoding
        $detected = mb_detect_encoding($content, ['UTF-8', 'EUC-CN', 'GB2312', 'GBK', 'GB18030', 'ISO-8859-1', 'Windows-1252', 'CP936'], true);
        
        $relPath = basename(dirname($fp)) . '/' . basename($fp);
        
        if ($detected === 'EUC-CN' || $detected === 'GB2312' || $detected === 'GBK' || $detected === 'GB18030' || $detected === 'CP936') {
            // Convert to UTF-8
            $utf8Content = mb_convert_encoding($content, 'UTF-8', $detected);
            if ($utf8Content === false) {
                $errors[] = "Conversion failed: $fp ($detected)";
                continue;
            }
            
            file_put_contents($fp, $utf8Content);
            echo "  CONVERTED: $relPath ($detected -> UTF-8)\n";
            $converted++;
        } elseif ($detected === 'UTF-8') {
            // Already UTF-8, verify no invalid sequences
            if (mb_check_encoding($content, 'UTF-8')) {
                // Already valid UTF-8
            } else {
                $errors[] = "Marked UTF-8 but invalid: $fp";
            }
        } else {
            echo "  SKIP ($detected): $relPath\n";
        }
    }
}

echo "\nConverted: $converted files\n";
if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $e) echo "  $e\n";
}
