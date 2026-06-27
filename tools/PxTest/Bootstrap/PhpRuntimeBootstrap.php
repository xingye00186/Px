<?php

/**
 * PhpRuntimeBootstrap — 纯 PHP Runtime 环境初始化
 *
 * 为 PHP CLI 下加载 AOT-gen 组件提供必要的函数 stub。
 * 调用时机：在 require gen/ 文件之前引入。
 * 对 AOT 编译模式零影响：此文件不在 AOT 编译范围内。
 */

if (!trait_exists('native_types', false)) {
    /**
     * native_types — phpx AOT 编译期 trait，纯 PHP 下为空实现
     * 框架类使用 `use native_types;` 来获得类型推导能力。
     * 在 PHP CLI 下无需此能力，提供空 trait 即可。
     */
    trait native_types {}
}

function px_php_runtime_init(): void
{
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    // 1. any() — phpx Variant 包装函数
    // 纯 PHP 下退化为恒等函数
    if (!function_exists('any')) {
        /**
         * @param mixed $v
         * @return mixed
         */
        function any(mixed $v): mixed
        {
            return $v;
        }
    }

    // 3. objval() — phpx 类型转换/断言函数
    // 在 PHP CLI 下退化为类型检查后返回值
    if (!function_exists('objval')) {
        function objval(mixed $value, string $type): mixed
        {
            if ($value === null) return null;
            if ($value instanceof \Closure && $type === \Closure::class) return $value;
            if (is_object($value) && class_exists($type) && $value instanceof $type) return $value;
            return $value;
        }
    }

    // 4. 设置环境变量标识 PHP Runtime 模式
    // 该环境变量被 PercentResolver::resolveTextWidth() 检测，
    // 用于激活黄金宽度表查询路径
    if (getenv('PX_PHP_RUNTIME') === false || getenv('PX_PHP_RUNTIME') === '') {
        putenv('PX_PHP_RUNTIME=1');
    }

    // 3. 双重保险：强制走估算 fallback（防止个别代码路径意外检测到 native 函数）
    if (getenv('PX_LAYOUT_TEST_FORCE_ESTIMATE') === false || getenv('PX_LAYOUT_TEST_FORCE_ESTIMATE') === '') {
        putenv('PX_LAYOUT_TEST_FORCE_ESTIMATE=1');
    }
}
