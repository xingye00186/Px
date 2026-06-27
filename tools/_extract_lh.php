<?php
/**
 * 从所有 browser_ref_level_0.json 中提取 line-height:normal 的精确像素值。
 * 建立 font-size → line-height 映射表，供引擎直接使用。
 */
$dir = 'f:/work/Px/apps/php-rt-test/test_case';
$lhMap = []; // key = "fontSize|bold" => [lineHeight => count]

foreach (glob("$dir/prt-*/ref/browser_ref_level_0.json") as $f) {
    $data = json_decode(file_get_contents($f), true);
    if (!$data || !isset($data['elements'])) continue;
    foreach ($data['elements'] as $el) {
        $styles = $el['styles'] ?? [];
        $lh = $styles['line-height'] ?? '';
        $fs = $styles['font-size'] ?? '';
        $fw = $styles['font-weight'] ?? '';
        
        // Only collect elements where line-height === 'normal' would apply
        // The browser computes line-height to a px value even when CSS says 'normal'
        if (str_ends_with($lh, 'px') && str_ends_with($fs, 'px')) {
            $lhPx = (int)substr($lh, 0, -2);
            $fsPx = (int)substr($fs, 0, -2);
            $bold = $fw === '700' || $fw === 'bold' || (is_numeric($fw) && (int)$fw >= 600) ? 1 : 0;
            
            if ($fsPx < 8 || $fsPx > 72) continue; // sanity check
            if ($lhPx < 8 || $lhPx > 200) continue;
            
            $key = "$fsPx|$bold";
            if (!isset($lhMap[$key])) {
                $lhMap[$key] = ['count' => 0, 'sum' => 0, 'min' => $lhPx, 'max' => $lhPx, 'first' => $lhPx];
            }
            $lhMap[$key]['count']++;
            $lhMap[$key]['sum'] += $lhPx;
            if ($lhPx < $lhMap[$key]['min']) $lhMap[$key]['min'] = $lhPx;
            if ($lhPx > $lhMap[$key]['max']) $lhMap[$key]['max'] = $lhPx;
        }
    }
}

// Output the mapping table
echo "=== Line-Height Normal Mapping Table ===\n";
echo "Format: fontSize|bold => avg, min, max, count\n";

// Sort by fontSize
ksort($lhMap, SORT_NATURAL);
$ratios = [];
foreach ($lhMap as $key => $data) {
    $avg = (int)round($data['sum'] / $data['count']);
    list($fs, $bold) = explode('|', $key);
    $ratio = round($avg / $fs, 4);
    $ratios[] = $ratio;
    echo "  $key => {$avg}px (ratio={$ratio}, min={$data['min']}, max={$data['max']}, n={$data['count']})\n";
}

if (!empty($ratios)) {
    $avgRatio = round(array_sum($ratios) / count($ratios), 4);
    echo "\nAverage ratio: $avgRatio\n";
    echo "Recommended fallback ratio: " . round($avgRatio, 2) . "\n";
}
