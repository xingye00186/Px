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

// ---- 核心接口 ----
require_once $frameworkDir . '/interfaces/ComponentInterface.php';

// ---- 核心渲染 ----
require_once $frameworkDir . '/Rendering/CssMappings.php';
require_once $frameworkDir . '/Rendering/CssValueParser.php';
require_once $frameworkDir . '/Rendering/VNode.php';
require_once $frameworkDir . '/Rendering/RenderNode.php';
require_once $frameworkDir . '/Rendering/RenderTreeManager.php';
require_once $frameworkDir . '/Rendering/LayoutResolver.php';
require_once $frameworkDir . '/Rendering/Layout/Tools/PercentResolver.php';
require_once $frameworkDir . '/Rendering/Layout/Tools/ScrollHelper.php';
require_once $frameworkDir . '/Rendering/Layout/AbsoluteStrategy.php';
require_once $frameworkDir . '/Rendering/Layout/AbsolutePositioning.php';
require_once $frameworkDir . '/Rendering/Layout/LayoutStrategyInterface.php';
require_once $frameworkDir . '/Rendering/Layout/BlockLayoutStrategy.php';
require_once $frameworkDir . '/Rendering/Layout/FlexLayoutStrategy.php';
require_once $frameworkDir . '/Rendering/Layout/GridLayoutStrategy.php';
require_once $frameworkDir . '/Rendering/Layout/InlineLayoutStrategy.php';
require_once $frameworkDir . '/Rendering/Layout/TableLayoutStrategy.php';
require_once $frameworkDir . '/Rendering/Layout/MultiColumnLayoutStrategy.php';
require_once $frameworkDir . '/Rendering/Layout/LayoutContext.php';
require_once $frameworkDir . '/Rendering/RenderContext.php';
require_once $frameworkDir . '/Rendering/VNodeRenderer.php';
require_once $frameworkDir . '/Rendering/ScrollbarEmitter.php';
require_once $frameworkDir . '/Rendering/TextOverflowProcessor.php';

// ---- 核心运行时 ----
require_once $frameworkDir . '/Core/Config.php';
require_once $frameworkDir . '/Core/Scheduler.php';
require_once $frameworkDir . '/BaseComponent.php';
require_once $frameworkDir . '/interfaces/ReactiveComponentInterface.php';
require_once $frameworkDir . '/ReactiveComponent.php';

// ---- 平台 ----
require_once $frameworkDir . '/Platform/Platform.php';
require_once $frameworkDir . '/Platform/PlatformEvent.php';

// ---- Application ----
require_once $frameworkDir . '/Core/Application.php';
require_once $frameworkDir . '/Core/ScrollManager.php';
require_once $frameworkDir . '/Rendering/ImageManager.php';

// ---- 性能计数器（AOT 适配） ----
require_once $frameworkDir . '/Core/PerfCounter.php';

// ---- 动画系统 ----
require_once $frameworkDir . '/Animation/CssAnimationParser.php';
require_once $frameworkDir . '/Animation/EasingFunctions.php';

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
require_once $frameworkDir . '/Styling/Adapter/PlatformAdapter.php';
require_once $frameworkDir . '/Styling/Adapter/PlatformStyling.php';
require_once $frameworkDir . '/Styling/Adapter/Win32Styling.php';

// ---- Rendering Backend (RuntimeBackendSelector 依赖) ----
require_once $frameworkDir . '/Rendering/Backend/BackendCapability.php';
require_once $frameworkDir . '/Rendering/Backend/BackendInitException.php';
require_once $frameworkDir . '/Rendering/Backend/IRenderBackend.php';
require_once $frameworkDir . '/Rendering/Backend/BackendRegistry.php';
require_once $frameworkDir . '/Rendering/Backend/RenderBackendFailedException.php';
require_once $frameworkDir . '/Rendering/Backend/GdiLegacyBackend.php';
require_once $frameworkDir . '/Rendering/Backend/GdiDirect2DBackend.php';
require_once $frameworkDir . '/Rendering/Backend/SkiaCpuBackend.php';
require_once $frameworkDir . '/Rendering/Backend/SkiaGaneshD3D11Backend.php';
require_once $frameworkDir . '/Rendering/Backend/SkiaGaneshWGLBackend.php';
require_once $frameworkDir . '/Rendering/Backend/SkiaGraphiteDawnBackend.php';
require_once $frameworkDir . '/Rendering/Backend/RuntimeBackendSelector.php';
require_once $frameworkDir . '/Rendering/Backend/ResilientRenderContext.php';

// ---- TextBackend（文本引擎接口抽象）----
require_once $frameworkDir . '/Rendering/TextBackend/ITextBackend.php';
require_once $frameworkDir . '/Rendering/TextBackend/TextBackendRegistry.php';
require_once $frameworkDir . '/Rendering/TextBackend/TextBackendSelector.php';
require_once $frameworkDir . '/Rendering/TextBackend/ResilientTextBackendProxy.php';
require_once $frameworkDir . '/Rendering/TextBackend/DWriteTextBackend.php';
require_once $frameworkDir . '/Rendering/TextBackend/SkiaTextBackend.php';
require_once $frameworkDir . '/Rendering/TextBackend/GdiTextBackend.php';

// ---- 测试框架（全局计数器+test/assert函数+Jest风格扩展）----
require_once __DIR__ . '/test-framework.php';
