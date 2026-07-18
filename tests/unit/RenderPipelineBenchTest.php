<?php
/**
 * Px 框架性能基准与 Bug 检测测试套件
 *
 * 覆盖 P0/P1 共 6 项优化，在修复前运行获取基线数据。
 *
 * 用法: php tests/unit/RenderPipelineBenchTest.php [--frames=10]
 * 设置 PX_PERF=1 启用 PerfCounter 计时。
 * 输出: tests/perf/results/baseline_pipeline_<timestamp>.json
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/test-framework.php';

use Px\Dom\VNode;
use Px\Render\RenderNode;
use Px\Render\RenderTreeManager;
use Px\Core\PerfCounter;
use Px\Core\Scheduler;
use Px\Layout\LayoutOrchestrator;
use Px\Css\StyleRecalcPass;
use Px\Paint\PaintPipeline;
use Px\Paint\RenderContext;

$frames = 10;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--frames=')) {
        $frames = max(5, (int)substr($arg, strlen('--frames=')));
    }
}

$results = [];
$globalStart = microtime(true);

class _SimpleRenderCtx extends RenderContext
{
    public function beginFrame(): void {}
    public function endFrame(): void {}
    public function drawElement(array $el): void {}
    public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
    public function drawText(int $x, int $y, string $text, int $fs, int $color, int $bold, string $ff = ''): void {}
    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
}

function readPrivate(object $obj, string $field): mixed
{
    $ref = new ReflectionProperty($obj, $field);
    $ref->setAccessible(true);
    return $ref->getValue($obj);
}

// ══════════════════════════════════════════════════════════
// 套件 1: 泄漏检测 (P0-1)
// ══════════════════════════════════════════════════════════
echo "\n========== 套件 1: 泄漏检测 (P0-1) ==========\n";

describe('ScrollManager 泄漏', function () {

    test('初始状态 scrollStates 为空', function () {
        $sm = new \Px\Core\ScrollManager(fn() => null, fn() => null, fn() => null, fn() => null);
        $states = readPrivate($sm, 'scrollStates');
        assert(count($states) === 0);
    });

    test('滚动操作创建 ScrollState 条目', function () {
        $sm = new \Px\Core\ScrollManager(fn() => null, fn() => null, fn() => null, fn() => null);
        $node = new RenderNode('div');
        $sm->setScrollTop($node, 100);
        $states = readPrivate($sm, 'scrollStates');
        assert(count($states) === 1, '应有 1 条');
        assert($states[spl_object_id($node)]->scrollTop === 100);
    });

    test('destroyRenderNodeTree 后 ScrollManager 应有残留（当前 Bug，确认泄漏）', function () {
        $sm = new \Px\Core\ScrollManager(fn() => null, fn() => null, fn() => null, fn() => null);
        $rtm = new RenderTreeManager();
        $root = new RenderNode('div');
        $s1 = new RenderNode('div'); $s1->isScrollContainer = true;
        $s2 = new RenderNode('div'); $s2->isScrollContainer = true;
        $root->addChild($s1); $root->addChild($s2);
        $sm->setScrollTop($s1, 50);
        $sm->setScrollTop($s2, 150);

        $rtm->destroyRenderNodeTree($root);

        $states = readPrivate($sm, 'scrollStates');
        $count = count($states);
        // 当前代码: destroy 不通知 ScrollManager → 残留
        // 修复后: 应为 0
        printf("  [LEAK] 残留条目数: %d（>0 = 泄漏确认）\n", $count);
        // 不 assert 失败 — 这是基线确认测试
    });

});

// ══════════════════════════════════════════════════════════
// 套件 2: 候选池 Bug (P0-2)
// ══════════════════════════════════════════════════════════
echo "\n========== 套件 2: 候选池 Bug (P0-2) ==========\n";

describe('findMatchingRenderNode 候选池', function () {

    test('列表头部插入 — 原有节点应被复用', function () {
        $rtm = new RenderTreeManager();
        $comp = new class extends \Px\Component\ReactiveComponent {
            public array $items = ['A', 'B', 'C'];
            public function render(): VNode {
                $children = [];
                foreach ($this->items as $i) {
                    $children[] = VNode::hKey('div', [], $i, 'key-' . $i);
                }
                return VNode::h('#root', ['style' => 'width:400;height:300'], $children);
            }
            public function setBindValue(string $k, string $v): void {}
            public function getBindValue(string $k): string { return ''; }
            public function onMount(): void {}
            public function dispatchClick(string $h, ?string $a = null): void {}
            public function invalidate(): void { $this->markDirty(); }
        };

        // 第 1 帧: [A, B, C]
        $comp->setScheduler(new \Px\Core\Scheduler());
        $vnode1 = $comp->getVNodeTree();
        (new StyleRecalcPass())->recalc($vnode1);
        $rn1 = $rtm->updateFromVNode($vnode1, null, $comp, ['app' => $comp], null, 'app');
        $oldRoot = $rtm->getRootRenderNode();
        $oldIds = [];
        if ($oldRoot !== null) {
            foreach ($oldRoot->children as $ch) {
                $oldIds[$ch->key ?? ''] = spl_object_id($ch);
            }
        }

        // 第 2 帧: [X, A, B, C]
        $comp->items = ['X', 'A', 'B', 'C'];
        $comp->invalidate();
        $vnode2 = $comp->getVNodeTree();
        (new StyleRecalcPass())->recalc($vnode2);
        $rn2 = $rtm->updateFromVNode($vnode2, null, $comp, ['app' => $comp],
            $oldRoot !== null ? [$oldRoot] : null, 'app');

        $reused = 0;
        $details = [];
        if ($rn2 !== null) {
            foreach ($rn2->children as $ch) {
                $key = $ch->key ?? '';
                $isReused = isset($oldIds[$key]) && $oldIds[$key] === spl_object_id($ch);
                if ($isReused) $reused++;
                $details[$key] = $isReused ? 'REUSED' : 'NEW';
            }
        }
        printf("  [POOL] 复用: %d/3 — A=%s B=%s C=%s\n", $reused,
            $details['key-A'] ?? '?', $details['key-B'] ?? '?', $details['key-C'] ?? '?');
        // 当前代码可能 < 3（候选池 Bug）
        // 应至少复用 A（key 匹配应正常工作）
        assert($details['key-A'] ?? '' === 'REUSED', 'A 应被复用');
    });

});

// ══════════════════════════════════════════════════════════
// 套件 3: areVNodesEqual 敏感度 (P1-1)
// ══════════════════════════════════════════════════════════
echo "\n========== 套件 3: areVNodesEqual 敏感度 (P1-1) ==========\n";

describe('areVNodesEqual', function () {

    test('相同 style 不同 :bind 应判为不等', function () {
        $rtm = new RenderTreeManager();
        $ref = new ReflectionMethod($rtm, 'areVNodesEqual');
        $ref->setAccessible(true);
        $a = VNode::h('span', [':bind' => 'userName', 'style' => 'color:red;'], '');
        $b = VNode::h('span', [':bind' => 'userAge',  'style' => 'color:red;'], '');
        assert($ref->invoke($rtm, $a, $b) === false, '不同 bind key 应不等');
        $c = VNode::h('span', [':bind' => 'userName', 'style' => 'color:red;'], '');
        assert($ref->invoke($rtm, $a, $c) === true, '相同 bind key 应相等');
    });

    test('不同 v-model 应判为不等', function () {
        $rtm = new RenderTreeManager();
        $ref = new ReflectionMethod($rtm, 'areVNodesEqual');
        $ref->setAccessible(true);
        $a = VNode::h('input', ['v-model' => 'name',  'style' => 'width:100;'], '');
        $b = VNode::h('input', ['v-model' => 'email', 'style' => 'width:100;'], '');
        assert($ref->invoke($rtm, $a, $b) === false, '不同 v-model 应不等');
    });

    test('相同所有属性应判为相等', function () {
        $rtm = new RenderTreeManager();
        $ref = new ReflectionMethod($rtm, 'areVNodesEqual');
        $ref->setAccessible(true);
        $a = VNode::h('div', ['class' => 'box', ':scroll-top' => 'pos', 'style' => 'width:100;'], '');
        $b = VNode::h('div', ['class' => 'box', ':scroll-top' => 'pos', 'style' => 'width:100;'], '');
        assert($ref->invoke($rtm, $a, $b) === true, '完全相同应相等');
    });

});

// ══════════════════════════════════════════════════════════
// 套件 4: PaintPipeline 全量遍历基线 (P1-4)
// ══════════════════════════════════════════════════════════
echo "\n========== 套件 4: PaintPipeline 全量遍历基线 (P1-4) ==========\n";

describe('paint 遍历基线', function () use ($frames, &$results) {

    test('100 节点树单节点变色 — paint 遍历时间', function () use ($frames, &$results) {
        $comp = new class extends \Px\Component\ReactiveComponent {
            public string $color = '#CCC';
            public function render(): VNode {
                $children = [];
                for ($i = 0; $i < 100; $i++) {
                    $children[] = VNode::hKey('div',
                        ['style' => 'width:50;height:20;color:' . $this->color . ';background:#333;'],
                        'item', 'item-' . $i);
                }
                return VNode::h('div', ['style' => 'width:900;height:2000;'], $children);
            }
            public function setBindValue(string $k, string $v): void {}
            public function getBindValue(string $k): string { return ''; }
            public function onMount(): void {}
            public function invalidate(): void { $this->markDirty(); }
            public function dispatchClick(string $h, ?string $a = null): void {}
        };

        $sched = new Scheduler();
        $comp->setScheduler($sched);
        $rtm = new RenderTreeManager();
        $lo = new LayoutOrchestrator();
        $pl = new PaintPipeline($comp, new _SimpleRenderCtx());

        // Warming
        $v = $comp->getVNodeTree();
        (new StyleRecalcPass())->recalc($v);
        $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp], null, 'app');

        PerfCounter::start('bench:paint_traverse');
        for ($i = 0; $i < $frames; $i++) {
            $comp->color = ($i % 2 === 0) ? '#F00' : '#0F0';
            $comp->invalidate();
            $v = $comp->getVNodeTree();
            (new StyleRecalcPass())->recalc($v);
            $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp],
                $prev !== null ? [$prev] : null, 'app');
            if ($prev !== null) {
                $frag = $lo->layout($prev);
                $pl->render($frag);
            }
        }
        PerfCounter::end('bench:paint_traverse');

        $snap = PerfCounter::snapshot();
        $t = $snap['bench:paint_traverse']['total'] ?? 0;
        $avg = $frames > 0 ? round($t / $frames, 2) : 0;
        $results['paint_traverse'] = ['total_us' => $t, 'frames' => $frames, 'avg_us' => $avg];
        printf("  [BENCH] %d us / %d frames = %.1f us/frame\n", $t, $frames, $avg);
    });

});

// ══════════════════════════════════════════════════════════
// 套件 5: 布局约束变化缓存基线 (P1-3)
// ══════════════════════════════════════════════════════════
echo "\n========== 套件 5: 布局约束变化缓存基线 (P1-3) ==========\n";

describe('布局约束变化', function () use ($frames, &$results) {

    test('父容器宽度变化后子布局缓存失效 — 测量重排时间差', function () use ($frames, &$results) {
        $comp = new class extends \Px\Component\ReactiveComponent {
            public int $containerW = 800;
            public function render(): VNode {
                $inner = VNode::h('div', ['style' => 'width:50;height:20;'], 'leaf');
                for ($i = 0; $i < 4; $i++) {
                    $inner = VNode::h('div', ['style' => 'display:flex;flex:1;'], [$inner]);
                }
                return VNode::h('div', ['style' => 'display:flex;width:' . $this->containerW . 'px;height:600;'], [$inner]);
            }
            public function setBindValue(string $k, string $v): void {}
            public function getBindValue(string $k): string { return ''; }
            public function onMount(): void {}
            public function invalidate(): void { $this->markDirty(); }
            public function dispatchClick(string $h, ?string $a = null): void {}
        };

        $sched = new Scheduler();
        $comp->setScheduler($sched);
        $rtm = new RenderTreeManager();
        $lo = new LayoutOrchestrator();
        $pl = new PaintPipeline($comp, new _SimpleRenderCtx());

        $v = $comp->getVNodeTree();
        (new StyleRecalcPass())->recalc($v);
        $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp], null, 'app');

        // 稳定帧（不变）
        PerfCounter::start('bench:layout_stable');
        for ($i = 0; $i < 5; $i++) {
            $comp->invalidate();
            $v = $comp->getVNodeTree();
            (new StyleRecalcPass())->recalc($v);
            $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp],
                $prev !== null ? [$prev] : null, 'app');
            if ($prev !== null) { $lo->layout($prev); }
        }
        PerfCounter::end('bench:layout_stable');
        $s = PerfCounter::snapshot();
        $stable = $s['bench:layout_stable']['total'] ?? 0;

        // 约束变化帧（resize）
        PerfCounter::start('bench:layout_resize');
        for ($i = 0; $i < 5; $i++) {
            $comp->containerW = 700 + ($i % 3) * 50;
            $comp->invalidate();
            $v = $comp->getVNodeTree();
            (new StyleRecalcPass())->recalc($v);
            $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp],
                $prev !== null ? [$prev] : null, 'app');
            if ($prev !== null) { $lo->layout($prev); }
        }
        PerfCounter::end('bench:layout_resize');
        $r = PerfCounter::snapshot();
        $resize = $r['bench:layout_resize']['total'] ?? 0;

        printf("  [BENCH] 稳定=%d us  resize=%d us  ratio=%.2fx\n", $stable, $resize,
            $stable > 0 ? $resize / $stable : 0);
        $results['layout_constraint'] = ['stable_us' => $stable, 'resize_us' => $resize,
            'ratio' => $stable > 0 ? round($resize / $stable, 2) : 0];
    });

});

// ══════════════════════════════════════════════════════════
// 微基准 1: paintDirty 子树跳过 (P1-4)
// 场景: 1000 节点中仅 1 个节点变色，其余 999 洁净
// 预期: paintDirty 跳过应使 paint 时间与节点总数近似无关
// ══════════════════════════════════════════════════════════
echo "\n========== 微基准 1: paintDirty 跳过 (P1-4) ==========\n";

describe('paintDirty 跳过', function () use ($frames, &$results) {

    test('1000节点中单节点变色 — paint 遍历时间', function () use ($frames, &$results) {
        $comp = new class extends \Px\Component\ReactiveComponent {
            public int $changedIdx = 0;
            public string $color = '#FFF';
            public function render(): VNode {
                $children = [];
                for ($i = 0; $i < 1000; $i++) {
                    $c = ($i === $this->changedIdx) ? $this->color : '#888';
                    $children[] = VNode::hKey('div',
                        ['style' => 'width:50;height:20;color:' . $c . ';background:#333;'],
                        'item', 'item-' . $i);
                }
                return VNode::h('div', ['style' => 'width:1000;height:2000;'], $children);
            }
            public function setBindValue(string $k, string $v): void {}
            public function getBindValue(string $k): string { return ''; }
            public function onMount(): void {}
            public function dispatchClick(string $h, ?string $a = null): void {}
            public function invalidate(): void { $this->markDirty(); }
        };

        $sched = new Scheduler();
        $comp->setScheduler($sched);
        $rtm = new RenderTreeManager();
        $lo = new LayoutOrchestrator();
        $pl = new PaintPipeline($comp, new _SimpleRenderCtx());

        // Warming: 建立缓存
        $v = $comp->getVNodeTree();
        (new StyleRecalcPass())->recalc($v);
        $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp], null, 'app');

        PerfCounter::start('bench:paint_1of1000');
        for ($i = 0; $i < $frames; $i++) {
            $comp->changedIdx = $i % 1000;
            $comp->color = ($i % 2 === 0) ? '#F00' : '#0F0';
            $comp->invalidate();
            $v = $comp->getVNodeTree();
            (new StyleRecalcPass())->recalc($v);
            $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp],
                $prev !== null ? [$prev] : null, 'app');
            if ($prev !== null) {
                $frag = $lo->layout($prev);
                $pl->render($frag);
            }
        }
        PerfCounter::end('bench:paint_1of1000');
        $snap = PerfCounter::snapshot();
        $t = $snap['bench:paint_1of1000']['total'] ?? 0;
        $avg = $frames > 0 ? round($t / $frames, 2) : 0;
        $results['paint_1of1000'] = ['total_us' => $t, 'frames' => $frames, 'avg_us' => $avg];
        printf("  [BENCH] 1000节点单变色: %d us / %d frames = %.1f us/frame\n", $t, $frames, $avg);
        echo "  [NOTE] 999 节点洁净，paintDirty 跳过应遍历 1 节点而非 1000\n";
    });

});

// ══════════════════════════════════════════════════════════
// 微基准 2: 候选池 (P0-2)
// 场景: 100 项列表头部插入 1 项，检验 RenderNode 复用率
// ══════════════════════════════════════════════════════════
echo "\n========== 微基准 2: 候选池 (P0-2) ==========\n";

describe('候选池', function () use (&$results) {

    test('100项列表头部插入 — 复用率与耗时', function () use (&$results) {
        $rtm = new RenderTreeManager();
        $comp = new class extends \Px\Component\ReactiveComponent {
            public array $items = [];
            public function __construct() {
                parent::__construct();
                for ($i = 0; $i < 100; $i++) { $this->items[] = 'item-' . $i; }
            }
            public function render(): VNode {
                $children = [];
                foreach ($this->items as $k) {
                    $children[] = VNode::hKey('div', ['style' => 'width:50;height:20;'], $k, 'k-' . $k);
                }
                return VNode::h('#root', ['style' => 'width:400;height:3000;'], $children);
            }
            public function setBindValue(string $k, string $v): void {}
            public function getBindValue(string $k): string { return ''; }
            public function onMount(): void {}
            public function dispatchClick(string $h, ?string $a = null): void {}
            public function invalidate(): void { $this->markDirty(); }
        };
        $comp->setScheduler(new Scheduler());

        // 第 1 帧: 100 项
        $v = $comp->getVNodeTree();
        (new StyleRecalcPass())->recalc($v);
        $rn = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp], null, 'app');
        $oldIds = [];
        foreach ($rtm->getRootRenderNodes() as $ch) {
            $oldIds[$ch->key ?? ''] = spl_object_id($ch);
        }

        // 第 2 帧: 头部插入 1 项 → 101 项（传递全部旧根节点作为候选池）
        array_unshift($comp->items, 'item-NEW');
        $comp->invalidate();
        PerfCounter::start('bench:prepend_pool');
        $v2 = $comp->getVNodeTree();
        (new StyleRecalcPass())->recalc($v2);
        $oldCandidates = $rtm->getRootRenderNodes();
        $rn2 = $rtm->updateFromVNode($v2, null, $comp, ['app' => $comp],
            !empty($oldCandidates) ? $oldCandidates : null, 'app');
        PerfCounter::end('bench:prepend_pool');

        $snap = PerfCounter::snapshot();
        $time = $snap['bench:prepend_pool']['total'] ?? 0;

        // 统计复用
        $reused = 0; $total = 0;
        foreach ($rtm->getRootRenderNodes() as $ch) {
            $total++;
            $key = $ch->key ?? '';
            if (isset($oldIds[$key]) && $oldIds[$key] === spl_object_id($ch)) $reused++;
        }
        $pct = $total > 0 ? round($reused / $total * 100, 1) : 0;
        $results['prepend_pool'] = ['reused' => $reused, 'total' => $total, 'pct' => $pct, 'us' => $time];
        printf("  [BENCH] 复用: %d/%d (%.1f%%) 耗时: %d us\n", $reused, $total, $pct, $time);
        echo "  [NOTE] 候选池修复后 100 项应全部复用，仅新项新建\n";
    });

});

// ══════════════════════════════════════════════════════════
// 微基准 3: LayoutBoundary (P2-3)
// 场景: 500 个显式宽高节点，改变父容器填充
// 预期: LayoutBoundary 跳过递归 → layout 时间与子节点数解耦
// ══════════════════════════════════════════════════════════
echo "\n========== 微基准 3: LayoutBoundary (P2-3) ==========\n";

describe('LayoutBoundary', function () use ($frames, &$results) {

    test('500个显式宽高节点 — 布局时间', function () use ($frames, &$results) {
        $comp = new class extends \Px\Component\ReactiveComponent {
            public int $trigger = 0;
            public function render(): VNode {
                // 500 个子节点，每个都有显式 width:100px;height:20px
                $children = [];
                for ($i = 0; $i < 500; $i++) {
                    $children[] = VNode::hKey('div',
                        ['style' => 'width:100px;height:20px;background:#' . (($i + $this->trigger) % 2 === 0 ? 'F00' : '00F') . ';'],
                        'fixed', 'fixed-' . $i);
                }
                return VNode::h('div', ['style' => 'display:flex;flex-wrap:wrap;width:900px;'], $children);
            }
            public function setBindValue(string $k, string $v): void {}
            public function getBindValue(string $k): string { return ''; }
            public function onMount(): void {}
            public function dispatchClick(string $h, ?string $a = null): void {}
            public function invalidate(): void { $this->markDirty(); }
        };

        $sched = new Scheduler();
        $comp->setScheduler($sched);
        $rtm = new RenderTreeManager();
        $lo = new LayoutOrchestrator();

        // Warming
        $v = $comp->getVNodeTree();
        (new StyleRecalcPass())->recalc($v);
        $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp], null, 'app');

        // 稳定布局（所有节点缓存命中）
        PerfCounter::start('bench:boundary_stable');
        for ($i = 0; $i < 5; $i++) {
            $comp->invalidate();
            $v = $comp->getVNodeTree();
            (new StyleRecalcPass())->recalc($v);
            $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp],
                $prev !== null ? [$prev] : null, 'app');
            if ($prev !== null) { $lo->layout($prev); }
        }
        PerfCounter::end('bench:boundary_stable');
        $snap = PerfCounter::snapshot();
        $stable = $snap['bench:boundary_stable']['total'] ?? 0;

        printf("  [BENCH] 500节点稳定layout: %d us / 5 frames = %.1f us/frame\n", $stable, $stable / 5);
        $results['boundary_stable'] = ['total_us' => $stable, 'frames' => 5, 'avg_us' => round($stable / 5, 2)];
    });

});

// ══════════════════════════════════════════════════════════
// 微基准 4: 约束签名 (P2-1)
// 场景: 1000 次 ConstraintSpace::equals() vs 1000 次字符串序列化
// ══════════════════════════════════════════════════════════
echo "\n========== 微基准 4: 约束签名 (P2-1) ==========\n";

describe('约束签名', function () use (&$results) {

    test('1000次 equals() vs 字符串序列化', function () use (&$results) {
        $spaces = [];
        for ($i = 0; $i < 1000; $i++) {
            $spaces[] = new \Px\Layout\ConstraintSpace(
                800 + ($i % 10), 600 + ($i % 5),
                0, 0, 780 + ($i % 10), 580 + ($i % 5),
                780 + ($i % 10), 580 + ($i % 5),
                ($i % 20) * 2, ($i % 15) * 2, ($i % 20) * 2 + 4, ($i % 15) * 2 + 4,
                1, 1, 1, 1
            );
        }

        // equals() 1000 次
        $t0 = hrtime(true);
        $eq = 0;
        for ($i = 1; $i < 1000; $i++) {
            if ($spaces[$i]->equals($spaces[$i - 1])) $eq++;
        }
        $tEq = (hrtime(true) - $t0) / 1000; // ns → μs

        // 字符串序列化 1000 次（模拟旧方式）
        $sigFn = function(\Px\Layout\ConstraintSpace $s): string {
            return implode('|', [
                $s->getContainerWidth(), $s->getContainerHeight(),
                $s->getContentWidth(), $s->getContentHeight(),
                $s->getPercentageWidth(), $s->getPercentageHeight(),
                $s->getPaddingTop(), $s->getPaddingRight(),
                $s->getPaddingBottom(), $s->getPaddingLeft(),
                $s->borderTop, $s->borderRight,
                $s->borderBottom, $s->borderLeft,
                $s->getBfcOffsetX(), $s->getBfcOffsetY(),
                $s->getSpaceType(),
            ]);
        };
        $t1 = hrtime(true);
        $strs = [];
        for ($i = 0; $i < 1000; $i++) {
            $strs[] = $sigFn($spaces[$i]);
        }
        $tStr = (hrtime(true) - $t1) / 1000;

        $ratio = $tStr > 0 ? round($tEq / $tStr * 100, 1) : 0;
        $results['constraint_sig'] = ['equals_us' => $tEq, 'string_us' => $tStr, 'pct' => $ratio];
        printf("  [BENCH] equals(): %.2f us  序列化: %.2f us  比例: %.1f%%\n", $tEq, $tStr, $ratio);
        echo "  [NOTE] equals() 应显著快于字符串序列化\n";
    });

});


// 微基准 5: offsetOnly 布局平移 (P1-3)
echo "\n========== 微基准 5: offsetOnly (P1-3) ==========\n";

describe('offsetOnly', function () use ($frames, &$results) {

    test('10层嵌套 padding 变化', function () use ($frames, &$results) {
        $comp = new class extends \Px\Component\ReactiveComponent {
            public int $containerPad = 10;
            public function render(): VNode {
                $inner = VNode::h('div', ['style' => 'width:100px;height:20px;'], 'leaf');
                for ($i = 0; $i < 10; $i++) {
                    $pad = ($i === 0) ? $this->containerPad : 4;
                    $inner = VNode::h('div', [
                        'style' => 'display:flex;padding:' . $pad . 'px;width:400px;',
                    ], [$inner]);
                }
                return VNode::h('div', ['style' => 'width:500px;'], [$inner]);
            }
            public function setBindValue(string $k, string $v): void {}
            public function getBindValue(string $k): string { return ''; }
            public function onMount(): void {}
            public function dispatchClick(string $h, ?string $a = null): void {}
            public function invalidate(): void { $this->markDirty(); }
        };
        $sched = new Scheduler();
        $comp->setScheduler($sched);
        $rtm = new RenderTreeManager();
        $lo = new LayoutOrchestrator();
        $pl = new PaintPipeline($comp, new _SimpleRenderCtx());
        $v = $comp->getVNodeTree();
        (new StyleRecalcPass())->recalc($v);
        $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp], null, 'app');
        PerfCounter::start('bench:offset_stable');
        for ($i = 0; $i < 5; $i++) {
            $comp->invalidate();
            $v = $comp->getVNodeTree();
            (new StyleRecalcPass())->recalc($v);
            $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp],
                $prev !== null ? [$prev] : null, 'app');
            if ($prev !== null) { $lo->layout($prev); $pl->render($lo->layout($prev)); }
        }
        PerfCounter::end('bench:offset_stable');
        PerfCounter::start('bench:offset_padding');
        for ($i = 0; $i < 5; $i++) {
            $comp->containerPad = 10 + ($i % 4) * 2;
            $comp->invalidate();
            $v = $comp->getVNodeTree();
            (new StyleRecalcPass())->recalc($v);
            $prev = $rtm->updateFromVNode($v, null, $comp, ['app' => $comp],
                $prev !== null ? [$prev] : null, 'app');
            if ($prev !== null) { $lo->layout($prev); $pl->render($lo->layout($prev)); }
        }
        PerfCounter::end('bench:offset_padding');
        $snap = PerfCounter::snapshot();
        $stable = $snap['bench:offset_stable']['total'] ?? 0;
        $pad = $snap['bench:offset_padding']['total'] ?? 0;
        $ratio = $stable > 0 ? round($pad / $stable, 2) : 0;
        $results['offset_padding'] = ['stable_us' => $stable, 'padding_us' => $pad, 'ratio' => $ratio];
        printf("  [BENCH] 稳定: %d us  padding变化: %d us  比例: %.2fx\n", $stable, $pad, $ratio);
    });

});


$globalElapsed = round(microtime(true) - $globalStart, 4);
$results['_meta'] = [
    'timestamp' => date('Y-m-d H:i:s'),
    'total_sec' => $globalElapsed,
    'php_version' => PHP_VERSION,
    'type' => 'baseline',
];

$resultsDir = __DIR__ . '/../perf/results';
if (!is_dir($resultsDir)) { @mkdir($resultsDir, 0777, true); }
$filename = $resultsDir . '/baseline_pipeline_' . date('Ymd_His') . '.json';
file_put_contents($filename, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n========================================\n";
printf(" 耗时: %.2fs\n", $globalElapsed);
echo " 基线: {$filename}\n";
echo "========================================\n";
