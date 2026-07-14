<?php
$fw = 'f:/work/Px/framework';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fw));
$errors = [];
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    if (strpos($f->getPathname(), 'Compiler') !== false) continue;
    $cmd = 'php -l "' . $f->getPathname() . '" 2>&1';
    $out = shell_exec($cmd);
    if (strpos($out, 'Parse error') !== false || strpos($out, 'Fatal error') !== false) {
        $errors[] = $f->getPathname();
    }
}
foreach ($errors as $e) {
    echo "ERROR: " . substr($e, strlen($fw) + 1) . "\n";
}
echo "\nTotal errors: " . count($errors) . "\n";
