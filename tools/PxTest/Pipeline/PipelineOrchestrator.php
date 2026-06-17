<?php

namespace PxTest\Pipeline;

/**
 * 管道编排器 — 按依赖顺序执行 Steps。
 *
 * 用法:
 *   $orchestrator = new PipelineOrchestrator();
 *   $orchestrator->addStep(new BuildStep(...));
 *   $orchestrator->addStep(new LayoutDumpStep(...));
 *   $orchestrator->addStep(new ScreenshotStep(...));
 *   $results = $orchestrator->run();
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
     * @return StepResult[]
     */
    public function run(PipelineContext $ctx = null): array
    {
        $ctx = $ctx ?? new PipelineContext();
        $results = [];
        $completed = [];

        while (count($completed) < count($this->steps)) {
            $progress = false;

            foreach ($this->steps as $step) {
                $name = $step->name();
                if (isset($completed[$name])) continue;

                // 检查前置依赖是否都已完成
                $depsOk = true;
                foreach ($step->requires() as $req) {
                    if (!isset($completed[$req])) {
                        $depsOk = false;
                        break;
                    }
                    // 前置步骤失败 → 当前步骤跳过
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

                if (!$finalResult->passed) {
                    // 默认行为：失败不阻断后续独立步骤
                    // （可通过 ctx 设置 failFast 改变此行为）
                }
            }

            if (!$progress) {
                // 循环依赖或不可达
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

    /** 获取所有步骤名称 */
    public function getStepNames(): array
    {
        return array_map(fn($s) => $s->name(), $this->steps);
    }
}
