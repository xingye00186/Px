<?php
// Add debug logging to trace anchor positioning root cause
$absPath = 'f:/work/Px/framework/Rendering/Layout/AbsolutePositioning.php';
$absContent = file_get_contents($absPath);

// Patch 1: resolvePositioningAncestor - log FULL parent chain
$search1 = 'private function resolvePositioningAncestor(RenderNode $node): void
    {
        if ($node->positioningAncestorValid) return;

        $ancestor = $node->parent;
        while ($ancestor !== null) {
            $pos = $ancestor->computedStyle?->position?->value ?? \'static\';
            if ($pos !== \'static\') {
                $node->positioningAncestor = $ancestor;
                $node->positioningAncestorValid = true;
                return;
            }
            $ancestor = $ancestor->parent;
        }
        $node->positioningAncestor = null;
        $node->positioningAncestorValid = true;
    }';

$replace1 = 'private function resolvePositioningAncestor(RenderNode $node): void
    {
        if ($node->positioningAncestorValid) {
            error_log(\'[POS_ANC] CACHED anc=\' . ($node->positioningAncestor?->type ?? \'null\') . \' x=\' . ($node->positioningAncestor?->x ?? -1));
            return;
        }

        $chainStr = \'anchor.type=\' . $node->type . \' parent=\' . ($node->parent?->type ?? \'null\') . \'@(\' . ($node->parent?->x ?? -1) . \',\' . ($node->parent?->y ?? -1) . \')\';
        $ancestor = $node->parent;
        $depth = 0;
        while ($ancestor !== null) {
            $pos = $ancestor->computedStyle?->position?->value ?? \'static\';
            $chainStr .= \' -> d=\' . $depth . \' \' . $ancestor->type . \'@(\' . $ancestor->x . \',\' . $ancestor->y . \')pos=\' . $pos;
            if ($pos !== \'static\') {
                $node->positioningAncestor = $ancestor;
                $node->positioningAncestorValid = true;
                error_log(\'[POS_ANC] FOUND depth=\' . $depth . \' \' . $ancestor->type . \' x=\' . $ancestor->x . \' y=\' . $ancestor->y . \' pos=\' . $pos . \' w=\' . $ancestor->w . \' h=\' . $ancestor->h . \' | \' . $chainStr);
                return;
            }
            $ancestor = $ancestor->parent;
            $depth++;
        }
        $node->positioningAncestor = null;
        $node->positioningAncestorValid = true;
        error_log(\'[POS_ANC] NOT FOUND (no positioned ancestor) | \' . $chainStr);
    }';

if (strpos($absContent, $search1) !== false) {
    $absContent = str_replace($search1, $replace1, $absContent);
    file_put_contents($absPath, $absContent);
    echo "ABSOLUTE POSITIONING: patched\n";
} else {
    echo "ABSOLUTE POSITIONING: search FAILED\n";
}

// Patch 2: LayoutResolver - log when setting position from constraints
$resPath = 'f:/work/Px/framework/Rendering/LayoutResolver.php';
$resContent = file_get_contents($resPath);

$search2 = 'if ($positionValue === \'static\' || $positionValue === \'relative\') {
            $node->x = $constraints->parentContentX + ($style?->left ?? 0);
            $node->y = $constraints->parentContentY + ($style?->top ?? 0);
        }';

$replace2 = 'if ($positionValue === \'static\' || $positionValue === \'relative\') {
            $oldX = $node->x;
            $newX = $constraints->parentContentX + ($style?->left ?? 0);
            $node->x = $newX;
            $node->y = $constraints->parentContentY + ($style?->top ?? 0);
            if ($oldX != $newX && $newX > 0) {
                error_log(\'[POS_PRE] type=\' . $node->type . \' oldX=\' . $oldX . \' newX=\' . $newX . \' parentContentX=\' . $constraints->parentContentX);
            }
        }';

if (strpos($resContent, $search2) !== false) {
    $resContent = str_replace($search2, $replace2, $resContent);
    file_put_contents($resPath, $resContent);
    echo "LAYOUT RESOLVER: patched\n";
} else {
    echo "LAYOUT RESOLVER: search FAILED\n";
}
