<?php

namespace Px\Animation;

use Px\Css\CssMappings;
use Px\Css\InlineStyleParser;

/**
 * CssAnimationParser — CSS 动画解析器
 *
 * 负责解析：
 * 1. @keyframes 规则
 * 2. CSS transition/animation 属性
 * 3. 动画类名转换（enter/leave 状态）
 *
 * AOT 兼容: 使用 protected static 数组，不使用闭包
 */
class CssAnimationParser
{
    // 已注册的 @keyframes 定义
    /** @var array<string, array<int, array<string, mixed>>> keyframeName => [percentage => [property => value]] */
    protected static array $keyframes = [];

    // 已解析的 transition/animation 规则
    /** @var array<string, array> className => parsed rules */
    protected static array $transitionRules = [];
    protected static array $animationRules = [];

    // ============================================================
    // @keyframes 注册
    // ============================================================

    /**
     * 注册 @keyframes 规则。
     *
     * @param string $name       动画名称
     * @param array $keyframes   keyframes 定义
     *   [
     *     0 => ['opacity' => 0, 'transform' => 'translateX(-100%)'],
     *     50 => ['opacity' => 1, 'transform' => 'translateX(0)'],
     *     100 => ['opacity' => 0, 'transform' => 'translateX(100%)'],
     *   ]
     */
    public static function registerKeyframes(string $name, array $keyframes): void
    {
        self::$keyframes[$name] = $keyframes;
    }

    /**
     * 获取已注册的 @keyframes。
     */
    public static function getKeyframes(string $name): array
    {
        return self::$keyframes[$name] ?? [];
    }

    /**
     * 检查是否已注册 @keyframes。
     */
    public static function hasKeyframes(string $name): bool
    {
        return isset(self::$keyframes[$name]);
    }

    /**
     * 解析 @keyframes CSS 并注册。
     *
     * @param string $css @keyframes 规则文本
     * 示例:
     *   @keyframes fadeIn {
     *     0% { opacity: 0; transform: translateX(-100%); }
     *     50% { opacity: 1; transform: translateX(0); }
     *     100% { opacity: 0; transform: translateX(100%); }
     *   }
     */
    public static function parseAndRegisterKeyframes(string $css): void
    {
        // 匹配 @keyframes name { ... }
        if (!preg_match_all('/@keyframes\s+([a-zA-Z0-9_-]+)\s*\{([^}]+(?:\{[^}]*\}[^}]*)*)\}/s', $css, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $name = $match[1];
            $body = $match[2];

            $frames = [];

            // 匹配每个关键帧: 0% { ... } 或 from { ... } 或 to { ... }
            if (preg_match_all('/(\d+(?:\.\d+)?%|(?:from|to))\s*\{([^}]*)\}/s', $body, $frameMatches, PREG_SET_ORDER)) {
                foreach ($frameMatches as $frameMatch) {
                    $percent = $frameMatch[1];

                    // 转换 from/to 为百分比
                    if ($percent === 'from') {
                        $percent = 0;
                    } elseif ($percent === 'to') {
                        $percent = 100;
                    } else {
                        $percent = (float)rtrim($percent, '%');
                    }

                    // 解析帧内的 CSS 属性
                    $props = InlineStyleParser::parseInlineStyle($frameMatch[2]);
                    $frames[(int)$percent] = $props;
                }
            }

            // 按百分比排序
            ksort($frames);

            self::registerKeyframes($name, $frames);
        }
    }

    // ============================================================
    // Transition 规则解析
    // ============================================================

    /**
     * 解析 CSS transition 属性并缓存。
     *
     * @param string $className  CSS 类名
     * @param string $transition CSS transition 值
     */
    public static function registerTransition(string $className, string $transition): void
    {
        self::$transitionRules[$className] = CssMappings::parseTransition($transition);
    }

    /**
     * 获取类的 transition 规则。
     */
    public static function getTransitionRules(string $className): array
    {
        return self::$transitionRules[$className] ?? [];
    }

    /**
     * 从 RenderNode 的 class 属性查找匹配的 transition 规则。
     * 返回第一个命中的规则集（对标 CSS：就近原则，后声明优先）。
     * 用于 B2 自动触发：updateFromVNode diff 检测后查询节点是否应产生过渡。
     *
     * @return array{property:string,duration:int,timing:string,delay:int}[]
     */
    public static function getTransitionRulesForClasses(string $classAttr): array
    {
        if ($classAttr === '') return [];
        $classes = preg_split('/\s+/', trim($classAttr));
        // 倒序遍历（后声明优先，对标 CSS specificity 同权时源序后则胜）
        for ($i = count($classes) - 1; $i >= 0; $i--) {
            $rules = self::$transitionRules[$classes[$i]] ?? null;
            if ($rules !== null && count($rules) > 0) {
                return $rules;
            }
        }
        return [];
    }

    /**
     * 解析 <style> 块中的所有 transition 规则。
     *
     * @param string $styleCss <style> 块内容
     */
    public static function parseStyleBlockTransitions(string $styleCss): void
    {
        if (!preg_match_all('/\.([a-zA-Z0-9_-]+)\s*\{([^}]*transition[^}]*)\}/s', $styleCss, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $className = $match[1];
            $body = $match[2];

            // 提取 transition 属性
            if (preg_match('/transition\s*:\s*([^;]+)/', $body, $m)) {
                self::registerTransition($className, trim($m[1]));
            }
        }
    }

    // ============================================================
    // Animation 规则解析
    // ============================================================

    /**
     * 解析 CSS animation 属性并缓存。
     *
     * @param string $className  CSS 类名
     * @param string $animation  CSS animation 值
     */
    public static function registerAnimation(string $className, string $animation): void
    {
        self::$animationRules[$className] = CssMappings::parseAnimation($animation);
    }

    /**
     * 获取类的 animation 规则。
     */
    public static function getAnimationRules(string $className): array
    {
        return self::$animationRules[$className] ?? [];
    }

    // ============================================================
    // 动画类名生成（Vue Transition 语义）
    // ============================================================

    /**
     * 生成 enter/leave 过渡的 CSS 类名。
     *
     * @param string $prefix 过渡名称前缀
     * @param string $phase  进入/离开阶段 ('enter' / 'leave')
     * @param string $state  状态 ('active' / 'from' / 'to')
     * @return string CSS 类名
     *
     * 示例:
     *   generateTransitionClass('fade', 'enter', 'active') → 'fade-enter-active'
     *   generateTransitionClass('fade', 'enter', 'from')  → 'fade-enter-from'
     *   generateTransitionClass('fade', 'leave', 'to')    → 'fade-leave-to'
     */
    public static function generateTransitionClass(string $prefix, string $phase, string $state): string
    {
        return "{$prefix}-{$phase}-{$state}";
    }

    /**
     * 检查类名是否为过渡相关的 enter 类。
     *
     * @param string $className CSS 类名
     * @param string $prefix    过渡名称前缀
     * @return bool
     */
    public static function isEnterClass(string $className, string $prefix): bool
    {
        return str_starts_with($className, "{$prefix}-enter");
    }

    /**
     * 检查类名是否为过渡相关的 leave 类。
     *
     * @param string $className CSS 类名
     * @param string $prefix    过渡名称前缀
     * @return bool
     */
    public static function isLeaveClass(string $className, string $prefix): bool
    {
        return str_starts_with($className, "{$prefix}-leave");
    }

    // ============================================================
    // 工具方法
    // ============================================================

    /**
     * 获取 @keyframes 插值帧。
     *
     * @param string $name 动画名称
     * @param float $progress 当前进度 [0, 1]
     * @return array 插值后的属性值
     */
    public static function getInterpolatedKeyframe(string $name, float $progress): array
    {
        $frames = self::getKeyframes($name);
        if (empty($frames)) {
            return [];
        }

        $percent = $progress * 100;
        $prevFrame = null;
        $nextFrame = null;

        // 查找相邻帧
        $frameKeys = array_keys($frames);
        foreach ($frameKeys as $key) {
            if ($key <= $percent) {
                $prevFrame = $key;
            }
            if ($key > $percent && $nextFrame === null) {
                $nextFrame = $key;
            }
        }

        // 处理边界情况
        if ($prevFrame === null) {
            return $frames[$frameKeys[0]] ?? [];
        }
        if ($nextFrame === null) {
            return $frames[$frameKeys[count($frameKeys) - 1]] ?? [];
        }

        // 在两帧之间插值
        $t = ($percent - $prevFrame) / ($nextFrame - $prevFrame);
        return self::interpolateKeyframes(
            $frames[$prevFrame],
            $frames[$nextFrame],
            $t
        );
    }

    /**
     * 在两个关键帧之间插值属性。
     *
     * @param array $from 起始帧属性
     * @param array $to   目标帧属性
     * @param float $t   插值进度 [0, 1]
     * @return array 插值后的属性
     */
    private static function interpolateKeyframes(array $from, array $to, float $t): array
    {
        $result = [];

        foreach ($to as $prop => $toValue) {
            $fromValue = $from[$prop] ?? null;

            if ($fromValue === null) {
                $result[$prop] = $toValue;
                continue;
            }

            // 根据属性类型选择插值方式
            $result[$prop] = self::interpolateProperty($prop, $fromValue, $toValue, $t);
        }

        return $result;
    }

    /**
     * 根据属性类型插值单个属性值。
     */
    private static function interpolateProperty(string $prop, mixed $from, mixed $to, float $t): mixed
    {
        // 数值属性
        if (is_numeric($from) && is_numeric($to)) {
            return Interpolator::lerpFloat((float)$from, (float)$to, $t);
        }

        // 颜色属性
        if (is_int($from) && is_int($to) && ($prop === 'color' || $prop === 'backgroundColor' || $prop === 'bg')) {
            return Interpolator::interpolateColor($from, $to, $t);
        }

        // Transform（translateX/translateY）
        if ($prop === 'transform') {
            $fromTransform = CssMappings::parseTransform($from);
            $toTransform = CssMappings::parseTransform($to);
            $result = Interpolator::interpolateTransform($fromTransform, $toTransform, $t);
            return CssMappings::buildTransformString($result);
        }

        // 其他属性：返回目标值
        return $to;
    }

    /**
     * 清空所有注册的规则（测试用）。
     */
    public static function reset(): void
    {
        self::$keyframes = [];
        self::$transitionRules = [];
        self::$animationRules = [];
    }
}
