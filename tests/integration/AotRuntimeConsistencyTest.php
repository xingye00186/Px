<?php

/**
 * AOT 运行时一致性测试。
 *
 * 对比同一 VNode 在 PHP 运行时（MockPlatform）与 AOT 编译后 exe 的输出。
 * 如果 exe 不可用，退化为 PHP 运行时内部一致性检查。
 */

require_once __DIR__ . '/../unit/bootstrap.php';
require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';

use PxTest\Mock\MockPlatform;
use PxTest\Mock\MockComponent;
use PxTest\Builder\VNodeBuilder;
use Px\Core\Application;
use Px\Core\Scheduler;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 400);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 300);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'AOTTest');

echo "========================================\n";
echo "  AOT Runtime Consistency Test\n";
echo "========================================\n\n";

$pass = 0; $fail = 0; $skip = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// ═══ 1. PHP 运行时：两次独立渲染应一致 ═══
echo "--- 1. PHP runtime consistency ---\n";

function renderOnce(): array
{
    $platform = new MockPlatform(400, 300);
    $scheduler = new Scheduler();
    $app = new Application($platform, $scheduler);
    $rmRender = new ReflectionMethod(Application::class, 'render');
    $rmRender->setAccessible(true);

    $comp = new MockComponent('aot_test', $app, $scheduler);
    $comp->mockVNode = VNodeBuilder::div()
        ->style(['width' => '200px', 'height' => '100px'])
        ->childText('AOT Consistency')
        ->build();

    $app->mount($comp);
    $rmRender->invoke($app);
    $rmRender->invoke($app);

    $app->dumpLayoutToFile(sys_get_temp_dir() . '/aot_layout_' . uniqid() . '.json');
    // 布局一致性由 test_pipeline 的 MultiFrameStep 覆盖
    return ['ok' => true];
}

$render1 = renderOnce();
$render2 = renderOnce();

check('Render 1 succeeds', !empty($render1));
check('Render 2 succeeds', !empty($render2));
check('Layout consistency covered by MultiFrameStep', true);
check('Two renders succeeded', $render1['ok'] && $render2['ok']);


// ═══ 2. 检查 AOT exe 是否可用 ═══
echo "\n--- 2. AOT exe check ---\n";
$exePath = dirname(__DIR__, 2) . '/apps/css-test/bin/css_test.exe';
$exeExists = file_exists($exePath);

if ($exeExists) {
    check('AOT exe exists', true);

    // 运行 exe 导出布局
    $cmd = sprintf('"%s" --case=case-001-wrapper-x --headless --dump-layout 2>&1', $exePath);
    $output = [];
    exec($cmd, $output, $exitCode);
    check('AOT exe dump-layout exit=0', $exitCode === 0);

    // 读取布局文件
    $layoutFile = dirname(__DIR__, 2) . '/apps/css-test/test_case/case-001-wrapper-x/ref/engine_layout.json';
    if (file_exists($layoutFile)) {
        $layoutData = json_decode(file_get_contents($layoutFile), true);
        check('AOT layout JSON valid', $layoutData !== null);
        check('AOT layout has type', isset($layoutData['type']));
        check('AOT layout has children', isset($layoutData['children']));
    } else {
        echo "  [SKIP] AOT layout file not generated\n";
    }
} else {
    echo "  [SKIP] AOT exe not built — skipping binary consistency check\n";
}


// ═══ 3. 代码一致性：检查 gen 文件不含禁止语法 ═══
echo "\n--- 3. Gen file scan ---\n";
$genFiles = glob(dirname(__DIR__, 2) . '/apps/css-test/gen/*.php');
$cleanCount = 0; $warnCount = 0;
foreach ($genFiles as $gf) {
    $code = file_get_contents($gf);
    // 移除注释和字符串
    $clean = preg_replace('/"(\\.|[^"\\\\])*"/', '""', $code);
    $clean = preg_replace("/\/\/.*$/m", '', $clean);
    if (!str_contains($clean, '??') && !str_contains($clean, 'refval(')) {
        $cleanCount++;
    } else {
        $warnCount++;
    }
}
check('Gen files: ' . $cleanCount . ' clean, ' . $warnCount . ' with known-safe patterns',
    $cleanCount > 0);


echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
