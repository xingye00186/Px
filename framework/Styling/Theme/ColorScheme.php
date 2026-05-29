<?php
declare(strict_types=1);

namespace Px\Styling\Theme;

/**
 * 颜色令牌集合，遵循 Material Design 颜色系统。
 *
 * 所有颜色值使用 RGB 格式整数存储（例如 0x1976D2 表示 #1976D2），
 * 此为人类可读格式。GDI 渲染需要 BGR 格式时，由 ThemeData::rgbToBgr() 转换。
 */
class ColorScheme
{
    public int $primary;
    public int $onPrimary;
    public int $primaryContainer;
    public int $onPrimaryContainer;
    public int $secondary;
    public int $onSecondary;
    public int $secondaryContainer;
    public int $onSecondaryContainer;
    public int $tertiary;
    public int $onTertiary;
    public int $background;
    public int $onBackground;
    public int $surface;
    public int $onSurface;
    public int $surfaceVariant;
    public int $onSurfaceVariant;
    public int $error;
    public int $onError;
    public int $outline;
    public int $shadow;

    /**
     * @param array $colors 可选的覆盖值，键名为属性名，值为 RGB 格式整数
     */
    public function __construct(array $colors = [])
    {
        // Material Design 3 浅色主题默认值
        $this->primary            = $colors['primary']            ?? 0x1976D2;
        $this->onPrimary          = $colors['onPrimary']          ?? 0xFFFFFF;
        $this->primaryContainer   = $colors['primaryContainer']   ?? 0xE3F2FD;
        $this->onPrimaryContainer = $colors['onPrimaryContainer'] ?? 0x0D47A1;
        $this->secondary          = $colors['secondary']          ?? 0x9C27B0;
        $this->onSecondary        = $colors['onSecondary']        ?? 0xFFFFFF;
        $this->secondaryContainer = $colors['secondaryContainer'] ?? 0xF3E5F5;
        $this->onSecondaryContainer = $colors['onSecondaryContainer'] ?? 0x6A1B9A;
        $this->tertiary           = $colors['tertiary']           ?? 0x00897B;
        $this->onTertiary         = $colors['onTertiary']         ?? 0xFFFFFF;
        $this->background         = $colors['background']         ?? 0xF5F5F5;
        $this->onBackground       = $colors['onBackground']       ?? 0x000000;
        $this->surface            = $colors['surface']            ?? 0xFFFFFF;
        $this->onSurface          = $colors['onSurface']          ?? 0x000000;
        $this->surfaceVariant     = $colors['surfaceVariant']     ?? 0xE7E0EC;
        $this->onSurfaceVariant   = $colors['onSurfaceVariant']   ?? 0x49454F;
        $this->error              = $colors['error']              ?? 0xB00020;
        $this->onError            = $colors['onError']            ?? 0xFFFFFF;
        $this->outline            = $colors['outline']            ?? 0x79747E;
        $this->shadow             = $colors['shadow']             ?? 0x000000;
    }

    public static function light(): self
    {
        return new self();
    }

    public static function dark(): self
    {
        return new self([
            'primary'            => 0xBB86FC,
            'onPrimary'          => 0x000000,
            'primaryContainer'   => 0x3700B3,
            'onPrimaryContainer' => 0xE3F2FD,
            'secondary'          => 0x03DAC6,
            'onSecondary'        => 0x000000,
            'secondaryContainer' => 0x005457,
            'onSecondaryContainer' => 0x03DAC6,
            'tertiary'           => 0x80DEEA,
            'onTertiary'         => 0x000000,
            'background'         => 0x121212,
            'onBackground'       => 0xFFFFFF,
            'surface'            => 0x1E1E1E,
            'onSurface'          => 0xFFFFFF,
            'surfaceVariant'     => 0x49454F,
            'onSurfaceVariant'   => 0xCAC4D0,
            'error'              => 0xCF6679,
            'onError'            => 0x000000,
            'outline'            => 0x938F99,
            'shadow'             => 0x000000,
        ]);
    }
}
