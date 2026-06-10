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
        $now = date('Y-m-d H:i:s');
        $header = "@generated $now\n";
        file_put_contents($snapFile, $header . $combined);
        echo "  [UPDATED] $snapFile (generated at $now)\n";
        return;
    }

    if (!file_exists($snapFile)) {
        echo "  [MISSING] Run with --update-snapshots to create baseline.\n";
        return;
    }

    // Strip @generated metadata lines before comparison
    $baseline = file_get_contents($snapFile);
    $baseline = preg_replace('/^@generated[^\n]*\n?/m', '', $baseline);

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

// ── Capturing RenderContext for rendering element verification ──

class _CssCaptureRenderContext extends \Px\Rendering\RenderContext
{
    public array $drawnElements = [];

    public function beginFrame(): void { $this->drawnElements = []; }
    public function endFrame(): void {}
    public function drawElement(array $el): void { $this->drawnElements[] = $el; }
    public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void {}
    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
}

class _CssCapturePlatform implements \Px\Platform\Platform
{
    public _CssCaptureRenderContext $renderContext;
    private int $width;
    private int $height;

    public function __construct(int $w, int $h) {
        $this->width = $w;
        $this->height = $h;
        $this->renderContext = new _CssCaptureRenderContext();
    }

    public function init(string $title, int $width, int $height): \Px\Rendering\RenderContext {
        return $this->renderContext;
    }
    public function getHwnd(): int { return 0; }
    public function shutdown(): void {}
    public function shouldClose(): bool { return false; }
    public function pollEvents(): array { return []; }
    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void {}
    public function setCursor(string $cursor): void {}
}

/**
 * Format a single render element as a readable string for snapshot comparison.
 */
function format_render_element(array $el): string
{
    $type = $el['type'] ?? 'unknown';
    switch ($type) {
        case 'text':
            $s = 'type=text text=' . json_encode($el['text'] ?? '', JSON_UNESCAPED_UNICODE);
            $s .= ' | x=' . $el['x'] . ' y=' . $el['y'];
            $s .= ' | fontSize=' . $el['fontSize'] . ' color=' . sprintf('0x%06X', $el['color'] ?? 0) . ' bold=' . ($el['bold'] ?? 0);
            if (array_key_exists('decorationLine', $el)) {
                $s .= ' | decorationLine=' . $el['decorationLine']
                    . ' decorationStyle=' . $el['decorationStyle'];
                $dc = $el['decorationColor'] ?? 0;
                if (is_int($dc)) {
                    $s .= ' decorationColor=' . sprintf('0x%06X', $dc);
                } else {
                    $s .= ' decorationColor=' . $dc;
                }
                $s .= ' decorationThickness=' . $el['decorationThickness']
                    . ' underlineOffset=' . $el['underlineOffset']
                    . ' textWidth=' . $el['textWidth'];
            }
            return $s;
        case 'rect':
            return 'type=rect | x=' . $el['x'] . ' y=' . $el['y'] . ' w=' . $el['w'] . ' h=' . $el['h']
                . ' color=' . sprintf('0x%06X', $el['color'] ?? 0) . ' layer=' . ($el['layer'] ?? 0);
        case 'group':
            return 'type=group | layer=' . ($el['layer'] ?? 0) . ' elementCount=' . count($el['elements'] ?? []);
        case 'scroll-container':
            return 'type=scroll-container | x=' . $el['x'] . ' y=' . $el['y']
                . ' w=' . $el['w'] . ' h=' . $el['h']
                . ' contentHeight=' . ($el['contentHeight'] ?? 0)
                . ' contentWidth=' . ($el['contentWidth'] ?? 0);
        default:
            return json_encode($el, JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Run full rendering pipeline with element capture.
 * Returns both layout tree dump and captured rendering elements for snapshot comparison.
 *
 * Usage (same as run_minimal_pipeline):
 *   $result = run_render_pipeline(VNode::h('div', [...], 'text'));
 *   assert_contains($result, 'decorationLine=underline');
 *   return $result;
 */
function run_render_pipeline(\Px\Rendering\VNode $vnode, int $width = 1440, int $height = 900): string
{
    if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
    if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', $width);
    if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', $height);
    if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'Test');

    $platform = new _CssCapturePlatform($width, $height);
    $scheduler = new \Px\Core\Scheduler();
    $app = new \Px\Core\Application($platform, $scheduler);

    $root = new class($vnode, $app, $scheduler) extends \Px\ReactiveComponent {
        private \Px\Rendering\VNode $vnode;
        public function __construct(\Px\Rendering\VNode $vnode, $app, $scheduler) {
            parent::__construct('Root');
            $this->vnode = $vnode;
            $this->setScheduler($scheduler);
            $this->setRenderCallback(function() use ($app) {
                $rm = new \ReflectionMethod(\Px\Core\Application::class, 'handleRenderRequest');
                $rm->setAccessible(true);
                $rm->invoke($app);
            });
        }
        public function render(): \Px\Rendering\VNode { return \Px\Rendering\VNode::h('#root', [], $this->vnode); }
        public function setBindValue(string $k, string $v): void {}
        public function getBindValue(string $k): string { return ''; }
        public function onMount(): void {}
    };

    $rm = new \ReflectionMethod($app, 'mount');
    $rm->setAccessible(true);
    $rm->invoke($app, $root);

    $rm = new \ReflectionMethod($app, 'render');
    $rm->setAccessible(true);
    $rm->invoke($app);

    // Layout dump
    $rtm = $app->getRenderTreeManager();
    $rootNode = $rtm->getRootRenderNode();
    $layoutDump = $rootNode !== null ? $rtm->dumpRenderTree($rootNode, 1, []) : '';

    // Render elements dump
    $elements = $platform->renderContext->drawnElements;
    $elementLines = [];
    foreach ($elements as $i => $el) {
        $elementLines[] = '  [' . $i . '] ' . format_render_element($el);
    }
    $renderDump = implode("\n", $elementLines);
    if ($elementLines === []) {
        $renderDump = '  (no elements)';
    }

    return $layoutDump . "=== render ===\n" . $renderDump . "\n";
}
