<?php

/**
 * PxTest 基础设施验证测试。
 *
 * 验证 Mock 平台、Builder、ToleranceConfig、SnapshotManager 的基础功能。
 * 同时作为新开发者使用 PxTest 的参考示例。
 */

// 先加载框架引导（框架类 + polyfills）
require_once __DIR__ . '/../bootstrap.php';

// 加载 PxTest 测试基础设施
require_once __DIR__ . '/../../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Mock\EventSimulator;
use PxTest\Builder\VNodeBuilder;
use PxTest\Builder\RenderNodeBuilder;
use PxTest\Core\ToleranceConfig;
use PxTest\Core\TestResult;
use PxTest\Snapshot\SnapshotManager;
use PxTest\Snapshot\SnapshotDomain;

echo "========================================\n";
echo "  PxTest Infrastructure Verification\n";
echo "========================================\n\n";

$pass = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $pass, $fail;
    if ($condition) {
        echo "  [PASS] $label\n";
        $pass++;
    } else {
        echo "  [FAIL] $label\n";
        $fail++;
    }
}

// —— 1. MockPlatform + MockRenderContext ——
echo "--- Mock Platform ---\n";
$platform = new MockPlatform(800, 600);
$rc = $platform->renderContext;

// 验证初始化（P1.3 Surface 解耦：init 不再返回上下文，字段直接可用）
$platform->init('Test', 800, 600);
check('Platform exposes MockRenderContext field',
    $rc instanceof \PxTest\Mock\MockRenderContext);

// 验证帧重置
$rc->drawText(10, 20, 'Hello', 14, 0x000000, 0, 'Arial');
$rc->drawText(30, 40, 'World', 14, 0xFF0000, 1, 'Arial');
check('MockRenderContext hasDrawnText',
    $rc->hasDrawnText('Hello') && $rc->hasDrawnText('World') && !$rc->hasDrawnText('Nope'));
check('MockRenderContext text count = 2', count($rc->texts) === 2);

$rc->beginFrame();
check('MockRenderContext reset clears texts', count($rc->texts) === 0);

// 验证事件注入
EventSimulator::injectClick($platform, 100, 200);
$events = $platform->pollEvents();
check('MockPlatform inject + poll event', count($events) === 1);

// 验证光标
$platform->setCursor('pointer');
check('MockPlatform cursor', $platform->getCursor() === 'pointer');


// —— 2. ToleranceConfig ——
echo "\n--- ToleranceConfig ---\n";
$tc = new ToleranceConfig();
check('Default geometry tolerance = 1',
    $tc->forCategory('geometry') === 1);
check('fontSize tolerance = 0 (strict)',
    $tc->forProperty('fontSize') === 0);
check('lineHeight tolerance = 2',
    $tc->forProperty('lineHeight') === 2);
check('Unknown property uses default',
    $tc->forProperty('unknownProp') === 1);

$strict = ToleranceConfig::strict();
check('Strict mode default = 0',
    $strict->defaultTolerance === 0);

$custom = $tc->withProperty('x', 5);
check('Custom property tolerance',
    $custom->forProperty('x') === 5 && $tc->forProperty('x') === 0);


// —— 3. VNodeBuilder ——
echo "\n--- VNodeBuilder ---\n";
$vnode = VNodeBuilder::div()
    ->style(['display' => 'flex', 'width' => '100px'])
    ->class('container')
    ->onClick('handleClick')
    ->child('span', 'Hello World', ['class' => 'text-bold'])
    ->build();

check('VNodeBuilder type = div', $vnode->type === 'div');
check('VNodeBuilder style contains display:flex',
    str_contains((string)$vnode->getProp('style', ''), 'display:flex'));
check('VNodeBuilder class = container',
    $vnode->getClass() === 'container');

$rootVNode = VNodeBuilder::root()
    ->childBuilder(VNodeBuilder::div()->style(['display' => 'block'])->childText('inner'))
    ->build();
check('Root VNode type = #root', $rootVNode->type === '#root');


// —— 4. RenderNodeBuilder ——
echo "\n--- RenderNodeBuilder ---\n";
$rn = RenderNodeBuilder::ofType('div', 'test content')
    ->withLayoutResult(100, 200, 300, 50)
    ->withStyle(['bg' => 0xFFFFFF, 'fontSize' => 14])
    ->withKey('item-1')
    ->build();

check('RenderNodeBuilder x/y', $rn->x === 100 && $rn->y === 200);
check('RenderNodeBuilder w/h', $rn->w === 300 && $rn->h === 50);
check('RenderNodeBuilder layout clean', !$rn->layoutDirty);
check('RenderNodeBuilder style bg', ($rn->style['bg'] ?? null) === 0xFFFFFF);
check('RenderNodeBuilder key', $rn->key === 'item-1');


// —— 5. SnapshotManager ——
echo "\n--- SnapshotManager ---\n";
$tmpDir = sys_get_temp_dir() . '/px_test_snapshots_' . getmypid();
$sm = new SnapshotManager($tmpDir);
$sm->setUpdateMode(SnapshotManager::UPDATE_ALL);

$testData = ['nodes' => [['type' => 'div', 'x' => 0, 'y' => 0]], 'timestamp' => '2026-06-17'];
$sm->write(SnapshotDomain::RENDER_TREE, 'test_case', $testData);

$read = $sm->read(SnapshotDomain::RENDER_TREE, 'test_case');
check('SnapshotManager write + read roundtrip', $read === $testData);

$diff = $sm->diff(SnapshotDomain::RENDER_TREE, 'test_case', $testData);
check('SnapshotManager diff passes on identical data', $diff->passed);

$diff2 = $sm->diff(SnapshotDomain::RENDER_TREE, 'test_case', ['nodes' => []]);
check('SnapshotManager diff fails on different data', !$diff2->passed);

// 清理临时文件
array_map('unlink', glob("$tmpDir/render_tree_snapshots/*.json"));
@rmdir("$tmpDir/render_tree_snapshots");
@rmdir($tmpDir);


// —— 6. TestResult ——
echo "\n--- TestResult ---\n";
$r = TestResult::pass('test1', 1.5);
check('TestResult pass isPassed', $r->isPassed());

$r2 = TestResult::fail('test2', 'reason');
check('TestResult fail isFailed', $r2->isFailed());

$r3 = TestResult::skip('test3', 'not applicable');
check('TestResult skip status', $r3->status === TestResult::STATUS_SKIP);


// —— 7. EventSimulator ——
echo "\n--- EventSimulator ---\n";
$click = EventSimulator::mouseClick(50, 75);
check('EventSimulator click coordinates',
    $click->x === 50 && $click->y === 75);

$wheel = EventSimulator::mouseWheel(10, 10, -120);
check('EventSimulator wheel delta',
    $wheel->delta === -120);


// —— Summary ——
echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";

exit($fail > 0 ? 1 : 0);
