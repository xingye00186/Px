<?php
declare(strict_types=1);

namespace Px\Styling\Provider;

use Px\Styling\Theme\ThemeData;

/**
 * 全局主题提供器，单例模式。
 *
 * 支持局部子树覆盖（模拟 Flutter 的 Theme.of(context)）：
 *   forSubtree() 压入新主题 → 在该组件渲染期间所有节点可见此主题
 *   restore() 恢复上一级主题
 *
 * 同时管理编译后的 class styles 注册表。
 */
class ThemeProvider
{
    private static ?ThemeData $rootTheme = null;
    private static array $themeStack = [];
    /** @var array<string, array> ComponentClassName → ['classKey' => [...props...]] */
    private static array $classStyleRegistry = [];

    /**
     * 注入（覆盖）根主题。
     */
    public static function inject(ThemeData $theme): void
    {
        self::$rootTheme = $theme;
    }

    /**
     * 获取当前上下文中的主题。
     * 始终返回有效 ThemeData（未注入时默认浅色主题）。
     */
    public static function of(): ThemeData
    {
        if (count(self::$themeStack) > 0) {
            return self::$themeStack[count(self::$themeStack) - 1];
        }
        if (self::$rootTheme === null) {
            self::$rootTheme = ThemeData::light();
        }
        return self::$rootTheme;
    }

    /**
     * 为子树创建主题覆盖。
     * 调用后当前主题变为 $subtreeTheme（其 parent 指向旧主题）。
     * 完成后必须调用 restore() 恢复。
     */
    public static function forSubtree(ThemeData $subtreeTheme): void
    {
        $subtreeTheme->setParent(self::of());
        self::$themeStack[] = $subtreeTheme;
    }

    /**
     * 恢复上一级主题。
     */
    public static function restore(): void
    {
        if (count(self::$themeStack) > 0) {
            array_pop(self::$themeStack);
        }
    }

    /**
     * 注册编译后的 class styles。
     *
     * @param string $className  组件类名
     * @param array  $classStyles class → properties 映射
     */
    public static function registerClassStyles(string $className, array $classStyles): void
    {
        self::$classStyleRegistry[$className] = $classStyles;
    }

    /**
     * 获取指定组件注册的编译后 class styles。
     *
     * @param string $className 组件类名
     * @return array class → properties 映射，未注册则返回空数组
     */
    public static function getClassStyles(string $className): array
    {
        return self::$classStyleRegistry[$className] ?? [];
    }

    /**
     * 获取所有已注册的编译后 class styles。
     *
     * @return array<string, array>
     */
    public static function getAllClassStyles(): array
    {
        return self::$classStyleRegistry;
    }
}
