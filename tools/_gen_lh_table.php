<?php
/**
 * Create line-height normal mapping for Segoe UI font.
 * Values measured from Microsoft Edge on Windows 25H2.
 * 
 * Format: "fontSize_bold" => lineHeight_px
 * Bold: 0 = normal (400), 1 = bold (600/700)
 */
$lhMap = [
    // font-size 11 (sidebar case count): normal
    '11_0' => 15,
    // font-size 12: normal
    '12_0' => 16,
    // font-size 13 (sidebar items): normal
    '13_0' => 18,
    // font-size 14 (generic text): normal
    '14_0' => 19,
    // font-size 15 (content header title): normal
    '15_0' => 20,
    // font-size 16 (default body): normal
    '16_0' => 22,
    // font-size 18 (case titles): normal
    '18_0' => 24,
    // font-size 18 (case titles): bold
    '18_1' => 24,
    // font-size 20 (stats/value text): bold
    '20_0' => 27,
    '20_1' => 27,
    // font-size 24 (large text): normal
    '24_0' => 32,
];
// Also store the average ratio for fallback
$lhMap['_ratio'] = 1.35;

$json = json_encode($lhMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents('f:/work/Px/tools/PxTest/GoldenMeasure/line_height_normal.json', $json);
echo "Generated: line_height_normal.json\n";
echo $json . "\n";
