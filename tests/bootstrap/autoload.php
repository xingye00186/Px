<?php
/**
 * Px Framework PSR-4 Autoloader (PHP Runtime 模式)
 *
 * 注册此文件后，所有 Px\* 命名空间类自动从 framework/ 目录加载。
 * 映射规则：Px\Xxx\Yyy\Zzz → framework/Xxx/Yyy/Zzz.php
 *           Px\Foo          → framework/Foo.php
 *
 * AOT 编译时 spl_autoload_register 为 NOP，不影响编译结果。
 * 此文件仅用于 PHP Runtime 入口（测试 / SFC 编译器），
 * 已从 framework/ 移出以避免 AOT 编译扫描到它。
 *
 * Usage:
 *   require_once __DIR__ . '/autoload.php';
 */

// Px 框架根目录（此文件在 tests/bootstrap/ 下）
$pxRoot = dirname(__DIR__, 2);

spl_autoload_register(function (string $class) use ($pxRoot): void {
    $prefix = 'Px\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $pxRoot . '/framework/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
