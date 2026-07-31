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
        // 本次管线**已注册**的步骤名集合：用于区分“依赖待执行”与
        // “依赖根本未注册”（如 --skip-build / PHP-runtime 下不加 BuildStep）。
        $registered = [];
        foreach ($this->steps as $s) { $registered[$s->name()] = true; }

        while (count($completed) < count($this->steps)) {
            $progress = false;

            foreach ($this->steps as $step) {
                $name = $step->name();
                if (isset($completed[$name])) continue;

                $depsOk = true;
                foreach ($step->requires() as $req) {
                    if (!isset($completed[$req])) {
                        // 依赖**未在本次管线中**（而非失败）。两种情形：
                        //  a) 尚未执行 → 本轮跳过，下一轮拓扑迭代再试（原有语义）
                        //  b) 步骤根本未注册（如 --skip-build / PHP-runtime 下不加 BuildStep）
                        //     → 永远不会出现，此时必须当作**跳过**而非失败。
                        // 旧行为：轮次耗尽后遗留未完成步骤，在尾部被当成失败，
                        // 导致 `--skip-build` 下 multiframe 对**全 56 case** 均报
                        // 0ms err → Passed: 0/56（而 PHP-runtime 模式下同位置为
                        // ⬛跳过、判通过）。修正：区分“待执行”与“未注册”。
                        if (!isset($registered[$req])) {
                            $skipped = StepResult::ok($name, 0.0);
                            $results[] = $skipped;
                            $completed[$name] = $skipped;
                            $progress = true;
                        }
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
