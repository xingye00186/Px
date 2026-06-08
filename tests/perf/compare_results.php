<?php
/**
 * Px Framework — 性能测试对比分析脚本
 *
 * 扫描 tests/perf/results/ 下所有 *.json，
 * 按 baseline_ vs optimized_ 分组，输出表格化对比报告。
 *
 * Usage:
 *   php tests/perf/compare_results.php
 */

$resultsDir = __DIR__ . '/results';

if (!is_dir($resultsDir)) {
    echo "[ERROR] Results directory not found: {$resultsDir}\n";
    exit(1);
}

$files = glob($resultsDir . '/*.json');
if (empty($files)) {
    echo "[ERROR] No result files found in {$resultsDir}\n";
    exit(1);
}

// 分组
$baselines = [];
$optimized = [];

foreach ($files as $f) {
    $data = json_decode(file_get_contents($f), true);
    if ($data === null) {
        echo "[WARN] Invalid JSON: {$f}\n";
        continue;
    }
    $meta = $data['meta'] ?? [];
    $type = $meta['type'] ?? 'unknown';
    $timestamp = $meta['timestamp'] ?? basename($f);

    if ($type === 'baseline') {
        $baselines[] = ['file' => basename($f), 'data' => $data, 'ts' => $timestamp];
    } elseif ($type === 'optimized') {
        $optimized[] = ['file' => basename($f), 'data' => $data, 'ts' => $timestamp];
    }
}

// 排序 - 取最新的
usort($baselines, fn($a, $b) => strcmp($b['ts'], $a['ts']));
usort($optimized, fn($a, $b) => strcmp($b['ts'], $a['ts']));

$baseline = $baselines[0] ?? null;
$optimizedData = $optimized[0] ?? null;

echo "========================================\n";
echo " Px Framework — 性能测试对比报告\n";
echo "========================================\n\n";

if ($baseline) {
    echo "基准线: {$baseline['file']} ({$baseline['ts']})\n";
} else {
    echo "基准线: (无)\n";
}

if ($optimizedData) {
    echo "优化后: {$optimizedData['file']} ({$optimizedData['ts']})\n";
} else {
    echo "优化后: (无)\n";
}
echo "\n";

// 定义需要比较的测试键
$testKeys = [
    'cold_start'         => '冷启动渲染',
    'digit_spam'         => '数字连击',
    'operator_chain'     => '运算链压力',
    'history_stress'     => '历史列表压力',
    'continuous_render'  => '连续渲染压力',
    'memory_stability'   => '内存稳定性',
];

if (!$baseline) {
    echo "[WARN] 没有基准数据可对比。\n";
    echo "可用文件:\n";
    foreach ($files as $f) {
        $data = json_decode(file_get_contents($f), true);
        $meta = $data['meta'] ?? [];
        echo "  - " . basename($f) . " (type: " . ($meta['type'] ?? 'unknown') . ")\n";
    }
    exit(0);
}

// 表头
echo str_repeat('-', 100) . "\n";
echo sprintf("| %-22s | %-14s | %-14s | %-14s | %-12s |\n",
    '测试项', '基线(ms)', '优化后(ms)', '差值(ms)', '变化');
echo str_repeat('-', 100) . "\n";

$totalBaseline = 0;
$totalOptimized = 0;

foreach ($testKeys as $key => $label) {
    $bVal = $baseline['data']['tests'][$key]['elapsed_ms'] ?? null;
    $oVal = $optimizedData ? ($optimizedData['data']['tests'][$key]['elapsed_ms'] ?? null) : null;

    if ($bVal === null) continue;

    $totalBaseline += $bVal;
    $bStr = number_format($bVal, 3);

    if ($oVal !== null) {
        $totalOptimized += $oVal;
        $diff = $oVal - $bVal;
        $diffStr = ($diff >= 0 ? '+' : '') . number_format($diff, 3);

        if ($bVal > 0) {
            $pct = ($diff / $bVal) * 100;
            $changeStr = ($pct >= 0 ? '+' : '') . number_format($pct, 1) . '%';
            if ($pct < -5) {
                $changeStr .= ' ↑';  // 性能提升
            } elseif ($pct > 5) {
                $changeStr .= ' ↓';  // 性能下降
            } else {
                $changeStr .= ' ∼';
            }
        } else {
            $changeStr = '—';
        }
        $oStr = number_format($oVal, 3);
    } else {
        $oStr = '—';
        $diffStr = '—';
        $changeStr = '—';
    }

    echo sprintf("| %-22s | %-14s | %-14s | %-14s | %-12s |\n",
        $label, $bStr, $oStr, $diffStr, $changeStr);
}

echo str_repeat('-', 100) . "\n";

// 总计行
$totalBStr = number_format($totalBaseline, 3);
if ($optimizedData) {
    $totalDStr = number_format($totalOptimized, 3);
    $totalDiff = $totalOptimized - $totalBaseline;
    $totalDiffStr = ($totalDiff >= 0 ? '+' : '') . number_format($totalDiff, 3);
    if ($totalBaseline > 0) {
        $totalPct = ($totalDiff / $totalBaseline) * 100;
        $totalChange = ($totalPct >= 0 ? '+' : '') . number_format($totalPct, 1) . '%';
    } else {
        $totalChange = '—';
    }
} else {
    $totalDStr = '—';
    $totalDiffStr = '—';
    $totalChange = '—';
}

echo sprintf("| %-22s | %-14s | %-14s | %-14s | %-12s |\n",
    '合计', $totalBStr, $totalDStr, $totalDiffStr, $totalChange);
echo str_repeat('-', 100) . "\n";

// PerfCounter 对比
if ($baseline['data']['perf_counters'] ?? null) {
    echo "\n--- PerfCounter 内嵌性能数据 ---\n";
    echo "基准线 PerfCounter:\n";
    echo \Px\Core\PerfCounter::formatSnapshot($baseline['data']['perf_counters']);

    if ($optimizedData && ($optimizedData['data']['perf_counters'] ?? null)) {
        echo "\n优化后 PerfCounter:\n";
        echo \Px\Core\PerfCounter::formatSnapshot($optimizedData['data']['perf_counters']);
    }
}

echo "\n\n图例: ↑ 性能提升 (>5%)  ↓ 性能下降 (>5%)  ∼ 无显著变化\n";
echo "========================================\n";
