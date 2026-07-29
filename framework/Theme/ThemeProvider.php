<?php
declare(strict_types=1);

namespace Px\Theme;

use native_types;

/**
 * 编译后 class styles 注册表（C1.5 瘦身）。
 *
 * 原 Flutter 式主题族（ThemeData/ColorScheme/forSubtree 子树覆盖栈）为
 * 生产僵尸（零消费者，见审计 §2.1），C1.5 随 ThemeData 族 9 文件一并删除，
 * 本类瘦身为纯 classStyleRegistry。运行时 class→style 注册通道在生产
 * 恒空（编译期烘焙已完成），仅测试侧（Level-25 + 单测）作被测物；
 * C2.9 由 StyleSheetContents 注册 API 取代后本文件整体删除。
 *
 * 命名注记（§8.1）：ThemeProvider 名称在 Blink 无占用冲突，C2.9 删除前保留。
 */
class ThemeProvider
{
    /** @var array<string, array> ComponentClassName → ['classKey' => [...props...]] */
    private static array $classStyleRegistry = [];

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
