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
            case 'Case003BasicBlockComponent':
                $comp = any(new Case003BasicBlockComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case004FlexLayoutComponent':
                $comp = any(new Case004FlexLayoutComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case005GridLayoutComponent':
                $comp = any(new Case005GridLayoutComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case006TypographyComponent':
                $comp = any(new Case006TypographyComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case007BorderStylesComponent':
                $comp = any(new Case007BorderStylesComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case008BoxShadowComponent':
                $comp = any(new Case008BoxShadowComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case009OutlineComponent':
                $comp = any(new Case009OutlineComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case010DisplayNoneComponent':
                $comp = any(new Case010DisplayNoneComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case011PositionAbsoluteComponent':
                $comp = any(new Case011PositionAbsoluteComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case012PositionRelativeComponent':
                $comp = any(new Case012PositionRelativeComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case013ZIndexComponent':
                $comp = any(new Case013ZIndexComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case014OverflowHiddenComponent':
                $comp = any(new Case014OverflowHiddenComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case015MinMaxHeightComponent':
                $comp = any(new Case015MinMaxHeightComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case016MarginCollapseComponent':
                $comp = any(new Case016MarginCollapseComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case017NegativeMarginComponent':
                $comp = any(new Case017NegativeMarginComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case018OpacityComponent':
                $comp = any(new Case018OpacityComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case019VisibilityComponent':
                $comp = any(new Case019VisibilityComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case020TextAlignComponent':
                $comp = any(new Case020TextAlignComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case021LineHeightComponent':
                $comp = any(new Case021LineHeightComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case022WhiteSpaceComponent':
                $comp = any(new Case022WhiteSpaceComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case023WordBreakComponent':
                $comp = any(new Case023WordBreakComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case024FontWeightComponent':
                $comp = any(new Case024FontWeightComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case025EnglishTextComponent':
                $comp = any(new Case025EnglishTextComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case026FontStyleComponent':
                $comp = any(new Case026FontStyleComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case027ScrollDiagnosticComponent':
                $comp = any(new Case027ScrollDiagnosticComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case028ScrollBlockComponent':
                $comp = any(new Case028ScrollBlockComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case029ScrollFlexColComponent':
                $comp = any(new Case029ScrollFlexColComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case030ScrollFlexRowComponent':
                $comp = any(new Case030ScrollFlexRowComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case031ScrollGridComponent':
                $comp = any(new Case031ScrollGridComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            case 'Case032ScrollRelativeComponent':
                $comp = any(new Case032ScrollRelativeComponent());
                if (method_exists($comp, 'setProps')) { $comp->setProps($props); }
                break;
            default:
                throw new \RuntimeException("Component not found: $className");
        }
        return $comp;
    }
}
