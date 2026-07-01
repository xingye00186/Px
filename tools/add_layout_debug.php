<?php
// Log each node's type and position during layout processing
$path = 'f:/work/Px/framework/Rendering/LayoutResolver.php';
$content = file_get_contents($path);

// Add a log right at the start of resolveNodeInternal (after dirty check)
$search = '// ── 创建 FragmentBuilder ──';
$replace = '// ── LOG each node for chain tracing ──
        if ($display !== \'none\') {
            $posStyle = $style?->position?->value ?? \'static\';
            error_log(\'[LAYOUT] type=\' . $node->type . \' display=\' . $display . \' position=\' . $posStyle . \' x=\' . $node->x . \' y=\' . $node->y . \' w=\' . $node->w . \' h=\' . $node->h . \' dirty=\' . ($node->layoutDirty ? \'1\' : \'0\') . \' pContentX=\' . $constraints->parentContentX . \' parent=\' . ($node->parent?->type ?? \'null\') . \'@(\' . ($node->parent?->x ?? -1) . \',\' . ($node->parent?->y ?? -1) . \')\');
        }
        
        // ── 创建 FragmentBuilder ──';

$count = substr_count($content, $search);
if ($count === 1) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($path, $content);
    echo "PATCHED LayoutResolver ($count match)\n";
} else {
    echo "FAILED: found $count matches\n";
}
