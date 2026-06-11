<?php
$e = json_decode(file_get_contents('f:/work/Px/apps/music-player/engine_layout.json'), true);
echo "引擎卡牌 children:\n";
foreach ($e['children'][0]['children'] as $c) {
    $pos = $c['style']['position'] ?? 'static';
    if ($pos === 'absolute') {
        printf("  xywh=(%d,%d,%d,%d) content='%s'\n",
            $c['x'], $c['y'], $c['w'], $c['h'], $c['content']??'');
    }
    // Also check first and last child
}
$ch = $e['children'][0]['children'];
$first = $ch[0];
$last = $ch[count($ch)-1];
printf("First child: xywh=(%d,%d,%d,%d) pos=%s\n", $first['x'],$first['y'],$first['w'],$first['h'],$first['style']['position']??'');
printf("Last child:  xywh=(%d,%d,%d,%d) pos=%s\n", $last['x'],$last['y'],$last['w'],$last['h'],$last['style']['position']??'');

echo "\n浏览器 ref 顶层元素:\n";
$b = json_decode(file_get_contents('f:/work/Px/apps/music-player/ref/browser_ref_level_0.json'), true);
foreach ($b['elements'] as $el) {
    printf("  xywh=(%d,%d,%d,%d) text='%s'\n", $el['x'],$el['y'],$el['w'],$el['h'],$el['text']??'');
    if (!empty($el['children'])) {
        foreach ($el['children'] as $c2) {
            $t = $c2['text']??'';
            if (str_contains($t, '__PX_ANCHOR')) {
                printf("    anchor xywh=(%d,%d,%d,%d) text='%s'\n", $c2['x'],$c2['y'],$c2['w'],$c2['h'],$t);
            }
        }
        // Also check deeper children for anchors
        foreach ($el['children'] as $c2) {
            if (!empty($c2['children'])) {
                foreach ($c2['children'] as $c3) {
                    $t = $c3['text']??'';
                    if (str_contains($t, '__PX_ANCHOR')) {
                        printf("    anchor xywh=(%d,%d,%d,%d) text='%s'\n", $c3['x'],$c3['y'],$c3['w'],$c3['h'],$t);
                    }
                }
            }
        }
    }
}
