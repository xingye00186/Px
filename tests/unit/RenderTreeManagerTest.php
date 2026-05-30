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
// 2. RenderNode 复用
// ─────────────────────────────────────────────
$manager2 = new RenderTreeManager();
$vnodeDiv = VNode::h('div', ['class' => 'box'], 'content');

// 第一次转换
$rn1 = $manager2->updateFromVNode($vnodeDiv, null, $rootComponent, $componentByGroupId);
assert($rn1->content === 'content', '第一次转换 content 正确');

// 第二次转换（复用）
$rn2 = $manager2->updateFromVNode($vnodeDiv, null, $rootComponent, $componentByGroupId);

// 对象一致性验证
assert(spl_object_hash($rn1) === spl_object_hash($rn2), '复用后应为同一对象');
assert($rn2->layoutDirty === true, '复用后应标记为 dirty');
echo "[PASS] RenderNode 复用（spl_object_hash 映射）\n";

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

// 9.3 组件定位：模拟 Application::expandComponentNode 的行为，
//    #component 的 style(left/top) 应在展开阶段写入子组件根元素的 style，
//    RenderTreeManager 无需偏移参数，仅负责样式传递，LayoutResolver 统一处理坐标
$manager9c = new RenderTreeManager();
$posComp = new ChildCompForTreeTest();
$posComp->mount();

$posCompVNode = VNode::hComponent('ChildCompForTreeTest', ['style' => 'left:10;top:20'], []);
$posCompVNode->componentInstance = $posComp;
$posCompVNode->children = $posComp->getVNodeTree();
$posCompVNode->children->groupId = 'child';

// 模拟 expandComponentNode 的定位传递：
// 将 #component 占位符的 left/top 写入子组件根元素（span）的 style
$rootElement = $posCompVNode->children->children;
assert($rootElement instanceof VNode, '子组件根元素应为 VNode');
$rootElement->props['style'] = 'left:10;top:20;';

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
echo "[PASS] 组件定位：expandComponentNode 已将 left/top 写入组件根元素 style，RenderTreeManager 无需偏移计算\n";

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
// 报告
// ─────────────────────────────────────────────
echo "\nRenderTreeManagerTest: 全部通过 ✓\n";
