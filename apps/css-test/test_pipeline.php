<?php

/**
 * CSS Test Pipeline — PxTest D→I 六步全流程编排器。
 *
 * Usage:
 *   php apps/css-test/test_pipeline.php                              # 全量测试
 *   php apps/css-test/test_pipeline.php --case=case-007-border-styles # 单 case
 *   php apps/css-test/test_pipeline.php --browser-engine-el-compare # 启用浏览器元素对比（默认跳过）
 *   php apps/css-test/test_pipeline.php --screenshot            # 启用截图对比（默认跳过）
 *   php apps/css-test/test_pipeline.php --format=md                  # Markdown 报告
 */

$projectRoot = dirname(__DIR__, 2);

// ─── Windows 进程清理 ───
if (PHP_OS_FAMILY === 'Windows') {
    exec('taskkill /F /IM php-cgi.exe /T 2>NUL');
    exec('taskkill /F /IM msedge.exe /T 2>NUL');
}

require_once $projectRoot . '/tests/unit/bootstrap.php';
require_once $projectRoot . '/tools/PxTest/bootstrap.php';

date_default_timezone_set('Asia/Shanghai');

use PxTest\Pipeline\PipelineBuilder;
use PxTest\Pipeline\StepResult;
use PxTest\Reporting\ConsoleReporter;
use PxTest\Reporting\MarkdownReporter;
use PxTest\Reporting\JsonReporter;
use PxTest\Reporting\SummaryReporter;
use PxTest\Core\TestSuite;

// ─── Build Pipeline ───
$builder = PipelineBuilder::create($projectRoot);
$builder->parseCli($argv ?? []);
$appName = (function() use ($argv) {
    foreach ($argv ?? [] as $a) {
        if (str_starts_with($a, '--app=')) return substr($a, 6);
    }
    return 'css-test';
})();
$appDir = $projectRoot . '/apps/' . $appName;
$title = $appName === 'php-rt-test' ? 'PHP RT Test Pipeline' : 'CSS Test Pipeline';

echo "═══════════════════════════════════════════════\n";
echo "  $title — PxTest (D→E→G→H→I)\n";
echo "═══════════════════════════════════════════════\n\n";

$orchestrator = $builder->build();

// ─── Discover Cases ───
$cases = $builder->getAllCases();
$filtered = [];
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--case=')) {
        $target = substr($arg, 7);
        foreach ($cases as $c) { if ($c === $target) $filtered[] = $c; }
        if (empty($filtered)) { echo "[ERR] Case not found: $target\n"; exit(1); }
        break;
    }
}
if (empty($filtered)) $filtered = $cases;

// ─── Select Reporter ───
$format = 'console';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--format=')) $format = substr($arg, 9);
}
$reporter = match ($format) {
    'json' => new JsonReporter(),
    'md'   => new MarkdownReporter(),
    default => new ConsoleReporter(),
};

// ─── Load issue tracker for DOC_WARN ───
$issueTracker = $appDir . '/docs/01-问题清单.md';
$issueContent = file_exists($issueTracker) ? file_get_contents($issueTracker) : '';

// ─── 单 case 模式：从 .case_data.json 加载历史数据 ───
$isSingleCase = (count($filtered) < count($cases));
$isPhpRuntime = getenv('PX_PHP_RUNTIME') !== false && getenv('PX_PHP_RUNTIME') !== '';
$caseDataFile = $appDir . '/' . ($isPhpRuntime ? '.case_data_php_rt.json' : '.case_data.json');

$allCaseData = [];
if ($isSingleCase && file_exists($caseDataFile)) {
    $stored = @json_decode(@file_get_contents($caseDataFile), true);
    if (is_array($stored)) {
        foreach ($stored as $cName => &$cData) {
            if (isset($cData['results']) && is_array($cData['results'])) {
                $restored = [];
                foreach ($cData['results'] as $r) {
                    $restored[] = new StepResult(
                        $r['stepName'] ?? '',
                        $r['passed'] ?? false,
                        $r['errors'] ?? [],
                        $r['durationMs'] ?? 0.0,
                    );
                }
                $cData['results'] = $restored;
            }
        }
        unset($cData);
        $allCaseData = $stored;
    }
}

// ─── Run Pipeline for each case ───
$suite = new TestSuite('css-test-pipeline');
$reporter->reportStart($suite);

$pipelineCtx = new \PxTest\Pipeline\PipelineContext();
$totalPass = 0; $totalFail = 0;
foreach ($filtered as $caseName) {
    echo "── $caseName ──\n";
    $ctx = new \PxTest\Pipeline\CaseContext($pipelineCtx);
    $ctx->set('case_name', $caseName);
    $results = $orchestrator->run($ctx);

    $allCaseData[$caseName] = [
        'results'          => $results,
        'prop_stats'       => $ctx->get('element_prop_stats', []),
        'engine_count'     => $ctx->get('element_engine_count', 0),
        'browser_count'    => $ctx->get('element_browser_count', 0),
        'layout_issues'    => $ctx->get('layout_validation_issues', -1),
        'overflow_issues'  => $ctx->get('overflow_issues', []),
        'container_issues' => $ctx->get('container_overflow_issues', []),
        'compare_stats'    => [
            'missing'   => $ctx->get('compare_missing_count', 0),
            'geometry'  => $ctx->get('compare_geometry_count', 0),
            'mismatch'  => $ctx->get('compare_mismatch_count', 0),
            'structure' => $ctx->get('compare_structure_count', 0),
            'critical'  => $ctx->get('geo_critical_count', 0),
            'major'     => $ctx->get('geo_major_count', 0),
            'minor'     => $ctx->get('geo_minor_count', 0),
        ],
        'pixel_diff'       => $ctx->get('pixel_diff_pct', null),
    ];

    $passed = true;
    foreach ($results as $r) {
        $icon = $r->passed ? '✅' : '❌';
        $ms = round($r->durationMs, 1);
        echo "  $icon {$r->stepName} ({$ms}ms)\n";
        if (!$r->passed) { $passed = false; foreach ($r->errors as $e) echo "      $e\n"; }
    }

    $result = $passed ? \PxTest\Core\TestResult::pass($caseName) : \PxTest\Core\TestResult::fail($caseName);
    $reporter->reportCaseResult($caseName, $result);
    $passed ? $totalPass++ : $totalFail++;
    echo "\n";
}

// ─── 保存 case 数据缓存（支持后续单 case 合并）───
@file_put_contents($caseDataFile, json_encode($allCaseData, JSON_UNESCAPED_UNICODE));

// ─── 生成汇总报告 + 运行历史 ───
if (!empty($allCaseData)) {
    $summary = new SummaryReporter($appDir);
    $summary->generate($allCaseData);
}

// DOC_WARN: check FAIL cases against issue tracker
if ($totalFail > 0 && $issueContent !== '') {
    echo "\n[DOC_WARN] Checking FAIL cases against issue tracker...\n";
    foreach ($filtered as $caseName) {
        if (stripos($issueContent, $caseName) === false
            && stripos($issueContent, str_replace('-', ' ', $caseName)) === false
        ) {
            echo "  [DOC_WARN] $caseName has FAIL but is not mentioned in issue tracker!\n";
        }
    }
}

$reporter->reportEnd($suite);
exit($totalFail > 0 ? 1 : 0);
