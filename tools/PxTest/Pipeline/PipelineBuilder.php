<?php

namespace PxTest\Pipeline;

// Preload Strategy classes (defined in same-file for cohesion)
require_once __DIR__ . '/Strategy/DumpStrategy.php';
require_once __DIR__ . '/Strategy/BrowserRefStrategy.php';
require_once __DIR__ . '/Strategy/PipelineSteps.php';

use PxTest\Pipeline\Strategy\DumpStrategy;
use PxTest\Pipeline\Strategy\ExeDumpStrategy;
use PxTest\Pipeline\Strategy\MockDumpStrategy;
use PxTest\Pipeline\Strategy\NoopDumpStrategy;
use PxTest\Pipeline\Strategy\BrowserRefStrategy;
use PxTest\Pipeline\Strategy\EdgeDomStrategy;
use PxTest\Pipeline\Strategy\NoopBrowserRefStrategy;
use PxTest\Infrastructure\ExeDiscovery;
use PxTest\Infrastructure\BrowserLauncher;

/**
 * Pipeline Builder — builds Pipeline from CLI arguments.
 *
 * Usage:
 *   $builder = PipelineBuilder::create($projectRoot);
 *   $builder->parseCli($argv);
 *   $orchestrator = $builder->build();
 *   $results = $orchestrator->run();
 */
class PipelineBuilder
{
    private string $projectRoot;
    private string $appDir;
    private string $appName = 'css-test';
    private string $casePrefix = 'case-';  // case-* for css-test, prt-* for php-rt-test
    private ?string $caseName = null;
    private bool $skipBuild = false;
    private bool $forceBuild = false;
    private bool $forceInit = false;
    private bool $usePhpRuntime = false;
    private bool $browserElCompare = true;
    private bool $skipScreenshot = true;
    private bool $updateBaseline = false;
    private bool $verbose = false;
    private string $format = 'console';

    private function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->appDir = $this->projectRoot . '/apps/css-test';
    }

    public static function create(string $projectRoot): self { return new self($projectRoot); }

    /** 设置应用名（同时更新 appDir 和 case 前缀） */
    public function setAppName(string $name): self
    {
        $this->appName = $name;
        $this->appDir = $this->projectRoot . '/apps/' . $name;
        // php-rt-test 使用 prt-* 前缀，css-test 使用 case-* 前缀
        $this->casePrefix = ($name === 'php-rt-test') ? 'prt-' : 'case-';
        return $this;
    }

    public function getDefaultCaseName(): string {
        return $this->casePrefix === 'prt-' ? 'prt-01-margin' : 'case-001-wrapper-x';
    }

    public function getExeName(): string {
        return str_replace('-', '_', $this->appName) . '.exe';
    }

    /** Parse CLI arguments into builder config. */
    public function parseCli(array $argv): self
    {
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (str_starts_with($arg, '--case=')) { $this->caseName = substr($arg, 7); }
            elseif (str_starts_with($arg, '--app=')) { $this->setAppName(substr($arg, 6)); }
            elseif ($arg === '--skip-build') { $this->skipBuild = true; }
            elseif ($arg === '--force-build') { $this->forceBuild = true; }
            elseif ($arg === '--force-init') { $this->forceInit = true; }
            elseif ($arg === '--php-runtime') { $this->usePhpRuntime = true; }
            elseif ($arg === '--browser-engine-el-compare') { $this->browserElCompare = true; }
            elseif ($arg === '--screenshot') { $this->skipScreenshot = false; }
            elseif ($arg === '--update-baseline') { $this->updateBaseline = true; }
            elseif ($arg === '--verbose') { $this->verbose = true; }
            elseif (str_starts_with($arg, '--format=')) { $this->format = substr($arg, 9); }
        }
        return $this;
    }

    /** Build PipelineOrchestrator from current config. */
    public function build(): PipelineOrchestrator
    {
        $orchestrator = new PipelineOrchestrator();

        // Step A: data-px-id 全量预处理（先于构建，确保 .vue 已生成）
        $orchestrator->addStep(new \PxTest\Pipeline\Strategy\PxIdGenerateStep($this->appDir, $this->casePrefix));

        // Step 0: Build (hash cache + process lock + orphan cleanup)
        if ($this->skipBuild) {
            echo "  [{$this->appName}] Skip build (--skip-build)\n";
        } elseif (!$this->usePhpRuntime) {
            $orchestrator->addStep(new BuildStep($this->projectRoot, $this->appName, $this->forceBuild));
        } else {
            echo "  [{$this->appName}] Skip build, using PHP native layout computation\n";
        }

        // Step B: 自动批次 browser ref（全量模式无 --case= 时启用）
        // 单次 Edge 启动为所有 case 生成 ref，比逐 case 启动快 20x
        // --case=xxx 单 case 模式保持逐个生成（更快更精确）
        if ($this->caseName === null && $this->browserElCompare) {
            $browser = new BrowserLauncher();
            $strategy = $browser->isAvailable() ? new EdgeDomStrategy($browser) : new NoopBrowserRefStrategy();
            $caseDirs = glob($this->appDir . '/test_case/' . $this->casePrefix . '*', GLOB_ONLYDIR);
            $allCases = [];
            foreach ($caseDirs as $dir) {
                $tag = basename($dir);
                $htmlFiles = glob("$dir/*.html");
                if (empty($htmlFiles)) continue;
                $allCases[] = [
                    'tag' => $tag,
                    'htmlPath' => $htmlFiles[0],
                    'refDir' => "$dir/ref",
                ];
            }
            if (!empty($allCases)) {
                $orchestrator->addStep(new \PxTest\Pipeline\Strategy\BatchBrowserRefStep($strategy, $allCases));
            }
        }

        // Step D: layout export
        $dumpStrategy = $this->selectDumpStrategy();
        $orchestrator->addStep(new Strategy\LayoutDumpStep($dumpStrategy, $this->caseName ?? $this->getDefaultCaseName(), $this->appDir));

        // Phase L: CSS layout assertions on engine tree (after dump, before browser)
        $orchestrator->addStep(new LayoutValidationStep());

        // Step E: multi-frame stability
        if ($dumpStrategy instanceof ExeDumpStrategy) {
            $exeDiscovery = new ExeDiscovery($this->appDir);
            $exePath = $exeDiscovery->findExe($this->getExeName());
            if ($exePath) {
                $orchestrator->addStep(new MultiFrameStep($exePath, $this->caseName ?? $this->getDefaultCaseName(), 5));
            }
        }

        // Step G+H: 浏览器元素对比（默认跳过，--browser-engine-el-compare 启用）
        if ($this->browserElCompare) {
            $browserStrategy = $this->selectBrowserStrategy();
            $orchestrator->addStep(new \PxTest\Pipeline\BrowserRefStep($browserStrategy, $this->appDir, $this->caseName ?? $this->getDefaultCaseName()));
            $caseDir = "{$this->appDir}/test_case/" . ($this->caseName ?? $this->getDefaultCaseName());
            $orchestrator->addStep(new ElementCompareStep(
                \PxTest\Comparison\ComparatorRegistry::default(),
                $caseDir,
                $this->caseName ?? $this->getDefaultCaseName()
            ));
        }

        // Step I: Screenshot comparison (exe headless vs browser headless)
        if (!$this->skipScreenshot) {
            $exeBinDir = "{$this->appDir}/bin";
            $exePath = "{$exeBinDir}/" . $this->getExeName();
            $caseHtml = "{$this->appDir}/test_case/" . ($this->caseName ?? $this->getDefaultCaseName()) . '/*.html';
            $htmlFiles = glob($caseHtml);
            $htmlPath = !empty($htmlFiles) ? $htmlFiles[0] : '';
            if (file_exists($exePath) && $htmlPath) {
                $refDir = dirname($htmlPath) . '/ref';
                $orchestrator->addStep(new ScreenshotStep(
                    $exePath, $htmlPath, $refDir, $this->caseName ?? $this->getDefaultCaseName()
                ));
            }
        }

        return $orchestrator;
    }

    private function selectDumpStrategy(): Strategy\DumpStrategy
    {
        if ($this->usePhpRuntime) {
            return new Strategy\PhpDumpStrategy($this->projectRoot, $this->appDir);
        }

        $exeName = $this->getExeName();
        $exeDiscovery = new ExeDiscovery($this->appDir);
        if ($exeDiscovery->isReady($exeName)) {
            return new ExeDumpStrategy($exeDiscovery->findExe($exeName));
        }
        if ($this->verbose) echo "  [INFO] Exe $exeName not found, using MockPlatform fallback\n";
        return new MockDumpStrategy();
    }

    private function selectBrowserStrategy(): Strategy\BrowserRefStrategy
    {
        $browser = new BrowserLauncher();
        if (!$browser->isAvailable()) return new NoopBrowserRefStrategy();
        // 浏览器参考数据始终用 EdgeDomStrategy（dump_layout.js 提取元素位置）
        // 截图由 ScreenshotStep 独立控制，与 selectBrowserStrategy 无关
        return new EdgeDomStrategy($browser);
    }

    /** Scan all case directories under test_case/. */
    public function getAllCases(): array
    {
        $dirs = glob($this->appDir . '/test_case/' . $this->casePrefix . '*', GLOB_ONLYDIR);
        sort($dirs);
        return array_map('basename', $dirs);
    }

    /** --force-init: 清理所有 case-* 目录下的生成文件，仅保留 .html 和 .bat */
    public function isForceInit(): bool { return $this->forceInit; }

    public function getCasePrefix(): string { return $this->casePrefix; }

    public function getAppDir(): string { return $this->appDir; }

    /**
     * 执行 --force-init 清理：删除 case-* 下除 .html/.bat 外的所有文件，
     * 以及 ref/ 和 baseline/ 子目录内容。
     */
    public function forceInitClean(): int
    {
        $caseDirs = glob($this->appDir . '/test_case/' . $this->casePrefix . '*', GLOB_ONLYDIR);
        if (empty($caseDirs)) return 0;

        $keepExtensions = ['html', 'bat'];
        $cleanDirs = ['ref', 'baseline'];
        $cleaned = 0;

        foreach ($caseDirs as $dir) {
            // 清理 ref/ 和 baseline/ 子目录内容
            foreach ($cleanDirs as $sub) {
                $subDir = "$dir/$sub";
                if (is_dir($subDir)) {
                    $files = glob("$subDir/*");
                    foreach ($files as $f) {
                        if (is_file($f)) { @unlink($f); $cleaned++; }
                    }
                }
            }

            // 删除非 .html/.bat 文件（含 .vue, .pxid_done, .json 等）
            $entries = scandir($dir);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $path = "$dir/$entry";
                if (!is_file($path)) continue;
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($ext, $keepExtensions, true)) continue;
                @unlink($path);
                $cleaned++;
            }
        }

        return $cleaned;
    }
}
