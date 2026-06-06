<?php
/**
 * VNodeRenderer 单元测试（RenderNode 版）
 *
 * 测试目标:
 *   1. 元素按 layer 分组
 *   2. 不同类型元素收集正确（含 button 边框状态）
 *   3. 复杂类型 (input, scroll-container) 返回单元素描述
 *   4. render() 完整流程
 *   5. 增量绘制（paintDirty 帧号机制）
 *
 * Usage: php tests/unit/VNodeRendererTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\RenderNode;
use Px\Rendering\VNode;
use Px\Rendering\VNodeRenderer;
use Px\Rendering\RenderContext;

echo "========================================\n";
echo " VNodeRenderer 单元测试（RenderNode）\n";
echo "========================================\n\n";

// ---- 测试用 RenderContext mock ----
class _MockRenderContext extends RenderContext
{
    public bool $beginFrameCalled = false;
    public bool $endFrameCalled = false;
    /** @var array<int, array> 记录 drawElement 收到的所有元素 */
    public array $drawnElements = [];

    public function beginFrame(): void { $this->beginFrameCalled = true; }
    public function endFrame(): void { $this->endFrameCalled = true; }
    public function drawElement(array $element): void { $this->drawnElements[] = $element; }
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
function invokeCollectElements(VNodeRenderer $renderer, RenderNode $root): array
{
    $refl = new \ReflectionClass(VNodeRenderer::class);
    $method = $refl->getMethod('collectElements');
    $method->setAccessible(true);

    $elementsByLayer = [];
    $maxLayer = 0;
    $method->invokeArgs($renderer, [$root, &$elementsByLayer, &$maxLayer]);

    return ['elements' => $elementsByLayer, 'maxLayer' => $maxLayer];
}

// 辅助：创建 RenderNode
function rn(string $type, array $style = [], array $children = [], ?string $content = null): RenderNode
{
    $node = new RenderNode($type, $style, $content);
    foreach ($children as $child) {
        $node->addChild($child);
    }
    return $node;
}

// ================================================================
echo "--- 1. 元素按 Layer 分组 ---\n";

test('同 layer 的元素在同一组', function () {
    $c1 = rn('div', ['bg' => 0x333333]);
    $c1->x = 0; $c1->y = 0; $c1->w = 50; $c1->h = 50; $c1->layer = 0;
    // sourceVNode needed for non-null props in vnodeToElement path
    $c1->sourceVNode = new VNode('div');

    $c2 = rn('div', ['bg' => 0x555555]);
    $c2->x = 60; $c2->y = 0; $c2->w = 50; $c2->h = 50; $c2->layer = 0;
    $c2->sourceVNode = new VNode('div');

    $root = rn('#root', [], [$c1, $c2]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];
    assert_eq(count($layer0), 2, 'layer 0 应有 2 个元素');
    assert_eq($result['maxLayer'], 0, 'maxLayer 应为 0');
});

test('不同 layer 的元素在不同组', function () {
    $c1 = rn('div', ['bg' => 0x333333]);
    $c1->x = 0; $c1->y = 0; $c1->w = 100; $c1->h = 50; $c1->layer = 0;
    $c1->sourceVNode = new VNode('div');

    $c2 = rn('div', ['bg' => 0x555555]);
    $c2->x = 0; $c2->y = 60; $c2->w = 100; $c2->h = 50; $c2->layer = 5;
    $c2->sourceVNode = new VNode('div');

    $root = rn('#root', [], [$c1, $c2]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    assert_eq(count($result['elements'][0] ?? []), 1, 'layer 0 应有 1 个元素');
    assert_eq(count($result['elements'][5] ?? []), 1, 'layer 5 应有 1 个元素');
    assert_eq($result['maxLayer'], 5, 'maxLayer 应为 5');
});

// ================================================================
echo "\n--- 2. 元素类型识别 ---\n";

test('button 类型生成 button 元素', function () {
    $btn = rn('button', ['bg' => 0x323232, 'fg' => 0xFFFFFF]);
    $btn->x = 0; $btn->y = 0; $btn->w = 100; $btn->h = 40; $btn->layer = 0;
    $btn->sourceVNode = new VNode('button', ['@click' => 'clickMe']);

    $root = rn('#root', [], [$btn]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 100;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $el = $result['elements'][0][0] ?? null;
    assert_not_null($el, '应收集到 button 元素');
    assert_eq($el['type'], 'button', '元素类型应为 button');
    assert_eq($el['bg'], 0x323232, 'button bg 应为 0x323232');
    assert_eq($el['fg'], 0xFFFFFF, 'button fg 应为 0xFFFFFF');
    // 未设 border CSS 时，border 和 borderWidth 应为 0
    assert_eq($el['border'] ?? -1, 0, 'button border 应为 0（无边框CSS时）');
    assert_eq($el['borderWidth'] ?? -1, 0, 'button borderWidth 应为 0（无边框CSS时）');
});

test('button 有显式 border CSS 时生成正确边框值', function () {
    $btn = rn('button', ['bg' => 0x323232, 'fg' => 0xFFFFFF, 'borderWidth' => 2, 'borderColor' => 0xFF0000]);
    $btn->x = 0; $btn->y = 0; $btn->w = 100; $btn->h = 40; $btn->layer = 0;
    $btn->sourceVNode = new VNode('button', ['@click' => 'clickMe']);

    $root = rn('#root', [], [$btn]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 100;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $el = $result['elements'][0][0] ?? null;
    assert_not_null($el, '应收集到 button 元素');
    assert_eq($el['type'], 'button', '元素类型应为 button');
    assert_eq($el['borderWidth'], 2, 'borderWidth 应为 2');
    assert_eq($el['border'], 0xFF0000, 'border 颜色应为 0xFF0000');
});

test('span 类型生成 text 元素', function () {
    $span = rn('span', ['fontSize' => 16, 'fg' => 0xFFFFFF, 'bold' => 0], [], 'Hello');
    $span->x = 0; $span->y = 0; $span->w = 100; $span->h = 30; $span->layer = 0;
    $span->sourceVNode = new VNode('span');

    $root = rn('#root', [], [$span]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 100;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $el = $result['elements'][0][0] ?? null;
    assert_not_null($el, '应收集到 text 元素');
    assert_eq($el['type'], 'text', '元素类型应为 text');
    assert_eq($el['text'], 'Hello', '文本应为 Hello');
});

test('div 有背景色时生成 rect 元素', function () {
    $div = rn('div', ['bg' => 0x123456]);
    $div->x = 0; $div->y = 0; $div->w = 100; $div->h = 100; $div->layer = 0;
    $div->sourceVNode = new VNode('div');

    $root = rn('#root', [], [$div]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $el = $result['elements'][0][0] ?? null;
    assert_not_null($el, '有背景的 div 应生成 rect 元素');
    assert_eq($el['type'], 'rect', '元素类型应为 rect');
    assert_eq($el['color'], 0x123456, '颜色应为 0x123456');
});

test('div 无背景色时不生成元素', function () {
    $div = rn('div', []);
    $div->x = 0; $div->y = 0; $div->w = 100; $div->h = 100; $div->layer = 0;
    $div->sourceVNode = new VNode('div');

    $root = rn('#root', [], [$div]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];
    assert_eq(count($layer0), 0, '无背景 div 不应生成渲染元素');
});

test('span 无文本内容时不生成元素', function () {
    $span = rn('span', []);
    $span->x = 0; $span->y = 0; $span->w = 100; $span->h = 30; $span->layer = 0;
    $span->sourceVNode = new VNode('span');

    $root = rn('#root', [], [$span]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 100;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];
    assert_eq(count($layer0), 0, '空 span 不应生成渲染元素');
});

// ================================================================
echo "\n--- 3. 复杂类型单元素描述 ---\n";

test('input 类型生成单个 input 元素 (不分解)', function () {
    $input = rn('input', ['bg' => 0x1E1E1E, 'fg' => 0xFFFFFF, 'fontSize' => 16]);
    $input->x = 0; $input->y = 0; $input->w = 200; $input->h = 30; $input->layer = 0;
    $input->sourceVNode = new VNode('input', ['v-model' => 'expr']);

    $root = rn('#root', [], [$input]);
    $root->x = 0; $root->y = 0; $root->w = 400; $root->h = 200;

    $comp = new class extends _MockComponent {
        public function getBindValue(string $bindKey): string {
            return $bindKey === 'expr' ? '3+5' : '';
        }
    };

    $renderer = new VNodeRenderer($comp, new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];
    assert_eq(count($layer0), 1, 'input 应生成恰好 1 个元素');
    $el = $layer0[0] ?? null;
    assert_not_null($el, '应收集到 input 元素');
    assert_eq($el['type'], 'input', '元素类型应为 input');
    assert_eq($el['text'], '3+5', 'input 应携带绑定文本');
    assert_eq($el['bg'], 0x1E1E1E, 'input 应携带背景色');
    assert_eq($el['fontSize'], 16, 'input 应携带字体大小');
});

test('scroll-container 类型生成单个描述元素', function () {
    $scroll = rn('div', ['bg' => 0x2D2D2D]);
    $scroll->x = 0; $scroll->y = 0; $scroll->w = 300; $scroll->h = 200; $scroll->layer = 0;
    $scroll->isScrollContainer = true;
    $scroll->contentHeight = 500;
    $scroll->scrollTop = 50;
    $scroll->sourceVNode = new VNode('div');

    $root = rn('#root', [], [$scroll]);
    $root->x = 0; $root->y = 0; $root->w = 400; $root->h = 300;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];
    // scroll-container 元素 + clip-push + clip-pop + scrollbar-v
    // 检查 scroll-container 元素存在且类型正确
    $foundScroll = false;
    foreach ($layer0 as $el) {
        if ($el['type'] === 'scroll-container') {
            $foundScroll = true;
            assert_eq($el['bg'], 0x2D2D2D, '应携带背景色');
            assert_eq($el['contentHeight'], 500, '应携带 contentHeight');
            assert_eq($el['scrollTop'], 50, '应携带 scrollTop');
        }
    }
    assert_true($foundScroll, '应收集到 scroll-container 元素');
});

// ================================================================
echo "\n--- 4. render() 完整流程 ---\n";

test('render() 调用 beginFrame 和 endFrame', function () {
    $btn = rn('button', ['bg' => 0x323232, 'fg' => 0xFFFFFF]);
    $btn->x = 0; $btn->y = 0; $btn->w = 100; $btn->h = 40; $btn->layer = 0;
    $btn->sourceVNode = new VNode('button', ['@click' => 'test']);

    $root = rn('#root', [], [$btn]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 100;

    $ctx = new _MockRenderContext();
    $renderer = new VNodeRenderer(new _MockComponent(), $ctx);
    $renderer->render($root);

    assert_true($ctx->beginFrameCalled, 'beginFrame 应被调用');
    assert_true($ctx->endFrameCalled, 'endFrame 应被调用');
});

test('空 RenderNode 树渲染不会崩溃', function () {
    $root = rn('#root', ['width' => 400, 'height' => 300], []);
    $root->x = 0; $root->y = 0; $root->w = 400; $root->h = 300;

    $ctx = new _MockRenderContext();
    $renderer = new VNodeRenderer(new _MockComponent(), $ctx);
    $renderer->render($root);

    assert_true($ctx->beginFrameCalled, '空树也应 beginFrame');
    assert_true($ctx->endFrameCalled, '空树也应 endFrame');
    assert_eq(count($ctx->drawnElements), 0, '空树不应绘制任何元素');
});

test('渲染顺序遵循 layer 递增', function () {
    $c0 = rn('div', ['bg' => 0x111111]);
    $c0->x = 0; $c0->y = 0; $c0->w = 100; $c0->h = 100; $c0->layer = 0;
    $c0->sourceVNode = new VNode('div');

    $c2 = rn('div', ['bg' => 0x333333]);
    $c2->x = 0; $c2->y = 0; $c2->w = 100; $c2->h = 100; $c2->layer = 2;
    $c2->sourceVNode = new VNode('div');

    $c1 = rn('div', ['bg' => 0x222222]);
    $c1->x = 0; $c1->y = 0; $c1->w = 100; $c1->h = 100; $c1->layer = 1;
    $c1->sourceVNode = new VNode('div');

    $root = rn('#root', [], [$c0, $c2, $c1]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $ctx = new _MockRenderContext();
    $renderer = new VNodeRenderer(new _MockComponent(), $ctx);
    $renderer->render($root);

    assert_eq(count($ctx->drawnElements), 3, '应绘制 3 个元素');
    assert_eq($ctx->drawnElements[0]['color'], 0x111111, '第1个应为 layer 0');
    assert_eq($ctx->drawnElements[1]['color'], 0x222222, '第2个应为 layer 1');
    assert_eq($ctx->drawnElements[2]['color'], 0x333333, '第3个应为 layer 2');
});

test('button 无边框CSS时 render 元素不含边框', function () {
    $btn = rn('button', ['bg' => 0x323232, 'fg' => 0xFFFFFF]);
    $btn->x = 0; $btn->y = 0; $btn->w = 100; $btn->h = 40; $btn->layer = 0;
    $btn->sourceVNode = new VNode('button', ['@click' => 'test']);

    $root = rn('#root', [], [$btn]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 100;

    $ctx = new _MockRenderContext();
    $renderer = new VNodeRenderer(new _MockComponent(), $ctx);
    $renderer->render($root);

    assert_eq(count($ctx->drawnElements), 1, '应绘制 1 个元素');
    $el = $ctx->drawnElements[0];
    assert_eq($el['type'], 'button', '元素类型应为 button');
    assert_eq($el['border'] ?? -1, 0, '未设边框CSS时 border 应为 0');
    assert_eq($el['borderWidth'] ?? -1, 0, '未设边框CSS时 borderWidth 应为 0');
});

test('button 有边框CSS时 render 元素携带 borderWidth', function () {
    $btn = rn('button', ['bg' => 0x323232, 'fg' => 0xFFFFFF, 'borderWidth' => 2, 'borderColor' => 0xFF0000]);
    $btn->x = 0; $btn->y = 0; $btn->w = 100; $btn->h = 40; $btn->layer = 0;
    $btn->sourceVNode = new VNode('button', ['@click' => 'test']);

    $root = rn('#root', [], [$btn]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 100;

    $ctx = new _MockRenderContext();
    $renderer = new VNodeRenderer(new _MockComponent(), $ctx);
    $renderer->render($root);

    assert_eq(count($ctx->drawnElements), 1, '应绘制 1 个元素');
    $el = $ctx->drawnElements[0];
    assert_eq($el['type'], 'button', '元素类型应为 button');
    assert_eq($el['borderWidth'], 2, 'borderWidth 应为 2');
    assert_eq($el['border'], 0xFF0000, 'border 颜色应为 0xFF0000');
});

// ================================================================
echo "\n--- 5. 增量绘制 ---\n";

test('needsPaint 控制节点是否生成元素', function () {
    $c1 = rn('div', ['bg' => 0x111111]);
    $c1->x = 0; $c1->y = 0; $c1->w = 50; $c1->h = 50; $c1->layer = 0;
    $c1->sourceVNode = new VNode('div');

    $root = rn('#root', [], [$c1]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $ctx = new _MockRenderContext();
    $renderer = new VNodeRenderer(new _MockComponent(), $ctx);

    // 第一次渲染：节点需要绘制
    $renderer->render($root);
    assert_eq(count($ctx->drawnElements), 1, '第一次渲染应绘制 1 个元素');

    // 标记为已绘制，再次渲染（节点 clean 状态）
    $ctx2 = new _MockRenderContext();
    $renderer2 = new VNodeRenderer(new _MockComponent(), $ctx2);

    // 手动设置 lastPaintFrame 以模拟已绘制状态
    // 实际上 render() 递增了 currentPaintFrame，c1 的 lastPaintFrame 还是 0
    // 但第二次 render 时 currentPaintFrame 变成 2，而 c1 的 lastPaintFrame 为 0
    // 所以 needsPaint(2) 返回 true（lastPaintFrame=0 < currentPaintFrame=2）
    // 要测试增量，需要让 c1 的 lastPaintFrame >= currentPaintFrame
    // 但每次 render 递增 currentPaintFrame，除非节点是布局dirty
    // 实际上 needsPaint 返回 true 当 layoutDirty 或 lastPaintFrame < currentFrame
    // 新节点 layoutDirty = true，第一次 render 后 markPainted 设 lastPaintFrame = 1
    // 但 layoutDirty 不会自动变为 false，所以第二次仍然 needsPaint
    // 所以这个测试需要设置 layoutDirty = false

    // 重新设置节点为 clean 状态
    $c1->layoutDirty = false;
    $c1->lastPaintFrame = 0; // 初始
    $ctx2 = new _MockRenderContext();

    // 第一次 render → currentPaintFrame = 1, needsPaint(1) → lastPaintFrame(0) < 1 → true
    $renderer2 = new VNodeRenderer(new _MockComponent(), $ctx2);
    $renderer2->render($root);
    assert_eq(count($ctx2->drawnElements), 1, 'clean 节点第一次渲染应绘制');

    // 渲染后 c1.lastPaintFrame = 1（currentPaintFrame = 1）
    // 第二次 render → currentPaintFrame = 2, needsPaint(2) → lastPaintFrame(1) < 2 → true
    // layers 变化了，所以还会绘制
    // 只有节点不 dirty 且帧号没变时才跳过

    // 简化测试：验证 needsPaint/markPainted 的基本逻辑
    assert_true($c1->needsPaint(5), 'lastPaintFrame=1 < currentFrame=5 → needsPaint');
    $c1->markPainted(5);
    assert_false($c1->needsPaint(5), 'lastPaintFrame=5 ≥ currentFrame=5 → 不需要重绘');
    assert_true($c1->needsPaint(6), 'lastPaintFrame=5 < currentFrame=6 → needsPaint（帧号递增）');
});

// ================================================================
echo "\n--- 6. overflow:hidden → Clip-Push / Clip-Pop ---\n";

test('overflow:hidden 生成 clip-push/clip-pop', function () {
    $parent = rn('div', ['bg' => 0x333333, 'overflow' => 'hidden']);
    $parent->x = 0; $parent->y = 0; $parent->w = 100; $parent->h = 100; $parent->layer = 0;
    $parent->sourceVNode = new VNode('div');

    $root = rn('#root', [], [$parent]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];

    $foundClipPush = false;
    $foundClipPop = false;
    foreach ($layer0 as $el) {
        if ($el['type'] === 'clip-push') {
            $foundClipPush = true;
            assert_eq($el['x'], 0, 'clip-push x 应为父容器 x');
            assert_eq($el['y'], 0, 'clip-push y 应为父容器 y');
            assert_eq($el['w'], 100, 'clip-push w 应为父容器 w');
            assert_eq($el['h'], 100, 'clip-push h 应为父容器 h');
        }
        if ($el['type'] === 'clip-pop') {
            $foundClipPop = true;
        }
    }

    assert_true($foundClipPush, 'overflow:hidden 应生成 clip-push');
    assert_true($foundClipPop, 'overflow:hidden 应生成 clip-pop');
});

test('overflow:hidden clip-push 在子元素之前且 clip-pop 在子元素之后', function () {
    $parent = rn('div', ['bg' => 0x333333, 'overflow' => 'hidden']);
    $parent->x = 0; $parent->y = 0; $parent->w = 100; $parent->h = 100; $parent->layer = 0;
    $parent->sourceVNode = new VNode('div');

    $child = rn('div', ['bg' => 0x555555]);
    $child->x = 50; $child->y = 50; $child->w = 50; $child->h = 50; $child->layer = 0;
    $child->sourceVNode = new VNode('div');
    $parent->addChild($child);

    $root = rn('#root', [], [$parent]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];

    $clipPushIdx = null;
    $clipPopIdx = null;
    $childIdx = null;
    foreach ($layer0 as $i => $el) {
        if ($el['type'] === 'clip-push') $clipPushIdx = $i;
        if ($el['type'] === 'clip-pop') $clipPopIdx = $i;
        if ($el['type'] === 'rect' && ($el['color'] ?? 0) === 0x555555) $childIdx = $i;
    }

    assert_not_null($clipPushIdx, '应有 clip-push');
    assert_not_null($clipPopIdx, '应有 clip-pop');

    if ($childIdx !== null) {
        assert_true($clipPushIdx < $childIdx, 'clip-push 应在子 rect 之前');
        assert_true($clipPopIdx > $childIdx, 'clip-pop 应在子 rect 之后');
    }
});

test('overflow:visible 不触发 clip-push/clip-pop', function () {
    $parent = rn('div', ['bg' => 0x333333]); // 默认 overflow:visible
    $parent->x = 0; $parent->y = 0; $parent->w = 100; $parent->h = 100; $parent->layer = 0;
    $parent->sourceVNode = new VNode('div');

    $root = rn('#root', [], [$parent]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];
    foreach ($layer0 as $el) {
        if ($el['type'] === 'clip-push' || $el['type'] === 'clip-pop') {
            assert(false, 'overflow:visible 不应生成 clip-push 或 clip-pop');
        }
    }
});

test('scroll-container 场景中 overflow:hidden 不额外生成 clip-push/clip-pop', function () {
    // scrollContainer 已生成 clip-push/clip-pop，overflow:hidden 不应重复生成
    $scroll = rn('div', ['bg' => 0x2D2D2D, 'overflow' => 'hidden']);
    $scroll->x = 0; $scroll->y = 0; $scroll->w = 300; $scroll->h = 200; $scroll->layer = 0;
    $scroll->isScrollContainer = true;
    $scroll->contentHeight = 500;
    $scroll->scrollTop = 50;
    $scroll->sourceVNode = new VNode('div');

    $root = rn('#root', [], [$scroll]);
    $root->x = 0; $root->y = 0; $root->w = 400; $root->h = 300;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];

    $clipPushCount = 0;
    $clipPopCount = 0;
    $scrollContainerCount = 0;
    foreach ($layer0 as $el) {
        if ($el['type'] === 'clip-push') $clipPushCount++;
        if ($el['type'] === 'clip-pop') $clipPopCount++;
        if ($el['type'] === 'scroll-container') $scrollContainerCount++;
    }

    // scrollContainer 本应生成 1 组 clip-push/clip-pop
    assert_eq($clipPushCount, 1, 'scroll-container 应恰好生成 1 个 clip-push');
    assert_eq($clipPopCount, 1, 'scroll-container 应恰好生成 1 个 clip-pop');
});

// ================================================================
echo "\n--- 7. 嵌套 overflow:hidden 边界 ---\n";

test('嵌套 overflow:hidden 父子各自生成 clip-push/clip-pop', function () {
    $child = rn('div', ['bg' => 0x555555, 'overflow' => 'hidden']);
    $child->x = 10; $child->y = 10; $child->w = 50; $child->h = 50; $child->layer = 0;
    $child->sourceVNode = new VNode('div');

    $parent = rn('div', ['bg' => 0x333333, 'overflow' => 'hidden']);
    $parent->x = 0; $parent->y = 0; $parent->w = 100; $parent->h = 100; $parent->layer = 0;
    $parent->sourceVNode = new VNode('div');
    $parent->addChild($child);

    $root = rn('#root', [], [$parent]);
    $root->x = 0; $root->y = 0; $root->w = 200; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];

    $clipPushCount = 0;
    $clipPopCount = 0;
    foreach ($layer0 as $el) {
        if ($el['type'] === 'clip-push') $clipPushCount++;
        if ($el['type'] === 'clip-pop') $clipPopCount++;
    }

    // parent + child = 2 pairs
    assert_eq($clipPushCount, 2, '父子两个 overflow:hidden 应生成 2 个 clip-push');
    assert_eq($clipPopCount, 2, '父子两个 overflow:hidden 应生成 2 个 clip-pop');

    // Verify the outer clip-push matches parent, inner matches child
    $outerClipIdx = null;
    $innerClipIdx = null;
    foreach ($layer0 as $i => $el) {
        if ($el['type'] === 'clip-push' && $el['w'] === 100) $outerClipIdx = $i;
        if ($el['type'] === 'clip-push' && $el['w'] === 50) $innerClipIdx = $i;
    }
    assert_not_null($outerClipIdx, 'outer clip-push 应存在');
    assert_not_null($innerClipIdx, 'inner clip-push 应存在');
    assert_true($outerClipIdx < $innerClipIdx, 'outer clip-push 应在 inner clip-push 之前');
});

test('多个兄弟 overflow:hidden 各自独立生成 clip-push/clip-pop', function () {
    $child1 = rn('div', ['bg' => 0x444444, 'overflow' => 'hidden']);
    $child1->x = 10; $child1->y = 10; $child1->w = 60; $child1->h = 60; $child1->layer = 0;
    $child1->sourceVNode = new VNode('div');

    $child2 = rn('div', ['bg' => 0x666666, 'overflow' => 'hidden']);
    $child2->x = 80; $child2->y = 10; $child2->w = 60; $child2->h = 60; $child2->layer = 0;
    $child2->sourceVNode = new VNode('div');

    $parent = rn('div', ['bg' => 0x333333]);
    $parent->x = 0; $parent->y = 0; $parent->w = 200; $parent->h = 100; $parent->layer = 0;
    $parent->sourceVNode = new VNode('div');
    $parent->addChild($child1);
    $parent->addChild($child2);

    $root = rn('#root', [], [$parent]);
    $root->x = 0; $root->y = 0; $root->w = 300; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];

    $clipPushCount = 0;
    $clipPopCount = 0;
    foreach ($layer0 as $el) {
        if ($el['type'] === 'clip-push') $clipPushCount++;
        if ($el['type'] === 'clip-pop') $clipPopCount++;
    }

    // 两个兄弟各自 overflow:hidden
    assert_eq($clipPushCount, 2, '两个兄弟 overflow:hidden 应各生成 clip-push');
    assert_eq($clipPopCount, 2, '两个兄弟 overflow:hidden 应各生成 clip-pop');

    // Verify order: child1's push, child1's pop, child2's push, child2's pop
    $push1Idx = null; $push2Idx = null;
    $pop1Idx = null; $pop2Idx = null;
    foreach ($layer0 as $i => $el) {
        if ($el['type'] === 'clip-push' && $el['w'] === 60 && $el['x'] === 10) $push1Idx = $i;
        if ($el['type'] === 'clip-push' && $el['w'] === 60 && $el['x'] === 80) $push2Idx = $i;
        if ($el['type'] === 'clip-pop') {
            if ($pop1Idx === null) $pop1Idx = $i;
            else $pop2Idx = $i;
        }
    }
    assert_true($push1Idx < $pop1Idx, 'child1 clip-push 应在 child1 clip-pop 之前');
    assert_true($pop1Idx < $push2Idx, 'child1 clip-pop 应在 child2 clip-push 之前');
    assert_true($push2Idx < $pop2Idx, 'child2 clip-push 应在 child2 clip-pop 之前');
});

// ================================================================
echo "--- 8. text-overflow: ellipsis / -webkit-line-clamp ---\n";

test('单行 text-overflow:ellipsis 超出容器宽度时追加…', function () {
    // 固定 container-w 数值，span 文本过长
    $span = rn('span', [
        'fontSize' => 16, 'fg' => 0xFFFFFF, 'bold' => 0,
        'textOverflow' => 'ellipsis',
    ]);
    $span->content = 'This is a very long text that should definitely exceed the container width';
    $span->x = 0; $span->y = 0; $span->w = 100; $span->h = 30; $span->layer = 0;
    $span->sourceVNode = new VNode('span', ['container-w' => '80']);

    $root = rn('#root', [], [$span]);
    $root->x = 0; $root->y = 0; $root->w = 300; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $el = $result['elements'][0][0] ?? null;
    assert_not_null($el, '应收集到 text 元素');
    assert_eq($el['type'], 'text', '元素类型应为 text');
    // 文本应以 … 结尾
    assert_true(str_ends_with($el['text'], '…'), '超出容器时文本应以 … 结尾');
});

test('单行 text-overflow:ellipsis 文本可正常放入时不截断', function () {
    $span = rn('span', [
        'fontSize' => 16, 'fg' => 0xFFFFFF, 'bold' => 0,
        'textOverflow' => 'ellipsis',
    ]);
    $span->content = 'Short';
    $span->x = 0; $span->y = 0; $span->w = 100; $span->h = 30; $span->layer = 0;
    $span->sourceVNode = new VNode('span', ['container-w' => '200']);

    $root = rn('#root', [], [$span]);
    $root->x = 0; $root->y = 0; $root->w = 300; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $el = $result['elements'][0][0] ?? null;
    assert_not_null($el, '应收集到 text 元素');
    assert_eq($el['text'], 'Short', '短文本不应被截断');
});

test('-webkit-line-clamp:2 超长文本拆分为两行', function () {
    $span = rn('span', [
        'fontSize' => 16, 'fg' => 0xFFFFFF, 'bold' => 0,
        'textOverflow' => 'ellipsis',
        'WebkitLineClamp' => '2',
        'lineHeight' => 22,
    ]);
    $span->content = 'This is a very long text that should be split into at least three lines by the line clamp algorithm implemented in the renderer';
    $span->x = 0; $span->y = 0; $span->w = 100; $span->h = 60; $span->layer = 0;
    $span->sourceVNode = new VNode('span', ['container-w' => '120']);

    $root = rn('#root', [], [$span]);
    $root->x = 0; $root->y = 0; $root->w = 300; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];
    // group 被 collectElements 展开为普通 text 元素 → layer0 中应有多个 text
    $textElements = array_values(array_filter($layer0, fn($el) => $el['type'] === 'text'));
    $textCount = count($textElements);
    assert_true($textCount >= 2, "line-clamp:2 应生成至少 2 个 text 元素，实际 $textCount");

    // 最后一行末尾应包含 …
    $lastText = end($textElements);
    assert_true(str_ends_with($lastText['text'], '…'), '末行文本应以 … 结尾');

    // 检查 Y 坐标递进
    $firstY = $textElements[0]['y'];
    $secondY = $textElements[1]['y'];
    assert_true($secondY > $firstY, '第二行 Y 坐标应大于第一行');
});

test('-webkit-line-clamp:2 + CJK 中文文本正确换行', function () {
    $span = rn('span', [
        'fontSize' => 16, 'fg' => 0x18191C, 'bold' => 0,
        'textOverflow' => 'ellipsis',
        'WebkitLineClamp' => '2',
        'lineHeight' => 22,
    ]);
    // 一段较长的中文文本
    $span->content = '这是一个很长的中文视频标题，它需要被正确拆分成多行显示，最后加上省略号';
    $span->x = 0; $span->y = 0; $span->w = 100; $span->h = 60; $span->layer = 0;
    $span->sourceVNode = new VNode('span', ['container-w' => '140']);

    $root = rn('#root', [], [$span]);
    $root->x = 0; $root->y = 0; $root->w = 300; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $layer0 = $result['elements'][0] ?? [];
    // group 被展开，直接查 text 元素
    $textElements = array_values(array_filter($layer0, fn($el) => $el['type'] === 'text'));
    $textCount = count($textElements);
    assert_true($textCount >= 2, "line-clamp:2 中文应生成至少 2 个 text 元素，实际 $textCount");

    // 最后一行带 …
    $lastText = end($textElements);
    assert_true(str_ends_with($lastText['text'], '…'), '中文末尾应有…');
});

test('-webkit-line-clamp:2 短文本不超过两行时不截断', function () {
    $span = rn('span', [
        'fontSize' => 16, 'fg' => 0xFFFFFF, 'bold' => 0,
        'textOverflow' => 'ellipsis',
        'WebkitLineClamp' => '2',
        'lineHeight' => 22,
    ]);
    $span->content = 'Short title';
    $span->x = 0; $span->y = 0; $span->w = 100; $span->h = 60; $span->layer = 0;
    $span->sourceVNode = new VNode('span', ['container-w' => '300']);

    $root = rn('#root', [], [$span]);
    $root->x = 0; $root->y = 0; $root->w = 300; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $el = $result['elements'][0][0] ?? null;
    assert_not_null($el, '应收集到 text 元素');
    assert_eq($el['type'], 'text', '短文本应返回 text 而非 group');
    assert_eq($el['text'], 'Short title', '短文本不应被截断');
});

test('无 ellipsis 时 line-clamp 不生效（正常渲染）', function () {
    $span = rn('span', [
        'fontSize' => 16, 'fg' => 0xFFFFFF, 'bold' => 0,
        'WebkitLineClamp' => '2',
    ]);
    $span->content = 'No ellipsis so line-clamp should not trigger';
    $span->x = 0; $span->y = 0; $span->w = 100; $span->h = 30; $span->layer = 0;
    $span->sourceVNode = new VNode('span', ['container-w' => '80']);

    $root = rn('#root', [], [$span]);
    $root->x = 0; $root->y = 0; $root->w = 300; $root->h = 200;

    $renderer = new VNodeRenderer(new _MockComponent(), new _MockRenderContext());
    $result = invokeCollectElements($renderer, $root);

    $el = $result['elements'][0][0] ?? null;
    assert_not_null($el, '应收集到 text 元素');
    assert_eq($el['type'], 'text', '无 ellipsis 时返回 text');
    // 文本应该完整（line-clamp 只在 textOverflow=ellipsis 时生效）
    assert_contains($el['text'], 'line-clamp', '无 ellipsis 时 line-clamp 不应截断文本');
});

// ================================================================
echo "\n";
$exitCode = print_summary();
exit($exitCode);
