<?php
declare(strict_types=1);

namespace Px\Styling\Adapter;

use native_types;

use Px\Styling\Theme\ComponentTheme;
use Px\Styling\Theme\ThemeData;

/**
 * Win32 (Windows) 平台样式适配。
 *
 * 按钮默认高度 32px，字体 Segoe UI / Microsoft YaHei，滚动条宽度 12px。
 */
class Win32Styling extends PlatformStyling
{
    public function apply(ThemeData $theme): ThemeData
    {
        $ct = $theme->componentTheme;

        // button 默认样式
        $ct = $ct->withStyle('button', [
            'height'   => 32,
            'fontSize' => 14,
            'bold'     => 0,
            'padding'  => 8,  // 0 16px → 左右各 8px
        ]);

        // input 默认样式
        $ct = $ct->withStyle('input', [
            'height'   => 30,
            'fontSize' => 14,
            'padding'  => 4,
        ]);

        // scrollbar 默认样式
        $ct = $ct->withStyle('scrollbar', [
            'width'      => 12,
            'thumbColor' => ThemeData::rgbToBgr(0x888888),
            'trackColor' => ThemeData::rgbToBgr(0xE0E0E0),
        ]);

        // body 全局默认
        $ct = $ct->withStyle('body', [
            'fontFamily' => 'Microsoft YaHei',
            'fontSize'   => 14,
        ]);

        return $theme->copyWith(['componentTheme' => $ct]);
    }

    public function getScrollbarSize(): int
    {
        return 12;
    }

    public function getDefaultFontFamily(): string
    {
        return 'Microsoft YaHei';
    }
}
