<?php

namespace Px\Css;

/**
 * CssTokenizer — CSS 选择器词法分析器（C2.1）。
 *
 * 对标 Blink CSSTokenizer 的选择器子集：将选择器串切为 token 流，
 * 供 SelectorParser 构造复合选择器链 AST。最小子集——不含 at-规则全语法、
 * 无 CSSOM 回写（文档 §八 术语对照）。
 *
 * token 形态：['type' => <T>, 'value' => <string>]，T ∈
 *   ident（标识符/标签/类名/id名/属性名/伪类名）
 *   hash（# 前缀，id）
 *   dot（.，class 引导）
 *   colon（: 伪类）/ dcolon（:: 伪元素）
 *   lbracket [ / rbracket ] / eq = / str（引号串）
 *   combinator（> + ~）/ space（后代组合子候选）/ comma（选择器列表分隔）
 *   star（* 通用）
 *
 * AOT 友好：纯字符扫描，无回调闭包、无动态调用。
 */
final class CssTokenizer
{
    public const T_IDENT      = 'ident';
    public const T_HASH       = 'hash';
    public const T_DOT        = 'dot';
    public const T_COLON      = 'colon';
    public const T_DCOLON     = 'dcolon';
    public const T_LBRACKET   = 'lbracket';
    public const T_RBRACKET   = 'rbracket';
    public const T_EQ         = 'eq';
    public const T_STR        = 'str';
    public const T_COMBINATOR = 'combinator';
    public const T_SPACE      = 'space';
    public const T_COMMA      = 'comma';
    public const T_STAR       = 'star';
    public const T_LPAREN     = 'lparen';
    public const T_RPAREN     = 'rparen';

    /**
     * @return array<int, array{type:string, value:string}>
     */
    public static function tokenize(string $selector): array
    {
        $tokens = [];
        $len = strlen($selector);
        $i = 0;
        while ($i < $len) {
            $ch = $selector[$i];

            // 空白：折叠为单个 space（后代组合子候选；解析器据相邻 token 决定丢弃）
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === "\f") {
                $i++;
                while ($i < $len && ($selector[$i] === ' ' || $selector[$i] === "\t"
                    || $selector[$i] === "\n" || $selector[$i] === "\r" || $selector[$i] === "\f")) {
                    $i++;
                }
                $tokens[] = ['type' => self::T_SPACE, 'value' => ' '];
                continue;
            }

            switch ($ch) {
                case '#': $tokens[] = ['type' => self::T_HASH, 'value' => '#']; $i++; continue 2;
                case '.': $tokens[] = ['type' => self::T_DOT, 'value' => '.']; $i++; continue 2;
                case '*': $tokens[] = ['type' => self::T_STAR, 'value' => '*']; $i++; continue 2;
                case '[': $tokens[] = ['type' => self::T_LBRACKET, 'value' => '[']; $i++; continue 2;
                case ']': $tokens[] = ['type' => self::T_RBRACKET, 'value' => ']']; $i++; continue 2;
                case '=': $tokens[] = ['type' => self::T_EQ, 'value' => '=']; $i++; continue 2;
                case '(': $tokens[] = ['type' => self::T_LPAREN, 'value' => '(']; $i++; continue 2;
                case ')': $tokens[] = ['type' => self::T_RPAREN, 'value' => ')']; $i++; continue 2;
                case ',': $tokens[] = ['type' => self::T_COMMA, 'value' => ',']; $i++; continue 2;
                case '>': case '+': case '~':
                    $tokens[] = ['type' => self::T_COMBINATOR, 'value' => $ch]; $i++; continue 2;
                case ':':
                    if ($i + 1 < $len && $selector[$i + 1] === ':') {
                        $tokens[] = ['type' => self::T_DCOLON, 'value' => '::']; $i += 2;
                    } else {
                        $tokens[] = ['type' => self::T_COLON, 'value' => ':']; $i++;
                    }
                    continue 2;
                case '"': case "'":
                    $quote = $ch; $i++; $buf = '';
                    while ($i < $len && $selector[$i] !== $quote) {
                        if ($selector[$i] === '\\' && $i + 1 < $len) { $buf .= $selector[$i + 1]; $i += 2; continue; }
                        $buf .= $selector[$i]; $i++;
                    }
                    $i++; // 跳过闭合引号
                    $tokens[] = ['type' => self::T_STR, 'value' => $buf];
                    continue 2;
            }

            // 标识符：字母/数字/-/_/转义（含 nth 公式内的数字与 n）
            if (ctype_alnum($ch) || $ch === '-' || $ch === '_' || $ch === '\\') {
                $buf = '';
                while ($i < $len) {
                    $c2 = $selector[$i];
                    if ($c2 === '\\' && $i + 1 < $len) { $buf .= $selector[$i + 1]; $i += 2; continue; }
                    if (ctype_alnum($c2) || $c2 === '-' || $c2 === '_') { $buf .= $c2; $i++; continue; }
                    break;
                }
                $tokens[] = ['type' => self::T_IDENT, 'value' => $buf];
                continue;
            }

            // 未知字符跳过（容错）
            $i++;
        }
        return $tokens;
    }
}
