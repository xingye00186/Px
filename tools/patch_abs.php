<?php
// Patch AbsolutePositioning.php - skip position:relative without offset
$path = 'f:/work/Px/framework/Rendering/Layout/AbsolutePositioning.php';
$content = file_get_contents($path);

$search = 'while ($ancestor !== null) {
            $pos = $ancestor->computedStyle?->position?->value ?? \'static\';
            if ($pos !== \'static\') {
                $node->positioningAncestor = $ancestor;
                $node->positioningAncestorValid = true;
                return;
            }
            $ancestor = $ancestor->parent;
        }';

$replace = 'while ($ancestor !== null) {
            $pos = $ancestor->computedStyle?->position?->value ?? \'static\';
            if ($pos !== \'static\') {
                $hasOffset = ($ancestor->computedStyle?->left ?? 0) !== 0
                    || ($ancestor->computedStyle?->top ?? 0) !== 0
                    || ($ancestor->computedStyle?->getRaw(\'right\') ?? null) !== null
                    || ($ancestor->computedStyle?->getRaw(\'bottom\') ?? null) !== null;
                if ($pos === \'relative\' && !$hasOffset && $ancestor->parent !== null) {
                    $ancestor = $ancestor->parent;
                    continue;
                }
                $node->positioningAncestor = $ancestor;
                $node->positioningAncestorValid = true;
                return;
            }
            $ancestor = $ancestor->parent;
        }';

if (strpos($content, $search) !== false) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($path, $content);
    echo "PATCHED: found and replaced\n";
} else {
    echo "FAILED: search string not found\n";
    // Debug: find the while loop area
    $pos = strpos($content, 'while ($ancestor');
    if ($pos !== false) {
        echo "while found at $pos\n";
        echo substr($content, $pos, 400) . "\n";
    }
}
