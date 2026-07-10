<?php
$files = [
    'tests/unit/LayoutResolverTest.php',
    'tests/unit/LayoutEngineTest.php',
    'tests/unit/ScrollSnapshotTest.php',
    'tests/unit/Layout/LayoutBase.php',
];

$base = 'f:/work/Px';

foreach ($files as $rel) {
    $path = $base . '/' . $rel;
    if (!file_exists($path)) { echo "NOT FOUND: $rel\n"; continue; }
    $c = file_get_contents($path);
    
    // Pattern: $orchestrator->layout($root) → $frag = $orchestrator->layout($root) 
    // (but only when not already assigned)
    $c = preg_replace(
        '/\$orchestrator->layout\((\$\w+)\);/',
        "\$frag = \$orchestrator->layout($1);",
        $c
    );
    
    // Also handle the Base.php which returns the orchestrator
    $c = str_replace(
        '$orchestrator->layout($root);' . "\n" . '    return $orchestrator;',
        '$frag = $orchestrator->layout($root);' . "\n" . '    return $orchestrator;',
        $c
    );
    
    // Pattern: $root->x → $frag->x (when verifying results)
    // But careful: $root->computedStyle should stay as $root->computedStyle
    // Only replace when reading geometry values that the Orchestrator set
    
    file_put_contents($path, $c);
    echo "UPDATED: $rel\n";
}
echo "Done\n";
