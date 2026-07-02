<?php

namespace PxTest\Pipeline;

/**
 * 管道编排器 — 按依赖顺序执行 Steps。
 */
class PipelineOrchestrator
{
    /** @var PipelineStepInterface[] */
    private array $steps = [];

    /** @var array<string, PipelineStepInterface> */
    private array $stepMap = [];

    public function addStep(PipelineStepInterface $step): self
    {
        $this->steps[] = $step;
        $this->stepMap[$step->name()] = $step;
        return $this;
    }

    /**
     * 运行所有步骤（按依赖拓扑排序）。
     * @param CaseContext $ctx case 级上下文（内含 pipeline 级引用）
     * @return StepResult[]
     */
    public function run(CaseContext $ctx): array
    {
        $results = [];
        $completed = [];

        while (count($completed) < count($this->steps)) {
            $progress = false;

            foreach ($this->steps as $step) {
                $name = $step->name();
                if (isset($completed[$name])) continue;

                $depsOk = true;
                foreach ($step->requires() as $req) {
                    if (!isset($completed[$req])) {
                        $depsOk = false;
                        break;
                    }
                    if (!$completed[$req]->passed) {
                        $results[] = StepResult::err($name, "skipped: dependency '$req' failed");
                        $completed[$name] = StepResult::err($name, "skipped");
                        $depsOk = false;
                        break;
                    }
                }

                if (!$depsOk) continue;

                $start = microtime(true);
                try {
                    $result = $step->execute($ctx);
                } catch (\Throwable $e) {
                    $result = StepResult::err($name, $e->getMessage());
                }
                $elapsed = (microtime(true) - $start) * 1000;
                $finalResult = new StepResult($name, $result->passed, $result->errors, $elapsed);

                $results[] = $finalResult;
                $completed[$name] = $finalResult;
                $progress = true;
            }

            if (!$progress) {
                foreach ($this->steps as $step) {
                    if (!isset($completed[$step->name()])) {
                        $results[] = StepResult::err($step->name(), 'unmet dependency cycle');
                        $completed[$step->name()] = StepResult::err($step->name(), 'cycle');
                    }
                }
                break;
            }
        }

        return $results;
    }

    public function getStepNames(): array
    {
        return array_map(fn($s) => $s->name(), $this->steps);
    }
}
