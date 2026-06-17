<?php

namespace PxTest\Reporting;

use PxTest\Core\TestSuite;
use PxTest\Core\TestResult;

/**
 * TAP 报告器 — Test Anything Protocol v13 (标准 CI 协议)。
 */
class TapReporter implements ReporterInterface
{
    private int $caseIndex = 0;
    /** @var array<string, string> */
    private array $failedCases = [];

    public function reportStart(TestSuite $suite): void
    {
        echo "1..{$suite->caseCount()}\n";
    }

    public function reportCaseResult(string $name, TestResult $result): void
    {
        $this->caseIndex++;
        $ok = $result->isPassed() ? 'ok' : 'not ok';
        echo "$ok {$this->caseIndex} - $name\n";

        if ($result->isFailed()) {
            $this->failedCases[$name] = $result->details['reason'] ?? $result->details['error'] ?? 'unknown';
        }
    }

    public function reportEnd(TestSuite $suite): void
    {
        if (!empty($this->failedCases)) {
            echo "\n# Failed tests:\n";
            foreach ($this->failedCases as $name => $reason) {
                echo "#   $name: $reason\n";
            }
        }
    }
}
