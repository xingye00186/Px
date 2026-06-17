<?php

namespace PxTest\Reporting;

use PxTest\Core\TestSuite;
use PxTest\Core\TestResult;

/**
 * 控制台报告器 — 人类可读格式。
 */
class ConsoleReporter implements ReporterInterface
{
    private float $startTime;
    private int $totalPassed = 0;
    private int $totalFailed = 0;
    private int $totalSkipped = 0;

    public function reportStart(TestSuite $suite): void
    {
        $this->startTime = microtime(true);
        $count = $suite->caseCount();
        echo "═══════════════════════════════════\n";
        echo "  PxTest Suite: {$suite->name}\n";
        echo "  Cases: $count\n";
        echo "═══════════════════════════════════\n\n";
    }

    public function reportCaseResult(string $name, TestResult $result): void
    {
        $icon = match ($result->status) {
            TestResult::STATUS_PASS => '✅',
            TestResult::STATUS_FAIL => '❌',
            TestResult::STATUS_SKIP => '⏭️',
            default => '⚠️',
        };
        $ms = round($result->durationMs, 1);
        echo "  $icon $name ({$ms}ms)\n";

        if ($result->status === TestResult::STATUS_FAIL && !empty($result->details)) {
            foreach ($result->details as $k => $v) {
                echo "      $k: $v\n";
            }
        }

        match ($result->status) {
            TestResult::STATUS_PASS => $this->totalPassed++,
            TestResult::STATUS_FAIL, TestResult::STATUS_ERROR => $this->totalFailed++,
            default => $this->totalSkipped++,
        };
    }

    public function reportEnd(TestSuite $suite): void
    {
        $elapsed = round(microtime(true) - $this->startTime, 2);
        $total = $this->totalPassed + $this->totalFailed + $this->totalSkipped;
        echo "\n═══════════════════════════════════\n";
        echo "  Passed: $this->totalPassed / $total\n";
        if ($this->totalFailed > 0) {
            echo "  Failed: $this->totalFailed\n";
            echo "  ❌ SOME TESTS FAILED!\n";
        } else {
            echo "  ✅ All tests passed!\n";
        }
        echo "  Time: {$elapsed}s\n";
        echo "═══════════════════════════════════\n";
    }
}
