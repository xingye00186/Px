<?php
// Fix all files that reference classes from other migrated namespaces without explicit "use"
$fw = 'f:/work/Px/framework';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fw));

// Files where bare class names need explicit "use" because the class is now in another namespace
// Format: [file_contains, expected_namespace, missing_use_class, use_statement]
$fixes = [];

foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    if (strpos($f->getPathname(), 'Compiler') !== false) continue;
    $path = $f->getPathname();
    $content = file_get_contents($path);
    $orig = $content;
    $rel = substr($path, strlen($fw) + 1);
    
    // Check for VNode usage without explicit import
    // Pattern: "VNode $" or "VNode;" or ": VNode" or "instanceof VNode" or "VNode|"
    // But should NOT be in Dom/ namespace (where VNode lives)
    if (preg_match('/\bVNode\b/', $content) && strpos($path, 'Dom/VNode.php') === false) {
        if (!preg_match('/^use\s+Px\\\\Dom\\\\VNode;/m', $content)) {
            // Add use statement after namespace declaration
            $content = preg_replace('/^(namespace[^;]+;)$/m', "$1\nuse Px\\Dom\\VNode;", $content);
        }
    }
    
    // Check for ComputedStyle usage without explicit import
    if (preg_match('/\bComputedStyle\b/', $content)) {
        if (!preg_match('/^use\s+Px\\\\Css\\\\ComputedStyle;/m', $content) && strpos($path, 'Css/ComputedStyle.php') === false) {
            $content = preg_replace('/^(namespace[^;]+;)$/m', "$1\nuse Px\\Css\\ComputedStyle;", $content);
        }
    }
    
    if ($content !== $orig) {
        file_put_contents($path, $content);
        echo "FIXED: $rel\n";
    }
}
echo "done\n";
