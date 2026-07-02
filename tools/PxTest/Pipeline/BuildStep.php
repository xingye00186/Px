<?php

namespace PxTest\Pipeline;

use PxTest\Infrastructure\ProcessManager;

/**
 * Build step - full build flow with hash cache, process lock, orphan cleanup.
 */
class BuildStep implements PipelineStepInterface
{
    /** 进程级构建标记：确保 --force-build 下也只编一次，后续 case 循环直接跳过 */
    private static bool $buildAlreadyDone = false;

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

    public function execute(CaseContext $ctx): StepResult
    {
        $start = microtime(true);

        // 进程级构建去重：force-build 也只编一次，后续 case 循环直接跳过
        if (self::$buildAlreadyDone) {
            return StepResult::ok('build', (microtime(true) - $start) * 1000);
        }

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
                self::$buildAlreadyDone = true;
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
            self::$buildAlreadyDone = true;
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
        $stubDir      = "{$this->projectRoot}/stub";
        $configFile   = "{$this->projectRoot}/config.yml";

        $files = [];
        // 递归扫描所有 framework PHP 文件（替代硬编码子目录列表）
        $files = array_merge($files, glob("$frameworkDir/**/*.php") ?: []);
        // 应用层源码
        $files = array_merge($files, glob("$appDir/**/*.vue") ?: []);
        $files = array_merge($files, glob("$appDir/**/*.php") ?: []);
        // C++ 原生层
        $files = array_merge($files, glob("$cppDir/*.cc") ?: []);
        $files = array_merge($files, glob("$cppDir/*.h") ?: []);
        // PHP stub 声明
        $files = array_merge($files, glob("$stubDir/*.php") ?: []);
        // 构建配置
        if (file_exists($configFile)) {
            $files[] = $configFile;
        }

        $hashes = '';
        foreach ($files as $f) {
            $hashes .= md5_file($f);
        }
        return md5($hashes);
    }
}
