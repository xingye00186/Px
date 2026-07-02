<?php

namespace PxTest\Pipeline;

/**
 * 多帧稳定性步骤 — 运行 exe --dump-layout-after-frames。
 */
class MultiFrameStep implements PipelineStepInterface
{
    private string $exePath;
    private string $caseName;
    private int $frames;

    public function __construct(string $exePath, string $caseName = 'case-001-wrapper-x', int $frames = 5)
    {
        $this->exePath = $exePath;
        $this->caseName = $caseName;
        $this->frames = $frames;
    }

    public function name(): string { return 'multiframe'; }
    public function requires(): array { return ['build']; }

    public function execute(CaseContext $ctx): StepResult
    {
        $start = microtime(true);
        $cmd = sprintf('"%s" --case=%s --headless --frame=%d --dump-layout 2>&1',
            $this->exePath, $this->caseName, $this->frames);

        exec($cmd, $output, $exitCode);
        $elapsed = (microtime(true) - $start) * 1000;

        return $exitCode === 0
            ? StepResult::ok('multiframe', $elapsed)
            : StepResult::err('multiframe', "exit=$exitCode", $elapsed);
    }
}
