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

    public static function parseHexColor(string $value): int
    {
        $value = trim($value);
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

    public static function parsePixels(string $value): int
    {
        return (int) preg_replace('/[^-0-9]/', '', $value);
    }

    public static function parseFlex(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d+(?:\.\d+)?)/', $value, $m)) {
            return $m[1];
        }
        return $value;
    }

    public static function parseFlexValue(string $flex): array
    {
        $flex = trim($flex);
        if ($flex === '') {
            return ['grow' => 0.0, 'shrink' => 1.0, 'basis' => 0];
        }
        $lower = strtolower($flex);
        if ($lower === 'auto') {
            return ['grow' => 1.0, 'shrink' => 1.0, 'basis' => 'auto'];
        }
        if ($lower === 'none') {
            return ['grow' => 0.0, 'shrink' => 0.0, 'basis' => 'auto'];
        }
        if ($lower === 'initial') {
            return ['grow' => 0.0, 'shrink' => 1.0, 'basis' => 'auto'];
        }
        if ($lower === 'content') {
            return ['grow' => 0.0, 'shrink' => 1.0, 'basis' => 'content'];
        }
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

    public static function parseFontWeight(string $value): int
    {
        $v = trim(strtolower($value));
        if ($v === 'bold' || (int)$v >= 600) {
            return 1;
        }
        return 0;
    }

    public static function parseTextAlign(string $value): string
    {
        $v = trim(strtolower($value));
        if (in_array($v, ['left', 'right', 'center'], true)) {
            return $v;
        }
        return 'left';
    }

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

    public static function parseBoxShadow(string $value): string
    {
        $v = trim($value);
        if ($v === '' || $v === 'none') return '';

        $color = '#000000';
        $numericStr = $v;

        if (preg_match('/rgba?\s*\([^)]+\)/i', $v, $m)) {
            if (preg_match('/rgba?\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $m[0], $cm)) {
                $r = (int)$cm[1]; $g = (int)$cm[2]; $b = (int)$cm[3];
                $color = sprintf('#%02X%02X%02X', $r, $g, $b);
            }
            $numericStr = trim(preg_replace('/' . preg_quote(explode('(', $m[0])[0], '/') . '\([^)]+\)\s*,?\s*/', '', $v));
        } elseif (preg_match('/#([0-9a-fA-F]{3,8})\b/', $v, $m)) {
            $color = $m[0];
            $numericStr = trim(str_replace($m[0], '', $v));
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
        return $h . '|' . $vOff . '|' . $blur . '|' . $spread . '|' . $color;
    }

    public static function parseOpacity(string $value): float
    {
        $v = trim($value);
        if (str_ends_with($v, '%')) {
            return ((float)substr($v, 0, -1)) / 100.0;
        }
        return min(1.0, max(0.0, (float)$v));
    }

    public static function parseIdent(string $value): string
    {
        return trim(strtolower($value));
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
            return ['type' => 'explicit', 'sizes' => $sizes];
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
}
