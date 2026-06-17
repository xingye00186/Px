<?php

namespace PxTest\Pipeline;

use PxTest\Comparison\ComparatorRegistry;

/** 元素对比步骤（引擎 vs 浏览器） */
class ElementCompareStep implements PipelineStepInterface
{
    private ComparatorRegistry $registry;
    public function __construct(ComparatorRegistry $registry = null) { $this->registry = $registry ?? ComparatorRegistry::default(); }
    public function name(): string { return 'element_compare'; }
    public function requires(): array { return ['browser_ref']; }
    public function execute(PipelineContext $ctx): StepResult
    {
        $layout = $ctx->get('layout_output');
        $ref = $ctx->get('browser_ref');
        if ($layout === null || $ref === null) return StepResult::err('element_compare', 'Missing data');
        $results = $this->registry->compareAll(['output' => $layout], ['output' => $ref], new \PxTest\Core\ToleranceConfig());
        $allPassed = !in_array(false, array_map(fn($r) => $r->passed, $results));
        return $allPassed ? StepResult::ok('element_compare') : StepResult::err('element_compare', 'Differences found');
    }
}
