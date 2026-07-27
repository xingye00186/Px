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
    // 提取纯类型（tag）选择器规则（CSS Selectors L3 type selector，特异性 (0,0,1)）：
    // code{}/span{} 等。此前无烘焙通道——code{background;padding} 整条丢失
    //（case-019 实锤）。锚定规则边界（^或 {}）避免误捕复合选择器尾部 tag；
    // body/html 走专用基线通道，排除。同 tag 多条规则按源序追加（后来者居上）。
    $tagRules = [];
    if (preg_match_all('#(?:^|[\{\}])\s*([a-z][a-z0-9]*)\s*\{([^}]*)\}#s', $css, $trs, PREG_SET_ORDER)) {
        foreach ($trs as $tr) {
            $tag = strtolower($tr[1]);
            if ($tag === 'body' || $tag === 'html') continue;
            $body = trim($tr[2]);
            $body = preg_replace('/\s+/', ' ', $body);
            $body = rtrim($body, ';');
            if ($body === '') continue;
            $tagRules[$tag] = isset($tagRules[$tag]) ? ($tagRules[$tag] . ';' . $body) : $body;
        }
    }
    if (count($tagRules) > 0) {
        $result['__tag_rules__'] = $tagRules;
    }
    // 提取 id 选择器规则（特异性 (1,0,0)，高于类低于 inline）：#vis-hidden{} 等。
    $idRules = [];
    if (preg_match_all('#\#([a-zA-Z][a-zA-Z0-9_-]*)\s*\{([^}]*)\}#s', $css, $irs, PREG_SET_ORDER)) {
        foreach ($irs as $ir) {
            $body = trim($ir[2]);
            $body = preg_replace('/\s+/', ' ', $body);
            $body = rtrim($body, ';');
            if ($body === '') continue;
            $idRules[$ir[1]] = isset($idRules[$ir[1]]) ? ($idRules[$ir[1]] . ';' . $body) : $body;
        }
    }
    if (count($idRules) > 0) {
        $result['__id_rules__'] = $idRules;
    }
    // 提取复合选择器（CSS Selectors L3）：.first <comb> (.second | tag) { }
    // 如 .rel-row div{flex:1}、.a > .b{...}。subject 为类或类型选择器；
    // 在 mergeClassStylesIntoNode 递归时携带祖先/前兄弟上下文匹配。
    $complexRules = [];
    if (preg_match_all('#\.([a-zA-Z0-9_-]+)\s*([>+~ ])\s*(?:\.([a-zA-Z0-9_-]+)|([a-z][a-z0-9]*))\s*\{([^}]*)\}#s', $css, $crs, PREG_SET_ORDER)) {
        foreach ($crs as $cr) {
            $body = trim($cr[5]);
            $body = preg_replace('/\s+/', ' ', $body);
            $body = rtrim($body, ';');
            if ($body === '') continue;
            $complexRules[] = [
                'first'       => $cr[1],
                'comb'        => trim($cr[2]) === '' ? ' ' : trim($cr[2]),
                'secondClass' => $cr[3] !== '' ? $cr[3] : null,
                'secondTag'   => (isset($cr[4]) && $cr[4] !== '') ? $cr[4] : null,
                'decls'       => $body,
            ];
        }
    }
    if (count($complexRules) > 0) {
        $result['__complex_rules__'] = $complexRules;
    }
    return $result;
}

/**
 * 递归地将解析后的 CSS 类样式（* 通用 + 类选择器 + 复合选择器）按级联优先级合并到 VNode 内联样式中。
 *
 * 层叠序（CSS Cascade L4 特异性升序）：
 *   * (0,0,0,0) → 简单类 (0,0,1,0) → .a tag (0,0,1,1) → .a .b (0,0,2,0) → inline → !important
 *
 * @param array $ancestorClassLists 每层祖先的 class 名数组列表（根在前，直接父在尾）
 * @param array $precedingSiblingClasses 前序兄弟的 class 字符串列表（文档序）
 */
function mergeClassStylesIntoNode($node, array $rawStyles, array $ancestorClassLists = [], array $precedingSiblingClasses = []): void
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

    // Step 1.5: 纯类型（tag）选择器（特异性 (0,0,1)：universal 之后、类之前）
    $tagDecls = '';
    if (isset($rawStyles['__tag_rules__']) && !$isComponentPlaceholder) {
        $nt = strtolower((string)($node->type ?? ''));
        if ($nt !== '' && isset($rawStyles['__tag_rules__'][$nt])) {
            $tagDecls = $rawStyles['__tag_rules__'][$nt];
        }
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

    // Step 2.5: 复合选择器（.first <comb> (.second|tag)）：subject 匹配自身，
    // first 侧按组合子匹配祖先/前兄弟。按特异性序：tag-subject (0,0,1,1) 先、
    // class-subject (0,0,2,0) 后，均追加在简单类之后（高特异性覆盖）。
    $ownClasses = [];
    if ($node->props !== null && isset($node->props['class']) && is_string($node->props['class'])) {
        foreach (explode(' ', $node->props['class']) as $oc) {
            $oc = trim($oc);
            if ($oc !== '') $ownClasses[] = $oc;
        }
    }
    if (isset($rawStyles['__complex_rules__']) && !$isComponentPlaceholder) {
        $nodeType = (string)($node->type ?? '');
        $tagSubjectDecls = [];
        $classSubjectDecls = [];
        foreach ($rawStyles['__complex_rules__'] as $rule) {
            // subject 侧
            $isTagSubject = ($rule['secondTag'] !== null);
            if ($isTagSubject) {
                if ($nodeType !== $rule['secondTag']) continue;
            } else {
                if (!in_array($rule['secondClass'], $ownClasses, true)) continue;
            }
            // first 侧（组合子语义，CSS Selectors L3）
            $firstOk = false;
            switch ($rule['comb']) {
                case ' ': // 后代：任意祖先层含 first
                    foreach ($ancestorClassLists as $aList) {
                        if (in_array($rule['first'], $aList, true)) { $firstOk = true; break; }
                    }
                    break;
                case '>': // 子：直接父层含 first
                    $direct = count($ancestorClassLists) > 0 ? $ancestorClassLists[count($ancestorClassLists) - 1] : [];
                    $firstOk = in_array($rule['first'], $direct, true);
                    break;
                case '+': // 紧邻前兄弟
                    $prev = count($precedingSiblingClasses) > 0 ? $precedingSiblingClasses[count($precedingSiblingClasses) - 1] : '';
                    $firstOk = in_array($rule['first'], explode(' ', $prev), true);
                    break;
                case '~': // 任意前兄弟
                    foreach ($precedingSiblingClasses as $sib) {
                        if (in_array($rule['first'], explode(' ', $sib), true)) { $firstOk = true; break; }
                    }
                    break;
            }
            if (!$firstOk) continue;
            foreach (explode(';', $rule['decls']) as $decl) {
                $decl = trim($decl);
                if ($decl === '') continue;
                if (stripos($decl, '!important') !== false) {
                    $classImportantDecls[] = $decl;
                } elseif ($isTagSubject) {
                    $tagSubjectDecls[] = $decl;
                } else {
                    $classSubjectDecls[] = $decl;
                }
            }
        }
        // 特异性升序追加：简单类已在 $classNormalDecls，tag-subject → class-subject
        foreach ($tagSubjectDecls as $d) $classNormalDecls[] = $d;
        foreach ($classSubjectDecls as $d) $classNormalDecls[] = $d;
    }

    // Step 2.7: id 选择器（特异性 (1,0,0)：高于全部类/复合，低于 inline）
    if (isset($rawStyles['__id_rules__']) && !$isComponentPlaceholder
        && $node->props !== null && isset($node->props['id']) && is_string($node->props['id'])) {
        $nid = trim($node->props['id']);
        if ($nid !== '' && isset($rawStyles['__id_rules__'][$nid])) {
            foreach (explode(';', $rawStyles['__id_rules__'][$nid]) as $decl) {
                $decl = trim($decl);
                if ($decl === '') continue;
                if (stripos($decl, '!important') !== false) {
                    $classImportantDecls[] = $decl;
                } else {
                    $classNormalDecls[] = $decl; // 追加在最后：同数组内后来者居上
                }
            }
        }
    }

    // Step 3: 合并（* + tag + class + inline，具有正确的层叠优先级）
    if ($universalDecls !== '' || $tagDecls !== '' || !empty($classNormalDecls) || !empty($classImportantDecls)) {
        $existing = $node->props['style'] ?? '';
        $parts = [];
        if ($universalDecls !== '') $parts[] = $universalDecls;
        if ($tagDecls !== '') $parts[] = $tagDecls;
        if (!empty($classNormalDecls)) $parts[] = implode(';', $classNormalDecls);
        if ($existing !== '') $parts[] = $existing;
        if (!empty($classImportantDecls)) $parts[] = implode(';', $classImportantDecls);
        $node->props['style'] = implode(';', $parts);
    }

    // Step 4: 递归子节点（携带祖先 class 链与前序兄弟上下文）
    if (is_array($node->children)) {
        $childAncestors = $ancestorClassLists;
        $childAncestors[] = $ownClasses;
        $siblingAcc = [];
        foreach ($node->children as $child) {
            if (is_object($child) && property_exists($child, 'props')) {
                mergeClassStylesIntoNode($child, $rawStyles, $childAncestors, $siblingAcc);
                $siblingAcc[] = (string)($child->props['class'] ?? '');
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
