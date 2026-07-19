<?php

namespace Px\Reactive;

use native_types;

/**
 * DependencyTracker — 全局依赖追踪管理器
 *
 * 对标 Vue 3 的 activeEffect + effectStack。
 * 由编译器生成的 Property Hook 自动调用。
 *
 * 数据流:
 *   track() ← get 钩子 ← render() 读取属性
 *   notify() ← set 钩子 ← 组件方法修改属性
 *
 * AOT 安全: 无动态属性创建，无命名参数，无 $GLOBALS。
 */
class DependencyTracker
{
    /** @var Effect|null 当前正活跃的渲染 Effect */
    private static ?Effect $currentEffect = null;

    /** @var Effect[] Effect 栈（支持嵌套渲染） */
    private static array $effectStack = [];

    /**
     * track — 注册依赖关系
     *
     * 由生成的属性 get 钩子调用。
     * 将当前 Effect 订阅到 [target][key] 的依赖映射中。
     *
     * @param object $target 组件实例
     * @param string $key 属性名
     */
    public static function track(object $target, string $key): void
    {
        $effect = self::$currentEffect;
        if ($effect === null) {
            return; // 不在渲染上下文中 — 不追踪
        }

        $depId = self::depId($target, $key);
        $effect->recordDependency($depId);
        DependentsMap::add($depId, $effect);
    }

    /**
     * notify — 通知依赖变更
     *
     * 由生成的属性 set 钩子调用。
     * 调度所有订阅了 [target][key] 的 Effect。
     *
     * @param object $target 组件实例
     * @param string $key 属性名
     */
    public static function notify(object $target, string $key): void
    {
        $depId = self::depId($target, $key);
        $effects = DependentsMap::get($depId);
        foreach ($effects as $effect) {
            $effect->schedule();
        }
    }

    /**
     * runWithEffect — 在 Effect 上下文中执行函数
     *
     * 由编译器生成 getVNodeTree() 调用。
     * Vue 3: activeEffect = effect → render() → activeEffect = parent
     *
     * @param Effect   $effect 当前渲染的 Effect
     * @param callable $fn     render() 闭包
     * @return mixed 函数返回值
     */
    public static function runWithEffect(Effect $effect, callable $fn): mixed
    {
        array_push(self::$effectStack, $effect);
        self::$currentEffect = $effect;
        try {
            return $fn();
        } finally {
            array_pop(self::$effectStack);
            $count = count(self::$effectStack);
            self::$currentEffect = $count > 0
                ? self::$effectStack[$count - 1]
                : null;
        }
    }

    /**
     * 计算唯一依赖 ID
     * AOT: spl_object_id 已验证为数组键安全
     */
    private static function depId(object $target, string $key): string
    {
        return (string)spl_object_id($target) . '|' . $key;
    }
}
