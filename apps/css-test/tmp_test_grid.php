<?php
// Direct test of parseInlineStyle - no framework autoloading
// Manually load required classes

spl_autoload_register(function ($class) {
    $map = [
        'Px\\Rendering\\CssMappings' => 'framework/Rendering/CssMappings.php',
        'Px\\Rendering\\CssValueParser' => 'framework/Rendering/CssValueParser.php',
    ];
    if (isset($map[$class])) {
        require_once __DIR__ . '/../../' . $map[$class];
    }
});

$styleStr = 'display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px';
$result = Px\Rendering\CssMappings::parseInlineStyle($styleStr);

echo "Input: $styleStr\n\n";
echo "Result has " . count($result) . " keys:\n";
foreach ($result as $k => $v) {
    echo "  $k => " . (is_string($v) ? "'$v'" : $v) . "\n";
}

echo "\ngridTemplateColumns present: " . (isset($result['gridTemplateColumns']) ? 'YES' : 'NO') . "\n";
echo "gap present: " . (isset($result['gap']) ? 'YES' : 'NO') . "\n";

// Also test what parseGridTemplateValue returns for the parsed value
echo "\n--- parseGridTemplateValue('1fr 1fr') ---\n";
$parsed = Px\Rendering\CssMappings::parseGridTemplateValue('1fr 1fr');
echo json_encode($parsed, JSON_UNESCAPED_UNICODE) . "\n";
