<?php
/**
 * Px Framework PSR-4 Autoloader
 *
 * 注册此文件后，所有 Px\* 命名空间类自动从 framework/ 目录加载。
 * 映射规则：Px\Xxx\Yyy\Zzz → framework/Xxx/Yyy/Zzz.php
 *           Px\Foo          → framework/Foo.php
 *
 * AOT 编译时 spl_autoload_register 为 NOP，不影响编译结果。
 *
 * Usage（PHP Runtime 模式入口）:
 *   require_once __DIR__ . '/autoload.php';
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'Px\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
