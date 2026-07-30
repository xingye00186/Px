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
// --index：开启 C2.7 倒排索引（默认关），用于量化索引在真实管线中的贡献。
$useIndex = in_array('--index', $argv, true);
// --hoist：静态子树提升（生产 gen 形态），使 C4.1 子树跳过可处发。
$useHoist = in_array('--hoist', $argv, true);
// --noskip：关闭 C4.1 增量重算（强制全量），用于严格 A/B。
$noSkip = in_array('--noskip', $argv, true);
\Px\Css\StyleRecalcPass::$incrementalEnabled = !$noSkip;

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

/**
 * 组件：固定 200 节点，仅索引 7 的节点 class 切换。
 * --hoist：复现生产形态——编译器将**静态子树**提升为 static 缓存
 * （gen 的 static $__sN ??= VNode::h(...)），跳帧为同一实例。不开则每帧
 * 全新建（手写 VNode 形态）。C4.1 子树跳过只对前者生效。
 */
final class C40Comp extends \Px\Component\ReactiveComponent
{
    public string $toggle = 'idle';
    public bool $hoist = false;
    /** @var array<int, VNode> 提升的静态 cell（模拟 gen static $__sN） */
    private array $staticCells = [];
    public function __construct($sch) { parent::__construct('C40'); $this->setScheduler($sch); }
    public function render(): VNode
    {
        $cells = [];
        for ($i = 0; $i < 200; $i++) {
            // 生产形态：v-for 产出 **keyed** 子节点，patchChildrenArray 走 key 匹配
            // 而复用旧实例。无 key 子节点的复用需 dynamicChildren（block root），
            // 手写 VNode 不满足——旧版本因此每帧全新建，不具生产代表性。
            $cls = ($i === 7) ? ('cell ' . $this->toggle) : 'cell';
            $cell = VNode::hKey('div', ['class' => $cls], 'c' . $i, 'k' . $i);
            // patchFlags 必须如实标记：默认 0 = PATCH_NONE 使 patchProps **整段跳过**
            // props 更新（手写 VNode 的陷阱，使 class 切换静默失效）。gen 为动态
            // class 绑定发 PATCH_CLASS；静态节点保持 PATCH_NONE（即提升语义）。
            if ($i === 7) {
                $cell = $cell->withPatchFlags(VNode::PATCH_CLASS);
            }
            $cells[] = $cell;
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
$comp->hoist = $useHoist;

$mMount = new ReflectionMethod($app, 'mount'); $mMount->setAccessible(true);
$mMount->invoke($app, $comp);
$mRender = new ReflectionMethod($app, 'render'); $mRender->setAccessible(true);

StyleEngine::setIndexEnabled($useIndex);

// 预热两帧（首帧建池，避免冷启动污染稳态测量）
$mRender->invoke($app);
$mRender->invoke($app);

// ── 稳态测量 ──
// 注：PerfCounter::snapshot() 为**平坦结构且读后自动重置**（name => {count,
// total, avg, min, max, elapsed_sec}）。故只需在预热后取一次快照清零，
// 循环结束后再取一次即为稳态增量（无需手工相减）。
PerfCounter::snapshot();   // 清零预热期计数

$mUpdate = new ReflectionMethod($comp, 'performUpdate'); $mUpdate->setAccessible(true);

$recalcTotal = 0.0;
for ($f = 0; $f < $frames; $f++) {
    $comp->toggle = ($f % 2 === 0) ? 'active' : 'idle';
    // performUpdate 才是真正的脏标记入口（置 $this->dirty，getVNodeTree 据此
    // 失效缓存）。旧本仅置 renderDirty → 缓存树直返 → class 切换从未发生。
    $mUpdate->invoke($comp);
    $t = microtime(true);
    $mRender->invoke($app);
    $recalcTotal += (microtime(true) - $t);
}

$snap = PerfCounter::snapshot();
if (empty($snap)) {
    echo "ABORT：PerfCounter 未启用——需 PX_PERF=1（无数据不等于不达标）\n";
    exit(2);
}

// ── 切换生效自检（必须）──
// 本脚本已三次因不同原因静默不切换（renderDirty 非脏标入口、无 key 子
// 不复用、patchFlags 默认 PATCH_NONE 跳过 props），导致测量无意义。此处直接
// 验证目标节点的背景色在两个状态间确实不同，否则终止。
function toggleBgOf(object $app, int $idx): int
{
    $pt = new ReflectionProperty($app, 'activeVNodeTree');
    $pt->setAccessible(true);
    $tree = $pt->getValue($app);
    $row = $tree->children;
    if (is_array($row)) { $row = $row[0]; }
    $kids = is_array($row->children) ? $row->children : [];
    $n = $kids[$idx] ?? null;
    if (!($n instanceof VNode) || $n->computedStyle === null) return -1;
    return $n->computedStyle->backgroundColor->toBgr();
}
$comp->toggle = 'active'; $mUpdate->invoke($comp); $mRender->invoke($app);
$bgActive = toggleBgOf($app, 7);
$comp->toggle = 'idle';   $mUpdate->invoke($comp); $mRender->invoke($app);
$bgIdle = toggleBgOf($app, 7);
if ($bgActive === $bgIdle) {
    echo "ABORT：class 切换未生效（active bg=$bgActive == idle bg=$bgIdle）——";
    echo "测量不成立，不得据此结论\n";
    exit(3);
}
echo "toggle self-check      : OK (active bg=$bgActive != idle bg=$bgIdle)\n";

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
echo "C2.7 index             : " . ($useIndex ? 'ON' : 'OFF (default)') . "\n";
echo "static hoisting        : " . ($useHoist ? 'ON (production gen form)' : 'OFF') . "\n";
echo "C4.1 incremental       : " . ($noSkip ? 'OFF (--noskip)' : 'ON') . "\n";
echo "node skips             : " . (int)($snap['style_recalc_node_skip']['count'] ?? 0) . "\n";
echo "  miss: no prev style  : " . (int)($snap['style_recalc_miss_nostyle']['count'] ?? 0) . "\n";
echo "  miss: styleDirty     : " . (int)($snap['style_recalc_miss_dirty']['count'] ?? 0) . "\n";
echo "  miss: parentCS ident : " . (int)($snap['style_recalc_miss_parentcs']['count'] ?? 0) . "\n";
echo "  miss: ctxSig         : " . (int)($snap['style_recalc_miss_ctxsig']['count'] ?? 0) . "\n";
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
