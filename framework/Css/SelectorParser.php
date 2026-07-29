<?php

namespace Px\Css;

/**
 * SelectorParser — 复合选择器链 AST 构造器（C2.1）。
 *
 * 对标 Blink CSSParserImpl 选择器子集：token 流 → 复合选择器链 AST，
 * 内联 specificity 计算（[a,b,c,d]，与 CssMappings::calculateSpecificity 同序）。
 *
 * AST 形态（纯数组，AOT 友好）：
 *   parse() → 复杂选择器列表（逗号分隔），每项：
 *     [
 *       'compounds'   => [ compound, ... ]   // 复合选择器序列（文档序：左→右）
 *       'combinators' => [ ' '|'>'|'+'|'~', ... ] // compounds[i] 与 compounds[i+1] 之间
 *       'specificity' => [a,b,c,d],
 *     ]
 *   compound = [
 *     'tag'          => ?string  // 标签名/'*'/null
 *     'id'           => ?string
 *     'classes'      => string[]
 *     'attrs'        => [ ['name'=>, 'op'=>'exists'|'=', 'value'=>?], ... ]
 *     'pseudoClasses'=> [ ['name'=>, 'arg'=>?string], ... ]  // hover/nth-child(...)/not(...)
 *     'pseudoEls'    => string[]  // before/after
 *   ]
 *
 * subject（右向左匹配起点）= compounds 末元素。
 */
final class SelectorParser
{
    /**
     * @return array<int, array{compounds:array, combinators:array, specificity:array}>
     */
    public static function parse(string $selector): array
    {
        $tokens = CssTokenizer::tokenize($selector);
        $groups = self::splitByComma($tokens);
        $result = [];
        foreach ($groups as $g) {
            $parsed = self::parseComplex($g);
            if ($parsed !== null) {
                $result[] = $parsed;
            }
        }
        return $result;
    }

    /** 按逗号切分为多个复杂选择器 token 组。 */
    private static function splitByComma(array $tokens): array
    {
        $groups = [];
        $cur = [];
        foreach ($tokens as $t) {
            if ($t['type'] === CssTokenizer::T_COMMA) {
                $groups[] = $cur; $cur = [];
            } else {
                $cur[] = $t;
            }
        }
        if (!empty($cur)) $groups[] = $cur;
        return $groups;
    }

    /** 解析单个复杂选择器（compound 链 + 组合子）。 */
    private static function parseComplex(array $tokens): ?array
    {
        // 去首尾 space；内部 space 若两侧非组合子则为后代组合子
        $tokens = self::trimSpaces($tokens);
        if (empty($tokens)) return null;

        $compounds = [];
        $combinators = [];
        $i = 0;
        $n = count($tokens);
        $pendingCombinator = null;

        while ($i < $n) {
            $t = $tokens[$i];
            if ($t['type'] === CssTokenizer::T_SPACE) {
                // 后代组合子候选：仅当尚无 pending 组合子时置 ' '（不覆盖显式 >/+/~）。
                // 组合子两侧的 space（'.b > .c' 的两个空白）不得重置 pending。
                if ($pendingCombinator === null) {
                    $pendingCombinator = ' ';
                }
                $i++; continue;
            }
            if ($t['type'] === CssTokenizer::T_COMBINATOR) {
                $pendingCombinator = $t['value'];
                $i++; continue;
            }
            // 解析一个 compound
            [$compound, $i] = self::parseCompound($tokens, $i, $n);
            if (!empty($compounds)) {
                $combinators[] = $pendingCombinator ?? ' ';
            }
            $compounds[] = $compound;
            $pendingCombinator = null;
        }

        if (empty($compounds)) return null;
        return [
            'compounds'   => $compounds,
            'combinators' => $combinators,
            'specificity' => self::computeSpecificity($compounds),
        ];
    }

    /** 从 $i 起解析一个 compound，返回 [compound, nextIndex]。 */
    private static function parseCompound(array $tokens, int $i, int $n): array
    {
        $compound = ['tag' => null, 'id' => null, 'classes' => [], 'attrs' => [], 'pseudoClasses' => [], 'pseudoEls' => []];
        while ($i < $n) {
            $t = $tokens[$i];
            $type = $t['type'];
            if ($type === CssTokenizer::T_SPACE || $type === CssTokenizer::T_COMBINATOR) break;

            if ($type === CssTokenizer::T_STAR) {
                $compound['tag'] = '*'; $i++; continue;
            }
            if ($type === CssTokenizer::T_IDENT) {
                // compound 起始的 ident = 标签
                $compound['tag'] = strtolower($t['value']); $i++; continue;
            }
            if ($type === CssTokenizer::T_HASH) {
                $i++;
                if ($i < $n && $tokens[$i]['type'] === CssTokenizer::T_IDENT) {
                    $compound['id'] = $tokens[$i]['value']; $i++;
                }
                continue;
            }
            if ($type === CssTokenizer::T_DOT) {
                $i++;
                if ($i < $n && $tokens[$i]['type'] === CssTokenizer::T_IDENT) {
                    $compound['classes'][] = $tokens[$i]['value']; $i++;
                }
                continue;
            }
            if ($type === CssTokenizer::T_LBRACKET) {
                [$attr, $i] = self::parseAttr($tokens, $i + 1, $n);
                if ($attr !== null) $compound['attrs'][] = $attr;
                continue;
            }
            if ($type === CssTokenizer::T_DCOLON) {
                $i++;
                if ($i < $n && $tokens[$i]['type'] === CssTokenizer::T_IDENT) {
                    $compound['pseudoEls'][] = strtolower($tokens[$i]['value']); $i++;
                }
                continue;
            }
            if ($type === CssTokenizer::T_COLON) {
                $i++;
                if ($i < $n && $tokens[$i]['type'] === CssTokenizer::T_IDENT) {
                    $pcName = strtolower($tokens[$i]['value']); $i++;
                    $arg = null;
                    if ($i < $n && $tokens[$i]['type'] === CssTokenizer::T_LPAREN) {
                        [$arg, $i] = self::readParenArg($tokens, $i + 1, $n);
                    }
                    $compound['pseudoClasses'][] = ['name' => $pcName, 'arg' => $arg];
                }
                continue;
            }
            $i++; // 容错跳过
        }
        return [$compound, $i];
    }

    /** 解析属性选择器 [name] / [name=value]，$i 指向 name。返回 [attr, nextIndex]。 */
    private static function parseAttr(array $tokens, int $i, int $n): array
    {
        $name = null; $op = 'exists'; $value = null;
        if ($i < $n && $tokens[$i]['type'] === CssTokenizer::T_IDENT) {
            $name = $tokens[$i]['value']; $i++;
        }
        if ($i < $n && $tokens[$i]['type'] === CssTokenizer::T_EQ) {
            $op = '='; $i++;
            if ($i < $n && ($tokens[$i]['type'] === CssTokenizer::T_STR || $tokens[$i]['type'] === CssTokenizer::T_IDENT)) {
                $value = $tokens[$i]['value']; $i++;
            }
        }
        // 跳到 ]
        while ($i < $n && $tokens[$i]['type'] !== CssTokenizer::T_RBRACKET) $i++;
        if ($i < $n) $i++; // 跳过 ]
        if ($name === null) return [null, $i];
        return [['name' => $name, 'op' => $op, 'value' => $value], $i];
    }

    /** 读括号内实参（nth 公式 / :not 内简单选择器）到匹配的 )。返回 [argString, nextIndex]。 */
    private static function readParenArg(array $tokens, int $i, int $n): array
    {
        $buf = '';
        $depth = 1;
        while ($i < $n && $depth > 0) {
            $t = $tokens[$i];
            if ($t['type'] === CssTokenizer::T_LPAREN) { $depth++; $buf .= '('; $i++; continue; }
            if ($t['type'] === CssTokenizer::T_RPAREN) { $depth--; if ($depth === 0) { $i++; break; } $buf .= ')'; $i++; continue; }
            $buf .= $t['value'];
            $i++;
        }
        return [trim($buf), $i];
    }

    /** specificity = [a=0(inline), b=id, c=class+attr+pseudoClass, d=tag+pseudoEl]。 */
    private static function computeSpecificity(array $compounds): array
    {
        $b = 0; $c = 0; $d = 0;
        foreach ($compounds as $cp) {
            if ($cp['id'] !== null) $b++;
            $c += count($cp['classes']);
            $c += count($cp['attrs']);
            $c += count($cp['pseudoClasses']);
            if ($cp['tag'] !== null && $cp['tag'] !== '*') $d++;
            $d += count($cp['pseudoEls']);
        }
        return [0, $b, $c, $d];
    }

    /** 去除 token 序列首尾的 space。 */
    private static function trimSpaces(array $tokens): array
    {
        while (!empty($tokens) && $tokens[0]['type'] === CssTokenizer::T_SPACE) array_shift($tokens);
        while (!empty($tokens) && end($tokens)['type'] === CssTokenizer::T_SPACE) array_pop($tokens);
        return $tokens;
    }
}
