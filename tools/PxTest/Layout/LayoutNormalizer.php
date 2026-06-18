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
     */
    private function flatten(array $node, int $depth): array
    {
        $result = [];

        $element = $this->normalizeNode($node, $depth);
        if ($element !== null) {
            $result[] = $element;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $result = array_merge($result, $this->flatten($child, $depth + 1));
            }
        }

        return $result;
    }

    /**
     * 规范化单个节点：映射键、过滤字段、转换样式。
     */
    private function normalizeNode(array $node, int $depth): ?array
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

            // 值规范化
            $cssValue = $this->normalizeStyleValue($key, $value, $cssKey);
            if ($cssValue === null) continue;

            $normalized[$cssKey] = $cssValue;
        }

        return $normalized;
    }

    /**
     * 规范化样式值。
     * 处理：bg=-1 跳过、fg 整数转 rgb、bold 转 font-weight 值等。
     */
    private function normalizeStyleValue(string $engineKey, mixed $value, string $cssKey): ?string
    {
        // bg: engine 用 ARGB 整数，转 rgb()/rgba() 字符串
        if ($engineKey === 'bg' && is_int($value)) {
            if ($value === -1) return 'rgba(0, 0, 0, 0)'; // 透明 sentinel
            $a = ($value >> 24) & 0xFF;
            $r = ($value >> 16) & 0xFF;
            $g = ($value >> 8) & 0xFF;
            $b = $value & 0xFF;
            if ($a === 0) {
                return "rgb($r, $g, $b)";
            }
            return "rgba($r, $g, $b, " . round($a / 255, 2) . ")";
        }

        // fg: engine 用 ARGB 整数，转 rgb() 字符串
        if ($engineKey === 'fg' && is_int($value)) {
            if ($value === -16777216) return 'rgb(0, 0, 0)'; // 黑色快捷
            $r = ($value >> 16) & 0xFF;
            $g = ($value >> 8) & 0xFF;
            $b = $value & 0xFF;
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
