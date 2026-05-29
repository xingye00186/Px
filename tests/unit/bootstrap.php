<?php
/**
 * 单元测试 Bootstrap
 * 
 * 加载框架核心类 + 提供测试工具函数
 * PHP 7.4+ 兼容
 */

$frameworkDir = dirname(__DIR__, 2) . '/framework';

// ---- Swoole AOT polyfill (测试环境无 swoole_compiler) ----
if (!function_exists('objval')) {
    function objval($object, string $class) {
        // swoole_compiler: 将对象 cast 到指定类以允许 AOT 闭包访问 protected 属性
        // PHP 原生: 直接返回对象即可
        return $object;
    }
}

if (!function_exists('any')) {
    /**
     * Swoole AOT 类型标注函数。
     * 在 AOT 编译中，any() 标记动态类型以便编译器推导；
     * 在原生 PHP 中，直接返回对象即可。
     */
    function any($object) {
        return $object;
    }
}

// ---- PHP 8.0 函数 polyfill (测试环境 PHP 7.4) ----
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

// ---- 核心接口 ----
require_once $frameworkDir . '/interfaces/ComponentInterface.php';

// ---- 核心渲染 ----
require_once $frameworkDir . '/Rendering/CssMappings.php';
require_once $frameworkDir . '/Rendering/VNode.php';
require_once $frameworkDir . '/Rendering/LayoutResolver.php';
require_once $frameworkDir . '/Rendering/RenderContext.php';
require_once $frameworkDir . '/Rendering/VNodeRenderer.php';

// ---- 核心运行时 ----
require_once $frameworkDir . '/Core/Scheduler.php';
require_once $frameworkDir . '/BaseComponent.php';
require_once $frameworkDir . '/ReactiveComponent.php';

// ---- 平台 ----
require_once $frameworkDir . '/Platform/Platform.php';
require_once $frameworkDir . '/Platform/PlatformEvent.php';

// ---- Application ----
require_once $frameworkDir . '/Core/Application.php';

// ---- 编译器 (按需加载) ----
require_once $frameworkDir . '/compiler/template-parser.php';
require_once $frameworkDir . '/compiler/component-registry.php';

// ---- Styling (测试环境加载最小依赖) ----
// Application::expandComponentNode 需要 ThemeProvider 注册 class styles
require_once $frameworkDir . '/Styling/Theme/ColorScheme.php';
require_once $frameworkDir . '/Styling/Theme/TextTheme.php';
require_once $frameworkDir . '/Styling/Theme/ComponentTheme.php';
require_once $frameworkDir . '/Styling/Theme/ThemeData.php';
require_once $frameworkDir . '/Styling/Provider/ThemeProvider.php';

// ---- 全局计数器 ----
$GLOBALS['_test_passed'] = 0;
$GLOBALS['_test_failed'] = 0;
$GLOBALS['_test_warnings'] = [];

/**
 * 执行单个测试用例
 */
function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['_test_passed']++;
        echo "  [PASS] {$name}\n";
    } catch (\AssertionError $e) {
        $GLOBALS['_test_failed']++;
        echo "  [FAIL] {$name}\n";
        echo "          {$e->getMessage()}\n";
    } catch (\Throwable $e) {
        $GLOBALS['_test_failed']++;
        echo "  [FAIL] {$name}\n";
        echo "          [{$e->getCode()}] {$e->getMessage()}\n";
    }
}

/**
 * 断言相等 (宽松)
 */
function assert_eq($actual, $expected, string $msg = ''): void
{
    if ($actual != $expected) {
        $actualStr = is_array($actual) ? json_encode($actual) : var_export($actual, true);
        $expectedStr = is_array($expected) ? json_encode($expected) : var_export($expected, true);
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}: " : '') . "expected {$expectedStr}, got {$actualStr}"
        );
    }
}

/**
 * 断言严格相等
 */
function assert_same($actual, $expected, string $msg = ''): void
{
    if ($actual !== $expected) {
        $actualStr = is_array($actual) ? json_encode($actual) : var_export($actual, true);
        $expectedStr = is_array($expected) ? json_encode($expected) : var_export($expected, true);
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}: " : '') . "expected {$expectedStr}, got {$actualStr}"
        );
    }
}

/**
 * 断言为真
 */
function assert_true($condition, string $msg = ''): void
{
    if (!$condition) {
        throw new \AssertionError($msg !== '' ? $msg : 'expected truthy, got falsy');
    }
}

/**
 * 断言为假
 */
function assert_false($condition, string $msg = ''): void
{
    if ($condition) {
        throw new \AssertionError($msg !== '' ? $msg : 'expected falsy, got truthy');
    }
}

/**
 * 断言非 null
 */
function assert_not_null($value, string $msg = ''): void
{
    if ($value === null) {
        throw new \AssertionError($msg !== '' ? $msg : 'expected non-null, got null');
    }
}

/**
 * 断言 null
 */
function assert_null($value, string $msg = ''): void
{
    if ($value !== null) {
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}: " : '') . 'expected null, got ' . var_export($value, true)
        );
    }
}

/**
 * 断言包含子串
 */
function assert_contains(string $haystack, string $needle, string $msg = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}: " : '') . "expected '{$haystack}' to contain '{$needle}'"
        );
    }
}

/**
 * 打印测试结果摘要
 */
function print_summary(): int
{
    $passed = $GLOBALS['_test_passed'];
    $failed = $GLOBALS['_test_failed'];
    $total = $passed + $failed;

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "Results: {$passed}/{$total} passed";
    if ($failed > 0) {
        echo ", {$failed} FAILED";
    }
    echo "\n";
    echo str_repeat('=', 50) . "\n";

    if ($failed > 0) {
        echo "SOME TESTS FAILED!\n";
        return 1;
    }
    echo "All tests passed.\n";
    return 0;
}
