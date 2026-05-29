<?php
declare(strict_types=1);

namespace Px\Styling\Adapter;

use Px\Styling\Theme\ThemeData;

/**
 * macOS 平台样式适配。
 *
 * 按钮默认高度 28px，字体 SF Pro Text，滚动条宽度 8px。
 */
class MacOSStyling extends PlatformStyling
{
    public function apply(ThemeData $theme): ThemeData
    {
        $ct = $theme->componentTheme;

        $ct = $ct->withStyle('button', [
            'height'   => 28,
            'fontSize' => 13,
            'bold'     => 0,
            'padding'  => 7,  // 0 14px → 左右各 7px
        ]);

        $ct = $ct->withStyle('input', [
            'height'   => 28,
            'fontSize' => 13,
            'padding'  => 4,
        ]);

        $ct = $ct->withStyle('scrollbar', [
            'width'      => 8,
            'thumbColor' => ThemeData::rgbToBgr(0xAAAAAA),
            'trackColor' => ThemeData::rgbToBgr(0xE5E5E5),
        ]);

        $ct = $ct->withStyle('body', [
            'fontFamily' => 'SF Pro Text',
            'fontSize'   => 13,
        ]);

        return $theme->copyWith(['componentTheme' => $ct]);
    }

    public function getScrollbarSize(): int
    {
        return 8;
    }

    public function getDefaultFontFamily(): string
    {
        return 'SF Pro Text';
    }
}
