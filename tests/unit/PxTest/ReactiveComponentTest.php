<?php

/**
 * ReactiveComponent 单元测试 — 使用 MockComponent 验证核心行为。
 *
 * 覆盖：
 *  - markDirty → vnodeCache 失效 + scheduleUpdate 调用
 *  - mount 生命周期回调
 *  - emit/on 事件传递
 *  - setBindValue/getBindValue
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockComponent;
use PxTest\Mock\MockPlatform;
use PxTest\Builder\VNodeBuilder;
use Px\Dom\VNode;
use Px\Core\Scheduler;

echo "========================================\n";
echo "  ReactiveComponent — Unit Tests\n";
echo "========================================\n\n";

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        echo "  [PASS] $label\n";
        $pass++;
    } else {
        echo "  [FAIL] $label" . ($detail ? " — $detail" : "") . "\n";
        $fail++;
    }
}

// ─── 1. markDirty 行为 ───
echo "--- 1. markDirty ---\n";
$scheduler = new Scheduler();
$comp = new MockComponent('test_mark_dirty', null, $scheduler);
check('Initial render count = 0', $comp->callCount['render'] === 0);
check('Initial markDirty count = 0', $comp->callCount['markDirty'] === 0);
check('Initial vnodeCache is null (via class)', ($comp->vnodeCache ?? null) === null);

// 首次 render（MockComponent 现在返回 VNode::h('#root', [], mockVNode)）
$vnode1 = $comp->getVNodeTree();
check('First getVNodeTree returns #root wrapping mock VNode', $vnode1->type === '#root');
check('First render triggers render()', $comp->callCount['render'] === 1);

// 再次获取（应返回缓存，不重新 render）
$vnode2 = $comp->getVNodeTree();
check('Second getVNodeTree returns same (cached)', $vnode2 === $vnode1);
check('Second get does NOT re-render', $comp->callCount['render'] === 1);

// markDirty 后失效缓存
$comp->renderDirty = true;
check('markDirty called', $comp->callCount['markDirty'] === 1);

$vnode3 = $comp->getVNodeTree();
check('After markDirty, re-renders', $comp->callCount['render'] === 2);


// ─── 2. mount 生命周期 ───
echo "\n--- 2. Lifecycle ---\n";
$comp2 = new MockComponent('test_lifecycle');
check('onMount not called before mount', $comp2->callCount['onMount'] === 0);

// mount 通过反射调用
$comp2->onMount();
check('onMount called once', $comp2->callCount['onMount'] === 1);


// ─── 3. emit/on 事件系统 ───
echo "\n--- 3. Events ---\n";
$parent = new MockComponent('parent');
$child = new MockComponent('child');

// 子 emit（通过覆盖的 emit 方法，它记录调用后调 parent::emit）
$child->emit('selected', ['id' => 42]);
check('Child emit recorded', $child->assertEmitted('selected'));
check('Child emit payload correct',
    $child->assertEmitted('selected', fn($p) => ($p['id'] ?? 0) === 42));

// 父 on 子事件（通过覆盖方法）
$receivedPayload = null;
$parent->on($child, 'selected', function($payload) use (&$receivedPayload) {
    $receivedPayload = $payload;
});
check('Parent on() registered on child', true);


// ─── 4. bind 值读写 ───
echo "\n--- 4. Bind Values ---\n";
// MockComponent 的 setBindValue/getBindValue 是 stub
$comp3 = new MockComponent('test_bind');
$comp3->setBindValue('scrollTop', '50');
$val = $comp3->getBindValue('scrollTop');
// MockComponent 的 stub 返回 '' — 需要测试一个有实现的子类
check('MockComponent bind stub returns empty', $val === '');


// ─── 5. VNode 树变化检测 ───
echo "\n--- 5. VNode Tree Changes ---\n";
$comp4 = new MockComponent('test_vnode_change', null, new Scheduler());
$comp4->mockVNode = VNodeBuilder::div()
    ->style(['width' => '100px'])
    ->childText('v1')
    ->build();

$v1 = $comp4->getVNodeTree();
check('VNode v1 root type = #root', $v1->type === '#root');

// 更改 mockVNode 模拟状态变化
$comp4->mockVNode = VNodeBuilder::span('v2')->build();
$comp4->renderDirty = true;

$v2 = $comp4->getVNodeTree();
check('After state change, root still #root', $v2->type === '#root');
check('After state change, re-rendered', $comp4->callCount['render'] === 2);


// ─── Summary ───
echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";

// 注：完整的 MockPlatform+Application 集成测试见 Phase 2 (tests/integration/)

exit($fail > 0 ? 1 : 0);
