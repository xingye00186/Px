<?php
/**
 * Px Framework — 脏位分离性能基准测试
 *
 * 测量三级脏位优化前后，各管线阶段在4种变更类型下的耗时。
 * 设置 PX_PERF=1 启用 PerfCounter 内嵌测量。
 *
 * 4 个场景，每个跑 N 帧取累计：
 *   A: 仅颜色变化 (styleDirty 场景)
 *   B: 仅尺寸变化 (layoutDirty 场景)
 *   C: 悬停切换 (:hover 伪类)
 *   D: 文本内容变更
 *
 * 用法:
 *   set PX_PERF=1
 *   php tests/perf/benchmark_dirtybit.php [--frames=100]
 *
 * 输出: tests/perf/results/{baseline|optimized}_<timestamp>.json
 */

// ── 参数 ──────────────────────────────────────────────
$frames = 50;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--frames=')) {
        $frames = max(10, (int)substr($arg, strlen('--frames=')));
    }
}

// ── AOT polyfill ─────────────────────────────────────
if (!function_exists('objval')) {
    function objval($object, string $class) { return $object; }
}
if (!function_exists('any')) {
    function any($object) { return $object; }
}

// ── 加载框架 ─────────────────────────────────────────
$pxRoot = dirname(__DIR__, 2);
require_once $pxRoot . '/tests/bootstrap/autoload.php';

use Px\Core\PerfCounter;
use Px\Core\Scheduler;
use Px\Dom\VNode;
use Px\Render\RenderNode;
use Px\Render\RenderTreeManager;
use Px\Layout\LayoutOrchestrator;
use Px\Css\ComputedStyle;
use Px\Css\StyleResolver;
use Px\Css\StyleRecalcPass;
use Px\Paint\PaintPipeline;
use Px\Paint\RenderContext;

// ── Mock RenderContext ───────────────────────────────
class _BenchRenderContext extends RenderContext
{
    public function beginFrame(): void {}
    public function endFrame(): void {}
    public function drawElement(array $el): void {}
    public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void {}
    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
}

// ── Mock Component ───────────────────────────────────
class _BenchComponent extends \Px\Component\ReactiveComponent
{
    public string $displayValue = '0';
    public string $textColor = '#FFFFFF';
    public int $boxWidth = 300;
    public int $boxHeight = 200;
    public string $label = 'Click Me';
    public bool $hovered = false;

    public function __construct()
    {
        $this->dirty = true;
        $this->isMounted = false;
        $this->hasPendingUpdate = false;
        $this->isUpdating = false;
        $this->listenerIds = [];
        $this->vnodeCache = null;
    }

    public function render(): VNode
    {
        $style = 'width:' . $this->boxWidth . 'px;'
               . 'height:' . $this->boxHeight . 'px;'
               . 'color:' . $this->textColor . ';';
        if ($this->hovered) {
            $style .= 'background-color:#444444;';
        } else {
            $style .= 'background-color:#222222;';
        }
        if ($this->boxHeight > 200) {
            $style .= 'font-size:18px;';
        }

        return VNode::h('div', ['style' => $style], [
            VNode::h('span', ['style' => 'font-size:16px;'], $this->label),
            VNode::h('div', ['style' => 'width:100%;height:1px;background:#666;']),
            VNode::h('span', ['style' => 'font-size:14px;'], 'Display: ' . $this->displayValue),
        ]);
    }

    public function setBindValue(string $key, string $val): void {}
    public function getBindValue(string $key): string { return ''; }
    public function onMount(): void {}
    public function dispatchClick(string $handler, ?string $arg = null): void {}
    public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void {}

    /** 公开的脏标记触发（供基准测试闭包调用） */
    public function invalidate(): void
    {
        $this->markDirty();
    }}

// ── 工具：运行一帧完整管线 ────────────────────────────
function runOneFrame(
    _BenchComponent $comp,
    RenderTreeManager $rtm,
    LayoutOrchestrator $lo,
    PaintPipeline $pipeline,
): void {
    // Step 1: 获取 VNode 树 (触发 render)
    $vnodeTree = $comp->getVNodeTree();

    // Step 2: StyleRecalc
    $styleRecalc = new StyleRecalcPass();
    $styleRecalc->recalc($vnodeTree);

    // Step 3: VNode → RenderNode
    $rootRN = $rtm->updateFromVNode(
        $vnodeTree, null, $comp,
        ['app' => $comp], null, 'app', '', []
    );

    // Step 4: Layout
    $fragmentTree = $lo->layout($rootRN);

    // Step 5: Render (Fragment 路径)
    $pipeline->render($fragmentTree);
}

// ── 工具：运行场景并收集 PerfCounter ──────────────────
function runScenario(
    string $label,
    int $frames,
    _BenchComponent $comp,
    RenderTreeManager $rtm,
    LayoutOrchestrator $lo,
    PaintPipeline $pipeline,
    callable $mutateFn,
): array {
    // 跑一帧 warming
    $mutateFn($comp, 0);
    runOneFrame($comp, $rtm, $lo, $pipeline);

    // 正式帧
    for ($i = 0; $i < $frames; $i++) {
        $mutateFn($comp, $i);
        runOneFrame($comp, $rtm, $lo, $pipeline);
    }

    $snapshot = PerfCounter::snapshot();
    echo "  {$label}: {$frames} frames\n";
    echo PerfCounter::formatSnapshot($snapshot);
    echo "\n";
    return $snapshot;
}

// ══════════════════════════════════════════════════════
echo "========================================\n";
echo " 脏位分离性能基准测试\n";
echo " frames={$frames}\n";
echo "========================================\n\n";

$allResults = [];
$globalStart = microtime(true);

// 创建共享实例
$scheduler = new Scheduler();
$comp = new _BenchComponent();
$comp->setScheduler($scheduler);
$rtm = new RenderTreeManager();
$lo = new LayoutOrchestrator();
$pipeline = new PaintPipeline($comp, new _BenchRenderContext());

// ── 场景 A: 仅颜色变化 ──────────────────────────────
echo "--- 场景 A: 仅颜色变化 ---\n";
$allResults['A_color'] = runScenario('A', $frames, $comp, $rtm, $lo, $pipeline,
    function (_BenchComponent $c, int $i) {
        $c->textColor = ($i % 2 === 0) ? '#FF0000' : '#00FF00';
        $c->invalidate();
    }
);

// ── 场景 B: 仅尺寸变化 ──────────────────────────────
echo "--- 场景 B: 仅尺寸变化 ---\n";
$allResults['B_size'] = runScenario('B', $frames, $comp, $rtm, $lo, $pipeline,
    function (_BenchComponent $c, int $i) {
        $c->boxWidth = 200 + ($i % 3) * 50;
        $c->invalidate();
    }
);

// ── 场景 C: 悬停切换 ────────────────────────────────
echo "--- 场景 C: 悬停切换 ---\n";
$allResults['C_hover'] = runScenario('C', $frames, $comp, $rtm, $lo, $pipeline,
    function (_BenchComponent $c, int $i) {
        $c->hovered = ($i % 2 === 0);
        $c->invalidate();
    }
);

// ── 场景 D: 文本内容变化 ────────────────────────────
echo "--- 场景 D: 文本内容变化 ---\n";
$allResults['D_text'] = runScenario('D', $frames, $comp, $rtm, $lo, $pipeline,
    function (_BenchComponent $c, int $i) {
        $c->label = 'Item #' . ($i % 100);
        $c->invalidate();
    }
);

// ── 输出结果 ──────────────────────────────────────────
$resultsDir = __DIR__ . '/results';
if (!is_dir($resultsDir)) {
    mkdir($resultsDir, 0777, true);
}

$mode = getenv('PX_PERF_MODE') ?: 'baseline';
$filename = $resultsDir . '/' . $mode . '_' . date('Ymd_His') . '.json';

$outputData = [
    'meta' => [
        'timestamp'   => date('Y-m-d H:i:s'),
        'total_sec'   => round(microtime(true) - $globalStart, 4),
        'php_version' => PHP_VERSION,
        'frames'      => $frames,
        'mode'        => $mode,
    ],
    'scenarios' => $allResults,
];

file_put_contents(
    $filename,
    json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "\n========================================\n";
echo " 结果已保存: {$filename}\n";
echo "========================================\n";
