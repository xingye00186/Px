<?php
/**
 * C4.0 取证：单节点 class 切换场景的样式重算成本与 StylePool 命中率。
 *
 * 计划判据：命中率 >90% 且 recalc <200μs → C4 增量重算降 P2 搁置。
 * 场景对标 reactive-bench「单节点 class 切换」：树规模固定，仅一个节点
 * 的 class 在两值间反复切换，逐帧全量 render（含 StyleRecalcPass）。
 *
 * 用法: php tools/c40_style_recalc_evidence.php [frames]
 */

require_once __DIR__ . '/../tests/unit/bootstrap.php';
require_once __DIR__ . '/../tests/css-standards/CssTestBase.php';   // StubPlatform

use Px\Dom\VNode;
use Px\Css\StyleEngine;
use Px\Core\PerfCounter;
use Px\Core\Application;
use Px\Core\Scheduler;

$frames = isset($argv[1]) ? max(10, (int)$argv[1]) : 200;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH')) define('WINDOW_WIDTH', 1440);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 900);
if (!defined('WINDOW_TITLE')) define('WINDOW_TITLE', 'C4.0');

// ── 真实样式表（多规则，令引擎与池都实际工作）──
StyleEngine::reset();
StyleEngine::registerCss(
    '.row { display:flex; padding:4px 8px; }'
    . ' .cell { width:120px; height:24px; background:#EEEEEE; color:#333333; }'
    . ' .cell.active { background:#FF0000; color:#FFFFFF; }'
    . ' .cell.idle { background:#DDDDDD; }'
    . ' .wrap .cell { border:1px solid #CCCCCC; }'
    . ' .cell:hover { background:#0000FF; }'
);

/** 组件：固定 200 节点，仅索引 7 的节点 class 切换。 */
final class C40Comp extends \Px\Component\ReactiveComponent
{
    public string $toggle = 'idle';
    public function __construct($sch) { parent::__construct('C40'); $this->setScheduler($sch); }
    public function render(): VNode
    {
        $cells = [];
        for ($i = 0; $i < 200; $i++) {
            $cls = ($i === 7) ? ('cell ' . $this->toggle) : 'cell';
            $cells[] = VNode::h('div', ['class' => $cls], 'c' . $i);
        }
        $row = VNode::h('div', ['class' => 'row wrap'], $cells);
        return VNode::h('#root', [], $row);
    }
    public function setBindValue(string $k, string $v): void {}
    public function getBindValue(string $k): string { return ''; }
    public function onMount(): void {}
}

$platform = new StubPlatform(1440, 900);
$sch = new Scheduler();
$app = new Application($platform, $sch);
$comp = new C40Comp($sch);

$mMount = new ReflectionMethod($app, 'mount'); $mMount->setAccessible(true);
$mMount->invoke($app, $comp);
$mRender = new ReflectionMethod($app, 'render'); $mRender->setAccessible(true);

// 预热两帧（首帧建池，避免冷启动污染稳态测量）
$mRender->invoke($app);
$mRender->invoke($app);

// ── 稳态测量 ──
// 注：PerfCounter::snapshot() 为**平坦结构且读后自动重置**（name => {count,
// total, avg, min, max, elapsed_sec}）。故只需在预热后取一次快照清零，
// 循环结束后再取一次即为稳态增量（无需手工相减）。
PerfCounter::snapshot();   // 清零预热期计数

$recalcTotal = 0.0;
for ($f = 0; $f < $frames; $f++) {
    $comp->toggle = ($f % 2 === 0) ? 'active' : 'idle';
    $comp->renderDirty = true;
    $t = microtime(true);
    $mRender->invoke($app);
    $recalcTotal += (microtime(true) - $t);
}

$snap = PerfCounter::snapshot();
if (empty($snap)) {
    echo "ABORT：PerfCounter 未启用——需 PX_PERF=1（无数据不等于不达标）\n";
    exit(2);
}

$hits   = (int)($snap['style_pool_hit']['count'] ?? 0);
$misses = (int)($snap['style_pool_miss']['count'] ?? 0);
$total = $hits + $misses;
$rate = $total > 0 ? ($hits / $total) * 100 : 0.0;

// stage:style_recalc：total 单位为 **微秒**（PerfCounter::end 内 *1_000_000），
// count 为调用次数。
$srTotalUs = (float)($snap['stage:style_recalc']['total'] ?? 0);
$srCalls   = (int)($snap['stage:style_recalc']['count'] ?? 0);
$perRecalcUs = $srCalls > 0 ? ($srTotalUs / $srCalls) : 0.0;
$perFrameFullUs = ($recalcTotal / $frames) * 1e6;

echo "========================================\n";
echo " C4.0 取证 — 单节点 class 切换\n";
echo "========================================\n";
echo "frames                 : $frames\n";
echo "nodes/frame            : 200 cells (+row +#root)\n";
echo "engine rules           : " . StyleEngine::ruleCount() . "\n";
echo "style_pool_hit         : $hits\n";
echo "style_pool_miss        : $misses\n";
printf("pool hit rate          : %.2f%%\n", $rate);
echo "stage:style_recalc calls: $srCalls\n";
printf("stage:style_recalc tot : %.2f us (%.2f ms)\n", $srTotalUs, $srTotalUs / 1000.0);
printf("per-recalc             : %.1f us\n", $perRecalcUs);
printf("per-frame full render  : %.1f us\n", $perFrameFullUs);
echo "----------------------------------------\n";
$hitOk = $rate > 90.0;
$recalcOk = $perRecalcUs > 0 && $perRecalcUs < 200.0;
echo "判据 hit>90%           : " . ($hitOk ? 'YES' : 'NO') . "\n";
echo "判据 recalc<200us      : " . ($recalcOk ? 'YES' : 'NO') . ($perRecalcUs > 0 ? '' : ' (无计时数据)') . "\n";
echo ($hitOk && $recalcOk)
    ? "\n结论：双判据满足 → C4 增量重算降 P2 搁置（计划终于此）\n"
    : "\n结论：判据未满足 → C4.1 增量重算有数据支持，应实施\n";
StyleEngine::reset();
