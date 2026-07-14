<?php
$fw = 'f:/work/Px/framework';
$files = [
    "$fw/Core/Application.php",
    "$fw/Css/CssMappings.php",
    "$fw/Css/StyleResolver.php",
    "$fw/Layout/BlockAlgorithm.php",
    "$fw/Layout/LayoutOrchestrator.php",
    "$fw/Paint/Backend/SkiaGaneshD3D11Backend.php",
    "$fw/Paint/Backend/SkiaGaneshWGLBackend.php",
    "$fw/Paint/Backend/SkiaGraphiteDawnBackend.php",
    "$fw/Paint/VNodeRenderer.php",
    "$fw/Text/TextBackendSelector.php",
];

$reps = [
    ['Px\\Rendering\\Diag', 'Px\\Core\\Diag'],
    ['Px\\Rendering\\CssValueParser', 'Px\\Css\\CssValueParser'],
    ['Px\\Rendering\\CssMappings', 'Px\\Css\\CssMappings'],
    ['Px\\Rendering\\RenderNode', 'Px\\Render\\RenderNode'],
    ['Px\\Rendering\\ComputedStyle', 'Px\\Css\\ComputedStyle'],
    ['Px\\Rendering\\SkiaRenderContext', 'Px\\Paint\\SkiaRenderContext'],
    ['Px\\Rendering\\Layout', 'Px\\Layout'],
    ['Px\\Rendering\\TextBackend', 'Px\\Text'],
    ['Px\\Rendering\\Backend', 'Px\\Paint\\Backend'],
    ['Px\\Rendering\\Layout\\PhysicalFragment', 'Px\\Layout\\PhysicalFragment'],
    

];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    $orig = $c;
    foreach ($reps as $r) {
        $c = str_replace($r[0], $r[1], $c);
    }
    if ($c !== $orig) {
        file_put_contents($f, $c);
        echo "FIXED: " . basename($f) . "\n";
    }
}
echo "done\n";
