<?php
$fw = 'f:/work/Px/framework';
$files = [
    'Render/RenderTreeManager.php', 'Render/RenderNode.php',
    'Layout/ConstraintSpace.php', 'Layout/BlockAlgorithm.php',
    'Css/CssMappings.php', 'Layout/LayoutCacheKey.php',
    'Core/ScrollManager.php', 'Layout/TextOverflowProcessor.php'
];
foreach ($files as $rel) {
    $fp = "$fw/$rel";
    $c = file_get_contents($fp);
    if (strpos($c, 'use Px\\Dom\\VNode;') !== false) continue;
    if (strpos($c, 'VNode') === false) continue;
    $c = preg_replace('/^(namespace Px\\\\[^;]+;)(\r?\n)/', '$1$2use Px\\Dom\\VNode;$2', $c, 1);
    file_put_contents($fp, $c);
    echo "FIXED: $rel\n";
}
echo "done\n";
