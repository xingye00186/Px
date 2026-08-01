<?php

namespace Px\Reactive;

use native_types;
use Px\Core\Scheduler;
use Px\Component\ReactiveComponent;

/**
 * Effect — 渲染副作用
 *
 * 每个响应式组件实例持有 1 个 Effect。
 * 对标 Vue 3 的 ReactiveEffect，借鉴 Flutter 的 markNeedsBuild() 调度。
 *
 * 职责:
 *   - 记录 render() 期间访问的依赖（depIds）
 *   - 依赖变化时通过 schedule() 触发组件更新
 *   - 组件 unmount 时清理所有订阅
 */
class Effect
{
    /** @var bool 是否已排入微任务队列（防重入） */
    private bool $pending = false;

    /** @var string[] 此 Effect 依赖的 depId 列表 */
    private array $depIds = [];

    /** @var ReactiveComponent|null 关联的组件实例 */
    private ?ReactiveComponent $component = null;

    public function __construct(ReactiveComponent $component)
    {
        $this->component = $component;
    }

    /**
     * 记录依赖（由 DependencyTracker::track() 调用）
     */
    public function recordDependency(string $depId): void
    {
        $this->depIds[] = $depId;
    }

    /**
     * 调度组件更新（由 DependencyTracker::notify() 调用）
     *
     * Vue 3: effect.scheduler 触发 componentUpdateFn
     * Flutter: markNeedsBuild() 将 element 加入 dirty list
     *
     * pending 保证: 同一事件循环中多次 notify 只触发一次微任务
     *
     * 关键时序: 同步清除 dirty 标志和 VNode 缓存,
     * 确保 setBindValue 后立即调用 getVNodeTree() 时
     * 能正确重建而不是返回过期的缓存。
     */
    public function schedule(): void
    {
        // 同步失效 VNode 缓存 — 必须在 pending 检查之前执行,
        // 确保即使 Effect 已排入微任务队列（pending=true），
        // 后续的属性变更也能正确设 dirty=true。
        // 否则连续两次赋值只有第一次会标记 dirty。
        if ($this->component !== null) {
            $this->component->dirty = true;
        }

        // pending 防重入: 同一事件循环中多次 notify 只触发一次微任务
        if ($this->pending) {
            return;
        }
        $this->pending = true;

        $componentRef = $this->component;
        // 必须使用组件持有的 Scheduler（Application 注入的实例）而非全局单例：
        // Application 主循环持有 new Scheduler() 实例，单例与它是两个不同队列，
        // 微任务排入单例队列永远不会被主循环 flush → 响应式更新静默失效。
        $scheduler = $componentRef !== null ? $componentRef->getScheduler() : null;
        if ($scheduler === null) {
            $scheduler = Scheduler::getInstance();
        }
        $scheduler->addMicrotask(function () use ($componentRef): void {
            $this->pending = false;

            if ($componentRef === null) {
                return;
            }

            $componentRef->performUpdate();
        });
    }

    /**
     * 清理所有依赖订阅（组件 unmount 时调用）
     *
     * Vue 3: effect.stop()
     * Flutter: dispose()
     */
    public function cleanup(): void
    {
        // 从 DependentsMap 中移除所有此 Effect 的引用
        DependentsMap::removeAllFor($this);

        $this->depIds = [];
        $this->component = null;
        $this->pending = false;
    }
}
