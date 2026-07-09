<?php

namespace Px\Rendering;

use native_types;

use Px\Rendering\Layout\StyleResolver;

/**
 * StyleRecalcPass — 独立样式重算通行证
 *
 * Phase 0.5 产物：将样式解析从 RenderTreeManager 中抽离为独立阶段。
 * 在 Application::render() 管线第 2 步调用，产出 ComputedStyle 写入 VNode。
 *
 * 当前为自包含实现：递归遍历 VNode 树，为每个元素解析样式。
 * 后续可替换为增量样式重算。
 */
class StyleRecalcPass
{
    /**
     * 对 VNode 树执行全量样式重算。
     * 结果存储在 VNode 的 computedStyle 字段中。
     *
     * @param VNode $root 根 VNode
     * @param array $parentStyle 父级样式声明（CSS 继承）
     * @param string $parentClassStr 父级类名（后代选择器用）
     */
    public function recalc(VNode $root, array $parentStyle = [], string $parentClassStr = ''): void
    {
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

        // 将解析后的样式存入 VNode（RenderTreeManager 直接读取）
        $root->computedStyle = $computedStyle;

        $resolvedStyle = $computedStyle->toExportArray();

        // 递归子节点
        $children = is_array($root->children) ? $root->children : [];
        foreach ($children as $child) {
            if ($child instanceof VNode) {
                $this->recalc($child, $resolvedStyle, $className);
            }
        }
    }
}
