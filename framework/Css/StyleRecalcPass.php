<?php

namespace Px\Css;

use native_types;

use Px\Css\StyleResolver;

/**
 * StyleRecalcPass — 独立样式重算通行证
 *
 * Phase 0.5 产物：将样式解析从 RenderTreeManager 中抽离为独立阶段。
 */
class StyleRecalcPass
{
    public function recalc(VNode $root, array $parentStyle = [], string $parentClassStr = ''): void
    {
        if ($root->isComponent) {
            // Component 节点不直接渲染，展开后由子组件管理
            return;
        }

        $inlineStyle = $root->props['style'] ?? '';
        $className = $root->props['class'] ?? '';

        $pseudoStyles = [];
        $computedStyle = StyleResolver::resolve(
            inlineStyle: $inlineStyle,
            className: $className,
            parentDeclarations: $parentStyle,
            elementType: $root->type,
            parentClassStr: $parentClassStr,
            precedingSiblingClasses: [],
            parentStyleDeclarations: $parentStyle,
            pseudoStyles: $pseudoStyles
        );

        $root->computedStyle = $computedStyle;
        $resolvedStyle = $computedStyle->toExportArray();

        $children = is_array($root->children) ? $root->children : [];
        foreach ($children as $child) {
            if ($child instanceof VNode) {
                $this->recalc($child, $resolvedStyle, $className);
            }
        }
    }
}
