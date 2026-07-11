<?php
/**
 * Find dead methods: defined but never called (except constructors, core public methods)
 */
$dirs = [
    'f:/work/Px/framework/Rendering/Layout' => 'Layout/',
    'f:/work/Px/framework/Rendering' => 'Rendering/',
    'f:/work/Px/framework/Core' => 'Core/',
];

$allMethods = [];

foreach ($dirs as $dir => $prefix) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $f) {
        if ($f->getExtension() !== 'php') continue;
        $c = file_get_contents($f->getPathname());
        $path = $f->getPathname();
        
        // Find all method definitions
        preg_match_all('/^\s*(public|private|protected)\s+(static\s+)?function\s+(\w+)/m', $c, $m);
        foreach ($m[3] as $method) {
            $relPath = str_replace('f:/work/Px/framework/', '', $path);
            $allMethods[$method][] = $relPath;
        }
    }
}

// Check if each method is called (ignoring definition sites)
foreach ($allMethods as $method => $defs) {
    if (in_array($method, ['__construct', 'layout', 'mainLayout', 'buildChildSpace', 'selectAlgorithm'])) continue;
    
    $callCount = 0;
    // Search across framework for calls (not on definition lines)
    foreach ($dirs as $dir => $prefix) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($files as $f) {
            if ($f->getExtension() !== 'php') continue;
            $c = file_get_contents($f->getPathname());
            // Count non-definition occurrences
            $lines = explode("\n", $c);
            foreach ($lines as $line) {
                if (strpos($line, "->$method(") !== false || strpos($line, "::$method(") !== false) {
                    // Not on a 'function' definition line
                    if (strpos(trim($line), 'function') !== 0) {
                        $callCount++;
                    }
                }
            }
        }
    }
    
    if ($callCount === 0) {
        echo "DEAD: $method (defined in: " . implode(', ', $defs) . ")\n";
    }
}
