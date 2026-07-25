<?php

use Px\Dom\VNode;

/**
 * MiscHelper — 杂项工具函数
 */

/**
 * 从 CSS 文本中提取类名 → 原始样式字符串映射。
 * 支持 .class { ... }、* { ... }、body { ... } / html { ... }
 */
function parseCssClassesForMerge(string $css): array
{
    // CSS Syntax §4（对标 Blink CSSTokenizer）：注释在 tokenize 阶段移除，绝不参与规则匹配。
    // 此前未剥离：注释文案中的 `* { ... }` 被拓为幽灵 universal 规则 '...'，
    // 污染所有节点 style（含 <component> 占位 → 透传路径覆盖子组件根样式）。
    $css = preg_replace('#/\*.*?\*/#s', '', $css);
    $result = [];
    if (preg_match_all('#\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}#s', $css, $rules, PREG_SET_ORDER)) {
        foreach ($rules as $rule) {
            $className = $rule[1];
            $body = trim($rule[2]);
            $body = preg_replace('/\s+/', ' ', $body);
            $body = rtrim($body, ';');
            $result[$className] = $body;
        }
    }
    // 提取 * 通用选择器规则
    if (preg_match('/\*\s*\{([^}]*)\}/s', $css, $m)) {
        $body = trim($m[1]);
        $body = preg_replace('/\s+/', ' ', $body);
        $body = rtrim($body, ';');
        $result['*'] = $body;
    }
    // 提取 body 等 tag 选择器规则
    if (preg_match('/(?:^|[\{\}])\s*(body|html)\s*\{([^}]*)\}/si', $css, $m)) {
        $body = trim($m[2]);
        $body = preg_replace('/\s+/', ' ', $body);
        $body = rtrim($body, ';');
        $result['_tag_' . strtolower($m[1])] = $body;
    }
    return $result;
}

/**
 * 递归地将解析后的 CSS 类样式（* 通用 + 类选择器）按级联优先级合并到 VNode 内联样式中。
 */
function mergeClassStylesIntoNode($node, array $rawStyles): void
{
    if ($node === null) return;

    // #component 占位是抽象出口（对标 Vue：最终渲染为子组件根元素），非真实元素：
    // universal * 样式在子组件自身编译时已按层叠应用，若合并到占位会经
    // 透传路径以“父覆盖”错误优先级二次施加（覆盖子根 inline 声明）。
    $isComponentPlaceholder = (($node->type ?? '') === '#component')
        || (($node->type ?? '') === 'component')
        || (property_exists($node, 'isComponent') && $node->isComponent);

    // Step 1: 应用 * 通用选择器到每一个真实元素节点（作为基础样式）
    $universalDecls = '';
    if (isset($rawStyles['*']) && !$isComponentPlaceholder) {
        $universalDecls = $rawStyles['*'];
    }

    // Step 2: 应用类选择器
    $classNormalDecls = [];
    $classImportantDecls = [];
    if ($node->props !== null && isset($node->props['class']) && !isset($node->props[':class'])) {
        $classVal = $node->props['class'];
        if (is_string($classVal) && $classVal !== '') {
            $classNames = explode(' ', $classVal);
            foreach ($classNames as $cn) {
                $cn = trim($cn);
                if ($cn === '' || !isset($rawStyles[$cn])) continue;
                $parts = explode(';', $rawStyles[$cn]);
                foreach ($parts as $decl) {
                    $decl = trim($decl);
                    if ($decl === '') continue;
                    if (stripos($decl, '!important') !== false) {
                        $classImportantDecls[] = $decl;
                    } else {
                        $classNormalDecls[] = $decl;
                    }
                }
            }
        }
    }

    // Step 3: 合并（* + class + inline，具有正确的层叠优先级）
    if ($universalDecls !== '' || !empty($classNormalDecls) || !empty($classImportantDecls)) {
        $existing = $node->props['style'] ?? '';
        $parts = [];
        if ($universalDecls !== '') $parts[] = $universalDecls;
        if (!empty($classNormalDecls)) $parts[] = implode(';', $classNormalDecls);
        if ($existing !== '') $parts[] = $existing;
        if (!empty($classImportantDecls)) $parts[] = implode(';', $classImportantDecls);
        $node->props['style'] = implode(';', $parts);
    }

    // Step 4: 递归子节点
    if (is_array($node->children)) {
        foreach ($node->children as $child) {
            if (is_object($child) && property_exists($child, 'props')) {
                mergeClassStylesIntoNode($child, $rawStyles);
            }
        }
    }
}

/**
 * var_export with short array syntax
 */
function varExportShort(array $data): string
{
    $export = var_export($data, true);
    $export = str_replace('array (', '[', $export);
    $export = str_replace('array(', '[', $export);
    $lines = explode("\n", $export);
    foreach ($lines as &$line) {
        // Skip __set_state lines — their closing parens are handled by __set_state syntax
        if (str_contains($line, '__set_state')) continue;
        // Convert closing parens to brackets: ) → ], )) → ]], )) → ]] etc.
        $line = preg_replace('/^(\s*)\)\)((,?))$/', '$1])$3', $line);
        $line = preg_replace('/^(\s*)\)((,?))$/', '$1]$2', $line);
    }
    $export = implode("\n", $lines);
    return $export;
}

/**
 * Convert VNode props array to PHP array literal string.
 * Skips internal props (starting with __).
 */
function propsToPhpArray(array $props): string
{
    $filtered = [];
    foreach ($props as $k => $v) {
        if (str_starts_with($k, '__')) continue;
        $filtered[$k] = $v;
    }

    if (count($filtered) === 0) return '[]';

    $str = varExportShort($filtered);

    // Fix numeric values: 'width:400px' (from var_export string) is OK
    return $str;
}

/**
 * Generate PHP array expression for componentProps binding map.
 *
 * Input:  ['childProp' => 'parentExpr', ...]
 * Output: "['childProp'=>'parentExpr', ...]"
 *
 * @param array $componentProps ['childKey'=>'parentExpr', ...]
 * @return string PHP array literal
 */
function generateComponentPropsExpr(array $componentProps): string
{
    if (count($componentProps) === 0) {
        return '[]';
    }
    $entries = [];
    foreach ($componentProps as $childKey => $parentExpr) {
        // Static string values are already prefixed with 'static:' by the caller.
        // Bind expression keys (e.g. 'display' from :value="display") pass through
        // without prefix, so at runtime expandComponentNode resolves them via
        // getBindValue() on the parent component.
        if (is_string($parentExpr) && substr($parentExpr, 0, 7) === 'static:') {
            // Already prefixed with 'static:' - KEEP it in generated code
            // Runtime will extract the value using substr($expr, 7)
            $entries[] = var_export($childKey, true) . "=>'" . addslashes($parentExpr) . "'";
        } else {
            // Dynamic expression or variable reference - pass through as-is
            $entries[] = var_export($childKey, true) . '=>' . var_export($parentExpr, true);
        }
    }
    return '[' . implode(',', $entries) . ']';
}
