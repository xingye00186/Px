<?php
$files = glob(__DIR__ . '/../framework/Rendering/Layout/*LayoutStrategy.php');
$v = [];
foreach ($files as $f) {
    foreach (file($f) as $i => $l) {
        $t = trim($l);
        if (preg_match(chr(47) . chr(92) . chr(36) . chr(99) . chr(104) . chr(45) . chr(62) . chr(91) . chr(120) . chr(121) . chr(119) . chr(104) . chr(93) . chr(32) . chr(61) . chr(32) . chr(47), $t)
            && strpos($t, chr(36) . chr(103) . chr(114) . chr(105) . chr(100)) === false
            && strpos($t, chr(36) . chr(110) . chr(111) . chr(100) . chr(101)) === false) {
            $v[] = basename($f) . chr(58) . ($i + 1) . chr(32) . $t;
        }
    }
}
$c = count($v);
echo $c . chr(32) . chr(118) . chr(105) . chr(111) . chr(108) . chr(97) . chr(116) . chr(105) . chr(111) . chr(110) . chr(115) . chr(10);
foreach ($v as $x) echo $x . chr(10);
