<?php
/**
 * CssTestBase — 渐进式 CSS 布局测试基类
 *
 * 为每个 CSS 属性/布局模式创建独立的最小化测试组件，
 * 通过完整渲染管线输出 RenderNode 树快照，与黄金基线对比。
 *
 * 设计原则：
 *   - 每个测试只验证一个 CSS 布局概念（单一职责）
 *   - 测试从简单（绝对定位 div）到复杂（grid auto-fill + scroll）渐进
 *   - 快照基线文件可作为 CSS 标准实现的"契约"(contract)
 *   - 运行 `--update-snapshots` 更新基线
 *
 * Usage:
 *   include __DIR__ . '/CssTestBase.php';
 *   // 创建测试组件
 *   class MyTest extends CssTestBase {
 *       public const WIDTH = 1440;
 *       public const HEIGHT = 900;
 *       public function testRender(): VNode { ... }
 *   }
 *   // 运行测试
 *   run_css_tests('MyTest');
 */

require_once __DIR__ . '/../unit/bootstrap.php';
require_once __DIR__ . '/../unit/PipelineTestBase.php';

use Px\Core\Scheduler;
use Px\Core\Application;
use Px\Rendering\RenderContext;
use Px\Rendering\VNode;

// ── 微型 RenderTree 构建器 ──
// 跳过完整的应用管线，直接创建最小的 VNode→RenderNode→Layout→dump 流程

/**
 * 从 VNode 运行最小渲染管线，返回 dumpRenderTree 结果。
 * 这是测试的核心：不依赖 App 目录、ComponentFactory、px_debug.yml。
 */
// 注：assert_contains() 由 test-framework.php 提供（通过 bootstrap.php 引入）

function run_minimal_pipeline(VNode $vnode, int $width = 1440, int $height = 900): string
{
    $appDir = __DIR__ . '/../../apps/bilibili';

    // 定义必要的常量
    if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
    if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', $width);
    if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', $height);
    if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'Test');

    // 创建 Application（使用 StubPlatform）
    $platform = new StubPlatform($width, $height);
    $scheduler = new Scheduler();
    $app = new Application($platform, $scheduler);

    // 创建根组件（render() 必须返回 #root 类型，否则 updateFromVNode 不会设置 rootRenderNode）
    $root = new class($vnode, $app, $scheduler) extends \Px\ReactiveComponent {
        private VNode $vnode;
        public function __construct(VNode $vnode, $app, $scheduler) {
            parent::__construct('Root');
            $this->vnode = $vnode;
            $this->setScheduler($scheduler);
            $this->setRenderCallback(function() use ($app) {
                $rm = new \ReflectionMethod(Application::class, 'handleRenderRequest');
                $rm->setAccessible(true);
                $rm->invoke($app);
            });
        }
        public function render(): VNode { return VNode::h('#root', [], $this->vnode); }
        public function setBindValue(string $k, string $v): void {}
        public function getBindValue(string $k): string { return ''; }
        public function onMount(): void {}
    };

    // 反射 mount + render
    $rm = new \ReflectionMethod($app, 'mount');
    $rm->setAccessible(true);
    $rm->invoke($app, $root);

    $rm = new \ReflectionMethod($app, 'render');
    $rm->setAccessible(true);
    $rm->invoke($app);

    // 获取 dump
    $rtm = $app->getRenderTreeManager();
    $rootNode = $rtm->getRootRenderNode();
    if ($rootNode === null) return '';

    return $rtm->dumpRenderTree($rootNode, 1, []);
}

/**
 * 运行一组 CSS 标准化测试。
 * 每个 test() 生成快照，最后与基线对比。
 */
function run_css_tests(string $suiteName, string $snapFile, array $tests): void
{
    echo "========================================\n";
    echo " CSS Layout: $suiteName\n";
    echo "========================================\n\n";

    $snapshots = [];

    foreach ($tests as $name => $fn) {
        try {
            $snapshots[$name] = $fn();
            echo "  [PASS] {$name}\n";
            $GLOBALS['_test_passed']++;
        } catch (\Throwable $e) {
            echo "  [FAIL] {$name}\n";
            echo "          {$e->getMessage()}\n";
            $GLOBALS['_test_failed']++;
        }
    }

    echo "\n--- 快照对比 ---\n";

    // 读取或更新基线
    $update = false;
    foreach (($_SERVER['argv'] ?? []) as $arg) {
        if ($arg === '--update-snapshots') { $update = true; break; }
    }

    if ($update) {
        $dir = dirname($snapFile);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $combined = implode("\n---\n\n", array_map(function($name, $snap) {
            return "=== Test: $name ===\n$snap";
        }, array_keys($snapshots), $snapshots));
        file_put_contents($snapFile, $combined);
        echo "  [UPDATED] $snapFile\n";
        return;
    }

    if (!file_exists($snapFile)) {
        echo "  [MISSING] Run with --update-snapshots to create baseline.\n";
        return;
    }

    $baseline = file_get_contents($snapFile);
    $combined = implode("\n---\n\n", array_map(function($name, $snap) {
        return "=== Test: $name ===\n$snap";
    }, array_keys($snapshots), $snapshots));

    if ($combined !== $baseline) {
        $bLines = explode("\n", $baseline);
        $sLines = explode("\n", $combined);
        $max = max(count($bLines), count($sLines));
        $diff = 0;
        for ($i = 0; $i < $max; $i++) {
            if (($bLines[$i] ?? '') !== ($sLines[$i] ?? '')) {
                $diff++;
                if ($diff <= 10) {
                    echo "  [DIFF L" . ($i+1) . "]\n";
                    echo "    expected: " . ($bLines[$i] ?? '(end)') . "\n";
                    echo "    actual:   " . ($sLines[$i] ?? '(end)') . "\n";
                }
            }
        }
        echo "  [FAIL] $diff lines differ from baseline (snapshot mismatch)\n";
        $GLOBALS['_test_failed']++;
    } else {
        // Snapshot matched — count suite-level pass (individual tests already counted)
        echo "  [PASS] Snapshot matches baseline\n";
    }
}
