<?php
/**
 * Block Tree / dynamicChildren fast-path 单元测试
 *
 * 测试目标:
 *   1. VNode::withDynamicChildren() setter
 *   2. patchVNodeTree 快速路径命中: dynamicChildren 长度一致 → 迭代
 *   3. patchVNodeTree 快速路径降级: 长度变化 / 单侧 block → 走全 diff
 *   4. 快速路径下静态子孙保留旧引用（未被 patch）
 *   5. 快速路径下动态子孙被正确更新
 *   6. PerfCounter block_fastpath_hit / _miss 埋点
 *
 * Usage: php tests/unit/BlockTreeTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Dom\VNode;
use Px\Core\PerfCounter;

// 启用 PerfCounter（默认需 PX_PERF=1），重置内部 initialized 状态
putenv('PX_PERF=1');
{
    $refl = new \ReflectionClass(PerfCounter::class);
    $initProp = $refl->getProperty('initialized');
    $initProp->setAccessible(true);
    $initProp->setValue(null, false);
}

echo "========================================\n";
echo " Block Tree / dynamicChildren 单元测试\n";
echo "========================================\n\n";

// ---- 测试用具体组件，允许外部注入 render 结果 ----
class _BlockTestComponent extends \Px\Component\ReactiveComponent
{
    /** @var VNode|null 下一次 render() 返回的树 */
    public ?VNode $nextTree = null;

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
        if ($this->nextTree === null) {
            return VNode::h('div');
        }
        return $this->nextTree;
    }

    public function onMount(): void {}
    public function dispatchClick(string $handler, ?string $arg = null): void {}
    public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void {}
    public function setBindValue(string $bindKey, string $value): void {}
    public function getBindValue(string $bindKey): string { return ''; }

    /** 反射调用私有 patchVNodeTree，供测试直接构造场景 */
    public function callPatch(VNode $old, VNode $new): void
    {
        $refl = new \ReflectionClass(\Px\Component\ReactiveComponent::class);
        $m = $refl->getMethod('patchVNodeTree');
        $m->setAccessible(true);
        $m->invoke($this, $old, $new);
    }
}

echo "--- 1. VNode dynamicChildren 字段 + setter ---\n";

test('VNode 默认 dynamicChildren 为 null', function () {
    $v = VNode::h('div');
    assert_null($v->dynamicChildren, '默认应为 null');
});

test('withDynamicChildren 设置数组并链式返回', function () {
    $child = VNode::h('span', [':style' => '$color'])->withPatchFlags(1);
    $v = VNode::h('div', null, [$child])->withDynamicChildren([$child]);
    assert_same(count($v->dynamicChildren), 1, '数组长度');
    assert_same($v->dynamicChildren[0], $child, '引用一致');
});

test('withDynamicChildren 支持空数组（block root 无动态子孙）', function () {
    $v = VNode::h('div')->withDynamicChildren([]);
    assert_same($v->dynamicChildren, [], '空数组');
    assert_same(count($v->dynamicChildren), 0);
});

echo "\n--- 2. patchVNodeTree 快速路径命中 ---\n";

test('block 长度一致 → 走快速路径 + hit 计数递增', function () {
    PerfCounter::snapshot(); // 清空

    $comp = new _BlockTestComponent();

    // 旧树：block root，2 个静态 span 中夹 1 个动态 span
    $oldDyn = VNode::h('span', [':style' => 'color:red'])
        ->withPatchFlags(VNode::PATCH_STYLE);
    $oldRoot = VNode::h('div', ['class' => 'container'], [
        VNode::h('span', null, 'static-A'),
        $oldDyn,
        VNode::h('span', null, 'static-B'),
    ]);
    $oldRoot->dynamicChildren = [$oldDyn];

    // 新树：结构相同，动态节点 :style 变化
    $newDyn = VNode::h('span', [':style' => 'color:blue'])
        ->withPatchFlags(VNode::PATCH_STYLE);
    $newRoot = VNode::h('div', ['class' => 'container'], [
        VNode::h('span', null, 'static-A'),
        $newDyn,
        VNode::h('span', null, 'static-B'),
    ]);
    $newRoot->dynamicChildren = [$newDyn];

    $comp->callPatch($oldRoot, $newRoot);

    $snap = PerfCounter::snapshot();
    $hit = $snap['block_fastpath_hit']['count'] ?? 0;
    $miss = $snap['block_fastpath_miss']['count'] ?? 0;
    assert_true($hit >= 1, '应命中 block fast-path（实际 hit=' . $hit . '）');
    assert_true($miss === 0, '不应有 miss（实际 miss=' . $miss . '）');
});

test('快速路径：动态子孙 props 被更新', function () {
    $comp = new _BlockTestComponent();

    $oldDyn = VNode::h('span', [':style' => 'color:red'])
        ->withPatchFlags(VNode::PATCH_STYLE);
    $oldRoot = VNode::h('div', null, [$oldDyn]);
    $oldRoot->dynamicChildren = [$oldDyn];

    $newDyn = VNode::h('span', [':style' => 'color:blue'])
        ->withPatchFlags(VNode::PATCH_STYLE);
    $newRoot = VNode::h('div', null, [$newDyn]);
    $newRoot->dynamicChildren = [$newDyn];

    $comp->callPatch($oldRoot, $newRoot);

    assert_eq($oldDyn->props[':style'], 'color:blue', '动态节点 :style 应已更新');
});

test('快速路径：静态兄弟保留旧引用（未被递归 patch）', function () {
    $comp = new _BlockTestComponent();

    $oldStaticA = VNode::h('span', null, 'A');
    $oldDyn = VNode::h('span', [':style' => 'red'])->withPatchFlags(VNode::PATCH_STYLE);
    $oldStaticB = VNode::h('span', null, 'B');

    $oldRoot = VNode::h('div', null, [$oldStaticA, $oldDyn, $oldStaticB]);
    $oldRoot->dynamicChildren = [$oldDyn];

    // 新树：静态引用不同但 children 数组长度一致（模拟编译期生成的新树）
    $newStaticA = VNode::h('span', null, 'A');
    $newDyn = VNode::h('span', [':style' => 'blue'])->withPatchFlags(VNode::PATCH_STYLE);
    $newStaticB = VNode::h('span', null, 'B');

    $newRoot = VNode::h('div', null, [$newStaticA, $newDyn, $newStaticB]);
    $newRoot->dynamicChildren = [$newDyn];

    $comp->callPatch($oldRoot, $newRoot);

    // 快速路径不 patch 静态子节点 → 旧 children 数组仍保留旧引用
    // （若走全 diff，old->children 会被 patchChildrenArray 重构）
    assert_same($oldRoot->children[0], $oldStaticA, '静态 A 引用未变（快速路径跳过）');
    assert_same($oldRoot->children[2], $oldStaticB, '静态 B 引用未变（快速路径跳过）');
    assert_same($oldRoot->children[1], $oldDyn, '动态节点引用未变（原地更新）');
});

echo "\n--- 3. 快速路径降级场景 ---\n";

test('block 长度变化 → 降级全 diff + miss 计数递增', function () {
    PerfCounter::snapshot(); // 清空

    $comp = new _BlockTestComponent();

    $oldDyn1 = VNode::h('span', [':style' => 'a'])->withPatchFlags(VNode::PATCH_STYLE);
    $oldRoot = VNode::h('div', null, [$oldDyn1]);
    $oldRoot->dynamicChildren = [$oldDyn1];

    $newDyn1 = VNode::h('span', [':style' => 'b'])->withPatchFlags(VNode::PATCH_STYLE);
    $newDyn2 = VNode::h('span', [':style' => 'c'])->withPatchFlags(VNode::PATCH_STYLE);
    $newRoot = VNode::h('div', null, [$newDyn1, $newDyn2]);
    $newRoot->dynamicChildren = [$newDyn1, $newDyn2]; // 长度 2 vs 旧 1

    $comp->callPatch($oldRoot, $newRoot);

    $snap = PerfCounter::snapshot();
    $hit = $snap['block_fastpath_hit']['count'] ?? 0;
    $miss = $snap['block_fastpath_miss']['count'] ?? 0;
    assert_true($miss >= 1, '长度变化应降级 miss（实际 miss=' . $miss . '）');
    assert_true($hit === 0, '不应命中（实际 hit=' . $hit . '）');
});

test('单侧 block → 降级全 diff + miss 计数', function () {
    PerfCounter::snapshot();

    $comp = new _BlockTestComponent();

    $oldDyn = VNode::h('span', [':style' => 'x'])->withPatchFlags(VNode::PATCH_STYLE);
    $oldRoot = VNode::h('div', null, [$oldDyn]);
    $oldRoot->dynamicChildren = [$oldDyn]; // 旧是 block

    $newDyn = VNode::h('span', [':style' => 'y'])->withPatchFlags(VNode::PATCH_STYLE);
    $newRoot = VNode::h('div', null, [$newDyn]); // 新不是 block

    $comp->callPatch($oldRoot, $newRoot);

    $snap = PerfCounter::snapshot();
    $miss = $snap['block_fastpath_miss']['count'] ?? 0;
    assert_true($miss >= 1, '单侧 block 应降级（实际 miss=' . $miss . '）');
});

test('两侧均无 block（单子节点递归路径）→ 不触发 hit/miss 计数', function () {
    PerfCounter::snapshot();

    $comp = new _BlockTestComponent();

    // 单子节点场景（非数组）：递归 patchVNodeTree 会走到 patchProps
    $oldChild = VNode::h('span', [':style' => 'x'])->withPatchFlags(VNode::PATCH_STYLE);
    $oldRoot = VNode::h('div', null, $oldChild);

    $newChild = VNode::h('span', [':style' => 'y'])->withPatchFlags(VNode::PATCH_STYLE);
    $newRoot = VNode::h('div', null, $newChild);

    $comp->callPatch($oldRoot, $newRoot);

    $snap = PerfCounter::snapshot();
    $hit = $snap['block_fastpath_hit']['count'] ?? 0;
    $miss = $snap['block_fastpath_miss']['count'] ?? 0;
    assert_true($hit === 0, '无 block 不应 hit');
    assert_true($miss === 0, '无 block 不应 miss');
    // 递归路径仍应工作
    assert_eq($oldChild->props[':style'], 'y', '递归路径正确更新');
});

echo "\n--- 4. 快速路径与 patchFlags 协同 ---\n";

test('block root 自身 patchFlags 生效 (patchProps 被调用)', function () {
    $comp = new _BlockTestComponent();

    $oldDyn = VNode::h('span', [':style' => 'x'])->withPatchFlags(VNode::PATCH_STYLE);
    $oldRoot = VNode::h('div', ['class' => 'old-class', ':style' => 'padding:0'])
        ->withPatchFlags(VNode::PATCH_STYLE); // block root 自身 :style 动态
    $oldRoot->children = [$oldDyn];
    $oldRoot->dynamicChildren = [$oldDyn];

    $newDyn = VNode::h('span', [':style' => 'y'])->withPatchFlags(VNode::PATCH_STYLE);
    $newRoot = VNode::h('div', ['class' => 'old-class', ':style' => 'padding:10'])
        ->withPatchFlags(VNode::PATCH_STYLE);
    $newRoot->children = [$newDyn];
    $newRoot->dynamicChildren = [$newDyn];

    $comp->callPatch($oldRoot, $newRoot);

    assert_eq($oldRoot->props[':style'], 'padding:10', 'block root 自身 :style 应被更新');
    assert_eq($oldDyn->props[':style'], 'y', '动态子孙 :style 应被更新');
});

test('block root PATCH_NONE → 只跳过 root props，子孙仍 patch', function () {
    $comp = new _BlockTestComponent();

    $oldDyn = VNode::h('span', [':style' => 'x'])->withPatchFlags(VNode::PATCH_STYLE);
    // block root 无 patchFlags，但仍需迭代 dynamicChildren
    $oldRoot = VNode::h('div', ['class' => 'fixed'], [$oldDyn]);
    $oldRoot->dynamicChildren = [$oldDyn];

    $newDyn = VNode::h('span', [':style' => 'y'])->withPatchFlags(VNode::PATCH_STYLE);
    $newRoot = VNode::h('div', ['class' => 'fixed'], [$newDyn]);
    $newRoot->dynamicChildren = [$newDyn];

    $comp->callPatch($oldRoot, $newRoot);

    assert_eq($oldRoot->props['class'], 'fixed', 'root class 不应变化');
    assert_eq($oldDyn->props[':style'], 'y', '子孙 :style 应更新');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
