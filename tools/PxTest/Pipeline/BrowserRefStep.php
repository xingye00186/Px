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

    public function execute(CaseContext $ctx): StepResult
    {
        // 从上下文获取当前 case 名（全量运行时每个 case 独立设置）
        $ctxCase = $ctx->get('case_name');
        $currentCase = ($ctxCase !== null && $ctxCase !== '') ? $ctxCase : $this->caseName;

        // 检查 batch 模式是否已完成（pipeline 级标记，跨 case 持久）
        if ($ctx->pipeline()->get('batch_ref_done', false)) {
            $refFile = "{$this->appDir}/test_case/{$currentCase}/ref/browser_ref_level_0.json";
            if (file_exists($refFile)) {
                $ctx->set('browser_ref', file_get_contents($refFile));
            }
            return StepResult::ok('browser_ref', 0);
        }

        $start = microtime(true);
        $caseDir = "{$this->appDir}/test_case/{$currentCase}";
        $htmlFiles = glob("$caseDir/*.html");
        if (empty($htmlFiles)) {
            return StepResult::err('browser_ref', 'No .html file found');
        }
        $htmlPath = $htmlFiles[0];
        $refDir = "$caseDir/ref";

        $ok = $this->strategy->generate($htmlPath, $refDir, $currentCase);
        $elapsed = (microtime(true) - $start) * 1000;

        if ($ok) {
            $refFile = "$refDir/browser_ref_level_0.json";
            if (file_exists($refFile)) {
                $ctx->set('browser_ref', file_get_contents($refFile));
            }
            echo "  [browser_ref] OK ({$elapsed}ms)\n";
            return StepResult::ok('browser_ref', $elapsed);
        }
        return StepResult::err('browser_ref', 'Browser ref generation failed', $elapsed);
    }
}
