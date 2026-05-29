<?php
declare(strict_types=1);

namespace Px\Styling\Theme;

/**
 * 主题唯一数据源，包含颜色、字体、组件样式。
 *
 * 支持向上查找（parent 指针），模拟 Flutter 的 Theme.of(context) 继承机制。
 * ColorScheme 以 RGB 格式存储，通过 rgbToBgr() 在 getComponentStyle() 合成默认值时转换为 BGR。
 */
class ThemeData
{
    public ColorScheme $colorScheme;
    public TextTheme $textTheme;
    public ComponentTheme $componentTheme;
    public ?string $platform;   // 'win32', 'macos', 'linux'
    public bool $isDark;

    private ?ThemeData $parent = null;

    public function __construct(array $config = [])
    {
        $this->colorScheme    = $config['colorScheme']    ?? ColorScheme::light();
        $this->textTheme      = $config['textTheme']      ?? TextTheme::default();
        $this->componentTheme = $config['componentTheme'] ?? new ComponentTheme();
        $this->platform       = $config['platform']       ?? null;
        $this->isDark         = $config['isDark']         ?? false;
    }

    /**
     * RGB → BGR 转换（GDI COLORREF 格式）。
     * 例如 0xRRGGBB → 0xBBGGRR。
     */
    public static function rgbToBgr(int $rgb): int
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return ($b << 16) | ($g << 8) | $r;
    }

    public static function light(): self
    {
        return new self();
    }

    public static function dark(): self
    {
        $dark = new self(['isDark' => true]);
        $dark->colorScheme = ColorScheme::dark();
        return $dark;
    }

    /**
     * 创建当前主题的浅拷贝，允许覆盖部分属性。
     * parent 指针指向当前实例（形成继承链）。
     *
     * 支持覆盖属性：colorScheme, textTheme, componentTheme, platform, isDark
     */
    public function copyWith(array $overrides): self
    {
        $clone = clone $this;
        $clone->parent = $this;
        if (array_key_exists('colorScheme', $overrides)) {
            $clone->colorScheme = $overrides['colorScheme'];
        }
        if (array_key_exists('textTheme', $overrides)) {
            $clone->textTheme = $overrides['textTheme'];
        }
        if (array_key_exists('componentTheme', $overrides)) {
            $clone->componentTheme = $overrides['componentTheme'];
        }
        if (array_key_exists('platform', $overrides)) {
            $clone->platform = $overrides['platform'];
        }
        if (array_key_exists('isDark', $overrides)) {
            $clone->isDark = $overrides['isDark'];
        }
        return $clone;
    }

    /**
     * 设置父主题（用于继承查找）。
     */
    public function setParent(?ThemeData $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * 获取父主题。
     */
    public function getParent(): ?ThemeData
    {
        return $this->parent;
    }

    /**
     * 获取组件类型/类名对应的样式。
     *
     * 查找顺序：
     *   1. 当前 ComponentTheme 中查找
     *   2. 父主题 ComponentTheme 中向上查找
     *   3. 合成 ColorScheme + TextTheme 默认值
     *
     * @param string $componentType 组件类型或 CSS 类名
     * @return array 样式数组（所有颜色为 BGR 格式）
     */
    public function getComponentStyle(string $componentType): array
    {
        // 1. 当前 ComponentTheme
        $style = $this->componentTheme->get($componentType);
        if ($style !== []) {
            return $style;
        }

        // 2. 父主题 ComponentTheme
        if ($this->parent !== null) {
            $parentStyle = $this->parent->getComponentStyle($componentType);
            if ($parentStyle !== []) {
                return $parentStyle;
            }
        }

        // 3. 根据 ColorScheme + TextTheme 合成默认值
        return $this->synthesizeDefaults();
    }

    /**
     * 从 ColorScheme 和 TextTheme 合成默认样式值。
     * 颜色值在此处从 RGB 转换为 BGR。
     *
     * @return array
     */
    private function synthesizeDefaults(): array
    {
        return [
            'bg'       => self::rgbToBgr($this->colorScheme->surface),
            'fg'       => self::rgbToBgr($this->colorScheme->onSurface),
            'fontSize' => $this->textTheme->bodyMedium,
            'bold'     => 0,
        ];
    }
}
