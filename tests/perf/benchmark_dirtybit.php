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

// ── Mock Component（1000 节点树）──────────────────────
class _BenchComponent extends \Px\Component\ReactiveComponent
{
    public int $changedNodeIdx = -1;     // -1 = 无变化
    public string $changedColor = '#FFFFFF';
    public int $changedWidth = 50;
    public string $changedText = 'static';
    public bool $hovered = false;
    public int $staticNodeCount = 1000;   // 子节点总数

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
        // 生成 1000 个子节点，大部分是静态的
        $children = [];
        for ($i = 0; $i < $this->staticNodeCount; $i++) {
            if ($i === $this->changedNodeIdx) {
                // 变化的节点
                $children[] = VNode::hKey('div',
                    ['style' => 'width:' . $this->changedWidth . 'px;height:20px;color:' . $this->changedColor . ';'],
                    $this->changedText,
                    'item-' . $i
                );
            } else {
                // 静态节点
                $children[] = VNode::hKey('div',
                    ['style' => 'width:50px;height:20px;color:#CCCCCC;background-color:#333333;'],
                    'static',
                    'item-' . $i
                );
            }
        }
        return VNode::h('div', ['style' => 'width:900px;height:2000px;'], $children);
    }

    public function setBindValue(string $key, string $val): void {}
    public function getBindValue(string $key): string { return ''; }
    public function onMount(): void {}
    public function dispatchClick(string $handler, ?string $arg = null): void {}
    public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void {}

    /** 公开的脏标记触发 */
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
    ?array $prevCandidates = null,
): ?RenderNode {
    // Step 1: 获取 VNode 树 (触发 render)
    $vnodeTree = $comp->getVNodeTree();

    // Step 2: StyleRecalc
    $styleRecalc = new StyleRecalcPass();
    $styleRecalc->recalc($vnodeTree);

    // Step 3: VNode → RenderNode
    // 传递上一帧的子节点列表作为 candidates，使 updateFromVNode 能按 key 跨帧匹配
    $rootRN = $rtm->updateFromVNode(
        $vnodeTree, null, $comp,
        ['app' => $comp], $prevCandidates, 'app', '', []
    );

    if ($rootRN === null) return null;

    // Step 4: Layout
    $fragmentTree = $lo->layout($rootRN);

    // Step 5: Render (Fragment 路径)
    $pipeline->render($fragmentTree);

    return $rootRN;
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
    // 跑一帧 warming（无 candidates，首次布局）
    $mutateFn($comp, 0);
    $prevRootRN = runOneFrame($comp, $rtm, $lo, $pipeline, null);

    // 正式帧（传递上一帧的子节点列表实现跨帧匹配）
    for ($i = 0; $i < $frames; $i++) {
        $prevCandidates = $prevRootRN !== null ? [$prevRootRN] : null;
        $mutateFn($comp, $i);
        $prevRootRN = runOneFrame($comp, $rtm, $lo, $pipeline, $prevCandidates);
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

// ── 场景 A: 仅颜色变化（改变节点 0 的颜色）─────────────
echo "--- 场景 A: 仅颜色变化（1000节点中1个节点变色）---\n";
$allResults['A_color'] = runScenario('A', $frames, $comp, $rtm, $lo, $pipeline,
    function (_BenchComponent $c, int $i) {
        $c->changedNodeIdx = 0;
        $c->changedColor = ($i % 2 === 0) ? '#FF0000' : '#00FF00';
        $c->changedWidth = 50;
        $c->changedText = 'static';
        $c->invalidate();
    }
);

// ── 场景 B: 仅尺寸变化（改变节点 1 的宽度）─────────────
echo "--- 场景 B: 仅尺寸变化（1000节点中1个节点变宽）---\n";
$allResults['B_size'] = runScenario('B', $frames, $comp, $rtm, $lo, $pipeline,
    function (_BenchComponent $c, int $i) {
        $c->changedNodeIdx = 1;
        $c->changedWidth = 60 + ($i % 5) * 10;
        $c->changedColor = '#CCCCCC';
        $c->changedText = 'static';
        $c->invalidate();
    }
);

// ── 场景 C: 悬停切换（节点 2 的背景色改变）────────────
echo "--- 场景 C: 悬停切换（1000节点中1个节点hover）---\n";
$allResults['C_hover'] = runScenario('C', $frames, $comp, $rtm, $lo, $pipeline,
    function (_BenchComponent $c, int $i) {
        $c->changedNodeIdx = 2;
        $c->hovered = ($i % 2 === 0);
        $c->changedColor = $c->hovered ? '#FF4444' : '#CCCCCC';
        $c->changedWidth = 50;
        $c->changedText = 'static';
        $c->invalidate();
    }
);

// ── 场景 D: 文本内容变化（节点 3 的文本改变）───────────
echo "--- 场景 D: 文本内容变化（1000节点中1个节点换文本）---\n";
$allResults['D_text'] = runScenario('D', $frames, $comp, $rtm, $lo, $pipeline,
    function (_BenchComponent $c, int $i) {
        $c->changedNodeIdx = 3;
        $c->changedText = 'chg-' . ($i % 50);
        $c->changedColor = '#CCCCCC';
        $c->changedWidth = 50;
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
