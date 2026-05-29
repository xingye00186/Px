<?php
declare(strict_types=1);

namespace Px\Styling\Theme;

/**
 * 存储每个组件类型或 CSS 类名的默认样式。
 *
 * 键可以是组件类型（'button', 'input', 'div'）或 CSS 类名（'btn-primary'）。
 * 值为样式数组，包含 'bg', 'fg', 'fontSize', 'bold', 'width', 'height', 'padding' 等。
 *
 * 所有颜色值必须为 BGR 格式（GDI 可直接使用）。
 * 采用不可变模式 — withStyle() 返回新实例，便于 ThemeData.copyWith() 链式调用。
 */
class ComponentTheme
{
    /** @var array<string, array> */
    private array $styles = [];

    /**
     * @param array<string, array> $styles 初始样式映射
     */
    public function __construct(array $styles = [])
    {
        $this->styles = $styles;
    }

    /**
     * 获取指定键的样式。
     *
     * @param string $key 组件类型或 CSS 类名
     * @return array 样式数组，未找到则返回空数组
     */
    public function get(string $key): array
    {
        return $this->styles[$key] ?? [];
    }

    /**
     * 返回所有样式（不可变副本）。
     *
     * @return array<string, array>
     */
    public function getAll(): array
    {
        return $this->styles;
    }

    /**
     * 不可变地添加/覆盖样式，返回新实例。
     *
     * @param string $key   组件类型或 CSS 类名
     * @param array  $style 样式键值对（BGR 格式颜色）
     * @return self 新实例
     */
    public function withStyle(string $key, array $style): self
    {
        $new = clone $this;
        $new->styles[$key] = $style;
        return $new;
    }

    /**
     * 合并另一个 ComponentTheme，返回新实例。
     * 重复的键由 $other 覆盖。
     *
     * @param ComponentTheme $other 要合并的其他主题
     * @return self 新实例
     */
    public function merge(ComponentTheme $other): self
    {
        $new = clone $this;
        foreach ($other->styles as $key => $style) {
            $new->styles[$key] = $style;
        }
        return $new;
    }
}
