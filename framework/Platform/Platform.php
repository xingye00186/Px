<?php

namespace Px\Platform;

use Px\Paint\RenderContext;

/**
 * Platform — 平台抽象接口
 *
 * 完全封装窗口管理，不暴露任何平台句柄（hwnd 等）给调用方。
 * 符合依赖倒置原则 (DIP)：Application 依赖此抽象，而非具体平台细节。
 */
interface Platform
{
    /**
     * 初始化平台：创建窗口、显示窗口、创建渲染上下文。
     *
     * @param string $title  窗口标题
     * @param int    $width  窗口宽度
     * @param int    $height 窗口高度
     * @return RenderContext 渲染上下文
     */
    public function init(string $title, int $width, int $height): RenderContext;

    /**
     * 获取当前窗口句柄（在 init 之后调用有效）
     * 主要供 RuntimeBackendSelector 等需要直接访问窗口的子系统使用。
     */
    public function getHwnd(): int;

    /**
     * 关闭平台：销毁窗口、释放资源。
     */
    public function shutdown(): void;

    /**
     * 窗口是否请求关闭。
     */
    public function shouldClose(): bool;

    /**
     * 轮询平台事件。
     *
     * @return PlatformEvent[]
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
     * @param string $cursor 光标类型：'' 默认箭头, 'pointer' 手型
     */
    public function setCursor(string $cursor): void;
}
