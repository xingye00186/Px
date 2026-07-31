<?php

namespace Px\Platform;

use Px\Paint\RenderContext;

/**
 * Platform — 平台抽象接口（Embedder 边界）
 *
 * Framework 层与平台世界的**唯一边界**（终极融合铁律 1：框架层零平台分支）。
 * 所有平台差异必须在此接口的实现内翻译为统一抽象后才进入 Framework：
 *   原生句柄 → RenderSurface
 *   窗口度量 → ViewMetrics
 *   输入消息 → PointerEvent / KeyEvent
 *   窗口状态 → LifecycleEvent / MetricsEvent / RedrawEvent
 *
 * 实现者：Win32Platform（当前）、AndroidEmbedder / IOSEmbedder（Phase 3/4）。
 */
interface Platform
{
    /**
     * 初始化平台：创建窗口/表面、显示、创建渲染上下文。
     *
     * @param string $title  窗口标题（移动端忽略）
     * @param int    $width  窗口宽度
     * @param int    $height 窗口高度
     * @return RenderContext 渲染上下文
     */
    public function init(string $title, int $width, int $height): RenderContext;

    /**
     * 获取渲染表面（在 init 之后调用有效）。
     *
     * 取代原 getHwnd()：原生句柄的平台含义封装在 RenderSurface 内，
     * Framework 层只看到"一个表面"。RuntimeBackendSelector 等需要句柄的
     * 子系统经 RenderSurface::getHandle() 获取。
     */
    public function getSurface(): RenderSurface;

    /**
     * 获取当前视图度量（尺寸 / DPR / 安全区域）。
     */
    public function getMetrics(): ViewMetrics;

    /**
     * 当前生命周期状态，取值见 LifecycleEvent::STATE_*。
     *
     * 桌面端只在 active / detached 间切换；移动端返回完整状态机。
     */
    public function getLifecycleState(): string;

    /**
     * 关闭平台：销毁窗口、释放资源。
     */
    public function shutdown(): void;

    /**
     * 宿主是否已请求终止（等价于 getLifecycleState() === 'detached'）。
     */
    public function shouldClose(): bool;

    /**
     * 轮询平台事件。
     *
     * @return PlatformEvent[] PointerEvent / KeyEvent / LifecycleEvent /
     *                         MetricsEvent / RedrawEvent
     */
    public function pollEvents(): array;

    /**
     * 设置动画定时器回调。
     *
     * @param callable $callback 每帧触发的回调函数
     * @param int $intervalMs 帧间隔（毫秒），默认约 16ms ≈ 60fps
     */
    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void;

    /**
     * 设置鼠标光标样式。
     *
     * @deprecated 光标切换已由 Win32 GDI 渲染层根据元素的 cursor 属性自动处理。
     *             此方法保留仅用于外部直接控制场景，目前为空实现。
     *             移动端 Embedder 空实现即可（不产出 kind='mouse' 的
     *             PointerEvent，cursor 路径永不触发）。
     * @param string $cursor 光标类型：'' 默认箭头, 'pointer' 手型
     */
    public function setCursor(string $cursor): void;
}
