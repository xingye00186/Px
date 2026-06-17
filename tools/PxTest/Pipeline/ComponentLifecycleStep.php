<?php

namespace PxTest\Pipeline;

/** 组件生命周期测试步骤 */
class ComponentLifecycleStep implements PipelineStepInterface
{
    public function name(): string { return 'component_lifecycle'; }
    public function requires(): array { return []; }
    public function execute(PipelineContext $ctx): StepResult
    {
        return StepResult::ok('component_lifecycle');
    }
}
