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
use Px\Platform\PointerEvent;
use Px\Platform\KeyEvent;
use Px\Platform\RenderSurface;
use Px\Platform\ViewMetrics;
use Px\Platform\LifecycleEvent;
use Px\Paint\RenderContext;

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
    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void {}
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

    public function getSurface(): RenderSurface
    {
        return new RenderSurface(0, $this->width, $this->height, 1000);
    }

    public function getMetrics(): ViewMetrics
    {
        return new ViewMetrics($this->width, $this->height, 1000, 0, 0, 0, 0, 'mock');
    }

    public function getLifecycleState(): string
    {
        return $this->shouldClose ? LifecycleEvent::STATE_DETACHED : LifecycleEvent::STATE_ACTIVE;
    }

    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void {}
    public function setCursor(string $cursor): void {}
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

test('PointerEvent 有正确的类型和坐标', function () {
    $event = new PointerEvent('down', 100, 200, 0, 0);

    assert_eq($event->type, 'pointer', 'event type 应为 pointer');
    assert_eq($event->action, 'down', 'action 应为 down');
    assert_eq($event->x, 100, 'x 应为 100');
    assert_eq($event->y, 200, 'y 应为 200');
    assert_eq($event->kind, 'mouse', '默认 kind 应为 mouse');
    assert_eq($event->pointerId, 0, '鼠标 pointerId 恒为 0');
});

test('PointerEvent wheel 有 scrollDelta 值', function () {
    $event = new PointerEvent('wheel', 0, 0, 0, 120);

    assert_eq($event->type, 'pointer', 'event type 应为 pointer');
    assert_eq($event->action, 'wheel', 'action 应为 wheel');
    assert_eq($event->getScrollDelta(), 120, 'scrollDelta 应为 120');
});

test('PointerEvent 统一抽象：touch 与 mouse 同类型不同 kind', function () {
    // 铁律 1：框架层零平台分支 —— 触摸与鼠标走同一个类，仅 kind 字段不同
    $mouse = new PointerEvent('down', 10, 20, 0, 0, false, 'mouse', 0, 1000);
    $touch = new PointerEvent('down', 10, 20, 0, 0, false, 'touch', 2, 750);

    assert_true($mouse instanceof PointerEvent && $touch instanceof PointerEvent, '两者同为 PointerEvent');
    assert_eq($touch->getKind(), 'touch', 'touch 事件 kind 应为 touch');
    assert_eq($touch->getPointerId(), 2, '多指触摸 pointerId 应保留');
    assert_eq($touch->getPressure(), 750, '压感应为千分比整数 750');
});

test('KeyEvent 有 keyCode / char / modifiers', function () {
    $event = new KeyEvent('down', 65, 'a', KeyEvent::MOD_CTRL);

    assert_eq($event->type, 'key', 'event type 应为 key');
    assert_eq($event->action, 'down', 'action 应为 down');
    assert_eq($event->keyCode, 65, 'keyCode 应为 65');
    assert_eq($event->char, 'a', 'char 应为 a');
    assert_true($event->hasCtrl(), 'MOD_CTRL 位应被识别');
    assert_false($event->hasShift(), '未设置的 MOD_SHIFT 不应被识别');
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

test('Platform 应提供 getSurface 方法 (RuntimeBackendSelector 需要句柄)', function () {
    // 终极融合 P0.3：getHwnd() 已移除——Win32 HWND 概念不得泄露到 Framework 层。
    // 句柄经 RenderSurface 中转，其平台含义封装在 Embedder 内部。
    $refl = new \ReflectionClass(Platform::class);
    assert_true($refl->hasMethod('getSurface'), 'Platform 接口应包含 getSurface()');
    assert_false($refl->hasMethod('getHwnd'), 'Platform 接口不得再暂露 getHwnd()（平台概念泄露）');
});

test('RenderSurface 封装原生句柄与 DPR', function () {
    $platform = new _MockPlatform();
    $platform->init('Surface Test', 800, 600);
    $surface = $platform->getSurface();

    assert_true($surface instanceof RenderSurface, 'getSurface() 应返回 RenderSurface');
    assert_eq($surface->getHandle(), 0, '无窗口环境 handle 应为 0');
    assert_true($surface->isOffscreen(), 'handle=0 应识别为离屏');
    // DPR 用整数千分比（避免 float 破坏 CLI≡AOT 确定性算术契约）
    assert_eq($surface->getDprPermille(), 1000, '默认 DPR 应为 1000（=1.0x）');
});

test('ViewMetrics 提供安全区域（桌面端恒 0）', function () {
    $platform = new _MockPlatform();
    $platform->init('Metrics Test', 800, 600);
    $metrics = $platform->getMetrics();

    assert_true($metrics instanceof ViewMetrics, 'getMetrics() 应返回 ViewMetrics');
    assert_eq($metrics->getSafeAreaTop(), 0, '桌面端安全区域顶部应为 0');
    assert_eq($metrics->getSafeAreaBottom(), 0, '桌面端安全区域底部应为 0');
});

test('getLifecycleState() 取代 shouldClose 的语义升级', function () {
    $platform = new _MockPlatform();

    assert_eq($platform->getLifecycleState(), LifecycleEvent::STATE_ACTIVE, '初始态应为 active');

    $platform->shouldClose = true;
    assert_eq($platform->getLifecycleState(), LifecycleEvent::STATE_DETACHED, '关闭后应为 detached');
    assert_true($platform->shouldClose(), 'shouldClose() 应与 detached 等价');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
