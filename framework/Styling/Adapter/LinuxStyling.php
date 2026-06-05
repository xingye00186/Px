<?php
declare(strict_types=1);

namespace Px\Styling\Adapter;

use native_types;

use Px\Styling\Theme\ThemeData;

/**
 * Linux 平台样式适配。
 *
 * 按钮默认高度 32px，字体 Noto Sans，滚动条宽度 10px。
 */
class LinuxStyling extends PlatformStyling
{
    public function apply(ThemeData $theme): ThemeData
    {
        $ct = $theme->componentTheme;

        $ct = $ct->withStyle('button', [
            'height'   => 32,
            'fontSize' => 14,
            'bold'     => 0,
            'padding'  => 8,
        ]);

        $ct = $ct->withStyle('input', [
            'height'   => 30,
            'fontSize' => 14,
            'padding'  => 4,
        ]);

        $ct = $ct->withStyle('scrollbar', [
            'width'      => 10,
            'thumbColor' => ThemeData::rgbToBgr(0x888888),
            'trackColor' => ThemeData::rgbToBgr(0xE0E0E0),
        ]);

        $ct = $ct->withStyle('body', [
            'fontFamily' => 'Noto Sans',
            'fontSize'   => 14,
        ]);

        return $theme->copyWith(['componentTheme' => $ct]);
    }

    public function getScrollbarSize(): int
    {
        return 10;
    }

    public function getDefaultFontFamily(): string
    {
        return 'Noto Sans';
    }
}
