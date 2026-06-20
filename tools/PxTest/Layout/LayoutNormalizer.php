<?php

namespace PxTest\Layout;

/**
 * LayoutNormalizer — 引擎 layout 树 → 浏览器兼容扁平格式的规范器。
 *
 * 功能：
 *   1. 树结构 → 扁平 elements[]（含 depth）
 *   2. type → tag | style → styles | content → text
 *   3. 引擎 camelCase 样式键 → CSS 属性名（fontFamily → font-size）
 *   4. 移除引擎内部字段（visualW/H, layer, isScrollContainer 等）
 *   5. 输出格式与 browser_ref_level_0.json 完全一致
 *
 * 输出格式：
 *   {
 *     "viewport": { "width": N, "height": N },
 *     "elements": [
 *       { "tag": "div", "x": N, "y": N, "w": N, "h": N, "depth": N,
 *         "styles": { "font-size": "...", ... },
 *         "text": "..." }
 *     ]
 *   }
 */
class LayoutNormalizer
{
    /** 引擎样式键 → CSS 属性名映射 */
    private const STYLE_KEY_MAP = [
        'fontFamily'       => 'font-family',
        'fontSize'         => 'font-size',
        'fontWeight'       => 'font-weight',
        'lineHeight'       => 'line-height',
        'color'            => 'color',
        'fg'               => 'color',        // engine fg → CSS color
        'bg'               => 'background-color',
        'backgroundColor'  => 'background-color',
        'display'          => 'display',
        'position'         => 'position',
        'top'              => 'top',
        'left'             => 'left',
        'width'            => 'width',
        'height'           => 'height',
        'opacity'          => 'opacity',
        'overflow'         => 'overflow',
        'overflowX'        => 'overflow-x',
        'overflowY'        => 'overflow-y',
        'whiteSpace'       => 'white-space',

        // margin
        'marginTop'        => 'margin-top',
        'marginRight'      => 'margin-right',
        'marginBottom'     => 'margin-bottom',
        'marginLeft'       => 'margin-left',

        // padding
        'paddingTop'       => 'padding-top',
        'paddingRight'     => 'padding-right',
        'paddingBottom'    => 'padding-bottom',
        'paddingLeft'      => 'padding-left',

        // border
        'borderWidth'      => 'border-width',
        'borderColor'      => 'border-color',
        'borderTopWidth'   => 'border-top-width',
        'borderTopColor'   => 'border-top-color',
        'borderRightWidth' => 'border-right-width',
        'borderRightColor' => 'border-right-color',
        'borderBottomWidth'=> 'border-bottom-width',
        'borderBottomColor'=> 'border-bottom-color',
        'borderLeftWidth'  => 'border-left-width',
        'borderLeftColor'  => 'border-left-color',
        'borderRadius'     => 'border-radius',

        // flex
        'flexDirection'    => 'flex-direction',
        'flexWrap'         => 'flex-wrap',
        'alignItems'       => 'align-items',
        'justifyContent'   => 'justify-content',
        'gap'              => 'gap',

        // other
        'textAlign'        => 'text-align',
        'boxSizing'        => 'box-sizing',
        'pointerEvents'    => 'pointer-events',

        // engine-specific logical props
        'bold'             => 'font-weight',
        'italic'           => 'font-style',
        'textDecoration'   => 'text-decoration',
    ];

    /** 引擎内部字段，规范化时跳过 */
    private const SKIP_FIELDS = [
        'visualW', 'visualH', 'layer', 'isScrollContainer',
        'scrollTop', 'scrollLeft', 'contentHeight', 'contentWidth',
        'renderOffsetX', 'renderOffsetY', 'textRenderInfo',
        'lastX', 'lastY', 'layoutDirty', 'lastPaintFrame',
        'positioningAncestor', 'positioningAncestorValid',
        'sourceVNode', 'groupId', 'parent', 'animatedStyle',
        'isAnimating', 'children',
    ];

    private int $viewportWidth = 1600;
    private int $viewportHeight = 800;

    public function __construct(int $viewportWidth = 1600, int $viewportHeight = 800)
    {
        $this->viewportWidth = $viewportWidth;
        $this->viewportHeight = $viewportHeight;
    }

    /**
     * 规范化引擎 layout JSON。
     *
     * @param string $layoutJson 引擎输出 JSON 字符串
     * @return string 规范化后的 JSON（与 browser_ref_level_0.json 同格式）
     */
    public function normalize(string $layoutJson): string
    {
        $data = json_decode($layoutJson, true);
        if ($data === null) return $layoutJson;

        $elements = $this->flatten($data, 0);
        $output = [
            'viewport' => [
                'width'  => $this->viewportWidth,
                'height' => $this->viewportHeight,
            ],
            'elements' => $elements,
        ];

        return json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 递归展平树并规范化每个节点。
     * 同时传播继承属性（text-align 等 CSS 继承属性）。
     */
    private function flatten(array $node, int $depth, array $parentInherited = []): array
    {
        $result = [];

        $element = $this->normalizeNode($node, $depth, $parentInherited);
        if ($element !== null) {
            $result[] = $element;
        }

        // 提取当前节点的继承属性传给子节点
        $childInherited = $parentInherited;
        if ($element !== null && isset($element['styles'])) {
            $inheritableKeys = ['text-align'];
            foreach ($inheritableKeys as $key) {
                if (isset($element['styles'][$key])) {
                    $childInherited[$key] = $element['styles'][$key];
                }
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $result = array_merge($result, $this->flatten($child, $depth + 1, $childInherited));
            }
        }

        return $result;
    }

    /**
     * 规范化单个节点：映射键、过滤字段、转换样式。
     */
    private function normalizeNode(array $node, int $depth, array $parentInherited = []): ?array
    {
        $type = $node['type'] ?? '';

        // 跳过不可见和内部类型
        if ($type === '#root') return null;

        // 映射 tag
        $tag = $this->typeToTag($type);

        // 提取文本
        $text = $node['content'] ?? $node['text'] ?? '';
        if (is_array($text)) $text = '';

        $element = [
            'tag'   => $tag,
            'x'     => (int)($node['x'] ?? 0),
            'y'     => (int)($node['y'] ?? 0),
            'w'     => (int)($node['w'] ?? 0),
            'h'     => (int)($node['h'] ?? 0),
            'depth' => $depth,
        ];

        // 样式规范化
        $element['styles'] = $this->normalizeStyle($node['style'] ?? []);

        // 节点字段补全到 styles（引擎把 w/h/position 放在节点字段而非 style 中）
        // 浏览器将这些作为 CSS 属性，所以补全以消除 MISSING
        // 仅当引擎 style 中没导出时补全，避免覆盖引擎已有值
        if (!isset($element['styles']['width']))  $element['styles']['width']  = (int)($node['w'] ?? 0) . 'px';
        if (!isset($element['styles']['height'])) $element['styles']['height'] = (int)($node['h'] ?? 0) . 'px';
        if (!isset($element['styles']['top']))    $element['styles']['top']    = (int)($node['y'] ?? 0) . 'px';
        if (!isset($element['styles']['left']))   $element['styles']['left']   = (int)($node['x'] ?? 0) . 'px';
        if (!isset($element['styles']['position'])) {
            $element['styles']['position'] = $node['style']['position'] ?? 'static';
        }

        // 文本内容

        // CSS 默认值补全：引擎不导出继承属性的默认值，补齐以消除 MISSING
        $cssDefaults = [
            'font-weight'  => '400',
            'font-style'   => 'normal',
            'text-align'   => 'start',
            'white-space'  => 'normal',
            'word-break'   => 'normal',
            'visibility'   => 'visible',
            'opacity'      => '1',
            'cursor'       => 'auto',
            'direction'    => 'ltr',
        ];
        foreach ($cssDefaults as $cssKey => $defaultVal) {
            if (!isset($element['styles'][$cssKey])) {
                $element['styles'][$cssKey] = $defaultVal;
            }
        }

        // CSS 继承传播：父节点有显式 text-align 时覆盖默认值
        // 引擎只导出显式设置的属性，继承的 text-align 不在 style 中
        if (!empty($parentInherited) && isset($parentInherited['text-align'])) {
            if (isset($element['styles']['text-align']) && $element['styles']['text-align'] === 'start') {
                // 'start' 是 CSS 默认值，父节点有不同值时说明应继承
                $element['styles']['text-align'] = $parentInherited['text-align'];
            }
        }

        if ($text !== '') {
            $element['text'] = mb_strlen($text) > 200 ? mb_substr($text, 0, 200) : (string)$text;
        }

        return $element;
    }

    /**
     * 引擎 type → 浏览器 tag。
     */
    private function typeToTag(string $type): string
    {
        return match ($type) {
            '#text'  => 'span',
            '#root'  => 'div',
            default  => $type,
        };
    }

    /**
     * 规范化样式：引擎 camelCase → CSS 属性名 + 值格式化。
     */
    private function normalizeStyle(array $style): array
    {
        $normalized = [];

        foreach ($style as $key => $value) {
            // 跳过引擎内部值
            if ($value === null || $value === '') continue;

            $cssKey = self::STYLE_KEY_MAP[$key] ?? null;
            if ($cssKey === null) continue;  // 跳过未映射的引擎字段

            // 如果 fontWeight 已处理（保留原始 CSS 数值），跳过 bold 的硬编码映射
            if ($key === 'bold' && isset($normalized['font-weight'])) {
                continue;
            }

            // 值规范化
            $cssValue = $this->normalizeStyleValue($key, $value, $cssKey, $style);
            if ($cssValue === null) continue;

            $normalized[$cssKey] = $cssValue;
        }

        // bgFromGradient 标记：元素有 linear-gradient 背景，无实质 background-color
        if (!empty($style['bgFromGradient'])) {
            unset($normalized['background-color']);
        }

        // ─── 统一 border-color: 从 per-side 颜色构建完整值 ───
        // 引擎同时导出 borderColor(单色) 和 borderTopColor 等(四边)，
        // 浏览器 border-color 包含全部四边值。
        // 但当 per-side 值已覆盖所有边时，移除冗余的 border-color 简写
        // 因为在比较时浏览器会跳过 border-color（已在 $BROWSER_DEFAULT_SKIP_KEYS）
        if (isset($normalized['border-color'])) {
            $sides = ['border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color'];
            $allSides = [];
            foreach ($sides as $side) {
                if (isset($normalized[$side])) {
                    $allSides[] = $normalized[$side];
                } else {
                    $allSides[] = $normalized['border-color'];
                }
            }
            $unique = array_unique($allSides);
            $hasPerSideColors = count(array_filter([
                isset($normalized['border-top-color']),
                isset($normalized['border-right-color']),
                isset($normalized['border-bottom-color']),
                isset($normalized['border-left-color']),
            ])) > 0;
            if ($hasPerSideColors) {
                // per-side 已能覆盖所有边框颜色，移除冗余的简写
                unset($normalized['border-color']);
            } else {
                $normalized['border-color'] = count($unique) === 1
                    ? $unique[0]
                    : implode(' ', $allSides);
            }
        }

        // ─── 统一 border-width: 同上逻辑 ───
        if (isset($normalized['border-width'])) {
            $sides = ['border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width'];
            $allSides = [];
            foreach ($sides as $side) {
                if (isset($normalized[$side])) {
                    $allSides[] = $normalized[$side];
                } else {
                    $allSides[] = $normalized['border-width'];
                }
            }
            $unique = array_unique($allSides);
            $hasPerSideWidths = count(array_filter([
                isset($normalized['border-top-width']),
                isset($normalized['border-right-width']),
                isset($normalized['border-bottom-width']),
                isset($normalized['border-left-width']),
            ])) > 0;
            if ($hasPerSideWidths) {
                unset($normalized['border-width']);
            } else {
                $normalized['border-width'] = count($unique) === 1
                    ? $unique[0]
                    : implode(' ', $allSides);
            }
        }

        return $normalized;
    }

    /**
     * 规范化样式值。
     * 处理：bg=-1 跳过、fg 整数转 rgb、bold 转 font-weight 值等。
     */
    private function normalizeStyleValue(string $engineKey, mixed $value, string $cssKey, array $fullStyle = []): ?string
    {
        // line-height 因子 → px：引擎存储原始因子(1.6)，浏览器导出计算值(22.4px)
        // 用元素自身的 font-size 计算以匹配浏览器格式
        if ($engineKey === 'lineHeight' && is_numeric($value) && (float)$value < 10) {
            $fs = 16; // 默认 font-size
            if (isset($fullStyle['fontSize']) && is_numeric($fullStyle['fontSize'])) {
                $fs = (int)$fullStyle['fontSize'];
            }
            $px = (float)$value * $fs;
            // 保留一位小数（与浏览器格式对齐）
            return number_format($px, 1) . 'px';
        }
        // bg/fg/borderColor: engine 用 0x00BBGGRR (COLORREF/GDI 格式)，转 rgb() 字符串
        // CssValueParser::hexToBgr 存储为 (b<<16)|(g<<8)|r 即 0x00BBGGRR
        if (in_array($engineKey, ['bg', 'fg', 'borderColor', 'borderTopColor', 'borderRightColor',
            'borderBottomColor', 'borderLeftColor', 'scrollbarTrackColor', 'scrollbarThumbColor'], true)
            && is_int($value)
        ) {
            if ($value === -1) return 'rgba(0, 0, 0, 0)'; // 透明 sentinel
            // COLORREF 格式: 0x00BBGGRR — bits 0-7=R, 8-15=G, 16-23=B
            $r = $value & 0xFF;
            $g = ($value >> 8) & 0xFF;
            $b = ($value >> 16) & 0xFF;
            return "rgb($r, $g, $b)";
        }

        // bold: engine 用 0/1，转 font-weight: 400/700
        if ($engineKey === 'bold') {
            return (int)$value > 0 ? '700' : '400';
        }

        // 直接数值 → 加 px（某些 CSS 属性需要）
        if (is_int($value) || is_float($value)) {
            // 对需要 px 单位的属性添加 px
            $pxProperties = [
                'font-size', 'line-height', 'padding-top', 'padding-right',
                'padding-bottom', 'padding-left', 'margin-top', 'margin-right',
                'margin-bottom', 'margin-left', 'border-width', 'border-top-width',
                'border-right-width', 'border-bottom-width', 'border-left-width',
                'border-radius', 'width', 'height', 'top', 'left', 'gap',
            ];
            if (in_array($cssKey, $pxProperties, true)) {
                return (string)(int)$value . 'px';
            }
            return (string)$value;
        }

        // 字符串值：直接返回（trim 空格）
        if (is_string($value)) {
            // 引擎字体名格式：'segoe ui',sans-serif → 浏览器格式
            if ($cssKey === 'font-family') {
                // 移除单引号，保持标准 CSS
                $v = str_replace("'", '"', $value);
                return $v;
            }
            return $value;
        }

        return (string)$value;
    }
}
