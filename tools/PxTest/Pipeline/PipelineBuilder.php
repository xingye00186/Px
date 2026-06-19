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
    private ?string $caseName = null;
    private bool $skipBuild = false;
    private bool $forceBuild = false;
    private bool $browserElCompare = true;  // 默认开启浏览器元素对比
    private bool $skipScreenshot = true;  // 默认跳过截图，需 --screenshot 启用
    private bool $updateBaseline = false;
    private bool $verbose = false;
    private string $format = 'console';

    private function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->appDir = $this->projectRoot . '/apps/css-test';
    }

    public static function create(string $projectRoot): self { return new self($projectRoot); }

    /** Parse CLI arguments into builder config. */
    public function parseCli(array $argv): self
    {
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (str_starts_with($arg, '--case=')) { $this->caseName = substr($arg, 7); }
            elseif ($arg === '--skip-build') { $this->skipBuild = true; }
            elseif ($arg === '--force-build') { $this->forceBuild = true; }
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

        // Step 0: Build (hash cache + process lock + orphan cleanup)
        $orchestrator->addStep(new BuildStep($this->projectRoot, 'css-test', $this->forceBuild));

        // Step D: layout export
        $dumpStrategy = $this->selectDumpStrategy();
        $orchestrator->addStep(new Strategy\LayoutDumpStep($dumpStrategy, $this->caseName ?? 'case-001-wrapper-x', $this->appDir));

        // Step E: multi-frame stability
        if ($dumpStrategy instanceof ExeDumpStrategy) {
            $exeDiscovery = new ExeDiscovery($this->appDir);
            $exePath = $exeDiscovery->findExe('css_test.exe');
            if ($exePath) {
                $orchestrator->addStep(new MultiFrameStep($exePath, $this->caseName ?? 'case-001-wrapper-x', 5));
            }
        }

        // Step G+H: 浏览器元素对比（默认跳过，--browser-engine-el-compare 启用）
        if ($this->browserElCompare) {
            $browserStrategy = $this->selectBrowserStrategy();
            $orchestrator->addStep(new Strategy\BrowserRefStep($browserStrategy, $this->appDir, $this->caseName ?? 'case-001-wrapper-x'));
            $caseDir = "{$this->appDir}/test_case/" . ($this->caseName ?? 'case-001-wrapper-x');
            $orchestrator->addStep(new ElementCompareStep(
                \PxTest\Comparison\ComparatorRegistry::default(),
                $caseDir,
                $this->caseName ?? 'case-001-wrapper-x'
            ));
        }

        // Step I: Screenshot comparison (exe headless vs browser headless)
        if (!$this->skipScreenshot) {
            $exeBinDir = "{$this->appDir}/bin";
            // build.bat converts hyphens to underscores in exe name
            $exeName = str_replace('-', '_', $this->appName) . '.exe';
            $exePath = "{$exeBinDir}/{$exeName}";
            $caseHtml = "{$this->appDir}/test_case/" . ($this->caseName ?? 'case-001-wrapper-x') . '/*.html';
            $htmlFiles = glob($caseHtml);
            $htmlPath = !empty($htmlFiles) ? $htmlFiles[0] : '';
            if (file_exists($exePath) && $htmlPath) {
                $refDir = dirname($htmlPath) . '/ref';
                $orchestrator->addStep(new ScreenshotStep(
                    $exePath, $htmlPath, $refDir, $this->caseName ?? 'case-001-wrapper-x'
                ));
            }
        }

        return $orchestrator;
    }

    private function selectDumpStrategy(): Strategy\DumpStrategy
    {
        $exeDiscovery = new ExeDiscovery($this->appDir);
        if ($exeDiscovery->isReady('css_test.exe')) {
            return new ExeDumpStrategy(
                $exeDiscovery->findExe('css_test.exe')
            );
        }
        // Fallback to Mock
        if ($this->verbose) echo "  [INFO] Exe not found, using MockPlatform fallback\n";
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
        $dirs = glob($this->appDir . '/test_case/case-*', GLOB_ONLYDIR);
        sort($dirs);
        return array_map('basename', $dirs);
    }
}
