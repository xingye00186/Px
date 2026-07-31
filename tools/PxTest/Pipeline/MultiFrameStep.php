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

    /**
     * 本步骤的真实前置是**exe 文件存在**，而非“build 步骤在本次管线中成功执行”。
     * 旧声明 requires: ['build'] 把**资源前置**误当成**步骤依赖**，后果：
     *   - 无修正时：--skip-build 下全 56 case 报 0ms err → Passed: 0/56
     *   - 仅修 orchestrator（未注册依赖→跳过）：本步骤被**静默跳过**，
     *     而 exe 明明存在、多帧稳定性检查本应运行 → 能力无声丢失。
     * 治本：requires 仅用于管线内**数据依赖与执行顺序**；资源前置在
     * execute() 内自检。于是 --skip-build 时本步骤照常运行。
     */
    public function requires(): array { return []; }

    public function execute(CaseContext $ctx): StepResult
    {
        $start = microtime(true);
        // 诊断：管线内曾出现 0ms + exit!=0（exe 根本未被执行），而相同命令
        // 手工经 exec() 跑得 exit=0。差异只剩 $exePath 来源，故先校验它。
        if (!is_file($this->exePath)) {
            return StepResult::err('multiframe',
                'exe not found: ' . $this->exePath, (microtime(true) - $start) * 1000);
        }
        $cmd = sprintf('"%s" --case=%s --headless --frame=%d --dump-layout 2>&1',
            $this->exePath, $this->caseName, $this->frames);

        exec($cmd, $output, $exitCode);
        $elapsed = (microtime(true) - $start) * 1000;

        if ($exitCode !== 0) {
            // 诊断：仅报 exit=N 无法定位原因（本步骤曾使全 56 case 均判 FAIL
            // 而无任何线索）。将 exe 尾部输出带入错误详情。
            $tail = array_slice($output, -5);
            $detail = "exit=$exitCode";
            foreach ($tail as $ln) {
                $ln = trim((string)$ln);
                if ($ln !== '') { $detail .= ' | ' . mb_substr($ln, 0, 120); }
            }
            return StepResult::err('multiframe', $detail, $elapsed);
        }
        return StepResult::ok('multiframe', $elapsed);
    }
}
