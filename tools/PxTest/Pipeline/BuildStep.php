<?php

namespace PxTest\Pipeline;

use PxTest\Infrastructure\ProcessManager;

/**
 * Build step - full build flow with hash cache, process lock, orphan cleanup.
 */
class BuildStep implements PipelineStepInterface
{
    private string $projectRoot;
    private string $appName;
    private ProcessManager $processMgr;
    private bool $forceBuild;

    public function __construct(
        string $projectRoot,
        string $appName = 'css-test',
        bool $forceBuild = false,
        ?ProcessManager $processMgr = null,
    ) {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->appName = $appName;
        $this->forceBuild = $forceBuild;
        $this->processMgr = $processMgr ?? new ProcessManager($this->projectRoot);
    }

    public function name(): string { return 'build'; }
    public function requires(): array { return []; }

    public function execute(PipelineContext $ctx): StepResult
    {
        $start = microtime(true);

        // appName may contain hyphens; actual exe uses underscores (build.bat converts them)
        $exeName  = str_replace('-', '_', $this->appName) . '.exe';
        $exePath  = "{$this->projectRoot}/apps/{$this->appName}/bin/{$exeName}";
        $hashFile = "{$this->projectRoot}/apps/{$this->appName}/.build_hash";

        // Smart skip: exe exists + hash unchanged + not forced
        if (!$this->forceBuild && file_exists($exePath) && file_exists($hashFile)) {
            $currentHash = $this->computeHash();
            $prevHash = trim(@file_get_contents($hashFile) ?: '');
            if ($currentHash === $prevHash) {
                $ctx->set('exe_path', $exePath);
                echo "  [skip] Source unchanged, skip build\n";
                return StepResult::ok('build', (microtime(true) - $start) * 1000);
            }
        }

        // Cleanup orphans
        $this->processMgr->killOrphanProcesses();
        $this->processMgr->cleanupProcessRegistry();
        $this->processMgr->registerSignalHandler();

        // Acquire build lock
        if (!$this->processMgr->acquireBuildLock()) {
            return StepResult::err('build', 'Build lock conflict - swoole_compiler already running');
        }

        // Execute build (使用临时日志文件避免 Windows 管道缓冲区死锁)
        echo "  [build] {$this->appName} ...\n";
        $buildScript = "{$this->projectRoot}/build.bat";
        $logFile = tempnam(sys_get_temp_dir(), 'css_build_');
        $cmd = sprintf('"%s" %s > "%s" 2>&1', $buildScript, $this->appName, $logFile);
        exec($cmd, $_output, $buildExit);
        $buildOut = file_get_contents($logFile);
        @unlink($logFile);

        $this->processMgr->releaseBuildLock();
        $elapsed = (microtime(true) - $start) * 1000;

        if ($buildExit === 0) {
            @file_put_contents($hashFile, $this->computeHash());
            $ctx->set('exe_path', $exePath);
            $ctx->set('build_output', $buildOut);
            echo "  [build] OK\n";
            return StepResult::ok('build', $elapsed);
        }

        echo "  [build] Build failed (exit=$buildExit)\n";
        echo "  [build] Output:\n$buildOut\n";
        return StepResult::err('build', "Build failed (exit=$buildExit)", $elapsed);
    }

    private function computeHash(): string
    {
        $frameworkDir = "{$this->projectRoot}/framework";
        $appDir       = "{$this->projectRoot}/apps/{$this->appName}";
        $cppDir       = "{$this->projectRoot}/cpp";

        $files = [];
        foreach (['Core','Rendering','Rendering/Layout','Platform','Styling','compiler'] as $sub) {
            $files = array_merge($files, glob("$frameworkDir/$sub/*.php") ?: []);
        }
        $files = array_merge($files, glob("$appDir/**/*.vue") ?: []);
        $files = array_merge($files, glob("$appDir/**/*.php") ?: []);
        $files = array_merge($files, glob("$cppDir/*.cc") ?: []);

        $hashes = '';
        foreach ($files as $f) {
            $hashes .= md5_file($f);
        }
        return md5($hashes);
    }
}
