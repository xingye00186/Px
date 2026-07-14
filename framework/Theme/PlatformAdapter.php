<?php
declare(strict_types=1);

namespace Px\Theme;

use native_types;

use Px\Theme\ThemeData;

/**
 * 平台适配器工厂。
 * 根据平台标识（'win32', 'macos', 'linux'）创建对应的 PlatformStyling 实例。
 */
class PlatformAdapter
{
    /**
     * @param string    $platform   平台标识
     * @param ThemeData $baseTheme  基础主题
     * @return PlatformStyling
     */
    public static function create(string $platform, ThemeData $baseTheme): Win32Styling
    {
        // AOT native_types 下不能用 match + 抽象基类返回类型（vtable 崩溃）
        // 因此直接返回具体类 Win32Styling
        return new Win32Styling($baseTheme);
    }
}
