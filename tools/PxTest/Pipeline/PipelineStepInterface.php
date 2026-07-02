<?php

namespace PxTest\Pipeline;

/**
 * 管道步骤接口 — 每个测试阶段实现此接口。
 */
interface PipelineStepInterface
{
    /** 执行步骤 */
    public function execute(CaseContext $ctx): StepResult;

    /** 步骤名称 */
    public function name(): string;

    /** 前置步骤名称列表（如 ['build']），空数组=无依赖 */
    public function requires(): array;
}
