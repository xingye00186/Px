<?php
$f = $argv[1];
$c = file_get_contents($f);
// Fix the corrupted import line
$c = str_replace(
    "use Px\\Rendering\\Layout\\BlockAlgorithm;`r`nuse Px\\Rendering\\Layout\\FlexAlgorithm;`r`nuse Px\\Rendering\\Layout\\GridAlgorithm;`r`nuse Px\\Rendering\\Layout\\InlineAlgorithm;`r`nuse Px\\Rendering\\Layout\\AbsoluteStrategy;",
    "use Px\\Rendering\\Layout\\BlockAlgorithm;\nuse Px\\Rendering\\Layout\\FlexAlgorithm;\nuse Px\\Rendering\\Layout\\GridAlgorithm;\nuse Px\\Rendering\\Layout\\InlineAlgorithm;\nuse Px\\Rendering\\Layout\\AbsoluteStrategy;",
    $c
);
file_put_contents($f, $c);
echo "Fixed\n";
