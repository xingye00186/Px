<?php

namespace Px\Rendering;

use native_types;

use Px\Styling\Provider\ThemeProvider;

/**
 * StyleResolver — 独立样式解析器
 *
 * 合并 CssMappings::parseInlineStyle()、dispatchParser()
 * 和 RenderTreeManager::resolveNodeStyle() 的样式解析逻辑。
 * 输出 ComputedStyle 不可变样式快照。
 *
 * Phase 2 新增，替代散落在多个类的样式解析。
 */
class StyleResolver
{
    /** @var array 已注册的 class styles */
    private static array $classStylesCache = [];

    /**
     * 解析元素样式为 ComputedStyle。
     *
     * @param string $inlineStyle 内联样式字符串（style="..."）
     * @param string $className CSS class 名称
     * @param array|null $parentDeclarations 父元素声明（用于继承）
     * @param string $elementType 元素类型（如 'div', 'span'）
     * @param string $parentClassStr 父元素 class 字符串
     * @param array $precedingSiblingClasses 前面的兄弟元素 class
     * @param array $parentStyleDeclarations 父元素完整声明（用于伪类合并）
     * @return ComputedStyle
     */
    public static function resolve(
        string $inlineStyle = '',
        string $className = '',
        ?array $parentDeclarations = null,
        string $elementType = 'div',
        string $parentClassStr = '',
        array $precedingSiblingClasses = [],
        array $parentStyleDeclarations = [],
        array &$pseudoStyles = []
    ): ComputedStyle {
        // 1. 解析内联样式为声明数组
        $declarations = self::parseInlineStyle($inlineStyle);

        // 2. 合并 CSS class 样式
        $classDeclarations = self::resolveClassStyles($className, $parentClassStr, $precedingSiblingClasses, $pseudoStyles);
        foreach ($classDeclarations as $k => $v) {
            if (!isset($declarations[$k])) {
                $declarations[$k] = $v;
            }
        }

        // 3. 合并父元素声明（继承）
        $parentDecls = $parentDeclarations ?? [];

        // 4. 构建 ComputedStyle
        return new ComputedStyle($declarations, $parentDecls, $elementType);
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
                for ($i = 0; $i < $count && $i < 4; $i++) {
                    if (strtolower(trim($parts[$i])) === 'auto') {
                        $dirMap = ['marginTopAuto', 'marginRightAuto', 'marginBottomAuto', 'marginLeftAuto'];
                        $marginAutoFlags[$dirMap[$i]] = true;
                    }
                }
            }
        }

        // Expand shorthand padding/margin to individual values
        $raw = self::expandBoxShorthand($raw);

        // Pre-detect percentage values
        $pctMap = [
            'width' => 'widthPercent', 'height' => 'heightPercent',
            'min-width' => 'minWidthPercent', 'max-width' => 'maxWidthPercent',
            'min-height' => 'minHeightPercent', 'max-height' => 'maxHeightPercent',
            'margin-top' => 'marginTopPercent', 'margin-right' => 'marginRightPercent',
            'margin-bottom' => 'marginBottomPercent', 'margin-left' => 'marginLeftPercent',
            'padding-top' => 'paddingTopPercent', 'padding-right' => 'paddingRightPercent',
            'padding-bottom' => 'paddingBottomPercent', 'padding-left' => 'paddingLeftPercent',
            'left' => 'leftPercent', 'top' => 'topPercent',
            'right' => 'rightPercent', 'bottom' => 'bottomPercent',
            'border-radius' => 'borderRadiusPercent',
        ];
        foreach ($pctMap as $cssProp => $styleKey) {
            if (isset($raw[$cssProp])) {
                $val = trim($raw[$cssProp]);
                if (str_ends_with($val, '%')) {
                    $style[$styleKey] = (float)substr($val, 0, -1);
                } elseif (preg_match('/^calc\s*\(\s*(\d+(?:\.\d+)?)%\s*([+\-])\s*(\d+(?:\.\d+)?)px\s*\)$/i', $val, $m)) {
                    $style[$styleKey] = (float)$m[1];
                    $calcOffsetKey = str_replace('Percent', 'CalcOffset', $styleKey);
                    $style[$calcOffsetKey] = (int)($m[2] === '-' ? -$m[3] : $m[3]);
                }
            }
        }

        // Pre-detect relative unit values
        $relativeUnitMap = [
            'font-size' => 'fontSizeUnit', 'width' => 'widthUnit', 'height' => 'heightUnit',
            'min-width' => 'minWidthUnit', 'max-width' => 'maxWidthUnit',
            'min-height' => 'minHeightUnit', 'max-height' => 'maxHeightUnit',
            'margin-top' => 'marginTopUnit', 'margin-right' => 'marginRightUnit',
            'margin-bottom' => 'marginBottomUnit', 'margin-left' => 'marginLeftUnit',
            'padding-top' => 'paddingTopUnit', 'padding-right' => 'paddingRightUnit',
            'padding-bottom' => 'paddingBottomUnit', 'padding-left' => 'paddingLeftUnit',
            'gap' => 'gapUnit', 'top' => 'topUnit', 'left' => 'leftUnit',
            'right' => 'rightUnit', 'bottom' => 'bottomUnit',
        ];
        foreach ($relativeUnitMap as $cssProp => $styleKey) {
            if (isset($raw[$cssProp])) {
                $parsed = CssValueParser::parseRelativeValue($raw[$cssProp]);
                if ($parsed['unit'] !== 'px') {
                    $style[$styleKey] = $parsed['value'] . '|' . $parsed['unit'];
                }
            }
        }

        // Apply PROPERTY_MAP parsers
        $lookup = array_merge(CssMappings::getPropertyMap(), CssMappings::getInlinePropertyMap());
        foreach ($raw as $propName => $value) {
            if (str_starts_with($propName, '--')) continue;
            $map = $lookup[$propName] ?? null;
            if ($map !== null) {
                if (count($allVariables) > 0) {
                    $value = CssValueParser::resolveCSSVariables($value, $allVariables);
                }
                $style[$map['key']] = self::dispatchParser($map['parser'], $value);
            } else {
                $style[self::kebabToCamelCase($propName)] = $value;
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
        array &$pseudoStyles
    ): array {
        if ($className === '') return [];

        $allRegistered = ThemeProvider::getAllClassStyles();
        $classNames = explode(' ', $className);
        $merged = [];

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
            $raw[$prop . '-top'] = $t . 'px';
            $raw[$prop . '-right'] = $r . 'px';
            $raw[$prop . '-bottom'] = $b . 'px';
            $raw[$prop . '-left'] = $l . 'px';
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
}
