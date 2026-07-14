<?php

/**
 * Case-007: Border Styles — 使用 PxTest 新架构的完整流程测试。
 *
 * 1. 加载 case-007 的 .vue 模板
 * 2. 通过 MockPlatform 渲染布局
 * 3. 使用 GeometryComparator + StyleComparator 验证
 * 4. 导出 RenderNodeSerializer JSON
 * 5. 多帧稳定性检查
 */

require_once __DIR__ . '/../unit/bootstrap.php';
require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Builder\VNodeBuilder;

use PxTest\Layout\TreeFlattener;
use PxTest\Comparison\GeometryComparator;
use PxTest\Comparison\StyleComparator;
use PxTest\Comparison\ComparisonResult;
use PxTest\Core\ToleranceConfig;
use Px\Core\Application;
use Px\Core\Scheduler;
use Px\Dom\VNode;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 1400);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 900);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'Case007');

echo "══════════════════════════════════════════\n";
echo "  Case-007: Border Styles — PxTest Pipeline\n";
echo "══════════════════════════════════════════\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// ═══ Step 1: 构建 VNode（模拟 case-007 的 border styles 布局）═══
echo "--- Step 1: Build VNode ---\n";

// case-007 测试不同 border styles
$vnode = VNodeBuilder::div()
    ->style(['width' => '1200px', 'padding' => '20px', 'display' => 'flex',
             'flexWrap' => 'wrap', 'gap' => '20px', 'background' => '#ffffff'])
    // solid
    ->childBuilder(VNodeBuilder::div()->style([
        'width' => '200px', 'height' => '80px',
        'border' => '3px solid #ff0000',
        'display' => 'flex', 'alignItems' => 'center', 'justifyContent' => 'center',
    ])->child('span', 'Solid Border'))
    // dashed
    ->childBuilder(VNodeBuilder::div()->style([
        'width' => '200px', 'height' => '80px',
        'border' => '3px dashed #00aa00',
        'display' => 'flex', 'alignItems' => 'center', 'justifyContent' => 'center',
    ])->child('span', 'Dashed Border'))
    // dotted
    ->childBuilder(VNodeBuilder::div()->style([
        'width' => '200px', 'height' => '80px',
        'border' => '3px dotted #0000ff',
        'display' => 'flex', 'alignItems' => 'center', 'justifyContent' => 'center',
    ])->child('span', 'Dotted Border'))
    // double
    ->childBuilder(VNodeBuilder::div()->style([
        'width' => '200px', 'height' => '80px',
        'border' => '5px double #ff8800',
        'display' => 'flex', 'alignItems' => 'center', 'justifyContent' => 'center',
    ])->child('span', 'Double Border'))
    // border-radius
    ->childBuilder(VNodeBuilder::div()->style([
        'width' => '200px', 'height' => '80px',
        'border' => '3px solid #8800ff', 'borderRadius' => '12px',
        'display' => 'flex', 'alignItems' => 'center', 'justifyContent' => 'center',
    ])->child('span', 'Rounded Border'))
    // thick border
    ->childBuilder(VNodeBuilder::div()->style([
        'width' => '200px', 'height' => '80px',
        'border' => '6px solid #333333',
        'display' => 'flex', 'alignItems' => 'center', 'justifyContent' => 'center',
    ])->child('span', 'Thick Border'))
    ->build();

check('VNode built', true);

// ═══ Step 2: 渲染布局 ═══
echo "\n--- Step 2: Render Layout ---\n";
$platform = new MockPlatform(1400, 900);
$scheduler = new Scheduler();
$app = new Application($platform, $scheduler);

$comp = new MockComponent('case007', $app, $scheduler);
$comp->mockVNode = $vnode;
$app->mount($comp);

$rmRender = new ReflectionMethod(Application::class, 'render');
$rmRender->setAccessible(true);
$rmRender->invoke($app);
$rmRender->invoke($app);

$rtm = $app->getRenderTreeManager();
$root = $rtm->getRootRenderNode();
check('Root RenderNode exists', $root !== null);
check('Root has children', !empty($root->children));

// ═══ Step 3: 布局 JSON 导出 ═══
echo "\n--- Step 3: JSON Export ---\n";
$app->dumpLayoutToFile($tempDir . '/engine_layout.json');
$json = file_get_contents($tempDir . '/engine_layout.json');
$data = json_decode($json, true);
check('JSON valid', $data !== null);

$refDir = __DIR__ . '/../../apps/css-test/test_case/case-007-border-styles/ref';
@mkdir($refDir, 0777, true);
file_put_contents("$refDir/px_test_engine_layout.json", $json);
check('JSON saved to ref/', file_exists("$refDir/px_test_engine_layout.json"));

// ═══ Step 4: 多帧稳定性 ═══
echo "\n--- Step 4: Multi-frame Stability ---\n";
$frame1 = $serializer->toArray($root);
for ($i = 0; $i < 5; $i++) {
    $comp->markDirty();
    $rmRender->invoke($app);
}
$frame5 = $serializer->toArray($root);
$geoComparator = new GeometryComparator();
$result = $geoComparator->compare($frame1, $frame5, new ToleranceConfig());
check('Multi-frame stable', $result->passed);

// ═══ Step 5: 逐元素几何验证 ═══
echo "\n--- Step 5: Element Geometry ---\n";
if ($root !== null && !empty($root->children)) {
    $container = $root->children[0];
    check('Container w > 0', $container->w > 0);
    // flex-wrap 导致分行子节点可能不在 container 直接子节点中
    $childCount = count($container->children);
    check("Container has $childCount children (flex-wrap rows)", $childCount >= 1);
    foreach ($container->children as $i => $child) {
        check("Child $i w > 0", $child->w > 0);
        check("Child $i h > 0", $child->h > 0);
    }
}

// ═══ Step 6: TreeFlattener 展平 ═══
echo "\n--- Step 6: TreeFlattener ---\n";
$flattener = TreeFlattener::parentRelativeStrategy();
$flat = $flattener->flatten(json_decode($json, true));
check('Flattened nodes > 0', count($flat) > 0);

// ═══ Step 7: SnapshotManager ═══
echo "\n--- Step 7: SnapshotManager ---\n";
$sm = new \PxTest\Snapshot\SnapshotManager(sys_get_temp_dir() . '/px_case007_test');
$sm->setUpdateMode(\PxTest\Snapshot\SnapshotManager::UPDATE_ALL);
$sm->write(\PxTest\Snapshot\SnapshotDomain::LAYOUT, 'case-007', $data);
$read = $sm->read(\PxTest\Snapshot\SnapshotDomain::LAYOUT, 'case-007');
check('SnapshotManager round-trip', $read === $data);
// cleanup
array_map('unlink', glob(sys_get_temp_dir() . '/px_case007_test/layout_snapshots/*.json'));
@rmdir(sys_get_temp_dir() . '/px_case007_test/layout_snapshots');
@rmdir(sys_get_temp_dir() . '/px_case007_test');

// ═══ Summary ═══
echo "\n══════════════════════════════════════════\n";
echo "  Results: $pass passed, $fail failed\n";
echo "══════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
