<?php

namespace PxTest\Pipeline;

use PxTest\Pipeline\Strategy\BrowserRefStrategy;

/** 浏览器参考生成步骤（单 case 模式，Edge headless） */
class BrowserRefStep implements PipelineStepInterface
{
    private BrowserRefStrategy $strategy;
    private string $appDir;
    private string $caseName;

    public function __construct(BrowserRefStrategy $strategy, string $appDir, string $caseName)
    {
        $this->strategy = $strategy;
        $this->appDir = $appDir;
        $this->caseName = $caseName;
    }

    public function name(): string { return 'browser_ref'; }
    public function requires(): array { return []; }

    public function execute(PipelineContext $ctx): StepResult
    {
        if ($ctx->get('batch_ref_done', false)) {
            return StepResult::ok('browser_ref', 0);
        }

        $start = microtime(true);
        $htmlFiles = glob("{$this->appDir}/test_case/{$this->caseName}/*.html");
        if (empty($htmlFiles)) {
            return StepResult::err('browser_ref', 'No .html file found');
        }
        $htmlPath = $htmlFiles[0];
        $refDir = dirname($htmlPath) . '/ref';

        $caseData = [['tag' => $this->caseName, 'htmlPath' => $htmlPath, 'refDir' => $refDir]];
        $ok = $this->strategy->generateBatch($caseData);
        $elapsed = (microtime(true) - $start) * 1000;

        if ($ok) {
            echo "  [browser_ref] OK ({$elapsed}ms)\n";
            return StepResult::ok('browser_ref', $elapsed);
        }
        return StepResult::err('browser_ref', 'Browser ref generation failed', $elapsed);
    }
}
