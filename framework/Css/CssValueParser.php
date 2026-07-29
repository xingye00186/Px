<?php

namespace Px\Css;

/**
 * CssValueParser — CSS 值解析器
 *
 * 从 CssMappings 提取，职责单一：将 CSS 属性值字符串解析为 PHP 类型值。
 * 所有方法为 public static，与 CssMappings 兼容。
 */
class CssValueParser
{
    // ============================================================
    // Color helpers
    // ============================================================

    /**
     * Convert CSS hex color #RRGGBB to GDI BGR integer (COLORREF).
     * Supports shorthand #RGB (expanded to #RRGGBB).
     */
    public static function hexToBgr(string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        // C3a.2：#RGBA（4 位）/ #RRGGBBAA（8 位）——消灭"strlen!==6 返 0 变黑"；
        // alpha 字节打包入高 8 位（<255；255 → 高字节 0 opaque 向后兼容）。
        if (strlen($hex) === 4) {
            // #RGBA → 展开为 #RRGGBBAA
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }
        if (strlen($hex) === 8 && ctype_xdigit($hex)) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            $a = hexdec(substr($hex, 6, 2));
            $bgr = ($b << 16) | ($g << 8) | $r;
            return $a < 255 ? (($a << 24) | $bgr) : $bgr;
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return 0;
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
    // Property parsers
    // ============================================================

    private const NAMED_COLORS = [
        // CSS Color Module Level 4 §6.1 — 完整 148 个命名颜色（规范 RGB 0xRRGGBB，
        // 查表时经 rgbToBgr() 转为 Px 内部 BGR）。规范值可直接对标校验，
        // 避免手工 BGR 转换出错；旧表仅 28 色（cornflowerblue/rebeccapurple 等
        // 120 色缺失 → 生产渲染为黑/透明）。
        'aliceblue' => 0xF0F8FF, 'antiquewhite' => 0xFAEBD7, 'aqua' => 0x00FFFF,
        'aquamarine' => 0x7FFFD4, 'azure' => 0xF0FFFF, 'beige' => 0xF5F5DC,
        'bisque' => 0xFFE4C4, 'black' => 0x000000, 'blanchedalmond' => 0xFFEBCD,
        'blue' => 0x0000FF, 'blueviolet' => 0x8A2BE2, 'brown' => 0xA52A2A,
        'burlywood' => 0xDEB887, 'cadetblue' => 0x5F9EA0, 'chartreuse' => 0x7FFF00,
        'chocolate' => 0xD2691E, 'coral' => 0xFF7F50, 'cornflowerblue' => 0x6495ED,
        'cornsilk' => 0xFFF8DC, 'crimson' => 0xDC143C, 'cyan' => 0x00FFFF,
        'darkblue' => 0x00008B, 'darkcyan' => 0x008B8B, 'darkgoldenrod' => 0xB8860B,
        'darkgray' => 0xA9A9A9, 'darkgreen' => 0x006400, 'darkgrey' => 0xA9A9A9,
        'darkkhaki' => 0xBDB76B, 'darkmagenta' => 0x8B008B, 'darkolivegreen' => 0x556B2F,
        'darkorange' => 0xFF8C00, 'darkorchid' => 0x9932CC, 'darkred' => 0x8B0000,
        'darksalmon' => 0xE9967A, 'darkseagreen' => 0x8FBC8F, 'darkslateblue' => 0x483D8B,
        'darkslategray' => 0x2F4F4F, 'darkslategrey' => 0x2F4F4F, 'darkturquoise' => 0x00CED1,
        'darkviolet' => 0x9400D3, 'deeppink' => 0xFF1493, 'deepskyblue' => 0x00BFFF,
        'dimgray' => 0x696969, 'dimgrey' => 0x696969, 'dodgerblue' => 0x1E90FF,
        'firebrick' => 0xB22222, 'floralwhite' => 0xFFFAF0, 'forestgreen' => 0x228B22,
        'fuchsia' => 0xFF00FF, 'gainsboro' => 0xDCDCDC, 'ghostwhite' => 0xF8F8FF,
        'gold' => 0xFFD700, 'goldenrod' => 0xDAA520, 'gray' => 0x808080,
        'green' => 0x008000, 'greenyellow' => 0xADFF2F, 'grey' => 0x808080,
        'honeydew' => 0xF0FFF0, 'hotpink' => 0xFF69B4, 'indianred' => 0xCD5C5C,
        'indigo' => 0x4B0082, 'ivory' => 0xFFFFF0, 'khaki' => 0xF0E68C,
        'lavender' => 0xE6E6FA, 'lavenderblush' => 0xFFF0F5, 'lawngreen' => 0x7CFC00,
        'lemonchiffon' => 0xFFFACD, 'lightblue' => 0xADD8E6, 'lightcoral' => 0xF08080,
        'lightcyan' => 0xE0FFFF, 'lightgoldenrodyellow' => 0xFAFAD2, 'lightgray' => 0xD3D3D3,
        'lightgreen' => 0x90EE90, 'lightgrey' => 0xD3D3D3, 'lightpink' => 0xFFB6C1,
        'lightsalmon' => 0xFFA07A, 'lightseagreen' => 0x20B2AA, 'lightskyblue' => 0x87CEFA,
        'lightslategray' => 0x778899, 'lightslategrey' => 0x778899, 'lightsteelblue' => 0xB0C4DE,
        'lightyellow' => 0xFFFFE0, 'lime' => 0x00FF00, 'limegreen' => 0x32CD32,
        'linen' => 0xFAF0E6, 'magenta' => 0xFF00FF, 'maroon' => 0x800000,
        'mediumaquamarine' => 0x66CDAA, 'mediumblue' => 0x0000CD, 'mediumorchid' => 0xBA55D3,
        'mediumpurple' => 0x9370DB, 'mediumseagreen' => 0x3CB371, 'mediumslateblue' => 0x7B68EE,
        'mediumspringgreen' => 0x00FA9A, 'mediumturquoise' => 0x48D1CC, 'mediumvioletred' => 0xC71585,
        'midnightblue' => 0x191970, 'mintcream' => 0xF5FFFA, 'mistyrose' => 0xFFE4E1,
        'moccasin' => 0xFFE4B5, 'navajowhite' => 0xFFDEAD, 'navy' => 0x000080,
        'oldlace' => 0xFDF5E6, 'olive' => 0x808000, 'olivedrab' => 0x6B8E23,
        'orange' => 0xFFA500, 'orangered' => 0xFF4500, 'orchid' => 0xDA70D6,
        'palegoldenrod' => 0xEEE8AA, 'palegreen' => 0x98FB98, 'paleturquoise' => 0xAFEEEE,
        'palevioletred' => 0xDB7093, 'papayawhip' => 0xFFEFD5, 'peachpuff' => 0xFFDAB9,
        'peru' => 0xCD853F, 'pink' => 0xFFC0CB, 'plum' => 0xDDA0DD,
        'powderblue' => 0xB0E0E6, 'purple' => 0x800080, 'rebeccapurple' => 0x663399,
        'red' => 0xFF0000, 'rosybrown' => 0xBC8F8F, 'royalblue' => 0x4169E1,
        'saddlebrown' => 0x8B4513, 'salmon' => 0xFA8072, 'sandybrown' => 0xF4A460,
        'seagreen' => 0x2E8B57, 'seashell' => 0xFFF5EE, 'sienna' => 0xA0522D,
        'silver' => 0xC0C0C0, 'skyblue' => 0x87CEEB, 'slateblue' => 0x6A5ACD,
        'slategray' => 0x708090, 'slategrey' => 0x708090, 'snow' => 0xFFFAFA,
        'springgreen' => 0x00FF7F, 'steelblue' => 0x4682B4, 'tan' => 0xD2B48C,
        'teal' => 0x008080, 'thistle' => 0xD8BFD8, 'tomato' => 0xFF6347,
        'turquoise' => 0x40E0D0, 'violet' => 0xEE82EE, 'wheat' => 0xF5DEB3,
        'white' => 0xFFFFFF, 'whitesmoke' => 0xF5F5F5, 'yellow' => 0xFFFF00,
        'yellowgreen' => 0x9ACD32,
        'transparent' => 0x000000,
    ];

    public static function parseHexColor(string $value): int
    {
        $value = trim($value);
        // CSS named colors
        $lower = strtolower($value);
        if (isset(self::NAMED_COLORS[$lower])) {
            // NAMED_COLORS 以规范 RGB 存储，转为 Px 内部 BGR。
            return self::rgbToBgr(self::NAMED_COLORS[$lower]);
        }
        if (str_starts_with($value, 'linear-gradient')) {
            if (preg_match('/#[0-9a-fA-F]{3,8}|rgba?\s*\([^)]+\)/', $value, $m)) {
                return self::parseHexColor($m[0]);
            }
            return 0;
        }
        if (preg_match('/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)/i', $value, $m)) {
            $r = (int)$m[1];
            $g = (int)$m[2];
            $b = (int)$m[3];
            $bgr = ($b << 16) | ($g << 8) | $r;
            // C3a.2：第 4 捕获组 alpha（CSS Color 4）→ 打包入高 8 位。
            // 约定：alpha ∈ [0,1) 存字节 round(a*255)（<255）；alpha>=1/缺省
            // → 高字节 0（opaque，与现有不透明色向后兼容）。
            if (isset($m[4]) && $m[4] !== '') {
                $a = (float)$m[4];
                if ($a < 1.0) {
                    $aByte = (int)round(max(0.0, $a) * 255.0);
                    return ($aByte << 24) | $bgr;
                }
            }
            return $bgr;
        }
        return self::hexToBgr($value);
    }

    public static function parsePixels(string $value): CssLength
    {
        return CssLength::fromString($value);
    }

    /**
     * Legacy: parse raw CSS length to int (for backward compat in style array).
     * Percentage values return the numeric part (for detectability).
     */
    public static function parsePixelsRaw(string $value): int
    {
        if (preg_match('/^(-?\d+(\.\d+)?)/', $value, $m)) {
            $num = (float)$m[1];
            $lower = strtolower($value);
            if (str_contains($lower, 'em')) {
                return (int)($num * 16.0);
            }
            if (str_contains($lower, '%')) {
                return (int)$num;
            }
            return (int)$num;
        }
        return 0;
    }

    public static function parseFlex(string $value): string
    {
        // 返回完整的 flex 简写值（如 "1 1 30%"），供 FlexLayoutStrategy 解析
        // 注意：不要截断为第一个数字，否则会丢失 flex-basis 和 flex-shrink 信息
        return trim($value);
    }

    public static function parseFlexValue(string $flex): array
    {
        $cf = CssFlex::fromString($flex);
        return [
            'grow'   => $cf->grow,
            'shrink' => $cf->shrink,
            'basis'  => $cf->basis,  // CssLength 类型
        ];
    }

    public static function parseFontWeight(string $value): int
    {
        $v = trim(strtolower($value));
        if ($v === 'bold' || $v === 'bolder') return 700;
        if ($v === 'normal') return 400;
        if ($v === 'lighter') return 300;
        if (is_numeric($v)) {
            $num = (int)$v;
            if ($num >= 100 && $num <= 900) return $num;
        }
        return 400;
    }

    public static function parseTextAlign(string $value): string
    {
        $v = trim(strtolower($value));
        // CSS Text Module Level 3 §7.1: start | end | left | right | center | justify | match-parent | justify-all
        // Initial value: start (depends on writing direction, LTR→left, RTL→right)
        if (in_array($v, ['left', 'right', 'center', 'justify', 'start', 'end', 'match-parent', 'justify-all'], true)) {
            return $v;
        }
        return 'start';
    }

    public static function parseBorder(string $value): string
    {
        $v = trim($value);
        if ($v === '' || $v === 'none') return '';
        // C3a.3：先整串提色（rgba(...) 含内部逗号，空格分割会碎化；
        // #RRGGBBAA 亦需），用 parseHexColor 保 alpha，而非 hexToBgr（不含 rgba）。
        $colorInt = 0;
        $vNoColor = $v;
        if (preg_match('/rgba?\s*\([^)]*\)/i', $v, $cm)) {
            $colorInt = self::parseHexColor($cm[0]);
            $vNoColor = str_replace($cm[0], '', $v);
        } elseif (preg_match('/#[0-9a-fA-F]{3,8}\b/', $v, $cm)) {
            $colorInt = self::parseHexColor($cm[0]);
            $vNoColor = str_replace($cm[0], '', $v);
        }
        $parts = preg_split('/\s+/', trim($vNoColor));
        $width = 0;
        $style = 'solid';
        $styleKeywords = ['none','hidden','dotted','dashed','solid','double','groove','ridge','inset','outset'];
        foreach ($parts as $p) {
            if ($p === '') continue;
            if (preg_match('/^\d+/', $p)) {
                $width = (int)$p;
            } elseif (in_array(strtolower($p), $styleKeywords, true)) {
                $style = strtolower($p);
            } elseif (isset(self::NAMED_COLORS[strtolower($p)])) {
                $colorInt = self::rgbToBgr(self::NAMED_COLORS[strtolower($p)]);
            }
        }
        return $width . '|' . $colorInt . '|' . $style;
    }

   public static function parseBoxShadow(string $value): string
    {
        $v = trim($value);
        if ($v === '' || $v === 'none') return '';

        $color = '#000000';
        $alpha = 0.5;
        $isInset = false;
        $numericStr = $v;

        // Strip 'inset' keyword first (must be the first word if present)
        if (str_starts_with(strtolower($numericStr), 'inset')) {
            $isInset = true;
            $numericStr = trim(substr($numericStr, 5)); // Remove 'inset'
        }

        if (preg_match('/rgba?\s*\([^)]+\)/i', $numericStr, $m)) {
            // 提取 alpha（rgba 第四参数）
            if (preg_match('/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,?\s*([\d.]+)?/i', $m[0], $cm)) {
                $r = (int)$cm[1]; $g = (int)$cm[2]; $b = (int)$cm[3];
                $color = sprintf('#%02X%02X%02X', $r, $g, $b);
                if (isset($cm[4]) && $cm[4] !== '') {
                    $alpha = (float)$cm[4];
                }
            }
            $numericStr = trim(preg_replace('/' . preg_quote(explode('(', $m[0])[0], '/') . '\([^)]+\)\s*,?\s*/', '', $numericStr));
        } elseif (preg_match('/#([0-9a-fA-F]{3,8})\b/', $numericStr, $m)) {
            $color = $m[0];
            $numericStr = trim(str_replace($m[0], '', $numericStr));
        }

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
        return ($isInset ? 'inset|' : '') . $h . '|' . $vOff . '|' . $blur . '|' . $spread . '|' . $color . '|' . $alpha;
    }

    /**
     * 将 parseBoxShadow 的 | 分隔字符串解析为偏移量数组。
     *
     * @return array{h:int, v:int, blur:int, spread:int, color:int, alpha:float, inset:bool}
     */
    public static function parseBoxShadowOffsets(string $boxShadow): array
    {
        $parts = explode('|', $boxShadow);
        $offset = 0;
        $isInset = false;
        if (isset($parts[0]) && $parts[0] === 'inset') {
            $isInset = true;
            $offset = 1;
        }
        return [
            'h'      => (int)($parts[0 + $offset] ?? 0),
            'v'      => (int)($parts[1 + $offset] ?? 0),
            'blur'   => (int)($parts[2 + $offset] ?? 0),
            'spread' => (int)($parts[3 + $offset] ?? 0),
            'color'  => self::hexToBgr($parts[4 + $offset] ?? '#000000'),
            'alpha'  => (float)($parts[5 + $offset] ?? 0.5),
            'inset'  => $isInset,
        ];
    }

    /**
     * Parse linear-gradient() CSS value into structured array.",
     *
     * Supports: linear-gradient(angle, color1, color2, ...)
     * Currently simplified to 2-color gradient support.
     *
     * @return array{angle:int, colors:array, stops:array}|null
     */
    public static function parseLinearGradient(string $value): ?array
    {
        $v = trim($value);
        if (!str_starts_with($v, 'linear-gradient(')) {
            return null;
        }
        // Extract content inside parentheses
        if (!preg_match('/^linear-gradient\s*\(([^)]+)\)$/i', $v, $m)) {
            return null;
        }
        $content = trim($m[1]);
        if ($content === '') return null;

        // Extract angle (e.g., "135deg", "45deg", "to bottom")
        $angle = 180; // default: to bottom
        $rest = $content;
        if (preg_match('/^(\d+(?:\.\d+)?)deg\s*,?\s*/i', $content, $am)) {
            $angle = (int)$am[1];
            $rest = trim(substr($content, strlen($am[0])));
        } elseif (preg_match('/^to\s+(top|bottom|left|right|top\s+left|top\s+right|bottom\s+left|bottom\s+right)\s*,?\s*/i', $content, $tm)) {
            $dir = strtolower(trim($tm[1]));
            $dirMap = [
                'bottom' => 0, 'top' => 180, 'right' => 270, 'left' => 90,
                'top right' => 225, 'top left' => 135,
                'bottom right' => 315, 'bottom left' => 45,
            ];
            $angle = $dirMap[$dir] ?? 180;
            $rest = trim(substr($content, strlen($tm[0])));
        }

        // Split remaining by comma to get color stops
        $parts = explode(',', $rest);
        $colors = [];
        $stops = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            // Extract optional percentage stop
            $stop = null;
            if (preg_match('/\s+(\d+(?:\.\d+)?)%\s*$/', $part, $sm)) {
                $stop = (float)$sm[1];
                $part = trim(substr($part, 0, -(strlen($sm[0]))));
            }
            $colorValue = self::parseHexColor($part);
            $colors[] = $colorValue;
            $stops[] = $stop;
        }

        if (count($colors) < 2) return null;

        return [
            'angle' => $angle,
            'colors' => $colors,
            'stops' => $stops,
        ];
    }

    public static function parseOpacity(string $value): float
    {
        $v = trim($value);
        if (str_ends_with($v, '%')) {
            return ((float)substr($v, 0, -1)) / 100.0;
        }
        return min(1.0, max(0.0, (float)$v));
    }

    public static function parseIdent(string $value): CssKeyword
    {
        return new CssKeyword($value);
    }

    /**
     * aspect-ratio 解析（CSS-Sizing-4 §5）：'a/b' 比例语法 → a÷b，
     * 单数 'r' → r。此前误用 parsePixels，'16/9' 被截成 16
     *（case-051 h=200/16.67≈12 vs B 112.5 实锤）。
     */
    public static function parseAspectRatio(string $value): float
    {
        $v = trim(strtolower($value));
        if ($v === '' || $v === 'auto') return 0.0;
        $slash = strpos($v, '/');
        if ($slash !== false) {
            $num = (float)trim(substr($v, 0, $slash));
            $den = (float)trim(substr($v, $slash + 1));
            return $den > 0 ? $num / $den : 0.0;
        }
        return max(0.0, (float)$v);
    }

    /** Legacy: return raw string for backward compat */
    public static function parseIdentRaw(string $value): string
    {
        return trim(strtolower($value));
    }

    /**
     * Parse line-height CSS value.
     * Returns a string representation:
     *   - empty string for 'normal'
     *   - multiplier for unitless/em/percentage (e.g. '1.5')
     *   - px string for absolute values
     *   - 'value|unit' for rem/vw/vh/etc (resolved at runtime)
     */
    public static function parseLineHeight(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if ($value === 'normal') return '';
        if (preg_match('/^(\d+(\.\d+)?)$/', $value, $m)) {
            return $m[1];
        }
        // px 保留单位后缀：与无单位倍数形态区分（CSS §10.8.1 number vs length
        // 语义不同；此前 '24px'→'24' 与 'line-height:24' 无法区分，单位语义丢失，
        // ComputedStyle 端 safeInt 再把 '1.5' 截成 1px —— 双重数据要素破坏）。
        if (str_ends_with($value, 'px')) {
            return ((string)(int)$value) . 'px';
        }
        if (str_ends_with($value, 'em')) {
            return (string)(float)$value;
        }
        if (str_ends_with($value, '%')) {
            return (string)((float)$value / 100.0);
        }
        if (preg_match('/^(\d+(\.\d+)?)\s*(rem|vw|vh|vmin|vmax|ch|ex)$/i', $value, $m)) {
            return $m[1] . '|' . strtolower($m[3]);
        }
        $unitMap = [
            'pt' => 96.0 / 72.0,
            'pc' => 96.0 / 6.0,
            'in' => 96.0,
            'cm' => 96.0 / 2.54,
            'mm' => 96.0 / 25.4,
        ];
        foreach ($unitMap as $unit => $pxPerUnit) {
            if (preg_match('/^(\d+(\.\d+)?)\s*' . $unit . '$/i', $value, $m)) {
                return (string)(int)round((float)$m[1] * $pxPerUnit);
            }
        }
        return $value;
    }

    public static function parseBackgroundImage(string $value): string
    {
        $v = trim($value);
        if (preg_match('/^url\(\s*["\']?([^"\'\)]+)["\']?\s*\)$/', $v, $m)) {
            return trim($m[1]);
        }
        return $v;
    }

    public static function parseGridTemplateValue(string $val): array
    {
        $val = trim($val);
        if (preg_match('/^repeat\(\s*(\d+)\s*,\s*(\d+(?:\.\d+)?)(px|fr|%|)\s*\)$/i', $val, $m)) {
            $unit = strtolower($m[3] ?? '');
            $size = (float)$m[2];
            return ['repeat' => true, 'count' => (int)$m[1], 'size' => ($unit === 'fr' || $unit === '%') ? $size : (int)$size, 'unit' => $unit];
        }
        if (preg_match('/^repeat\(\s*(auto-fill|auto-fit)\s*,\s*minmax\(\s*(\d+(?:\.\d+)?)(px|%|)\s*,\s*(\d+(?:\.\d+)?)(px|fr|%|)\s*\)\s*\)$/i', $val, $m)) {
            $mode = strtolower($m[1]);
            $min = (float)$m[2];
            $minUnit = strtolower($m[3] ?? '');
            return [
                'repeat' => $mode,
                'min' => ($minUnit === '%' || $minUnit === '') ? (int)$min : (int)$min,
                'minUnit' => $minUnit,
                'max' => (float)$m[4],
                'maxTrack' => strtolower($m[5] ?? ''),
            ];
        }
        if (preg_match('/^repeat\(\s*(\d+)\s*,\s*(.+)\)$/i', $val, $m)) {
            return ['repeat' => true, 'count' => (int)$m[1], 'track' => trim($m[2])];
        }
        if (strtolower($val) === 'auto') {
            return ['type' => 'auto'];
        }
        $parts = preg_split('/\s+/', $val);
        $sizes = [];
        foreach ($parts as $part) {
            if ($part !== '') $sizes[] = $part;
        }
        if (count($sizes) > 0) {
            return ['type' => 'explicit', 'list' => $sizes, 'sizes' => $sizes];
        }
        return ['type' => 'none'];
    }

    public static function parseTransform(string $value): array
    {
        $result = ['translateX' => 0, 'translateY' => 0, 'rotate' => 0];
        $value = trim($value);
        if ($value === '') return $result;

        if (preg_match('/rotate\s*\(\s*([\d.-]+)\s*deg\s*\)/i', $value, $m)) {
            $result['rotate'] = (int)$m[1];
        }
        if (preg_match('/translate\s*\(\s*([^,)]+)\s*(?:,\s*([^,)]+))?\s*\)/i', $value, $m)) {
            $result['translateX'] = self::parsePixels($m[1]);
            if (isset($m[2]) && $m[2] !== '') {
                $result['translateY'] = self::parsePixels($m[2]);
            }
            return $result;
        }
        if (preg_match('/translateX\s*\(\s*([^)]+)\s*\)/i', $value, $m)) {
            $result['translateX'] = self::parsePixels($m[1]);
        }
        if (preg_match('/translateY\s*\(\s*([^)]+)\s*\)/i', $value, $m)) {
            $result['translateY'] = self::parsePixels($m[1]);
        }
        return $result;
    }

    /**
     * 解析 transform 中的 translate 分量为已用像素值（CSS Transforms §6）。
     * 百分比参照自身 border box 尺寸（对标 Blink TransformOperations::Apply(border_box_size)，
     * 必须在 used size 已知后调用）。parseTransform() 把 % 误当 px，仅适用于纯 px 动画链，
     * 布局侧一律走本函数。
     *
     * @return array{0:int,1:int} [tx, ty]
     */
    public static function resolveTranslate(string $value, int $selfW, int $selfH): array
    {
        $tx = 0;
        $ty = 0;
        $value = trim($value);
        if ($value === '' || $value === 'none') return [0, 0];
        if (preg_match('/translate\s*\(\s*([^,)]+)\s*(?:,\s*([^,)]+))?\s*\)/i', $value, $m)) {
            $tx = self::resolveTranslateComponent($m[1], $selfW);
            if (isset($m[2]) && $m[2] !== '') $ty = self::resolveTranslateComponent($m[2], $selfH);
            return [$tx, $ty];
        }
        if (preg_match('/translateX\s*\(\s*([^)]+)\s*\)/i', $value, $m)) {
            $tx = self::resolveTranslateComponent($m[1], $selfW);
        }
        if (preg_match('/translateY\s*\(\s*([^)]+)\s*\)/i', $value, $m)) {
            $ty = self::resolveTranslateComponent($m[1], $selfH);
        }
        return [$tx, $ty];
    }

    /** 单分量：% 基于自身尺寸，否则取数值（'30px' → 30，(float) 忽略尾部单位）。AOT 兼容：不用闭包。 */
    private static function resolveTranslateComponent(string $raw, int $base): int
    {
        $raw = trim($raw);
        if ($raw === '') return 0;
        if (str_ends_with($raw, '%')) {
            return (int)round((float)substr($raw, 0, -1) / 100.0 * $base);
        }
        return (int)round((float)$raw);
    }

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

    public static function parseTransition(string $value): array
    {
        $result = [];
        $value = trim($value);
        if ($value === '' || $value === 'none') return $result;

        $transitions = preg_split('/\s*,\s*/', $value);
        foreach ($transitions as $transition) {
            $transition = trim($transition);
            if ($transition === '') continue;
            $parts = preg_split('/\s+/', $transition);
            $parsed = [
                'property' => 'all',
                'duration' => 300,
                'timing'   => 'ease',
                'delay'    => 0,
            ];
            foreach ($parts as $part) {
                if (preg_match('/^(\d+(?:\.\d+)?)(m?s)$/', $part, $m)) {
                    $time = (float)$m[1];
                    if ($m[2] === 's') $time *= 1000;
                    if ($parsed['duration'] === 300) {
                        $parsed['duration'] = (int)$time;
                    } else {
                        $parsed['delay'] = (int)$time;
                    }
                } elseif (in_array(strtolower($part), ['linear', 'ease', 'ease-in', 'ease-out', 'ease-in-out'])) {
                    $parsed['timing'] = strtolower($part);
                } elseif (strpos($part, '(') === false) {
                    $parsed['property'] = strtolower($part);
                }
            }
            $result[] = $parsed;
        }
        return $result;
    }

    public static function parseAnimation(string $value): array
    {
        $value = trim($value);
        if ($value === '' || $value === 'none') {
            return [
                'name' => '', 'duration' => 0, 'timing' => 'ease', 'delay' => 0,
                'count' => 1, 'direction' => 'normal', 'fillMode' => 'none', 'playState' => 'running',
            ];
        }
        $parts = preg_split('/\s+/', $value);
        $parsed = [
            'name' => '', 'duration' => 0, 'timing' => 'ease', 'delay' => 0,
            'count' => 1, 'direction' => 'normal', 'fillMode' => 'none', 'playState' => 'running',
        ];
        foreach ($parts as $part) {
            if (preg_match('/^(\d+(?:\.\d+)?)(m?s)$/', $part, $m)) {
                $time = (float)$m[1];
                if ($m[2] === 's') $time *= 1000;
                if ($parsed['duration'] === 0) {
                    $parsed['duration'] = (int)$time;
                } else {
                    $parsed['delay'] = (int)$time;
                }
            } elseif (in_array(strtolower($part), ['linear', 'ease', 'ease-in', 'ease-out', 'ease-in-out'])) {
                $parsed['timing'] = strtolower($part);
            } elseif ($part === 'infinite') {
                $parsed['count'] = -1;
            } elseif (ctype_digit($part)) {
                $parsed['count'] = (int)$part;
            } elseif (in_array(strtolower($part), ['normal', 'reverse', 'alternate', 'alternate-reverse'])) {
                $parsed['direction'] = strtolower($part);
            } elseif (in_array(strtolower($part), ['none', 'forwards', 'backwards', 'both'])) {
                $parsed['fillMode'] = strtolower($part);
            } elseif (in_array(strtolower($part), ['running', 'paused'])) {
                $parsed['playState'] = strtolower($part);
            } else {
                $parsed['name'] = $part;
            }
        }
        return $parsed;
    }

    /**
     * RGB → BGR 格式转换。
     */
    public static function rgbToBgr(int $rgb): int
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return ($b << 16) | ($g << 8) | $r;
    }

    /**
     * Resolve CSS var() references in a value string.
     *
     * Replaces var(--name, fallback) with the resolved value from $variables map.
     * Supports nested var() calls via iterative resolution (up to 10 levels deep).
     *
     * @param string $value     CSS property value potentially containing var()
     * @param array  $variables Map of --name => raw value
     * @return string Resolved value with all var() replaced
     */
    public static function resolveCSSVariables(string $value, array $variables): string
    {
        $maxIterations = 10;
        for ($i = 0; $i < $maxIterations; $i++) {
            $resolved = preg_replace_callback(
                '/var\(\s*--([a-zA-Z0-9_-]+)\s*(?:,\s*((?:[^()]|\([^()]*\))*)\s*)?\)/',
                function(array $m) use ($variables): string {
                    $varName = '--' . $m[1];
                    if (array_key_exists($varName, $variables)) {
                        return $variables[$varName];
                    }
                    return isset($m[2]) ? trim($m[2]) : '';
                },
                $value
            );
            if ($resolved === $value) {
                break;
            }
            $value = $resolved;
        }
        return $value;
    }

    /**
     * Parse a CSS length value and detect relative units.
     *
     * CSS Values and Units Module Level 3 §5:
     *   em  → relative to parent element's font-size
     *   rem → relative to root element's font-size
     *   vw  → 1% of viewport width
     *   vh  → 1% of viewport height
     *   vmin → min(vw, vh)
     *   vmax → max(vw, vh)
     *
     * @param string $value CSS length value (e.g., "1.5em", "100vw", "2rem")
     * @return array ['value' => float, 'unit' => 'px'|'em'|'rem'|'vw'|'vh'|'vmin'|'vmax']
     */
    public static function parseRelativeValue(string $value): array
    {
        $value = trim($value);
        $lower = strtolower($value);

        // Try longer suffixes first to avoid partial matches (vmin vs vm, rem vs re)
        $unitPatterns = [
            'vmin' => '/^([\d.]+)\s*vmin$/',
            'vmax' => '/^([\d.]+)\s*vmax$/',
            'rem'  => '/^([\d.]+)\s*rem$/',
            'em'   => '/^([\d.]+)\s*em$/',
            'vw'   => '/^([\d.]+)\s*vw$/',
            'vh'   => '/^([\d.]+)\s*vh$/',
        ];

        foreach ($unitPatterns as $unit => $pattern) {
            if (preg_match($pattern, $lower, $m)) {
                return ['value' => (float)$m[1], 'unit' => $unit];
            }
        }

        // Default: treat as px
        $numericVal = (float) preg_replace('/[^-\d.]/', '', $value);
        return ['value' => $numericVal, 'unit' => 'px'];
    }

    /**
     * Resolve a relative CSS length to an absolute pixel value.
     *
     * @param float  $value      The numeric part of the length
     * @param string $unit       The unit (em, rem, vw, vh, vmin, vmax, px)
     * @param int    $parentFontSize Parent element font-size in px (for em)
     * @param int    $rootFontSize   Root element font-size in px (for rem)
     * @param int    $viewportWidth  Viewport width in px (for vw)
     * @param int    $viewportHeight Viewport height in px (for vh)
     * @return int  Resolved pixel value
     */
    public static function resolveRelativeLength(float $value, string $unit, int $parentFontSize = 16, int $rootFontSize = 16, int $viewportWidth = 1920, int $viewportHeight = 1080): int
    {
        return match ($unit) {
            'em'   => (int)round($value * $parentFontSize),
            'rem'  => (int)round($value * $rootFontSize),
            'vw'   => (int)round($value * $viewportWidth / 100.0),
            'vh'   => (int)round($value * $viewportHeight / 100.0),
            'vmin' => (int)round($value * min($viewportWidth, $viewportHeight) / 100.0),
            'vmax' => (int)round($value * max($viewportWidth, $viewportHeight) / 100.0),
            default => (int)round($value),
        };
    }

    /**
     * Parse and evaluate a calc() expression.
     *
     * CSS Values and Units Module Level 3 §9:
     *   calc() supports +, -, *, / with mixed units where possible.
     *
     * Supported syntax:
     *   calc(100% - 40px)     → percentage + pixel offset
     *   calc(50% + 20px)     → percentage + pixel offset
     *   calc(100vw - 200px)  → viewport-relative + pixel
     *   calc(2 * 16px)       → simple multiplication
     *   calc(100px / 2)      → simple division
     *
     * Returns an array with the parsed components:
     *   ['percent' => float|null, 'px' => int, 'vw' => float|null, 'vh' => float|null]
     *   or the string value if not a recognizable calc pattern.
     *
     * @param string $value Raw CSS value potentially containing calc()
     * @return array|string Parsed components or original string if not calc
     */
    public static function parseCalcExpression(string $value): array|string
    {
        $value = trim($value);
        if (!str_starts_with(strtolower($value), 'calc(')) {
            return $value;
        }

        // Extract inner expression: calc( ... )
        if (!preg_match('/^calc\s*\(\s*(.+)\)$/i', $value, $m)) {
            return $value;
        }
        $expr = trim($m[1]);

        // C3b.2 根因治本（对齐 Blink / CSS Values L3 §9）：旧实现为硬编码
        // 正则模式（仅 ~4 种固定 2 操作数形式），不支持多操作数、
        // 优先级、括号、数在单位后（`16px * 2`）——非表达式树抽象。
        // 改为真实递归下降求值器：tokenize + 优先级（* / 高于 + -）
        // + 括号 + 任意操作数。值建模为线性组合 {num, px, pct}。
        $tokens = self::tokenizeCalc($expr);
        if ($tokens === null) return $value; // 不支持字符/单位（如 vw/em）→ 原串回落
        $pos = 0;
        $r = self::parseCalcSum($tokens, $pos);
        if ($r === null || $pos !== count($tokens)) return $value; // 解析错误/尾部残留
        // 映射回消费契约 {percent, px}；无单位的纯数宽容作 px。
        $pxFinal = $r['px'] + $r['num'];
        if (abs($r['pct']) > 1e-9) {
            return ['percent' => $r['pct'], 'px' => (int)round($pxFinal)];
        }
        return ['percent' => null, 'px' => (int)round($pxFinal)];
    }

    /**
     * calc() 词法分析：拆为 number(可带 px/% 单位)、运算符、括号 token。
     * 遇不支持单位（vw/vh/em 等，下游仅消费 px/%）或非法字符返 null。
     * @return array|null token 数组，或 null（不可解析 → 调用方回落原串）
     */
    private static function tokenizeCalc(string $expr): ?array
    {
        $tokens = [];
        $i = 0;
        $n = strlen($expr);
        while ($i < $n) {
            $c = $expr[$i];
            if (ctype_space($c)) { $i++; continue; }
            if ($c === '+' || $c === '-' || $c === '*' || $c === '/' || $c === '(' || $c === ')') {
                $tokens[] = ['t' => $c];
                $i++;
                continue;
            }
            if (ctype_digit($c) || $c === '.') {
                $j = $i;
                while ($j < $n && (ctype_digit($expr[$j]) || $expr[$j] === '.')) $j++;
                $numStr = substr($expr, $i, $j - $i);
                $unit = '';
                if ($j < $n && $expr[$j] === '%') {
                    $unit = '%';
                    $j++;
                } elseif ($j < $n && ctype_alpha($expr[$j])) {
                    $k = $j;
                    while ($k < $n && ctype_alpha($expr[$k])) $k++;
                    $u = strtolower(substr($expr, $j, $k - $j));
                    if ($u === 'px') { $unit = 'px'; } else { return null; } // 不支持单位
                    $j = $k;
                }
                $tokens[] = ['t' => 'num', 'num' => (float)$numStr, 'unit' => $unit];
                $i = $j;
                continue;
            }
            return null; // 非法字符
        }
        return $tokens;
    }

    /** 和/差：term (('+'|'-') term)*  —— 最低优先级 */
    private static function parseCalcSum(array $tokens, int &$pos): ?array
    {
        $left = self::parseCalcProduct($tokens, $pos);
        if ($left === null) return null;
        while ($pos < count($tokens) && ($tokens[$pos]['t'] === '+' || $tokens[$pos]['t'] === '-')) {
            $op = $tokens[$pos]['t'];
            $pos++;
            $right = self::parseCalcProduct($tokens, $pos);
            if ($right === null) return null;
            $sign = ($op === '-') ? -1.0 : 1.0;
            $left = [
                'num' => $left['num'] + $sign * $right['num'],
                'px'  => $left['px'] + $sign * $right['px'],
                'pct' => $left['pct'] + $sign * $right['pct'],
            ];
        }
        return $left;
    }

    /** 积/商：factor (('*'|'/') factor)*  —— 高优先级；length*length / 除以 length 为非法 */
    private static function parseCalcProduct(array $tokens, int &$pos): ?array
    {
        $left = self::parseCalcFactor($tokens, $pos);
        if ($left === null) return null;
        while ($pos < count($tokens) && ($tokens[$pos]['t'] === '*' || $tokens[$pos]['t'] === '/')) {
            $op = $tokens[$pos]['t'];
            $pos++;
            $right = self::parseCalcFactor($tokens, $pos);
            if ($right === null) return null;
            $aPure = (abs($left['px']) < 1e-9 && abs($left['pct']) < 1e-9);
            $bPure = (abs($right['px']) < 1e-9 && abs($right['pct']) < 1e-9);
            if ($op === '*') {
                if ($bPure)      $left = self::scaleCalc($left, $right['num']);
                elseif ($aPure)  $left = self::scaleCalc($right, $left['num']);
                else             return null; // length * length 非法
            } else { // '/'
                if (!$bPure || abs($right['num']) < 1e-9) return null; // 除以 length 或 0 非法
                $left = self::scaleCalc($left, 1.0 / $right['num']);
            }
        }
        return $left;
    }

    /** 因子：number | dimension | '(' sum ')' */
    private static function parseCalcFactor(array $tokens, int &$pos): ?array
    {
        if ($pos >= count($tokens)) return null;
        $tok = $tokens[$pos];
        if ($tok['t'] === '(') {
            $pos++;
            $v = self::parseCalcSum($tokens, $pos);
            if ($v === null) return null;
            if ($pos >= count($tokens) || $tokens[$pos]['t'] !== ')') return null;
            $pos++;
            return $v;
        }
        if ($tok['t'] === 'num') {
            $pos++;
            $u = $tok['unit'];
            return [
                'num' => ($u === '' ? $tok['num'] : 0.0),
                'px'  => ($u === 'px' ? $tok['num'] : 0.0),
                'pct' => ($u === '%' ? $tok['num'] : 0.0),
            ];
        }
        return null; // 操作符/右括号开头 → 解析错误
    }

    /** 线性缩放（乘/除数字） */
    private static function scaleCalc(array $v, float $k): array
    {
        return ['num' => $v['num'] * $k, 'px' => $v['px'] * $k, 'pct' => $v['pct'] * $k];
    }

    /**
     * Evaluate a calc expression result into a single pixel value.
     *
     * @param array|string $calcResult Result from parseCalcExpression()
     * @param int $containerSize Container size for percentage resolution
     * @return int Pixel value
     */
    public static function resolveCalcToPx(array|string $calcResult, int $containerSize = 0): int
    {
        if (is_string($calcResult)) {
            return (int)preg_replace('/[^0-9-]/', '', $calcResult);
        }
        $px = $calcResult['px'] ?? 0;
        $pct = $calcResult['percent'] ?? null;
        if ($pct !== null && $containerSize > 0) {
            $px += (int)round($pct * $containerSize / 100.0);
        }
        return $px;
    }
}
