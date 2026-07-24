<?php

use Px\Dom\VNode;

/**
 * StyleArrayTransform — 样式数组化变换
 *
 * 遍历 VNode 树，将静态的 style 字符串和动态 :style 表达式
 * 转换为 PHP 数组格式，减少运行时解析开销。
 *
 * 提取自 sfc-compiler.php 的 tryConvertStyleToArray() + generateVNodeExpr() 中的内联 style→array 转换。
 */
class StyleArrayTransform implements TransformInterface
{
    public function transform(VNode $root, array &$metadata): void
    {
        $this->walk($root);
    }

    private function walk(VNode $node): void
    {
        if ($node->props === null) {
            // 仍需要递归子节点（即使 props 为 null）
            $this->walkChildren($node);
            return;
        }

        $props = &$node->props;

       // 转换静态 style: "color:red;font-size:14px" → ['color'=>'red','font-size'=>'14px']
        if (isset($props['style'])) {
            if (is_string($props['style']) && $props['style'] !== '') {
                $style = $props['style'];
                // 已经是 PHP 表达式（以 $ 开头）则不转换
                if ($style[0] !== '$' && $style[0] !== '(') {
                    $converted = $this->convertStaticStyle($style);
                    if ($converted !== null) {
                        $props['style'] = $converted;
                    }
                }
            }
            // 空 style → 删除（运行时用 ?? [] 兜底，避免传 string 给 StyleResolver）
            if ($props['style'] === '' || $props['style'] === []) {
                unset($props['style']);
            }
        }

        // 对组件占位符也做 style 数组化
        if ($node->isComponent) {
            // 不需要递归子节点
            return;
        }

        $this->walkChildren($node);
    }

    /**
     * 递归处理子节点。
     */
    private function walkChildren(VNode $node): void
    {
        if (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $this->walk($child);
                }
            }
        } elseif ($node->children instanceof VNode) {
            $this->walk($node->children);
        }
    }

    /**
     * 将静态 CSS 字符串转换为 PHP 数组表达式字符串。
     * 输入: "color:red;font-size:14px"
     * 输出: ['color'=>'red','font-size'=>'14px']
     */
    private function convertStaticStyle(string $style): ?string
    {
        $decls = explode(';', $style);
        $raw = [];
        $valid = true;
        foreach ($decls as $decl) {
            $decl = trim($decl);
            if ($decl === '') continue;
            $colonPos = strpos($decl, ':');
            if ($colonPos === false) { $valid = false; break; }
            $prop = strtolower(trim(substr($decl, 0, $colonPos)));
            $val = trim(substr($decl, $colonPos + 1));
            $raw[$prop] = $val;
        }
        if (!$valid || empty($raw)) return null;

        // 一套代码，两处使用：与 StyleResolver 运行时共用同一套简写展开 + key 归一化
        $raw = \Px\Css\CssShorthandExpander::expandAll($raw, false);

        $pairs = [];
        foreach ($raw as $prop => $val) {
            // 统一 key 归一化（kebab → camelCase）
            $canonicalKey = \Px\Css\CssMappings::canonicalStyleKey($prop);
            $pairs[] = var_export($canonicalKey, true) . '=>' . var_export($val, true);
        }
        if (!empty($pairs)) {
            return '[' . implode(',', $pairs) . ']';
        }
        return null;
    }
}
