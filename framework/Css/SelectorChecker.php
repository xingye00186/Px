<?php

namespace Px\Css;

/**
 * SelectorChecker — 复合选择器链匹配器（C2.2，对标 Blink SelectorChecker）。
 *
 * 右向左匹配：从 subject（compounds 末元素）起，沿组合子回溯祖先/兄弟。
 * 支持任意级组合子（后代 ' ' / 子 '>' / 相邻 '+' / 通用 '~'）、
 * :nth-child(an+b) / :not(简单选择器) / 属性选择器 [attr] / [attr=v]。
 *
 * 元素上下文（纯数组，无 DOM 依赖，AOT 友好）：
 *   element = [
 *     'tag'      => string,
 *     'id'       => ?string,
 *     'classes'  => string[],
 *     'attrs'    => array<string,string>,   // name => value
 *     'index'    => int,   // 在父元素子序中的 1-based 元素序（nth-child）
 *     'ancestors'=> array<int, element>,    // 根→直接父（用于 ' ' 和 '>'）
 *     'prevSiblings' => array<int, element>,// 文档序前兄弟（用于 '+' 和 '~'）
 *   ]
 *
 * 本类为纯函数；C2.2 不接线（零行为变化），C2.4 由运行时通道消费。
 */
final class SelectorChecker
{
    /**
     * 复杂选择器（parse 的单项）是否匹配 element。
     * @param array $complex parse() 单项（compounds + combinators）
     */
    public static function matches(array $complex, array $element): bool
    {
        $compounds = $complex['compounds'];
        $combinators = $complex['combinators'];
        $n = count($compounds);
        if ($n === 0) return false;

        // subject = 末 compound，必须匹配 element 自身
        if (!self::matchCompound($compounds[$n - 1], $element)) {
            return false;
        }
        // 右向左回溯：compounds[n-2] .. compounds[0]，组合子 combinators[i] 连接 [i] 与 [i+1]
        return self::matchAncestors($compounds, $combinators, $n - 2, $element);
    }

    /**
     * 便捷：选择器串（可含逗号列表）任一复杂选择器匹配 element。
     */
    public static function matchesSelector(string $selector, array $element): bool
    {
        foreach (SelectorParser::parse($selector) as $complex) {
            if (self::matches($complex, $element)) return true;
        }
        return false;
    }

    /** 从 compoundIdx 起右向左匹配剩余链，$subject 为当前锚定元素。 */
    private static function matchAncestors(array $compounds, array $combinators, int $compoundIdx, array $subject): bool
    {
        if ($compoundIdx < 0) return true;
        $comb = $combinators[$compoundIdx]; // 连接 compounds[compoundIdx] 与右侧
        $target = $compounds[$compoundIdx];

        switch ($comb) {
            case '>': // 子：直接父必须匹配
                $parent = self::directParent($subject);
                if ($parent === null || !self::matchCompound($target, $parent)) return false;
                return self::matchAncestors($compounds, $combinators, $compoundIdx - 1, $parent);

            case ' ': // 后代：任意祖先匹配（回溯所有祖先）
                $ancestors = $subject['ancestors'] ?? [];
                for ($k = count($ancestors) - 1; $k >= 0; $k--) {
                    if (self::matchCompound($target, $ancestors[$k])) {
                        if (self::matchAncestors($compounds, $combinators, $compoundIdx - 1, $ancestors[$k])) {
                            return true;
                        }
                    }
                }
                return false;

            case '+': // 相邻前兄弟
                $prev = self::adjacentPrev($subject);
                if ($prev === null || !self::matchCompound($target, $prev)) return false;
                return self::matchAncestors($compounds, $combinators, $compoundIdx - 1, $prev);

            case '~': // 通用前兄弟
                $sibs = $subject['prevSiblings'] ?? [];
                for ($k = count($sibs) - 1; $k >= 0; $k--) {
                    if (self::matchCompound($target, $sibs[$k])) {
                        if (self::matchAncestors($compounds, $combinators, $compoundIdx - 1, $sibs[$k])) {
                            return true;
                        }
                    }
                }
                return false;
        }
        return false;
    }

    private static function directParent(array $element): ?array
    {
        $anc = $element['ancestors'] ?? [];
        return empty($anc) ? null : $anc[count($anc) - 1];
    }

    private static function adjacentPrev(array $element): ?array
    {
        $sibs = $element['prevSiblings'] ?? [];
        return empty($sibs) ? null : $sibs[count($sibs) - 1];
    }

    /** 单个 compound 是否匹配 element 自身（tag/id/class/attr/pseudo）。 */
    private static function matchCompound(array $compound, array $element): bool
    {
        $tag = $compound['tag'];
        if ($tag !== null && $tag !== '*' && strtolower((string)($element['tag'] ?? '')) !== $tag) {
            return false;
        }
        if ($compound['id'] !== null && (string)($element['id'] ?? '') !== $compound['id']) {
            return false;
        }
        $elClasses = $element['classes'] ?? [];
        foreach ($compound['classes'] as $cls) {
            if (!in_array($cls, $elClasses, true)) return false;
        }
        foreach ($compound['attrs'] as $attr) {
            $elAttrs = $element['attrs'] ?? [];
            if ($attr['op'] === 'exists') {
                if (!array_key_exists($attr['name'], $elAttrs)) return false;
            } else { // '='
                if (($elAttrs[$attr['name']] ?? null) !== $attr['value']) return false;
            }
        }
        foreach ($compound['pseudoClasses'] as $pc) {
            if (!self::matchPseudoClass($pc, $element)) return false;
        }
        return true;
    }

    /** 伪类匹配：nth-child(an+b) / not(简单选择器) / first-child / last-child。 */
    private static function matchPseudoClass(array $pc, array $element): bool
    {
        $name = $pc['name'];
        $arg = $pc['arg'];
        switch ($name) {
            case 'first-child':
                return (int)($element['index'] ?? 0) === 1;
            case 'last-child':
                return !empty($element['isLastChild']);
            case 'nth-child':
                return self::matchNth((string)$arg, (int)($element['index'] ?? 0));
            case 'not':
                // :not(简单选择器)——arg 作为单 compound 解析，取反
                $parsed = SelectorParser::parse((string)$arg);
                if (empty($parsed)) return true;
                $inner = $parsed[0]['compounds'][0] ?? null;
                if ($inner === null) return true;
                return !self::matchCompound($inner, $element);
            case 'hover': case 'focus': case 'active':
                // 状态伪类：静态匹配阶段视为匹配（运行时叠加，C2.8）
                return true;
        }
        return false; // 未知伪类不匹配
    }

    /**
     * :nth-child(an+b) 公式匹配（1-based index）。
     * 支持 odd/even/整数/an+b/负 a。
     */
    public static function matchNth(string $formula, int $index): bool
    {
        if ($index < 1) return false;
        $f = strtolower(str_replace(' ', '', $formula));
        if ($f === 'odd') { return $index % 2 === 1; }
        if ($f === 'even') { return $index % 2 === 0; }

        // 纯整数
        if (preg_match('/^-?\d+$/', $f)) {
            return $index === (int)$f;
        }
        // an+b 形式：解析 a 与 b
        if (preg_match('/^([+-]?\d*)n([+-]\d+)?$/', $f, $m)) {
            $aStr = $m[1];
            if ($aStr === '' || $aStr === '+') $a = 1;
            elseif ($aStr === '-') $a = -1;
            else $a = (int)$aStr;
            $b = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
            // index = a*k + b，k>=0 整数 → (index-b)/a 为非负整数
            if ($a === 0) return $index === $b;
            $k = ($index - $b) / $a;
            return $k >= 0 && floor($k) === (float)$k;
        }
        return false;
    }
}
