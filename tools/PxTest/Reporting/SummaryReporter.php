<?php

namespace PxTest\Reporting;

/**
 * SummaryReporter — 汇总全量 pipeline 运行结果，生成持久化报告和历史记录。
 *
 * 输出:
 *   1. apps/css-test/docs/02-测试报告/最新报告.md   — Case 级汇总 + 属性通过率
 *   2. apps/css-test/.run_history.json               — 时间序列历史，追踪趋势
 *
 * Usage:
 *   (在 test_pipeline.php 中全量循环结束后调用)
 *   $reporter = new SummaryReporter($appDir);
 *   $reporter->generate($allCaseData);
 */
class SummaryReporter
{
    private string $appDir;
    private float $startTime;

    public function __construct(string $appDir)
    {
        $this->appDir = rtrim($appDir, '/\\');
        $this->startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    }

    /**
     * 生成汇总报告 + 追加运行历史。
     *
     * @param array $allCaseData  keyed by caseName, each containing:
     *   'results'        => StepResult[]
     *   'prop_stats'     => ['prop-name' => ['match' => N, 'diff' => N]]
     *   'engine_count'   => int
     *   'browser_count'  => int
     *   'layout_issues'  => int (-1 if not run)
     *   'overflow_issues'=> array
     *   'pixel_diff'     => ?float
     */
    public function generate(array $allCaseData): void
    {
        $report = $this->buildSummary($allCaseData);
        $previous = $this->loadLastRun();
        $report['regression'] = $this->detectRegression($report, $previous);
        $this->writeReport($report);
        $this->appendHistory($report);
    }

    private function buildSummary(array $allCaseData): array
    {
        $caseRows = [];
        $totalPass = 0;
        $totalFail = 0;
        $totalTime = 0;
        $globalPropStats = []; // aggregated across all cases

        foreach ($allCaseData as $caseName => $data) {
            $results = $data['results'] ?? [];
            $propStats = $data['prop_stats'] ?? [];
            $caseTime = 0;

            // Merge per-case prop stats into global
            foreach ($propStats as $prop => $stat) {
                if (!isset($globalPropStats[$prop])) {
                    $globalPropStats[$prop] = ['match' => 0, 'diff' => 0];
                }
                $globalPropStats[$prop]['match'] += $stat['match'];
                $globalPropStats[$prop]['diff'] += $stat['diff'];
            }

            // Build column map: stepName → passed
            $stepMap = [];
            $stepPassed = true;
            foreach ($results as $r) {
                $stepMap[$r->stepName] = $r->passed;
                $caseTime += $r->durationMs;
                if (!$r->passed) $stepPassed = false;
            }

            $stepPassed ? $totalPass++ : $totalFail++;
            $totalTime += $caseTime;

            // Format step icons
            $buildIcon = $this->stepIcon($stepMap, 'build');
            $layoutDumpIcon = $this->stepIcon($stepMap, 'dump_layout');
            $multiFrameIcon = $this->stepIcon($stepMap, 'multiframe');
            $browserRefIcon = $this->stepIcon($stepMap, 'browser_ref');
            $elemCompIcon = $this->stepIcon($stepMap, 'element_compare');
            $phaseLIcon = $this->stepIcon($stepMap, 'layout_validation');

            // Phase L issue count
            $layoutIssues = $data['layout_issues'] ?? -1;
            if ($layoutIssues > 0) {
                $phaseLIcon .= " {$layoutIssues}issue";
            }
            $screenshotIcon = $this->stepIcon($stepMap, 'screenshot_compare');

            // Phase G container overflow count
            $containerIssues = $data['container_issues'] ?? [];
            $containerOverflowCount = count($containerIssues);
            $phaseGIcon = $containerOverflowCount > 0 ? "⚠️ {$containerOverflowCount}" : '✅';

            // Element compare diff counts
            $compStats = $data['compare_stats'] ?? [];
            $geoCount = $compStats['geometry'] ?? 0;
            $misCount = $compStats['mismatch'] ?? 0;
            $structCount = $compStats['structure'] ?? 0;
            $totalDiff = $geoCount + $misCount + $structCount;
            $critical = $compStats['critical'] ?? 0;
            $major = $compStats['major'] ?? 0;
            $diffInfo = $totalDiff > 0 ? "{$totalDiff}diff" : '✅';
            if ($critical > 0) $diffInfo .= " 🔴{$critical}";
            elseif ($major > 0) $diffInfo .= " 🟡{$major}";

            // Screenshot info
            $pixelDiff = $data['pixel_diff'] ?? null;
            $screenshotInfo = $screenshotIcon;
            if ($pixelDiff !== null) {
                $screenshotInfo = ($screenshotIcon === '✅' ? '✅' : '❌') . " {$pixelDiff}%";
            }

            $caseRows[] = [
                'name'         => $caseName,
                'build'        => $buildIcon,
                'layout'       => $layoutDumpIcon,
                'multiframe'   => $multiFrameIcon,
                'browser_ref'  => $browserRefIcon,
                'element_comp' => $elemCompIcon,
                'phase_l'      => $phaseLIcon,
                'phase_g'      => $phaseGIcon,
                'diff_detail'  => $diffInfo,
                'missing_count'  => $compStats['missing'] ?? 0,
                'geometry_count' => $geoCount,
                'critical_count' => $compStats['critical'] ?? 0,
                'major_count'    => $compStats['major'] ?? 0,
                'mismatch_count' => $misCount,
                'structure_count'=> $structCount,
                'overflow_count' => $containerOverflowCount,
                'container_issues' => $containerIssues,
                'screenshot'   => $screenshotInfo,
                'result'       => $stepPassed ? '✅ 通过' : '❌ 失败',
                'time_ms'      => round($caseTime / 1000, 1), // seconds
            ];
        }

        return [
            'timestamp'      => date('Y-m-d H:i:s'),
            'total_cases'    => count($allCaseData),
            'passed'         => $totalPass,
            'failed'         => $totalFail,
            'total_time_s'   => round($totalTime / 1000, 1),
            'case_rows'      => $caseRows,
            'property_stats' => $globalPropStats,
        ];
    }

    private function loadLastRun(): ?array
    {
        $historyPath = $this->appDir . '/.run_history.json';
        if (!file_exists($historyPath)) return null;
        $content = @file_get_contents($historyPath);
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || empty($decoded)) return null;
        return end($decoded);
    }

    /**
     * 对比当前运行与上一次运行，检测回归。
     * 返回 ['regressed'=>bool, 'reasons'=>string[]]
     */
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
                    $result['reasons'][] = sprintf(
                        '通过率下降: %.1f%% → %.1f%%',
                        $prevRate * 100, $curRate * 100
                    );
                }
            }
        }

        // 2. 上次通过本次失败（精确 case 级回归）
        // 从 property_stats 推断：上次每个属性的 diff 不应增加
        $prevStats = $previous['property_stats'] ?? [];
        $curStats = $current['property_stats'] ?? [];

        foreach ($curStats as $prop => $curStat) {
            $curDiff = $curStat['diff'] ?? 0;
            $prevDiff = ($prevStats[$prop]['diff'] ?? 0);
            // 上次 total 不为 0 才比较（避免首次出现时的误报）
            $prevTotal = ($prevStats[$prop]['total'] ?? 0);
            $curTotal = $curStat['total'] ?? 0;
            if ($prevTotal > 0 && $curDiff > $prevDiff && $curTotal >= $prevTotal) {
                $inc = $curDiff - $prevDiff;
                if ($inc >= 2) {  // 2px 以下忽略微小波动
                    $result['regressed'] = true;
                    $result['reasons'][] = "属性 '{$prop}' diff 增加: {$prevDiff}→{$curDiff} (+{$inc})";
                }
            }
        }

        // 3. 浏览器对比失败数增加
        $prevBrFail = $previous['browser_fail'] ?? 0;
        $curBrFail = 0;
        foreach ($current['case_rows'] ?? [] as $row) {
            if (($row['element_comp'] ?? '') === '❌') $curBrFail++;
        }
        if ($curBrFail > $prevBrFail) {
            $result['regressed'] = true;
            $result['reasons'][] = "浏览器元素对比失败增加: {$prevBrFail}→{$curBrFail}";
        }

        // 4. case_rows 级别逐一对比（需保存上一次的 case_rows）
        // 当前 appendHistory 未保存 per-case 明细，暂不对比

        return $result;
    }

    private function stepIcon(array $stepMap, string $name): string
    {
        if (!isset($stepMap[$name])) return '⏭️';
        return $stepMap[$name] ? '✅' : '❌';
    }

    private function writeReport(array $report): void
    {
        $reportDir = $this->appDir . '/docs/02-测试报告';
        if (!is_dir($reportDir)) {
            @mkdir($reportDir, 0777, true);
        }

        $md = $this->formatMarkdown($report);
        file_put_contents($reportDir . '/最新报告.md', $md);
        echo "\n[SUMMARY] Report saved: {$reportDir}/最新报告.md\n";
    }

    private function formatMarkdown(array $report): string
    {
        $lines = [];
        $lines[] = '# CSS Test Sandbox — 测试报告';
        $lines[] = '';
        $lines[] = '**运行时间**: ' . $report['timestamp'] . ' | **总耗时**: ' . $report['total_time_s'] . 's';
        $lines[] = '';
        $lines[] = '| 用例 | 构建 | 布局 | 多帧 | 浏览器 | 元素对比 | Phase L | Phase G | 差异 | 截图 | 结果 | 耗时 |';
        $lines[] = '|------|------|------|------|--------|----------|---------|---------|------|------|------|------|';

        foreach ($report['case_rows'] as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %ss |',
                $row['name'],
                $row['build'],
                $row['layout'],
                $row['multiframe'],
                $row['browser_ref'],
                $row['element_comp'],
                $row['phase_l'],
                $row['phase_g'],
                $row['diff_detail'],
                $row['screenshot'],
                $row['result'],
                $row['time_ms']
            );
        }

        $lines[] = '';
        $lines[] = '**汇总**: ' . $report['passed'] . ' ✅ / ' . $report['failed'] . ' ❌ / ' . $report['total_cases'] . ' 总计 (总耗时: ' . $report['total_time_s'] . 's)';
        $lines[] = '';

        // ─── 回归判定 ───
        $reg = $report['regression'] ?? [];
        $lines[] = '## 回归判定';
        $lines[] = '';
        if (!empty($reg['first_run'])) {
            $lines[] = '> 🟢 首次运行，无历史数据可供对比，本次结果将作为后续回归的基线。';
        } elseif (!empty($reg['regressed'])) {
            $lines[] = '> 🔴 **检测到回归** — 与上一次运行相比，以下指标恶化:';
            $lines[] = '>';
            foreach ($reg['reasons'] as $reason) {
                $lines[] = "> - {$reason}";
            }
            $lines[] = '>';
            $lines[] = '> ⚠️ 建议: 检查本次变更是否引入了预期外的行为改变。';
        } else {
            $lines[] = '> ✅ **无回归** — 与上一次运行相比，各项指标均未恶化。';
        }
        $lines[] = '';

        // ─── 回归对比详情表 ───
        $propStats = $report['property_stats'];
        if (!empty($propStats)) {
            // Sort: by total count descending, then by pass rate ascending
            uksort($propStats, function ($a, $b) use ($propStats) {
                $ta = $propStats[$a]['match'] + $propStats[$a]['diff'];
                $tb = $propStats[$b]['match'] + $propStats[$b]['diff'];
                if ($ta !== $tb) return $tb - $ta; // more total first
                $ra = $ta > 0 ? $propStats[$a]['match'] / $ta : 1;
                $rb = $tb > 0 ? $propStats[$b]['match'] / $tb : 1;
                return $ra <=> $rb; // lower rate first (problems first)
            });

            $lines[] = '## 样式属性统计';
            $lines[] = '';
            $lines[] = '| 属性 | 通过率 | 通过/总 |';
            $lines[] = '|------|--------|--------|';
            foreach ($propStats as $prop => $stat) {
                $total = $stat['match'] + $stat['diff'];
                $rate = $total > 0 ? round($stat['match'] / $total * 100, 1) : 0;
                $lines[] = "| $prop | {$rate}% | {$stat['match']}/{$total} |";
            }
        }

        // ─── 逐 Case 差异详情 ───
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## 逐 Case 差异详情';
        $lines[] = '';
        $lines[] = '| 用例 | 缺失(MISSING) | 严重(>20px) | 中等(5-20px) | 值(MISMATCH) | 结构(STRUCTURE) | Phase G 溢出 |';
        $lines[] = '|------|:-------------:|:-----------:|:------------:|:-------------:|:---------------:|:------------:|';
        foreach ($report['case_rows'] as $row) {
            $critical = $row['critical_count'] ?? 0;
            $major = $row['major_count'] ?? 0;
            $cLabel = $critical > 0 ? "**{$critical}**" : '0';
            $mLabel = $major > 0 ? "**{$major}**" : '0';
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %s |',
                $row['name'],
                $row['missing_count'],
                $cLabel,
                $mLabel,
                $row['mismatch_count'],
                $row['structure_count'],
                $row['overflow_count']
            );
        }
        $lines[] = '';

        // ─── Phase G 容器溢出详情 ───
        $hasPhaseG = false;
        foreach ($report['case_rows'] as $row) {
            if (!empty($row['container_issues'])) {
                if (!$hasPhaseG) {
                    $lines[] = '---';
                    $lines[] = '';
                    $lines[] = '## Phase G 容器溢出详情';
                    $lines[] = '';
                    $hasPhaseG = true;
                }
                $lines[] = "### {$row['name']}\n";
                foreach ($row['container_issues'] as $issue) {
                    $lines[] = "- $issue";
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines) . "\n";
    }

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

        // Browser comparison totals from per-case data
        $browserPass = 0;
        $browserFail = 0;
        foreach ($report['case_rows'] as $row) {
            if ($row['element_comp'] === '✅') $browserPass++;
            elseif ($row['element_comp'] === '❌') $browserFail++;
        }

        $entry = [
            'timestamp'    => $report['timestamp'],
            'total_cases'  => $report['total_cases'],
            'passed'       => $report['passed'],
            'failed'       => $report['failed'],
            'elapsed_s'    => $report['total_time_s'],
            'browser_pass' => $browserPass,
            'browser_fail' => $browserFail,
            'property_stats' => $propStats,
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
}
