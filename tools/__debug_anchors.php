<?php
$exeDir = 'f:/work/Px/apps/music-player/test_log';
$exeFiles = glob($exeDir . '/*.png');
$captured = $exeFiles[0] ?? '';

$cImg = @imagecreatefrompng($captured);
if (!$cImg) { echo "无法加载\n"; exit(1); }

$w = imagesx($cImg); $h = imagesy($cImg);
echo "EXE 截图: {$w}x{$h}\n\n";

// 扫描所有接近青色 (0,255,255) 的像素
echo "全部青色像素扫描:\n";
$cyans = [];
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($cImg, $x, $y);
        $pr = ($c >> 16) & 0xFF;
        $pg = ($c >> 8) & 0xFF;
        $pb = $c & 0xFF;
        if (abs($pr - 0) <= 10 && abs($pg - 255) <= 10 && abs($pb - 255) <= 10) {
            // 记录首次出现的青色区域
            $key = round($x/10)*10 . ',' . round($y/10)*10;
            if (!isset($cyans[$key])) $cyans[$key] = ['x'=>$x,'y'=>$y,'r'=>$pr,'g'=>$pg,'b'=>$pb];
        }
    }
}
printf("找到 %d 个青色像素区域\n\n", count($cyans));
$sorted = array_values($cyans);
// 按y排序，前10个
usort($sorted, function($a,$b){return $a['y']-$b['y'];});
echo "青色区域 (按y排序, 前15):\n";
foreach (array_slice($sorted, 0, 15) as $s) {
    printf("  (%4d, %4d) RGB=(%d,%d,%d)\n", $s['x'],$s['y'],$s['r'],$s['g'],$s['b']);
}

// 检测TL锚点
$cTL = null;
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($cImg, $x, $y);
        $pr = ($c >> 16) & 0xFF; $pg = ($c >> 8) & 0xFF; $pb = $c & 0xFF;
        if (abs($pr - 255) <= 10 && abs($pg - 0) <= 10 && abs($pb - 255) <= 10) {
            $cTL = ['x'=>$x,'y'=>$y]; break 2;
        }
    }
}
if ($cTL) printf("\nTL 锚点 (第一个洋红): (%d,%d)\n", $cTL['x'], $cTL['y']);

imagedestroy($cImg);
