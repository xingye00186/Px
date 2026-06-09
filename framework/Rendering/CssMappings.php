<?php

namespace Px\Rendering;

use native_types;

/**
 * CSS → GDI Mapping Table
 *
 * Defines which CSS properties are supported, how they map to GDI rendering
 * parameters, and what keys they produce in the layout output.
 *
 * Usage:
 *   $mapped = CssMappings::parseStyleBlock($styleCss);
 *   // → ['app-bg' => ['bg'=>1973790], 'display-text' => ['fg'=>16777215, 'fontSize'=>32, 'bold'=>1], ...]
 *
 * Extend $PROPERTY_MAP to add new CSS property support.
 */
class CssMappings
{
    /**
     * CSS property → [outputKey, parserFunction, default]
     *
     * Each entry maps a CSS property name to:
     *   - outputKey: the key in the generated layout array
     *   - parser: a callable that converts the CSS value string to the output type
     *   - default: fallback value if property is not specified
     *
     * Adding a new CSS property is as simple as adding one entry here.
     */
    const PROPERTY_MAP = [
        'background' => [
            'key'     => 'bg',
            'parser'  => 'Px\\Rendering\\CssMappings::parseHexColor',
            'default' => 0,
        ],
        'background-size' => [
            'key'     => 'backgroundSize',
            'parser'  => 'Px\\Rendering\\CssMappings::parseIdent',
            'default' => '',
        ],
        'background-position' => [
            'key'     => 'backgroundPosition',
            'parser'  => 'Px\\Rendering\\CssMappings::parseIdent',
            'default' => '',
        ],
        'color' => [
            'key'     => 'fg',
            'parser'  => 'Px\\Rendering\\CssMappings::parseHexColor',
            'default' => 0xFFFFFF,
        ],
        'font-size' => [
            'key'     => 'fontSize',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 16,
        ],
        'font-weight' => [
            'key'     => 'bold',
            'parser'  => 'Px\\Rendering\\CssMappings::parseFontWeight',
            'default' => 0,
        ],
        // ---- Layout properties (width/height for CSS class styles) ----
        'width' => [
            'key'     => 'width',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'height' => [
            'key'     => 'height',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'box-sizing' => [
            'key'     => 'boxSizing',
            'parser'  => 'Px\\Rendering\\CssMappings::parseIdent',
            'default' => 'content-box',
        ],
        // ---- Extensions for future GDI/Direct2D support ----
        'border-radius' => [
            'key'     => 'borderRadius',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'padding-top' => [
            'key'     => 'paddingTop',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'padding-right' => [
            'key'     => 'paddingRight',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'padding-bottom' => [
            'key'     => 'paddingBottom',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'padding-left' => [
            'key'     => 'paddingLeft',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'margin-top' => [
            'key'     => 'marginTop',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'margin-right' => [
            'key'     => 'marginRight',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'margin-bottom' => [
            'key'     => 'marginBottom',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'margin-left' => [
            'key'     => 'marginLeft',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'padding' => [
            'key'     => 'padding',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'margin' => [
            'key'     => 'margin',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'text-align' => [
            'key'     => 'textAlign',
            'parser'  => 'Px\\Rendering\\CssMappings::parseTextAlign',
            'default' => 'left',
        ],
        // ---- v8 UI extensions ----
        'border' => [
            'key'     => 'border',
            'parser'  => 'Px\\Rendering\\CssMappings::parseBorder',
            'default' => '',
        ],
        'border-bottom' => [
            'key'     => 'borderBottom',
            'parser'  => 'Px\\Rendering\\CssMappings::parseBorder',
            'default' => '',
        ],
        'border-top' => [
            'key'     => 'borderTop',
            'parser'  => 'Px\\Rendering\\CssMappings::parseBorder',
            'default' => '',
        ],
        'border-left' => [
            'key'     => 'borderLeft',
            'parser'  => 'Px\\Rendering\\CssMappings::parseBorder',
            'default' => '',
        ],
        'border-right' => [
            'key'     => 'borderRight',
            'parser'  => 'Px\\Rendering\\CssMappings::parseBorder',
            'default' => '',
        ],
        'border-width' => [
            'key'     => 'borderWidth',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'border-color' => [
            'key'     => 'borderColor',
            'parser'  => 'Px\\Rendering\\CssMappings::parseHexColor',
            'default' => 0,
        ],
        'box-shadow' => [
            'key'     => 'boxShadow',
            'parser'  => 'Px\\Rendering\\CssMappings::parseBoxShadow',
            'default' => '',
        ],
        'cursor' => [
            'key'     => 'cursor',
            'parser'  => 'Px\\Rendering\\CssMappings::parseIdent',
            'default' => 'default',
        ],
        'opacity' => [
            'key'     => 'opacity',
            'parser'  => 'Px\\Rendering\\CssMappings::parseOpacity',
            'default' => 1.0,
        ],
        // ---- Layout/positioning properties ----
        'left'             => ['key' => 'left',             'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'top'              => ['key' => 'top',              'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'right'            => ['key' => 'right',            'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'bottom'           => ['key' => 'bottom',           'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'display'          => ['key' => 'display',          'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'block'],
        'position'         => ['key' => 'position',         'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'static'],
        'z-index'          => ['key' => 'zIndex',           'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'overflow'         => ['key' => 'overflow',         'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'visible'],
        'overflow-x'       => ['key' => 'overflowX',        'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'visible'],
        'overflow-y'       => ['key' => 'overflowY',        'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'visible'],
        'text-overflow'    => ['key' => 'textOverflow',      'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'clip'],
        'white-space'      => ['key' => 'whiteSpace',       'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'normal'],
        'flex-direction'   => ['key' => 'flexDirection',    'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'row'],
        'flex-wrap'        => ['key' => 'flexWrap',         'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'nowrap'],
        'justify-content'  => ['key' => 'justifyContent',   'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'flex-start'],
        'align-items'      => ['key' => 'alignItems',       'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'stretch'],
        'align-content'    => ['key' => 'alignContent',     'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'stretch'],
        'gap'              => ['key' => 'gap',              'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'flex'             => ['key' => 'flex',              'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => ''],
        'flex-grow'        => ['key' => 'flexGrow',    'parser' => 'Px\Rendering\CssMappings::parsePixels', 'default' => 0],
        'flex-basis'       => ['key' => 'flexBasis',    'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'auto'],
        'flex-shrink'      => ['key' => 'flexShrink',   'parser' => 'Px\Rendering\CssMappings::parsePixels', 'default' => 1],
        'order'            => ['key' => 'order',        'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'align-self'       => ['key' => 'alignSelf',   'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'auto'],
        'justify-self'     => ['key' => 'justifySelf', 'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'auto'],
        'justify-items'    => ['key' => 'justifyItems', 'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'normal'],
        'min-width'        => ['key' => 'minWidth',  'parser' => 'Px\Rendering\CssMappings::parsePixels', 'default' => 0],
        'max-width'        => ['key' => 'maxWidth',  'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'min-height'       => ['key' => 'minHeight', 'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'max-height'       => ['key' => 'maxHeight', 'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'grid-template-columns' => ['key' => 'gridTemplateColumns', 'parser' => 'Px\\Rendering\\CssMappings::parseIdent', 'default' => ''],
        'grid-template-rows'    => ['key' => 'gridTemplateRows',    'parser' => 'Px\\Rendering\\CssMappings::parseIdent', 'default' => ''],
        'grid-column-gap'      => ['key' => 'gridColumnGap', 'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'grid-row-gap'         => ['key' => 'gridRowGap',    'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'grid-row'             => ['key' => 'gridRow',       'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => ''],
        'grid-column'          => ['key' => 'gridColumn',    'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => ''],
        'object-fit'           => ['key' => 'objectFit',     'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'fill'],
        'background-image'     => ['key' => 'backgroundImage', 'parser' => 'Px\Rendering\CssMappings::parseBackgroundImage', 'default' => ''],
        'transform'            => ['key' => 'transform',       'parser' => 'Px\\Rendering\\CssMappings::parseTransform', 'default' => ''],
        'pointer-events'       => ['key' => 'pointerEvents',   'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => ''],
    
        // ---- Text Decoration (CSS Text Decoration Module Level 3) ----
        'text-decoration-line'      => ['key' => 'textDecorationLine',     'parser' => 'Px\Rendering\CssMappings::parseIdent',     'default' => 'none'],
        'text-decoration-color'     => ['key' => 'textDecorationColor',    'parser' => 'Px\Rendering\CssMappings::parseHexColor',   'default' => 0xFFFFFF],
        'text-decoration-style'     => ['key' => 'textDecorationStyle',    'parser' => 'Px\Rendering\CssMappings::parseIdent',     'default' => 'solid'],
        'text-decoration-thickness' => ['key' => 'textDecorationThickness','parser' => 'Px\Rendering\CssMappings::parsePixels',    'default' => 0],
        'text-underline-offset'     => ['key' => 'textUnderlineOffset',    'parser' => 'Px\Rendering\CssMappings::parsePixels',    'default' => 0],
    ];
    
    /**
     * Inline style → 布局属性映射（仅 PROPERTY_MAP 未覆盖的属性）
     *
     * 布局/定位/弹性/网格属性在 PROPERTY_MAP 中已定义，此处只加补充项。
     * parseInlineStyle() 通过 array_merge(PROPERTY_MAP, INLINE_PROPERTY_MAP) 合并使用。
     */
    const INLINE_PROPERTY_MAP = [
        // ---- 滚动条样式 (PROPERTY_MAP 中未包含, 其余布局属性从 PROPERTY_MAP 合并) ----
        'scrollbar-width'        => ['key' => 'scrollbarWidth',      'parser' => 'Px\Rendering\CssMappings::parsePixels', 'default' => 12],
        'scrollbar-track-color'  => ['key' => 'scrollbarTrackColor', 'parser' => 'Px\Rendering\CssMappings::parseHexColor', 'default' => 0x4A4A4A],
        'scrollbar-thumb-color'  => ['key' => 'scrollbarThumbColor', 'parser' => 'Px\Rendering\CssMappings::parseHexColor', 'default' => 0x888888],
        'scrollbar-border-radius'=> ['key' => 'scrollbarBorderRadius','parser' => 'Px\Rendering\CssMappings::parsePixels', 'default' => 0],
    ];

    // ============================================================
    // Color helpers
    // ============================================================

    /**
     * Convert CSS hex color #RRGGBB to GDI BGR integer (COLORREF).
     * Supports shorthand #RGB (expanded to #RRGGBB).
     */
    public static function hexToBgr(string $hex): int
    {
        return CssValueParser::hexToBgr($hex);
    }

    /**
     * Derive border color from background (lighten each channel by a delta).
     */
    public static function borderColor(int $bg, int $delta = 20): int
    {
        return CssValueParser::borderColor($bg, $delta);
    }

    // ============================================================
    // Property parsers (each returns a typed value from CSS string)
    // ============================================================

    /**
     * Parse CSS color value → BGR integer
     *
     * Supports:
     *   - "#RRGGBB" / "#RGB" (hex colors)
     *   - rgb(r, g, b) / rgba(r, g, b, a)
     *   - linear-gradient(...) → extract first color stop
     */
    public static function parseHexColor(string $value): int
    {
        return CssValueParser::parseHexColor($value);
    }

    /**
     * Parse "16px" → 16 (int)
     */
    public static function parsePixels(string $value): int
    {
        return CssValueParser::parsePixels($value);
    }

    /**
     * Parse "1" / "1.5" / "0" → flex grow value as string (e.g., "1", "2")
     */
    public static function parseFlex(string $value): string
    {
        return CssValueParser::parseFlex($value);
    }

    /**
     * Parse flex shorthand value into structured array per CSS spec.
     *
     * CSS flex shorthand (https://www.w3.org/TR/css-flexbox-1/#flex-shorthand):
     *   auto    → flex: 1 1 auto
     *   initial → flex: 0 1 auto
     *   none    → flex: 0 0 auto
     *   <num>        → flex-grow: <num>, flex-shrink: 1, flex-basis: 0
     *   <num> <num>  → flex-grow + flex-shrink, flex-basis: 0
     *   <num> <num> <basis>  → all three
     *
     * Examples:
     *   "1"         → ['grow'=>1.0, 'shrink'=>1.0, 'basis'=>0]
     *   "auto"      → ['grow'=>1.0, 'shrink'=>1.0, 'basis'=>'auto']
     *   "none"      → ['grow'=>0.0, 'shrink'=>0.0, 'basis'=>'auto']
     *   "initial"   → ['grow'=>0.0, 'shrink'=>1.0, 'basis'=>'auto']
     *   "1 0 auto"  → ['grow'=>1.0, 'shrink'=>0.0, 'basis'=>'auto']
     *   "2 0 100px" → ['grow'=>2.0, 'shrink'=>0.0, 'basis'=>100]
     *   ""          → ['grow'=>0.0, 'shrink'=>1.0, 'basis'=>0]
     */
    public static function parseFlexValue(string $flex): array
    {
        return CssValueParser::parseFlexValue($flex);
    }

    /**
     * Parse "bold" / "700" → 1, "normal" / "400" → 0
     */
    public static function parseFontWeight(string $value): int
    {
        return CssValueParser::parseFontWeight($value);
    }

    /**
     * Parse "left" / "right" / "center" → align string
     */
    public static function parseTextAlign(string $value): string
    {
        return CssValueParser::parseTextAlign($value);
    }

    /**
     * Parse "1px solid #d9d9d9" → border string (v8)
     */
    public static function parseBorder(string $value): string
    {
        return CssValueParser::parseBorder($value);
    }

    /**
     * Parse "h-offset v-offset [blur] [spread] [color]" into structured string.
     * Returns "h|v|blur|spread|color" for downstream use.
     */
    public static function parseBoxShadow(string $value): string
    {
        return CssValueParser::parseBoxShadow($value);
    }

    /**
     * Parse "h|v|blur|spread|color" box-shadow string to offset array.
     * Extracted to eliminate 3x duplicate in VNodeRenderer.
     *
     * @return array{h:int, v:int, color:int}
     */
    public static function parseBoxShadowOffsets(string $boxShadow): array
    {
        $parts = explode('|', $boxShadow);
        return [
            'h'     => (int)($parts[0] ?? 0),
            'v'     => (int)($parts[1] ?? 0),
            'color' => self::hexToBgr($parts[4] ?? '#000000'),
        ];
    }

    /**
     * Parse "0.5" or "50%" → float 0.0-1.0 (v8)
     */
    public static function parseOpacity(string $value): float
    {
        return CssValueParser::parseOpacity($value);
    }

    /**
     * Parse identity: return the trimmed value as-is
     * Used for display, flex-direction, overflow, position 等关键字属性
     */
    public static function parseIdent(string $value): string
    {
        return CssValueParser::parseIdent($value);
    }

    /**
     * Parse `url("path/to/image.png")` → extract the image path
     * Matches CSS background-image property: background-image: url("...")
     * Supports both single/double quotes and unquoted URLs.
     */
    public static function parseBackgroundImage(string $value): string
    {
        return CssValueParser::parseBackgroundImage($value);
    }

    /**
     * AOT-compatible parser dispatcher.
     * Replaces call_user_func() which is not supported by AOT.
     */
    private static function dispatchParser(string $parser, string $value): mixed
    {
        // 从 "Px\\Rendering\\CssMappings::parseHexColor" 提取方法名 parseHexColor
        $method = substr($parser, (int)strrpos($parser, '::') + 2);
        return match($method) {
            'parseHexColor'        => CssValueParser::parseHexColor($value),
            'parsePixels'          => CssValueParser::parsePixels($value),
            'parseFlex'            => CssValueParser::parseFlex($value),
            'parseFontWeight'      => CssValueParser::parseFontWeight($value),
            'parseTextAlign'       => CssValueParser::parseTextAlign($value),
            'parseBorder'          => CssValueParser::parseBorder($value),
            'parseOpacity'         => CssValueParser::parseOpacity($value),
            'parseIdent'           => CssValueParser::parseIdent($value),
            'parseBackgroundImage' => CssValueParser::parseBackgroundImage($value),
            'parseTransform'       => CssValueParser::parseTransform($value),
            'parseBoxShadow'       => CssValueParser::parseBoxShadow($value),
            default                => $value,
        };
    }

    // ============================================================
    // Inline Style Parsing (HTML style="..." attribute)
    // ============================================================

    /**
     * Parse an HTML inline style string into a key-value array.
     *
     * Handles both layout properties (width, height, left, top, display, etc.)
     * AND visual properties (background, color, font-size, etc.).
     *
     * Example:
     *   "width:400px;height:500px;left:10px;top:50px;display:flex;gap:8px"
     *   → ['width' => 400, 'height' => 500, 'left' => 10, 'top' => 50, 'display' => 'flex', 'gap' => 8]
     *
     * @param string $styleStr Raw style attribute value
     * @return array  ['propName' => parsedValue, ...]
     */
    public static function parseInlineStyle(string $styleStr): array
    {
        $style = [];

        // Parse declarations: property: value; property: value; ...
        // Supports vendor prefixes (e.g., -webkit-appearance) via leading dash in prop name
        if (!preg_match_all('#([a-zA-Z-][a-zA-Z0-9_-]*)\s*:\s*([^;]+)\s*(?:!important)?\s*;?#', $styleStr, $m, PREG_SET_ORDER)) {
            return $style;
        }

        // First pass: collect raw declarations
        $raw = [];
        foreach ($m as $decl) {
            $raw[strtolower(trim($decl[1]))] = trim($decl[2]);
        }

        // Pre-scan for 'auto' margin values (before expandBoxShorthand converts them to '0px')
        // Store as bool flags: marginLeftAuto, marginRightAuto, marginTopAuto, marginBottomAuto
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
                // Single 'auto' value: all four margins are auto
                $marginAutoFlags['marginTopAuto'] = true;
                $marginAutoFlags['marginRightAuto'] = true;
                $marginAutoFlags['marginBottomAuto'] = true;
                $marginAutoFlags['marginLeftAuto'] = true;
            } else {
                for ($i = 0; $i < $count && $i < 4; $i++) {
                    if (strtolower(trim($parts[$i])) === 'auto') {
                        $dirMap = ['marginTopAuto', 'marginRightAuto', 'marginBottomAuto', 'marginLeftAuto'];
                        $marginAutoFlags[$dirMap[$i]] = true;
                        if ($count === 2 && $i === 0) $marginAutoFlags[$dirMap[2]] = true;
                        if ($count === 2 && $i === 1) $marginAutoFlags[$dirMap[3]] = true;
                        if ($count === 3 && $i === 1) $marginAutoFlags[$dirMap[3]] = true;
                    }
                }
            }
        }

        // Expand shorthand padding/margin to individual direction properties
        $raw = self::expandBoxShorthand($raw);

        // Expand background shorthand into individual sub-properties
        $raw = self::expandBackgroundShorthand($raw);

        // Expand text-decoration shorthand into individual sub-properties
        $raw = self::expandTextDecorationShorthand($raw);

        // Pre-detect percentage values for layout properties.
        // Store as "widthPercent" (float, e.g. 50.0 for "50%") alongside the
        // regular pixel key. LayoutResolver checks *Percent first.
        $pctMap = [
            'width' => 'widthPercent', 'height' => 'heightPercent',
            'min-width' => 'minWidthPercent', 'max-width' => 'maxWidthPercent',
            'min-height' => 'minHeightPercent', 'max-height' => 'maxHeightPercent',
            // CSS Box Model §7: margin/padding 百分比基于包含块宽度
            'margin-top' => 'marginTopPercent',
            'margin-right' => 'marginRightPercent',
            'margin-bottom' => 'marginBottomPercent',
            'margin-left' => 'marginLeftPercent',
            'padding-top' => 'paddingTopPercent',
            'padding-right' => 'paddingRightPercent',
            'padding-bottom' => 'paddingBottomPercent',
            'padding-left' => 'paddingLeftPercent',
        ];
        foreach ($pctMap as $cssProp => $styleKey) {
            if (isset($raw[$cssProp])) {
                $val = trim($raw[$cssProp]);
                // Standalone percentage: 50%
                if (str_ends_with($val, '%')) {
                    $style[$styleKey] = (float) substr($val, 0, -1);
                }
                // calc() expression with percentage + pixel offset: calc(100% - 40px)
                elseif (preg_match('/^calc\s*\(\s*(\d+(?:\.\d+)?)%\s*([+\-])\s*(\d+(?:\.\d+)?)px\s*\)$/i', $val, $m)) {
                    $style[$styleKey] = (float) $m[1];
                    $calcOffsetKey = str_replace('Percent', 'CalcOffset', $styleKey);
                    $style[$calcOffsetKey] = (int)($m[2] === '-' ? -$m[3] : $m[3]);
                }
            }
        }

        // Second pass: parse through lookup map
        $lookup = array_merge(self::PROPERTY_MAP, self::INLINE_PROPERTY_MAP);
        foreach ($raw as $propName => $value) {
            $map = $lookup[$propName] ?? null;
            if ($map !== null) {
                $style[$map['key']] = self::dispatchParser($map['parser'], $value);
            } else {
                // Convert kebab-case to camelCase for unknown properties
                $camelCase = self::kebabToCamelCase($propName);
                $style[$camelCase] = $value;
            }
        }

        // Merge auto margin flags (preserved from pre-scan)
        if (isset($marginAutoFlags['marginTopAuto']))  $style['marginTopAuto']  = true;
        if (isset($marginAutoFlags['marginRightAuto'])) $style['marginRightAuto'] = true;
        if (isset($marginAutoFlags['marginBottomAuto'])) $style['marginBottomAuto'] = true;
        if (isset($marginAutoFlags['marginLeftAuto']))  $style['marginLeftAuto']  = true;

        // Expand flex shorthand into flex-grow/flex-shrink/flex-basis
        if (isset($raw['flex']) && $raw['flex'] !== '') {
            $flexParsed = self::parseFlexValue($raw['flex']);
            if (!isset($style['flexGrow'])) {
                $style['flexGrow'] = $flexParsed['grow'];
            }
            if (!isset($style['flexShrink'])) {
                $style['flexShrink'] = $flexParsed['shrink'];
            }
            if (!isset($style['flexBasis'])) {
                $style['flexBasis'] = $flexParsed['basis'];
            }
        }

        // Parse border shorthand into individual properties (only if not already explicitly set)
        // Directional borders (更具体) 优先于通用 border 处理
        foreach (['borderBottom', 'borderTop', 'borderLeft', 'borderRight', 'border'] as $borderProp) {
            if (isset($style[$borderProp]) && $style[$borderProp] !== '') {
                $parts = explode('|', $style[$borderProp]);
                if (!isset($style['borderWidth'])) {
                    $style['borderWidth'] = (int)($parts[0] ?? 0);
                }
                if (!isset($style['borderColor'])) {
                    $style['borderColor'] = (int)($parts[1] ?? 0);
                }
            }
        }

        // Extract rgba alpha channel as opacity (only if opacity not explicitly set)
        if (!isset($style['opacity'])) {
            foreach ($raw as $rawValue) {
                if (preg_match('/rgba\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*([\d.]+)\s*\)/i', $rawValue, $m)) {
                    $alpha = (float)$m[1];
                    if ($alpha >= 0.0 && $alpha < 1.0) {
                        $style['opacity'] = $alpha;
                    }
                    break;
                }
            }
        }

        return $style;
    }

    /**
     * Expand CSS shorthand padding/margin into individual direction properties.
     *
     * Input "padding: 10px" → padding-top, padding-right, padding-bottom, padding-left = 10
     * Input "margin: 10px 20px" → margin-top=margin-bottom=10, margin-left=margin-right=20
     * Input "padding: 1px 2px 3px" → top=1, left/right=2, bottom=3
     * Input "margin: 1px 2px 3px 4px" → top=1, right=2, bottom=3, left=4
     */
    private static function expandBoxShorthand(array $raw): array
    {
        foreach (['padding', 'margin'] as $prop) {
            if (!isset($raw[$prop])) continue;

            $parts = preg_split('/\s+/', trim($raw[$prop]));
            $nums = [];
            foreach ($parts as $p) {
                $nums[] = (int) preg_replace('/[^-0-9]/', '', $p);
            }
            $count = count($nums);
            if ($count === 0) continue;

            $top    = $nums[0];
            $right  = $nums[1] ?? $top;
            $bottom = $nums[2] ?? $top;
            $left   = $nums[3] ?? $right;

            $raw[$prop . '-top']    = $top . 'px';
            $raw[$prop . '-right']  = $right . 'px';
            $raw[$prop . '-bottom'] = $bottom . 'px';
            $raw[$prop . '-left']   = $left . 'px';

            // Keep original shorthand for backward compat
            $raw[$prop] = $top . 'px';
        }
        return $raw;
    }

    /**
     * Parse CSS background shorthand into individual sub-properties.
     *
     * CSS background shorthand syntax:
     *   [bg-color] [bg-image] [bg-position]/[bg-size] [bg-repeat] [bg-attachment] [bg-origin] [bg-clip]
     *
     * This method extracts sub-properties by detecting distinctive patterns:
     *   1. Color first (hex, rgb/rgba, linear-gradient)
     *   2. Image (url(...))
     *   3. Position/Size (".../size" pattern)
     *   4. Repeat keywords (no-repeat, repeat-x, etc.)
     *   5. Attachment keywords (scroll, fixed, local)
     *   6. Remainder → position (if /size was extracted) or empty
     *
     * Only expands multi-value shorthands containing url() or /
     * (position/size delimiter). Single color values pass through unchanged.
     *
     * Examples:
     *   "#FB7299"                                    → unchanged (single color)
     *   "url('bg.png')"                              → image only
     *   "url('bg.png') center/cover no-repeat"       → image + position + size
     *   "#FB7299 url('bg.png') center/cover no-repeat" → full shorthand
     *   "rgba(251,114,153,0.4) url('bg.png')"         → rgba color + image
     *
     * @param array $raw Raw style declarations
     * @return array Updated raw declarations with expanded sub-properties
     */
    private static function expandBackgroundShorthand(array $raw): array
    {
        if (!isset($raw['background']) || $raw['background'] === '') {
            return $raw;
        }

        $value = trim($raw['background']);

        // Single color value (hex, rgb/rgba, gradient, transparent, none) → no expansion
        if (preg_match('/^#[\da-fA-F]{3,8}$/', $value) ||
            preg_match('/^rgba?\s*\([^)]*\)$/i', trim($value)) ||
            preg_match('/^linear-gradient\s*\([^)]*\)$/i', trim($value)) ||
            strtolower($value) === 'transparent' ||
            strtolower($value) === 'none') {
            return $raw;
        }

        // No url() and no position/size delimiter → not a multi-value shorthand
        if (!preg_match('/url\s*\(/i', $value) && !str_contains($value, '/')) {
            return $raw;
        }

        // Parse the multi-value shorthand
        $rest = $value;
        $bgColor = '';
        $bgImage = '';
        $bgPosition = '';
        $bgSize = '';

        // 1. Extract color (distinctive formats)
        if (preg_match('/#[\da-fA-F]{3,8}\b/', $rest, $m)) {
            $bgColor = $m[0];
            $rest = trim(str_replace($m[0], '', $rest));
        }
        if (preg_match('/rgba?\s*\([^)]*\)/i', $rest, $m)) {
            $bgColor = $m[0];
            $rest = trim(str_replace($m[0], '', $rest));
        }
        if (preg_match('/linear-gradient\s*\([^)]*\)/i', $rest, $m)) {
            $bgColor = $m[0];
            $rest = trim(str_replace($m[0], '', $rest));
        }

        // 2. Extract image: url(...)
        if (preg_match('/url\s*\(\s*["\']?([^"\'\)]+)["\']?\s*\)/i', $rest, $m)) {
            $bgImage = trim($m[1]);
            $rest = trim(str_replace($m[0], '', $rest));
        }

        // 3. Extract position/size pair: ".../size"
        if (preg_match('#/\s*(\S+)#', $rest, $m)) {
            $bgSize = trim($m[1]);
            $rest = trim(str_replace($m[0], '', $rest));
        }

        // 4. Extract repeat keywords
        foreach (['repeat-x', 'repeat-y', 'no-repeat', 'repeat', 'space', 'round'] as $kw) {
            if (stripos($rest, $kw) !== false) {
                $rest = trim(str_ireplace($kw, '', $rest));
                break;
            }
        }

        // 5. Extract attachment keywords
        foreach (['scroll', 'fixed', 'local'] as $kw) {
            if (stripos($rest, $kw) !== false) {
                $rest = trim(str_ireplace($kw, '', $rest));
                break;
            }
        }

        // 6. Remaining rest is position (if /size was extracted)
        $rest = trim(preg_replace('/\s+/', ' ', $rest));
        if ($bgSize !== '' && $rest !== '') {
            $bgPosition = $rest;
            $rest = '';
        }

        // Set sub-properties only if not already explicitly specified
        if ($bgImage !== '' && !isset($raw['background-image'])) {
            $raw['background-image'] = 'url("' . $bgImage . '")';
        }
        if ($bgPosition !== '' && !isset($raw['background-position'])) {
            $raw['background-position'] = $bgPosition;
        }
        if ($bgSize !== '' && !isset($raw['background-size'])) {
            $raw['background-size'] = $bgSize;
        }

        // Update background to color-only value for PROPERTY_MAP parsing
        if ($bgColor !== '') {
            $raw['background'] = $bgColor;
        } else {
            // No color specified → transparent (parseHexColor returns 0)
            $raw['background'] = 'transparent';
        }

        return $raw;
    }

    /**
     * Expand text-decoration shorthand into individual sub-properties.
     *
     * CSS text-decoration shorthand syntax (CSS Text Decoration Module Level 3):
     *   text-decoration: <line> || <style> || <color> || <thickness>
     *
     * Examples:
     *   "underline"                        → line=underline
     *   "underline wavy red"               → line=underline, style=wavy, color=red
     *   "underline overline"               → line=underline overline
     *   "underline wavy #FF0000 2px"        → line=underline, style=wavy, color=#FF0000, thickness=2
     *   "none"                             → line=none (no decoration)
     *
     * All four components can appear in any order. Multiple line keywords are
     * space-separated (e.g., "underline overline line-through").
     *
     * @param array $raw Raw style declarations
     * @return array Updated raw declarations with expanded sub-properties
     */
    private static function expandTextDecorationShorthand(array $raw): array
    {
        if (!isset($raw['text-decoration']) || $raw['text-decoration'] === '') {
            return $raw;
        }
        $expanded = self::expandTextDecorationValue(trim($raw['text-decoration']));
        foreach ($expanded as $key => $val) {
            // Convert camelCase back to kebab-case for the raw CSS property array
            $cssKey = strtolower(preg_replace('/([A-Z])/', '-$1', $key));
            if (!isset($raw[$cssKey])) {
                $raw[$cssKey] = $val;
            }
        }
        return $raw;
    }

    /**
     * Parse a text-decoration shorthand value into individual CSS declarations.
     *
     * @param string $value The raw shorthand value (e.g., "underline wavy red")
     * @return array CSS property name => value pairs
     */
    private static function expandTextDecorationValue(string $value): array
    {
        $result = [];
        $parts = preg_split('/\s+/', $value);

        $lineParts = [];
        $hasLine = false;

        foreach ($parts as $part) {
            if ($part === '') continue;
            $lower = strtolower($part);

            // Line keywords: underline, overline, line-through, none, blink
            if (in_array($lower, ['underline', 'overline', 'line-through', 'none', 'blink'], true)) {
                $lineParts[] = $lower;
                $hasLine = true;
                continue;
            }

            // Style keywords: solid, double, dotted, dashed, wavy
            if (in_array($lower, ['solid', 'double', 'dotted', 'dashed', 'wavy'], true)) {
                $result['textDecorationStyle'] = $lower;
                continue;
            }

            // Color: hex or rgb/rgba
            if (str_starts_with($part, '#') || preg_match('/^rgba?\s*\(/i', $part)) {
                $result['textDecorationColor'] = $part;
                continue;
            }

            // Thickness: numeric with optional px unit
            if (preg_match('/^\d+(\.\d+)?(px)?$/', $part)) {
                $result['textDecorationThickness'] = $part;
                continue;
            }
        }

        if ($hasLine) {
            $result['textDecorationLine'] = implode(' ', $lineParts);
        }

        return $result;
    }

    /**
     * Convert kebab-case to camelCase
     * e.g., "align-items" -> "alignItems"
     */
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
     * Parse grid-template-columns / grid-template-rows value.
     *
     * Examples:
     *   "repeat(4, 80px)" → ['repeat' => true, 'count' => 4, 'size' => 80]
     *   "1fr 1fr 1fr 1fr" → ['type' => 'explicit', 'sizes' => ['1fr','1fr','1fr','1fr']]
     *   "auto"            → ['type' => 'auto']
     *
     * @param string $val Raw CSS value
     * @return array
     */
    public static function parseGridTemplateValue(string $val): array
    {
        return CssValueParser::parseGridTemplateValue($val);
    }

    // ============================================================
    // Block-level parsing
    // ============================================================

    /**
     * Parse a <style> block and return class→properties map.
     * 
     * @param string $styleCss  Raw content of <style>...</style>
     * @param array  $warnings  Output: collects parse warnings
     * @return array  [className => [outputKey => value], ...]
     */
    public static function parseStyleBlock(string $styleCss, array &$warnings = []): array
    {
        $classStyles = [];

        if (!preg_match_all('#\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}#s', $styleCss, $rules, PREG_SET_ORDER)) {
            return $classStyles;
        }

        foreach ($rules as $rule) {
            $className = $rule[1];
            $body      = $rule[2];
            $props     = [];

            foreach (self::PROPERTY_MAP as $cssProp => $map) {
                $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                if (preg_match($pattern, $body, $m)) {
                    $value = trim($m[1]);
                    $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                }
            }

            // If neither background nor color was specified, log a warning
            if (!isset($props['bg']) && !isset($props['fg'])) {
                $warnings[] = "CSS class '$className': no background or color property (will render as transparent)";
            }

            // Parse border shorthand into individual properties (only if not already explicitly set)
            // Directional borders (更具体) 优先于通用 border 处理
            foreach (['borderBottom', 'borderTop', 'borderLeft', 'borderRight', 'border'] as $borderProp) {
                if (isset($props[$borderProp]) && $props[$borderProp] !== '') {
                    $parts = explode('|', $props[$borderProp]);
                    if (!isset($props['borderWidth'])) {
                        $props['borderWidth'] = (int)($parts[0] ?? 0);
                    }
                    if (!isset($props['borderColor'])) {
                        $props['borderColor'] = (int)($parts[1] ?? 0);
                    }
                }
            }

            // Expand text-decoration shorthand in class body
            if (preg_match('~text-decoration\s*:\s*([^;]+)~', $body, $tdMatch)) {
                $expanded = self::expandTextDecorationValue(trim($tdMatch[1]));
                foreach ($expanded as $tdKey => $tdVal) {
                    if (!isset($props[$tdKey])) {
                        $props[$tdKey] = $tdVal;
                    }
                }
            }

            $classStyles[$className] = $props;
        }

        return $classStyles;
    }

    /**
     * Apply class styles to a layout element, merging with inline overrides.
     * 
     * @param array $classStyles  Result of parseStyleBlock()
     * @param string $className   CSS class name
     * @param array $overrides    Inline property overrides (e.g., from template attrs)
     * @return array  Merged style properties
     */
    public static function resolveStyle(array $classStyles, string $className, array $overrides = []): array
    {
        $style = $classStyles[$className] ?? [];
        return array_merge($style, $overrides);
    }

    /**
     * RGB → BGR 格式转换。
     *
     * GDI COLORREF 使用 BGR 字节序，而主题系统（ColorScheme）存储 RGB 格式。
     * 例如：0xRRGGBB → 0xBBGGRR。
     *
     * @param int $rgb RGB 格式颜色值
     * @return int BGR 格式颜色值
     */
    public static function rgbToBgr(int $rgb): int
    {
        return CssValueParser::rgbToBgr($rgb);
    }

    /**
     * 将 CSS 样式字符串解析为键值对数组。
     * 输入: "background:#2C2C2E;color:#FFF;left:10px"
     * 输出: ['background' => '#2C2C2E', 'color' => '#FFF', 'left' => '10px']
     *
     * AOT 安全：仅使用字符串操作和数组遍历。
     */
    public static function parseStyleStringToArray(string $style): array
    {
        $result = [];
        $pairs = explode(';', $style);
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;
            $colonPos = strpos($pair, ':');
            if ($colonPos === false) continue;
            $prop = trim(substr($pair, 0, $colonPos));
            $value = trim(substr($pair, $colonPos + 1));
            if ($prop !== '') {
                $result[$prop] = $value;
            }
        }
        return $result;
    }

    /**
     * 将键值对数组序列化为 CSS 样式字符串。
     * 输入: ['background' => '#2C2C2E', 'left' => '11px']
     * 输出: "background:#2C2C2E;left:11px;"
     * 输入: []
     * 输出: ""
     *
     * AOT 安全：仅使用字符串操作和数组遍历。
     */
    public static function buildStyleStringFromArray(array $style): string
    {
        if (empty($style)) {
            return '';
        }
        $parts = [];
        foreach ($style as $prop => $value) {
            $parts[] = "{$prop}:{$value}";
        }
        return implode(';', $parts) . ';';
    }

    // ============================================================
    // Animation & Transition parsing
    // ============================================================

    /**
     * 解析 CSS transition 属性。
     *
     * 格式: property duration timing-function delay
     * 示例: "all 300ms ease-in-out"
     *       "background-color 200ms linear, transform 300ms ease"
     *
     * @param string $value CSS transition 值
     * @return array 每个属性的解析结果
     *   [
     *     ['property' => 'all', 'duration' => 300, 'timing' => 'ease', 'delay' => 0],
     *     ...
     *   ]
     */
    public static function parseTransition(string $value): array
    {
        return CssValueParser::parseTransition($value);
    }

    /**
     * 解析 CSS animation 属性。
     *
     * 格式: name duration timing-function delay count direction fill-mode play-state
     * 示例: "fadeIn 300ms ease-in-out"
     *
     * @param string $value CSS animation 值
     * @return array 解析结果
     */
    public static function parseAnimation(string $value): array
    {
        return CssValueParser::parseAnimation($value);
    }

    /**
     * 解析 CSS transform 属性。
     *
     * 格式: translateX(X) translateY(Y)
     * 示例: "translateX(10px) translateY(-20px)"
     *       "translate(10px, -20px)"
     *
     * @param string $value CSS transform 值
     * @return array ['translateX' => int, 'translateY' => int]
     */
    public static function parseTransform(string $value): array
    {
        return CssValueParser::parseTransform($value);
    }

    /**
     * 序列化 transform 数组为 CSS 字符串。
     *
     * @param array $transform ['translateX' => int, 'translateY' => int]
     * @return string CSS transform 值
     */
    public static function buildTransformString(array $transform): string
    {
        return CssValueParser::buildTransformString($transform);
    }
}