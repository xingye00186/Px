<?php

namespace Px;

use Px\Interfaces\ComponentInterface;
use Px\Core\Scheduler;
use Px\Core\ReactionBus;
use Px\Rendering\VNode;

/**
 * ReactiveComponent — 响应式组件基类
 *
 *  - 响应式属性 + dirty 标记
 *  - 异步更新队列（via Scheduler closure）
 *  - 自动解绑监听（via ReactionBus）
 *
 * AOT：闭包调用合法，默认值传递。
 */
abstract class ReactiveComponent extends BaseComponent
{
    // AOT: 使用 protected 而非 private，确保子类 + objval() 闭包可访问
    protected bool $hasPendingUpdate = false;
    public bool $dirty = false;
    protected bool $isMounted = false;
    protected bool $isUpdating = false;

    /** @var array<int> ReactionBus 监听器 ID 列表（unmount 时自动解绑） */
    protected array $listenerIds = [];

    /**
     * VNode 树缓存 — Vue 3 风格惰性重建：
     *  - 状态未变时复用缓存，避免整树重建
     *  - markDirty() 清缓存（状态已变，旧树失效）
     *  - getVNodeTree() 仅在 dirty 时调用 render()
     */
    protected ?VNode $vnodeCache = null;

    /**
     * 标记脏状态，触发异步更新。
     * 立即清除 VNode 缓存 — 状态已变更，旧树失效。
     */
    protected function markDirty(): void
    {
        $this->vnodeCache = null;
        $this->scheduleUpdate();
    }

    /**
     * 异步更新（微任务队列）
     * AOT 支持闭包 $fn() 调用
     */
    protected function scheduleUpdate(): void
    {
        if ($this->hasPendingUpdate) {
            return;
        }
        $this->hasPendingUpdate = true;
        $component = objval($this, self::class);
        $this->scheduler->addMicrotask(function () use ($component) {
            $component->hasPendingUpdate = false;
            $component->performUpdate();
        });
    }

    /**
     * 执行更新 (AOT: public 以便闭包回调中可通过 objval() 引用访问)
     */
    public function performUpdate(): void
    {
        if ($this->isUpdating) {
            return;
        }

        $this->isUpdating = true;

        if ($this->isMounted) {
            $this->onBeforeUpdate();
        }

        // 请求 Application 重渲染
        $this->bus->emit('render:request', null);

        $this->dirty = true;

        if ($this->isMounted) {
            $this->onUpdated();
        }

        $this->isUpdating = false;
    }

    /**
     * 获取 VNode 树 — Vue 3 风格惰性重建。
     *
     * 仅在 dirty 时调用 render() 重建并缓存；
     * 状态未变时直接返回上次缓存的树，跳过整树重建。
     *
     * 这相当于 Vue 3 的 component effect：
     *   dirty === true  → re-run render() → cache → dirty = false
     *   dirty === false → return cached VNode
     */
    public function getVNodeTree(): VNode
    {
        if (!$this->dirty && $this->vnodeCache !== null) {
            return $this->vnodeCache;
        }

        $this->vnodeCache = $this->render();
        $this->dirty = false;
        return $this->vnodeCache;
    }

    /**
     * 订阅事件（unmount 时自动解绑）
     */
    protected function subscribe(string $eventType, callable $callback, int $priority = 0): int
    {
        $id = $this->bus->on($eventType, $callback, $priority);
        $this->listenerIds[] = $id;
        return $id;
    }

    /**
     * 取消订阅
     */
    protected function unsubscribe(int $listenerId): void
    {
        $idx = array_search($listenerId, $this->listenerIds, true);
        if ($idx !== false) {
            unset($this->listenerIds[$idx]);
            $this->listenerIds = array_values($this->listenerIds);
        }
        $this->bus->off($listenerId);
    }

    /**
     * 挂载组件
     */
    public function mount(): void
    {
        $this->isMounted = true;
        $this->onMount();
    }

    /**
     * 卸载组件（自动解绑所有监听器）
     */
    public function unmount(): void
    {
        $this->onUnmount();
        $this->isMounted = false;

        foreach ($this->listenerIds as $id) {
            $this->bus->off($id);
        }
        $this->listenerIds = [];
    }

    public function onUnmount(): void {}

    public function onBeforeUpdate(): void {}
    public function onUpdated(): void {}

    abstract public function onMount(): void;
    abstract public function render(): VNode;
    abstract public function dispatchClick(string $handler, ?string $arg = null): void;
    abstract public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void;
    abstract public function setBindValue(string $bindKey, string $value): void;
    abstract public function getBindValue(string $bindKey): string;
}