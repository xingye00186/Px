<?php
/**
 * PipelineTestBase — 完整渲染管线测试基类
 *
 * 提供 StubPlatform 替代 Win32 Platform，让 Application 在无窗口环境下
 * 走完完整渲染管线（render → rebuildVNodeTree → updateFromVNode → LayoutResolver → dumpRenderTree）。
 *
 * 子类只需实现具体组件的快照断言。
 *
 * Usage:
 *   class MySnapshotTest extends PipelineTestBase {
 *       public function testLayout(): void {
 *           $snapshot = self::captureSnapshot(__DIR__ . '/../../apps/my-app');
 *           $this->assertNodeContains($snapshot, 'w=340 h=660');
 *       }
 *   }
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Core\Scheduler;
use Px\Core\Application;
use Px\Platform\Platform;
use Px\Paint\RenderContext;

// ---- 额外需要的框架文件（不在 bootstrap.php 中） ----
$fw = dirname(__DIR__, 2) . '/framework';
require_once $fw . '/Core/ScrollManager.php';
require_once $fw . '/Styling/Adapter/PlatformStyling.php';
require_once $fw . '/Styling/Adapter/Win32Styling.php';
require_once $fw . '/Styling/Adapter/PlatformAdapter.php';

// ──────────────────────────────────────────────────
// StubPlatform：替代 Win32Platform，无窗口环境
// ──────────────────────────────────────────────────
class StubPlatform implements Platform
{
    private int $width;
    private int $height;

    public function __construct(int $w, int $h)
    {
        $this->width = $w;
        $this->height = $h;
    }

    public function init(string $title, int $width, int $height): RenderContext
    {
        return new class extends RenderContext
        {
            public function beginFrame(): void {}
            public function endFrame(): void {}
            public function drawElement(array $el): void {}
            public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
            public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void {}
            public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
        };
    }

    public function getHwnd(): int { return 0; }
    public function shutdown(): void {}
    public function shouldClose(): bool { return false; }
    public function pollEvents(): array { return []; }
    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void {}
    public function setCursor(string $cursor): void {}
}

// ──────────────────────────────────────────────────
// 通用基类
// ──────────────────────────────────────────────────
abstract class PipelineTestBase
{
    /**
     * 创建 StubPlatform 实例（子类可重写以自定义尺寸）
     */
    protected static function createStubPlatform(int $w, int $h): StubPlatform
    {
        return new StubPlatform($w, $h);
    }

    /**
     * 加载应用的 gen/ 目录下的所有 PHP 文件。
     */
    protected static function loadAppGenFiles(string $appDir): void
    {
        $genDir = $appDir . '/gen';
        if (!is_dir($genDir)) {
            throw new \RuntimeException("gen directory not found: $genDir");
        }
        $files = glob($genDir . '/*.php');
        sort($files);
        foreach ($files as $file) {
            if (basename($file) !== '.dep-cache.json') {
                require_once $file;
            }
        }
    }

    /**
     * 走完整管线并返回 dumpRenderTree 快照文本。
     *
     * @param string $appDir 应用目录绝对路径（包含 main.php 和 gen/）
     * @return string 快照文本
     */
    protected static function captureSnapshot(string $appDir): string
    {
        $appDir = realpath($appDir);
        if ($appDir === false) {
            throw new \RuntimeException("App directory not found");
        }

        // 步骤1: 加载应用的 main.php（定义 APP_PLATFORM, WINDOW_WIDTH, WINDOW_HEIGHT 等常量）
        // 注意：main.php 只定义常量和声明 main() 函数，不自动执行
        require_once "$appDir/main.php";

        // 步骤2: 加载 gen/ 目录下的所有组件文件（ComponentFactory + 各组件类）
        self::loadAppGenFiles($appDir);

        // 步骤3: 创建 StubPlatform 和 Application
        $platform = self::createStubPlatform(WINDOW_WIDTH, WINDOW_HEIGHT);
        $scheduler = new Scheduler();
        $app = new Application($platform, $scheduler);

        // 步骤4: 挂载根组件
        $root = \ComponentFactory::create(AppComponent::class);
        $app->mount($root);

        // 步骤5: 调用 private render() — 使用反射
        $rm = new \ReflectionMethod(Application::class, 'render');
        $rm->setAccessible(true);
        $rm->invoke($app);

        // 步骤6: 获取 dumpRenderTree 输出
        $rtm = $app->getRenderTreeManager();
        $rootNode = $rtm->getRootRenderNode();

        if ($rootNode === null) {
            return '';
        }

        return $rtm->dumpRenderTree($rootNode, 1, []);
    }

    /**
     * 断言快照文本包含预期子串。
     */
    protected static function assertNodeContains(string $snapshot, string $expected): void
    {
        assert_contains($snapshot, $expected);
    }

    /**
     * 全量快照对比：将当前 dumpRenderTree 输出与基线 .snap 文件逐字符对比。
     *
     * - 基线存在 → assertSame 逐字符比较
     * - 基线不存在 → 提示先运行 --update-snapshots 创建
     * - 传了 --update-snapshots 标志 → 覆写基线文件
     *
     * @param string $snapshot    当前管线输出的 dumpRenderTree 文本
     * @param string $snapFilePath .snap 基线文件的绝对路径
     */
    protected static function assertSnapshotMatches(string $snapshot, string $snapFilePath): void
    {
        // 检测 --update-snapshots CLI 标志
        $update = false;
        foreach (($_SERVER['argv'] ?? []) as $arg) {
            if ($arg === '--update-snapshots') {
                $update = true;
                break;
            }
        }

        if ($update) {
            $dir = dirname($snapFilePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($snapFilePath, $snapshot);
            echo "  [UPDATED] snapshot: $snapFilePath\n";
            return;
        }

        if (!file_exists($snapFilePath)) {
            echo "  [MISSING] snapshot file not found: $snapFilePath\n";
            echo "  Run with --update-snapshots to create it.\n";
            return;
        }

        $baseline = file_get_contents($snapFilePath);
        if ($snapshot !== $baseline) {
            $baselineLines = explode("\n", $baseline);
            $snapshotLines = explode("\n", $snapshot);
            $maxLines = max(count($baselineLines), count($snapshotLines));
            $diffCount = 0;
            for ($i = 0; $i < $maxLines; $i++) {
                $bLine = $baselineLines[$i] ?? null;
                $sLine = $snapshotLines[$i] ?? null;
                if ($bLine !== $sLine) {
                    $diffCount++;
                    if ($diffCount <= 10) {
                        $lineNum = $i + 1;
                        echo "  [DIFF L$lineNum]\n";
                        echo "    expected: " . ($bLine ?? '(end)') . "\n";
                        echo "    actual:   " . ($sLine ?? '(end)') . "\n";
                    }
                }
            }
            echo "  [FAIL] snapshot mismatch: $snapFilePath ($diffCount lines differ)\n";
            assert(false, "Snapshot mismatch: $snapFilePath");
        }
    }
}
