<?php
$fw = 'f:/work/Px/framework';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fw));
$count = 0;
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    if (strpos($f->getPathname(), 'Compiler') !== false) continue;
    $c = file_get_contents($f->getPathname());
    $orig = $c;
    
    // Fix inline class references (not use/namespace statements)
    $replacements = [
        ['Px\\Rendering\\TextBackend\\', 'Px\\Text\\'],
        ['Px\\Rendering\\Backend\\', 'Px\\Paint\\Backend\\'],
        ['Px\\Rendering\\Layout\\', 'Px\\Layout\\'],
        ['Px\\Rendering\\', 'Px\\Layout\\'], // generic fallback for LayoutOrchestrator etc
    ];
    // More careful: only replace when inside ::class, instanceof, new, etc.
    $c = str_replace('\\Px\\Rendering\\TextBackend\\DWriteTextBackend::class', '\\Px\\Text\\DWriteTextBackend::class', $c);
    $c = str_replace('\\Px\\Rendering\\TextBackend\\GdiTextBackend::class', '\\Px\\Text\\GdiTextBackend::class', $c);
    $c = str_replace('\\Px\\Rendering\\TextBackend\\SkiaTextBackend::class', '\\Px\\Text\\SkiaTextBackend::class', $c);
    $c = str_replace('\\Px\\Rendering\\Backend\\', '\\Px\\Paint\\Backend\\', $c);
    $c = str_replace('\\Px\\Styling\\', '\\Px\\Theme\\', $c);
    
    if ($c !== $orig) {
        file_put_contents($f->getPathname(), $c);
        $rel = substr($f->getPathname(), strlen($fw) + 1);
        echo "FIXED: $rel\n";
        $count++;
    }
}
echo "\nTotal: $count\n";
