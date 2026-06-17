<?php

namespace PxTest\Reporting;

use PxTest\Core\TestSuite;
use PxTest\Core\TestResult;

/**
 * 报告器统一接口 — 支持多种输出格式。
 */
interface ReporterInterface
{
    public function reportStart(TestSuite $suite): void;
    public function reportCaseResult(string $name, TestResult $result): void;
    public function reportEnd(TestSuite $suite): void;
}
