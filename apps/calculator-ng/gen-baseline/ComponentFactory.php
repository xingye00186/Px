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
            case 'CalculatorDisplayComponent':
                $comp = any(new CalculatorDisplayComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'MemoryBarComponent':
                $comp = any(new MemoryBarComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'ScientificPadComponent':
                $comp = any(new ScientificPadComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'BasicPadComponent':
                $comp = any(new BasicPadComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'HistoryPanelComponent':
                $comp = any(new HistoryPanelComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            default:
                throw new \RuntimeException("Component not found: $className");
        }
        return $comp;
    }
}
