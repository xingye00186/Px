<?php
$f = 'f:/work/Px/framework/Rendering/VNodeRenderer.php';
$c = file_get_contents($f);

// Fix 1: setRenderOffsetX - use paintFlags storage
$c = str_replace(
    "private function setRenderOffsetX(RenderNode \$node, int \$value): void\r\n    {\r\n        \$node->renderOffsetX = \$value;\r\n    }",
    "private function setRenderOffsetX(RenderNode \$node, int \$value): void\r\n    {\r\n        \$oid = spl_object_id(\$node);\r\n        \$this->paintFlags[\$oid]['offX'] = \$value;\r\n    }",
    $c
);

// Fix 2: setRenderOffsetY - use paintFlags storage
$c = str_replace(
    "private function setRenderOffsetY(RenderNode \$node, int \$value): void\r\n    {\r\n        \$node->renderOffsetY = \$value;\r\n    }",
    "private function setRenderOffsetY(RenderNode \$node, int \$value): void\r\n    {\r\n        \$oid = spl_object_id(\$node);\r\n        \$this->paintFlags[\$oid]['offY'] = \$value;\r\n    }",
    $c
);

// Fix 3: getRenderOffsetX - read from paintFlags
$c = str_replace(
    "private function getRenderOffsetX(RenderNode \$node): int\r\n    {\r\n        return (int)(\$node->renderOffsetX ?? 0);\r\n    }",
    "private function getRenderOffsetX(RenderNode \$node): int\r\n    {\r\n        \$oid = spl_object_id(\$node);\r\n        return (int)(\$this->paintFlags[\$oid]['offX'] ?? 0);\r\n    }",
    $c
);

// Fix 4: getRenderOffsetY - read from paintFlags
$c = str_replace(
    "private function getRenderOffsetY(RenderNode \$node): int\r\n    {\r\n        return (int)(\$node->renderOffsetY ?? 0);\r\n    }",
    "private function getRenderOffsetY(RenderNode \$node): int\r\n    {\r\n        \$oid = spl_object_id(\$node);\r\n        return (int)(\$this->paintFlags[\$oid]['offY'] ?? 0);\r\n    }",
    $c
);

// Fix 5-8: Remove all 4 textRenderInfo writes (they are multiline)
// Use substr approach to safely remove each occurrence
$patterns = [
    "\$node->textRenderInfo = [\r\n",
];
foreach ($patterns as $startPattern) {
    $pos = 0;
    while (($pos = strpos($c, $startPattern, $pos)) !== false) {
        // Find the closing ]; after the opening [
        $openBracket = strpos($c, '[', $pos);
        $closeBracket = strpos($c, '];', $openBracket);
        if ($closeBracket !== false) {
            $len = $closeBracket - $pos + 2; // include ];
            $c = substr_replace($c, "/* textRenderInfo stored locally */", $pos, $len);
        } else {
            $pos++;
        }
    }
}

file_put_contents($f, $c);
echo "Fixed\n";
