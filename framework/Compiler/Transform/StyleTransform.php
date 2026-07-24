<?php

use Px\Dom\VNode;

/**
 * StyleTransform — 样式处理强制关卡（SFC 编译管线唯一 style 处理入口）
 *
 * 架构原则：
 *   所有 style 产出在离开编译器前**必须且仅需**经过此 Transform。
 *   一次遍历完成三项职责：
 *     1. 静态 style 字符串 → PHP 数组字面量（消除运行时 regex 解析）
 *     2. CssShorthandExpander::expandAll（简写展开：padding→4方向、border、overflow、gap 等）
 *     3. CssMappings::canonicalStyleKey（key 归一化：kebab→camelCase）
 *
 *   与 StyleResolver 运行时共用同一套展开逻辑（CssShorthandExpander），保证：
 *     - 编译期产出 = 运行时解析产出（行为一致性）
 *     - 新增 codegen 路径无需记得调展开（此 Transform 保底覆盖）
 *     - 一套代码，两处调用（SFC 编译期 + StyleResolver 运行时）
 *
 * 对标 Blink: CSSParser 内部强制展开所有简写，下游拿到的永远是 longhand。
 *
 * 前身：StyleArrayTransform + StyleNormalizeTransform（已合并为单一关卡）
 */
class StyleTransform implements TransformInterface
{
    public function transform(VNode $root, array &$metadata): void
    {
        $this->walk($root);
    }

    private function walk(VNode $node): void
    {
        if ($node->props === null) {
            $this->walkChildren($node);
            return;
        }

        $props = &$node->props;

        // 转换静态 style: "color:red;font-size:14px" → ['fg'=>'red','fontSize'=>'14px']
        if (isset($props['style'])) {
            if (is_string($props['style']) && $props['style'] !== '') {
                $style = $props['style'];
                // PHP 表达式（$ 或 ( 开头）保持不变——运行时由 StyleResolver 处理
                if ($style[0] !== '$' && $style[0] !== '(') {
                    $converted = $this->compileStaticStyle($style);
                    if ($converted !== null) {
                        $props['style'] = $converted;
                    }
                }
            }
            // 空 style 删除（运行时用 ?? [] 兜底）
            if ($props['style'] === '' || $props['style'] === []) {
                unset($props['style']);
            }
        }

        // 组件占位符不需要递归子节点
        if ($node->isComponent) {
            return;
        }

        $this->walkChildren($node);
    }

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
     * 编译静态 style 字符串为已展开+归一化的 PHP 数组字面量。
     *
     * 输入: "padding:10px;flex-direction:row"
     * 输出: "['paddingTop'=>'10px','paddingRight'=>'10px','paddingBottom'=>'10px','paddingLeft'=>'10px','flexDirection'=>'row']"
     *
     * 步骤：解析 → CssShorthandExpander::expandAll → canonicalStyleKey → 输出数组字面量
     */
    private function compileStaticStyle(string $style): ?string
    {
        $decls = explode(';', $style);
        $raw = [];
        foreach ($decls as $decl) {
            $decl = trim($decl);
            if ($decl === '') continue;
            $colonPos = strpos($decl, ':');
            if ($colonPos === false) return null;
            $prop = strtolower(trim(substr($decl, 0, $colonPos)));
            $val = trim(substr($decl, $colonPos + 1));
            $raw[$prop] = $val;
        }
        if (empty($raw)) return null;

        // 强制关卡：与 StyleResolver 运行时共用同一套展开逻辑
        // flex 简写展开暂禁用（待 Phase 5 修复嵌套 flex column basis=0）
        $raw = \Px\Css\CssShorthandExpander::expandAll($raw, false);

        $pairs = [];
        foreach ($raw as $prop => $val) {
            $canonicalKey = \Px\Css\CssMappings::canonicalStyleKey($prop);
            $pairs[] = var_export($canonicalKey, true) . '=>' . var_export($val, true);
        }
        if (!empty($pairs)) {
            return '[' . implode(',', $pairs) . ']';
        }
        return null;
    }
}
