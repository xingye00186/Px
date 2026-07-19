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

        // 合并静态 style + 动态 :style（:style 优先级高于 style，对标 Vue 3 模板语义）
        $inlineStyle = $root->props['style'] ?? '';
        $dynamicStyle = $root->props[':style'] ?? '';
        if ($dynamicStyle !== '') {
            // :style 是 CSS 字符串（SFC 编译器已将其编译为 'background:#a6e3a1;' 格式）
            // 拼接后 CSS 层叠序确保 :style 覆盖同名的静态 style 属性
            $inlineStyle = $inlineStyle !== ''
                ? $inlineStyle . ';' . $dynamicStyle
                : $dynamicStyle;
        }

        // 合并 HTML align 属性到 inlineStyle
        // CSS 2.2 §7.1: text-align 可被 HTML align 属性设置，但 CSS 显式声明优先
        if (($root->props['align'] ?? '') !== '') {
            $inlineStyle .= ';text-align:' . $root->props['align'];
        }

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
