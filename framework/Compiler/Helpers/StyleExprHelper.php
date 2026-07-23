<?php

/**
 * StyleExprHelper — 样式表达式解析与转换
 */

/**
 * 解析样式表达式中的标识符为 PHP 引用。
 * 处理 v-for 循环变量替换 + JS `+` 转 PHP `.` + 裸标识符加 `$this->` 前缀。
 */
function resolveStyleExpr(string $expr, ?array $loopInfo): string
{
    if ($loopInfo !== null) {
        $item = $loopInfo['item'] ?? '';
        // 1. Handle item.property → \$item['property']
        if ($item !== '') {
            $expr = preg_replace('/\b(' . preg_quote($item, '/') . ')\.(\w+)\b/', '\$' . $item . "['\$2']", $expr);
        }
        // 2. Handle index variable → \$idx
        $index = $loopInfo['index'] ?? '';
        if ($index !== '') {
            $expr = preg_replace('/\b' . preg_quote($index, '/') . '\b/', '\$' . $index, $expr);
        }
    }

    // 3. Char-walker: replace remaining bare identifiers with $this->prefix
    // Also converts JS `+` (string concat) to PHP `.` at depth 0 (outside parens).
    // Handles: 'text' . coverBg . 'more' → 'text' . $this->coverBg . 'more'
    //          'left:' + (idx * 44) + 'px' → 'left:' . ($this->idx * 44) . 'px' (Vue 3 compat)
    $result = '';
    $len = strlen($expr);
    $inSingle = false;
    $inDouble = false;
    $depth = 0;
    $i = 0;

    while ($i < $len) {
        $ch = $expr[$i];

        // Track quote state
        if ($ch === "'" && !$inDouble) {
            $inSingle = !$inSingle;
            $result .= $ch;
            $i++;
            continue;
        }
        if ($ch === '"' && !$inSingle) {
            $inDouble = !$inDouble;
            $result .= $ch;
            $i++;
            continue;
        }

        if (!$inSingle && !$inDouble) {
            // Track paren/bracket depth for + → . conversion
            if ($ch === '(' || $ch === '[') {
                $depth++;
                $result .= $ch;
                $i++;
                continue;
            }
            if ($ch === ')' || $ch === ']') {
                $depth--;
                $result .= $ch;
                $i++;
                continue;
            }

            // Convert JS `+` to PHP `.` at depth 0 (string concatenation)
            // Inside parens (depth > 0), `+` is arithmetic addition.
            if ($ch === '+' && $depth === 0) {
                $result .= '.';
                $i++;
                continue;
            }

            // Skip PHP variable names (starts with $)
            if ($ch === '$') {
                $result .= $ch;
                $i++;
                while ($i < $len && preg_match('/\w/', $expr[$i])) {
                    $result .= $expr[$i];
                    $i++;
                }
                continue;
            }

            // Check for -> object access
            if ($ch === '-' && $i + 1 < $len && $expr[$i + 1] === '>') {
                $result .= '->';
                $i += 2;
                continue;
            }

            // Check for :: static access
            if ($ch === ':' && $i + 1 < $len && $expr[$i + 1] === ':') {
                $result .= '::';
                $i += 2;
                continue;
            }

            // Check for bare identifier (word character)
            if (preg_match('/[a-zA-Z_]/', $ch)) {
                $word = '';
                $start = $i;
                while ($i < $len && preg_match('/\w/', $expr[$i])) {
                    $word .= $expr[$i];
                    $i++;
                }

                // Skip PHP keywords and magic values
                if (in_array(strtolower($word), ['true', 'false', 'null', 'isset', 'empty', 'unset', 'die', 'exit', 'echo', 'print', 'return', 'if', 'else', 'elseif', 'for', 'foreach', 'while', 'do', 'switch', 'case', 'break', 'continue', 'default', 'function', 'class', 'interface', 'trait', 'namespace', 'use', 'new', 'clone', 'var', 'public', 'private', 'protected', 'static', 'const', 'self', 'parent', 'abstract', 'final', 'readonly', 'match', 'fn', 'throw', 'try', 'catch', 'finally', 'yield', 'from', 'include', 'require', 'include_once', 'require_once', 'and', 'or', 'xor', 'int', 'float', 'string', 'bool', 'array', 'object', 'void', 'mixed', 'never'], true)) {
                    $result .= $word;
                    continue;
                }

                // Regular identifier → $this->identifier
                $result .= '$this->' . $word;
                continue;
            }
        }

        $result .= $ch;
        $i++;
    }

    return $result;
}

/**
 * 尝试将 :style 的字符串拼接表达式转换为 PHP 数组表达式。
 *
 * 输入：'color:red;font-size:' . $size . 'px'
 * 输出：['color'=>'red','font-size'=>$size . 'px']
 *
 * 转换失败时返回 null（表达式过于复杂，保留原字符串路径）。
 */
function tryConvertStyleToArray(string $resolved): ?string
{
    // 按 `.` 拆分为顶层拼接片段（不拆括号/引号内的 `.`）
    $parts = [];
    $len = strlen($resolved);
    $inSingle = false;
    $inDouble = false;
    $depth = 0;
    $current = '';
    for ($i = 0; $i < $len; $i++) {
        $ch = $resolved[$i];
        if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; $current .= $ch; continue; }
        if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; $current .= $ch; continue; }
        if (!$inSingle && !$inDouble) {
            if ($ch === '(' || $ch === '[') { $depth++; $current .= $ch; continue; }
            if ($ch === ')' || $ch === ']') { $depth--; $current .= $ch; continue; }
            if ($ch === '.' && $depth === 0) {
                $trimmed = trim($current);
                if ($trimmed !== '') $parts[] = $trimmed;
                $current = '';
                continue;
            }
        }
        $current .= $ch;
    }
    $trimmed = trim($current);
    if ($trimmed !== '') $parts[] = $trimmed;

    if (count($parts) === 0) return null;

    $entries = [];      // ['prop' => 'valueExpr', ...]
    $pendingProp = null; // 上一个未完成的 CSS 属性名

    foreach ($parts as $part) {
        $isString = (str_starts_with($part, "'") && str_ends_with($part, "'"))
                  || (str_starts_with($part, '"') && str_ends_with($part, '"'));

        if ($isString) {
            // 去掉引号
            $strContent = substr($part, 1, -1);
            // 按 ; 拆分为 CSS 声明
            $decls = explode(';', $strContent);
            foreach ($decls as $decl) {
                $decl = trim($decl);
                if ($decl === '') continue;
                $colonPos = strpos($decl, ':');
                if ($colonPos === false) {
                    // 没有冒号：整个字符串是值的一部分（追加到上一个属性）
                    // 这种情况很少见，回退
                    return null;
                }
                $prop = trim(substr($decl, 0, $colonPos));
                // 治本：编译期将 CSS 属性名统一为规范 key（camelCase），
                // 与 parseStyleBlock/parseInlineStyle 一致（同时覆盖 pendingProp 与直接存储两路径）
                $prop = \Px\Css\CssMappings::canonicalStyleKey($prop);
                $val = trim(substr($decl, $colonPos + 1));

                if ($val === '') {
                    // 值在下一个动态片段中
                    $pendingProp = $prop;
                } else {
                    // 完整声明
                    $entries[] = var_export($prop, true) . '=>' . var_export($val, true);
                    $pendingProp = null;
                }
            }
        } else {
            // 动态表达式
            if ($pendingProp !== null) {
                // 作为上一个 CSS 属性的值
                $entries[] = var_export($pendingProp, true) . '=>' . $part;
                $pendingProp = null;
            } else {
                // 没有待补全属性：可能是危险的动态 CSS，保留字符串路径
                return null;
            }
        }
    }

    // 还有未完成的属性 → 回退
    if ($pendingProp !== null) return null;

    return '[' . implode(',', $entries) . ']';
}

/**
 * 将 v-for 的 :key 表达式解析为 PHP 表达式字符串。
 * 映射 item.field → $item['field'], item → $item, index → $index。
 */
function resolveVForKeyExpr(string $expr, array $loopInfo): string
{
    $item = $loopInfo['item'] ?? '';
    $index = $loopInfo['index'] ?? '';

    if ($item !== '' && str_starts_with($expr, $item . '.')) {
        $propName = substr($expr, strlen($item) + 1);
        return "\${$item}['" . addslashes($propName) . "']";
    } elseif ($item !== '' && $expr === $item) {
        return "\${$item}";
    } elseif ($index !== '' && $expr === $index) {
        return "\${$index}";
    } else {
        // Static string or other expression: export as-is
        return var_export($expr, true);
    }
}
