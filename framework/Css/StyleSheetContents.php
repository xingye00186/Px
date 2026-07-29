<?php

namespace Px\Css;

/**
 * StyleSheetContents — 编译期规则存储（C2.3，对标 Blink StyleSheetContents）。
 *
 * 将组件 <style> 块解析为 RuleData 数组：每条 CSS 规则（selector { decls }）
 * → RuleData{ selector, ast, declarations, specificity, order, scopeId }。
 * 编译期最大化——SelectorParser 产出的复合链 AST 随规则一并烘入 gen 常量，
 * 运行时零解析（C2.4/C2.5 消费）。
 *
 * RuleData 形态（纯数组，AOT 友好）：
 *   [
 *     'selector'     => string,   // 原始选择器串（逗号列表整体）
 *     'ast'          => array,    // SelectorParser::parse 结果（复杂选择器列表）
 *     'declarations' => string,   // 声明串（'k:v;k:v'，运行时按 CascadeResolver 消费）
 *     'specificity'  => [a,b,c,d],// 该规则最高 specificity（逗号列表取 max）
 *     'order'        => int,      // 源顺序（同优先级 tie-break）
 *     'scopeId'      => string,   // 组件 scope（对标 Vue [data-v-hash]）
 *   ]
 *
 * 命名（§8.1）：与 Blink StyleSheetContents / RuleData 同义，直接采用。
 */
final class StyleSheetContents
{
    /**
     * 从 <style> 原始 CSS 构建 RuleData 列表。
     *
     * @return array<int, array{selector:string, ast:array, declarations:string, specificity:array, order:int, scopeId:string}>
     */
    public static function build(string $css, string $scopeId = ''): array
    {
        // 剥离注释
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        // 剥离 @-规则块（@keyframes/@media 等：C2.3 不入规则表，C5 另行处理）
        $css = self::stripAtRules($css);

        $rules = [];
        $order = 0;
        // 匹配 selector { body }（非贪婪 body，不含嵌套 {}）
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                $selector = trim($m[1]);
                $body = trim($m[2]);
                if ($selector === '' || $body === '') continue;

                $ast = SelectorParser::parse($selector);
                if (empty($ast)) continue;

                // 逗号列表取最高 specificity
                $maxSpec = [0, 0, 0, 0];
                foreach ($ast as $complex) {
                    if (CascadeResolver::compareSpecificity($complex['specificity'], $maxSpec) > 0) {
                        $maxSpec = $complex['specificity'];
                    }
                }

                $rules[] = [
                    'selector'     => $selector,
                    'ast'          => $ast,
                    'declarations' => self::normalizeDecls($body),
                    'specificity'  => $maxSpec,
                    'order'        => $order++,
                    'scopeId'      => $scopeId,
                ];
            }
        }
        return $rules;
    }

    /** 归一化声明串：折叠空白、去尾分号。 */
    private static function normalizeDecls(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        return rtrim($body, '; ');
    }

    /** 剥离 @-规则（含其块体），避免混入普通规则匹配。 */
    private static function stripAtRules(string $css): string
    {
        // 逐个吞掉 @xxx { ... }（支持一层嵌套花括号，如 @media { .a{} }）
        $out = '';
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            if ($css[$i] === '@') {
                // 找到首个 {，然后按深度吞到匹配 }
                $j = $i;
                while ($j < $len && $css[$j] !== '{' && $css[$j] !== ';') $j++;
                if ($j < $len && $css[$j] === ';') { $i = $j + 1; continue; } // @import; 等
                if ($j >= $len) break;
                $depth = 0;
                for (; $j < $len; $j++) {
                    if ($css[$j] === '{') $depth++;
                    elseif ($css[$j] === '}') { $depth--; if ($depth === 0) { $j++; break; } }
                }
                $i = $j;
                continue;
            }
            $out .= $css[$i];
            $i++;
        }
        return $out;
    }
}
