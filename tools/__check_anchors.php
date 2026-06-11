<?php
require_once 'f:/work/Px/tools/shared_test_lib.php';

$baseline = 'f:/work/Px/apps/music-player/base_line_pic.png';
$appDir = 'f:/work/Px/apps/music-player';

$exeFiles = glob($appDir . '/test_log/*.png');
$captured = $exeFiles[0] ?? '';

if (!$captured || !file_exists($captured)) {
    echo "未找到 exe 截图\n"; exit(1);
}

echo "基线截图: $baseline\n";
echo "EXE 截图: $captured\n\n";

$bImg = @imagecreatefrompng($baseline);
$cImg = @imagecreatefrompng($captured);
if (!$bImg || !$cImg) { echo "无法加载图片\n"; exit(1); }

printf("基线截图尺寸: %d x %d\n", imagesx($bImg), imagesy($bImg));
printf("EXE 截图尺寸: %d x %d\n\n", imagesx($cImg), imagesy($cImg));

$bTL = findColorAnchor($bImg, 255, 0, 255);
$bBR = findColorAnchor($bImg, 0, 255, 255);
$cTL = findColorAnchor($cImg, 255, 0, 255);
$cBR = findColorAnchor($cImg, 0, 255, 255);

echo "=== 基线截图 (baseline.html 14px新基线) ===\n";
printf("TL: (%3d, %3d)   BR: (%3d, %3d)\n", $bTL['x'], $bTL['y'], $bBR['x'], $bBR['y']);
$bw = $bBR['x'] + 8 - $bTL['x'];
$bh = $bBR['y'] + 8 - $bTL['y'];
printf("TL→BR 跨度: %d × %d  宽高比=%.3f\n", $bw, $bh, $bw/$bh);

echo "\n=== EXE 截图 (引擎) ===\n";
printf("TL: (%3d, %3d)   BR: (%3d, %3d)\n", $cTL['x'], $cTL['y'], $cBR['x'], $cBR['y']);
$cw = $cBR['x'] + 8 - $cTL['x'];
$ch = $cBR['y'] + 8 - $cTL['y'];
printf("TL→BR 跨度: %d × %d  宽高比=%.3f\n", $cw, $ch, $cw/$ch);

echo "\n=== 差异 ===\n";
printf("宽度差: %d px (基线=%d, EXE=%d)\n", $bw - $cw, $bw, $cw);
printf("高度差: %d px (基线=%d, EXE=%d)\n", $bh - $ch, $bh, $ch);
printf("宽高比: 基线=%.3f  EXE=%.3f\n", $bw/$bh, $cw/$ch);

imagedestroy($bImg);
imagedestroy($cImg);
