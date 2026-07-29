<?php
/**
 * 单元测试 Bootstrap
 * 
 * 加载框架核心类 + 提供测试工具函数
 * PHP 7.4+ 兼容
 */

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

if (!function_exists('refval')) {
    /**
     * AOT refval() polyfill: 在动态调用中将值传递修改为引用传递。
     */
    function &refval(&$var) { return $var; }
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

// ---- PSR-4 Autoloader ----
require_once dirname(__DIR__) . '/bootstrap/autoload.php';

// ---- 旧命名空间兼容别名层（C0.2 僵尸甄别批）----
// 模块重构前的测试引用 Px\Rendering\* / Px\ReactiveComponent 等旧名；
// 惰性钩子在旧名被请求时 class_alias 到新名（仅测试环境，生产/AOT 不载入）。
// 无新名对应物的旧 API（如 Tools\PercentResolver）不入表 → 对应测试属 B 类退役。
spl_autoload_register(static function (string $class): void {
    static $legacyMap = [
        'Px\\ReactiveComponent'             => 'Px\\Component\\ReactiveComponent',
        'Px\\Rendering\\LayoutOrchestrator' => 'Px\\Layout\\LayoutOrchestrator',
        'Px\\Rendering\\RenderContext'      => 'Px\\Paint\\RenderContext',
        'Px\\Rendering\\GdiRenderContext'   => 'Px\\Paint\\GdiRenderContext',
        'Px\\Rendering\\RenderTreeManager'  => 'Px\\Render\\RenderTreeManager',
        'Px\\Rendering\\VNode'              => 'Px\\Dom\\VNode',
        'Px\\Rendering\\RenderNode'         => 'Px\\Render\\RenderNode',
        'Px\\Rendering\\CssMappings'        => 'Px\\Css\\CssMappings',
    ];
    if (isset($legacyMap[$class]) && class_exists($legacyMap[$class])) {
        class_alias($legacyMap[$class], $class);
    }
}, prepend: false);

// ---- Stub 文件（C++ 原生函数声明，非类，不参与 PSR-4）----
$stubDir = dirname(__DIR__, 2) . '/stub';
if (file_exists($stubDir . '/vue_calc.stub.php')) {
    require_once $stubDir . '/vue_calc.stub.php';
}
if (file_exists($stubDir . '/skia.stub.php')) {
    require_once $stubDir . '/skia.stub.php';
}

// ---- 测试框架（全局计数器+test/assert函数+Jest风格扩展）----
require_once __DIR__ . '/test-framework.php';

// ---- PHP Runtime 模式（启用文本测量等 C++ 函数回退）----
putenv('PX_PHP_RUNTIME=1');

// ---- PxTest 自动加载器（GoldenTextWidth 等工具类）----
$pxTestBootstrap = dirname(__DIR__, 2) . '/tools/PxTest/bootstrap.php';
if (file_exists($pxTestBootstrap)) {
    require_once $pxTestBootstrap;
    // 设置黄金宽度表数据文件路径
    $goldenFile = dirname(__DIR__, 2) . '/tools/PxTest/GoldenMeasure/golden_text_widths.json';
    if (file_exists($goldenFile) && class_exists('\\PxTest\\Bootstrap\\GoldenTextWidth')) {
        \PxTest\Bootstrap\GoldenTextWidth::setDataFile($goldenFile);
    }
}
