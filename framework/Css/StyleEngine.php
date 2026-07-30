<?php

namespace Px\Css;

/**
 * StyleEngine — 运行时规则引擎（C2.5-full 第一增量，对标 Blink StyleEngine）。
 *
 * 消费 gen 烘焙的 StyleSheetContents RuleData[]（selector/ast/declarations/
 * specificity/order/scopeId），以 SelectorChecker 全 AST 匹配 +
 * CascadeResolver 层叠排序（slot > specificity > 源序），产出元素的声明映射。
 *
 * 定位：替代生产恒空的 ThemeProvider 注册表（C2.9 前提卡点）。本增量为
 * 纯函数核心 + 存储；烘焙通道保持权威，等价门控通过后再接线切换
 *（C2.5 高风险点：值级烘焙 → 规则 ID 级消费）。
 *
 * AOT 友好：纯静态数组、无闭包、无动态调用。
 */
final class StyleEngine
{
    /** @var array<int, array> RuleData 列表（多组件聚合，注册序） */
    private static array $rules = [];
    
    /** @var array<string, bool> 已注册组件类（幂等去重：同类多实例只注一次） */
    private static array $registeredClasses = [];
    
    /**
     * 注册组件的编译期规则存储（gen: static styleSheetContents()）。
     * C2.5-full 生产激活入口；同类幂等（v-for 多实例不重复膨胀）。
     * AOT 安全：method_exists 静态检查 + 静态调用，无动态属性访问。
     */
    public static function registerComponentRules(object $component): void
    {
        $cls = get_class($component);
        if (isset(self::$registeredClasses[$cls])) return;
        self::$registeredClasses[$cls] = true;
        if (!method_exists($cls, 'styleSheetContents')) return;
        $rules = $cls::styleSheetContents();
        if (is_array($rules)) {
            self::register($rules);
        }
    }

    /**
     * 注册一个组件的 StyleSheetContents 规则（gen: Component::styleSheetContents()）。
     */
    public static function register(array $ruleDataList): void
    {
        foreach ($ruleDataList as $r) {
            if (is_array($r)) {
                $idx = count(self::$rules);
                self::$rules[] = $r;
                self::collectFeatures($r);
                self::bucketRule($idx, $r);
            }
        }
    }

    // ───── C2.7 RuleSet 倒排索引（对标 Blink RuleSet 分桶）─────
    //
    // Blink 按 subject（最右）compound 的最具区分度特征分桶：
    // id → idRules、class → classRules、tag → tagRules、其余 → universalRules。
    // 匹配时仅枚举元素自身 id/classes/tag 对应的桶 + universal，将
    // O(全部规则) 降为 O(相关桶)。
    // 按计划：**默认关闭**，由 $indexEnabled 开关门控；开启与否必须产出
    // 完全一致的声明（索引仅做候选预筛，真实判定仍由 SelectorChecker）。
    private static array $idBuckets = [];
    private static array $classBuckets = [];
    private static array $tagBuckets = [];
    private static array $universalBucket = [];
    private static bool $indexEnabled = false;
    private static int $selectorMatchCount = 0;

    /** 开关门控（默认关）。 */
    public static function setIndexEnabled(bool $on): void { self::$indexEnabled = $on; }
    public static function isIndexEnabled(): bool { return self::$indexEnabled; }

    /** 观测点：累计送入 SelectorChecker 的候选复杂选择器数。 */
    public static function selectorMatchCount(): int { return self::$selectorMatchCount; }
    public static function resetSelectorMatchCount(): void { self::$selectorMatchCount = 0; }

    /**
     * 按 subject compound 将规则归桶。一条规则可含多个选择器（逗号列表），
     * 任一选择器命中即规则命中，故每个选择器各自归桶（同一 ruleIdx 可
     * 出现于多桶，枚举后去重）。
     */
    private static function bucketRule(int $ruleIdx, array $rule): void
    {
        foreach (($rule['ast'] ?? []) as $complex) {
            if (!is_array($complex)) continue;
            $compounds = $complex['compounds'] ?? [];
            if (!is_array($compounds) || empty($compounds)) {
                self::$universalBucket[] = $ruleIdx;
                continue;
            }
            $subject = $compounds[count($compounds) - 1];
            if (!is_array($subject)) {
                self::$universalBucket[] = $ruleIdx;
                continue;
            }
            $id = $subject['id'] ?? null;
            if (is_string($id) && $id !== '') {
                self::$idBuckets[$id][] = $ruleIdx;
                continue;
            }
            $classes = $subject['classes'] ?? [];
            if (is_array($classes) && !empty($classes)) {
                // Blink 选任一类作桶键（其余类由 SelectorChecker 复校）。
                self::$classBuckets[(string)$classes[0]][] = $ruleIdx;
                continue;
            }
            $tag = $subject['tag'] ?? null;
            if (is_string($tag) && $tag !== '' && $tag !== '*') {
                self::$tagBuckets[$tag][] = $ruleIdx;
                continue;
            }
            self::$universalBucket[] = $ruleIdx;
        }
    }

    /**
     * 候选规则下标（升序，保持注册序以不扰动层叠）。
     * 开关关时返回全量下标（线性扫描，与索引开启结果必须一致）。
     */
    private static function candidateRuleIndices(array $element): array
    {
        $n = count(self::$rules);
        if (!self::$indexEnabled) {
            return range(0, $n - 1);
        }
        $seen = [];
        foreach (self::$universalBucket as $i) { $seen[$i] = true; }
        $id = $element['id'] ?? null;
        if (is_string($id) && $id !== '' && isset(self::$idBuckets[$id])) {
            foreach (self::$idBuckets[$id] as $i) { $seen[$i] = true; }
        }
        foreach (($element['classes'] ?? []) as $c) {
            $ck = (string)$c;
            if (isset(self::$classBuckets[$ck])) {
                foreach (self::$classBuckets[$ck] as $i) { $seen[$i] = true; }
            }
        }
        $tag = $element['tag'] ?? null;
        if (is_string($tag) && $tag !== '' && isset(self::$tagBuckets[$tag])) {
            foreach (self::$tagBuckets[$tag] as $i) { $seen[$i] = true; }
        }
        $out = array_keys($seen);
        sort($out);
        return $out;
    }

    /**
     * 规则特征汇总（对标 Blink RuleFeatureSet）。只有当确存在相应组合子
     * 的规则时，样式重算才需维护对应的元素上下文账本：
     * - usesSiblingRules：`+` / `~` —— 需前序兄弟上下文（原本无条件累积，
     *   对 n 个兄弟为 O(n²) 内存，500 兄弟实测峰值 8MB→18MB）。
     * - usesAncestorRules：` ` / `>` —— 需祖先链（O(depth)，成本可控）。
     */
    private static bool $usesSiblingRules = false;
    private static bool $usesAncestorRules = false;

    private static function collectFeatures(array $rule): void
    {
        foreach (($rule['ast'] ?? []) as $complex) {
            if (!is_array($complex)) continue;
            foreach (($complex['combinators'] ?? []) as $comb) {
                if ($comb === '+' || $comb === '~') {
                    self::$usesSiblingRules = true;
                } elseif ($comb === ' ' || $comb === '>') {
                    self::$usesAncestorRules = true;
                }
            }
        }
    }

    public static function usesSiblingRules(): bool { return self::$usesSiblingRules; }
    public static function usesAncestorRules(): bool { return self::$usesAncestorRules; }

    public static function reset(): void
    {
        self::$rules = [];
        self::$registeredClasses = [];
        self::$usesSiblingRules = false;
        self::$usesAncestorRules = false;
        self::$idBuckets = [];
        self::$classBuckets = [];
        self::$tagBuckets = [];
        self::$universalBucket = [];
        self::$selectorMatchCount = 0;
    }

    /**
     * 从 CSS 串注册规则（测试/动态场景便捷入口）。
     * 与 gen 同一构建器 StyleSheetContents::build，避免测试与生产两套形态。
     * C2.9：取代 ThemeProvider::registerClassStyles（注册表格式注入）。
     */
    public static function registerCss(string $css, string $scopeId = ''): void
    {
        self::register(StyleSheetContents::build($css, $scopeId));
    }

    public static function ruleCount(): int
    {
        return count(self::$rules);
    }

    /**
     * 元素匹配的声明映射（CSS 层叠序合并，后写者胜）。
     *
     * @param array $element SelectorChecker 元素上下文
     *   {tag, id, classes, attrs, index, ancestors, prevSiblings}
     * @return array property => value（parseInlineStyle 解析键）
     */
    public static function declarationsFor(array $element): array
    {
        $blocks = [];
        // C2.7：候选枚举（索引开时仅相关桶，关时全量）。升序下标保持
        // 注册序，故 order 与线性扫描一致，层叠结果不变。
        foreach (self::candidateRuleIndices($element) as $ri) {
            $rule = self::$rules[$ri];
            $matched = false;
            foreach (($rule['ast'] ?? []) as $complex) {
                if (is_array($complex)) {
                    self::$selectorMatchCount++;
                    if (SelectorChecker::matches($complex, $element)) {
                        $matched = true;
                        break;
                    }
                }
            }
            if (!$matched) continue;
            $blocks[] = [
                'payload'     => (string)($rule['declarations'] ?? ''),
                'origin'      => CascadeResolver::ORIGIN_AUTHOR,
                'important'   => false,
                'specificity' => $rule['specificity'] ?? [0, 0, 0, 0],
                'order'       => (int)($rule['order'] ?? 0),
            ];
        }
        // 层叠排序后按序合并（后写者胜 = 高优先级后写）
        $merged = [];
        foreach (CascadeResolver::sortDeclarationBlocks($blocks) as $b) {
            $decls = InlineStyleParser::parseInlineStyle((string)$b['payload']);
            foreach ($decls as $k => $v) {
                $merged[$k] = $v;
            }
        }
        return $merged;
    }

    /** 状态伪类集（与 SelectorChecker 同源语义；仅这些参与 Paint 期叠加） */
    private const OVERLAY_STATES = ['hover', 'focus', 'active'];

    /**
     * 元素的状态伪类叠加声明（对标 Blink：:hover 等仅在状态成立时参与）。
     *
     * declarationsFor 产出**基态**声明（状态伪类被拒绝，C2.5 安全修正）；
     * 本方法单独产出 state => 声明映射，供 Paint 期 pseudoStyles 叠加
     *（Px 模型，C2.8）。判定方式：若规则 subject compound 带状态伪类，
     * 则向探针元素注入该状态后重测匹配（其余条件仍须真实成立）。
     *
     * 定位：取代 ThemeProvider 注册表的 extractPseudoStyles（C2.9 前提）。
     *
     * @return array<string, array> state => (property => value)
     */
    public static function pseudoStylesFor(array $element): array
    {
        $byState = [];
        // C2.7：同走候选枚举（索引开关不影响结果）。
        foreach (self::candidateRuleIndices($element) as $ri) {
            $rule = self::$rules[$ri];
            foreach (($rule['ast'] ?? []) as $complex) {
                if (!is_array($complex)) continue;
                $compounds = $complex['compounds'] ?? [];
                $n = count($compounds);
                if ($n === 0) continue;
                $subject = $compounds[$n - 1];
                $states = [];
                foreach (($subject['pseudoClasses'] ?? []) as $pc) {
                    $nm = $pc['name'] ?? '';
                    if (in_array($nm, self::OVERLAY_STATES, true)) {
                        $states[] = $nm;
                    }
                }
                if (empty($states)) continue; // 非状态规则 → 基态通道已处理
                // 注入状态后重测：其余条件（类/tag/组合子/结构伪类）仍须真实成立
                $probe = $element;
                $existing = isset($probe['states']) && is_array($probe['states']) ? $probe['states'] : [];
                $probe['states'] = array_values(array_unique(array_merge($existing, $states)));
                if (!SelectorChecker::matches($complex, $probe)) continue;
                foreach ($states as $st) {
                    if (!isset($byState[$st])) $byState[$st] = [];
                    $byState[$st][] = [
                        'payload'     => (string)($rule['declarations'] ?? ''),
                        'origin'      => CascadeResolver::ORIGIN_AUTHOR,
                        'important'   => false,
                        'specificity' => $rule['specificity'] ?? [0, 0, 0, 0],
                        'order'       => (int)($rule['order'] ?? 0),
                    ];
                }
                break; // 同规则已命中，不重复计入
            }
        }
        $out = [];
        foreach ($byState as $st => $blocks) {
            $merged = [];
            foreach (CascadeResolver::sortDeclarationBlocks($blocks) as $b) {
                foreach (InlineStyleParser::parseInlineStyle((string)$b['payload']) as $k => $v) {
                    $merged[$k] = $v;
                }
            }
            if (!empty($merged)) $out[$st] = $merged;
        }
        return $out;
    }
}
