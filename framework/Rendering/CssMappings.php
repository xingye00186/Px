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
            'parser'  => 'Px\Rendering\CssMappings::parseTextAlign',
            'default' => 'left',
        ],
        'line-height' => [
            'key'     => 'lineHeight',
            'parser'  => 'Px\Rendering\CssMappings::parseLineHeight',
            'default' => '',
        ],
        'font-family' => [
            'key'     => 'fontFamily',
            'parser'  => 'Px\Rendering\CssMappings::parseIdent',
            'default' => '',
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
        'border-top-width' => [
            'key'     => 'borderTopWidth',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'border-right-width' => [
            'key'     => 'borderRightWidth',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'border-bottom-width' => [
            'key'     => 'borderBottomWidth',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'border-left-width' => [
            'key'     => 'borderLeftWidth',
            'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
            'default' => 0,
        ],
        'border-color' => [
            'key'     => 'borderColor',
            'parser'  => 'Px\\Rendering\\CssMappings::parseHexColor',
            'default' => 0,
        ],
        // ---- border-style (CSS 2.2 §8.5.3) ----
        'border-style' => [
            'key'     => 'borderStyle',
            'parser'  => 'Px\\Rendering\\CssMappings::parseIdent',
            'default' => 'solid',
        ],
        'border-top-style' => [
            'key'     => 'borderTopStyle',
            'parser'  => 'Px\\Rendering\\CssMappings::parseIdent',
            'default' => 'solid',
        ],
        'border-right-style' => [
            'key'     => 'borderRightStyle',
            'parser'  => 'Px\\Rendering\\CssMappings::parseIdent',
            'default' => 'solid',
        ],
        'border-bottom-style' => [
            'key'     => 'borderBottomStyle',
            'parser'  => 'Px\\Rendering\\CssMappings::parseIdent',
            'default' => 'solid',
        ],
        'border-left-style' => [
            'key'     => 'borderLeftStyle',
            'parser'  => 'Px\\Rendering\\CssMappings::parseIdent',
            'default' => 'solid',
        ],
        // ---- per-side border colors (CSS 2.2 §8.5.2) ----
        'border-top-color' => [
            'key'     => 'borderTopColor',
            'parser'  => 'Px\\Rendering\\CssMappings::parseHexColor',
            'default' => 0,
        ],
        'border-right-color' => [
            'key'     => 'borderRightColor',
            'parser'  => 'Px\\Rendering\\CssMappings::parseHexColor',
            'default' => 0,
        ],
        'border-bottom-color' => [
            'key'     => 'borderBottomColor',
            'parser'  => 'Px\\Rendering\\CssMappings::parseHexColor',
            'default' => 0,
        ],
        'border-left-color' => [
            'key'     => 'borderLeftColor',
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
            'parser'  => 'Px\Rendering\CssMappings::parseOpacity',
            'default' => 1.0,
        ],
        'scroll-behavior' => [
            'key'     => 'scrollBehavior',
            'parser'  => 'Px\Rendering\CssMappings::parseIdent',
            'default' => 'auto',
        ],
        // ---- Layout/positioning properties ----
        'left'             => ['key' => 'left',             'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 'auto'],
        'top'              => ['key' => 'top',              'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 'auto'],
        'right'            => ['key' => 'right',            'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 'auto'],
        'bottom'           => ['key' => 'bottom',           'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 'auto'],
        'display'          => ['key' => 'display',          'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'block'],
        'position'         => ['key' => 'position',         'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'static'],
        'z-index'          => ['key' => 'zIndex',           'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'overflow'         => ['key' => 'overflow',         'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'visible'],
        'overflow-x'       => ['key' => 'overflowX',        'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'visible'],
        'overflow-y'       => ['key' => 'overflowY',        'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'visible'],
        'text-overflow'    => ['key' => 'textOverflow',      'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'clip'],
        'white-space'      => ['key' => 'whiteSpace',       'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'normal'],
        'word-break'       => ['key' => 'wordBreak',        'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'normal'],
        'font-style'       => ['key' => 'fontStyle',        'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'normal'],
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
        'grid-area'            => ['key' => 'gridArea',       'parser' => 'Px\\Rendering\\CssMappings::parseIdent',   'default' => ''],
        'grid-auto-rows'       => ['key' => 'gridAutoRows',    'parser' => 'Px\\Rendering\\CssMappings::parsePixels',  'default' => 0],
        'grid-template-areas'  => ['key' => 'gridTemplateAreas','parser' => 'Px\\Rendering\\CssMappings::parseIdent',   'default' => ''],
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
    
        // CSS Inline Layout: vertical-align (CSS 2.2 §10.8.1)
        'vertical-align' => [
            'key'     => 'verticalAlign',
            'parser'  => 'Px\Rendering\CssMappings::parseIdent',
            'default' => 'baseline',
        ],
    
        // CSS Basic User Interface Module Level 3: outline (不占布局空间)
        'outline-width' => [
            'key'     => 'outlineWidth',
            'parser'  => 'Px\Rendering\CssMappings::parsePixels',
            'default' => 0,
        ],
        'outline-style' => [
            'key'     => 'outlineStyle',
            'parser'  => 'Px\Rendering\CssMappings::parseIdent',
            'default' => 'none',
        ],
        'outline-color' => [
            'key'     => 'outlineColor',
            'parser'  => 'Px\Rendering\CssMappings::parseHexColor',
            'default' => 0,
        ],

        // CSS Multi-column Layout Module Level 1
        'column-count' => [
            'key'     => 'columnCount',
            'parser'  => 'Px\Rendering\CssMappings::parsePixels',
            'default' => 0,
        ],
        'column-width' => [
            'key'     => 'columnWidth',
            'parser'  => 'Px\Rendering\CssMappings::parsePixels',
            'default' => 0,
        ],
        'column-gap' => [
            'key'     => 'columnGap',
            'parser'  => 'Px\Rendering\CssMappings::parsePixels',
            'default' => 16,
        ],
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
     * Parse line-height value.
     * Supports: unitless number (1.7), px (28px), em (1.6em), % (150%).
     * For unitless numbers, returns as 'N.N' string for runtime line spacing calculation.
     * For px values, returns pixel count.
     * For em/%, returns multiplier string (e.g., '1.6em' → '1.6').
     */
    public static function parseLineHeight(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if ($value === 'normal') return '';
        // unitless number (most common in base.html: line-height: 1.7)
        if (preg_match('/^(\d+(\.\d+)?)$/', $value, $m)) {
            return $m[1];
        }
        // px value
        if (str_ends_with($value, 'px')) {
            return (string)(int)$value;
        }
        // em value: return multiplier (CSS: line-height:1.6em = 1.6 × font-size)
        if (str_ends_with($value, 'em')) {
            $num = (float)$value;
            return (string)$num;
        }
        // percentage: return multiplier (CSS: line-height:150% = 1.5 × font-size)
        if (str_ends_with($value, '%')) {
            $num = (float)$value / 100.0;
            return (string)$num;
        }
        // rem, viewport units: store as "value|unit" for runtime resolution
        if (preg_match('/^(\d+(\.\d+)?)\s*(rem|vw|vh|vmin|vmax|ch|ex)$/i', $value, $m)) {
            return $m[1] . '|' . strtolower($m[3]);
        }
        // Physical units: convert to px immediately
        // CSS Values §5: 1pt=1/72in, 1pc=12pt, 1cm=96/2.54px, 1mm=96/25.4px, 1in=96px
        $unitMap = [
            'pt' => 96.0 / 72.0,   // 1.333px
            'pc' => 96.0 / 6.0,     // 16px
            'in' => 96.0,           // 96px
            'cm' => 96.0 / 2.54,    // ~37.8px
            'mm' => 96.0 / 25.4,    // ~3.78px
        ];
        foreach ($unitMap as $unit => $pxPerUnit) {
            if (preg_match('/^(\d+(\.\d+)?)\s*' . $unit . '$/i', $value, $m)) {
                return (string)(int)round((float)$m[1] * $pxPerUnit);
            }
        }
        return $value;
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
            'blur'  => (int)($parts[2] ?? 0),
            'alpha' => (float)($parts[5] ?? 0.5),
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
    public static function parseInlineStyle(string $styleStr, array $variables = []): array
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

        // Extract inline custom properties (--*) for var() resolution
        $inlineVars = [];
        foreach ($raw as $propName => $value) {
            if (str_starts_with($propName, '--')) {
                $inlineVars[$propName] = $value;
            }
        }
        // Inline variables override passed variables (same specificity in CSS)
        $allVariables = array_merge($variables, $inlineVars);

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

        // Detect linear-gradient in background — the parser extracts the first
        // color as bg, but the element doesn't have a solid background-color.
        // Set a flag so the normalizer can skip exporting background-color
        // for gradient-only elements (browser shows rgba(0,0,0,0) for these).
        if (isset($raw['background']) && stripos($raw['background'], 'linear-gradient') !== false) {
            $style['bgFromGradient'] = true;
        }

        // Expand text-decoration shorthand into individual sub-properties
        $raw = self::expandTextDecorationShorthand($raw);

        // Expand outline shorthand into individual sub-properties
        if (isset($raw['outline']) && $raw['outline'] !== '') {
            $parts = preg_split('/\s+/', trim($raw['outline']));
            foreach ($parts as $part) {
                if (preg_match('/^\d+/', $part)) {
                    if (!isset($raw['outline-width'])) $raw['outline-width'] = $part;
                } elseif (preg_match('/^#/', $part)) {
                    if (!isset($raw['outline-color'])) $raw['outline-color'] = $part;
                } else {
                    $lower = strtolower($part);
                    if (in_array($lower, ['solid', 'dotted', 'dashed', 'double', 'none'])) {
                        if (!isset($raw['outline-style'])) $raw['outline-style'] = $lower;
                    }
                }
            }
        }

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
            // CSS Positioned Layout §3.1: left/top/right/bottom 百分比基于包含块
            'left'   => 'leftPercent',
            'top'    => 'topPercent',
            'right'  => 'rightPercent',
            'bottom' => 'bottomPercent',
            // CSS Backgrounds & Borders §5.1: border-radius 百分比基于元素的宽度和高度
            'border-radius' => 'borderRadiusPercent',
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

        // Pre-detect relative unit values (em/rem/vw/vh/vmin/vmax) for layout properties.
        // CSS Values and Units Module Level 3 §5:
        //   em  → relative to parent element's font-size
        //   rem → relative to root element's font-size
        //   vw  → 1% of viewport width
        //   vh  → 1% of viewport height
        // Stored as "{value}|{unit}" string (e.g., "2|em") for layout-time resolution.
        $relativeUnitMap = [
            'font-size'          => 'fontSizeUnit',
            'width'              => 'widthUnit',
            'height'             => 'heightUnit',
            'min-width'          => 'minWidthUnit',
            'max-width'          => 'maxWidthUnit',
            'min-height'         => 'minHeightUnit',
            'max-height'         => 'maxHeightUnit',
            'margin-top'         => 'marginTopUnit',
            'margin-right'       => 'marginRightUnit',
            'margin-bottom'      => 'marginBottomUnit',
            'margin-left'        => 'marginLeftUnit',
            'padding-top'        => 'paddingTopUnit',
            'padding-right'      => 'paddingRightUnit',
            'padding-bottom'     => 'paddingBottomUnit',
            'padding-left'       => 'paddingLeftUnit',
            'gap'                => 'gapUnit',
            'top'                => 'topUnit',
            'left'               => 'leftUnit',
            'right'              => 'rightUnit',
            'bottom'             => 'bottomUnit',
        ];
        foreach ($relativeUnitMap as $cssProp => $styleKey) {
            if (isset($raw[$cssProp])) {
                $parsed = CssValueParser::parseRelativeValue($raw[$cssProp]);
                if ($parsed['unit'] !== 'px') {
                    $style[$styleKey] = $parsed['value'] . '|' . $parsed['unit'];
                }
            }
        }

        // Second pass: parse through lookup map
        $lookup = array_merge(self::PROPERTY_MAP, self::INLINE_PROPERTY_MAP);
        foreach ($raw as $propName => $value) {
            // Skip custom properties (--*) — they are definitions, not rendering properties
            if (str_starts_with($propName, '--')) {
                continue;
            }
            $map = $lookup[$propName] ?? null;
            if ($map !== null) {
                // Resolve CSS variables in property value before parsing
                if (count($allVariables) > 0) {
                    $value = CssValueParser::resolveCSSVariables($value, $allVariables);
                }
                $style[$map['key']] = self::dispatchParser($map['parser'], $value);
            } else {
                // Convert kebab-case to camelCase for unknown properties
                $camelCase = self::kebabToCamelCase($propName);
                $style[$camelCase] = $value;
            }
        }

        // Preserve original CSS font-weight numeric value for test comparison.
        // engine 内部用 bold(0/1) 做渲染，但 CSS 对比需要原始数值（如 600、700）。
        if (isset($raw['font-weight'])) {
            $fw = trim($raw['font-weight']);
            if (is_numeric($fw)) {
                $style['fontWeight'] = (int)$fw;
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

        // ── Border property processing (CSS 2.2 §8.5-8.6) ──
        // CSS standard processing order: directionless → directional → explicit properties
        // Directional shorthands (border-bottom etc.) set ONLY per-side, NOT borderWidth

        // 1. Directionless border shorthand: sets borderWidth + all 4 per-side widths
        //    Also sets borderColor, borderStyle, and per-side colors/styles (CSS 2.2 §8.5)
        //    Process first so directional shorthands can override individual sides later
        if (isset($style['border']) && $style['border'] !== '') {
            $parts = explode('|', $style['border']);
            $bw = (int)($parts[0] ?? 0);
            $bc = (int)($parts[1] ?? 0);
            $bs = $parts[2] ?? 'solid';
            if (!isset($style['borderWidth'])) {
                $style['borderWidth'] = $bw;
            }
            if (!isset($style['borderColor'])) {
                $style['borderColor'] = $bc;
            }
            if (!isset($style['borderStyle'])) {
                $style['borderStyle'] = $bs;
            }
            if (!isset($style['borderTopWidth']))    $style['borderTopWidth'] = $bw;
            if (!isset($style['borderRightWidth']))  $style['borderRightWidth'] = $bw;
            if (!isset($style['borderBottomWidth'])) $style['borderBottomWidth'] = $bw;
            if (!isset($style['borderLeftWidth']))   $style['borderLeftWidth'] = $bw;
            if (!isset($style['borderTopColor']))    $style['borderTopColor'] = $bc;
            if (!isset($style['borderRightColor']))  $style['borderRightColor'] = $bc;
            if (!isset($style['borderBottomColor'])) $style['borderBottomColor'] = $bc;
            if (!isset($style['borderLeftColor']))   $style['borderLeftColor'] = $bc;
            if (!isset($style['borderTopStyle']))    $style['borderTopStyle'] = $bs;
            if (!isset($style['borderRightStyle']))  $style['borderRightStyle'] = $bs;
            if (!isset($style['borderBottomStyle'])) $style['borderBottomStyle'] = $bs;
            if (!isset($style['borderLeftStyle']))   $style['borderLeftStyle'] = $bs;
        }

        // 2. Directional border shorthands: each sets ONLY its own per-side (CSS 2.2 §8.6)
        //    Do NOT set borderWidth — directional shorthands don't affect opposite sides
        $directionalMap = [
            'borderBottom' => ['w' => 'borderBottomWidth', 'c' => 'borderBottomColor', 's' => 'borderBottomStyle'],
            'borderTop'    => ['w' => 'borderTopWidth',    'c' => 'borderTopColor',    's' => 'borderTopStyle'],
            'borderLeft'   => ['w' => 'borderLeftWidth',   'c' => 'borderLeftColor',   's' => 'borderLeftStyle'],
            'borderRight'  => ['w' => 'borderRightWidth',  'c' => 'borderRightColor',  's' => 'borderRightStyle'],
        ];
        foreach ($directionalMap as $dirProp => $keys) {
            if (isset($style[$dirProp]) && $style[$dirProp] !== '') {
                $parts = explode('|', $style[$dirProp]);
                $bw = (int)($parts[0] ?? 0);
                $bc = (int)($parts[1] ?? 0);
                $bs = $parts[2] ?? 'solid';
                // Set borderColor only if no per-side color was already set by explicit property
                if (!isset($style['borderColor'])) {
                    $style['borderColor'] = $bc;
                }
                // Directional shorthand ALWAYS overrides (CSS 2.2 §8.6):
                // border-left overrides any value set by directionless border shorthand
                $style[$keys['w']] = $bw;
                $style[$keys['c']] = $bc;
                $style[$keys['s']] = $bs;
            }
        }

        // 3. Explicit border-width CSS property: propagate to all 4 per-side widths
        //    Only propagates if NO per-side was set by any preceding shorthand
        if (isset($style['borderWidth']) && $style['borderWidth'] > 0) {
            $bw = (int)$style['borderWidth'];
            $anySideSet = isset($style['borderTopWidth']) || isset($style['borderRightWidth'])
                || isset($style['borderBottomWidth']) || isset($style['borderLeftWidth']);
            if (!$anySideSet) {
                $style['borderTopWidth'] = $bw;
                $style['borderRightWidth'] = $bw;
                $style['borderBottomWidth'] = $bw;
                $style['borderLeftWidth'] = $bw;
            }
        }

        // 4. Explicit border-style CSS property: propagate to all 4 per-side styles
        if (isset($style['borderStyle']) && $style['borderStyle'] !== '') {
            $bs = $style['borderStyle'];
            $anySideSet = isset($style['borderTopStyle']) || isset($style['borderRightStyle'])
                || isset($style['borderBottomStyle']) || isset($style['borderLeftStyle']);
            if (!$anySideSet) {
                $style['borderTopStyle']    = $bs;
                $style['borderRightStyle']  = $bs;
                $style['borderBottomStyle'] = $bs;
                $style['borderLeftStyle']   = $bs;
            }
        }

        // 5. Explicit border-color CSS property: propagate to all 4 per-side colors
        if (isset($style['borderColor']) && $style['borderColor'] > 0) {
            $bc = (int)$style['borderColor'];
            $anySideSet = isset($style['borderTopColor']) || isset($style['borderRightColor'])
                || isset($style['borderBottomColor']) || isset($style['borderLeftColor']);
            if (!$anySideSet) {
                $style['borderTopColor']    = $bc;
                $style['borderRightColor']  = $bc;
                $style['borderBottomColor'] = $bc;
                $style['borderLeftColor']   = $bc;
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

        // ---- border-width: 1-4 value expansion (CSS 2.2 §8.5.1) ----
        // Same pattern as padding/margin: strips non-numeric, appends px
        if (isset($raw['border-width'])) {
            $parts = preg_split('/\s+/', trim($raw['border-width']));
            $nums = [];
            foreach ($parts as $p) {
                $nums[] = (int) preg_replace('/[^-0-9]/', '', $p);
            }
            $count = count($nums);
            if ($count >= 2) {
                $top    = $nums[0];
                $right  = $nums[1] ?? $top;
                $bottom = $nums[2] ?? $top;
                $left   = $nums[3] ?? $right;
                $raw['border-top-width']    = $top . 'px';
                $raw['border-right-width']  = $right . 'px';
                $raw['border-bottom-width'] = $bottom . 'px';
                $raw['border-left-width']   = $left . 'px';
                $raw['border-width'] = $top . 'px';
            }
        }

        // ---- border-color: 1-4 value expansion (CSS 2.2 §8.5.2) ----
        // Colors are strings (not pixels), only expand when 2-4 values
        if (isset($raw['border-color'])) {
            $parts = preg_split('/\s+/', trim($raw['border-color']));
            $count = count($parts);
            if ($count >= 2) {
                $top    = $parts[0];
                $right  = $parts[1] ?? $top;
                $bottom = $parts[2] ?? $top;
                $left   = $parts[3] ?? $right;
                $raw['border-top-color']    = $top;
                $raw['border-right-color']  = $right;
                $raw['border-bottom-color'] = $bottom;
                $raw['border-left-color']   = $left;
                $raw['border-color'] = $top;
            }
        }

        // ---- border-style: 1-4 value expansion (CSS 2.2 §8.5.3) ----
        if (isset($raw['border-style'])) {
            $parts = preg_split('/\s+/', trim($raw['border-style']));
            $count = count($parts);
            if ($count >= 2) {
                $top    = $parts[0];
                $right  = $parts[1] ?? $top;
                $bottom = $parts[2] ?? $top;
                $left   = $parts[3] ?? $right;
                $raw['border-top-style']    = $top;
                $raw['border-right-style']  = $right;
                $raw['border-bottom-style'] = $bottom;
                $raw['border-left-style']   = $left;
                $raw['border-style'] = $top;
            }
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
     * Parse a <style> block and return class→properties map,
     * including :hover, :focus, :active pseudo-class variants
     * and complex selector rules.
     * 
     * Pseudo-class variants are stored with key "{className}__{pseudo}".
     * Complex selectors are stored as "__complex__{index}" with metadata.
     * 
     * @param string $styleCss  Raw content of <style>...</style>
     * @param array  $warnings  Output: collects parse warnings
     * @return array  [className => [outputKey => value], ...]
     */
    public static function parseStyleBlock(string $styleCss, array &$warnings = []): array
    {
        $classStyles = [];

        // Extract :root custom properties for var() resolution
        $variables = self::extractCustomProperties($styleCss);

        // --- First pass: Parse simple class rules ---
        // Match .className { ... }
        if (!preg_match_all('#\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}#s', $styleCss, $rules, PREG_SET_ORDER)) {
            // Even if no normal rules, still check for pseudo-class and complex rules
        }

        // --- Also parse universal selector rules: *, html, body ---
        // These apply to all elements and are stored under key '*', 'html', 'body'.
        $universalSelectors = ['*', 'html', 'body'];
        $universalRules = [];
        foreach ($universalSelectors as $us) {
            $escaped = preg_quote($us, '#');
            if (preg_match_all('#' . $escaped . '\s*\{([^}]*)\}#s', $styleCss, $m)) {
                foreach ($m[1] as $body) {
                    if (!isset($universalRules[$us])) $universalRules[$us] = '';
                    $universalRules[$us] .= $body;
                }
            }
        }
        // Treat universal rules as if they were .* { ... } in the rules list
        foreach ($universalRules as $us => $body) {
            $rules[] = [$us, $us, $body];
        }

        foreach ($rules as $rule) {
            $className = $rule[1];
            $body      = $rule[2];
            $props     = [];

            foreach (self::PROPERTY_MAP as $cssProp => $map) {
                $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                if (preg_match($pattern, $body, $m)) {
                    $value = trim($m[1]);
                    // Resolve CSS variables var(--name, fallback)
                    if (count($variables) > 0) {
                        $value = CssValueParser::resolveCSSVariables($value, $variables);
                    }
                    $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                }
            }

            // If neither background nor color was specified, log a warning
            // Skip for universal selectors — they apply to all elements and
            // don't need explicit styling.
            if (!isset($props['bg']) && !isset($props['fg'])) {
                if (!in_array($className, ['*', 'html', 'body'], true)) {
                    $warnings[] = "CSS class '$className': no background or color property (will render as transparent)";
                }
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

            // Detect relative unit values (em/rem/vw/vh) in class body
            $relativeUnitProps = [
                'font-size'     => 'fontSizeUnit',
                'width'         => 'widthUnit',
                'height'        => 'heightUnit',
                'min-width'     => 'minWidthUnit',
                'max-width'     => 'maxWidthUnit',
                'min-height'    => 'minHeightUnit',
                'max-height'    => 'maxHeightUnit',
                'margin-top'    => 'marginTopUnit',
                'margin-right'  => 'marginRightUnit',
                'margin-bottom' => 'marginBottomUnit',
                'margin-left'   => 'marginLeftUnit',
                'padding-top'   => 'paddingTopUnit',
                'padding-right' => 'paddingRightUnit',
                'padding-bottom'=>'paddingBottomUnit',
                'padding-left'  => 'paddingLeftUnit',
                'gap'           => 'gapUnit',
                'top'           => 'topUnit',
                'left'          => 'leftUnit',
                'right'         => 'rightUnit',
                'bottom'        => 'bottomUnit',
            ];
            foreach ($relativeUnitProps as $cssProp => $styleKey) {
                $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                if (preg_match($pattern, $body, $m)) {
                    $parsed = CssValueParser::parseRelativeValue(trim($m[1]));
                    if ($parsed['unit'] !== 'px') {
                        $props[$styleKey] = $parsed['value'] . '|' . $parsed['unit'];
                    }
                }
            }

            // Preserve original CSS font-weight numeric value for test comparison.
            if (preg_match('/font-weight\s*:\s*(\d+)/i', $body, $fwMatch)) {
                $props['fontWeight'] = (int)$fwMatch[1];
            }

            $classStyles[$className] = $props;
        }

        // --- Pass 1.5: Parse universal/tag selectors (*, html, body, etc.) ---
        // Store as '*' for universal base styles
        if (preg_match_all('#([a-zA-Z*]+)\s*\{([^}]*)\}#s', $styleCss, $tagRules, PREG_SET_ORDER)) {
            foreach ($tagRules as $rule) {
                $selector = $rule[1];
                // Skip class-based rules (already parsed above) and pseudo-rules
                if (str_starts_with($selector, '.') || $selector === '') continue;
                $body = $rule[2];
                $props = [];
                foreach (self::PROPERTY_MAP as $cssProp => $map) {
                    $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                    if (preg_match($pattern, $body, $m)) {
                        $value = trim($m[1]);
                        if (count($variables) > 0) {
                            $value = CssValueParser::resolveCSSVariables($value, $variables);
                        }
                        $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                    }
                }
                // Preserve original CSS font-weight numeric value for test comparison.
                if (preg_match('/font-weight\s*:\s*(\d+)/i', $body, $fwMatch)) {
                    $props['fontWeight'] = (int)$fwMatch[1];
                }
                if (!empty($props)) {
                    // 通用选择器 * 归入 '*' 键，其他标签选择器归入对应键
                    $key = $selector === '*' ? '*' : $selector;
                    $classStyles[$key] = $props;
                }
            }
        }

        // Parse pseudo-class variants: .className:hover { ... }, .className:focus { ... }, .className:active { ... }
        // Store as "{className}__hover", "{className}__focus", "{className}__active"
        $pseudoClasses = ['hover', 'focus', 'active'];
        foreach ($pseudoClasses as $pseudo) {
            if (preg_match_all('#\.([a-zA-Z0-9_-]+):' . $pseudo . '\s*\{([^}]*)\}#s', $styleCss, $pseudoRules, PREG_SET_ORDER)) {
                foreach ($pseudoRules as $rule) {
                    $className = $rule[1];
                    $body      = $rule[2];
                    $props     = [];

                    foreach (self::PROPERTY_MAP as $cssProp => $map) {
                        $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                        if (preg_match($pattern, $body, $m)) {
                            $value = trim($m[1]);
                            // Resolve CSS variables
                            if (count($variables) > 0) {
                                $value = CssValueParser::resolveCSSVariables($value, $variables);
                            }
                            $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                        }
                    }

                    // Expand border shorthand
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

                    // Expand text-decoration shorthand
                    if (preg_match('~text-decoration\s*:\s*([^;]+)~', $body, $tdMatch)) {
                        $expanded = self::expandTextDecorationValue(trim($tdMatch[1]));
                        foreach ($expanded as $tdKey => $tdVal) {
                            if (!isset($props[$tdKey])) {
                                $props[$tdKey] = $tdVal;
                            }
                        }
                    }

                    // Detect relative unit values in pseudo-class body
                    $relativeUnitProps = [
                        'font-size'     => 'fontSizeUnit',
                        'width'         => 'widthUnit',
                        'height'        => 'heightUnit',
                        'min-width'     => 'minWidthUnit',
                        'max-width'     => 'maxWidthUnit',
                        'min-height'    => 'minHeightUnit',
                        'max-height'    => 'maxHeightUnit',
                        'margin-top'    => 'marginTopUnit',
                        'margin-right'  => 'marginRightUnit',
                        'margin-bottom' => 'marginBottomUnit',
                        'margin-left'   => 'marginLeftUnit',
                        'padding-top'   => 'paddingTopUnit',
                        'padding-right' => 'paddingRightUnit',
                        'padding-bottom'=>'paddingBottomUnit',
                        'padding-left'  => 'paddingLeftUnit',
                        'gap'           => 'gapUnit',
                        'top'           => 'topUnit',
                        'left'          => 'leftUnit',
                        'right'         => 'rightUnit',
                        'bottom'        => 'bottomUnit',
                    ];
                    foreach ($relativeUnitProps as $cssProp => $styleKey) {
                        $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                        if (preg_match($pattern, $body, $m)) {
                            $parsed = CssValueParser::parseRelativeValue(trim($m[1]));
                            if ($parsed['unit'] !== 'px') {
                                $props[$styleKey] = $parsed['value'] . '|' . $parsed['unit'];
                            }
                        }
                    }

                    $pseudoKey = $className . '__' . $pseudo;
                    $classStyles[$pseudoKey] = $props;
                }
            }
        }

        // --- Parse ::before / ::after pseudo-elements (CSS Pseudo-Elements Module Level 4 §4) ---
        // Match .className::before { content: "..."; color: #...; }
        if (preg_match_all('#\.([a-zA-Z0-9_-]+)::(before|after)\s*\{([^}]*)\}#s', $styleCss, $pseudoElRules, PREG_SET_ORDER)) {
            foreach ($pseudoElRules as $rule) {
                $className = $rule[1];
                $pseudoEl = $rule[2]; // 'before' or 'after'
                $body = $rule[3];
                $props = [];

                // Extract 'content' property specially (not in PROPERTY_MAP)
                if (preg_match('~content\s*:\s*["\']?([^;"\'\}]+)["\']?\s*;?~', $body, $cMatch)) {
                    $props['content'] = trim($cMatch[1]);
                }

                foreach (self::PROPERTY_MAP as $cssProp => $map) {
                    $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                    if (preg_match($pattern, $body, $m)) {
                        $value = trim($m[1]);
                        if (count($variables) > 0) {
                            $value = CssValueParser::resolveCSSVariables($value, $variables);
                        }
                        $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                    }
                }

                $pseudoElKey = $className . '__' . $pseudoEl;
                $classStyles[$pseudoElKey] = $props;
            }
        }

        // --- Second pass: Parse complex selectors (descendant, child, sibling) ---
        // CSS Selectors Level 3: supported combinators:
        //   ' ' (descendant), '>' (child), '+' (adjacent sibling), '~' (general sibling)
        // Match .parent .child { }, .parent > .child { }, .sibling + .sibling { }, etc.
        if (preg_match_all('#\.([a-zA-Z0-9_-]+)\s*([>+~ ])\s*\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}#s', $styleCss, $complexRules, PREG_SET_ORDER)) {
            $complexIdx = 0;
            foreach ($complexRules as $rule) {
                $firstClass = $rule[1];
                $combinator = trim($rule[2]);
                $secondClass = $rule[3];
                $body = $rule[4];
                $props = [];

                foreach (self::PROPERTY_MAP as $cssProp => $map) {
                    $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                    if (preg_match($pattern, $body, $m)) {
                        $value = trim($m[1]);
                        if (count($variables) > 0) {
                            $value = CssValueParser::resolveCSSVariables($value, $variables);
                        }
                        $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                    }
                }

                // Expand border shorthand
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

                // Expand text-decoration shorthand
                if (preg_match('~text-decoration\s*:\s*([^;]+)~', $body, $tdMatch)) {
                    $expanded = self::expandTextDecorationValue(trim($tdMatch[1]));
                    foreach ($expanded as $tdKey => $tdVal) {
                        if (!isset($props[$tdKey])) {
                            $props[$tdKey] = $tdVal;
                        }
                    }
                }

                // Detect relative unit values
                $relativeUnitProps = [
                    'font-size'     => 'fontSizeUnit',
                    'width'         => 'widthUnit',
                    'height'        => 'heightUnit',
                    'margin-top'    => 'marginTopUnit',
                    'margin-right'  => 'marginRightUnit',
                    'margin-bottom' => 'marginBottomUnit',
                    'margin-left'   => 'marginLeftUnit',
                    'padding-top'   => 'paddingTopUnit',
                    'padding-right' => 'paddingRightUnit',
                    'padding-bottom'=>'paddingBottomUnit',
                    'padding-left'  => 'paddingLeftUnit',
                ];
                foreach ($relativeUnitProps as $cssProp => $styleKey) {
                    $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                    if (preg_match($pattern, $body, $m)) {
                        $parsed = CssValueParser::parseRelativeValue(trim($m[1]));
                        if ($parsed['unit'] !== 'px') {
                            $props[$styleKey] = $parsed['value'] . '|' . $parsed['unit'];
                        }
                    }
                }

                // Store complex selector rule
                $complexKey = '__complex__' . $complexIdx;
                $classStyles[$complexKey] = [
                    'firstClass'  => $firstClass,
                    'combinator'  => $combinator,
                    'secondClass' => $secondClass,
                    'props'       => $props,
                    'specificity' => self::calculateSpecificity('.' . $firstClass . ' ' . $combinator . ' .' . $secondClass),
                ];
                $complexIdx++;
            }
        }

        return $classStyles;
    }

    /**
     * Calculate CSS selector specificity (a,b,c,d) per CSS Cascading and Inheritance Level 4 §6.
     *
     * Specificity calculation:
     *   - a: inline style (not calculated here, handled externally)
     *   - b: number of ID selectors (#id)
     *   - c: number of class selectors (.class), attribute selectors, pseudo-classes
     *   - d: number of element selectors, pseudo-elements
     *
     * @param string $selector Raw CSS selector string
     * @return array [a, b, c, d] specificity values
     */
    public static function calculateSpecificity(string $selector): array
    {
        $a = 0; // inline style (always 0 here)
        $b = 0; // ID selectors
        $c = 0; // class, attribute, pseudo-class
        $d = 0; // element, pseudo-element

        // Count ID selectors
        $b = (int)preg_match_all('/#[a-zA-Z0-9_-]+/', $selector);

        // Count class selectors
        $c = (int)preg_match_all('/\.[a-zA-Z0-9_-]+/', $selector);

        // Count pseudo-classes (:hover, :focus, :active, etc.)
        $c += (int)preg_match_all('/:(?:hover|focus|active|visited|link|first-child|last-child|nth-child|nth-of-type|not|is|where|has|enabled|disabled|checked|empty|target)/', $selector);

        // Count attribute selectors [attr]
        $c += (int)preg_match_all('/\[[^\]]+\]/', $selector);

        // Remaining simple selectors are element selectors
        // Remove ID, class, pseudo-class, attribute selectors first
        $remaining = preg_replace(
            ['/#[a-zA-Z0-9_-]+/', '/\.[a-zA-Z0-9_-]+/', '/:[a-zA-Z-]+(?:([^)]*))?/', '/\[[^\]]+\]/'],
            '',
            $selector
        );
        // Count remaining tags (words not separated by combinators)
        // Split by combinators (space, >, +, ~) and count non-empty parts that look like element names
        $parts = preg_split('/\s*[>+~ ]\s*/', $remaining);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && !str_starts_with($part, '.') && !str_starts_with($part, '#') && !str_starts_with($part, ':') && !str_starts_with($part, '[')) {
                // It's an element selector (e.g., 'div', 'span')
                if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $part)) {
                    $d++;
                }
            }
        }
        // Also count pseudo-elements (::before, ::after, etc.)
        $d += (int)preg_match_all('/::[a-zA-Z-]+/', $selector);

        return [$a, $b, $c, $d];
    }

    /**
     * Alias for calculateSpecificity().
     *
     * @param string $selector Raw CSS selector string
     * @return array [a, b, c, d] specificity values
     */
    public static function countSpecificity(string $selector): array
    {
        return self::calculateSpecificity($selector);
    }

    /**
     * Compare two specificity arrays.
     * Returns -1 if $a < $b, 0 if equal, 1 if $a > $b.
     *
     * @param array $specA First specificity [a,b,c,d]
     * @param array $specB Second specificity [a,b,c,d]
     * @return int Comparison result
     */
    public static function compareSpecificity(array $specA, array $specB): int
    {
        for ($i = 0; $i < 4; $i++) {
            $sa = $specA[$i] ?? 0;
            $sb = $specB[$i] ?? 0;
            if ($sa !== $sb) {
                return $sa > $sb ? 1 : -1;
            }
        }
        return 0;
    }

    /**
     * Check if a complex selector matches given parent and child class names.
     *
     * @param string $combinator  The combinator character (' ', '>', '+', '~')
     * @param string $firstClass  The first/left class in the selector
     * @param string $secondClass The second/right class in the selector
     * @param string $parentClassStr  The parent VNode's class attribute string
     * @param string $childClassStr   The child VNode's class attribute string
     * @param array  $parentSiblings  Array of preceding sibling class strings (for + and ~)
     * @return bool True if the selector matches
     */
    public static function matchComplexSelector(string $combinator, string $firstClass, string $secondClass, string $parentClassStr, string $childClassStr, array $parentSiblings = []): bool
    {
        $childClasses = explode(' ', $childClassStr);
        // The second class must match the child element
        if (!in_array($secondClass, $childClasses, true)) {
            return false;
        }

        $parentClasses = explode(' ', $parentClassStr);

        switch ($combinator) {
            case ' ':
                // Descendant: parent must contain the first class
                return in_array($firstClass, $parentClasses, true);

            case '>':
                // Child: direct parent must contain the first class
                return in_array($firstClass, $parentClasses, true);

            case '+':
                // Adjacent sibling: the immediately preceding sibling must have the first class
                if (count($parentSiblings) === 0) return false;
                $prevSibling = end($parentSiblings);
                $prevClasses = explode(' ', $prevSibling);
                return in_array($firstClass, $prevClasses, true);

            case '~':
                // General sibling: any preceding sibling must have the first class
                foreach ($parentSiblings as $sibling) {
                    $siblingClasses = explode(' ', $sibling);
                    if (in_array($firstClass, $siblingClasses, true)) {
                        return true;
                    }
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * Get pseudo-class styles for a CSS class name.
     * Returns the parsed :hover/:focus/:active variant properties, or empty array if not defined.
     *
     * @param array  $classStyles  Result of parseStyleBlock()
     * @param string $className    CSS class name (without pseudo-class suffix)
     * @param string $pseudo       Pseudo-class name ('hover', 'focus', 'active')
     * @return array  Pseudo-class properties, or [] if no variant defined
     */
    public static function getPseudoStyle(array $classStyles, string $className, string $pseudo = 'hover'): array
    {
        return $classStyles[$className . '__' . $pseudo] ?? [];
    }

    /**
     * Get hover styles for a CSS class name (convenience wrapper).
     */
    public static function getHoverStyle(array $classStyles, string $className): array
    {
        return self::getPseudoStyle($classStyles, $className, 'hover');
    }

    /**
     * Extract CSS custom property definitions from :root { ... } blocks.
     *
     * CSS Custom Properties for Cascading Variables Module Level 1 §3:
     * Custom properties are --* names defined in :root or element-level style.
     * This method extracts them from :root in a <style> block for var() resolution.
     *
     * @param string $styleCss Raw <style> block content
     * @return array  Map of --name => raw value
     */
    public static function extractCustomProperties(string $styleCss): array
    {
        $variables = [];
        if (preg_match('/:root\s*\{([^}]*)\}/s', $styleCss, $m)) {
            $body = $m[1];
            if (preg_match_all('/\s*(--[a-zA-Z0-9_-]+)\s*:\s*([^;]+);?/', $body, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $varName = trim($match[1]);
                    $varValue = trim($match[2]);
                    $variables[$varName] = $varValue;
                }
            }
        }
        return $variables;
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