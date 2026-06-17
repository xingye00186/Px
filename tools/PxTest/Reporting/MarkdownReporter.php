<?php

namespace PxTest\Reporting;

use PxTest\Core\TestSuite;
use PxTest\Core\TestResult;

/** Markdown 报告器 */
class MarkdownReporter implements ReporterInterface
{
    private float $startTime;
    /** @var array<int, array> */
    private array $rows = [];

    public function reportStart(TestSuite $suite): void { $this->startTime = microtime(true); }
    public function reportCaseResult(string $name, TestResult $result): void
    {
        $icon = $result->isPassed() ? '✅' : '❌';
        $ms = round($result->durationMs, 1);
        $this->rows[] = "| $icon $name | {$ms}ms |";
    }
    public function reportEnd(TestSuite $suite): void
    {
        $elapsed = round(microtime(true) - $this->startTime, 2);
        $passed = count(array_filter($this->rows, fn($r) => str_starts_with($r, '| ✅')));
        $total = count($this->rows);
        echo "# Test Report: {$suite->name}\n\n";
        echo "**Time**: {$elapsed}s | **Passed**: $passed/$total\n\n";
        echo "| Test | Duration |\n|------|----------|\n";
        echo implode("\n", $this->rows) . "\n";
    }
}
