<?php
$files = [
    'tests/unit/Layout/LayoutBase.php',
    'tests/unit/LayoutResolverTest.php',
    'tests/unit/LayoutEngineTest.php',
    'tests/unit/ScrollSnapshotTest.php',
];

$base = 'f:/work/Px';
foreach ($files as $rel) {
    $path = $base . '/' . $rel;
    if (!file_exists($path)) { echo "NOT FOUND: $rel\n"; continue; }
    $c = file_get_contents($path);
    
    $c = str_replace('use Px\Rendering\LayoutResolver;', 'use Px\Rendering\Layout\LayoutOrchestrator;', $c);
    $c = str_replace('new LayoutResolver()', 'new LayoutOrchestrator()', $c);
    $c = str_replace('->resolve($root)', '->layout($root)', $c);
    $c = str_replace('->resolve($child)', '->layout($child)', $c);
    $c = str_replace('$resolver = new', '$orchestrator = new', $c);
    $c = str_replace('$resolver->resolve(', '$orchestrator->layout(', $c);
    $c = str_replace(': LayoutResolver', ': LayoutOrchestrator', $c);
    
    file_put_contents($path, $c);
    echo "UPDATED: $rel\n";
}
echo "Done\n";
