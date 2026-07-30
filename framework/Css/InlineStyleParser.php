<?php

namespace Px\Css;

use native_types;

/**
 * InlineStyleParser — 样式解析器
 *
 * 职责分离：
 *   parseInlineStyle() — 解析内联样式字符串为声明数组（供合并后传入 ComputedStyle 构造器）
 *   resolve() — 合并内联样式 + class 样式 + 继承，输出 ComputedStyle 不可变快照
 *
 * Phase 2 新增。
 */
class InlineStyleParser
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
        array &$pseudoStyles = [],
        array $ancestorClassLists = [],
        array $elementCtx = []
    ): ComputedStyle {
        // 防御：空 string → []（编译期未覆盖的空 style 路径）
        if (!is_array($inlineStyle)) {
            $inlineStyle = [];
        }
        // 1. 内联样式已经是编译期数组，直接使用

        // 2. 合并 CSS class 样式 + tag 选择器样式（CSS 层叠：class 是 base，inline 覆盖）
        // 2. CSS class 样式：由 StyleEngine（gen StyleSheetContents 规则引擎）产出。
        // C2.9：旧 resolveClassStyles 读 ThemeProvider 注册表（生产恒空）已删除；
        // 两者等价性经 tools/c25_equivalence_gate.php 验证（425 元素/6344 属性
        // MISMATCH 0）。引擎空（未注册）= 空声明，行为同旧注册表恒空。
        $declarations = [];
        $engineFp = '';
        if (!empty($elementCtx) && StyleEngine::ruleCount() > 0) {
            foreach (StyleEngine::declarationsFor($elementCtx) as $k => $v) {
                $declarations[$k] = $v;
            }
            // C2.9 前提：状态伪类叠加声明由引擎产出（取代注册表
            // extractPseudoStyles）。进入 by-ref pseudoStyles，供 Paint 期叠加；
            // 不覆盖已有 state（烘焙/注册表优先，双通道并行期安全）。
            foreach (StyleEngine::pseudoStylesFor($elementCtx) as $st => $decls) {
                if (!isset($pseudoStyles[$st])) {
                    $pseudoStyles[$st] = $decls;
                }
            }
            // 引擎声明依赖完整元素上下文（ancestors/nth/兄弟），超出既有
            // key 维度 → 上下文指纹入 key，避免跨元素池碰撞。（AOT：无闭包）
            $ancClasses = [];
            foreach (($elementCtx['ancestors'] ?? []) as $anc) {
                $ancClasses[] = $anc['classes'] ?? [];
            }
            $sibClasses = [];
            foreach (($elementCtx['prevSiblings'] ?? []) as $sib) {
                $sibClasses[] = $sib['classes'] ?? [];
            }
            $engineFp = md5(serialize([
                $elementCtx['classes'] ?? [], $elementCtx['tag'] ?? '',
                $elementCtx['index'] ?? 0, $ancClasses, $sibClasses,
            ]));
        }
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
            $className,
            // C2.6（key 部分）：兄弟指纹入 key，修兄弟组合子跨元素
            // 缓存碰撞（C2.4 残留；只影响含前序兄弟的运行时通道）。
            // C2.5：引擎活跃时附加上下文指纹（引擎空时 '' 不改既有 key）。
            (empty($precedingSiblingClasses) ? '' : implode(',', $precedingSiblingClasses)) . $engineFp
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
        // C1.3：!important 不再混入值串（此前 (?:!important)? 在 [^;]+ 贪婪吞噬后
        // 永不匹配，值带 " !important" 尾巴流入解析器）；按 CascadeResolver 槽位
        // 语义，important 声明后写胜同名 normal（值级烘焙形态：两遍收集）。
        $rawImportant = [];
        foreach ($m as $decl) {
            $prop = strtolower(trim($decl[1]));
            $val = trim($decl[2]);
            if (preg_match('/^(.*?)\s*!\s*important\s*$/i', $val, $im)) {
                $rawImportant[$prop] = trim($im[1]);
            } else {
                $raw[$prop] = $val;
            }
        }
        foreach ($rawImportant as $prop => $val) {
            $raw[$prop] = $val;
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
        // flex 简写展开已启用（§9.2）：basis 按 §7.1.1 展开为 0%（非 0px），
        // is_fixed_block_size + min-content 链路就位后重验；FlexAlgorithm 保留 $cs->flex 回退兼容。
        $raw = CssShorthandExpander::expandAll($raw, true);

        // Apply PROPERTY_MAP parsers
        // 静态缓存合并结果（避免每元素/帧重复 array_merge 两个 const 数组）
        static $lookupCache = null;
        if ($lookupCache === null) {
            $lookupCache = array_merge(CssMappings::getPropertyMap(), CssMappings::getInlinePropertyMap());
        }
        $lookup = $lookupCache;

        // text-decoration 已由 CssShorthandExpander::expandAll 内部处理，无需再单独调用

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
     * AOT 兼容的 parser dispatcher。
     * 替代 CssMappings::dispatchParser()。
     */
    private static function dispatchParser(string $parser, string $value): mixed
    {
        // C4 全局 CSS 关键字（同 CssMappings::dispatchParser）：保留关键字串
        // 穿透，由 ComputedStyle 合并段按级联语义解析。
        $lv = strtolower(trim($value));
        if ($lv === 'inherit' || $lv === 'initial' || $lv === 'unset' || $lv === 'revert') {
            return $lv;
        }
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
        // 静态查表缓存（避免每属性每帧重复 explode+ucfirst）
        static $cache = [];
        if (isset($cache[$str])) return $cache[$str];
        $parts = explode('-', $str);
        $result = array_shift($parts);
        foreach ($parts as $part) {
            $result .= ucfirst($part);
        }
        // 容量限制 256（防止不常见属性名累积）
        if (count($cache) < 256) $cache[$str] = $result;
        return $result;
    }

}
