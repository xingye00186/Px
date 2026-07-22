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

echo "\n--- 5. B-Phase 2.5: #list VNode fast-path ---\n";

test('hList 工厂方法创建 #list VNode', function () {
    $c1 = VNode::h('div', null, 'a');
    $c2 = VNode::h('div', null, 'b');
    $list = VNode::hList([$c1, $c2], VNode::PATCH_KEYED_LIST);

    assert_eq($list->type, '#list', '#list VNode type 应为 #list');
    assert_eq($list->patchFlags, VNode::PATCH_KEYED_LIST, 'patchFlags 应为 PATCH_KEYED_LIST');
    assert_true(is_array($list->children) && count($list->children) === 2, 'children 应为两元数组');
});

test('childrenToArray 展平 #list 子节点（layout 透明）', function () {
    $a = VNode::h('span', null, 'A');
    $b = VNode::h('span', null, 'B');
    $c = VNode::h('span', null, 'C');
    $list = VNode::hList([$a, $b], VNode::PATCH_KEYED_LIST);

    // 父节点包含 [static, #list([a,b]), static]
    $result = VNode::childrenToArray([$c, $list, $a]);

    assert_eq(count($result), 4, '#list 展平后共 4 个真实子节点（c + a + b + a）');
    assert_eq($result[0]->children, 'C', '首项 = c');
    assert_eq($result[1]->children, 'A', '第二项 = #list 展平第一项 a');
    assert_eq($result[2]->children, 'B', '第三项 = #list 展平第二项 b');
});

test('childrenToArray 单个 #list children 也展平', function () {
    $a = VNode::h('span', null, 'A');
    $b = VNode::h('span', null, 'B');
    $list = VNode::hList([$a, $b], VNode::PATCH_UNKEYED_LIST);

    // 父节点的 children 字段 = 单个 #list VNode
    $result = VNode::childrenToArray($list);

    assert_eq(count($result), 2, '单 #list 展平为 2 个子节点');
    assert_eq($result[0]->children, 'A', '首项 a');
    assert_eq($result[1]->children, 'B', '第二项 b');
});

test('patchVNodeTree 遇到 #list 走 patchChildrenArray（keyed diff）', function () {
    $comp = new _BlockTestComponent();

    // 旧 #list: [key=1 A, key=2 B]
    $oldA = VNode::hKey('div', null, 'A', '1');
    $oldB = VNode::hKey('div', null, 'B', '2');
    $oldList = VNode::hList([$oldA, $oldB], VNode::PATCH_KEYED_LIST);

    // 新 #list: [key=2 B*, key=1 A*] — 交换顺序，内容更新
    $newA = VNode::hKey('div', null, 'A_new', '1');
    $newB = VNode::hKey('div', null, 'B_new', '2');
    $newList = VNode::hList([$newB, $newA], VNode::PATCH_KEYED_LIST);

    $comp->callPatch($oldList, $newList);

    // list_patch 计数器递增
    $snap = PerfCounter::snapshot();
    $lp = $snap['list_patch']['count'] ?? 0;
    assert_true($lp >= 1, 'list_patch 计数器应递增 (got ' . $lp . ')');

    // 旧 list 的 children 已重新排列：首项 = key=2 的旧对象 (B_new)，次项 = key=1 的旧对象 (A_new)
    $ch = $oldList->children;
    assert_true(is_array($ch) && count($ch) === 2, '#list children 应仍为 2 元');
    assert_eq($ch[0]->key, '2', '首项 key = 2');
    assert_eq($ch[0]->children, 'B_new', '首项 content 更新为 B_new');
    assert_eq($ch[1]->key, '1', '次项 key = 1');
    assert_eq($ch[1]->children, 'A_new', '次项 content 更新为 A_new');
    // 旧对象复用：$ch[0] === $oldB，$ch[1] === $oldA
    assert_true($ch[0] === $oldB, '#list keyed diff 应复用旧 VNode (key=2)');
    assert_true($ch[1] === $oldA, '#list keyed diff 应复用旧 VNode (key=1)');
});

test('patchVNodeTree #list unkeyed 按 index+type 匹配', function () {
    // 先重置 counter
    PerfCounter::snapshot();

    $comp = new _BlockTestComponent();

    $oldA = VNode::h('span', ['class' => 'a'], 'A');
    $oldB = VNode::h('span', ['class' => 'b'], 'B');
    $oldList = VNode::hList([$oldA, $oldB], VNode::PATCH_UNKEYED_LIST);

    $newA = VNode::h('span', ['class' => 'a2'], 'A2');
    $newB = VNode::h('span', ['class' => 'b2'], 'B2');
    $newList = VNode::hList([$newA, $newB], VNode::PATCH_UNKEYED_LIST);

    $comp->callPatch($oldList, $newList);

    $snap = PerfCounter::snapshot();
    assert_true(($snap['list_patch']['count'] ?? 0) >= 1, 'list_patch 计数器应递增');

    // 旧 list 的 children 保留旧对象但 content 更新
    $ch = $oldList->children;
    assert_true($ch[0] === $oldA, 'unkeyed 首项应复用旧 VNode a');
    assert_true($ch[1] === $oldB, 'unkeyed 次项应复用旧 VNode b');
    assert_eq($ch[0]->children, 'A2', '首项 content 更新');
    assert_eq($ch[1]->children, 'B2', '次项 content 更新');
});

test('patchVNodeTree #list 长度变化（新增/删除项）', function () {
    PerfCounter::snapshot();
    $comp = new _BlockTestComponent();

    $oldA = VNode::hKey('div', null, 'A', '1');
    $oldB = VNode::hKey('div', null, 'B', '2');
    $oldList = VNode::hList([$oldA, $oldB], VNode::PATCH_KEYED_LIST);

    // 新增 key=3，删除 key=1
    $newB = VNode::hKey('div', null, 'B_new', '2');
    $newC = VNode::hKey('div', null, 'C', '3');
    $newList = VNode::hList([$newB, $newC], VNode::PATCH_KEYED_LIST);

    $comp->callPatch($oldList, $newList);

    $ch = $oldList->children;
    assert_eq(count($ch), 2, 'children 长度应为 2');
    assert_true($ch[0] === $oldB, '首项応复用旧 key=2 VNode');
    assert_eq($ch[1]->key, '3', '次项应为新 key=3 VNode');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
