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

        // Step 4: 构建应用基础设施（MockPlatform 替代 Win32 窗口）
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
        $app->dumpLayoutToFile($outFile);

        if (!file_exists($outFile)) {
            echo "  [PhpDumpStrategy] FAILED: $outFile not generated\n";
            return null;
        }

        $json = file_get_contents($outFile);
        echo "  [PhpDumpStrategy] OK: $caseName (" . strlen($json) . " bytes)\n";

        return [$json, $outFile];
    }
}
