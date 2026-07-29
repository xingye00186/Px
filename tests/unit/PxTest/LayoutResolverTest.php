<?php

/**
 * LayoutResolver 单元测试 — 使用 Builder + MockPlatform 验证各布局策略。
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 800);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 600);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'LayoutTest');

echo "========================================\n";
echo "  LayoutResolver Unit Test (PxTest)\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

function renderAndGetRoot(Application $app, VNodeBuilder $builder): ?object
{
    $comp = new MockComponent('layout_test', $app, new Scheduler());
    $comp->mockVNode = $builder->build();
    $app->mount($comp);
    $rm = new ReflectionMethod(Application::class, 'render');
    $rm->setAccessible(true);
    $rm->invoke($app);
    $rm->invoke($app);
    return $app->getRenderTreeManager()->getRootRenderNode();
}

$app = new Application(new MockPlatform(800, 600), new Scheduler());

// ═══ 1. Block 布局 ═══
echo "--- 1. Block Layout ---\n";
$root = renderAndGetRoot($app, VNodeBuilder::div()
    ->style(['width' => '200px', 'height' => '100px'])
    ->childText('Block Content'));
check('Block root exists', $root !== null);
check('Block has children', !empty($root->children));
$child = $root->children[0] ?? null;
check('Block child x >= 0', $child !== null && $child->x >= 0);
// C0.2 退役：RenderNode->w 几何回写在 MockPlatform 无完整帧环下不覆盖
//（真实几何由 css-test/css-standards 覆盖）。
echo "  [SKIP] Block child w > 0（mock 环无几何回写）\n";
if (false) {
check('Block child w > 0', $child !== null && $child->w > 0);
}


// ═══ 2. Flex 布局 ═══
echo "\n--- 2. Flex Layout ---\n";
$app2 = new Application(new MockPlatform(800, 600), new Scheduler());
$root2 = renderAndGetRoot($app2, VNodeBuilder::div()
    ->style(['width' => '400px', 'display' => 'flex', 'flexDirection' => 'row'])
    ->childBuilder(VNodeBuilder::div()->style(['width' => '100px', 'height' => '50px']))
    ->childBuilder(VNodeBuilder::div()->style(['width' => '150px', 'height' => '50px'])));
check('Flex root exists', $root2 !== null);


// ═══ 3. Absolute 定位 ═══
echo "\n--- 3. Absolute Positioning ---\n";
$app3 = new Application(new MockPlatform(800, 600), new Scheduler());
$root3 = renderAndGetRoot($app3, VNodeBuilder::div()
    ->style(['position' => 'relative', 'width' => '300px', 'height' => '200px'])
    ->childBuilder(VNodeBuilder::div()
        ->style(['position' => 'absolute', 'top' => '10px', 'left' => '10px',
                 'width' => '50px', 'height' => '50px'])));
check('Absolute root exists', $root3 !== null);


// ═══ 4. 百分比宽度 ═══
echo "\n--- 4. Percent Width ---\n";
$app4 = new Application(new MockPlatform(800, 600), new Scheduler());
$root4 = renderAndGetRoot($app4, VNodeBuilder::div()
    ->style(['width' => '400px'])
    ->childBuilder(VNodeBuilder::div()
        ->style(['width' => '50%', 'height' => '30px'])));
check('Percent root exists', $root4 !== null);


// ═══ Summary ═══
echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
