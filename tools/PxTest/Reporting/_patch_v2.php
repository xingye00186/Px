<?php
/**
 * Completely rebuild SummaryReporter.php with full regression coverage.
 * Fixes 5 gaps: case-level diff, overflow, screenshot, steps, elapsed time.
 */
$in = file_get_contents(__DIR__ . '/SummaryReporter.php');
$out = '';

// ─── 1. Replace detectRegression method ───
$oldDetectStart = 'private function detectRegression(array $current, ?array $previous): array';
$oldDetectEnd = 'private function stepIcon';
$start = strpos($in, $oldDetectStart);
$end = strpos($in, $oldDetectEnd, $start);
if ($start === false || $end === false) die("CANNOT FIND detectRegression\n");

$newDetect = <<<'PHP'
private function detectRegression(array $current, ?array $previous): array
{
    $result = ['regressed' => false, 'reasons' => []];
    if ($previous === null) {
        $result['first_run'] = true;
        return $result;
    }
    $result['first_run'] = false;

    // 1. 通过率下降
    if ($previous['total_cases'] > 0) {
        $prevRate = $previous['passed'] / $previous['total_cases'];
        if ($current['total_cases'] > 0) {
            $curRate = $current['passed'] / $current['total_cases'];
            if ($curRate < $prevRate - 0.01) {
                $result['regressed'] = true;
                $result['reasons'][] = sprintf('通过率下降: %.1f%%->%.1f%%', $prevRate * 100, $curRate * 100);
            }
        }
    }

    // 2. 属性级 diff 趋势
    $prevStats = $previous['property_stats'] ?? [];
    $curStats = $current['property_stats'] ?? [];
    foreach ($curStats as $prop => $curStat) {
        $curDiff = $curStat['diff'] ?? 0;
        $prevDiff = ($prevStats[$prop]['diff'] ?? 0);
        $prevTotal = ($prevStats[$prop]['total'] ?? 0);
        $curTotal = $curStat['total'] ?? 0;
        if ($prevTotal > 0 && $curDiff > $prevDiff && $curTotal >= $prevTotal) {
            $inc = $curDiff - $prevDiff;
            if ($inc >= 2) {
                $result['regressed'] = true;
                $result['reasons'][] = "属性 '{$prop}' diff 增加: {$prevDiff}->{$curDiff} (+{$inc})";
            }
        }
    }

    // 3. Case 级逐项对比
    $prevCases = $previous['case_details'] ?? [];
    $curCases = $current['case_rows'] ?? [];
    foreach ($curCases as $row) {
        $name = $row['name'];
        $prev = $prevCases[$name] ?? null;
        if ($prev === null) continue;
        $dims = ['geometry_count' => '几何', 'mismatch_count' => '值', 'structure_count' => '结构', 'missing_count' => '缺失', 'critical_count' => '严重', 'major_count' => '中等'];
        foreach ($dims as $dim => $label) {
            $curVal = (int)($row[$dim] ?? 0);
            $prevVal = (int)($prev[$dim] ?? 0);
            if ($curVal > $prevVal) {
                $result['regressed'] = true;
                $result['reasons'][] = "{$name} {$label}差异增加: {$prevVal}->{$curVal}";
            }
        }
    }

    // 4. 浏览器对比失败数
    $prevBrFail = $previous['browser_fail'] ?? 0;
    $curBrFail = 0;
    foreach ($current['case_rows'] ?? [] as $row) {
        if (strpos($row['element_comp'] ?? '', "\u274c") !== false) $curBrFail++;
    }
    if ($curBrFail > $prevBrFail) {
        $result['regressed'] = true;
        $result['reasons'][] = "浏览器元素对比失败增加: {$prevBrFail}->{$curBrFail}";
    }

    // 5. Phase G 容器溢出
    $prevOverflow = 0;
    foreach ($prevCases as $pc) { $prevOverflow += (int)($pc['overflow_count'] ?? 0); }
    $curOverflow = 0;
    foreach ($curCases as $row) { $curOverflow += (int)($row['overflow_count'] ?? 0); }
    if ($curOverflow > $prevOverflow) {
        $result['regressed'] = true;
        $result['reasons'][] = "容器溢出总数增加: {$prevOverflow}->{$curOverflow}";
    }

    // 6. 步骤失败数
    $prevSteps = $previous['step_fails'] ?? 0;
    $curSteps = 0;
    foreach ($curCases as $row) {
        foreach (['build','layout','multiframe','phase_l'] as $s) {
            if (strpos($row[$s] ?? '', "\u274c") !== false) $curSteps++;
        }
    }
    if ($curSteps > $prevSteps) {
        $result['regressed'] = true;
        $result['reasons'][] = "步骤失败增加: {$prevSteps}->{$curSteps}";
    }

    // 7. 截图像素差异
    $prevPixel = $previous['avg_pixel_diff'] ?? null;
    $curTotal = 0; $curCnt = 0;
    foreach ($curCases as $row) {
        $pct = $row['screenshot'] ?? '';
        if (preg_match('/(\d+\.?\d*)%/', $pct, $m)) { $curTotal += (float)$m[1]; $curCnt++; }
    }
    $curAvg = $curCnt > 0 ? round($curTotal / $curCnt, 1) : null;
    if ($prevPixel !== null && $curAvg !== null && $curAvg > $prevPixel + 1.0) {
        $result['regressed'] = true;
        $result['reasons'][] = "截图平均像素差异增加: {$prevPixel}%->{$curAvg}%";
    }

    // 8. 运行时间异常
    $prevTime = $previous['elapsed_s'] ?? 0;
    $curTime = $current['total_time_s'] ?? 0;
    if ($prevTime > 5 && $curTime > $prevTime * 2) {
        $result['regressed'] = true;
        $result['reasons'][] = "运行时间异常增加: {$prevTime}s->{$curTime}s (x" . round($curTime/$prevTime, 1) . ")";
    }

    return $result;
}
PHP;

$out = substr($in, 0, $start) . $newDetect . substr($in, $end);

// ─── 2. Replace appendHistory method ───
$oldAppendStart = 'private function appendHistory(array $report): void';
$oldAppendEnd = '}'; // class closing brace
$start2 = strpos($out, $oldAppendStart);
$end2 = strrpos($out, $oldAppendEnd); // last '}' = end of class
if ($start2 === false || $end2 === false) die("CANNOT FIND appendHistory\n");

$newAppend = <<<'PHP'
private function appendHistory(array $report): void
{
    $historyPath = $this->appDir . '/.run_history.json';

    // Aggregate property stats from report
    $propStats = [];
    foreach ($report['property_stats'] as $prop => $stat) {
        $total = $stat['match'] + $stat['diff'];
        $propStats[$prop] = [
            'match' => $stat['match'],
            'diff'  => $stat['diff'],
            'total' => $total,
        ];
    }

    // Aggregate per-case details and step status
    $caseDetails = [];
    $totalPixelDiff = 0;
    $pixelDiffCount = 0;
    $stepFails = 0;
    $browserPass = 0;
    $browserFail = 0;
    foreach ($report['case_rows'] as $row) {
        $caseDetails[$row['name']] = [
            'geometry_count'  => $row['geometry_count'] ?? 0,
            'mismatch_count'  => $row['mismatch_count'] ?? 0,
            'structure_count' => $row['structure_count'] ?? 0,
            'missing_count'   => $row['missing_count'] ?? 0,
            'critical_count'  => $row['critical_count'] ?? 0,
            'major_count'     => $row['major_count'] ?? 0,
            'overflow_count'  => $row['overflow_count'] ?? 0,
            'element_comp'    => $row['element_comp'] ?? '',
            'build'           => $row['build'] ?? '',
            'layout'          => $row['layout'] ?? '',
            'multiframe'      => $row['multiframe'] ?? '',
            'phase_l'         => $row['phase_l'] ?? '',
            'screenshot'      => $row['screenshot'] ?? '',
            'result'          => $row['result'] ?? '',
        ];
        if (strpos($row['build'] ?? '', "\u274c") !== false) $stepFails++;
        if (strpos($row['layout'] ?? '', "\u274c") !== false) $stepFails++;
        if (strpos($row['multiframe'] ?? '', "\u274c") !== false) $stepFails++;
        if (strpos($row['element_comp'] ?? '', "\u274c") !== false) $browserFail++;
        elseif (strpos($row['element_comp'] ?? '', "\u2705") !== false) $browserPass++;
        if (strpos($row['phase_l'] ?? '', "\u274c") !== false) $stepFails++;
        // Screenshot pixel diff
        $pct = $row['screenshot'] ?? '';
        if (preg_match('/(\d+\.?\d*)%/', $pct, $m)) {
            $totalPixelDiff += (float)$m[1];
            $pixelDiffCount++;
        }
    }
    $avgPixelDiff = $pixelDiffCount > 0 ? round($totalPixelDiff / $pixelDiffCount, 1) : null;

    $entry = [
        'timestamp'     => $report['timestamp'],
        'total_cases'   => $report['total_cases'],
        'passed'        => $report['passed'],
        'failed'        => $report['failed'],
        'elapsed_s'     => $report['total_time_s'],
        'browser_pass'  => $browserPass,
        'browser_fail'  => $browserFail,
        'step_fails'    => $stepFails,
        'avg_pixel_diff' => $avgPixelDiff,
        'property_stats'  => $propStats,
        'case_details'    => $caseDetails,
    ];

    $history = [];
    if (file_exists($historyPath)) {
        $content = @file_get_contents($historyPath);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $history = $decoded;
        }
    }

    $history[] = $entry;
    // Keep last 50 entries
    if (count($history) > 50) {
        $history = array_slice($history, -50);
    }

    file_put_contents($historyPath,
        json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo "[SUMMARY] History appended: {$historyPath} ({$entry['passed']}/{$entry['total_cases']} passed)\n";
}
PHP;

$out = substr($out, 0, $start2) . $newAppend . substr($out, $end2);

file_put_contents(__DIR__ . '/SummaryReporter.php', $out);
echo "PATCHED " . filesize(__DIR__ . '/SummaryReporter.php') . " bytes\n";
echo "Has step_fails: " . (strpos($out, 'step_fails') !== false ? 'YES' : 'NO') . "\n";
echo "Has case_details: " . (strpos($out, 'case_details') !== false ? 'YES' : 'NO') . "\n";
echo "Has avg_pixel_diff: " . (strpos($out, 'avg_pixel_diff') !== false ? 'YES' : 'NO') . "\n";
echo "Has 容器溢出: " . (strpos($out, '容器溢出') !== false ? 'YES' : 'NO') . "\n";
echo "Has 截图平均: " . (strpos($out, '截图平均') !== false ? 'YES' : 'NO') . "\n";
echo "Has 运行时间: " . (strpos($out, '运行时间') !== false ? 'YES' : 'NO') . "\n";
echo "Has 步骤失败: " . (strpos($out, '步骤失败') !== false ? 'YES' : 'NO') . "\n";
