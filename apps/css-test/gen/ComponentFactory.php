<?php

/**
 * ComponentFactory - 组件工厂类 (v9)
 * 由 SFC 编译器自动生成，使用 switch-case 创建组件实例。
 * v9: 仅包含实际使用的组件（按需编译）。
 */
use Px\Interfaces\ComponentInterface;

class ComponentFactory
{
    public static function create(string $className, array $props = []): ComponentInterface
    {
        switch ($className) {
            case 'AppComponent':
                $comp = any(new AppComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case001WrapperXComponent':
                $comp = any(new Case001WrapperXComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case002AutoHeightComponent':
                $comp = any(new Case002AutoHeightComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case027ScrollDiagnosticComponent':
                $comp = any(new Case027ScrollDiagnosticComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            default:
                throw new \RuntimeException("Component not found: $className");
        }
        return $comp;
    }
}
