<?php
$fw = 'f:/work/Px/framework';
$files = [
    'Layout/BlockAlgorithm.php' => ['use Px\Render\RenderNode;', 'use Px\Css\ComputedStyle;'],
    'Layout/ConstraintSpace.php' => ['use Px\Render\RenderNode;', 'use Px\Css\ComputedStyle;'],
    'Layout/LayoutCacheKey.php' => ['use Px\Render\RenderNode;'],
    'Render/RenderNode.php' => ['use Px\Dom\VNode;'],
    'Render/RenderTreeManager.php' => ['use Px\Dom\VNode;'],
];

foreach ($files as $rel => $uses) {
    $fp = "$fw/$rel";
    $c = file_get_contents($fp);
    $orig = $c;
    foreach ($uses as $use) {
        if (strpos($c, $use) !== false) continue;
        $c = preg_replace('/^(namespace Px\\\\[^;]+;)(\r?\n)/', '$1$2' . $use . '$2', $c, 1);
    }
    if ($c !== $orig) {
        file_put_contents($fp, $c);
        echo "FIXED: $rel\n";
    }
}
echo "done\n";
