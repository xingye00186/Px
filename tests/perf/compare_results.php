<?php
/**
 * Px Framework — 脏位分离收益对比工具
 *
 * 对比 baseline 和 optimized 两轮基准测试结果，输出结构化差异报告。
 *
 * 用法:
 *   php tests/perf/compare_results.php <baseline.json> [optimized.json]
 *
 * 如果只传 baseline.json，自动从 results 目录查找对应的 optimized 文件。
 */

$baselinePath = $argv[1] ?? '';
if ($baselinePath === '') {
    echo "用法: php tests/perf/compare_results.php <baseline.json> [optimized.json]\n";
    exit(1);
}

if (!file_exists($baselinePath)) {
    echo "错误: 找不到 baseline 文件: {$baselinePath}\n";
    exit(1);
}

$optimizedPath = $argv[2] ?? '';
if ($optimizedPath === '') {
    // 自动查找同名 optimized 文件
    $dir = dirname($baselinePath);
    $base = basename($baselinePath);
    $optimizedPath = $dir . '/' . str_replace('baseline_', 'optimized_', $base);
    if (!file_exists($optimizedPath)) {
        // 找最新的 optimized 文件
        $files = glob($dir . '/optimized_*.json');
        if (!empty($files)) {
            $optimizedPath = $files[count($files) - 1];
        }
    }
}

if (!file_exists($optimizedPath)) {
    echo "警告: 找不到 optimized 文件，只输出 baseline 数据\n";
    $optimizedPath = '';
}

// ── 加载数据 ─────────────────────────────────────────
$baseline = json_decode(file_get_contents($baselinePath), true);
$optimized = $optimizedPath ? json_decode(file_get_contents($optimizedPath), true) : null;

if ($baseline === null) {
    echo "错误: baseline JSON 解析失败\n";
    exit(1);
}

// ── 场景名称映射 ─────────────────────────────────────
$scenarioNames = [
    'A_color' => '颜色变化',
    'B_size'  => '尺寸变化',
    'C_hover' => '悬停切换',
    'D_text'  => '文本内容',
];

// ── 管线阶段显示顺序 ─────────────────────────────────
$stageOrder = ['stage:full_render', 'stage:layout', 'stage:render', 'stage:tree_convert'];

// ── 输出报告 ─────────────────────────────────────────
echo "========================================\n";
echo " 脏位分离收益对比报告\n";
echo "========================================\n\n";

echo "基线文件  : {$baselinePath}\n";
if ($optimizedPath) {
    echo "优化后文件: {$optimizedPath}\n";
}
$metaB = $baseline['meta'] ?? [];
$metaO = $optimized['meta'] ?? [];
echo "帧数     : " . ($metaB['frames'] ?? '?') . "\n";
echo "PHP版本  : " . ($metaB['php_version'] ?? '?') . "\n\n";

// 总耗时对比
if ($metaB && $metaO) {
    $totalB = round($metaB['total_sec'] ?? 0, 4);
    $totalO = round($metaO['total_sec'] ?? 0, 4);
    if ($totalB > 0) {
        $change = round(($totalO - $totalB) / $totalB * 100, 1);
        $arrow = $change < 0 ? '▼' : ($change > 0 ? '▲' : '—');
        echo "总耗时: baseline={$totalB}s  optimized={$totalO}s  ({$arrow}{$change}%)\n\n";
    }
}

$scenarios = $baseline['scenarios'] ?? [];
$optScenarios = $optimized ? ($optimized['scenarios'] ?? []) : [];

foreach ($scenarioNames as $key => $label) {
    $baseData = $scenarios[$key] ?? [];
    $optData = $optScenarios[$key] ?? [];

    if (empty($baseData)) continue;

    echo str_repeat('-', 70) . "\n";
    echo " 场景 {$key}: {$label}\n";
    echo str_repeat('-', 70) . "\n";
    printf("  %-28s | %-12s | %-12s | %-10s\n", 'Counter', 'Before(μs)', 'After(μs)', 'Change');
    echo str_repeat('-', 70) . "\n";

    // 收集所有阶段
    $allStages = $stageOrder;
    foreach ($baseData as $name => $d) {
        if (!in_array($name, $allStages)) $allStages[] = $name;
    }
    foreach ($optData as $name => $d) {
        if (!in_array($name, $allStages)) $allStages[] = $name;
    }

    $cols = ['counter', 'before_total', 'after_total', 'change_pct', 'arrow'];
    $rows = [];

    foreach ($allStages as $stage) {
        $bd = $baseData[$stage] ?? null;
        $od = $optData[$stage] ?? null;

        $beforeVal = $bd ? round($bd['total'] ?? 0, 2) : 0;
        $afterVal  = $od ? round($od['total'] ?? 0, 2) : 0;
        $pct = '';
        $arrow = '—';

        if ($beforeVal > 0 && $afterVal > 0) {
            $pct = round(($afterVal - $beforeVal) / $beforeVal * 100, 1) . '%';
            $arrow = $afterVal < $beforeVal ? '▼' : ($afterVal > $beforeVal ? '▲' : '—');
        } elseif ($beforeVal > 0 && $afterVal === 0.0) {
            $pct = '-100.0%';
            $arrow = '▼▼';
        } elseif ($afterVal > 0 && $beforeVal === 0.0) {
            $pct = 'NEW';
            $arrow = '◆';
        }

        printf("  %-28s | %-12s | %-12s | %-10s\n",
            $stage,
            $bd ? (string)$beforeVal . 'μs' : '-',
            $od ? (string)$afterVal . 'μs' : '-',
            $arrow . $pct
        );
    }
    echo "\n";
}

// ── 汇总表 ────────────────────────────────────────────
echo str_repeat('=', 70) . "\n";
echo " 汇总（stage:layout + stage:render 合计）\n";
echo str_repeat('=', 70) . "\n";
printf("  %-15s | %-12s | %-12s | %-10s | %-10s\n", 'Scenario', 'Before(μs)', 'After(μs)', 'Change', 'LayoutΔ');
echo str_repeat('-', 70) . "\n";

foreach ($scenarioNames as $key => $label) {
    $baseData = $scenarios[$key] ?? [];
    $optData = $optScenarios[$key] ?? [];

    $bLayout = (float)($baseData['stage:layout']['total'] ?? 0);
    $oLayout = (float)($optData['stage:layout']['total'] ?? 0);
    $bRender = (float)($baseData['stage:render']['total'] ?? 0);
    $oRender = (float)($optData['stage:render']['total'] ?? 0);
    $bTotal = $bLayout + $bRender;
    $oTotal = $oLayout + $oRender;

    $totalPct = $bTotal > 0 ? round(($oTotal - $bTotal) / $bTotal * 100, 1) : 0;
    $layoutPct = $bLayout > 0 ? round(($oLayout - $bLayout) / $bLayout * 100, 1) : 0;
    $arrow = $oTotal < $bTotal ? '▼' : ($oTotal > $bTotal ? '▲' : '—');

    printf("  %-15s | %-12s | %-12s | %-10s | %-10s\n",
        $key,
        $bTotal > 0 ? (string)$bTotal . 'μs' : '-',
        $oTotal > 0 ? (string)$oTotal . 'μs' : '-',
        $arrow . $totalPct . '%',
        $layoutPct . '%'
    );
}
echo str_repeat('=', 70) . "\n";
echo "\n";
