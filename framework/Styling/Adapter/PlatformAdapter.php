<?php
declare(strict_types=1);

namespace Px\Styling\Adapter;

use Px\Styling\Theme\ThemeData;

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
    public static function create(string $platform, ThemeData $baseTheme): PlatformStyling
    {
        return match ($platform) {
            'win32'  => new Win32Styling($baseTheme),
            'macos'  => new MacOSStyling($baseTheme),
            'linux'  => new LinuxStyling($baseTheme),
            default  => new Win32Styling($baseTheme),
        };
    }
}
