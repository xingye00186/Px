<?php
require __DIR__ . "/shared_test_lib.php";
$base = imagecreatefrompng(__DIR__ . "/../apps/music-player/base_line_pic.png");
$cap = imagecreatefrompng(__DIR__ . "/../apps/music-player/test_log/screenshot_captured.png");
if (!$base || !$cap) { echo "FAIL\n"; exit(1); }
$bTL = findColorAnchor($base, 255, 0, 255);
$bBR = findColorAnchor($base, 0, 255, 255);
$cTL = findColorAnchor($cap, 255, 0, 255);
$cBR = findColorAnchor($cap, 0, 255, 255);
echo "BASE TL=" . $bTL["x"] . "," . $bTL["y"] . " BR=" . $bBR["x"] . "," . $bBR["y"] . "\n";
echo "EXE  TL=" . $cTL["x"] . "," . $cTL["y"] . " BR=" . $cBR["x"] . "," . $cBR["y"] . "\n";
echo "BASE wh=" . ($bBR["x"]+8-$bTL["x"]) . "x" . ($bBR["y"]+8-$bTL["y"]) . "\n";
echo "EXE  wh=" . ($cBR["x"]+8-$cTL["x"]) . "x" . ($cBR["y"]+8-$cTL["y"]) . "\n";
echo "TL dx=" . ($bTL["x"]-$cTL["x"]) . " dy=" . ($bTL["y"]-$cTL["y"]) . "\n";
echo "BR dx=" . ($bBR["x"]-$cBR["x"]) . " dy=" . ($bBR["y"]-$cBR["y"]) . "\n";
