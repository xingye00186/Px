<?php

namespace Px\Reactive;

use native_types;

/**
 * DependentsMap — 依赖映射表
 *
 * depId (spl_object_id|property) → Effect 订阅者列表
 * 使用 SplObjectStorage 管理 Effect 引用（AOT 兼容，替代 WeakMap）。
 *
 * Vue 3: targetMap → depsMap → Set<ReactiveEffect>
 * Px:    self::$map[depId] → SplObjectStorage<Effect>
 */
class DependentsMap
{
    /** @var array<string, \SplObjectStorage> */
    private static array $map = [];

    /**
     * 添加订阅关系
     */
    public static function add(string $depId, Effect $effect): void
    {
        if (!isset(self::$map[$depId])) {
            self::$map[$depId] = new \SplObjectStorage();
        }
        self::$map[$depId]->attach($effect);
    }

    /**
     * 获取某 depId 的所有订阅 Effect
     * AOT 安全: 使用 while+valid+current 而非 foreach 或 iterator_to_array
     *
     * @return Effect[]
     */
    public static function get(string $depId): array
    {
        if (!isset(self::$map[$depId])) {
            return [];
        }

        $storage = self::$map[$depId];
        $result = [];
        $storage->rewind();
        while ($storage->valid()) {
            $result[] = $storage->current();
            $storage->next();
        }
        return $result;
    }

    /**
     * 移除某 depId 下的指定 Effect 订阅
     */
    public static function remove(string $depId, Effect $effect): void
    {
        if (!isset(self::$map[$depId])) {
            return;
        }
        self::$map[$depId]->detach($effect);
        if (self::$map[$depId]->count() === 0) {
            unset(self::$map[$depId]);
        }
    }

    /**
     * 跨 depId 移除某 Effect 的所有订阅（组件 unmount 时调用）
     */
    public static function removeAllFor(Effect $effect): void
    {
        foreach (self::$map as $depId => $storage) {
            if ($storage->contains($effect)) {
                $storage->detach($effect);
                if ($storage->count() === 0) {
                    unset(self::$map[$depId]);
                }
            }
        }
    }

    /**
     * 获取当前映射条目数（诊断用）
     */
    public static function count(): int
    {
        return count(self::$map);
    }

    /**
     * 清空所有映射（仅测试用）
     */
    public static function clear(): void
    {
        self::$map = [];
    }
}
