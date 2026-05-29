<?php
declare(strict_types=1);

namespace Px\Styling\Adapter;

use Px\Styling\Theme\ThemeData;

/**
 * 平台样式适配器抽象基类。
 *
 * 每个平台通过 apply() 注入平台特定的组件默认样式到主题中。
 */
abstract class PlatformStyling
{
    protected ThemeData $baseTheme;

    public function __construct(ThemeData $baseTheme)
    {
        $this->baseTheme = $baseTheme;
    }

    /**
     * 注入平台特定样式到主题中，并返回新的主题。
     */
    abstract public function apply(ThemeData $theme): ThemeData;

    /**
     * 获取平台默认滚动条宽度（px）。
     */
    abstract public function getScrollbarSize(): int;

    /**
     * 获取平台默认字体族。
     */
    abstract public function getDefaultFontFamily(): string;
}
