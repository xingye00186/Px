<?php

namespace Px\Component\Contracts;
use Px\Component\ReactiveComponent;

use Px\Core\Scheduler;
use Px\Render\RenderNode;
use Px\Dom\VNode;

/**
 * ReactiveComponentInterface — 响应式组件接口
 *
 * 定义响应式组件的公共契约，包括渲染、脏标记更新、
 * 事件冒泡、绑定值读写、生命周期管理。
 *
 * @see \Px\ReactiveComponent
 */
interface ReactiveComponentInterface
{
    // ── 渲染 & 更新 ─────────────────────────────

    /**
     * 注入渲染请求回调（由 Application 注入）。
     */
    public function setRenderCallback(callable $callback): void;

    /**
     * 执行异步更新（微任务中调用）。
     */
    public function performUpdate(): void;

    /**
     * 获取 VNode 树（惰性重建）。
     */
    public function getVNodeTree(): VNode;

    /**
     * 渲染当前组件的 VNode 树。
     */
    public function render(): VNode;

    // ── 生命周期 ─────────────────────────────────

    /**
     * 挂载组件。
     */
    public function mount(): void;

    /**
     * 卸载组件。
     */
    public function unmount(): void;

    // ── 绑定值 ───────────────────────────────────

    /**
     * 设置绑定值。
     */
    public function setBindValue(string $bindKey, string $value): void;

    /**
     * 获取绑定值。
     */
    public function getBindValue(string $bindKey): string;

    // ── RenderNode 树状态管理 ────────────────────

    /**
     * 获取上一帧的根 RenderNode，用于跨帧匹配复用。
     */
    public function getRootRenderNode(): ?RenderNode;

    /**
     * 设置当前根 RenderNode，供下一帧匹配复用。
     */
    public function setRootRenderNode(?RenderNode $node): void;

    /**
     * 检查本轮渲染周期内组件是否重渲染过（用于 updateFromVNode 组件级跳过）。
     * renderDirty 在 performUpdate 中设置，在 updateFromVNode 组件处理中清除。
     */
    public function isRenderDirty(): bool;

    /**
     * 清除渲染脏标记（在 updateFromVNode 处理完组件后调用）。
     */
    public function clearRenderDirty(): void;

    // ── 事件分发 ─────────────────────────────────

    /**
     * 沿组件树冒泡点击事件。
     */
    public function dispatchClick(string $handler, ?string $arg = null): void;

    /**
     * 沿组件树冒泡键盘事件。
     */
    public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void;
}
