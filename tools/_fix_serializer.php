<?php
$f = 'f:/work/Px/framework/Rendering/RenderNodeSerializer.php';
$c = file_get_contents($f);

// Fix treeToText style debug outputs
$c = str_replace(
    '$coords = "(' . "\$node->x" . ',' . "\$node->y" . ') ' . "\$node->w" . 'x' . "\$node->h" . '";',
    '$coords = "(" . ($node->x ?? 0) . "," . ($node->y ?? 0) . ") " . ($node->w ?? 0) . "x" . ($node->h ?? 0) . ' . '";',
    $c
);

$c = str_replace(
    '$lines[] = "' . '{$indent}[{$node->type}] {$coords} layer={$node->layer}";',
    '$lines[] = "{$indent}[{$node->type}] {$coords} layer=" . ($node->layer ?? 0);',
    $c
);

$c = str_replace(
    '$lines[] = "' . '{$indent}  scroll: top={$node->scrollTop}, contentH={$node->contentHeight}";',
    '$lines[] = "{$indent}  scroll: top=" . ($node->scrollTop ?? 0) . ", contentH=" . ($node->contentHeight ?? 0);',
    $c
);

file_put_contents($f, $c);
echo "Fixed\n";
