<?php
$files = glob(__DIR__ . '/../framework/Rendering/Layout/*LayoutStrategy.php');
$skip = ['Block', 'Inline', 'MultiColumn'];
$v = [];
foreach ($files as $f) {
    $bn = basename($f);
    $ok = false; foreach ($skip as $s) { if (strpos($bn, $s) !== false) $ok = true; }
    if ($ok) continue;
    foreach (file($f) as $i => $l) {
        $t = trim($l);
        if ($t === '' || $t[0] === '/') continue;
        $pat = '/' . chr(36) . 'ch->[xywh] =';
        if (preg_match($pat, $t) && strpos($t, chr(36) . chr(103) . chr(114) . chr(105) . chr(100)) === false)
            $v[] = $bn . ':' . ($i + 1) .   . $t;
    }
}
echo count($v) . ' violations in Flex/Grid/Table:';
foreach ($v as $x) echo "
  " . $x;
