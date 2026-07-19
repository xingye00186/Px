<?php

namespace Px\Reactive;

/**
 * Notifier — DependencyTracker::notify() 的语义别名
 *
 * 保持方案 "set 钩子调用 Notifier::notify()" 的语义一致性。
 * 内部委派到 DependencyTracker。
 */
class Notifier
{
    /**
     * 通知依赖变更
     *
     * @param object $target 组件实例
     * @param string $key 属性名
     */
    public static function notify(object $target, string $key): void
    {
        DependencyTracker::notify($target, $key);
    }
}
