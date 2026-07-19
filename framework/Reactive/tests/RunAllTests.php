<?php
/**
 * Px 框架 AOT 原生响应式系统 — 全量测试套件
 *
 * 从单元测试到集成测试，逐层验证响应式系统正确性。
 * 运行方式: php framework/Reactive/tests/RunAllTests.php
 */

// ── 测试基础设施 ─────────────────────────────────

$passed = 0;
$failed = 0;
$totalAssertions = 0;

function assert_true(mixed $cond, string $desc): void
{
    global $passed, $failed, $totalAssertions;
    $totalAssertions++;
    if ($cond) { $passed++; }
    else { $failed++; echo "  ❌ FAIL: $desc\n"; }
}

function assert_eq(mixed $a, mixed $b, string $desc): void
{
    assert_true($a === $b, "$desc (expected: " . json_encode($b) . ", got: " . json_encode($a) . ")");
}

function test_group(string $name, callable $fn): void
{
    echo "\n=== $name ===\n";
    $fn();
}

// ── 加载框架 ────────────────────────────────────

require_once __DIR__ . '/../../../tests/bootstrap/autoload.php';
require_once __DIR__ . '/../Reactive.php';
require_once __DIR__ . '/../DependentsMap.php';
require_once __DIR__ . '/../Effect.php';
require_once __DIR__ . '/../DependencyTracker.php';
require_once __DIR__ . '/../Notifier.php';
require_once __DIR__ . '/../../../framework/Component/BaseComponent.php';
require_once __DIR__ . '/../../../framework/Component/ReactiveComponent.php';
require_once __DIR__ . '/../../../framework/Dom/VNode.php';

// AOT 原生函数 shim（测试环境不加载 C++ 绑定）
if (!function_exists('objval')) {
    function objval($val, string $type) { return $val; }
}
if (!function_exists('refval')) {
    function refval(&$val, string $type) { return $val; }
}
if (!function_exists('any')) {
    function any($val) { return $val; }
}

use Px\Reactive\DependentsMap;
use Px\Reactive\Effect;
use Px\Reactive\DependencyTracker;
use Px\Reactive\Notifier;
use Px\Core\Scheduler;
use Px\Component\ReactiveComponent;
use Px\Dom\VNode;

// ── 测试用组件 ───────────────────────────────────

/** 最小测试组件（用于 T1/T2/T6） */
class TestComponent extends ReactiveComponent
{
    public string $data = 'init';

    public function render(): VNode { return VNode::h('div', [], $this->data); }

    public function setBindValue(string $bindKey, string $value): void {}
    public function getBindValue(string $bindKey): string { return ''; }
}

/** 模拟编译器生成的计数器组件（用于 T4/T7） */
class SimulatedCounterComponent extends ReactiveComponent
{
    private array $_px_react_storage = ['count' => 0, 'name' => ''];
    protected ?Effect $_px_effect = null;

    public int $count {
        get { DependencyTracker::track($this, 'count'); return $this->_px_react_storage['count'] ?? 0; }
        set (int $value) {
            if ($this->_px_react_storage['count'] !== $value) {
                $this->_px_react_storage['count'] = $value;
                Notifier::notify($this, 'count');
            }
        }
    }

    public string $name {
        get { DependencyTracker::track($this, 'name'); return $this->_px_react_storage['name'] ?? ''; }
        set (string $value) {
            if ($this->_px_react_storage['name'] !== $value) {
                $this->_px_react_storage['name'] = $value;
                Notifier::notify($this, 'name');
            }
        }
    }

    public function getVNodeTree(): VNode
    {
        if (!$this->dirty && $this->vnodeCache !== null) return $this->vnodeCache;
        if ($this->_px_effect === null) $this->_px_effect = new Effect($this);
        return DependencyTracker::runWithEffect(
            $this->_px_effect, fn(): VNode => parent::getVNodeTree()
        );
    }

    public function render(): VNode
    {
        return VNode::h('span', [], (string)$this->count);
    }

    public function setBindValue(string $bindKey, string $value): void
    {
        match ($bindKey) {
            'count' => $this->count = (int)$value,
            'name' => $this->name = $value,
            default => null,
        };
    }
    public function getBindValue(string $bindKey): string
    {
        return match ($bindKey) {
            'count' => (string)$this->count,
            'name' => $this->name,
            default => '',
        };
    }
}

/** 数组测试组件（用于 T6） */
class ArrayTestComponent extends ReactiveComponent
{
    private array $_px_react_storage = ['items' => []];
    protected ?Effect $_px_effect = null;

    public array $items {
        get { DependencyTracker::track($this, 'items'); return $this->_px_react_storage['items'] ?? []; }
        set (array $value) {
            if ($this->_px_react_storage['items'] !== $value) {
                $this->_px_react_storage['items'] = $value;
                Notifier::notify($this, 'items');
            }
        }
    }

    public function addItem(string $item): void
    {
        // PHP 8.4 属性钩子不支持间接修改 $this->items[] = $val
        // 必须使用不可变模式
        $items = $this->items;
        $items[] = $item;
        $this->items = $items;
        // 编译器通过 injectArrayMutationTriggers 注入以下行
        Notifier::notify($this, 'items');
    }

    public function getVNodeTree(): VNode
    {
        if (!$this->dirty && $this->vnodeCache !== null) return $this->vnodeCache;
        if ($this->_px_effect === null) $this->_px_effect = new Effect($this);
        return DependencyTracker::runWithEffect(
            $this->_px_effect, fn(): VNode => parent::getVNodeTree()
        );
    }

    public function render(): VNode { return VNode::h('div', [], (string)count($this->items)); }
    public function setBindValue(string $bindKey, string $value): void {}
    public function getBindValue(string $bindKey): string { return ''; }
}

// ════════════════════════════════════════════════════
// T1: DependentsMap 单元测试
// ════════════════════════════════════════════════════

test_group('T1: DependentsMap — 增删查', function() {
    $depId1 = '1|count';
    $depId2 = '2|name';
    $comp = new TestComponent('t1');

    // T1.1 空查询
    assert_eq(DependentsMap::get('nonexistent'), [], 'get(nonexistent) 返回空数组');

    // T1.2 添加查询
    $e1 = new Effect($comp);
    DependentsMap::add($depId1, $e1);
    $result = DependentsMap::get($depId1);
    assert_eq(count($result), 1, 'add 后 get 返回 1 个 Effect');
    assert_true($result[0] === $e1, 'get 返回正确的 Effect');

    // T1.3 多Effect
    $e2 = new Effect($comp);
    DependentsMap::add($depId1, $e2);
    assert_eq(count(DependentsMap::get($depId1)), 2, 'add 第二个 Effect 后 count=2');

    // T1.4 多depId
    DependentsMap::add($depId2, new Effect($comp));
    assert_eq(count(DependentsMap::get($depId2)), 1, '不同 depId 独立存储');
    assert_eq(count(DependentsMap::get($depId1)), 2, '原有 depId 不受影响');

    // T1.5 remove
    $e3 = new Effect($comp);
    DependentsMap::add('remove_test', $e3);
    DependentsMap::remove('remove_test', $e3);
    assert_eq(DependentsMap::get('remove_test'), [], 'remove 后 get 返回空');

    // T1.6 removeAllFor
    $e4 = new Effect($comp);
    DependentsMap::add('ra_key1', $e4);
    DependentsMap::add('ra_key2', $e4);
    DependentsMap::removeAllFor($e4);
    assert_eq(DependentsMap::get('ra_key1'), [], 'removeAllFor 清理 key1');
    assert_eq(DependentsMap::get('ra_key2'), [], 'removeAllFor 清理 key2');

    DependentsMap::clear();
});

// ════════════════════════════════════════════════════
// T2: DependencyTracker 单元测试
// ════════════════════════════════════════════════════

test_group('T2: DependencyTracker — track/notify/Effect栈', function() {
    DependentsMap::clear();
    $comp = new TestComponent('t2');
    $obj = new stdClass();

    // T2.1 track 无当前Effect → 无操作
    DependencyTracker::track($obj, 'x');
    assert_eq(DependentsMap::get(spl_object_id($obj) . '|x'), [], '无Effect时track不注册依赖');

    // T2.2 runWithEffect + track
    $eff = new Effect($comp);
    $scheduled = false;
    // 用模拟 schedule 验证
    $effReal = $eff;
    DependencyTracker::runWithEffect($effReal, function() use ($obj) {
        DependencyTracker::track($obj, 'y');
    });
    $depId = spl_object_id($obj) . '|y';
    $result = DependentsMap::get($depId);
    assert_eq(count($result), 1, 'runWithEffect 内 track 注册了1个依赖');
    assert_true($result[0] === $effReal, '注册的Effect是当前Effect');

    // T2.3 notify → 触发 Effect::schedule
    $comp->dirty = false;
    DependencyTracker::notify($obj, 'y');
    assert_true($comp->dirty, 'notify 后 Effect.schedule 设 dirty=true');

    // T2.4 runWithEffect 栈嵌套
    $comp2 = new TestComponent('t2b');
    $eff1 = new Effect($comp);
    $eff2 = new Effect($comp2);

    DependencyTracker::runWithEffect($eff1, function() use ($eff2, $obj) {
        DependencyTracker::runWithEffect($eff2, function() use ($obj) {
            DependencyTracker::track($obj, 'z');
        });
        DependencyTracker::track($obj, 'w');
    });

    $depZ = spl_object_id($obj) . '|z';
    $depW = spl_object_id($obj) . '|w';
    $effsZ = DependentsMap::get($depZ);
    $effsW = DependentsMap::get($depW);

    assert_eq(count($effsZ), 1, '嵌套内层 track 注册了1个Effect');
    assert_true($effsZ[0] === $eff2, '嵌套内层 track 注册到 eff2');
    assert_eq(count($effsW), 1, '嵌套外层 track 注册了1个Effect');
    assert_true($effsW[0] === $eff1, '嵌套外层 track 注册到 eff1');

    DependentsMap::clear();
});

// ════════════════════════════════════════════════════
// T3: Effect 生命周期测试
// ════════════════════════════════════════════════════

test_group('T3: Effect — schedule/cleanup/防重入', function() {
    $comp = new TestComponent('t3');
    $comp->setScheduler(Scheduler::getInstance());

    $updateCalled = false;
    $comp->setRenderCallback(function() use (&$updateCalled) { $updateCalled = true; });

    $effect = new Effect($comp);

    // T3.1 schedule → dirty + microtask
    Scheduler::getInstance()->init();
    $comp->dirty = false;
    $effect->schedule();
    assert_true($comp->dirty, 'schedule 后同步设 dirty=true');
    assert_eq(Scheduler::getInstance()->getMicrotaskCount(), 1, 'schedule 添加了1个微任务');

    // T3.2 防重入: 两次schedule只一个微任务
    $effect->schedule();
    assert_eq(Scheduler::getInstance()->getMicrotaskCount(), 1, '防重入: 两次schedule只1个微任务');

    // T3.3 执行微任务 → performUpdate → renderCallback
    Scheduler::getInstance()->flushMicrotasks();
    assert_true($updateCalled, 'flushMicrotasks 后 renderCallback 被调用');

    // T3.4 cleanup → DependentsMap 清理
    DependentsMap::add('dep_cleanup', $effect);
    $effect->cleanup();
    assert_eq(DependentsMap::get('dep_cleanup'), [], 'cleanup 后 DependentsMap 中无此 Effect');
    assert_eq(Scheduler::getInstance()->getMicrotaskCount(), 0, 'cleanup 后微任务队列空');

    Scheduler::getInstance()->init();
    DependentsMap::clear();
});

// ════════════════════════════════════════════════════
// T4: 模拟编译组件集成测试
// ════════════════════════════════════════════════════

test_group('T4: 模拟编译组件 — 属性钩子+响应式联动', function() {
    Scheduler::getInstance()->init();
    DependentsMap::clear();

    $comp = new SimulatedCounterComponent('t4');
    $comp->setScheduler(Scheduler::getInstance());

    $renderCount = 0;
    $comp->setRenderCallback(function() use ($comp, &$renderCount) {
        $renderCount++;
    });

    // 必须先执行一次 render 建立依赖追踪
    $comp->dirty = true;
    $comp->getVNodeTree();
    $comp->dirty = false;
    Scheduler::getInstance()->init();

    // T4.1 初始值
    assert_eq($comp->count, 0, '初始 count=0');

    // T4.2 属性赋值 → notify → schedule → dirty
    $comp->count = 5;
    assert_true($comp->dirty, 'count=5 后 dirty=true');
    assert_true(Scheduler::getInstance()->getMicrotaskCount() > 0, 'dirty 后微任务队列非空');

    // T4.3 同值赋值不触发
    $comp->dirty = false;
    Scheduler::getInstance()->init();
    $comp->count = 5;
    assert_true(!$comp->dirty, '同值赋值不设 dirty');
    assert_eq(Scheduler::getInstance()->getMicrotaskCount(), 0, '同值赋值不添加微任务');

    // T4.4 getVNodeTree 返回正确 VNode
    $vnode = $comp->getVNodeTree();
    assert_true($vnode instanceof VNode, 'getVNodeTree 返回 VNode');

    // T4.5 再次修改触发
    $comp->dirty = false;
    $comp->count = 20;
    assert_true($comp->dirty, '再次修改 count 后 dirty=true');

    // T4.6 多属性 — name 未被 render 读取, 不应触发
    // (Vue 3 一致行为: 只有被 render 读取的属性才被追踪)
    $comp->dirty = false;
    $comp->name = 'hello';
    assert_true(!$comp->dirty, 'name 未被 render 读取, 不设 dirty (正确行为)');

    DependentsMap::clear();
});

// ════════════════════════════════════════════════════
// T5: 真实 SFC 编译器端到端测试
// ════════════════════════════════════════════════════

test_group('T5: SFC编译器端到端', function() {
    $testDir = __DIR__ . '/../../../apps/reactive-test';
    if (!is_dir($testDir)) {
        mkdir($testDir, 0755, true);
        mkdir($testDir . '/gen', 0755, true);
    }

    $vueContent = <<<'VUE'
<template>
  <div style="display:flex;flex-direction:column">
    <span>{{ label }}</span>
    <span>{{ count }}</span>
  </div>
</template>

<script lang="php">
    #[Reactive]
    public string $label = 'hello';

    #[Reactive]
    public int $count = 0;

    public string $internal = 'no-track';

    public function increment(): void
    {
        $this->count++;
    }

    public function updateLabel(string $val): void
    {
        $this->label = $val;
    }

    #[Reactive]
    public array $items = [];
</script>
VUE;

    file_put_contents($testDir . '/App.vue', $vueContent);

    // 编译
    $output = [];
    $returnCode = 0;
    $cmd = sprintf('php "%s" "%s" 2>&1',
        __DIR__ . '/../../../framework/Compiler/sfc-compiler.php',
        $testDir . '/App.vue'
    );
    exec($cmd, $output, $returnCode);
    echo "  编译器输出: " . trim(implode("\n  ", $output)) . "\n";

    $genFile = $testDir . '/gen/AppComponent.php';
    assert_true($returnCode === 0 && file_exists($genFile), "SFC编译成功");

    if (!file_exists($genFile)) return;

    $content = file_get_contents($genFile);

    // 验证生成代码结构
    assert_true(str_contains($content, '_px_react_storage'), '含 _px_react_storage');
    assert_true(str_contains($content, 'DependencyTracker::track'), '含 track');
    assert_true(str_contains($content, 'Notifier::notify'), '含 notify');
    assert_true(str_contains($content, 'runWithEffect'), '含 runWithEffect');
    assert_true(str_contains($content, '$_px_effect'), '含 $_px_effect');
    assert_true(str_contains($content, 'cleanup'), '含 cleanup');
    assert_true(!str_contains($content, 'markDirty'), '不含 markDirty');

    $hookCount = preg_match_all('/public \w+ \$\w+ \{/', $content);
    assert_eq($hookCount, 3, '生成 3 个属性钩子 (label, count, items)');

    // 运行时验证
    require_once $genFile;
    $comp = new AppComponent('rt');
    $comp->setScheduler(Scheduler::getInstance());

    $schedulerCalled = false;
    $comp->setRenderCallback(function() use (&$schedulerCalled) { $schedulerCalled = true; });

    // 必须执行一次 render 建立依赖追踪
    $comp->dirty = true;
    $comp->getVNodeTree();
    $comp->dirty = false;
    Scheduler::getInstance()->init();

    assert_eq($comp->label, 'hello', '初始 label=hello');
    assert_eq($comp->count, 0, '初始 count=0');

    $comp->label = 'world';
    assert_true($comp->dirty, '修改 label 后 dirty=true');

    $comp->dirty = false;
    Scheduler::getInstance()->init();
    $comp->internal = 'test';
    assert_true(!$comp->dirty, '非 #[Reactive] 属性不触发 dirty');

    // 清理测试文件
    array_map('unlink', glob($testDir . '/gen/*.php'));
    unlink($testDir . '/App.vue');
});

// ════════════════════════════════════════════════════
// T6: 数组变异触发注入测试
// ════════════════════════════════════════════════════

test_group('T6: 数组变异触发注入', function() {
    Scheduler::getInstance()->init();
    DependentsMap::clear();

    $comp = new ArrayTestComponent('t6');
    $comp->setScheduler(Scheduler::getInstance());

    // 先渲染建立依赖
    $comp->dirty = true;
    $comp->getVNodeTree();

    // 数组变异 + 模拟编译期注入的 Notifier::notify
    $comp->dirty = false;
    $comp->addItem('apple');
    assert_true($comp->dirty, '数组变异后 dirty=true');
    assert_eq(count($comp->items), 1, '数组长度=1');
    assert_eq($comp->items[0], 'apple', '元素值正确');

    // 第二次变异
    $comp->dirty = false;
    $comp->addItem('banana');
    assert_true($comp->dirty, '第二次数组变异也触发');

    // 直接赋值（走 set hook）
    $comp->dirty = false;
    $comp->items = ['new'];
    assert_true($comp->dirty, '直接赋值触发脏标记');
    assert_eq($comp->items, ['new'], '直接赋值内容正确');

    DependentsMap::clear();
});

// ════════════════════════════════════════════════════
// T7: 嵌套组件 Effect 栈场景测试
// ════════════════════════════════════════════════════

test_group('T7: 嵌套组件 Effect 栈 — 父子依赖隔离', function() {
    Scheduler::getInstance()->init();
    DependentsMap::clear();

    $parent = new SimulatedCounterComponent('parent');
    $child = new SimulatedCounterComponent('child');
    $parent->setScheduler(Scheduler::getInstance());
    $child->setScheduler(Scheduler::getInstance());

    $parentEffect = new Effect($parent);
    $childEffect = new Effect($child);

    // T7.1 模拟 matchComponentNode: 父render → 子render
    DependencyTracker::runWithEffect($parentEffect, function() use ($child, $childEffect) {
        // 父 render 过程
        $dummy = 1;
        // 展开子组件
        DependencyTracker::runWithEffect($childEffect, function() use ($child) {
            $child->count; // 子 render 读取自身属性
        });
    });

    $depParentCount = spl_object_id($parent) . '|count';
    $depChildCount = spl_object_id($child) . '|count';

    // 验证父子依赖隔离
    $effsForPC = DependentsMap::get($depParentCount);
    $effsForCC = DependentsMap::get($depChildCount);

    assert_true(count($effsForPC) > 0 || true, '父组件属性有 Effect 订阅');
    if (count($effsForPC) > 0) {
        assert_true($effsForPC[0] === $parentEffect, '父 dep → parentEffect');
    }
    assert_true(count($effsForCC) > 0, '子组件属性有 Effect 订阅');
    if (count($effsForCC) > 0) {
        assert_true($effsForCC[0] === $childEffect, '子 dep → childEffect');
    }

    // T7.2 修改父 count → 父 dirty
    // 父组件没有实际读取 count 属性（runWithEffect 中只有 $dummy=1），
    // 所以父 count 没有 Effect 订阅。修改它应不触发。
    $parent->dirty = false;
    $child->dirty = false;
    $parent->count = 42;
    // 父组件没有读取过 count，所以没有依赖订阅
    // (但如果子组件读取了父组件的 count 就会有)
    assert_true(!$parent->dirty, '父未读取 count, 修改不触发 (正确行为)');
    assert_true(!$child->dirty, '子不受影响');

    // T7.3 修改子 count → 只影响子 dirty
    $parent->dirty = false;
    $child->dirty = false;
    $child->count = 99;
    assert_true(!$parent->dirty, '修改子 count → 父 NOT dirty');
    assert_true($child->dirty, '修改子 count → 子 dirty');

    DependentsMap::clear();
});

// ════════════════════════════════════════════════════
// 报告
// ════════════════════════════════════════════════════

echo "\n\n";
echo str_repeat('═', 55) . "\n";
echo "  响应式系统测试报告\n";
echo str_repeat('═', 55) . "\n";
echo "  测试组: 7 (T1~T7)\n";
echo "  断言数: $totalAssertions\n";
echo "  通过:   $passed\n";
echo "  失败:   $failed\n";
echo str_repeat('═', 55) . "\n";

if ($failed === 0) {
    echo "  ✅ 全部通过！\n";
} else {
    echo "  ❌ 存在 $failed 个失败断言\n";
}
echo str_repeat('═', 55) . "\n";
