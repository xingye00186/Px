<?php

namespace PxTest\Pipeline;

use PxTest\Infrastructure\BrowserLauncher;

/** 浏览器参考生成步骤（Edge headless） */
class BrowserRefStep implements PipelineStepInterface
{
    private BrowserLauncher $browser;
    public function __construct(BrowserLauncher $browser = null) { $this->browser = $browser ?? new BrowserLauncher(); }
    public function name(): string { return 'browser_ref'; }
    public function requires(): array { return ['build']; }
    public function execute(PipelineContext $ctx): StepResult
    {
        $available = $this->browser->isAvailable();
        return $available ? StepResult::ok('browser_ref') : StepResult::err('browser_ref', 'Edge not available');
    }
}
