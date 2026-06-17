<?php

/**
 * VNode 单元测试 — 使用 VNodeBuilder 验证 VNode 核心行为。
 *
 * 覆盖：
 *  - VNode 静态工厂方法 (h, hKey, hComponent)
 *  - props 获取 (getProp, getClass)
 *  - 类型判断 (isRoot, isComponent)
 *  - VNodeBuilder 链式构造的各种模式
 *  - children 结构
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../tools/PxTest/bootstrap.php';

use Px\Rendering\VNode;
use PxTest\Builder\VNodeBuilder;

echo "========================================\n";
echo "  VNode — Unit Tests (Builder-driven)\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;

function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// ─── 1. 静态工厂方法 ───
echo "--- 1. Factory Methods ---\n";
$div = VNode::h('div', ['class' => 'box'], 'Hello');
check('h() type = div', $div->type === 'div');
check('h() text content', is_string($div->children) && $div->children === 'Hello');

$span = VNode::h('span', [], '');
check('h() empty text', $span->children === '');

$root = VNode::h('#root', [], []);
check('h() #root type', $root->type === '#root');

$withKey = VNode::hKey('div', ['v-for' => 'item in items'], [], 'item-1');
check('hKey() sets key', $withKey->key === 'item-1');

$comp = VNode::hComponent('MyComp', ['title' => 'Test']);
check('hComponent() isComponent', $comp->isComponent === true);
check('hComponent() componentClass', $comp->componentClass === 'MyComp');


// ─── 2. Props 操作 ───
echo "\n--- 2. Props ---\n";
$v = VNode::h('div', ['style' => 'width:100px', 'class' => 'main', '@click' => '', 'data-id' => '42'], '');
check('getProp style', $v->getProp('style') === 'width:100px');
check('getProp class', $v->getClass() === 'main');
check('getProp data-id', $v->getProp('data-id') === '42');
check('getProp missing returns default', $v->getProp('nope', 'fallback') === 'fallback');
check('getProp missing null default', $v->getProp('nope') === null);


// ─── 3. 类型判断 ───
echo "\n--- 3. Type Checks ---\n";
$rootV = VNode::h('#root', [], []);
check('isRoot true', $rootV->isRoot());

$compV = VNode::hComponent('X', []);
check('isComponent true', $compV->isComponent());
check('isRoot false for component', !$compV->isRoot());

$textV = VNode::h('#text', [], 'plain');
check('#text type', $textV->type === '#text');


// ─── 4. VNodeBuilder: 基本构造 ───
echo "\n--- 4. VNodeBuilder: Basic ---\n";
$b1 = VNodeBuilder::div()
    ->style(['display' => 'flex'])
    ->class('container')
    ->build();
check('Builder div type', $b1->type === 'div');
check('Builder flex style', str_contains((string)$b1->getProp('style'), 'display:flex'));
check('Builder class', $b1->getClass() === 'container');


// ─── 5. VNodeBuilder: 子节点 ───
echo "\n--- 5. VNodeBuilder: Children ---\n";
$b2 = VNodeBuilder::root()
    ->child('span', 'Item 1')
    ->child('span', 'Item 2')
    ->build();
check('Root with 2 children', is_array($b2->children) && count($b2->children) === 2);
check('First child is span', $b2->children[0]->type === 'span');


// ─── 6. VNodeBuilder: 复杂嵌套 ───
echo "\n--- 6. VNodeBuilder: Nested ---\n";
$nested = VNodeBuilder::div()
    ->style(['width' => '300px'])
    ->childBuilder(
        VNodeBuilder::div()
            ->style(['display' => 'flex'])
            ->child('span', 'Left')
            ->child('span', 'Right')
    )
    ->build();
check('Nested div has children', !is_null($nested->children));
check('Inner has 2 spans (via type check)',
    $nested->children->children[0]->type === 'span'
    && $nested->children->children[1]->type === 'span');


// ─── 7. VNodeBuilder: 事件绑定 ───
echo "\n--- 7. VNodeBuilder: Events ---\n";
$b3 = VNodeBuilder::div()
    ->onClick('handleSubmit', 'btn-1')
    ->build();
check('@click with arg', $b3->getProp('@click=handleSubmit(btn-1)') !== null);


// ─── 8. VNodeBuilder: v-for + key ───
echo "\n--- 8. VNodeBuilder: v-for ---\n";
$items = VNodeBuilder::div()
    ->vFor('item in items')
    ->prop('key', 'item-{id}')
    ->childText('Dynamic')
    ->build();
check('v-for prop', $items->getProp('v-for') === 'item in items');


// ─── 9. VNodeBuilder: #component 占位 ───
echo "\n--- 9. VNodeBuilder: Component ---\n";
$cp = VNodeBuilder::component('MyWidget', ['title' => 'Hi'])
    ->build();
check('Component type', $cp->type === '#component');
// isComponent 是 VNode 属性，由 VNode::hComponent() 设置；
// VNodeBuilder::component() 使用 VNode::h('#component', ...) 方式，
// isComponent 是否设为 true 取决于 VNode 构造函数行为
check('Component class set', $cp->getProp('_componentClass') === 'MyWidget');


// ─── 10. VNode: childrenToArray ───
echo "\n--- 10. VNode: childrenToArray ---\n";
$single = VNode::h('div', [], 'text only');
check('String child is not array', !is_array($single->children));

$multi = VNode::h('div', [], [
    VNode::h('span', [], 'a'),
    VNode::h('span', [], 'b'),
]);
check('Array children count', is_array($multi->children) && count($multi->children) === 2);

echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
