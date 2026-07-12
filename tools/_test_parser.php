<?php

function parseCssClassesForMerge(string $css): array {
    $result = [];
    if (preg_match_all('#\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}#s', $css, $rules, PREG_SET_ORDER)) {
        foreach ($rules as $rule) {
            $className = $rule[1];
            $body = trim($rule[2]);
            $body = preg_replace('/\s+/', ' ', $body);
            $body = rtrim($body, ';');
            $result[$className] = $body;
        }
    }
    return $result;
}

$vue = file_get_contents('f:/work/Px/apps/css-test/test_case/case-007-border-styles/case-007-border-styles.vue');
preg_match('#<style[^>]*>(.*?)</style>#s', $vue, $m);
$styleBlock = $m[1];
echo 'Style block: ' . strlen($styleBlock) . " bytes\n";
$parsed = parseCssClassesForMerge($styleBlock);
echo 'Parsed classes: ' . count($parsed) . "\n";
foreach ($parsed as $cn => $body) {
    echo "  '$cn' => '$body'\n";
}

// Now check if the gen file has bx-header inline style
$gen = file_get_contents('f:/work/Px/apps/css-test/gen/Case007BorderStylesComponent.php');
if (strpos($gen, "bx-header") !== false) {
    echo "\nGen file HAS bx-header.\n";
}
if (strpos($gen, "margin-bottom:20px") !== false && strpos($gen, "bx-header") !== false) {
    echo "bx-header has margin-bottom in same scope: checking proximity...\n";
}
