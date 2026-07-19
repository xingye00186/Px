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
 * ReactiveComponent — 响应式组件基类 (v10 Reactive)
 *
 *  - 响应式属性通过 #[Reactive] + Property Hooks 自动追踪
 *  - 属性变更自动触发 Effect 调度 → 微任务 → 组件更新
 *  - $emit() 子→父事件通信（对标 Vue 3）
 *  - unmount 自动清理 Effect 订阅 + 事件处理器
 *
 * 注意:
 *   - markDirty() 已移除 — 由 Property Hook 的 set → Notifier::notify() → Effect 自动处理
 *   - 子类仍可手动调用 performUpdate() 强制更新
 *   - 兼容 Application::matchComponentNode() 的 $instance->dirty 检查
 *
 * AOT：闭包调用合法，默认值传递。
 */
abstract class ReactiveComponent extends BaseComponent implements ComponentInterface, ReactiveComponentInterface
{
    /** @var bool 缓存脏标记 — 由 performUpdate() 设置，getVNodeTree() 清除 */
    public bool $dirty = false;

    /** @var bool 防止 performUpdate() 重入 */
    protected bool $isUpdating = false;

    protected bool $isMounted = false;

    /** @var callable|null 渲染请求回调（由 Application 注入） */
    protected ?\Closure $renderCallback = null;

    /** @var array<string, array<int, callable>> eventName => [handlerId => callback] */
    protected array $eventHandlers = [];

    /** @var array<array{child: ReactiveComponent, handlerId: int}> 注册在子组件上的处理器引用 */
    protected array $listenerIds = [];

    private int $nextHandlerId = 1;

    /**
     * VNode 树缓存
     *
     * Vue 3 风格惰性重建：
     *   状态未变时复用缓存，避免整树重建
     *   performUpdate() 将 dirty 设为 true → getVNodeTree() 重新执行 render()
     */
    protected ?VNode $vnodeCache = null;

    /**
     * 响应式 Effect（由编译器生成的子类初始化）
     * 通过 DependencyTracker::runWithEffect() 包裹 render()
     * 自动追踪 render() 期间读取的 #[Reactive] 属性
     */
    protected ?\Px\Reactive\Effect $_px_effect = null;

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
     * 执行更新 (由 Effect::schedule() 的微任务回调调用)
     *
     * 触发渲染管线：
     *   Effect::schedule() → microtask → performUpdate()
     *   → renderCallback → Application::requestRender()
     *   → rebuildVNodeTree() → getVNodeTree() → render()
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

        // 清除 VNode 缓存 — 强制下次 getVNodeTree() 重新执行 render()
        $this->vnodeCache = null;
        $this->dirty = true;

        // 通过注入的回调请求 Application 重渲染
        if ($this->renderCallback !== null) {
            ($this->renderCallback)();
        }

        if ($this->isMounted) {
            $this->onUpdated();
        }

        $this->isUpdating = false;
    }

    /**
     * 获取 VNode 树 — 惰性重建。
     *
     * 仅在 dirty 时调用 render() 重建并缓存；
     * 状态未变时直接返回上次缓存的树。
     *
     * 子类若使用了 #[Reactive] 属性，编译器会自动覆盖此方法
     * 并包裹在 DependencyTracker::runWithEffect() 中。
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
