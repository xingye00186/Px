<?php
require 'f:/work/Px/framework/Rendering/CssValueParser.php';
require 'f:/work/Px/framework/Rendering/CssMappings.php';

// Test the exact grid style
$style = 'display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px';
$result = Px\Rendering\CssMappings::parseInlineStyle($style);
echo "test keys: " . implode(', ', array_keys($result)) . "\n";
if (isset($result['gridTemplateColumns'])) {
    echo "gridTemplateColumns=" . $result['gridTemplateColumns'] . "\n";
} else {
    echo "gridTemplateColumns NOT FOUND\n";
}
echo "\nFull result:\n";
print_r($result);

echo "\n\n--- Parsing grid template value '1fr 1fr' ---\n";
$parsed = Px\Rendering\CssValueParser::parseGridTemplateValue('1fr 1fr');
print_r($parsed);
