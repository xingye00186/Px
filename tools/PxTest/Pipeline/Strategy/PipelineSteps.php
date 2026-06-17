<?php

namespace PxTest\Pipeline\Strategy;

use PxTest\Pipeline\PipelineStepInterface;
use PxTest\Pipeline\PipelineContext;
use PxTest\Pipeline\StepResult;

/** Strategy-aware LayoutDumpStep */
class LayoutDumpStep implements PipelineStepInterface
{
    public function __construct(
        private DumpStrategy $strategy,
        private string $caseName,
        private string $appDir,
    ) {}
    public function name(): string { return 'dump_layout'; }
    public function requires(): array { return []; }
    public function execute(PipelineContext $ctx): StepResult
    {
        $refDir = "{$this->appDir}/test_case/{$this->caseName}/ref";
        $result = $this->strategy->dump($this->caseName, $refDir);
        if ($result !== null) {
            $ctx->set('layout_json', $result[0]);
            $ctx->set('layout_path', $result[1]);
            return StepResult::ok('dump_layout');
        }
        return StepResult::err('dump_layout', 'Strategy ' . $this->strategy->name() . ' failed');
    }
}

/** Strategy-aware BrowserRefStep */
class BrowserRefStep implements PipelineStepInterface
{
    public function __construct(
        private BrowserRefStrategy $strategy,
        private string $appDir,
        private string $caseName,
    ) {}
    public function name(): string { return 'browser_ref'; }
    public function requires(): array { return []; }
    public function execute(PipelineContext $ctx): StepResult
    {
        $caseDir = "{$this->appDir}/test_case/{$this->caseName}";
        $htmlFiles = glob("$caseDir/*.html");
        if (empty($htmlFiles)) return StepResult::err('browser_ref', 'No HTML file found');
        $refDir = "$caseDir/ref";
        $ok = $this->strategy->generate($htmlFiles[0], $refDir, $this->caseName);
        return $ok ? StepResult::ok('browser_ref') : StepResult::err('browser_ref', 'Strategy ' . $this->strategy->name() . ' failed');
    }
}
