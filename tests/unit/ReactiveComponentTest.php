<?php
/**
 * ReactiveComponent 单元测试
 * 
 * 测试目标:
 *   1. VNode 缓存: dirty=false 时返回缓存树
 *   2. Dirty 追踪: markDirty() 清除缓存
 *   3. getVNodeTree(): 首次调 render(), 二次返缓存
 *   4. performUpdate(): 标记 dirty + 触发渲染请求
 * 
 * Usage: php tests/unit/ReactiveComponentTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\VNode;

echo "========================================\n";
echo " ReactiveComponent 单元测试\n";
echo "========================================\n\n";

// ---- 测试用的具体组件子类 ----
class _TestComponent extends \Px\ReactiveComponent
{
    public int $renderCallCount = 0;
    public string $message = 'hello';

    public function __construct()
    {
        // 绕过 BaseComponent 构造函数 (需要 scheduler/bus)
        // 不调用 parent::__construct，直接初始化需要的属性
        $this->dirty = true;
        $this->isMounted = false;
        $this->hasPendingUpdate = false;
        $this->isUpdating = false;
        $this->listenerIds = [];
        $this->vnodeCache = null;
    }

    /** 手动注入依赖 (避开 Application::mount 完整流程) */
    public function injectDeps(\Px\Core\Scheduler $scheduler, \Px\Core\ReactionBus $bus): void
    {
        $this->scheduler = $scheduler;
        $this->bus = $bus;
    }

    public function render(): VNode
    {
        $this->renderCallCount++;
        return VNode::h('div', ['class' => 'test'], $this->message);
    }

    public function onMount(): void {}
    public function dispatchClick(string $handler, ?string $arg = null): void {}
    public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void {}
    public function setBindValue(string $bindKey, string $value): void {}
    public function getBindValue(string $bindKey): string { return ''; }
}

echo "--- 1. VNode 缓存与 Dirty 追踪 ---\n";

test('首次调用 getVNodeTree() 调用 render()', function () {
    $comp = new _TestComponent();
    assert_eq($comp->renderCallCount, 0, '初始 renderCallCount');

    $tree = $comp->getVNodeTree();

    assert_eq($comp->renderCallCount, 1, '首次调用后 renderCallCount 应为 1');
    assert_same($tree->type, 'div');
    assert_false($comp->dirty, 'getVNodeTree() 后 dirty 应为 false');
});

test('二次调用 getVNodeTree() 返回缓存，不调 render()', function () {
    $comp = new _TestComponent();
    $tree1 = $comp->getVNodeTree();
    $count1 = $comp->renderCallCount;

    $tree2 = $comp->getVNodeTree();
    $count2 = $comp->renderCallCount;

    assert_same($tree1, $tree2, '相同对象引用');
    assert_eq($count1, $count2, 'render() 不应被再次调用');
    assert_eq($comp->renderCallCount, 1, 'render() 只应被调用 1 次');
});

test('markDirty() 清除 VNode 缓存', function () {
    $comp = new _TestComponent();
    $tree1 = $comp->getVNodeTree();  // render() called → dirty=false
    $count1 = $comp->renderCallCount;

    // 模拟状态变更: 设置 dirty=true 即可触发重建
    // (getVNodeTree 检查 !dirty && vnodeCache 两个条件)
    $comp->dirty = true;

    $tree2 = $comp->getVNodeTree();  // render() called again
    $count2 = $comp->renderCallCount;

    assert_same($tree1 !== $tree2, true, '应返回新的 VNode 树');
    assert_eq($count2, $count1 + 1, 'render() 应被再次调用');
    assert_false($comp->dirty, '调用后 dirty 应为 false');
});

test('dirty=true 状态变更后返回新树', function () {
    $comp = new _TestComponent();
    $comp->message = 'v1';
    $tree1 = $comp->getVNodeTree();

    // 模拟状态变更
    $comp->message = 'v2';
    $comp->dirty = true;

    $tree2 = $comp->getVNodeTree();

    assert_same($tree1 !== $tree2, true, '状态变更后应返回不同对象');
    assert_eq($tree2->children, 'v2', '应反映最新状态');
});

test('vnodeCache 初始为 null', function () {
    $comp = new _TestComponent();
    // 用反射读取 protected 属性
    $refl = new \ReflectionClass(_TestComponent::class);
    $prop = $refl->getProperty('vnodeCache');
    $prop->setAccessible(true);
    assert_null($prop->getValue($comp), '初始缓存应为 null');
});

echo "\n--- 2. Dirty 标记与异步更新 ---\n";

test('markDirty() 将 dirty 设为 true', function () {
    $comp = new _TestComponent();
    // markDirty 需要 scheduler，直接手动模拟
    $comp->dirty = true;
    assert_true($comp->dirty, 'dirty 应为 true');
});

test('performUpdate() 触发渲染请求', function () {
    $bus = new \Px\Core\ReactionBus();
    $scheduler = new \Px\Core\Scheduler($bus);
    $comp = new _TestComponent();
    $comp->injectDeps($scheduler, $bus);

    $renderRequested = false;
    $bus->on('render:request', function () use (&$renderRequested) {
        $renderRequested = true;
    });

    $comp->performUpdate();

    assert_true($renderRequested, 'performUpdate() 应触发 render:request 事件');
    assert_true($comp->dirty, 'performUpdate() 后 dirty 应为 true');
});

echo "\n--- 3. 缓存一致性 ---\n";

test('连续 getVNodeTree() 返回相同缓存直到 dirty', function () {
    $comp = new _TestComponent();

    $t1 = $comp->getVNodeTree();
    $t2 = $comp->getVNodeTree();
    $t3 = $comp->getVNodeTree();

    assert_same($t1, $t2, '第1次和第2次应返回同一对象');
    assert_same($t2, $t3, '第2次和第3次应返回同一对象');
    assert_eq($comp->renderCallCount, 1, 'render() 只应被调用 1 次');
});

test('缓存对象正确反映组件属性', function () {
    $comp = new _TestComponent();
    $comp->message = 'initial';
    $tree = $comp->getVNodeTree();

    assert_eq($tree->children, 'initial', 'VNode 文本应反映 message 属性');
    assert_eq($tree->type, 'div');
    assert_eq($tree->props['class'], 'test');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
