<?php
$fw = 'f:/work/Px/framework';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fw));
$count = 0;
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    if (strpos($f->getPathname(), 'Compiler') !== false) continue;
    $c = file_get_contents($f->getPathname());
    // Check for remaining old namespace references in use/namespace lines
    if (preg_match('/^(use|namespace)\s+Px\\\\(?:Rendering|Styling|Interfaces)\b/m', $c)) {
        $rel = substr($f->getPathname(), strlen($fw) + 1);
        echo "REMAINING: $rel\n";
        $count++;
    }
}
echo "\nTotal: $count\n";
