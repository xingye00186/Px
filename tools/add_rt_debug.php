<?php
// Add debug to trace wrapper node's origin in RenderTreeManager
$path = 'f:/work/Px/framework/Rendering/RenderTreeManager.php';
$content = file_get_contents($path);

// Patch: in updateNodeRecursive, log when creating RenderNode with position:relative
// Find where layoutDirty is set and add a tracker
$search = '$renderNode->layoutDirty = true;';
$replace = '$renderNode->layoutDirty = true;
        // DEBUG: trace elements with position:relative at creation
        if ($vnode->type === \'#root\' || $vnode->type === \'#component\') {
            $posStr = $computedStyle?->position?->value ?? \'none\';
            error_log(\'[RTCREATE] type=\' . $vnode->type . \' position=\' . $posStr . \' style_props=\' . json_encode($vnode->props));
        }
        // DEBUG: also trace first render after expansion
        if (isset($renderNode->dataset[\'pxAnchor\']) && $renderNode->parent !== null) {
            error_log(\'[RTANCHOR] created anchor parent.type=\' . $renderNode->parent->type . \' parent.x=\' . $renderNode->parent->x . \' parent.pos=\' . ($renderNode->parent->computedStyle?->position?->value ?? \'?\'));
        }';

$count = substr_count($content, $search);
if ($count === 1) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($path, $content);
    echo "PATCHED RenderTreeManager ($count match)\n";
} else {
    echo "FAILED: found $count matches\n";
}

// Also patch #root creation in the expand path
$search2 = 'error_log(\'[DIAG] expandComponentTree res=\' . ($childRN !== null ? \'OK\' : \'NULL\'));';
$replace2 = '$anchorDS = $childRN?->dataset ?? []; 
        if (isset($anchorDS[\'pxAnchor\'])) {
            error_log(\'[RTEXPAND] anchor child from expansion, parent=\' . ($parent?->type ?? \'null\') . \' parent.x=\' . ($parent?->x ?? -1));
        }
        error_log(\'[DIAG] expandComponentTree res=\' . ($childRN !== null ? \'OK\' : \'NULL\'));';

if (strpos($content, $search2) !== false) {
    $content = str_replace($search2, $replace2, $content);
    file_put_contents($path, $content);
    echo "PATCHED expandComponentTree\n";
} else {
    echo "expandComponentTree: pattern not found\n";
}
