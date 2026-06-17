<?php

/**
 * CSS Test Pipeline — PxTest D→I 六步全流程编排器。
 *
 * Usage:
 *   php apps/css-test/test_pipeline.php                              # 全量测试
 *   php apps/css-test/test_pipeline.php --case=case-007-border-styles # 单 case
 *   php apps/css-test/test_pipeline.php --skip-browser-ref           # 跳过浏览器
 *   php apps/css-test/test_pipeline.php --skip-screenshot            # 跳过截图
 *   php apps/css-test/test_pipeline.php --format=md                  # Markdown 报告
 */

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/tests/unit/bootstrap.php';
require_once $projectRoot . '/tools/PxTest/bootstrap.php';

use PxTest\Pipeline\PipelineBuilder;
use PxTest\Reporting\ConsoleReporter;
use PxTest\Reporting\MarkdownReporter;
use PxTest\Reporting\JsonReporter;
use PxTest\Core\TestSuite;

echo "═══════════════════════════════════════════════\n";
echo "  CSS Test Pipeline — PxTest (D→E→G→H→I)\n";
echo "═══════════════════════════════════════════════\n\n";

// ─── Build Pipeline ───
$builder = PipelineBuilder::create($projectRoot);
$builder->parseCli($argv ?? []);
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
$issueTracker = $projectRoot . '/apps/css-test/docs/01-问题清单.md';
$issueContent = file_exists($issueTracker) ? file_get_contents($issueTracker) : '';

// ─── Run Pipeline for each case ───
$suite = new TestSuite('css-test-pipeline');
$reporter->reportStart($suite);

$totalPass = 0; $totalFail = 0;
foreach ($filtered as $caseName) {
    echo "── $caseName ──\n";
    $ctx = new \PxTest\Pipeline\PipelineContext();
    $ctx->set('case_name', $caseName);
    $results = $orchestrator->run($ctx);

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

// DOC_WARN: check FAIL cases against issue tracker
if ($totalFail > 0 && $issueContent !== '') {
    echo "\n[DOC_WARN] Checking FAIL cases against issue tracker...\n";
    foreach ($filtered as $caseName) {
        // Check if case has FAIL but not in issue tracker
        if (stripos($issueContent, $caseName) === false
            && stripos($issueContent, str_replace('-', ' ', $caseName)) === false
        ) {
            echo "  [DOC_WARN] $caseName has FAIL but is not mentioned in issue tracker!\n";
        }
    }
}

$reporter->reportEnd($suite);
exit($totalFail > 0 ? 1 : 0);
