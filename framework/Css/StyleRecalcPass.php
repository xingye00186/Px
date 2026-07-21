<?php

namespace Px\Css;
use Px\Render\RenderTreeManager;

use native_types;

use Px\Dom\VNode;

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

        $inlineStyle = $root->props['style'] ?? [];
        $className = $root->props['class'] ?? '';

        // #text 节点无样式，用父样式直接构造最小 ComputedStyle，跳过 StyleResolver
        if ($root->type === '#text') {
            $root->computedStyle = new ComputedStyle($parentStyle);
            \Px\Core\PerfCounter::inc('style_recalc_text_skip');
            return;
        }

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
