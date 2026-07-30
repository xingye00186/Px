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
                self::$rules[] = $r;
            }
        }
    }

    public static function reset(): void
    {
        self::$rules = [];
        self::$registeredClasses = [];
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
        foreach (self::$rules as $rule) {
            $matched = false;
            foreach (($rule['ast'] ?? []) as $complex) {
                if (is_array($complex) && SelectorChecker::matches($complex, $element)) {
                    $matched = true;
                    break;
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
}
