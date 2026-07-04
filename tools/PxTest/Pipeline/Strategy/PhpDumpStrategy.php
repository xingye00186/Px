<?php

namespace PxTest\Pipeline\Strategy;

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockRenderContext;
use PxTest\Bootstrap\GoldenTextWidth;
use Px\Core\Application;
use Px\Core\Scheduler;

/**
 * PhpDumpStrategy — 纯 PHP Runtime 布局导出策略
 *
 * 零编译开销：使用 MockPlatform + MockRenderContext 替代 Win32/Skia，
 * 纯 PHP 执行 VNode 树构建 → LayoutResolver 全流程。
 * 文本测量优先查黄金宽度表获得与浏览器一致的测量值。
 *
 * 前置条件：
 *   - gen/ 组件文件已存在（由 sfc-compiler 生成）
 *   - golden_text_widths.json 已生成
 *
 * 对 AOT 编译模式零影响：此文件不在 AOT 编译范围内。
 */
class PhpDumpStrategy implements DumpStrategy
{
    private string $projectRoot;
    private string $appDir;

    public function __construct(string $projectRoot, string $appDir)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->appDir = rtrim($appDir, '/\\');
    }

    public function name(): string
    {
        return 'php-runtime';
    }

    public function dump(string $caseName, string $refDir): ?array
    {
        // Step 1: 初始化 PHP Runtime 环境
        $bootstrapFile = $this->projectRoot . '/tools/PxTest/Bootstrap/PhpRuntimeBootstrap.php';
        if (file_exists($bootstrapFile)) {
            require_once $bootstrapFile;
            \px_php_runtime_init();
        }

        // Step 2: 初始化黄金宽度表（配置数据文件路径）
        $goldenDataFile = $this->projectRoot . '/tools/PxTest/GoldenMeasure/golden_text_widths.json';
        if (file_exists($goldenDataFile)) {
            if (!class_exists('\\PxTest\\Bootstrap\\GoldenTextWidth', false)) {
                require_once $this->projectRoot . '/tools/PxTest/Bootstrap/GoldenTextWidth.php';
            }
            GoldenTextWidth::setDataFile($goldenDataFile);
        }

        // Step 3: 定义平台常量（main.php 和 gen/ 代码依赖它们）
        if (!defined('APP_PLATFORM'))  define('APP_PLATFORM', 'win32');
        if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 1600);
        if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 800);
        if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'CSS Test');

        // Step 4: 加载框架基础类（gen/ 组件依赖它们）
        // PhpRuntimeBootstrap 已定义 native_types trait
        // 显式加载核心文件（确保依赖顺序）
        $fwDir = $this->projectRoot . '/framework';
        $coreFiles = [
            $fwDir . '/Rendering/VNode.php',
            $fwDir . '/Rendering/RenderNode.php',
            $fwDir . '/Rendering/CssMappings.php',
            $fwDir . '/Rendering/CssValueParser.php',
            $fwDir . '/interfaces/ComponentInterface.php',
            $fwDir . '/interfaces/ReactiveComponentInterface.php',
            $fwDir . '/BaseComponent.php',
            $fwDir . '/ReactiveComponent.php',
            $fwDir . '/Platform/Platform.php',
            $fwDir . '/Platform/PlatformEvent.php',
            $fwDir . '/Platform/MouseEvent.php',
            $fwDir . '/Platform/KeyboardEvent.php',
            $fwDir . '/Platform/WindowEvent.php',
            $fwDir . '/Platform/PlatformFactory.php',
            $fwDir . '/Core/Scheduler.php',
            $fwDir . '/Core/Config.php',
            $fwDir . '/Core/PerfCounter.php',
            $fwDir . '/Core/ScrollManager.php',
            $fwDir . '/Core/Application.php',
            $fwDir . '/Rendering/RenderContext.php',
            $fwDir . '/Rendering/GdiRenderContext.php',
            $fwDir . '/Rendering/TextBackend/ITextBackend.php',
            $fwDir . '/Rendering/TextBackend/GdiTextBackend.php',
            $fwDir . '/Rendering/TextBackend/SkiaTextBackend.php',
            $fwDir . '/Rendering/TextBackend/DWriteTextBackend.php',
            $fwDir . '/Rendering/TextBackend/TextBackendRegistry.php',
            $fwDir . '/Rendering/TextBackend/ResilientTextBackendProxy.php',
            $fwDir . '/Rendering/ImageManager.php',
            $fwDir . '/Rendering/RenderTreeManager.php',
            $fwDir . '/Rendering/VNodeRenderer.php',
            $fwDir . '/Rendering/LayoutResolver.php',
            $fwDir . '/Rendering/Layout/LayoutStrategyInterface.php',
            // Styling/Theme (Application::mount 需要)
            $fwDir . '/Styling/Theme/ColorScheme.php',
            $fwDir . '/Styling/Theme/ComponentTheme.php',
            $fwDir . '/Styling/Theme/TextTheme.php',
            $fwDir . '/Styling/Theme/ThemeData.php',
            $fwDir . '/Styling/Provider/ThemeProvider.php',
            $fwDir . '/Styling/Adapter/PlatformStyling.php',
            $fwDir . '/Styling/Adapter/Win32Styling.php',
            $fwDir . '/Styling/Adapter/MacOSStyling.php',
            $fwDir . '/Styling/Adapter/LinuxStyling.php',
            $fwDir . '/Styling/Adapter/PlatformAdapter.php',
            $fwDir . '/Styling/Resolver/StyleResolver.php',
        ];
        foreach ($coreFiles as $p) {
            if (file_exists($p)) require_once $p;
        }
        // 加载 Layout/ 下所有文件（接口/策略类先于实现类）
        $layoutFiles = glob($fwDir . '/Rendering/Layout/*.php');
        sort($layoutFiles);
        $interfaces = []; $implementations = [];
        foreach ($layoutFiles as $p) {
            $bn = basename($p);
            if (str_contains($bn, 'Interface') || str_contains($bn, 'Strategy')) {
                $interfaces[] = $p;
            } else {
                $implementations[] = $p;
            }
        }
        // 加载 Layout/Tools/ 下所有文件
        $toolFiles = glob($fwDir . '/Rendering/Layout/Tools/*.php');
        sort($toolFiles);
        foreach (array_merge($interfaces, $toolFiles, $implementations) as $p) require_once $p;

        // 递归加载 framework/ 下其余所有 PHP 文件
        $dirIter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fwDir,
                \RecursiveDirectoryIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS)
        );
        foreach ($dirIter as $f) {
            if ($f->getExtension() !== 'php') continue;
            $path = $f->getPathname();
            if (str_contains($path, '/compiler/') || str_contains($path, 'aot-checker')) continue;
            // 跳过 Backend/Styling/TextBackend/Animation/DevTools（纯渲染层，Mock 替代）
            $skipDirs = ['/Backend/', '/Animation/', '/DevTools/'];
            $skipFile = false;
            foreach ($skipDirs as $sd) {
                if (str_contains($path, $sd)) { $skipFile = true; break; }
            }
            if ($skipFile) continue;
            // 跳过已加载的核心文件
            if (in_array($path, $coreFiles, true)) continue;
            require_once $path;
        }

        // Step 5: 构建应用基础设施（MockPlatform 替代 Win32 窗口）
        $platform = new MockPlatform(1600, 800);
        $scheduler = new Scheduler();
        $app = new Application($platform, $scheduler);
        Application::$HEADLESS = true;

        // Step 5: 加载 gen/ 组件文件
        // gen/ 中的 `use native_types;` 是文件级 namespace import，
        // 在 PHP 8.x 下即使该类不存在也编译通过（仅注册别名，未被实际引用）
        $genDir = $this->appDir . '/gen';
        $genFiles = [
            $genDir . '/AppComponent.php',
        ];
        // 扫描 gen/ 下所有组件文件
        foreach (glob($genDir . '/*Component.php') ?: [] as $f) {
            $base = basename($f);
            if ($base !== 'ComponentFactory.php') {
                $genFiles[] = $f;
            }
        }
        foreach (array_unique($genFiles) as $f) {
            if (file_exists($f)) {
                require_once $f;
            }
        }

        // Step 6: 加载 ComponentFactory
        $factoryFile = $genDir . '/ComponentFactory.php';
        if (file_exists($factoryFile)) {
            require_once $factoryFile;
        }

        // Step 7: 创建根组件实例并挂载
        $root = new \AppComponent();
        $app->mount($root, $this->appDir);

        // Step 8: onMount → 填充 caseList → 切换到目标 case
        $app->getScheduler()->flushMicrotasks();
        $root->selectCase($caseName);

        // Step 9: 渲染一帧（VNode → RenderNode → LayoutResolver 全流程）
        $app->getScheduler()->flushMicrotasks();
        $app->render();

        // Step 10: 输出 layout JSON
        @mkdir($refDir, 0777, true);
        $outFile = $refDir . '/engine_layout.json';
        $app->dumpLayoutToFile($outFile, true);

        if (!file_exists($outFile)) {
            echo "  [PhpDumpStrategy] FAILED: $outFile not generated\n";
            return null;
        }

        $json = file_get_contents($outFile);
        echo "  [PhpDumpStrategy] OK: $caseName (" . strlen($json) . " bytes)\n";

        return [$json, $outFile];
    }
}
