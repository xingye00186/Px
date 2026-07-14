<?php
$c = file_get_contents('f:/work/Px/framework/Css/ComputedStyle.php');

// Remove box-sizing check from visualWidth and visualHeight
$c = preg_replace(
    '/\n\s+\$sizing = \$this->boxSizing->value;\n\s+if \(\$sizing === \'border-box\'\) \{\n\s+return max\(0, \$content[WH]\);\n\s+\}\n/',
    "\n",
    $c
);

// Also remove declaration and check from visualHeight (separate pass for each)
$c = preg_replace(
    '/(visualHeight.*\n\s+\{)\n\s+\$sizing.+\n\s+if.+border-box.+\n\s+return max.+;\n\s+\}\n/',
    '$1',
    $c
);

file_put_contents('f:/work/Px/framework/Css/ComputedStyle.php', $c);
echo "Fixed\n";
