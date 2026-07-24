<?php

namespace Px\Css;

use native_types;

use Px\Theme\ThemeProvider;

/**
 * StyleResolver — 样式解析器
 *
 * 职责分离：
 *   parseInlineStyle() — 解析内联样式字符串为声明数组（供合并后传入 ComputedStyle 构造器）
 *   resolve() — 合并内联样式 + class 样式 + 继承，输出 ComputedStyle 不可变快照
 *
 * Phase 2 新增。
 */
class StyleResolver
{
    /** @var array 已注册的 class styles */
    private static array $classStylesCache = [];

    /**
     * 解析元素样式为 ComputedStyle（走 StylePool Flyweight 池）。
     *
     * @param array           $inlineStyle       内联样式数组（编译期已解析）
     * @param string          $className         CSS class 名称
     * @param ?ComputedStyle  $parentCS          父元素 ComputedStyle（用于继承 + 池 key 组成的稳定身份）
     * @param string          $elementType       元素类型（如 'div', 'span'）
     * @param string          $parentClassStr    父元素 class 字符串
     * @param array           $precedingSiblingClasses 前面的兄弟元素 class
     * @param array           $pseudoStyles      伪类/伪元素样式输出（by-ref）
     * @return ComputedStyle 池化实例（同输入必返同一指针）
     */
    public static function resolve(
        array $inlineStyle = [],
        string $className = '',
        ?ComputedStyle $parentCS = null,
        string $elementType = 'div',
        string $parentClassStr = '',
        array $precedingSiblingClasses = [],
        array &$pseudoStyles = []
    ): ComputedStyle {
        // 防御：空 string → []（编译期未覆盖的空 style 路径）
        if (!is_array($inlineStyle)) {
            $inlineStyle = [];
        }
        // 1. 内联样式已经是编译期数组，直接使用

        // 2. 合并 CSS class 样式 + tag 选择器样式（CSS 层叠：class 是 base，inline 覆盖）
        $classDeclarations = self::resolveClassStyles($className, $parentClassStr, $precedingSiblingClasses, $pseudoStyles, $elementType);
        $declarations = $classDeclarations;
        foreach ($inlineStyle as $k => $v) {
            $declarations[$k] = $v;
        }

        // 3. 走 StylePool：池命中则返回复用实例（identity 稳定）；未命中则构造入池。
        // Key 组成：$className | $elementType | inline指纹 | spl_object_id($parentCS)
        // → 父身份代替父内容，避开序列化父数组的 O(depth) 开销。
        // 注：pseudoStyles 由 resolveClassStyles 通过 by-ref 写入，不影响池 key。
        return StylePool::intern(
            $declarations,
            $parentCS,
            $elementType,
            StylePool::fingerprintInline($inlineStyle),
            $className
        );
    }

    /**
     * 解析内联样式字符串为声明数组。
     * replace CssMappings::parseInlineStyle()
     */
    public static function parseInlineStyle(string $styleStr, array $variables = []): array
    {
        if ($styleStr === '') return [];
        $style = [];

        if (!preg_match_all('#([a-zA-Z-][a-zA-Z0-9_-]*)\s*:\s*([^;]+)\s*(?:!important)?\s*;?#', $styleStr, $m, PREG_SET_ORDER)) {
            return $style;
        }

        $raw = [];
        foreach ($m as $decl) {
            $raw[strtolower(trim($decl[1]))] = trim($decl[2]);
        }

        // CSS variable resolution
        $allVariables = $variables;
        foreach ($raw as $propName => $value) {
            if (str_starts_with($propName, '--')) {
                $allVariables[$propName] = $value;
            }
        }

        // margin:auto flags
        $marginAutoFlags = [];
        foreach (['margin-left', 'margin-right', 'margin-top', 'margin-bottom'] as $mp) {
            if (isset($raw[$mp]) && strtolower(trim($raw[$mp])) === 'auto') {
                $flagKey = lcfirst(str_replace('-', '', ucwords($mp, '-'))) . 'Auto';
                $marginAutoFlags[$flagKey] = true;
            }
        }
        if (isset($raw['margin'])) {
            $parts = preg_split('/\s+/', trim($raw['margin']));
            $count = count($parts);
            if ($count === 1 && strtolower(trim($parts[0])) === 'auto') {
                $marginAutoFlags['marginTopAuto'] = true;
                $marginAutoFlags['marginRightAuto'] = true;
                $marginAutoFlags['marginBottomAuto'] = true;
                $marginAutoFlags['marginLeftAuto'] = true;
            } else {
                $count = count($parts);
                if ($count === 2) {
                    // CSS §8.3: 2-value = [top/bottom, left/right]
                    for ($i = 0; $i < 2; $i++) {
                        if (strtolower(trim($parts[$i])) === 'auto') {
                            if ($i === 0) {
                                $marginAutoFlags['marginTopAuto'] = true;
                                $marginAutoFlags['marginBottomAuto'] = true;
                            } else {
                                $marginAutoFlags['marginLeftAuto'] = true;
                                $marginAutoFlags['marginRightAuto'] = true;
                            }
                        }
                    }
                } else {
                    // 3/4-value: top, right, bottom, left
                    for ($i = 0; $i < $count && $i < 4; $i++) {
                        if (strtolower(trim($parts[$i])) === 'auto') {
                            $dirMap = ['marginTopAuto', 'marginRightAuto', 'marginBottomAuto', 'marginLeftAuto'];
                            $marginAutoFlags[$dirMap[$i]] = true;
                        }
                    }
                }
            }
        }

        // Expand shorthand padding/margin to individual values
        // 使用统一展开类（与 SFC 编译器共用同一套展开逻辑）
        $raw = CssShorthandExpander::expandAll($raw, false);

        // Note: flex shorthand expansion (flex:1 → flex-grow:1 + flex-shrink:1 + flex-basis:0px)
        // 暂不在此阶段展开——展开后 basis=0 会绕过 min-width:auto 的 content-size 保护，
        // 导致无完整 min-content 计算时大量回归。待 Phase 5 完整 min-content 计算就绪后启用。
        // FlexAlgorithm 中通过 $cs->flex->basis 回退机制处理。

        // Apply PROPERTY_MAP parsers
        $lookup = array_merge(CssMappings::getPropertyMap(), CssMappings::getInlinePropertyMap());

        // Expand text-decoration shorthand before property parsing
        $raw = \Px\Css\CssMappings::expandTextDecorationShorthand($raw);

        foreach ($raw as $propName => $value) {
            if (str_starts_with($propName, '--')) continue;
            $map = $lookup[$propName] ?? null;
            if ($map !== null) {
                if (count($allVariables) > 0) {
                    $value = CssValueParser::resolveCSSVariables($value, $allVariables);
                }
                $parsed = self::dispatchParser($map['parser'], $value);
                $style[$map['key']] = $parsed;

                // Auto-create percentage/unit shadow keys from CssLength type info
                if ($parsed instanceof CssLength) {
                    if ($parsed->isPercent()) {
                        $style[$map['key'] . 'Percent'] = $parsed->value;
                    }
                    if ($parsed->isRelative()) {
                        $style[$map['key'] . 'Unit'] = $parsed->value . '|' . $parsed->unit;
                    }
                }
            } else {
                $style[self::kebabToCamelCase($propName)] = $value;
            }
        }

        // calc() 百分比偏移支持（在 CssLength 解析后补全）
        foreach ($raw as $propName => $value) {
            if (preg_match('/^calc\s*\(\s*(\d+(?:\.\d+)?)%\s*([+\-])\s*(\d+(?:\.\d+)?)px\s*\)$/i', $value, $m)) {
                $camelKey = self::kebabToCamelCase($propName);
                $styleKey = $camelKey . 'Percent';
                $style[$styleKey] = (float)$m[1];
                $calcOffsetKey = $camelKey . 'CalcOffset';
                $style[$calcOffsetKey] = (int)($m[2] === '-' ? -$m[3] : $m[3]);
            }
        }

        // Apply graph color extraction
        if (isset($raw['background']) && stripos($raw['background'], 'linear-gradient') !== false) {
            $gradientData = CssValueParser::parseLinearGradient($raw['background']);
            if ($gradientData !== null) {
                $style['bgFromGradient'] = true;
                $style['gradientAngle'] = $gradientData['angle'];
                $style['gradientColors'] = $gradientData['colors'];
                $style['gradientStops'] = $gradientData['stops'];
            }
        }

        // Add margin auto flags
        foreach ($marginAutoFlags as $k => $v) {
            $style[$k] = $v;
        }

        // Expand border shorthand
        self::expandBorderHonorsInStyle($raw, $style);

        return $style;
    }

    /**
     * CSS class 样式解析。
     */
    private static function resolveClassStyles(
        string $className,
        string $parentClassStr,
        array $precedingSiblingClasses,
        array &$pseudoStyles,
        string $elementType = 'div'
    ): array {
        $allRegistered = ThemeProvider::getAllClassStyles();
        $classNames = $className !== '' ? explode(' ', $className) : [];
        $merged = [];

        // 应用通用选择器 *（所有元素的最低基线样式）
        foreach ($allRegistered as $compStyles) {
            if (isset($compStyles['*'])) {
                foreach ($compStyles['*'] as $k => $v) {
                    $merged[$k] = $v;
                }
            }
            // 应用 tag 选择器样式（如 body, html, p 等）
            if (isset($compStyles[$elementType])) {
                foreach ($compStyles[$elementType] as $k => $v) {
                    $merged[$k] = $v;
                }
            }
        }

        foreach ($classNames as $cn) {
            if ($cn === '') continue;
            foreach ($allRegistered as $compStyles) {
                if (isset($compStyles[$cn])) {
                    foreach ($compStyles[$cn] as $k => $v) {
                        $merged[$k] = $v;
                    }
                }
                // Pseudo-class variants
                foreach (['hover', 'focus', 'active'] as $pseudo) {
                    $key = $cn . '__' . $pseudo;
                    if (isset($compStyles[$key])) {
                        if (!isset($pseudoStyles[$pseudo])) {
                            $pseudoStyles[$pseudo] = [];
                        }
                        foreach ($compStyles[$key] as $k => $v) {
                            $pseudoStyles[$pseudo][$k] = $v;
                        }
                    }
                }
                // ::before / ::after
                foreach (['before', 'after'] as $pel) {
                    $key = $cn . '__' . $pel;
                    if (isset($compStyles[$key])) {
                        if (!isset($pseudoStyles[$pel])) {
                            $pseudoStyles[$pel] = [];
                        }
                        foreach ($compStyles[$key] as $k => $v) {
                            $pseudoStyles[$pel][$k] = $v;
                        }
                    }
                }
                // Complex selectors
                foreach ($compStyles as $styleKey => $styleValue) {
                    if (str_starts_with((string)$styleKey, '__complex__') && is_array($styleValue)) {
                        if ($styleValue['secondClass'] === $cn) {
                            $matches = CssMappings::matchComplexSelector(
                                $styleValue['combinator'],
                                $styleValue['firstClass'],
                                $styleValue['secondClass'],
                                $parentClassStr,
                                $className,
                                $precedingSiblingClasses
                            );
                            if ($matches) {
                                foreach ($styleValue['props'] as $k => $v) {
                                    $merged[$k] = $v;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * AOT 兼容的 parser dispatcher。
     * 替代 CssMappings::dispatchParser()。
     */
    private static function dispatchParser(string $parser, string $value): mixed
    {
        $method = substr($parser, (int)strrpos($parser, '::') + 2);
        return match ($method) {
            'parseHexColor' => CssValueParser::parseHexColor($value),
            'parsePixels' => CssValueParser::parsePixels($value),
            'parseFlex' => CssValueParser::parseFlex($value),
            'parseFontWeight' => CssValueParser::parseFontWeight($value),
            'parseTextAlign' => CssValueParser::parseTextAlign($value),
            'parseBorder' => CssValueParser::parseBorder($value),
            'parseOpacity' => CssValueParser::parseOpacity($value),
            'parseIdent' => CssValueParser::parseIdent($value),
            'parseLineHeight' => CssValueParser::parseLineHeight($value),
            'parseBackgroundImage' => CssValueParser::parseBackgroundImage($value),
            'parseTransform' => CssValueParser::parseTransform($value),
            'parseBoxShadow' => CssValueParser::parseBoxShadow($value),
            default => $value,
        };
    }

    private static function expandBoxShorthand(array $raw): array
    {
        foreach (['padding', 'margin'] as $prop) {
            if (!isset($raw[$prop]) || $raw[$prop] === '') continue;
            $parts = preg_split('/\s+/', trim($raw[$prop]));
            $nums = [];
            foreach ($parts as $p) {
                $nums[] = (int)preg_replace('/[^-0-9]/', '', $p);
            }
            $count = count($nums);
            if ($count === 0) continue;
            $t = $nums[0];
            $r = $nums[1] ?? $t;
            $b = $nums[2] ?? $t;
            $l = $nums[3] ?? $r;
            // CSS §3.3.7: 简写展开不应覆盖已存在的独立属性
            // 例如 margin:0;margin-bottom:16px 中 margin-bottom:16px 优先
            if (!isset($raw[$prop . '-top'])) $raw[$prop . '-top'] = $t . 'px';
            if (!isset($raw[$prop . '-right'])) $raw[$prop . '-right'] = $r . 'px';
            if (!isset($raw[$prop . '-bottom'])) $raw[$prop . '-bottom'] = $b . 'px';
            if (!isset($raw[$prop . '-left'])) $raw[$prop . '-left'] = $l . 'px';
        }
        return $raw;
    }

    private static function expandBorderHonorsInStyle(array $raw, array &$style): void
    {
        foreach (['border', 'borderTop', 'borderRight', 'borderBottom', 'borderLeft'] as $bp) {
            if (isset($raw[$bp]) && $raw[$bp] !== '') {
                if (!isset($style['borderWidth'])) {
                    $style['borderWidth'] = (int)$raw[$bp];
                }
            }
        }
        if (isset($style['borderWidth']) && $style['borderWidth'] > 0) {
            $bw = $style['borderWidth'];
            foreach (['Top', 'Right', 'Bottom', 'Left'] as $side) {
                $sk = 'border' . $side . 'Width';
                if (!isset($style[$sk])) $style[$sk] = $bw;
            }
        }
    }

    private static function kebabToCamelCase(string $str): string
    {
        $parts = explode('-', $str);
        $result = array_shift($parts);
        foreach ($parts as $part) {
            $result .= ucfirst($part);
        }
        return $result;
    }

    /**
     * 提取伪类/伪元素样式定义（不执行完整样式解析）。
     * 供 updateFromVNode 在 StyleRecalcPass 已运行场景下补充 pseudoStyles 数据。
     *
     * @param string $className CSS class 名称
     * @param string $elementType 元素类型
     * @return array key 为 'hover'/'focus'/'active'/'before'/'after'
     */
    public static function extractPseudoStyles(string $className, string $elementType = 'div'): array
    {
        $pseudoStyles = [];
        if ($className === '') return $pseudoStyles;
        $allRegistered = ThemeProvider::getAllClassStyles();
        $classNames = explode(' ', $className);
        foreach ($classNames as $cn) {
            if ($cn === '') continue;
            foreach ($allRegistered as $compStyles) {
                foreach (['hover', 'focus', 'active'] as $pseudo) {
                    $key = $cn . '__' . $pseudo;
                    if (isset($compStyles[$key])) {
                        if (!isset($pseudoStyles[$pseudo])) {
                            $pseudoStyles[$pseudo] = [];
                        }
                        foreach ($compStyles[$key] as $k => $v) {
                            $pseudoStyles[$pseudo][$k] = $v;
                        }
                    }
                }
                foreach (['before', 'after'] as $pel) {
                    $key = $cn . '__' . $pel;
                    if (isset($compStyles[$key])) {
                        if (!isset($pseudoStyles[$pel])) {
                            $pseudoStyles[$pel] = [];
                        }
                        foreach ($compStyles[$key] as $k => $v) {
                            $pseudoStyles[$pel][$k] = $v;
                        }
                    }
                }
            }
        }
        return $pseudoStyles;
    }
}
