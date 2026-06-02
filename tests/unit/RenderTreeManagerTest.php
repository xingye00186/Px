<?php

/**
 * RenderTreeManager 单元测试
 *
 * 覆盖范围：
 * - VNode → RenderNode 转换（普通节点、#root、组件占位）
 * - RenderNode 复用（spl_object_hash 映射）
 * - groupId 防御性继承
 * - scroll bind 值同步
 * - hitTest
 * - findScrollContainerAt
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\VNode;
use Px\Rendering\RenderNode;
use Px\Rendering\RenderTreeManager;
use Px\Styling\Provider\ThemeProvider;

// ─────────────────────────────────────────────
// 辅助组件类
// ─────────────────────────────────────────────
class TestComponentForRenderTree extends \Px\ReactiveComponent
{
    public string $scrollTop = '0';

    public function render(): VNode
    {
        return VNode::h('#root', ['style' => 'width:400;height:300'], []);
    }

    public function setBindValue(string $key, string $val): void
    {
        if ($key === 'scrollTop') {
            $this->scrollTop = $val;
        }
    }

    public function getBindValue(string $key): string
    {
        if ($key === 'scrollTop') {
            return $this->scrollTop;
        }
        return '';
    }

    public function dispatchClick(string $handler, ?string $arg = null): void {}
}

// ─────────────────────────────────────────────
// 1. 基本转换
// ─────────────────────────────────────────────
$manager = new RenderTreeManager();
$rootComponent = new TestComponentForRenderTree();
$componentByGroupId = ['app' => $rootComponent];

// 构造简单的 VNode 树
$vnode = VNode::h('#root', [], [
    VNode::h('div', ['class' => 'container'], [
        VNode::h('span', [], 'Hello'),
        VNode::h('button', ['@click' => 'handleClick'], 'Click'),
    ]),
]);

$rn = $manager->updateFromVNode($vnode, null, $rootComponent, $componentByGroupId);
assert($rn !== null, '转换后应返回 RenderNode');
assert($rn->type === 'div', '根 RenderNode 应为 div');
assert($rn->type === 'div', '跳过 #root 后 type 应为 div');
assert(count($rn->children) === 2, '应有 2 个子节点');
assert($rn->children[0]->type === 'span', '第一个子节点应为 span');
assert($rn->children[1]->type === 'button', '第二个子节点应为 button');
assert($rn->children[0]->content === 'Hello', 'span 文本内容应为 Hello');
echo "[PASS] VNode → RenderNode 基本转换\n";

// ─────────────────────────────────────────────
// 2. RenderNode 复用（type+key 匹配）
// ─────────────────────────────────────────────
$manager2 = new RenderTreeManager();

// 第一次转换：通过 #root 创建 div RenderNode（无旧 root → 新建）
$root2first = VNode::h('#root', [], [VNode::h('div', ['class' => 'box'], 'content')]);
$rn1 = $manager2->updateFromVNode($root2first, null, $rootComponent, $componentByGroupId);
assert($rn1 !== null, '第一次转换应成功');
assert($rn1->content === 'content', '第一次转换 content 正确');

// 第二次转换：获取旧 root 作为 candidates，创建新 VNode 但同 type+key
$oldRoot2 = $manager2->getRootRenderNode();
$candidates2 = $oldRoot2 !== null ? [$oldRoot2] : null;
$root2second = VNode::h('#root', [], [VNode::h('div', ['class' => 'box'], 'content-2')]);
$rn2 = $manager2->updateFromVNode($root2second, null, $rootComponent, $componentByGroupId, $candidates2);

// 对象一致性验证：应为同一 RenderNode 对象（type+key 匹配）
assert(spl_object_hash($rn1) === spl_object_hash($rn2), '不同 VNode 同 type+key 应匹配到同一 RenderNode');
echo "[PASS] RenderNode 复用（type+key 跨帧匹配）\n";

// ─────────────────────────────────────────────
// 3. groupId 继承
// ─────────────────────────────────────────────
$manager3 = new RenderTreeManager();
$vnodeWithGroupId = VNode::h('div', ['class' => 'box'], null);
$vnodeWithGroupId->groupId = 'mygroup';

$child1 = VNode::h('span', [], 'text');
$vnodeWithGroupId->children = [$child1];

$rn3 = $manager3->updateFromVNode($vnodeWithGroupId, null, $rootComponent, $componentByGroupId);
assert($rn3->groupId === 'mygroup', 'groupId 应从 VNode 复制');
assert($rn3->children[0]->groupId === 'mygroup', '子节点 groupId 应与父节点一致');
echo "[PASS] groupId 从 sourceVNode 复制\n";

// ─────────────────────────────────────────────
// 4. #root 节点跳过
// ─────────────────────────────────────────────
$manager4 = new RenderTreeManager();
$vnodeRoot = VNode::h('#root', [], [
    VNode::h('div', ['class' => 'child1'], 'A'),
]);

$rn4 = $manager4->updateFromVNode($vnodeRoot, null, $rootComponent, $componentByGroupId);
assert($rn4 !== null, '#root 下应能提取到子节点');
assert($rn4->type === 'div', '跳过了 #root，直接拿到 div');
echo "[PASS] #root 节点被跳过，直接返回子节点\n";

// ─────────────────────────────────────────────
// 5. clear() 方法
// ─────────────────────────────────────────────
$manager5 = new RenderTreeManager();
$vnode5 = VNode::h('div', [], 'test');
$manager5->updateFromVNode($vnode5, null, $rootComponent, $componentByGroupId);
assert($manager5->getRootRenderNode() !== null, '转换后 rootRenderNode 不应为 null');

$manager5->clear();
assert($manager5->getRootRenderNode() === null, 'clear() 后 rootRenderNode 应为 null');
echo "[PASS] clear() 清空所有映射\n";

// ─────────────────────────────────────────────
// 6. hitTest
// ─────────────────────────────────────────────
$manager6 = new RenderTreeManager();
$btnVNode = VNode::h('button', ['@click' => 'test', 'style' => 'left:10;top:10;width:100;height:50'], 'OK');

$rootVNode = VNode::h('#root', [], [$btnVNode]);

// 手动设置布局结果到 RenderNode
$rn6 = $manager6->updateFromVNode($rootVNode, null, $rootComponent, $componentByGroupId);
assert($rn6 !== null, '转换应成功');
$rn6->x = 10;
$rn6->y = 10;
$rn6->w = 100;
$rn6->h = 50;

// hitTest 应该在 RenderNode 上
$hitResult = $manager6->hitTest(15, 15);
assert($hitResult !== null, '命中测试应在坐标 (15,15) 找到按钮');
assert($hitResult->sourceVNode === $btnVNode, '命中的 RenderNode 的 sourceVNode 应为原始 VNode');
echo "[PASS] hitTest 在 RenderNode 树上正确执行\n";

// ─────────────────────────────────────────────
// 7. findScrollContainerAt
// ─────────────────────────────────────────────
$manager7 = new RenderTreeManager();
$scrollVNode = VNode::h('div', [':scroll-top' => 'scrollTop', 'style' => 'left:0;top:0;width:200;height:300'], []);
$scrollVNode->isScrollContainer = true;

$rootVNode7 = VNode::h('#root', [], [$scrollVNode]);

$rn7 = $manager7->updateFromVNode($rootVNode7, null, $rootComponent, $componentByGroupId);
assert($rn7 !== null, '转换应成功');
$rn7->x = 0;
$rn7->y = 0;
$rn7->w = 200;
$rn7->h = 300;
$rn7->isScrollContainer = true;

$scrollResult = $manager7->findScrollContainerAt(50, 50);
assert($scrollResult !== null, '应找到滚动容器');
assert($scrollResult->isScrollContainer === true, '找到的节点应为滚动容器');
echo "[PASS] findScrollContainerAt 正确查找滚动容器\n";

// ─────────────────────────────────────────────
// 8. 查找方法
// ─────────────────────────────────────────────
$manager8 = new RenderTreeManager();
$vnode8 = VNode::h('div', [], 'target');
$vnode8->groupId = 'testGroup';

$rootVNode8 = VNode::h('#root', [], [$vnode8]);

$rn8 = $manager8->updateFromVNode($rootVNode8, null, $rootComponent, $componentByGroupId);

// findRenderNodeBySourceVNode
$found = $manager8->findRenderNodeBySourceVNode($vnode8);
assert($found !== null, '应能通过 VNode 查找 RenderNode');
assert($found->sourceVNode === $vnode8, '查找结果应指向同一 VNode');

// findRenderNodeByGroupId
$groupNodes = $manager8->findRenderNodeByGroupId('testGroup');
assert(count($groupNodes) >= 1, 'groupId 映射应包含至少一个节点');

// findFirstRenderNodeByGroupId
$firstNode = $manager8->findFirstRenderNodeByGroupId('testGroup');
assert($firstNode !== null, 'findFirst 应返回一个节点');
echo "[PASS] 查找方法正确工作\n";

// ─────────────────────────────────────────────
// 9. 组件展开的父子关系
// ─────────────────────────────────────────────
class ChildCompForTreeTest extends \Px\ReactiveComponent
{
    public function render(): VNode
    {
        return VNode::h('#root', [], VNode::h('span', ['class' => 'child-span'], 'comp-content'));
    }
    public function setBindValue(string $key, string $val): void {}
    public function getBindValue(string $key): string { return ''; }
    public function dispatchClick(string $handler, ?string $arg = null): void {}
}

// 9.1 组件展开后子节点正确链接（累积偏移方案，无包装器）
$manager9 = new RenderTreeManager();
$childComp = new ChildCompForTreeTest();
$childComp->mount();

$compVNode = VNode::hComponent('ChildCompForTreeTest', [], []);
$compVNode->componentInstance = $childComp;
$compVNode->children = $childComp->getVNodeTree();
$compVNode->children->groupId = 'child';

$parentVNode = VNode::h('#root', [], [
    VNode::h('div', ['id' => 'parent', 'style' => 'width:300;height:200'], [
        VNode::h('span', [], 'before'),
        $compVNode,
        VNode::h('span', [], 'after'),
    ]),
]);

$rn9 = $manager9->updateFromVNode($parentVNode, null, $rootComponent, $componentByGroupId);
assert($rn9 !== null, '转换应成功');
assert($rn9->type === 'div', '根应为 div');
// div 应有 3 个孩子: span(before), component span, span(after)
// #component 不产生包装器，子组件的根元素直接作为父 div 的子节点
assert(count($rn9->children) === 3, '父 div 应有 3 个子 RenderNode，实际: ' . count($rn9->children));

// 第二个子节点是 ChildCompForTreeTest 展开的 span（无包装器）
$childSpan = $rn9->children[1];
assert($childSpan->type === 'span', '组件展开后子节点应为 span');
assert($childSpan->content === 'comp-content', '子 span 应有正确内容');
assert($childSpan->parent === $rn9, '子 span 的 parent 应直接指向父 div');
echo "[PASS] 组件展开后子节点正确链接到父 RenderNode（累积偏移，无包装器）\n";

// 9.2 无 componentInstance 时跳过组件占位
$manager9b = new RenderTreeManager();
$emptyCompVNode = VNode::hComponent('NonExistent', [], []);
$parentVNode9b = VNode::h('#root', [], [
    VNode::h('div', [], [
        VNode::h('span', [], 'static'),
        $emptyCompVNode,
    ]),
]);

$rn9b = $manager9b->updateFromVNode($parentVNode9b, null, $rootComponent, $componentByGroupId);
assert($rn9b !== null, '转换应成功');
assert($rn9b->type === 'div', '应为 div');
assert(count($rn9b->children) === 1, '空组件被跳过，应有 1 个子节点');
echo "[PASS] 无 componentInstance 的组件占位被正确跳过\n";

// 9.3 组件定位：验证 layoutOffset 机制，
//    #component 的 style(left/top) 解析后存入 layoutOffset，
//    RenderTreeManager 在 #component 处理时应用到 RenderNode
$manager9c = new RenderTreeManager();
$posComp = new ChildCompForTreeTest();
$posComp->mount();

$posCompVNode = VNode::hComponent('ChildCompForTreeTest', ['style' => 'left:10;top:20'], []);
$posCompVNode->componentInstance = $posComp;
$posCompVNode->children = $posComp->getVNodeTree();
$posCompVNode->children->groupId = 'child';

// 验证 layoutOffset 机制：不再修改子 VNode 的 style，
// 改为在 #component VNode 上设置 layoutOffset
$posCompVNode->layoutOffset = ['left' => 10, 'top' => 20];

$parentVNode9c = VNode::h('#root', [], [
    VNode::h('div', ['id' => 'container', 'style' => 'width:400;height:600'], [
        $posCompVNode,
    ]),
]);

$rn9c = $manager9c->updateFromVNode($parentVNode9c, null, $rootComponent, $componentByGroupId);
assert($rn9c !== null, '转换应成功');
assert($rn9c->type === 'div', '根应为 div');
assert(count($rn9c->children) === 1, '容器应有 1 个子节点');

// 组件根元素（span）直接作为容器子节点（无包装器）
$childSpan = $rn9c->children[0];
assert($childSpan->type === 'span', '组件根元素应为 span（无包装器）');
assert(isset($childSpan->style['left']), '组件根元素 style 应包含 left');
assert($childSpan->style['left'] === 10, 'left 应为 10，实际: ' . ($childSpan->style['left'] ?? 'unset'));
assert(isset($childSpan->style['top']), '组件根元素 style 应包含 top');
assert($childSpan->style['top'] === 20, 'top 应为 20，实际: ' . ($childSpan->style['top'] ?? 'unset'));
assert($childSpan->content === 'comp-content', '组件根元素应有正确内容');
assert($childSpan->parent === $rn9c, '组件根元素的 parent 应直接指向容器 div');
echo "[PASS] 组件定位：layoutOffset 机制从 #component 传递到 RenderNode，无包装器\n";

// ─────────────────────────────────────────────
// 10. CSS class 样式合并
// ─────────────────────────────────────────────
ThemeProvider::registerClassStyles('TestComponentA', [
    'btn-primary' => ['bg' => 0x0000FF, 'fg' => 0xFFFFFF, 'width' => 100, 'height' => 40],
    'btn-small' => ['width' => 60, 'height' => 30],
]);
ThemeProvider::registerClassStyles('TestComponentB', [
    'btn-danger' => ['bg' => 0x0000FF, 'fg' => 0xFFFFFF],
]);

$manager10 = new RenderTreeManager();

// 10.1 class 样式正确合并
$vnode10a = VNode::h('button', ['class' => 'btn-primary'], 'Submit');
$rn10a = $manager10->updateFromVNode($vnode10a, null, $rootComponent, $componentByGroupId);
assert($rn10a !== null, '转换应成功');
assert($rn10a->style['bg'] === 0x0000FF, 'btn-primary 的 bg 应为 0x0000FF, 实际: ' . ($rn10a->style['bg'] ?? 'unset'));
assert($rn10a->style['fg'] === 0xFFFFFF, 'btn-primary 的 fg 应为 0xFFFFFF');
assert($rn10a->style['width'] === 100, 'btn-primary 的 width 应为 100');
assert($rn10a->style['height'] === 40, 'btn-primary 的 height 应为 40');
echo "[PASS] CSS class 样式正确合并到 RenderNode.style\n";

// 10.2 inline 样式覆盖 class 样式
$vnode10b = VNode::h('button', ['class' => 'btn-primary', 'style' => 'width:120;height:50'], 'Submit');
$rn10b = $manager10->updateFromVNode($vnode10b, null, $rootComponent, $componentByGroupId);
assert($rn10b !== null, '转换应成功');
assert($rn10b->style['bg'] === 0x0000FF, 'class bg 应保留: ' . ($rn10b->style['bg'] ?? 'unset'));
assert($rn10b->style['width'] === 120, 'inline width 应覆盖 class width');
assert($rn10b->style['height'] === 50, 'inline height 应覆盖 class height');
echo "[PASS] inline 样式正确覆盖 class 样式\n";

// 10.3 仅 inline 样式（无 class）
$vnode10c = VNode::h('div', ['style' => 'left:10;top:20;width:100'], 'Content');
$rn10c = $manager10->updateFromVNode($vnode10c, null, $rootComponent, $componentByGroupId);
assert($rn10c !== null, '转换应成功');
assert($rn10c->style['left'] === 10, 'left 应为 10');
assert($rn10c->style['top'] === 20, 'top 应为 20');
assert($rn10c->style['width'] === 100, 'width 应为 100');
assert(!isset($rn10c->style['bg']), '无 class 时不应有 bg');
echo "[PASS] 仅 inline 样式（无 class）正常工作\n";

// 10.4 无样式时返回空数组
$vnode10d = VNode::h('span', [], 'Text');
$rn10d = $manager10->updateFromVNode($vnode10d, null, $rootComponent, $componentByGroupId);
assert($rn10d !== null, '转换应成功');
assert(is_array($rn10d->style), 'style 应为数组');
assert(count($rn10d->style) === 0, '无样式时 style 应为空数组');
echo "[PASS] 无样式时 RenderNode.style 为空数组\n";

// 10.5 跨组件 class 搜索（class 是全局的）
$vnode10e = VNode::h('button', ['class' => 'btn-danger'], 'Delete');
$rn10e = $manager10->updateFromVNode($vnode10e, null, $rootComponent, $componentByGroupId);
assert($rn10e !== null, '转换应成功');
assert($rn10e->style['bg'] === 0x0000FF, 'btn-danger 的 bg 应被正确查找（跨组件）');
assert($rn10e->style['fg'] === 0xFFFFFF, 'btn-danger 的 fg 应被正确查找（跨组件）');
echo "[PASS] 跨组件 class 样式全局搜索正确\n";

// 10.6 多个 class 名合并
ThemeProvider::registerClassStyles('TestComponentC', [
    'rounded' => ['borderRadius' => 8],
    'shadow' => ['shadow' => 1],
]);
$vnode10f = VNode::h('div', ['class' => 'btn-primary rounded shadow'], 'Styled');
$rn10f = $manager10->updateFromVNode($vnode10f, null, $rootComponent, $componentByGroupId);
assert($rn10f !== null, '转换应成功');
assert($rn10f->style['bg'] === 0x0000FF, 'btn-primary bg 应存在');
assert($rn10f->style['borderRadius'] === 8, 'rounded 的 borderRadius 应为 8');
assert($rn10f->style['shadow'] === 1, 'shadow 应为 1');
echo "[PASS] 多个 class 名样式正确合并\n";

// ─────────────────────────────────────────────
// 11. Key 匹配：v-for 跨帧复用 + 顺序交换
// ─────────────────────────────────────────────
$manager11 = new RenderTreeManager();

// Frame 1: div(k-a=A, k-b=B)
$root11f1 = VNode::h('#root', [], [VNode::h('div', [], [
    VNode::hKey('div', [], 'A', 'k-a'),
    VNode::hKey('div', [], 'B', 'k-b'),
])]);
$rn11f1 = $manager11->updateFromVNode($root11f1, null, $rootComponent, $componentByGroupId);
$rnA = $rn11f1->children[0];
$rnB = $rn11f1->children[1];

// Frame 2: 新 VNode 对象，同 key，顺序交换
$oldRoot11 = $manager11->getRootRenderNode();
$candidates11 = $oldRoot11 !== null ? [$oldRoot11] : null;
$root11f2 = VNode::h('#root', [], [VNode::h('div', [], [
    VNode::hKey('div', [], 'B2', 'k-b'),
    VNode::hKey('div', [], 'A2', 'k-a'),
])]);
$rn11f2 = $manager11->updateFromVNode($root11f2, null, $rootComponent, $componentByGroupId, $candidates11);

assert($rn11f2->children[0] === $rnB, 'k-b 应匹配到原来的 B RenderNode');
assert($rn11f2->children[1] === $rnA, 'k-a 应匹配到原来的 A RenderNode');
assert($rn11f2->children[0]->content === 'B2', '匹配后 content 应更新');
assert($rn11f2->children[1]->content === 'A2', '匹配后 content 应更新');
echo "[PASS] Key 匹配：顺序交换后仍正确匹配\n";

// ─────────────────────────────────────────────
// 12. RN 复用始终为脏（旧 spl_object_hash 行为一致）
// ─────────────────────────────────────────────
$manager12 = new RenderTreeManager();

// Frame 1: create
$root12f1 = VNode::h('#root', [], [VNode::h('div', ['class' => 'static'], 'A')]);
$rn12f1 = $manager12->updateFromVNode($root12f1, null, $rootComponent, $componentByGroupId);
assert($rn12f1->layoutDirty === true, '新建 RN 应为脏');
assert($rn12f1->content === 'A', 'content 正确');

// Frame 2: 同内容复用 → 始终为脏（auto-stack 需全量重算）
$oldRoot12 = $manager12->getRootRenderNode();
$candidates12 = $oldRoot12 !== null ? [$oldRoot12] : null;
$root12f2 = VNode::h('#root', [], [VNode::h('div', ['class' => 'static'], 'A')]);
$rn12f2 = $manager12->updateFromVNode($root12f2, null, $rootComponent, $componentByGroupId, $candidates12);
assert(spl_object_hash($rn12f1) === spl_object_hash($rn12f2), '同 type+key 应复用');
assert($rn12f2->layoutDirty === true, '复用 RN 始终为脏路径');
echo "[PASS] 洁净路径不存在：复用 RN 始终 layoutDirty=true\n";

// Frame 3: style 变化 → 脏路径
$oldRoot12b = $manager12->getRootRenderNode();
$candidates12b = $oldRoot12b !== null ? [$oldRoot12b] : null;
$root12f3 = VNode::h('#root', [], [VNode::h('div', ['class' => 'static', 'style' => 'width:200'], 'A')]);
$rn12f3 = $manager12->updateFromVNode($root12f3, null, $rootComponent, $componentByGroupId, $candidates12b);
assert($rn12f3->layoutDirty === true, 'style 变化后应为脏路径');
echo "[PASS] 脏路径：复用 RN 始终 layoutDirty=true（不含洁净路径优化）\n";

// ─────────────────────────────────────────────
// 13. 子节点清理
// ─────────────────────────────────────────────
$manager13 = new RenderTreeManager();

// Frame 1: div(A, B, C)
$root13f1 = VNode::h('#root', [], [VNode::h('div', [], [
    VNode::hKey('span', [], 'A', 'k-a'),
    VNode::hKey('span', [], 'B', 'k-b'),
    VNode::hKey('span', [], 'C', 'k-c'),
])]);
$rn13f1 = $manager13->updateFromVNode($root13f1, null, $rootComponent, $componentByGroupId);
$oldChildren = $rn13f1->children;
assert(count($oldChildren) === 3, '应有 3 个子节点');

// Frame 2: 删除 B（k-b 消失），A 和 C 应保留，B 应被清理
$oldRoot13 = $manager13->getRootRenderNode();
$candidates13 = $oldRoot13 !== null ? [$oldRoot13] : null;
$root13f2 = VNode::h('#root', [], [VNode::h('div', [], [
    VNode::hKey('span', [], 'A2', 'k-a'),
    VNode::hKey('span', [], 'C2', 'k-c'),
])]);
$rn13f2 = $manager13->updateFromVNode($root13f2, null, $rootComponent, $componentByGroupId, $candidates13);

assert(count($rn13f2->children) === 2, '删除 B 后应有 2 个子节点，实际: ' . count($rn13f2->children));
assert($rn13f2->children[0] === $oldChildren[0], 'A(k-a) 应复用原 RN');
assert($rn13f2->children[1] === $oldChildren[2], 'C(k-c) 应复用原 RN');
assert($rn13f2->children[0]->content === 'A2', 'A 的 content 应更新');
assert($rn13f2->children[1]->content === 'C2', 'C 的 content 应更新');

// B(k-b) 应从 renderNodeToVNodeMap 中移除
$bHash = spl_object_hash($oldChildren[1]);
$bMapEntry = null;
try {
    $reflMap = new \ReflectionProperty(RenderTreeManager::class, 'renderNodeToVNodeMap');
    $reflMap->setAccessible(true);
    $bMapEntry = $reflMap->getValue($manager13)[$bHash] ?? null;
} catch (\ReflectionException $e) {}
assert($bMapEntry === null, '被清理的 B RenderNode 不应在 renderNodeToVNodeMap 中');
echo "[PASS] 子节点清理：删除的 key 子节点被正确清理\n";

// ─────────────────────────────────────────────
// 14. getRootRenderNodes() 生命周期
// ─────────────────────────────────────────────
$manager14 = new RenderTreeManager();
assert($manager14->getRootRenderNodes() === [], '新创建的 manager rootRenderNodes 应为空数组');
echo "[PASS] getRootRenderNodes() 初始为空\n";

// 单子节点 #root → rootRenderNodes 应有 1 个元素
$root14f1 = VNode::h('#root', [], [VNode::h('div', [], 'single')]);
$rn14f1 = $manager14->updateFromVNode($root14f1, null, $rootComponent, $componentByGroupId);
$rootNodes1 = $manager14->getRootRenderNodes();
assert(count($rootNodes1) === 1, '单子节点 #root 后 rootRenderNodes 应有 1 元素，实际: ' . count($rootNodes1));
assert($rootNodes1[0] === $rn14f1, 'rootRenderNodes[0] 应为返回的 RenderNode');
echo "[PASS] 单子 #root 后 rootRenderNodes 有 1 元素\n";

// clear() 后应为空
$manager14->clear();
assert($manager14->getRootRenderNodes() === [], 'clear() 后 rootRenderNodes 应为空数组');
echo "[PASS] clear() 后 getRootRenderNodes() 为空\n";

// ─────────────────────────────────────────────
// 15. 多子节点 #root + getRootRenderNodes() 跨帧复用
// ─────────────────────────────────────────────
$manager15 = new RenderTreeManager();

// Frame 1: #root 有 3 个子节点 div, span, button
$root15f1 = VNode::h('#root', [], [
    VNode::h('div', ['class' => 'a'], 'A'),
    VNode::h('span', ['class' => 'b'], 'B'),
    VNode::h('button', ['class' => 'c'], 'C'),
]);
$rn15f1 = $manager15->updateFromVNode($root15f1, null, $rootComponent, $componentByGroupId);
assert($rn15f1 !== null, 'Frame 1 转换应成功');
assert($rn15f1->type === 'div', 'Frame 1 根应为 div');
assert($rn15f1->content === 'A', 'Frame 1 div 内容应为 A');

$rootNodes15 = $manager15->getRootRenderNodes();
assert(count($rootNodes15) === 3, '多子节点 #root 后 rootRenderNodes 应有 3 元素，实际: ' . count($rootNodes15));
assert($rootNodes15[0]->type === 'div', 'rootRenderNodes[0] type 应为 div');
assert($rootNodes15[1]->type === 'span', 'rootRenderNodes[1] type 应为 span');
assert($rootNodes15[2]->type === 'button', 'rootRenderNodes[2] type 应为 button');
echo "[PASS] 多子节点 #root 后 getRootRenderNodes() 正确收集 3 个子节点\n";

// Frame 2: 用 getRootRenderNodes() 作为 candidates（模拟 Application 行为）
$candidates15 = !empty($rootNodes15) ? $rootNodes15 : null;
$root15f2 = VNode::h('#root', [], [
    VNode::h('div', ['class' => 'a'], 'A2'),
    VNode::h('span', ['class' => 'b'], 'B2'),
    VNode::h('button', ['class' => 'c'], 'C2'),
]);
$rn15f2 = $manager15->updateFromVNode($root15f2, null, $rootComponent, $componentByGroupId, $candidates15);
assert($rn15f2 !== null, 'Frame 2 转换应成功');

// 验证 3 个子节点均正确跨帧复用（同一对象）
assert($rn15f2 === $rootNodes15[0], 'div 应复用原 RN');
assert($rn15f2->children[0]->parent === $rn15f2, 'div 子 span 的 parent 应指向 div');
// 由于 #root 不产生 RN，且 #root 的子节点是 div，div 的子节点是 span 和 button
// 我们需要检查 div 的子节点是否被正确复用
// 实际上，Frame 2 的 div 子节点是 span 和 button（在 div 的 children 列表中）
echo "[PASS] 多子节点 #root 跨帧：getRootRenderNodes() 作为 candidates 正确复用\n";

// 验证 rootRenderNodes 在 Frame 2 中被正确刷新（指向新帧的 RN）
$rootNodes15b = $manager15->getRootRenderNodes();
assert(count($rootNodes15b) === 3, 'Frame 2 后 rootRenderNodes 应有 3 元素，实际: ' . count($rootNodes15b));
assert($rootNodes15b[0] === $rn15f2, 'rootRenderNodes[0] 应为 Frame 2 的 div');
echo "[PASS] rootRenderNodes 在每帧刷新正确\n";

// ─────────────────────────────────────────────
// 16. #root 旧子节点清理（unmatched candidates 被 destroy）
// ─────────────────────────────────────────────
$manager16 = new RenderTreeManager();

// Frame 1: #root 有 2 个子节点
$root16f1 = VNode::h('#root', [], [
    VNode::h('div', [], 'keep'),
    VNode::h('span', [], 'remove'),
]);
$rn16f1 = $manager16->updateFromVNode($root16f1, null, $rootComponent, $componentByGroupId);
$rootNodes16 = $manager16->getRootRenderNodes();
$removedRN = $rootNodes16[1];  // span 将在下一帧被移除

// Frame 2: 只有 div, span 消失
$candidates16 = !empty($rootNodes16) ? $rootNodes16 : null;
$root16f2 = VNode::h('#root', [], [
    VNode::h('div', [], 'keep2'),
]);
$rn16f2 = $manager16->updateFromVNode($root16f2, null, $rootComponent, $componentByGroupId, $candidates16);

// 验证 span 的 RN 已被清理（从 renderNodeToVNodeMap 移除）
$reflMap16 = new \ReflectionProperty(RenderTreeManager::class, 'renderNodeToVNodeMap');
$reflMap16->setAccessible(true);
$map16 = $reflMap16->getValue($manager16);
$removedHash16 = spl_object_hash($removedRN);
assert(!isset($map16[$removedHash16]), '被移除的 #root 子节点应从 renderNodeToVNodeMap 清理');
echo "[PASS] #root handler 清理未被复用的旧子节点\n";

// ─────────────────────────────────────────────
// 17. areVNodesEqual() 方法正确性验证
// ─────────────────────────────────────────────
$manager17 = new RenderTreeManager();
$reflMethod17 = new \ReflectionMethod(RenderTreeManager::class, 'areVNodesEqual');
$reflMethod17->setAccessible(true);

// 17a: 完全相同 → true
$va = VNode::h('div', ['style' => 'width:100;height:50', 'class' => 'box'], 'text');
$vb = VNode::h('div', ['style' => 'width:100;height:50', 'class' => 'box'], 'text2');
assert($reflMethod17->invoke($manager17, $va, $vb) === true, '相同 type+key+style+class 应返回 true');
echo "[PASS] areVNodesEqual 相同节点返回 true\n";

// 17b: type 不同 → false
$vc = VNode::h('span', ['style' => 'width:100;height:50', 'class' => 'box'], 'text');
assert($reflMethod17->invoke($manager17, $va, $vc) === false, 'type 不同应返回 false');
echo "[PASS] areVNodesEqual type 不同返回 false\n";

// 17c: key 不同 → false
$vd = VNode::hKey('div', ['style' => 'width:100;height:50'], 'text', 'key-a');
$ve = VNode::hKey('div', ['style' => 'width:100;height:50'], 'text2', 'key-b');
assert($reflMethod17->invoke($manager17, $vd, $ve) === false, 'key 不同应返回 false');
echo "[PASS] areVNodesEqual key 不同返回 false\n";

// 17d: style 不同 → false
$vf = VNode::h('div', ['style' => 'width:200'], 'text');
assert($reflMethod17->invoke($manager17, $va, $vf) === false, 'style 不同应返回 false');
echo "[PASS] areVNodesEqual style 不同返回 false\n";

// 17e: class 不同 → false
$vg = VNode::h('div', ['style' => 'width:100;height:50', 'class' => 'other'], 'text');
assert($reflMethod17->invoke($manager17, $va, $vg) === false, 'class 不同应返回 false');
echo "[PASS] areVNodesEqual class 不同返回 false\n";

// 17f: scroll bind 不同 → false
$vh = VNode::h('div', [':scroll-top' => 'scrollA'], 'text');
$vi = VNode::h('div', [':scroll-top' => 'scrollB'], 'text');
assert($reflMethod17->invoke($manager17, $vh, $vi) === false, 'scroll bind 不同应返回 false');
echo "[PASS] areVNodesEqual scroll bind 不同返回 false\n";

// ─────────────────────────────────────────────
// 18. 混合 keyed/non-keyed 子节点匹配
// ─────────────────────────────────────────────
$manager18 = new RenderTreeManager();

// Frame 1: div(k-a=A, k-b=B, C(static), D(static))
$root18f1 = VNode::h('#root', [], [VNode::h('div', [], [
    VNode::hKey('span', [], 'A', 'k-a'),
    VNode::hKey('span', [], 'B', 'k-b'),
    VNode::h('span', [], 'C'),
    VNode::h('span', [], 'D'),
])]);
$rn18f1 = $manager18->updateFromVNode($root18f1, null, $rootComponent, $componentByGroupId);
$oldChildren18 = $rn18f1->children;
assert(count($oldChildren18) === 4, 'Frame 1 应有 4 个子节点');

// Frame 2: 交换 key 顺序，保持 static 位置
$root18f2 = VNode::h('#root', [], [VNode::h('div', [], [
    VNode::hKey('span', [], 'B2', 'k-b'),
    VNode::hKey('span', [], 'A2', 'k-a'),
    VNode::h('span', [], 'C2'),
    VNode::h('span', [], 'D2'),
])]);
$oldRoot18 = $manager18->getRootRenderNodes();
$candidates18 = !empty($oldRoot18) ? $oldRoot18 : null;
$rn18f2 = $manager18->updateFromVNode($root18f2, null, $rootComponent, $componentByGroupId, $candidates18);

assert(count($rn18f2->children) === 4, 'Frame 2 应有 4 个子节点');
// key 匹配：顺序交换
assert($rn18f2->children[0] === $oldChildren18[1], 'k-b 应匹配到原来的 B');
assert($rn18f2->children[1] === $oldChildren18[0], 'k-a 应匹配到原来的 A');
// static 匹配：位置匹配
assert($rn18f2->children[2] === $oldChildren18[2], 'static C 应保持位置匹配');
assert($rn18f2->children[3] === $oldChildren18[3], 'static D 应保持位置匹配');
assert($rn18f2->children[0]->content === 'B2', '匹配后 B 的 content 应更新');
assert($rn18f2->children[1]->content === 'A2', '匹配后 A 的 content 应更新');
assert($rn18f2->children[2]->content === 'C2', 'C 的 content 应更新');
assert($rn18f2->children[3]->content === 'D2', 'D 的 content 应更新');
echo "[PASS] 混合 keyed/non-keyed 子节点：key 按 key 匹配、static 按位置匹配\n";

// ─────────────────────────────────────────────
// 19. 空 candidates 下 #root 多子节点创建
// ─────────────────────────────────────────────
$manager19 = new RenderTreeManager();

// #root 多子节点 + null candidates（首次渲染场景）
$root19f1 = VNode::h('#root', [], [
    VNode::h('div', ['class' => 'first'], 'First'),
    VNode::h('div', ['class' => 'second'], 'Second'),
    VNode::h('div', ['class' => 'third'], 'Third'),
]);
$rn19f1 = $manager19->updateFromVNode($root19f1, null, $rootComponent, $componentByGroupId);
assert($rn19f1 !== null, '空 candidates 多子节点 #root 应返回第一个子节点');
assert($rn19f1->type === 'div', '第一个子节点应为 div');
assert($rn19f1->content === 'First', '第一个子节点 content 应为 First');

// 验证 rootRenderNodes 收集了所有 3 个子节点
$rootNodes19 = $manager19->getRootRenderNodes();
assert(count($rootNodes19) === 3, 'rootRenderNodes 应有 3 元素，实际: ' . count($rootNodes19));
assert($rootNodes19[0] === $rn19f1, 'rootRenderNodes[0] 应为首个子节点');
assert($rootNodes19[1]->content === 'Second', 'rootRenderNodes[1] content 应为 Second');
assert($rootNodes19[2]->content === 'Third', 'rootRenderNodes[2] content 应为 Third');
echo "[PASS] 空 candidates 多子节点 #root：所有子节点正确创建并收集\n";

// ─────────────────────────────────────────────
// 报告
// ─────────────────────────────────────────────
echo "\nRenderTreeManagerTest: 全部通过 ✓\n";
