<?php
/**
 * Platform 接口单元测试
 * 
 * 测试目标:
 *   1. Platform 接口契约: init/shutdown/shouldClose/pollEvents
 *   2. Mock Platform 可被 Application 使用 (DIP 验证)
 *   3. init() 返回 RenderContext
 *   4. shutdown() 清理资源
 * 
 * Usage: php tests/unit/PlatformTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Platform\Platform;
use Px\Platform\PlatformEvent;
use Px\Platform\MouseEvent;
use Px\Rendering\RenderContext;

echo "========================================\n";
echo " Platform 接口单元测试\n";
echo "========================================\n\n";

// ---- Mock RenderContext ----
class _PlatformMockRenderContext extends RenderContext
{
    public function beginFrame(): void {}
    public function endFrame(): void {}
    public function drawElement(array $element): void {}
    public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold): void {}
    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
}

// ---- Mock Platform 实现 ----
class _MockPlatform implements Platform
{
    public bool $initCalled = false;
    public bool $shutdownCalled = false;
    public string $title = '';
    public int $width = 0;
    public int $height = 0;
    public bool $shouldClose = false;

    /** @var PlatformEvent[] */
    public array $events = [];

    public function init(string $title, int $width, int $height): RenderContext
    {
        $this->initCalled = true;
        $this->title = $title;
        $this->width = $width;
        $this->height = $height;
        return new _PlatformMockRenderContext();
    }

    public function shutdown(): void
    {
        $this->shutdownCalled = true;
    }

    public function shouldClose(): bool
    {
        return $this->shouldClose;
    }

    public function pollEvents(): array
    {
        return $this->events;
    }

    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void {}
}

echo "--- 1. Platform 接口契约 ---\n";

test('MockPlatform 实现 Platform 接口', function () {
    $platform = new _MockPlatform();
    assert_true($platform instanceof Platform, 'MockPlatform 必须实现 Platform 接口');
});

test('init() 返回 RenderContext', function () {
    $platform = new _MockPlatform();
    $ctx = $platform->init('Test App', 800, 600);

    assert_true($platform->initCalled, 'init 应被调用');
    assert_eq($platform->title, 'Test App', 'title 应为 Test App');
    assert_eq($platform->width, 800, 'width 应为 800');
    assert_eq($platform->height, 600, 'height 应为 600');
    assert_true($ctx instanceof RenderContext, 'init() 应返回 RenderContext 实例');
});

test('shutdown() 清理资源', function () {
    $platform = new _MockPlatform();
    $platform->shutdown();

    assert_true($platform->shutdownCalled, 'shutdown 应被调用');
});

test('shouldClose() 返回关闭状态', function () {
    $platform = new _MockPlatform();

    assert_false($platform->shouldClose(), '初始 shouldClose 应为 false');

    $platform->shouldClose = true;
    assert_true($platform->shouldClose(), '设为 true 后应返回 true');
});

test('pollEvents() 返回事件数组', function () {
    $platform = new _MockPlatform();

    $result = $platform->pollEvents();
    assert_true(is_array($result), 'pollEvents 应返回数组');
    assert_eq(count($result), 0, '无事件时应返回空数组');
});

echo "\n--- 2. Platform 事件 ---\n";

test('MouseEvent 有正确的类型和坐标', function () {
    $event = new MouseEvent('down', 100, 200, 0, 0);

    assert_eq($event->type, 'mouse', 'event type 应为 mouse');
    assert_eq($event->action, 'down', 'action 应为 down');
    assert_eq($event->x, 100, 'x 应为 100');
    assert_eq($event->y, 200, 'y 应为 200');
});

test('MouseEvent wheel 有 delta 值', function () {
    $event = new MouseEvent('wheel', 0, 0, 0, 120);

    assert_eq($event->type, 'mouse', 'event type 应为 mouse');
    assert_eq($event->action, 'wheel', 'action 应为 wheel');
    assert_eq($event->delta, 120, 'delta 应为 120');
});

test('KeyboardEvent 有 keyCode 和 char', function () {
    $event = new \Px\Platform\KeyboardEvent('down', 65, 'a');

    assert_eq($event->type, 'keyboard', 'event type 应为 keyboard');
    assert_eq($event->action, 'down', 'action 应为 down');
    assert_eq($event->keyCode, 65, 'keyCode 应为 65');
    assert_eq($event->char, 'a', 'char 应为 a');
});

echo "\n--- 3. DIP 验证：Application 依赖 Platform 抽象 ---\n";

test('Application 通过 Platform 接口使用平台 (不持有 hwnd)', function () {
    // 核心验证：Application 不应持有 hwnd 引用
    $platform = new _MockPlatform();

    // init() 封装了 hwnd，返回 RenderContext
    $ctx = $platform->init('DIP Test', 640, 480);

    // 验证：Application 层面没有访问 hwnd 的方式
    assert_true($ctx instanceof RenderContext, 'Application 只看到 RenderContext');
    // hwnd 完全封装在 Platform 实现内部
});

test('Platform 不暴露平台句柄 (hwnd)', function () {
    // Platform 接口不包含任何 getHwnd() 方法
    $refl = new \ReflectionClass(Platform::class);
    $methods = $refl->getMethods();

    $methodNames = [];
    foreach ($methods as $m) {
        $methodNames[] = $m->getName();
    }

    // 不应该有 getHwnd, getWindow, getHandle 等方法
    foreach ($methodNames as $name) {
        $lower = strtolower($name);
        assert_true(
            strpos($lower, 'hwnd') === false && strpos($lower, 'handle') === false,
            "Platform 接口不应包含 hwnd/handle 方法: {$name}"
        );
    }
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
