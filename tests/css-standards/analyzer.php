<?php
/**
 * analyzer.php — CSS 标准测试自动分析器
 *
 * 解析各 suite 的原始输出，聚合测试结果，检测异常并生成汇总报告。
 * 异常分类：
 *   Minor   - 单像素偏移 / 装饰属性差异
 *   Major   - 结构差异（缺/多余节点、容器尺寸错误）
 *   Critical - 管线崩溃或异常
 *
 * Usage: 由 run_all.php --analyze 自动调用
 */

/**
 * 从 suite 输出中解析测试级别统计。
 * 返回: ['total' => int, 'passed' => int, 'failed' => int, 'lines' => [...]]
 */
function parse_suite_output(string $output): array
{
    $lines = explode("\n", $output);
    $result = [
        'total' => 0,
        'passed' => 0,
        'failed' => 0,
        'tests' => [], // ['name' => ..., 'status' => 'PASS'|'FAIL', 'details' => ...]
    ];

    $currentTest = null;

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);

        // 匹配 [PASS] 或 [FAIL] 行
        if (preg_match('/^\[(PASS|FAIL|SKIP)\]\s+(.+)$/', $line, $m)) {
            $status = $m[1];
            $name = trim($m[2]);

            if ($status === 'PASS') {
                $result['passed']++;
            } elseif ($status === 'FAIL') {
                $result['failed']++;
            }

            $result['tests'][] = [
                'name' => $name,
                'status' => $status,
                'details' => [],
            ];
            $currentTest = &$result['tests'][count($result['tests']) - 1];
        }
        // 收集 [DIFF] 详细信息
        elseif (preg_match('/^\[DIFF/', $line) && $currentTest !== null) {
            $currentTest['details'][] = $line;
        }
        // 收集错误信息行（[FAIL] 后的缩进行）
        elseif (preg_match('/^\s{10}/', $line) && $currentTest !== null && $currentTest['status'] === 'FAIL') {
            $currentTest['details'][] = trim($line);
        }
    }

    $result['total'] = $result['passed'] + $result['failed'];
    return $result;
}

/**
 * 对单个测试的差异进行分类。
 * 返回: 'MINOR' | 'MAJOR' | 'CRITICAL' | 'UNKNOWN'
 */
function classify_anomaly(array $test): string
{
    if ($test['status'] === 'PASS' || $test['status'] === 'SKIP') {
        return 'NONE';
    }

    $details = implode("\n", $test['details']);

    // 严重：管线崩溃或异常
    if (preg_match('/(Fatal error|Parse error|exception|Throwable|stack trace)/i', $details)) {
        return 'CRITICAL';
    }

    // 主要：结构差异（子节点数量、缺失/多余节点、容器尺寸严重偏差）
    if (preg_match('/(expected.*\(end\)|actual.*\(end\)|不同子节点|Missing node|Extra node)/i', $details)) {
        return 'MAJOR';
    }

    // 检查尺寸偏差：如果 w/h 偏差超过 5px 认为是 major
    if (preg_match('/expected.* w=(\d+)/', $details, $mExp) && preg_match('/actual.* w=(\d+)/', $details, $mAct)) {
        $diff = abs((int)$mExp[1] - (int)$mAct[1]);
        if ($diff > 5) return 'MAJOR';
        if ($diff <= 5) return 'MINOR';
    }
    if (preg_match('/expected.* h=(\d+)/', $details, $mExp) && preg_match('/actual.* h=(\d+)/', $details, $mAct)) {
        $diff = abs((int)$mExp[1] - (int)$mAct[1]);
        if ($diff > 5) return 'MAJOR';
        if ($diff <= 5) return 'MINOR';
    }

    // 轻微偏移或装饰属性
    return 'MINOR';
}

/**
 * 生成完整的分析报告。
 * 返回: ['text' => string, 'has_failures' => bool]
 */
function generate_analysis_report(array $suiteOutputs, array $scripts): array
{
    $totalTests = 0;
    $totalPassed = 0;
    $totalFailed = 0;
    $totalSkipped = 0;
    $suitesPassed = 0;
    $suitesFailed = 0;
    $anomalies = []; // ['suite' => ..., 'test' => ..., 'type' => ..., 'details' => ...]

    $reportLines = [];
    $reportLines[] = '';
    $reportLines[] = '========================================';
    $reportLines[] = ' CSS Standards Layout Test Suite Report';
    $reportLines[] = '========================================';
    $reportLines[] = '';

    // 对每个 suite 解析
    $suiteRows = [];
    foreach ($scripts as $suiteName => $file) {
        $info = $suiteOutputs[$suiteName] ?? ['status' => 'SKIP', 'output' => ''];
        $parsed = parse_suite_output($info['output']);

        $totalTests += $parsed['total'];
        $totalPassed += $parsed['passed'];
        $totalFailed += $parsed['failed'];

        if ($parsed['total'] > 0) {
            $statusTag = $parsed['failed'] > 0 ? 'FAIL' : 'PASS';
            $suiteRows[] = sprintf(
                "  %-38s [%s] %d/%d",
                $suiteName,
                $statusTag,
                $parsed['passed'],
                $parsed['total']
            );

            if ($parsed['failed'] > 0) {
                $suitesFailed++;
                // 收集异常细节
                foreach ($parsed['tests'] as $test) {
                    if ($test['status'] === 'FAIL') {
                        $anomalyType = classify_anomaly($test);
                        $anomalies[] = [
                            'suite' => $suiteName,
                            'test' => $test['name'],
                            'type' => $anomalyType,
                            'details' => $test['details'],
                        ];
                    }
                }
            } else {
                $suitesPassed++;
            }
        } else {
            $suiteRows[] = sprintf("  %-38s [SKIP]", $suiteName);
        }
    }

    // Suite results table
    $reportLines = array_merge($reportLines, $suiteRows);
    $reportLines[] = '';
    $reportLines[] = '----------------------------------------';

    // 详细失败信息
    if (!empty($anomalies)) {
        $reportLines[] = ' Detailed Failures';
        $reportLines[] = '----------------------------------------';
        $reportLines[] = '';

        foreach ($anomalies as $anomaly) {
            $reportLines[] = "Suite: {$anomaly['suite']}";
            $reportLines[] = "  Test: \"{$anomaly['test']}\"";

            foreach ($anomaly['details'] as $detail) {
                $reportLines[] = "    {$detail}";
            }
            $reportLines[] = "  Category: {$anomaly['type']}";
            $reportLines[] = '';
        }

        $reportLines[] = '========================================';
    }

    // 汇总
    $reportLines[] = ' Summary';
    $reportLines[] = '========================================';
    $reportLines[] = '';
    $reportLines[] = "  Total suites:  " . count($scripts);
    $reportLines[] = "  Passed:        {$suitesPassed}";
    $reportLines[] = "  Failed:        {$suitesFailed}";
    $reportLines[] = '';
    $reportLines[] = "  Total tests:   {$totalTests}";
    $reportLines[] = "  Passed:        {$totalPassed}";
    $reportLines[] = "  Failed:        {$totalFailed}";
    if ($totalSkipped > 0) {
        $reportLines[] = "  Skipped:       {$totalSkipped}";
    }
    $reportLines[] = '';

    // 异常分类汇总
    $minorCount = 0;
    $majorCount = 0;
    $criticalCount = 0;
    foreach ($anomalies as $a) {
        if ($a['type'] === 'MINOR') $minorCount++;
        elseif ($a['type'] === 'MAJOR') $majorCount++;
        elseif ($a['type'] === 'CRITICAL') $criticalCount++;
    }
    if ($minorCount > 0 || $majorCount > 0 || $criticalCount > 0) {
        $reportLines[] = "  Anomalies: {$minorCount} minor, {$majorCount} major, {$criticalCount} critical";
        $reportLines[] = '';
    }

    if ($suitesFailed > 0 || $criticalCount > 0) {
        $reportLines[] = '  ❌ SOME SUITES FAILED!';
        $reportLines[] = '';
        $hasFailures = true;
    } else {
        $reportLines[] = '  ✅ All suites passed!';
        $reportLines[] = '';
        $hasFailures = false;
    }

    return [
        'text' => implode("\n", $reportLines),
        'has_failures' => $hasFailures,
        'anomalies' => $anomalies,
    ];
}
