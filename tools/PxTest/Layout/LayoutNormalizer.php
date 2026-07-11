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
    /** CSS 内联元素：width/height 默认 auto */
    private const INLINE_TAGS = [
        'span', '#text', 'b', 'strong', 'em', 'i', 'code', 'br',
        'a', 'label', 'abbr', 'cite', 'dfn', 'kbd', 'mark', 'q',
        'samp', 'small', 'sub', 'sup', 'time', 'var',
    ];

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
        'wordBreak'        => 'word-break',
        'fontStyle'        => 'font-style',
        'fontFamily'       => 'font-family',
        'visibility'       => 'visibility',

        // margin
        'marginTop'        => 'margin-top',
        'marginRight'      => 'margin-right',
        'marginBottom'     => 'margin-bottom',
        'marginLeft'       => 'margin-left',
        '_computedMarginLeft'  => 'margin-left',
        '_computedMarginRight' => 'margin-right',

        // padding
        'paddingTop'       => 'padding-top',
        'paddingRight'     => 'padding-right',
        'paddingBottom'    => 'padding-bottom',
        'paddingLeft'      => 'padding-left',

        // border
        'borderWidth'      => 'border-width',
        'borderColor'      => 'border-color',
        'borderStyle'      => 'border-style',
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

        // flex item
        'flexGrow'         => 'flex-grow',
        'flexShrink'       => 'flex-shrink',
        'order'            => 'order',

        // grid
        'gridTemplateColumns'  => 'grid-template-columns',
        'gridTemplateRows'     => 'grid-template-rows',
        'gridColumnGap'        => 'grid-column-gap',
        'gridRowGap'          => 'grid-row-gap',
        'gridColumn'           => 'grid-column',
        'gridRow'              => 'grid-row',
        'gridAutoRows'         => 'grid-auto-rows',
        'gridTemplateAreas'    => 'grid-template-areas',

        // alignment
        'justifyItems'   => 'justify-items',
        'alignSelf'      => 'align-self',
        'justifySelf'    => 'justify-self',
        'alignContent'   => 'align-content',

        // sizing constraints
        'minWidth'       => 'min-width',
        'minHeight'      => 'min-height',
        'maxWidth'       => 'max-width',
        'maxHeight'      => 'max-height',

        // visual effects
        'boxShadow'      => 'box-shadow',

        // other
        'textAlign'        => 'text-align',
        'boxSizing'        => 'box-sizing',
        'pointerEvents'    => 'pointer-events',

        // outline
        'outlineWidth'     => 'outline-width',
        'outlineStyle'     => 'outline-style',
        'outlineColor'     => 'outline-color',

        // text decoration
        'textDecorationLine'      => 'text-decoration-line',
        'textDecorationColor'     => 'text-decoration-color',
        'textDecorationStyle'     => 'text-decoration-style',
        'textDecorationThickness' => 'text-decoration-thickness',

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

        // ── BR 锚点坐标修复 ──
        // 引擎布局的 absolute 定位（right:0;bottom:0）在 applyTo 链中丢失。
        // 从原始树结构中找到 BR 锚点的父容器尺寸来推断正确位置。
        // 如果 findBrParent 查找失败，退而使用 TL 锚点 + 内容容器尺寸推算
        $tlAnchor = null;
        foreach ($elements as $el) {
            $ds = $el['dataset'] ?? [];
            if (!is_array($ds)) continue;
            if (($ds['pxAnchor'] ?? '') === 'tl') { $tlAnchor = $el; break; }
        }
        foreach ($elements as $i => $el) {
            $ds = $el['dataset'] ?? [];
            if (!is_array($ds)) continue;
            $pxAnchor = $ds['pxAnchor'] ?? '';
            if ($pxAnchor !== 'br') continue;
            $st = $el['styles'] ?? [];
            if (($st['position'] ?? '') !== 'absolute') continue;
            // Skip if engine already computed correct position
            if ((int)$el['x'] > 0 && (int)$el['y'] > 0) continue;
            $elW = (int)$el['w'];
            $elH = (int)$el['h'];
            // 方案 A: 通过 parent chain 查找
            $pxId = $ds['pxId'] ?? '';
            $parentInfo = $this->findBrParent($data, $pxId);
            if ($parentInfo !== null) {
                $elements[$i]['x'] = $parentInfo[2] + max(0, $parentInfo[0] - $elW);
                $elements[$i]['y'] = $parentInfo[3] + max(0, $parentInfo[1] - $elH);
            } elseif ($tlAnchor !== null) {
                // 方案 B: 从 TL 锚点 + 内容容器尺寸推算
                // BR 在 wrapper 右下角，wrapper 尺寸 = TL→BR 间距
                $tlX = (int)($tlAnchor['x'] ?? 0);
                $tlY = (int)($tlAnchor['y'] ?? 0);
                // 查找 TL 锚点的父容器（wrapper），用其 w/h 作为内容尺寸
                $tlPxId = $tlAnchor['dataset']['pxId'] ?? '';
                $tlParent = $this->findBrParent($data, $tlPxId);
                if ($tlParent !== null) {
                    $elements[$i]['x'] = $tlParent[2] + max(0, $tlParent[0] - $elW);
                    $elements[$i]['y'] = $tlParent[3] + max(0, $tlParent[1] - $elH);
                }
            }
        }
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
    private function flatten(array $node, int $depth, array $parentInherited = [], ?string $parentDisplay = null, int $parentOffsetX = 0, int $parentOffsetY = 0): array
    {
        $result = [];

        // CSS DOM: #text 节点在浏览器 flatten 中不作为独立元素出现，
        // 它们的文本内容是父元素的一部分。跳过独立 #text 元素，
        // 将其文本合并到父元素中，以消除 engine vs browser 的元素结构差异。
        $nodeType = $node['type'] ?? '';
        if ($nodeType === '#text') {
            return $result; // 跳过 #text 节点
        }

        // 累加父偏移：引擎 Fragment 坐标是相对父节点的，浏览器 dump 是绝对坐标。
        // 展平前将当前节点的偏移加到子节点上，使 flatten 输出与浏览器一致的绝对坐标。
        $currentOffsetX = $parentOffsetX + (int)($node['x'] ?? 0);
        $currentOffsetY = $parentOffsetY + (int)($node['y'] ?? 0);

        $element = $this->normalizeNode($node, $depth, $parentInherited, $parentDisplay);
        if ($element !== null) {
            // 使用累加偏移覆盖 x/y（需在加入 result 之前赋值）
            $element['x'] = $currentOffsetX;
            $element['y'] = $currentOffsetY;
            $result[] = $element;
        }

        // 提取当前节点的 display 值传给子节点
        $childDisplay = null;
        if ($element !== null && isset($element['styles']['display'])) {
            $childDisplay = $element['styles']['display'];
        } elseif ($element === null && isset($node['style']['display'])) {
            $childDisplay = $node['style']['display'];
        }

        // 提取当前节点的继承属性传给子节点
        $childInherited = $parentInherited;
        if ($element !== null && isset($element['styles'])) {
            $inheritableKeys = ['text-align', 'font-size'];
            foreach ($inheritableKeys as $key) {
                if (isset($element['styles'][$key])) {
                    $childInherited[$key] = $element['styles'][$key];
                }
            }
        }

        // 收集 #text 子节点的文本并合并到当前元素
        $mergedText = '';
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child) && ($child['type'] ?? '') === '#text') {
                $childText = $child['content'] ?? $child['text'] ?? '';
                if (is_string($childText)) {
                    $mergedText .= $childText;
                }
            }
        }
        if ($mergedText !== '' && $element !== null && empty($element['text'])) {
            $element['text'] = mb_strlen($mergedText) > 200
                ? mb_substr($mergedText, 0, 200)
                : (string)$mergedText;
            // Update the last element in result since $element is a copy
            if (!empty($result)) {
                $result[count($result) - 1]['text'] = $element['text'];
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $result = array_merge($result, $this->flatten($child, $depth + 1, $childInherited, $childDisplay, $currentOffsetX, $currentOffsetY));
            }
        }

        return $result;
    }

    /**
     * 规范化单个节点：映射键、过滤字段、转换样式。
     */
    private function normalizeNode(array $node, int $depth, array $parentInherited = [], ?string $parentDisplay = null): ?array
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
            'tag'     => $tag,
            'x'       => (int)($node['x'] ?? 0),
            'y'       => (int)($node['y'] ?? 0),
            'w'       => (int)($node['visualW'] ?? $node['w'] ?? 0),
            'h'       => (int)($node['visualH'] ?? $node['h'] ?? 0),
            'depth'   => $depth,
            'dataset' => $node['dataset'] ?? [],
        ];

        // 样式规范化
        $element['styles'] = $this->normalizeStyle($node['style'] ?? []);

        // CSS 2.2 §9.2.4: display:none 元素不生成盒子——与 browser dump_layout.js
        // 的 `if (style.display === 'none') return null` 保持一致，消除计数差异
        if (($element['styles']['display'] ?? '') === 'none') {
            return null;
        }

        // 节点字段补全到 styles（引擎把 w/h/position 放在节点字段而非 style 中）
        // 浏览器将这些作为 CSS 属性，所以补全以消除 MISSING
        // 仅当引擎 style 中没导出时补全，避免覆盖引擎已有值
        // CSS 2.2 §10.3.1: 内联元素的 width/height 默认值为 'auto'
        $isInline = in_array($tag, self::INLINE_TAGS, true);
        if (!isset($element['styles']['width'])) {
            $element['styles']['width'] = $isInline ? 'auto' : (int)($node['visualW'] ?? $node['w'] ?? 0) . 'px';
        }
        if (!isset($element['styles']['height'])) {
            $element['styles']['height'] = $isInline ? 'auto' : (int)($node['visualH'] ?? $node['h'] ?? 0) . 'px';
        }
        // CSS 2.2 §9.3.2: static 定位元素的 top/left 默认值为 'auto'
        $nodePos = $node['style']['position'] ?? 'static';
        if (!isset($element['styles']['top'])) {
            $element['styles']['top'] = ($nodePos === 'static') ? 'auto' : (int)($node['y'] ?? 0) . 'px';
        }
        if (!isset($element['styles']['left'])) {
            $element['styles']['left'] = ($nodePos === 'static') ? 'auto' : (int)($node['x'] ?? 0) . 'px';
        }
        if (!isset($element['styles']['position'])) {
            $element['styles']['position'] = $node['style']['position'] ?? 'static';
        }

        // CSS display 补全：引擎可能不导出 display，基于 tag 推断
        if (!isset($element['styles']['display'])) {
            $element['styles']['display'] = $isInline ? 'inline' : 'block';
        }

        // CSS 2.2 §9.4: 当父容器为 flex/grid 时，所有子项生成 block-level 盒子
        $flexGridDisplays = ['flex', 'inline-flex', 'grid', 'inline-grid'];
        if ($parentDisplay !== null && in_array($parentDisplay, $flexGridDisplays, true)) {
            $element['styles']['display'] = 'block';
        }

        // 文本内容

        // CSS 默认值补全：引擎不导出继承属性的默认值，补齐以消除 MISSING
        // 注意：text-align 不在此补全——引擎可能使用非标准默认值（left vs start），
        // 补全会掩盖引擎 bug。让 MISSING 如实上报引擎未导出的属性。
        $cssDefaults = [
            'font-weight'  => '400',
            'font-style'   => 'normal',
            'font-size'    => '16px',
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
        // 优先使用 fullStyle 中的 fontSize（引擎统一用 px），其次用规范默认值 16px
        if ($engineKey === 'lineHeight' && is_numeric($value) && (float)$value < 10) {
            $fs = 16;
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
                'min-width', 'min-height', 'max-width', 'max-height',
                'outline-width', 'text-decoration-thickness',
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

    /**
     * 在原始引擎树中查找 BR 锚点的父容器尺寸和坐标。
     * 递归搜索：如果某节点包含 BR 锚点为子节点，返回其 w/h/x/y。
     * @return array{w: int, h: int, x: int, y: int}|null
     */
    private function findBrParent(array $node, string $brPxId): ?array
    {
        // 检查 $node 的直接子节点是否包含 BR 锚点
        foreach ($node['children'] ?? [] as $child) {
            if (!is_array($child)) continue;
            $childDs = $child['dataset'] ?? [];
            if (!is_array($childDs)) continue;
            $childPxId = $childDs['pxId'] ?? '';
            if ($childPxId === $brPxId) {
                // 找到了！返回当前 $node 的尺寸和绝对坐标
                $pw = (int)($node['visualW'] ?? $node['w'] ?? 0);
                $ph = (int)($node['visualH'] ?? $node['h'] ?? 0);
                $px = (int)($node['x'] ?? 0);
                $py = (int)($node['y'] ?? 0);
                return [$pw, $ph, $px, $py];
            }
        }
        // 递归搜索每个子节点
        foreach ($node['children'] ?? [] as $child) {
            if (!is_array($child)) continue;
            $result = $this->findBrParent($child, $brPxId);
            if ($result !== null) return $result;
        }
        return null;
    }
}
