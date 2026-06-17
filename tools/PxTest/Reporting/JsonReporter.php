<?php

namespace PxTest\Reporting;

use PxTest\Core\TestSuite;
use PxTest\Core\TestResult;

/**
 * JSON 报告器 — 结构化输出 (CI 集成)。
 */
class JsonReporter implements ReporterInterface
{
    private float $startTime;
    /** @var array<int, array> */
    private array $results = [];

    public function reportStart(TestSuite $suite): void
    {
        $this->startTime = microtime(true);
    }

    public function reportCaseResult(string $name, TestResult $result): void
    {
        $this->results[] = [
            'name'   => $name,
            'status' => $result->status,
            'duration_ms' => $result->durationMs,
            'details' => $result->details,
        ];
    }

    public function reportEnd(TestSuite $suite): void
    {
        $passed = count(array_filter($this->results, fn($r) => $r['status'] === TestResult::STATUS_PASS));
        $failed = count(array_filter($this->results, fn($r) => $r['status'] === TestResult::STATUS_FAIL));
        $elapsed = round(microtime(true) - $this->startTime, 2);

        echo json_encode([
            'suite'   => $suite->name,
            'total'   => count($this->results),
            'passed'  => $passed,
            'failed'  => $failed,
            'elapsed_s' => $elapsed,
            'results' => $this->results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
