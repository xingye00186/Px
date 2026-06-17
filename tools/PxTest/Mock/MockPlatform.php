<?php

namespace PxTest\Mock;

use Px\Platform\Platform;
use Px\Rendering\RenderContext;

/**
 * 可捕获 RenderContext 操作和注入事件的测试平台。
 *
 * 替代现有的 StubPlatform 和 _CssCapturePlatform，统一测试用平台抽象。
 */
class MockPlatform implements Platform
{
    public MockRenderContext $renderContext;

    /** @var array<int, object> 预先注入的事件队列 */
    public array $events = [];

    private int $width;
    private int $height;
    private bool $shouldClose = false;
    private string $currentCursor = 'default';

    /** @var array<int, array{callback: callable, intervalMs: int}> */
    private array $timers = [];

    public function __construct(int $width = 1440, int $height = 900)
    {
        $this->width = $width;
        $this->height = $height;
        $this->renderContext = new MockRenderContext();
    }

    public function init(string $title, int $width, int $height): RenderContext
    {
        $this->width = $width;
        $this->height = $height;
        return $this->renderContext;
    }

    public function getHwnd(): int
    {
        return 0; // 无实际窗口句柄
    }

    public function shutdown(): void {}

    public function shouldClose(): bool
    {
        return $this->shouldClose;
    }

    /** 强制平台"关闭"（模拟窗口关闭事件） */
    public function forceClose(): void
    {
        $this->shouldClose = true;
    }

    /** @return array<int, object> */
    public function pollEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    /** 注入事件到下一帧的 pollEvents 返回 */
    public function injectEvent(object $event): void
    {
        $this->events[] = $event;
    }

    /** 注入多个事件 */
    public function injectEvents(object ...$events): void
    {
        array_push($this->events, ...$events);
    }

    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void
    {
        $this->timers[] = ['callback' => $callback, 'intervalMs' => $intervalMs];
    }

    public function setCursor(string $cursor): void
    {
        $this->currentCursor = $cursor;
    }

    public function getCursor(): string
    {
        return $this->currentCursor;
    }

    /** 触发所有注册的动画定时器回调 */
    public function tickAnimation(): void
    {
        foreach ($this->timers as $timer) {
            ($timer['callback'])();
        }
    }

    /** 重置所有捕获状态（两帧之间调用） */
    public function resetCapturedState(): void
    {
        $this->renderContext->beginFrame();
        $this->events = [];
    }
}
