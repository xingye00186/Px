<?php
/**
 * PxTest — Px 框架统一测试基础设施 (PSR-4)
 *
 * 自动加载器注册。在测试入口 require_once 此文件即可。
 *
 * Usage:
 *   require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';
 */

spl_autoload_register(function (string $class): void {
    // PxTest\Foo\Bar → tools/PxTest/Foo/Bar.php
    $prefix = 'PxTest\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

// 确保框架类也可加载（如果尚未注册）
if (!class_exists('Px\Core\Application', false)) {
    $frameworkAutoload = dirname(__DIR__, 2) . '/framework';
    // 框架本身使用自己的 autoload 机制；此处不重复注册
    // 测试通常在已经加载框架的环境下运行
}

// 注册全局测试辅助函数
require_once __DIR__ . '/Assertions/RenderAssertions.php';
