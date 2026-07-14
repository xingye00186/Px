<?php
// Fix BOM + double backslash namespace issues
$dirs = [
    'f:/work/Px/framework/Paint/Backend',
    'f:/work/Px/framework/Component/Contracts',
    'f:/work/Px/framework/Component',
];

foreach ($dirs as $dir) {
    $files = glob("$dir/*.php");
    foreach ($files as $file) {
        $c = file_get_contents($file);
        
        // Fix 1: Remove BOM
        $bom = "\xEF\xBB\xBF";
        if (substr($c, 0, 3) === $bom) {
            $c = substr($c, 3);
            echo "BOM removed: $file\n";
        }
        
        // Fix 2: Replace all \ with single backslash (double → single)
        // But we need to be careful: PHP strings use \ for escapes
        // The actual file bytes have \\ (two 0x5C) that should be \ (one 0x5C)
        $c = str_replace('\\\\', '\\', $c);
        
        file_put_contents($file, $c);
    }
}

echo "done\n";
