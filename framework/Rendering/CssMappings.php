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
        // ---- Layout/positioning properties (also in INLINE_PROPERTY_MAP for <style> block support) ----
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
        'justify-self'     => ['key' => 'justifySelf', 'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'auto'],
        'min-width'        => ['key' => 'minWidth',  'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
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
    ];

    /**
     * Inline style → 布局属性映射 (用于 parseInlineStyle)
     *
     * 这些属性不出现在 <style> 块中, 而是在元素的 style="..." 属性里。
     * 解析后存入 VNode.computedStyle 数组。
     */
    const INLINE_PROPERTY_MAP = [
        'width'            => ['key' => 'width',            'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'height'           => ['key' => 'height',           'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'left'             => ['key' => 'left',             'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'top'              => ['key' => 'top',              'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'right'            => ['key' => 'right',            'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'bottom'           => ['key' => 'bottom',           'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'display'          => ['key' => 'display',          'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'block'],
        'flex-direction'   => ['key' => 'flexDirection',    'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'row'],
        'flex-wrap'        => ['key' => 'flexWrap',         'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'nowrap'],
        'justify-content'  => ['key' => 'justifyContent',   'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'flex-start'],
        'align-items'      => ['key' => 'alignItems',       'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'stretch'],
        'align-content'    => ['key' => 'alignContent',     'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'stretch'],
        'gap'              => ['key' => 'gap',              'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'overflow'         => ['key' => 'overflow',         'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'visible'],
        'overflow-x'       => ['key' => 'overflowX',        'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'visible'],
        'overflow-y'       => ['key' => 'overflowY',        'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'visible'],
        'text-overflow'    => ['key' => 'textOverflow',      'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'clip'],
        'position'         => ['key' => 'position',         'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'static'],
        'z-index'          => ['key' => 'zIndex',           'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'grid-template-columns' => ['key' => 'gridTemplateColumns', 'parser' => 'Px\\Rendering\\CssMappings::parseIdent', 'default' => ''],
        'grid-template-rows'    => ['key' => 'gridTemplateRows',    'parser' => 'Px\\Rendering\\CssMappings::parseIdent', 'default' => ''],
        'grid-column-gap'      => ['key' => 'gridColumnGap', 'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'grid-row-gap'         => ['key' => 'gridRowGap',    'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'grid-row'             => ['key' => 'gridRow',       'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => ''],
        'grid-column'          => ['key' => 'gridColumn',    'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => ''],
        'flex'                 => ['key' => 'flex',           'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => ''],
        // ---- min/max 尺寸约束 ----
        'min-width'  => ['key' => 'minWidth',  'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'max-width'  => ['key' => 'maxWidth',  'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'min-height' => ['key' => 'minHeight', 'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'max-height' => ['key' => 'maxHeight', 'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        // ---- flex 扩展 ----
        'order'        => ['key' => 'order',        'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'flex-grow'    => ['key' => 'flexGrow',    'parser' => 'Px\Rendering\CssMappings::parsePixels', 'default' => 0],
        'flex-basis'   => ['key' => 'flexBasis',    'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'auto'],
        'flex-shrink'  => ['key' => 'flexShrink',   'parser' => 'Px\Rendering\CssMappings::parsePixels', 'default' => 1],
        // ---- 单项对齐 ----
        'align-self'   => ['key' => 'alignSelf',   'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'auto'],
        'justify-self' => ['key' => 'justifySelf', 'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'auto'],
        // ---- padding / margin 四方向 ----
        'padding-top'    => ['key' => 'paddingTop',    'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'padding-right'  => ['key' => 'paddingRight',  'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'padding-bottom' => ['key' => 'paddingBottom', 'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'padding-left'   => ['key' => 'paddingLeft',   'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'margin-top'     => ['key' => 'marginTop',     'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'margin-right'   => ['key' => 'marginRight',   'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'margin-bottom'  => ['key' => 'marginBottom',  'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'margin-left'    => ['key' => 'marginLeft',    'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'border-width'   => ['key' => 'borderWidth',  'parser' => 'Px\Rendering\CssMappings::parsePixels', 'default' => 0],
        'border-color'   => ['key' => 'borderColor',  'parser' => 'Px\Rendering\CssMappings::parseHexColor', 'default' => 0],
        'border-bottom'  => ['key' => 'borderBottom', 'parser' => 'Px\Rendering\CssMappings::parseBorder', 'default' => ''],
        'border-top'     => ['key' => 'borderTop',    'parser' => 'Px\Rendering\CssMappings::parseBorder', 'default' => ''],
        'border-left'    => ['key' => 'borderLeft',   'parser' => 'Px\Rendering\CssMappings::parseBorder', 'default' => ''],
        'border-right'   => ['key' => 'borderRight',  'parser' => 'Px\Rendering\CssMappings::parseBorder', 'default' => ''],
        'border-radius'  => ['key' => 'borderRadius',  'parser' => 'Px\Rendering\CssMappings::parsePixels', 'default' => 0],
        'object-fit'     => ['key' => 'objectFit',     'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'fill'],
        'white-space'    => ['key' => 'whiteSpace',    'parser' => 'Px\Rendering\CssMappings::parseIdent',  'default' => 'normal'],
        'background-size' => ['key' => 'backgroundSize', 'parser' => 'Px\Rendering\CssMappings::parseIdent', 'default' => ''],
        'background-position' => ['key' => 'backgroundPosition', 'parser' => 'Px\Rendering\CssMappings::parseIdent', 'default' => ''],
        'background-image'     => ['key' => 'backgroundImage', 'parser' => 'Px\Rendering\CssMappings::parseBackgroundImage', 'default' => ''],
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
        // Strip 0x / 0X prefix (C-style hex literal) and # prefix (CSS)
        $hex = ltrim($hex, '#');
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }

        // Support shorthand: #RGB → #RRGGBB
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return 0; // Invalid color → black
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return ($b << 16) | ($g << 8) | $r;
    }

    /**
     * Derive border color from background (lighten each channel by a delta).
     */
    public static function borderColor(int $bg, int $delta = 20): int
    {
        $r = min(255, (($bg >> 16) & 0xFF) + $delta);
        $g = min(255, (($bg >> 8)  & 0xFF) + $delta);
        $b = min(255, ($bg         & 0xFF) + $delta);
        return ($r << 16) | ($g << 8) | $b;
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
        $value = trim($value);

        // Handle linear-gradient: extract first color stop
        if (str_starts_with($value, 'linear-gradient')) {
            // Extract first color stop: linear-gradient(135deg, #667eea 0%, #764ba2 100%)
            if (preg_match('/#[0-9a-fA-F]{3,8}|rgba?\s*\([^)]+\)/', $value, $m)) {
                return self::parseHexColor($m[0]);
            }
            return 0;
        }

        // Handle rgb/rgba: rgb(255, 255, 255)
        if (preg_match('/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $value, $m)) {
            $r = (int)$m[1];
            $g = (int)$m[2];
            $b = (int)$m[3];
            return ($b << 16) | ($g << 8) | $r;
        }

        // Handle hex color
        return self::hexToBgr($value);
    }

    /**
     * Parse "16px" → 16 (int)
     */
    public static function parsePixels(string $value): int
    {
        return (int) preg_replace('/[^-0-9]/', '', $value);
    }

    /**
     * Parse "1" / "1.5" / "0" → flex grow value as string (e.g., "1", "2")
     */
    public static function parseFlex(string $value): string
    {
        $value = trim($value);
        // Extract numeric part
        if (preg_match('/^(\d+(?:\.\d+)?)/', $value, $m)) {
            return $m[1];
        }
        return $value;
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
        $flex = trim($flex);
        if ($flex === '') {
            return ['grow' => 0.0, 'shrink' => 1.0, 'basis' => 0];
        }

        // CSS keyword values: auto, none, initial, content
        $lower = strtolower($flex);
        if ($lower === 'auto') {
            return ['grow' => 1.0, 'shrink' => 1.0, 'basis' => 'auto'];
        }
        if ($lower === 'none') {
            return ['grow' => 0.0, 'shrink' => 0.0, 'basis' => 'auto'];
        }
        if ($lower === 'initial' || $lower === 'content') {
            return ['grow' => 0.0, 'shrink' => 1.0, 'basis' => 'auto'];
        }

        // Multi-value form: "grow shrink basis"
        $parts = preg_split('/\s+/', $flex);
        $result = ['grow' => 0.0, 'shrink' => 1.0, 'basis' => 0];

        if (count($parts) >= 1 && $parts[0] !== '') {
            $result['grow'] = (float)$parts[0];
        }
        if (count($parts) >= 2 && $parts[1] !== '') {
            $result['shrink'] = (float)$parts[1];
        }
        if (count($parts) >= 3 && $parts[2] !== '') {
            $v = strtolower(trim($parts[2]));
            if ($v === 'auto' || $v === 'content') {
                $result['basis'] = $v;
            } else {
                $result['basis'] = (int) preg_replace('/[^0-9]/', '', $parts[2]);
            }
        }

        return $result;
    }

    /**
     * Parse "bold" / "700" → 1, "normal" / "400" → 0
     */
    public static function parseFontWeight(string $value): int
    {
        $v = trim(strtolower($value));
        if ($v === 'bold' || (int)$v >= 600) {
            return 1;
        }
        return 0;
    }

    /**
     * Parse "left" / "right" / "center" → align string
     */
    public static function parseTextAlign(string $value): string
    {
        $v = trim(strtolower($value));
        if (in_array($v, ['left', 'right', 'center'], true)) {
            return $v;
        }
        return 'left';
    }

    /**
     * Parse "1px solid #d9d9d9" → border string (v8)
     */
    public static function parseBorder(string $value): string
    {
        $v = trim($value);
        if ($v === '' || $v === 'none') return '';
        $parts = preg_split('/\s+/', $v);
        $width = 0;
        $color = '#000000';
        foreach ($parts as $p) {
            if (preg_match('/^\d+/', $p)) {
                $width = (int)$p;
            } elseif (preg_match('/^#/', $p)) {
                $color = $p;
            }
        }
        return $width . '|' . self::hexToBgr($color);
    }

    /**
     * Parse "h-offset v-offset [blur] [spread] [color]" into structured string.
     * Returns "h|v|blur|spread|color" for downstream use.
     */
    public static function parseBoxShadow(string $value): string
    {
        $v = trim($value);
        if ($v === '' || $v === 'none') return '';

        // 先提取颜色值（rgba/rgb/hex），避免空格干扰 split
        $color = '#000000';
        $numericStr = $v;

        if (preg_match('/rgba?\s*\([^)]+\)/i', $v, $m)) {
            // 提取 rgba/rgb 颜色分量
            if (preg_match('/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $m[0], $cm)) {
                $r = (int)$cm[1]; $g = (int)$cm[2]; $b = (int)$cm[3];
                $color = sprintf('#%02X%02X%02X', $r, $g, $b);
            }
            // 移除颜色部分（处理多阴影逗号连接的情况）
            $numericStr = trim(preg_replace('/' . preg_quote(explode('(', $m[0])[0], '/') . '\([^)]+\)\s*,?\s*/', '', $v));
        } elseif (preg_match('/#([0-9a-fA-F]{3,8})\b/', $v, $m)) {
            $color = $m[0];
            $numericStr = trim(str_replace($m[0], '', $v));
        }

        // 按空白分割剩余数值
        $parts = preg_split('/\s+/', $numericStr);
        $numParts = [];
        foreach ($parts as $p) {
            if (trim($p) === '') continue;
            $numParts[] = (int)$p;
        }

        $h = $numParts[0] ?? 0;
        $vOff = $numParts[1] ?? 0;
        $blur = $numParts[2] ?? 0;
        $spread = $numParts[3] ?? 0;
        return $h . '|' . $vOff . '|' . $blur . '|' . $spread . '|' . $color;
    }

    /**
     * Parse "0.5" or "50%" → float 0.0-1.0 (v8)
     */
    public static function parseOpacity(string $value): float
    {
        $v = trim($value);
        if (str_ends_with($v, '%')) {
            return ((float)substr($v, 0, -1)) / 100.0;
        }
        return min(1.0, max(0.0, (float)$v));
    }

    /**
     * Parse identity: return the trimmed value as-is
     * Used for display, flex-direction, overflow, position 等关键字属性
     */
    public static function parseIdent(string $value): string
    {
        return trim(strtolower($value));
    }

    /**
     * Parse `url("path/to/image.png")` → extract the image path
     * Matches CSS background-image property: background-image: url("...")
     * Supports both single/double quotes and unquoted URLs.
     */
    public static function parseBackgroundImage(string $value): string
    {
        $v = trim($value);
        // Match url("...") with single quotes, double quotes, or unquoted
        if (preg_match('/^url\(\s*["\']?([^"\'\)]+)["\']?\s*\)$/', $v, $m)) {
            return trim($m[1]);
        }
        // If it doesn't look like a CSS url(), just return the raw value
        return $v;
    }

    /**
     * AOT-compatible parser dispatcher.
     * Replaces call_user_func() which is not supported by AOT.
     */
    private static function dispatchParser(string $parser, string $value): mixed
    {
        switch ($parser) {
            case 'Px\\Rendering\\CssMappings::parseHexColor':   return self::parseHexColor($value);
            case 'Px\\Rendering\\CssMappings::parsePixels':     return self::parsePixels($value);
            case 'Px\\Rendering\\CssMappings::parseFlex':      return self::parseFlex($value);
            case 'Px\\Rendering\\CssMappings::parseFontWeight': return self::parseFontWeight($value);
            case 'Px\\Rendering\\CssMappings::parseTextAlign':  return self::parseTextAlign($value);
            case 'Px\\Rendering\\CssMappings::parseBorder':     return self::parseBorder($value);
            case 'Px\\Rendering\\CssMappings::parseOpacity':   return self::parseOpacity($value);
            case 'Px\Rendering\CssMappings::parseIdent':      return self::parseIdent($value);
            case 'Px\Rendering\CssMappings::parseBackgroundImage': return self::parseBackgroundImage($value);
            default:                             return $value;
        }
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

        // Pre-detect percentage values for layout properties.
        // Store as "widthPercent" (float, e.g. 50.0 for "50%") alongside the
        // regular pixel key. LayoutResolver checks *Percent first.
        $pctMap = [
            'width' => 'widthPercent', 'height' => 'heightPercent',
            'min-width' => 'minWidthPercent', 'max-width' => 'maxWidthPercent',
            'min-height' => 'minHeightPercent', 'max-height' => 'maxHeightPercent',
        ];
        foreach ($pctMap as $cssProp => $styleKey) {
            if (isset($raw[$cssProp]) && str_ends_with(trim($raw[$cssProp]), '%')) {
                $style[$styleKey] = (float) substr(trim($raw[$cssProp]), 0, -1);
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
        foreach ($marginAutoFlags as $key => $val) {
            $style[$key] = $val;
        }

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
        $val = trim($val);

        // match "repeat(N, SIZE)" — SIZE can be px, fr, % or bare number
        if (preg_match('/^repeat\(\s*(\d+)\s*,\s*(\d+(?:\.\d+)?)(px|fr|%|)\s*\)$/i', $val, $m)) {
            $unit = strtolower($m[3] ?? '');
            $size = (float)$m[2];
            // Keep as float if 'fr', else convert to int for px
            return ['repeat' => true, 'count' => (int)$m[1], 'size' => ($unit === 'fr' || $unit === '%') ? $size : (int)$size, 'unit' => $unit];
        }

        // match auto-fill/auto-fit repeat: repeat(auto-fill, minmax(MIN, MAX)) or repeat(auto-fit, minmax(MIN, MAX))
        if (preg_match('/^repeat\(\s*(auto-fill|auto-fit)\s*,\s*minmax\(\s*(\d+(?:\.\d+)?)(px|%|)\s*,\s*(\d+(?:\.\d+)?)(px|fr|%|)\s*\)\s*\)$/i', $val, $m)) {
            $mode = strtolower($m[1]);
            $min = (float)$m[2];
            $minUnit = strtolower($m[3] ?? '');
            $max = $m[4];
            $maxTrack = strtolower($m[5] ?? '');
            return [
                'repeat' => $mode,
                'min' => ($minUnit === '%' || $minUnit === '') ? (int)$min : (int)$min,
                'minUnit' => $minUnit,
                'max' => (float)$max,
                'maxTrack' => $maxTrack,
            ];
        }

        // match complex repeat: repeat(N, minmax(...)) or repeat(N, calc(...))
        if (preg_match('/^repeat\(\s*(\d+)\s*,\s*(.+)\)$/i', $val, $m)) {
            return ['repeat' => true, 'count' => (int)$m[1], 'track' => trim($m[2])];
        }

        // match "auto"
        if (strtolower($val) === 'auto') {
            return ['type' => 'auto'];
        }

        // match "1fr 1fr 1fr 1fr" or "100px 1fr auto"
        $parts = preg_split('/\s+/', $val);
        $sizes = [];
        foreach ($parts as $part) {
            if ($part !== '') {
                $sizes[] = $part;
            }
        }

        if (count($sizes) > 0) {
            return ['type' => 'explicit', 'sizes' => $sizes];
        }

        return ['type' => 'none'];
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
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return ($b << 16) | ($g << 8) | $r;
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
        $result = [];
        $value = trim($value);

        if ($value === '' || $value === 'none') {
            return $result;
        }

        // 按逗号分割多个 transition
        $transitions = preg_split('/\s*,\s*/', $value);
        foreach ($transitions as $transition) {
            $transition = trim($transition);
            if ($transition === '') continue;

            // 解析各部分
            $parts = preg_split('/\s+/', $transition);
            $parsed = [
                'property' => 'all',
                'duration' => 300,
                'timing'   => 'ease',
                'delay'    => 0,
            ];

            foreach ($parts as $i => $part) {
                // 检测是时间值（秒或毫秒）
                if (preg_match('/^(\d+(?:\.\d+)?)(m?s)$/', $part, $m)) {
                    $time = (float)$m[1];
                    if ($m[2] === 's') {
                        $time *= 1000; // 秒转毫秒
                    }
                    if ($parsed['duration'] === 300 && $i < 3) {
                        $parsed['duration'] = (int)$time;
                    } else {
                        $parsed['delay'] = (int)$time;
                    }
                } elseif (stripos($part, 'ms') !== false || stripos($part, 's') !== false) {
                    // 已在上面处理
                } elseif (in_array(strtolower($part), ['linear', 'ease', 'ease-in', 'ease-out', 'ease-in-out'])) {
                    $parsed['timing'] = strtolower($part);
                } elseif ($part !== 'cubic-bezier' && strpos($part, '(') === false) {
                    // 排除函数名，保留属性名
                    $parsed['property'] = strtolower($part);
                }
            }

            $result[] = $parsed;
        }

        return $result;
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
        $value = trim($value);

        if ($value === '' || $value === 'none') {
            return [
                'name'     => '',
                'duration' => 0,
                'timing'   => 'ease',
                'delay'    => 0,
                'count'    => 1,
                'direction' => 'normal',
                'fillMode'  => 'none',
                'playState' => 'running',
            ];
        }

        $parts = preg_split('/\s+/', $value);
        $parsed = [
            'name'      => '',
            'duration'  => 0,
            'timing'    => 'ease',
            'delay'     => 0,
            'count'     => 1,
            'direction' => 'normal',
            'fillMode'  => 'none',
            'playState'  => 'running',
        ];

        foreach ($parts as $part) {
            // 时间值
            if (preg_match('/^(\d+(?:\.\d+)?)(m?s)$/', $part, $m)) {
                $time = (float)$m[1];
                if ($m[2] === 's') {
                    $time *= 1000;
                }
                if ($parsed['duration'] === 0) {
                    $parsed['duration'] = (int)$time;
                } else {
                    $parsed['delay'] = (int)$time;
                }
            }
            // 缓动函数
            elseif (in_array(strtolower($part), ['linear', 'ease', 'ease-in', 'ease-out', 'ease-in-out'])) {
                $parsed['timing'] = strtolower($part);
            }
            // 循环次数
            elseif ($part === 'infinite') {
                $parsed['count'] = -1; // -1 表示无限
            } elseif (ctype_digit($part)) {
                $parsed['count'] = (int)$part;
            }
            // 方向
            elseif (in_array(strtolower($part), ['normal', 'reverse', 'alternate', 'alternate-reverse'])) {
                $parsed['direction'] = strtolower($part);
            }
            // 填充模式
            elseif (in_array(strtolower($part), ['none', 'forwards', 'backwards', 'both'])) {
                $parsed['fillMode'] = strtolower($part);
            }
            // 播放状态
            elseif (in_array(strtolower($part), ['running', 'paused'])) {
                $parsed['playState'] = strtolower($part);
            }
            // 动画名称
            else {
                $parsed['name'] = $part;
            }
        }

        return $parsed;
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
        $result = ['translateX' => 0, 'translateY' => 0, 'rotate' => 0];
        $value = trim($value);

        if ($value === '') {
            return $result;
        }

        // 解析 rotate(deg) 形式
        if (preg_match('/rotate\s*\(\s*([\d.-]+)\s*deg\s*\)/i', $value, $m)) {
            $result['rotate'] = (int)$m[1];
        }

        // 解析 translate(X, Y) 简写形式
        if (preg_match('/translate\s*\(\s*([^,)]+)\s*(?:,\s*([^,)]+))?\s*\)/i', $value, $m)) {
            $result['translateX'] = self::parsePixels($m[1]);
            if (isset($m[2]) && $m[2] !== '') {
                $result['translateY'] = self::parsePixels($m[2]);
            }
            return $result;
        }

        // 解析 translateX(X)
        if (preg_match('/translateX\s*\(\s*([^)]+)\s*\)/i', $value, $m)) {
            $result['translateX'] = self::parsePixels($m[1]);
        }

        // 解析 translateY(Y)
        if (preg_match('/translateY\s*\(\s*([^)]+)\s*\)/i', $value, $m)) {
            $result['translateY'] = self::parsePixels($m[1]);
        }

        return $result;
    }

    /**
     * 序列化 transform 数组为 CSS 字符串。
     *
     * @param array $transform ['translateX' => int, 'translateY' => int]
     * @return string CSS transform 值
     */
    public static function buildTransformString(array $transform): string
    {
        $parts = [];
        $translateX = $transform['translateX'] ?? 0;
        $translateY = $transform['translateY'] ?? 0;

        if ($translateX !== 0 || $translateY !== 0) {
            if ($translateY !== 0) {
                $parts[] = "translate({$translateX}px, {$translateY}px)";
            } else {
                $parts[] = "translateX({$translateX}px)";
            }
        }

        return implode(' ', $parts);
    }
}