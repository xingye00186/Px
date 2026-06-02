<?php

use Px\Interfaces\ComponentInterface;

/**
 * AOT 组件工厂
 */
class ComponentFactory
{
    /**
     * @param class-string $className
     * @param array<string, mixed> $props
     * @return ComponentInterface
     */
    public static function create(string $className, array $props = []): ComponentInterface
    {
        switch ($className) {
            case 'AppComponent':
                $comp = any(new AppComponent());
                if (method_exists($comp, 'setProps')) {
                    $comp->setProps($props);
                }
                break;
            default:
                throw new \RuntimeException("Component not found: {$className}");
        }
        return $comp;
    }
}
