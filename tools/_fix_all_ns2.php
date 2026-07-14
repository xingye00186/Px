<?php
// Fix BOM + double backslash + namespace corruption in ALL framework php files
$fw = 'f:/work/Px/framework';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fw));
$fixed = 0;

foreach ($rii as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    if (strpos($file->getPathname(), 'Compiler') !== false) continue;
    
    $c = file_get_contents($file->getPathname());
    $orig = $c;
    
    // Remove BOM
    $bom = "\xEF\xBB\xBF";
    if (substr($c, 0, 3) === $bom) {
        $c = substr($c, 3);
    }
    
    // Replace ALL occurrences of Px\\(SOMETHING) (double backslash after Px) with single
    // This fixes "namespace Px\\Paint\\Backend;" → "namespace Px\Paint\Backend;"
    if (strpos($c, 'namespace Px\\') !== false && strpos($c, 'Px\\\\') !== false) {
        $c = str_replace('Px\\\\', 'Px\\', $c);
    }
    // Also fix any remaining \\ (double backslash) that should be single
    // Only in namespace/use lines, not in string literals
    if (strpos($c, 'use Px\\\\') !== false) {
        $c = str_replace('use Px\\\\', 'use Px\\', $c);
    }
    // Fix use statements with double backslash (e.g., use Px\\Paint\\Backend\\Xxx)
    $c = preg_replace('/(use Px\\\\)\\\\/', '$1', $c);
    $c = preg_replace('/namespace Px\\\\([^\\\\])/', 'namespace Px\\$1', $c);
    
    if ($c !== $orig) {
        file_put_contents($file->getPathname(), $c);
        $rel = substr($file->getPathname(), strlen($fw) + 1);
        echo "FIXED: $rel\n";
        $fixed++;
    }
}
echo "\nTotal fixed: $fixed\n";
