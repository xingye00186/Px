<?php
$f = $argv[1];
$c = file_get_contents($f);
// Fix the corrupted import line
$c = str_replace(
    "MultiColumnLayoutStrategy;`r`nuse Px\\Rendering\\Layout\\TableAlgorithm;",
    "MultiColumnLayoutStrategy;\nuse Px\\Rendering\\Layout\\TableAlgorithm;",
    $c
);
// Fix the constructor usage
$c = str_replace(
    '$this->tableStrategy = new \Px\Rendering\Layout\TableAlgorithm()',
    '$this->tableStrategy = new \Px\Rendering\Layout\TableAlgorithm()',
    $c
);
file_put_contents($f, $c);
echo "Fixed\n";
