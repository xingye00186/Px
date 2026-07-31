<?php

namespace PxTest\Pipeline;

/**
 * 布局导出步骤 — 运行 exe --headless --dump-layout。
 */
class LayoutDumpStep implements PipelineStepInterface
{
    private string $exePath;
    private string $caseName;

    public function __construct(string $exePath, string $caseName = 'case-001-wrapper-x')
    {
        $this->exePath = $exePath;
        $this->caseName = $caseName;
    }

    public function name(): string { return 'dump_layout'; }
    public function requires(): array { return []; }   // 资源前置（exe 存在）在 execute 内自检，不依赖 build **步骤**

    public function execute(CaseContext $ctx): StepResult
    {
        $start = microtime(true);
        if (!is_file($this->exePath)) {
            return StepResult::err('dump_layout', 'exe not found: ' . $this->exePath,
                (microtime(true) - $start) * 1000);
        }
        $cmd = sprintf('"%s" --case=%s --headless --dump-layout 2>&1',
            $this->exePath, $this->caseName);

        $output = [];
        exec($cmd, $output, $exitCode);
        $elapsed = (microtime(true) - $start) * 1000;

        $ctx->set('layout_output', implode("\n", $output));
        $ctx->set('layout_exit', $exitCode);

        return $exitCode === 0
            ? StepResult::ok('dump_layout', $elapsed)
            : StepResult::err('dump_layout', "exit=$exitCode", $elapsed);
    }
}
