<?php

namespace PxTest\Pipeline;

/** 交互模拟测试步骤 */
class InteractionStep implements PipelineStepInterface
{
    public function name(): string { return 'interaction'; }
    public function requires(): array { return []; }
    public function execute(CaseContext $ctx): StepResult
    {
        return StepResult::ok('interaction');
    }
}
