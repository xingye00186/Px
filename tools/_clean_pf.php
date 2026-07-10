<?php
$f = 'f:/work/Px/framework/Rendering/Layout/PhysicalFragment.php';
$c = file_get_contents($f);
$c = str_replace("use Px\Rendering\Layout\LayoutResult;\n", "", $c);

// Remove buildFromLayoutResult method
$methodStart = strpos($c, 'public static function buildFromLayoutResult');
if ($methodStart === false) { echo "method not found\n"; exit(0); }

$braceDepth = 0; $endPos = 0; $inMethod = false;
for ($i = $methodStart; $i < strlen($c); $i++) {
    if ($c[$i] === '{') { $braceDepth++; $inMethod = true; }
    if ($c[$i] === '}') { $braceDepth--; }
    if ($inMethod && $braceDepth === 0) { $endPos = $i + 1; break; }
}
if ($endPos > 0) {
    $before = substr($c, 0, $methodStart);
    $after = substr($c, $endPos);
    $after = ltrim($after, "\r\n");
    $c = $before . $after;
}
file_put_contents($f, $c);
echo "Removed buildFromLayoutResult from PhysicalFragment\n";
