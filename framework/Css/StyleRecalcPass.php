<?php

namespace Px\Css;
use Px\Render\RenderTreeManager;

use native_types;

use Px\Dom\VNode;

use Px\Css\InlineStyleParser;

/**
 * StyleRecalcPass — 独立样式重算通行证
 *
 * Phase 0.5 产物：将样式解析从 RenderTreeManager 中抽离为独立阶段。
 */
class StyleRecalcPass
{
    public function recalc(VNode $root, ?ComputedStyle $parentCS = null, string $parentClassStr = '', array $precedingSiblingClasses = []): void
    {
        if ($root->isComponent) {
            // Component 节点不直接渲染，展开后由子组件管理
            return;
        }

        $inlineStyle = $root->props['style'] ?? [];
        // 运行时 style 字符串（动态构建的 VNode，如测试 harness）需解析为声明数组；
        // 编译期数组化路径（SFC 组件）已是数组，直接使用。与 RenderTreeManager
        // 处理 placeholder style 的 is_string ? parseInlineStyle : ... 逻辑一致。
        if (is_string($inlineStyle)) {
            $inlineStyle = $inlineStyle !== '' ? InlineStyleParser::parseInlineStyle($inlineStyle) : [];
        } elseif (!is_array($inlineStyle)) {
            $inlineStyle = [];
        }
        $className = $root->props['class'] ?? '';

        // #text 节点无自身样式：直接复用父 ComputedStyle（identity 稳定，避开估算开销）
        // 若无父（顶层预防护理论上不该发生），够用 empty 单例兑底
        if ($root->type === '#text') {
            $root->computedStyle = $parentCS ?? StylePool::empty();
            \Px\Core\PerfCounter::inc('style_recalc_text_skip');
            return;
        }

        $pseudoStyles = [];
        $computedStyle = InlineStyleParser::resolve(
            inlineStyle: $inlineStyle,
            className: $className,
            parentCS: $parentCS,
            elementType: $root->type,
            parentClassStr: $parentClassStr,
            // C2.4：传真实前序兄弟 class（修§1.3.3 运行时兄弟组合子 +/~
            // 恒传空失效）；由父级子循环按文档序累积传入。
            precedingSiblingClasses: $precedingSiblingClasses,
            pseudoStyles: $pseudoStyles
        );

        $root->computedStyle = $computedStyle;

        $children = is_array($root->children) ? $root->children : [];
        // 前序兄弟 class 串累积（文档序）：供子层运行时兄弟组合子匹配。
        $siblingAcc = [];
        foreach ($children as $child) {
            if ($child instanceof VNode) {
                // 递归直传父 ComputedStyle 对象（O(1) 身份），不再传 toExportArray()
                $this->recalc($child, $computedStyle, $className, $siblingAcc);
                $cc = $child->props['class'] ?? '';
                if (is_string($cc) && $cc !== '') {
                    $siblingAcc[] = $cc;
                }
            }
        }
    }
}
