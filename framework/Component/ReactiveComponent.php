<?php

namespace Px\Component;
use Px\Render\RenderTreeManager;

use native_types;

use Px\Component\Contracts\ComponentInterface;
use Px\Component\Contracts\ReactiveComponentInterface;
use Px\Core\Scheduler;
use Px\Render\RenderNode;
use Px\Dom\VNode;

/**
 * ReactiveComponent — 响应式组件基类
 *
 *  - 响应式属性 + dirty 标记
 *  - 异步更新队列（via Scheduler microtask）
 *  - $emit() 子→父事件通信（对标 Vue 3）
 *  - unmount 自动清理事件处理器
 *
 * AOT：闭包调用合法，默认值传递。
 */
abstract class ReactiveComponent extends BaseComponent implements ComponentInterface, ReactiveComponentInterface
{
    // AOT: 使用 protected 而非 private，确保子类 + objval() 闭包可访问
    protected bool $hasPendingUpdate = false;
    public bool $dirty = false;
    protected bool $isMounted = false;
    protected bool $isUpdating = false;

    /** @var callable|null 渲染请求回调（由 Application 注入） */
    protected ?\Closure $renderCallback = null;

    /** @var array<string, array<int, callable>> eventName => [handlerId => callback] */
    protected array $eventHandlers = [];

    /** @var array<array{child: ReactiveComponent, handlerId: int}> 注册在子组件上的处理器引用 */
    protected array $listenerIds = [];

    private int $nextHandlerId = 1;

    /**
     * VNode 树缓存 — Vue 3 风格惰性重建：
     *  - 状态未变时复用缓存，避免整树重建
     *  - markDirty() 清缓存（状态已变，旧树失效）
     *  - getVNodeTree() 仅在 dirty 时调用 render()
     */
    protected ?VNode $vnodeCache = null;

    /**
     * 上一帧的根 RenderNode，用于跨帧匹配复用。
     * 由 RenderTreeManager::updateFromVNode 在 #component 处理器中设置。
     */
    public ?RenderNode $rootRenderNode = null;

    public function getRootRenderNode(): ?RenderNode
    {
        return $this->rootRenderNode;
    }

    public function setRootRenderNode(?RenderNode $node): void
    {
        $this->rootRenderNode = $node;
    }

    /**
     * Application 注入渲染请求回调。
     */
    public function setRenderCallback(callable $callback): void
    {
        $this->renderCallback = $callback;
    }

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

        $this->scheduler->addMicrotask(function () {
            $this->hasPendingUpdate = false;
            $this->performUpdate();
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

        // 通过注入的回调请求 Application 重渲染
        if ($this->renderCallback !== null) {
            ($this->renderCallback)();
        }

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

    // ── 事件处理器注册（内部） ──────────────────

    /**
     * 注册事件处理器（由 on() 在子组件上调用），返回处理器 ID。
     */
    private function registerHandler(string $eventName, callable $callback): int
    {
        $id = $this->nextHandlerId++;
        if (!isset($this->eventHandlers[$eventName])) {
            $this->eventHandlers[$eventName] = [];
        }
        $this->eventHandlers[$eventName][$id] = $callback;
        return $id;
    }

    /**
     * 移除事件处理器。
     */
    private function removeHandler(int $id): void
    {
        foreach ($this->eventHandlers as $eventName => $handlers) {
            if (isset($handlers[$id])) {
                unset($this->eventHandlers[$eventName][$id]);
                if (empty($this->eventHandlers[$eventName])) {
                    unset($this->eventHandlers[$eventName]);
                }
                return;
            }
        }
    }

    // ── 组件通信 ─────────────────────────────────

    /**
     * 向父组件发送事件（对标 Vue 3 $emit）。
     *
     * 直接调用注册在当前组件上的事件处理器。
     *
     * 示例:
     *   // 子组件
     *   $this->emit('itemSelected', ['id' => 5]);
     *
     *   // 父组件
     *   $child->on('itemSelected', function($payload) { ... });
     *
     * @param string $eventName 事件名
     * @param mixed $payload 事件载荷
     */
    protected function emit(string $eventName, mixed $payload = null): void
    {
        if (!isset($this->eventHandlers[$eventName])) {
            return;
        }
        foreach ($this->eventHandlers[$eventName] as $callback) {
            $callback($payload);
        }
    }

    /**
     * 监听子组件事件（对标 Vue 3 v-on）。
     *
     * 子组件 emit('itemSelected', ...) 后触发回调。
     * 在 unmount 时自动解绑。
     *
     * @param ReactiveComponent $child 子组件实例
     * @param string $eventName 事件名
     * @param callable $callback 回调
     */
    protected function on(ReactiveComponent $child, string $eventName, callable $callback): void
    {
        $id = $child->registerHandler($eventName, $callback);
        $this->listenerIds[] = ['child' => $child, 'handlerId' => $id];
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
        $this->rootRenderNode = null;

        // 移除注册在子组件上的事件处理器
        foreach ($this->listenerIds as $entry) {
            $entry['child']->removeHandler($entry['handlerId']);
        }
        $this->listenerIds = [];

        // 清空自身事件处理器
        $this->eventHandlers = [];
    }

    public function onUnmount(): void
    {
    }

    public function onBeforeUpdate(): void
    {
    }

    public function onUpdated(): void
    {
    }

    public function onMount(): void
    {
    }

    abstract public function render(): VNode;

    abstract public function setBindValue(string $bindKey, string $value): void;

    abstract public function getBindValue(string $bindKey): string;
}
