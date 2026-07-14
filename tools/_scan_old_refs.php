<?php
$fw = 'f:/work/Px/framework';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fw));
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    if (strpos($f->getPathname(), 'Compiler') !== false) continue;
    $c = file_get_contents($f->getPathname());
    // Search for ANY occurrence of Px\Rendering (without use or namespace prefix)
    $pos = strpos($c, 'Px\\Rendering');
    if ($pos !== false) {
        // Get context around the match
        $start = max(0, $pos - 30);
        $len = min(strlen($c) - $start, 80);
        $ctx = substr($c, $start, $len);
        echo "IN " . substr($f->getPathname(), strlen($fw) + 1) . ":\n  ...$ctx...\n\n";
    }
}
echo "scan done\n";
