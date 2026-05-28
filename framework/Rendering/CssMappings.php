<?php

namespace Px\Rendering;

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
        'box-shadow' => [
            'key'     => 'boxShadow',
            'parser'  => 'Px\\Rendering\\CssMappings::parseIdent',
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
        'overflow-y'       => ['key' => 'overflowY',        'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'visible'],
        'position'         => ['key' => 'position',         'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => 'static'],
        'z-index'          => ['key' => 'zIndex',           'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'grid-template-columns' => ['key' => 'gridTemplateColumns', 'parser' => 'Px\\Rendering\\CssMappings::parseIdent', 'default' => ''],
        'grid-template-rows'    => ['key' => 'gridTemplateRows',    'parser' => 'Px\\Rendering\\CssMappings::parseIdent', 'default' => ''],
        'grid-column-gap'      => ['key' => 'gridColumnGap', 'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'grid-row-gap'         => ['key' => 'gridRowGap',    'parser' => 'Px\\Rendering\\CssMappings::parsePixels', 'default' => 0],
        'grid-row'             => ['key' => 'gridRow',       'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => ''],
        'grid-column'          => ['key' => 'gridColumn',    'parser' => 'Px\\Rendering\\CssMappings::parseIdent',  'default' => ''],
        'flex'                 => ['key' => 'flex',           'parser' => 'Px\\Rendering\\CssMappings::parseFlex',  'default' => ''],
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
            if (preg_match('/#[0-9a-fA-F]{3,6}|rgb\s*\([^)]+\)/', $value, $m)) {
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
        return (int) preg_replace('/[^0-9]/', '', $value);
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
        return trim(strtolower($value));
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
            case 'Px\\Rendering\\CssMappings::parseIdent':      return self::parseIdent($value);
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

        // Merge both PROPERTY_MAP and INLINE_PROPERTY_MAP for lookup
        $lookup = array_merge(self::PROPERTY_MAP, self::INLINE_PROPERTY_MAP);

        foreach ($m as $decl) {
            $propName = strtolower(trim($decl[1]));
            $value    = trim($decl[2]);

            $map = $lookup[$propName] ?? null;
            if ($map !== null) {
                $style[$map['key']] = self::dispatchParser($map['parser'], $value);
            } else {
                // Convert kebab-case to camelCase for unknown properties
                $camelCase = self::kebabToCamelCase($propName);
                $style[$camelCase] = $value;
            }
        }

        return $style;
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
}