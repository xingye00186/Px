<?php

/**
 * 样式合并集成测试 — 多 class 合并 + 渲染验证。
 *
 * 验证 ThemeProvider 类注册、多 class 合并、覆盖顺序。
 */

require_once __DIR__ . '/../unit/bootstrap.php';
require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;
use Px\Theme\ThemeProvider;
use Px\Dom\VNode;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 800);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 600);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'Test');

echo "========================================\n";
echo "  Style Merge Integration Test\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

$platform = new MockPlatform(800, 600);
$scheduler = new Scheduler();
$app = new Application($platform, $scheduler);
$rmRender = new ReflectionMethod(Application::class, 'render');
$rmRender->setAccessible(true);

// ═══ 1. 单 class 样式 ═══
echo "--- 1. Single class style ---\n";
$comp = new MockComponent('test_style', $app, $scheduler);
$comp->mockVNode = VNodeBuilder::div()
    ->class('test-box')
    ->style(['width' => '100px'])
    ->childText('Styled')
    ->build();

$app->mount($comp);
$rmRender->invoke($app);
$rmRender->invoke($app);

$rtm = $app->getRenderTreeManager();
$root = $rtm->getRootRenderNode();
check('Single class render succeeds', $root !== null);


// ═══ 2. 多 class 合并 ═══
echo "\n--- 2. Multi-class merge ---\n";
$comp2 = new MockComponent('test_multi_class', $app, $scheduler);
$comp2->mockVNode = VNodeBuilder::div()
    ->class(['box', 'primary', 'large'])
    ->style(['width' => '200px'])
    ->childText('Multi Class')
    ->build();

$app->mount($comp2);
$rmRender->invoke($app);
$rmRender->invoke($app);

$root2 = $rtm->getRootRenderNode();
check('Multi-class render succeeds', $root2 !== null);


// ═══ 3. 内联样式 + class 共存 ═══
echo "\n--- 3. Inline + class coexistence ---\n";
$comp3 = new MockComponent('test_inline_class', $app, $scheduler);
$comp3->mockVNode = VNodeBuilder::div()
    ->class('card')
    ->style(['width' => '300px', 'background' => '#ffffff', 'borderRadius' => '8px'])
    ->childText('Card Content')
    ->build();

$app->mount($comp3);
$rmRender->invoke($app);
$rmRender->invoke($app);

$root3 = $rtm->getRootRenderNode();
check('Inline+class render succeeds', $root3 !== null);


// ═══ 4. VNodeBuilder 样式数组 → 字符串 ═══
echo "\n--- 4. VNodeBuilder array style → string ---\n";
$vnode = VNodeBuilder::div()
    ->style(['display' => 'flex', 'gap' => '10px', 'padding' => '5px'])
    ->build();
$style = (string)$vnode->getProp('style', '');
check('Array style contains display:flex', str_contains($style, 'display:flex'));
check('Array style contains gap:10px', str_contains($style, 'gap:10px'));


// ═══ 5. 嵌套组件样式继承 ═══
echo "\n--- 5. Nested style inheritance ---\n";
$comp5 = new MockComponent('test_nested_style', $app, $scheduler);
$comp5->mockVNode = VNodeBuilder::div()
    ->style(['display' => 'flex', 'fontSize' => '14px'])
    ->class('container')
    ->childBuilder(
        VNodeBuilder::div()
            ->style(['width' => '50%'])
            ->childText('Child A')
    )
    ->childBuilder(
        VNodeBuilder::div()
            ->style(['width' => '50%'])
            ->childText('Child B')
    )
    ->build();

$app->mount($comp5);
$rmRender->invoke($app);
$rmRender->invoke($app);

$root5 = $rtm->getRootRenderNode();
$container = $root5->children[0] ?? null;
check('Nested style render succeeds', $container !== null);
if ($container !== null) {
    // C0.2 现代化：RenderNode->style 数组已被 typed ComputedStyle 取代，
    // fontSize 断言改读 computedStyle（探针实锤 style 数组恒空）。
    $hasFontSize = $container->computedStyle !== null
        && (int)$container->computedStyle->getFontSize() > 0;
    check('Container fontSize parsed', $hasFontSize);
}


// ═══ Summary ═══
echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
