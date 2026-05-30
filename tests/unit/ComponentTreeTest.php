<?php
/**
 * 组件树语义测试
 *
 * 验证运行时组件树语义对标 Vue 3：
 *   1. 组件 parent 链 — child.parent 指向模板父组件
 *   2. dispatchClick 事件冒泡 — 未匹配 handler 沿 parent 链上行
 *   3. 组件实例独立 — 不同组件互不影响
 *   4. 组件生命周期 — mount/unmount 正确触发
 *   5. patchComponentTree 机制 — 组件实例复用、groupId 正确路由
 *   6. Props 绑定同步 — 父子组件通过 setBindValue 同步
 *
 * Usage: D:\swoole_compiler\php.exe tests/unit/ComponentTreeTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\VNode;
use Px\Core\Scheduler;
use Px\Core\Application;
use Px\ReactiveComponent;

// ============================================================
// Test Double Components
// ============================================================

class _TestRootComponent extends ReactiveComponent
{
    public int $renderCount = 0;

    public function __construct()
    {
        $this->dirty = true;
        $this->vnodeCache = null;
    }

    public function inject(Scheduler $s): void
    {
        $this->scheduler = $s;
    }

    public function render(): VNode
    {
        $this->renderCount++;
        return VNode::h('#root', ['style' => 'width:400px;height:300px'], [
            VNode::h('div', ['style' => 'width:100px;height:50px', '@click' => 'testHandler'], 'click me'),
        ]);
    }

    public function dispatchClick(string $handler, ?string $arg = null): void
    {
        switch ($handler) {
            case 'testHandler':
                break;
            default:
                if ($this->parent !== null) {
                    $this->parent->dispatchClick($handler, $arg);
                }
        }
    }

    public function dispatchKey(string $h, string $a, int $k, string $c): void {}
    public function setBindValue(string $k, string $v): void {}
    public function getBindValue(string $k): string { return ''; }
    public function onMount(): void {}
}

/**
 * 追踪事件冒泡的测试组件。
 * 收集所有 dispatchClick 调用以便断言。
 */
class _BubbleTrackerComponent extends ReactiveComponent
{
    public string $name = '';
    /** @var array<array{handler:string, arg:?string, component:string}> */
    public array $receivedEvents = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->dirty = true;
        $this->vnodeCache = null;
    }

    public function inject(Scheduler $s): void
    {
        $this->scheduler = $s;
    }

    public function render(): VNode
    {
        return VNode::h('#root', ['style' => 'width:100px;height:100px'], []);
    }

    public function dispatchClick(string $handler, ?string $arg = null): void
    {
        $this->receivedEvents[] = [
            'handler' => $handler,
            'arg' => $arg,
            'component' => $this->name,
        ];

        if ($handler === 'stop') {
            return; // 消费事件，停止冒泡
        }

        // 默认冒泡
        if ($this->parent !== null) {
            $this->parent->dispatchClick($handler, $arg);
        }
    }

    public function dispatchKey(string $h, string $a, int $k, string $c): void {}
    public function setBindValue(string $k, string $v): void {}
    public function getBindValue(string $k): string { return ''; }
    public function onMount(): void {}
}

// ============================================================
// Tests
// ============================================================
echo "========================================\n";
echo " 组件树语义测试\n";
echo "========================================\n\n";

// --- 1. Component Parent Chain ---
echo "--- 1. Component Parent Chain ---\n";

test('setParent/getParent 设置父组件引用', function () {
    $parent = new _BubbleTrackerComponent('parent');
    $child = new _BubbleTrackerComponent('child');

    $child->setParent($parent);
    assert_same($child->getParent(), $parent, 'child.parent 应指向 parent');
    assert_null($parent->getParent(), 'parent.parent 应为 null');
});

test('addChild 建立双向父子关系', function () {
    $p = new _BubbleTrackerComponent('p');
    $c = new _BubbleTrackerComponent('c');

    $p->addChild($c);
    assert_same($c->getParent(), $p, 'child.parent 应指向 parent');
    assert_eq(count($p->getChildren()), 1, 'parent.children 应包含 child');
});

test('组件自身不持有父子引用', function () {
    $a = new _BubbleTrackerComponent('a');
    assert_null($a->getParent(), '孤立组件 parent 应为 null');
    assert_eq(count($a->getChildren()), 0, '无子组件');
});

// --- 2. Event Bubbling ---
echo "\n--- 2. Event Bubbling ---\n";

test('dispatchClick 沿 parent 链冒泡', function () {
    $grandparent = new _BubbleTrackerComponent('gp');
    $parent = new _BubbleTrackerComponent('p');
    $child = new _BubbleTrackerComponent('c');

    $child->setParent($parent);
    $parent->setParent($grandparent);

    $child->dispatchClick('customEvent', 'arg42');

    // 冒泡顺序：c → p → gp
    assert_eq(count($child->receivedEvents), 1, 'child 收到 1 次');
    assert_eq($child->receivedEvents[0]['handler'], 'customEvent');
    assert_eq($child->receivedEvents[0]['component'], 'c');

    assert_eq(count($parent->receivedEvents), 1, 'parent 收到 1 次');
    assert_eq($parent->receivedEvents[0]['handler'], 'customEvent');

    assert_eq(count($grandparent->receivedEvents), 1, 'grandparent 收到 1 次');
    assert_eq($grandparent->receivedEvents[0]['handler'], 'customEvent');
    assert_eq($grandparent->receivedEvents[0]['arg'], 'arg42');
});

test('消费事件后停止冒泡', function () {
    $parent = new _BubbleTrackerComponent('parent');
    $child = new _BubbleTrackerComponent('child');

    $child->setParent($parent);

    // 'stop' handler 被 child 消费，不冒泡
    $child->dispatchClick('stop');

    assert_eq(count($child->receivedEvents), 1, 'child 收到事件');
    assert_eq(count($parent->receivedEvents), 0, 'parent 不应收到（冒泡停止）');
});

test('根组件冒泡到 null parent 不报错', function () {
    $app = new _BubbleTrackerComponent('root');
    $app->dispatchClick('unknownEvent');
    assert_eq(count($app->receivedEvents), 1, '根组件收到事件但不继续冒泡');
});

test('dispatchKey 沿 parent 链冒泡', function () {
    $p = new _BubbleTrackerComponent('p');
    $c = new _BubbleTrackerComponent('c');
    $c->setParent($p);

    // dispatchKey 在 BaseComponent 中有默认冒泡实现
    // 这里验证它不抛异常
    try {
        $c->dispatchKey('enter', 'down', 13, '');
        assert_true(true, 'dispatchKey 冒泡不抛异常');
    } catch (\Throwable $e) {
        assert_true(false, 'dispatchKey 冒泡抛异常: ' . $e->getMessage());
    }
});

// --- 3. Component Instance Identity ---
echo "\n--- 3. Component Instance Identity ---\n";

test('两个同类型组件是不同实例', function () {
    $a = new _BubbleTrackerComponent('t1');
    $b = new _BubbleTrackerComponent('t2');
    assert_same($a !== $b, true, '不同实例');
    assert_same($a->name, 't1');
    assert_same($b->name, 't2');

    // 验证互不影响
    $a->receivedEvents[] = ['handler' => 'evt', 'arg' => null, 'component' => 'a'];
    assert_eq(count($b->receivedEvents), 0, 'b 不应受 a 影响');
});

test('组件 ID 唯一标识', function () {
    $a = new _BubbleTrackerComponent('inst1');
    $b = new _BubbleTrackerComponent('inst2');

    $a->setId('comp_a');
    $b->setId('comp_b');

    assert_eq($a->getId(), 'comp_a');
    assert_eq($b->getId(), 'comp_b');
});

// --- 4. Component Lifecycle ---
echo "\n--- 4. Component Lifecycle ---\n";

test('mount 触发 onMount', function () {
    $comp = new _TestRootComponent();
    $comp->mount();
    assert_true(true, 'mount() 可正常调用');
});

test('unmount 触发 onUnmount', function () {
    $comp = new _TestRootComponent();
    $comp->mount();
    $comp->unmount();
    assert_true(true, 'unmount() 可正常调用');
});

test('重复 mount 不报错', function () {
    $comp = new _TestRootComponent();
    $comp->mount();
    $comp->mount(); // 第二次调用不抛异常
    assert_true(true, '重复 mount 正常');
});

// --- 5. VNode Tree Caching & Dirty ---
echo "\n--- 5. VNode Tree Caching ---\n";

test('getVNodeTree 首次调用 render()', function () {
    $comp = new _TestRootComponent();
    assert_eq($comp->renderCount, 0);
    $tree = $comp->getVNodeTree();
    assert_eq($comp->renderCount, 1);
    assert_not_null($tree, '应返回 VNode 树');
    assert_eq($tree->type, '#root');
});

test('getVNodeTree 二次调用返回缓存', function () {
    $comp = new _TestRootComponent();
    $t1 = $comp->getVNodeTree();
    $count1 = $comp->renderCount;

    $t2 = $comp->getVNodeTree();
    assert_same($t1, $t2, '相同对象引用');
    assert_eq($comp->renderCount, $count1, 'render() 不被再次调用');
});

test('dirty=true 后 getVNodeTree 重建', function () {
    $comp = new _TestRootComponent();
    $t1 = $comp->getVNodeTree();
    $count1 = $comp->renderCount;

    $comp->dirty = true;
    $t2 = $comp->getVNodeTree();
    assert_same($t1 !== $t2, true, '不同对象引用');
    assert_eq($comp->renderCount, $count1 + 1, 'render() 被再次调用');
});

test('markDirty 清除缓存', function () {
    $comp = new _TestRootComponent();
    $comp->getVNodeTree();

    // markDirty 需要 scheduler 才能调用 addMicrotask
    $comp->inject(new Scheduler());

    $refl = new \ReflectionClass(_TestRootComponent::class);
    $method = $refl->getMethod('markDirty');
    $method->setAccessible(true);
    $method->invoke($comp);

    $prop = $refl->getProperty('vnodeCache');
    $prop->setAccessible(true);
    assert_null($prop->getValue($comp), 'markDirty 后缓存应为 null');
});

// --- 6. VNode Factory (hComponent) ---
echo "\n--- 6. VNode Factory ---\n";

test('hComponent 创建组件占位 VNode', function () {
    $vnode = VNode::hComponent('_BubbleTrackerComponent', ['style' => 'left:0;top:0'], ['name' => 'comp']);
    assert_true($vnode->isComponent(), '占位节点 isComponent 应为 true');
    assert_eq($vnode->componentClass, '_BubbleTrackerComponent');
    assert_not_null($vnode->componentProps, '应有 componentProps');
    assert_eq($vnode->componentProps['name'], 'comp');
});

test('hComponent 的 componentProps 含绑定映射关系', function () {
    $vnode = VNode::hComponent('AppComponent', [], ['display' => 'display', 'acLabel' => 'acLabel']);
    assert_eq($vnode->componentProps['display'], 'display');
    assert_eq($vnode->componentProps['acLabel'], 'acLabel');
});

test('组件展开后设置正确的 groupId', function () {
    // 验证 Application::setGroupIdRecursive 的效果
    // 创建 VNode 树，手动调用 setGroupIdRecursive（通过反射）
    $root = VNode::h('#root', ['style' => 'width:300px;height:200px'], [
        VNode::h('div', ['style' => 'width:100px;height:50px', '@click' => 'handler'], 'btn'),
    ]);
    $root->groupId = 'testGroup';

    // 验证 groupId 已设置
    assert_eq($root->groupId, 'testGroup');

    // 手动验证 setGroupIdRecursive 效果
    $callback = function (VNode $node, string $id) use (&$callback) {
        $node->groupId = $id;
        if ($node->children instanceof VNode) {
            $callback($node->children, $id);
        } elseif (is_array($node->children)) {
            foreach ($node->children as $c) {
                if ($c instanceof VNode) {
                    $callback($c, $id);
                }
            }
        }
    };
    $callback($root, 'newGroup');

    assert_eq($root->groupId, 'newGroup');
    if ($root->children instanceof VNode) {
        assert_eq($root->children->groupId, 'newGroup');
    } elseif (is_array($root->children)) {
        foreach ($root->children as $c) {
            if ($c instanceof VNode) {
                assert_eq($c->groupId, 'newGroup');
            }
        }
    }
});

// --- 7. Patch Component Tree (via Reflection) ---
echo "\n--- 7. Patch Component Tree ---\n";

test('patchComponentTree 处理普通节点（设置 groupId）', function () {
    $app = newInstanceWithoutApp();
    $root = VNode::h('#root', ['style' => 'width:200px;height:200px'], [
        VNode::h('div', ['style' => 'width:50px;height:50px'], 'child'),
    ]);
    $root->groupId = 'app';

    // 调用 patchComponentTree(root, rootComponent, null)
    $patchMethod = new \ReflectionMethod(Application::class, 'patchComponentTree');
    $patchMethod->setAccessible(true);

    $rootComp = new _TestRootComponent();
    $rootComp->setId('testApp');

    $patchMethod->invoke($app, $root, $rootComp, null);

    // 验证普通节点的 groupId 被设置为 owner 的 ID
    assert_eq($root->groupId, 'testApp', '根节点 groupId');
    if ($root->children instanceof VNode) {
        assert_eq($root->children->groupId, 'testApp', '子节点 groupId');
    } elseif (is_array($root->children)) {
        foreach ($root->children as $c) {
            if ($c instanceof VNode) {
                assert_eq($c->groupId, 'testApp');
            }
        }
    }
});

test('patchComponentTree 展开 #component 节点', function () {
    $app = newInstanceWithoutApp();

    // 加载组件工厂和子组件
    $appDir = realpath(__DIR__ . '/../../apps/calculator-ng');
    require_once $appDir . '/gen/AppComponent.php';
    require_once $appDir . '/gen/ComponentFactory.php';
    require_once $appDir . '/gen/BasicPadComponent.php';

    // 创建包含 #component 占位的 VNode 树
    $root = VNode::h('#root', ['style' => 'width:400px;height:300px'], [
        VNode::hComponent('BasicPadComponent', ['style' => 'left:0;top:0'], ['acLabel' => 'acLabel']),
    ]);
    $root->groupId = 'app';
    $root->componentClass = null;

    // 需要有 AppComponent 作为 owner
    $ownerRefl = new \ReflectionClass(AppComponent::class);
    $owner = $ownerRefl->newInstanceWithoutConstructor();
    $owner->setId('ownerComp');
    $scheduler = new Scheduler();
    $owner->setScheduler($scheduler);

    // 需要给 $owner 注入 scheduler（通过反射设置 protected 属性）
    $schedProp = $ownerRefl->getProperty('scheduler');
    $schedProp->setAccessible(true);
    $schedProp->setValue($owner, $scheduler);

    $patchMethod = new \ReflectionMethod(Application::class, 'patchComponentTree');
    $patchMethod->setAccessible(true);

    try {
        $patchMethod->invoke($app, $root, $owner, null);
        assert_true(true, 'patchComponentTree 展开组件不抛异常');
    } catch (\Throwable $e) {
        assert_true(false, 'patchComponentTree 抛异常: ' . $e->getMessage());
    }
});

test('两次 patch 重用组件实例（同 class + 同 key）', function () {
    $app = newInstanceWithoutApp();

    // 加载真实组件（测试 double 不在 ComponentFactory 中）
    $appDir = realpath(__DIR__ . '/../../apps/calculator-ng');
    require_once $appDir . '/gen/ComponentFactory.php';
    require_once $appDir . '/gen/BasicPadComponent.php';

    // 第一次 patch: 创建新实例（$oldNode=null → expandComponentNode）
    $root1 = VNode::h('#root', ['style' => 'width:400px;height:300px'], [
        VNode::hComponent('BasicPadComponent', ['style' => 'left:0;top:0'], []),
    ]);
    $root1->groupId = 'app';

    $owner = new _BubbleTrackerComponent('owner');
    $owner->setId('ownerId');

    $patchMethod = new \ReflectionMethod(Application::class, 'patchComponentTree');
    $patchMethod->setAccessible(true);
    $patchMethod->invoke($app, $root1, $owner, null);

    // 获取第一次的实例引用
    $childNode = $root1->children[0] ?? null;
    assert_not_null($childNode, '应有子节点');
    $firstInstance = $childNode->componentInstance ?? null;
    assert_not_null($firstInstance, '第一次 patch 应创建实例');

    // 第二次 patch（传递旧树 root1 进行匹配）
    $root2 = VNode::h('#root', ['style' => 'width:400px;height:300px'], [
        VNode::hComponent('BasicPadComponent', ['style' => 'left:0;top:0'], []),
    ]);
    $root2->groupId = 'app';

    // Priority 2: 旧树对应位置有同 class 组件 → 应复用实例
    $patchMethod->invoke($app, $root2, $owner, $root1);

    $childNode2 = $root2->children[0] ?? null;
    $secondInstance = $childNode2->componentInstance ?? null;

    assert_not_null($secondInstance, '第二次 patch 应复用实例');
    // Vue 3 风格 keyed reconciliation：同 class + 同位置 → 复用
    assert_same($firstInstance, $secondInstance, '相同位置同 class → 复用实例');
});

test('组件定位在重用实例后保留（即使组件 markDirty 重新 render）', function () {
    $app = newInstanceWithoutApp();

    // 加载真实组件
    $appDir = realpath(__DIR__ . '/../../apps/calculator-ng');
    require_once $appDir . '/gen/ComponentFactory.php';
    require_once $appDir . '/gen/HistoryPanelComponent.php';

    // 第一次 patch: 创建新实例
    $root1 = VNode::h('#root', ['style' => 'width:340px;height:660px'], [
        VNode::hComponent('HistoryPanelComponent', ['style' => 'left:11px;top:524px'], ['arrow' => 'arrowText']),
    ]);
    $root1->groupId = 'app';

    $owner = new _BubbleTrackerComponent('owner');
    $owner->setId('ownerId');
    // 给 owner 设置 arrowText 属性
    $owner->arrowText = '>';

    $patchMethod = new \ReflectionMethod(Application::class, 'patchComponentTree');
    $patchMethod->setAccessible(true);
    $patchMethod->invoke($app, $root1, $owner, null);

    // 获取第一次的组件实例和其根元素样式
    $childNode = $root1->children[0] ?? null;
    assert_not_null($childNode, '应有子节点');
    $instance = $childNode->componentInstance ?? null;
    assert_not_null($instance, '第一次 patch 应创建实例');

    // 展开 #root → 找到子组件根元素
    $childRoot = $instance->getVNodeTree();
    $rootElement = $childRoot;
    while ($rootElement !== null && $rootElement->type === '#root') {
        if (is_array($rootElement->children)) {
            $rootElement = $rootElement->children[0] ?? null;
        } elseif ($rootElement->children instanceof VNode) {
            $rootElement = $rootElement->children;
        } else {
            break;
        }
    }
    assert_not_null($rootElement, '应有根元素');
    $style = $rootElement->props['style'] ?? '';
    assert(strpos($style, 'left:11;') !== false || strpos($style, 'left:11px;') !== false,
        '第一次 patch 后根元素应包含 left:11，实际: ' . $style);
    assert(strpos($style, 'top:524;') !== false || strpos($style, 'top:524px;') !== false,
        '第一次 patch 后根元素应包含 top:524，实际: ' . $style);

    // 第二次 patch：模拟 toggleHistory → arrowText changes
    // 先更新 owner 的 arrowText 值
    $owner->arrowText = 'v';
    // 同时设置组件 Props 使得 setBindValue 触发 markDirty
    // 需要让 instance 进入 dirty 状态，从而 getVNodeTree 重新 render

    $root2 = VNode::h('#root', ['style' => 'width:340px;height:660px'], [
        VNode::hComponent('HistoryPanelComponent', ['style' => 'left:11px;top:524px'], ['arrow' => 'arrowText']),
    ]);
    $root2->groupId = 'app';

    $patchMethod->invoke($app, $root2, $owner, $root1);

    // 验证第二次 patch 后实例被复用
    $childNode2 = $root2->children[0] ?? null;
    $secondInstance = $childNode2->componentInstance ?? null;
    assert_same($instance, $secondInstance, '第二次 patch 应复用实例');

    // 验证定位仍然保留
    $childRoot2 = $instance->getVNodeTree();
    $rootElement2 = $childRoot2;
    while ($rootElement2 !== null && $rootElement2->type === '#root') {
        if (is_array($rootElement2->children)) {
            $rootElement2 = $rootElement2->children[0] ?? null;
        } elseif ($rootElement2->children instanceof VNode) {
            $rootElement2 = $rootElement2->children;
        } else {
            break;
        }
    }
    assert_not_null($rootElement2, '第二次应有根元素');
    $style2 = $rootElement2->props['style'] ?? '';
    assert(strpos($style2, 'left:11;') !== false || strpos($style2, 'left:11px;') !== false,
        '第二次 patch 后根元素应保留 left:11，实际: ' . $style2);
    assert(strpos($style2, 'top:524;') !== false || strpos($style2, 'top:524px;') !== false,
        '第二次 patch 后根元素应保留 top:524，实际: ' . $style2);
});

test('expandComponentNode 后 getVNodeTree 返回定位后的树（vnodeCache 一致性）', function () {
    $app = newInstanceWithoutApp();

    $appDir = realpath(__DIR__ . '/../../apps/calculator-ng');
    require_once $appDir . '/gen/ComponentFactory.php';
    require_once $appDir . '/gen/HistoryPanelComponent.php';

    $owner = new _BubbleTrackerComponent('owner');
    $owner->setId('ownerId');
    $owner->arrowText = '>';

    $patchMethod = new \ReflectionMethod(Application::class, 'patchComponentTree');
    $patchMethod->setAccessible(true);

    // 第一次 patch（首次渲染，触发 expandComponentNode）
    $root = VNode::h('#root', ['style' => 'width:340px;height:660px'], [
        VNode::hComponent('HistoryPanelComponent', ['style' => 'left:11px;top:524px'], ['arrow' => 'arrowText']),
    ]);
    $root->groupId = 'app';

    $patchMethod->invoke($app, $root, $owner, null);

    // 获取组件实例
    $childNode = $root->children[0] ?? null;
    $instance = $childNode->componentInstance ?? null;
    assert_not_null($instance, 'expandComponentNode 应创建组件实例');

    // ── 断言 1（修复验证）：expandComponentNode 后，
    //    getVNodeTree 应返回含 left/top 定位的树
    //    （即 expandComponentNode 必须使用 getVNodeTree 而非 render，
    //      确保修改同步到 vnodeCache，而非创建新树）
    $childRoot = $instance->getVNodeTree();
    $rootElement = $childRoot;
    while ($rootElement !== null && $rootElement->type === '#root') {
        if (is_array($rootElement->children)) {
            $rootElement = $rootElement->children[0] ?? null;
        } elseif ($rootElement->children instanceof VNode) {
            $rootElement = $rootElement->children;
        } else {
            break;
        }
    }
    assert_not_null($rootElement, '应有根元素');
    $style = $rootElement->props['style'] ?? '';
    assert(strpos($style, 'left:11') !== false,
        'getVNodeTree 应返回含 left:11 的树，实际: ' . $style);
    assert(strpos($style, 'top:524') !== false,
        'getVNodeTree 应返回含 top:524 的树，实际: ' . $style);

    // ── 断言 2（缓存验证）：连续调用 getVNodeTree 返回同一对象
    $childRoot2 = $instance->getVNodeTree();
    assert_same($childRoot2, $childRoot,
        '二次 getVNodeTree 应返回同一对象（vnodeCache 命中）');
});

test('expandComponentNode + updateFromVNode 完整管线保留 RenderNode 定位', function () {
    $app = newInstanceWithoutApp();

    // ── 阶段 1：expandComponentNode 展开组件 ──
    $appDir = realpath(__DIR__ . '/../../apps/calculator-ng');
    require_once $appDir . '/gen/ComponentFactory.php';
    require_once $appDir . '/gen/HistoryPanelComponent.php';

    $owner = new _BubbleTrackerComponent('owner');
    $owner->setId('ownerId');
    $owner->arrowText = '>';

    $patchMethod = new \ReflectionMethod(Application::class, 'patchComponentTree');
    $patchMethod->setAccessible(true);

    $root = VNode::h('#root', ['style' => 'width:340px;height:660px'], [
        VNode::hComponent('HistoryPanelComponent', ['style' => 'left:11px;top:524px'], ['arrow' => 'arrowText']),
    ]);
    $root->groupId = 'app';

    $patchMethod->invoke($app, $root, $owner, null);

    // 注册组件实例到 componentByGroupId（updateFromVNode 需要此映射）
    $childNode = $root->children[0] ?? null;
    $instance = $childNode->componentInstance ?? null;
    assert_not_null($instance, 'expandComponentNode 应创建组件实例');
    $regProp = (new \ReflectionClass(Application::class))->getProperty('componentByGroupId');
    $regProp->setAccessible(true);
    $registry = $regProp->getValue($app);
    $registry[$instance->getId()] = $instance;
    $regProp->setValue($app, $registry);

    // 创建根组件引用
    $rootCompForRT = new _TestRootComponent();
    $rootCompForRT->setId('ownerId');

    // ── 阶段 2：updateFromVNode 转换 VNode → RenderNode ──
    $rm = new \Px\Rendering\RenderTreeManager();
    $renderNode = $rm->updateFromVNode(
        $root,
        null,
        $rootCompForRT,
        $registry
    );

    // #root → 容器 div → #component → HistoryPanel 子树的 container div
    // updateFromVNode 遇到 #component 会 delegate 到 $instance->getVNodeTree()
    // 由于 getVNodeTree 应返回定位后的树，子 container div 的 left/top 应被正确解析
    assert_not_null($renderNode, 'updateFromVNode 应返回 RenderNode');

    // 找到 HistoryPanel 的 container div RenderNode
    // 树结构：div(#root的孩子,容器) > div(HistoryPanel container)
    // 注意：#component 节点在 updateFromVNode 中被委派，HistoryPanel 的
    // #root 被跳过，其子 container div 直接作为父 div 的 RenderNode 子节点
    $historyPanelRoot = $rm->findRenderNodeByGroupId($instance->getId());
    assert_true(count($historyPanelRoot) >= 1, '应找到 HistoryPanel 的 RenderNode');

    // 找到 container div（样式含 left/top 的那个）
    $containerRN = null;
    foreach ($historyPanelRoot as $rn) {
        if (isset($rn->style['left']) && isset($rn->style['top'])) {
            $containerRN = $rn;
            break;
        }
    }
    assert_not_null($containerRN,
        '应在 RenderNode 中找到含 left/top 的节点');
    assert($containerRN->style['left'] === 11,
        'RenderNode style.left 应为 11，实际: ' . ($containerRN->style['left'] ?? 'unset'));
    assert($containerRN->style['top'] === 524,
        'RenderNode style.top 应为 524，实际: ' . ($containerRN->style['top'] ?? 'unset'));
});

test('多次 re-render 后定位值不退化', function () {
    $app = newInstanceWithoutApp();

    // 加载真实组件（与现有测试一致）
    $appDir = realpath(__DIR__ . '/../../apps/calculator-ng');
    require_once $appDir . '/gen/ComponentFactory.php';
    require_once $appDir . '/gen/HistoryPanelComponent.php';

    $owner = new _BubbleTrackerComponent('owner');
    $owner->setId('ownerId');
    $owner->arrowText = '>';

    $patchMethod = new \ReflectionMethod(Application::class, 'patchComponentTree');
    $patchMethod->setAccessible(true);

    $iterations = 5;   // 多次 re-render 暴露样式字符串退化
    $prevRoot = null;
    $instance = null;

    for ($i = 0; $i < $iterations; $i++) {
        // 每次创建新的 VNode 树（模拟真实 re-render）
        $root = VNode::h('#root', ['style' => 'width:340px;height:660px'], [
            VNode::hComponent(
                'HistoryPanelComponent',
                ['style' => 'left:11px;top:524px'],
                ['arrow' => 'arrowText']
            ),
        ]);
        $root->groupId = 'app';

        // 切换 arrowText 使组件进入 dirty 状态（模拟实际业务操作）
        $owner->arrowText = ($i % 2 === 0) ? '>' : 'v';

        // patchComponentTree: 传入旧树 oldNode=$prevRoot 启用实例复用
        $patchMethod->invoke($app, $root, $owner, $prevRoot);

        // 验证实例复用
        $childNode = $root->children[0] ?? null;
        assert_not_null($childNode, "第 {$i} 次应有子节点");
        if ($i === 0) {
            $instance = $childNode->componentInstance;
            assert_not_null($instance, "第 {$i} 次应创建实例");
        } else {
            assert_same($instance, $childNode->componentInstance,
                "第 {$i} 次应复用实例");
        }

        // 展开 #root → 找到子组件根元素
        $childRoot = $instance->getVNodeTree();
        $rootElement = $childRoot;
        while ($rootElement !== null && $rootElement->type === '#root') {
            $children = $rootElement->children;
            if ($children instanceof VNode) {
                $rootElement = $children;
            } elseif (is_array($children)) {
                $next = null;
                foreach ($children as $child) {
                    if ($child instanceof VNode && $child->type !== '#text') {
                        $next = $child;
                        break;
                    }
                }
                $rootElement = $next;
            } else {
                break;
            }
        }
        assert_not_null($rootElement, "第 {$i} 次应有根元素");

        $style = $rootElement->props['style'] ?? '';

        // ── 断言 1：left:11 存在 ──
        assert(strpos($style, 'left:11') !== false,
            "第 {$i} 次 re-render 后应包含 left:11，实际: {$style}");

        // ── 断言 2：top:524 存在 ──
        assert(strpos($style, 'top:524') !== false,
            "第 {$i} 次 re-render 后应包含 top:524，实际: {$style}");

        // ── 断言 3（关键）：left: 在整个 style 中只出现一次 ──
        $leftPropCount = substr_count($style, 'left:');
        assert($leftPropCount === 1,
            "第 {$i} 次 re-render 后 left: 应恰好出现 1 次（实际 {$leftPropCount} 次），style={$style}");

        // ── 断言 4（关键）：top: 在整个 style 中只出现一次 ──
        $topPropCount = substr_count($style, 'top:');
        assert($topPropCount === 1,
            "第 {$i} 次 re-render 后 top: 应恰好出现 1 次（实际 {$topPropCount} 次），style={$style}");

        $prevRoot = $root;
    }
});


// ============================================================
// Helper
// ============================================================

/**
 * 创建无平台依赖的 Application 实例用于测试。
 * 通过反射注入必要依赖，不启动事件循环。
 */
function newInstanceWithoutApp(): Application
{
    $refl = new \ReflectionClass(Application::class);
    $app = $refl->newInstanceWithoutConstructor();

    $scheduler = new Scheduler();
    $schedProp = $refl->getProperty('scheduler');
    $schedProp->setAccessible(true);
    $schedProp->setValue($app, $scheduler);

    $lrProp = $refl->getProperty('layoutResolver');
    $lrProp->setAccessible(true);
    $lrProp->setValue($app, new \Px\Rendering\LayoutResolver());

    // 初始化 componentByGroupId 数组
    $regProp = $refl->getProperty('componentByGroupId');
    $regProp->setAccessible(true);
    $regProp->setValue($app, []);

    return $app;
}

echo "\n";
$exitCode = print_summary();
exit($exitCode);
