<?php
/**
 * VNodeRenderer 单元测试
 * 
 * 测试目标:
 *   1. collectElements: 不在运行时检查 v-if (编译期已处理)
 *   2. 元素按 layer 分组
 *   3. 不同类型元素收集正确
 *   4. scroll 容器子元素偏移
 * 
 * Usage: php tests/unit/VNodeRendererTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\VNode;
use Px\Rendering\VNodeRenderer;
use Px\Rendering\RenderContext;

echo "========================================\n";
echo " VNodeRenderer 单元测试\n";
echo "========================================\n\n";

// ---- 测试用 RenderContext mock ----
class _MockRenderContext extends RenderContext
{
    public bool $beginFrameCalled = false;
    public bool $endFrameCalled = false;

    public function beginFrame(): void { $this->beginFrameCalled = true; }
    public function endFrame(): void { $this->endFrameCalled = true; }
    public function drawElement(array $element): void {}
    public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold): void {}
    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
}

// ---- 测试用 ReactiveComponent mock ----
class _MockComponent extends \Px\ReactiveComponent
{
    public function __construct()
    {
        $this->dirty = true;
        $this->isMounted = false;
        $this->hasPendingUpdate = false;
        $this->isUpdating = false;
        $this->listenerIds = [];
        $this->vnodeCache = null;
    }

    public function render(): VNode { return VNode::h('div'); }
    public function onMount(): void {}
    public function dispatchClick(string $handler, ?string $arg = null): void {}
    public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void {}
    public function setBindValue(string $bindKey, string $value): void {}
    public function getBindValue(string $bindKey): string { return ''; }
}

/**
 * 通过反射调用 private VNodeRenderer::collectElements()
 */
function invokeCollectElements(VNodeRenderer $renderer, VNode $root): array
{
    $refl = new \ReflectionClass(VNodeRenderer::class);
    $method = $refl->getMethod('collectElements');
    $method->setAccessible(true);

    $elementsByLayer = [];
    $maxLayer = 0;
    $method->invokeArgs($renderer, [$root, &$elementsByLayer, &$maxLayer]);

    return ['elements' => $elementsByLayer, 'maxLayer' => $maxLayer];
}

echo "--- 1. 无运行时 v-if 检查 ---\n";

test('即使有 v-if prop 也不跳过渲染 (编译期已处理)', function () {
    // 在编译期 v-if 被移除，运行时不会出现。但即使伪造它，渲染器也不应检查。
    $node = new VNode('button', ['v-if' => 'showMe', '@click' => 'test']);
    $node->x = 0; $node->y = 0; $node->w = 100; $node->h = 40;
    $node->layer = 0;
    $node->computedStyle = ['bg' => 0x323232, 'fg' => 0xFFFFFF];

    $root = VNode::h('#root', [], [$node]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $ctx = new _MockRenderContext();
    $comp = new _MockComponent();
    $renderer = new VNodeRenderer($comp, $ctx);

    $result = invokeCollectElements($renderer, $root);

    // 即使有 v-if prop，元素也应被收集（v-if 在编译期处理）
    $layer0 = $result['elements'][0] ?? [];
    assert_eq(count($layer0) > 0, true, '即使有 v-if prop，元素也应被收集 (v-if 运行时不再检查)');
});

echo "\n--- 2. 元素按 Layer 分组 ---\n";

test('同 layer 的元素在同一组', function () {
    $c1 = new VNode('button', ['@click' => 'a']);
    $c1->x = 0; $c1->y = 0; $c1->w = 50; $c1->h = 50; $c1->layer = 0;

    $c2 = new VNode('button', ['@click' => 'b']);
    $c2->x = 60; $c2->y = 0; $c2->w = 50; $c2->h = 50; $c2->layer = 0;

    $root = VNode::h('#root', [], [$c1, $c2]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $ctx = new _MockRenderContext();
    $comp = new _MockComponent();
    $renderer = new VNodeRenderer($comp, $ctx);

    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];
    assert_eq(count($layer0), 2, 'layer 0 应有 2 个元素');
    assert_eq($result['maxLayer'], 0, 'maxLayer 应为 0');
});

test('不同 layer 的元素在不同组', function () {
    $c1 = new VNode('button', ['@click' => 'layer1Btn']);
    $c1->x = 0; $c1->y = 0; $c1->w = 100; $c1->h = 50; $c1->layer = 0;

    $c2 = new VNode('button', ['@click' => 'layer5Btn']);
    $c2->x = 0; $c2->y = 60; $c2->w = 100; $c2->h = 50; $c2->layer = 5;

    $root = VNode::h('#root', [], [$c1, $c2]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $ctx = new _MockRenderContext();
    $comp = new _MockComponent();
    $renderer = new VNodeRenderer($comp, $ctx);

    $result = invokeCollectElements($renderer, $root);

    assert_eq(count($result['elements'][0] ?? []), 1, 'layer 0 应有 1 个元素');
    assert_eq(count($result['elements'][5] ?? []), 1, 'layer 5 应有 1 个元素');
    assert_eq($result['maxLayer'], 5, 'maxLayer 应为 5');
});

echo "\n--- 3. 元素类型识别 ---\n";

test('button 类型生成 button 元素', function () {
    $btn = new VNode('button', ['@click' => 'clickMe']);
    $btn->x = 0; $btn->y = 0; $btn->w = 100; $btn->h = 40; $btn->layer = 0;
    $btn->computedStyle = ['bg' => 0x323232, 'fg' => 0xFFFFFF];

    $root = VNode::h('#root', [], [$btn]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 100;

    $ctx = new _MockRenderContext();
    $comp = new _MockComponent();
    $renderer = new VNodeRenderer($comp, $ctx);

    $result = invokeCollectElements($renderer, $root);

    $el = $result['elements'][0][0] ?? null;
    assert_not_null($el, '应收集到 button 元素');
    assert_eq($el['type'], 'button', '元素类型应为 button');
});

test('span 类型生成 text 元素', function () {
    $span = new VNode('span', ['class' => 'label'], 'Hello');
    $span->x = 0; $span->y = 0; $span->w = 100; $span->h = 30; $span->layer = 0;
    $span->computedStyle = ['fontSize' => 16, 'fg' => 0xFFFFFF, 'bold' => 0];

    $root = VNode::h('#root', [], [$span]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 100;

    $ctx = new _MockRenderContext();
    $comp = new _MockComponent();
    $renderer = new VNodeRenderer($comp, $ctx);

    $result = invokeCollectElements($renderer, $root);

    $el = $result['elements'][0][0] ?? null;
    assert_not_null($el, '应收集到 text 元素');
    assert_eq($el['type'], 'text', '元素类型应为 text');
    assert_eq($el['text'], 'Hello', '文本应为 Hello');
});

test('div 无背景色时返回 null 元素', function () {
    $div = new VNode('div', []);
    $div->x = 0; $div->y = 0; $div->w = 100; $div->h = 100; $div->layer = 0;
    $div->computedStyle = [];

    $root = VNode::h('#root', [], [$div]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $ctx = new _MockRenderContext();
    $comp = new _MockComponent();
    $renderer = new VNodeRenderer($comp, $ctx);

    $result = invokeCollectElements($renderer, $root);

    // 无背景的 div 不应生成元素
    $layer0 = $result['elements'][0] ?? [];
    assert_eq(count($layer0), 0, '无背景 div 不应生成渲染元素');
});

echo "\n--- 4. render() 完整流程 ---\n";

test('render() 调用 beginFrame 和 endFrame', function () {
    $btn = new VNode('button', ['@click' => 'test']);
    $btn->x = 0; $btn->y = 0; $btn->w = 100; $btn->h = 40; $btn->layer = 0;
    $btn->computedStyle = ['bg' => 0x323232, 'fg' => 0xFFFFFF];

    $root = VNode::h('#root', [], [$btn]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 100;

    $ctx = new _MockRenderContext();
    $comp = new _MockComponent();
    $renderer = new VNodeRenderer($comp, $ctx);

    $renderer->render($root);

    assert_true($ctx->beginFrameCalled, 'beginFrame 应被调用');
    assert_true($ctx->endFrameCalled, 'endFrame 应被调用');
});

test('空 VNode 树渲染不会崩溃', function () {
    $root = VNode::h('#root', ['style' => 'width:400px;height:300px;'], []);
    $root->x = 0; $root->y = 0; $root->w = 400; $root->h = 300;

    $ctx = new _MockRenderContext();
    $comp = new _MockComponent();
    $renderer = new VNodeRenderer($comp, $ctx);

    // 不应抛出异常
    $renderer->render($root);

    assert_true($ctx->beginFrameCalled, '空树也应 beginFrame');
    assert_true($ctx->endFrameCalled, '空树也应 endFrame');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
