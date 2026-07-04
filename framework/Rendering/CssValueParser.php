<?php

namespace Px\Rendering;

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
        // CSS Color Module Level 4 §7 — Named Colors
        'white'      => 0xFFFFFF,
        'black'      => 0x000000,
        'red'        => 0x0000FF,
        'green'      => 0x008000,
        'blue'       => 0xFF0000,
        'yellow'     => 0x00FFFF,
        'cyan'       => 0xFFFF00,
        'magenta'    => 0xFF00FF,
        'gray'       => 0x808080,
        'grey'       => 0x808080,
        'orange'     => 0x00A5FF,
        'purple'     => 0x800080,
        'pink'       => 0xC0C0FF,
        'brown'      => 0x2A2AA5,
        'navy'       => 0x800000,
        'teal'       => 0x808000,
        'silver'     => 0xC0C0C0,
        'gold'       => 0x00D7FF,
        'aqua'       => 0xFFFF00,
        'lime'       => 0x00FF00,
        'maroon'     => 0x000080,
        'olive'      => 0x008080,
        'indigo'     => 0x82004B,
        'violet'     => 0xEE82EE,
        'coral'      => 0x507FFF,
        'salmon'     => 0x7280FA,
        'tomato'     => 0x4763FF,
        'skyblue'    => 0xEBCE87,
        'transparent'=> 0x00000000,
    ];

    public static function parseHexColor(string $value): int
    {
        $value = trim($value);
        // CSS named colors
        $lower = strtolower($value);
        if (isset(self::NAMED_COLORS[$lower])) {
            return self::NAMED_COLORS[$lower];
        }
        if (str_starts_with($value, 'linear-gradient')) {
            if (preg_match('/#[0-9a-fA-F]{3,8}|rgba?\s*\([^)]+\)/', $value, $m)) {
                return self::parseHexColor($m[0]);
            }
            return 0;
        }
        if (preg_match('/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $value, $m)) {
            $r = (int)$m[1];
            $g = (int)$m[2];
            $b = (int)$m[3];
            return ($b << 16) | ($g << 8) | $r;
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
        $parts = preg_split('/\s+/', $v);
        $width = 0;
        $color = '#000000';
        $style = 'solid';
        $styleKeywords = ['none','hidden','dotted','dashed','solid','double','groove','ridge','inset','outset'];
        foreach ($parts as $p) {
            if (preg_match('/^\d+/', $p)) {
                $width = (int)$p;
            } elseif (preg_match('/^#/', $p)) {
                $color = $p;
            } elseif (in_array(strtolower($p), $styleKeywords, true)) {
                $style = strtolower($p);
            }
        }
        return $width . '|' . self::hexToBgr($color) . '|' . $style;
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
        if (str_ends_with($value, 'px')) {
            return (string)(int)$value;
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

        // Pattern 1: calc(<percent>% [+-] <px>px)
        if (preg_match('/^(\d+(?:\.\d+)?)%\s*([+\-])\s*(\d+(?:\.\d+)?)px$/i', $expr, $m)) {
            $pct = (float)$m[1];
            $offset = (float)$m[3];
            if ($m[2] === '-') $offset = -$offset;
            return ['percent' => $pct, 'px' => (int)$offset];
        }

        // Pattern 2: calc(<px>px [+-] <percent>%)
        if (preg_match('/^(\d+(?:\.\d+)?)px\s*([+\-])\s*(\d+(?:\.\d+)?)%$/i', $expr, $m)) {
            $pct = (float)$m[3];
            $offset = (float)$m[1];
            if ($m[2] === '-') $pct = -$pct;
            return ['percent' => $pct, 'px' => (int)$offset];
        }

        // Pattern 3: calc(<percent>% [+-] <percent>%)
        if (preg_match('/^(\d+(?:\.\d+)?)%\s*([+\-])\s*(\d+(?:\.\d+)?)%$/i', $expr, $m)) {
            $pct1 = (float)$m[1];
            $pct2 = (float)$m[3];
            if ($m[2] === '-') $pct2 = -$pct2;
            return ['percent' => $pct1 + $pct2, 'px' => 0];
        }

        // Pattern 4: calc(<px>px [+-] <px>px)
        if (preg_match('/^(\d+(?:\.\d+)?)px\s*([+\-])\s*(\d+(?:\.\d+)?)px$/i', $expr, $m)) {
            $px1 = (float)$m[1];
            $px2 = (float)$m[3];
            if ($m[2] === '-') $px2 = -$px2;
            return ['percent' => null, 'px' => (int)($px1 + $px2)];
        }

        // Pattern 5: calc(<value> * <number>)
        if (preg_match('/^(\d+(?:\.\d+)?)(px|)%\s*\*\s*(\d+(?:\.\d+)?)$/i', $expr, $m)) {
            $val = (float)$m[1];
            $mult = (float)$m[3];
            $unit = $m[2];
            if ($unit === '') {
                // Unitless * number → px
                return ['percent' => null, 'px' => (int)($val * $mult)];
            }
            return ['percent' => null, 'px' => (int)($val * $mult)];
        }

        // Pattern 6: calc(<number> * <value>)
        if (preg_match('/^(\d+(?:\.\d+)?)\s*\*\s*(\d+(?:\.\d+)?)(px|)%?$/i', $expr, $m)) {
            $val = (float)$m[1];
            $mult = (float)$m[2];
            return ['percent' => null, 'px' => (int)($val * $mult)];
        }

        // Pattern 7: calc(<px>px / <number>)
        if (preg_match('/^(\d+(?:\.\d+)?)px\s*\/\s*(\d+(?:\.\d+)?)$/i', $expr, $m)) {
            $px = (float)$m[1];
            $div = (float)$m[2];
            if ($div === 0.0) return ['percent' => null, 'px' => 0];
            return ['percent' => null, 'px' => (int)($px / $div)];
        }

        // Unrecognized calc pattern — return as string
        return $value;
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
