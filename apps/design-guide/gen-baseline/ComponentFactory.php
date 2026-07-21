<?php

/**
 * ComponentFactory - 组件工厂类 (v9)
 * 由 SFC 编译器自动生成，使用 switch-case 创建组件实例。
 * v9: 仅包含实际使用的组件（按需编译）。
 */
use Px\Component\Contracts\ComponentInterface;

class ComponentFactory
{
    public static function create(string $className, array $props = []): ComponentInterface
    {
        switch ($className) {
            case 'AppComponent':
                $comp = any(new AppComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'OverviewComponent':
                $comp = any(new OverviewComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'ColorPaletteComponent':
                $comp = any(new ColorPaletteComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'ComponentsShowcaseComponent':
                $comp = any(new ComponentsShowcaseComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'LayoutDemoComponent':
                $comp = any(new LayoutDemoComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'ScrollShowcaseComponent':
                $comp = any(new ScrollShowcaseComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'AnimationShowcaseComponent':
                $comp = any(new AnimationShowcaseComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            default:
                throw new \RuntimeException("Component not found: $className");
        }
        return $comp;
    }
}
