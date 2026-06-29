<?php

namespace Px\Rendering;

use native_types;

/**
 * CssStyleHelper — 过渡期样式值解析辅助
 *
 * 替换 PercentResolver，适配 CssValue 类型（CssLength/CssKeyword 等）
 * 与旧式 int/string 并存的混合 $style 数组。
 *
 * Phase 2 中由 ComputedStyle 完全替代后删除此类。
 */
class CssStyleHelper
{
    /**
     * 从样式数组中解析尺寸值，支持 CssLength 对象或原始 int。
     *
     * @param array $style  样式数组
     * @param string $key   属性键名（如 'width', 'height', 'minWidth'）
     * @param int $containerSize 容器尺寸（百分比基准）
     * @return int 解析后的像素值
     */
    public static function resolveLength(array $style, string $key, int $containerSize): int
    {
        $val = $style[$key] ?? null;
        if ($val === null || $val === 'auto' || $val === '' || $val === 'none') {
            return 0;
        }
        if ($val instanceof CssLength) {
            if ($val->isPercent()) {
                return $containerSize > 0 ? (int)($containerSize * $val->value / 100.0) : 0;
            }
            return $val->toPx();
        }
        if (is_string($val)) {
            return CssLength::fromString($val)->resolveInContext($containerSize);
        }
        return (int)$val;
    }

    /**
     * 解析带 calc 偏移的尺寸（旧版 widthPercent + calcOffset 模式）。
     * 当 CssLength 值带 isPercent 时，自动应用 calcOffset。
     */
    public static function resolveWithCalc(array $style, string $key, int $containerSize): int
    {
        $val = $style[$key] ?? null;
        if ($val === null || $val === 'auto' || $val === '') {
            return 0;
        }

        $calcOffsetKey = 'calcOffset_' . $key;
        $calcOffset = (int)($style[$calcOffsetKey] ?? 0);

        if ($val instanceof CssLength) {
            $result = $val->resolveInContext($containerSize);
            return max(0, $result + $calcOffset);
        }

        $result = (int)$val;
        return max(0, $result + $calcOffset);
    }

    /**
     * 应用 CSS min-width/max-width 或 min-height/max-height 约束。
     */
    public static function applyMinMax(array $style, int $size, bool $isWidth): int
    {
        $minKey = $isWidth ? 'minWidth' : 'minHeight';
        $maxKey = $isWidth ? 'maxWidth' : 'maxHeight';

        $min = 0;
        if (isset($style[$minKey])) {
            $min = self::resolveLength($style, $minKey, $size);
        }

        $max = 0;
        if (isset($style[$maxKey])) {
            $max = self::resolveLength($style, $maxKey, $size);
        }

        // CSS 规范: 如果 min > max，max 被忽略
        if ($min > 0 && $max > 0 && $min > $max) {
            $max = 0;
        }

        if ($min > 0 && $size < $min) {
            $size = $min;
        }
        if ($max > 0 && $size > $max) {
            $size = $max;
        }

        return max(0, $size);
    }

    /**
     * 计算 content box 宽度（考虑 box-sizing）。
     */
    public static function contentBoxWidth(array $style, int $totalW): int
    {
        $boxSizing = self::getKeyword($style, 'boxSizing', 'content-box');
        if ($boxSizing === 'border-box') {
            $padL = self::resolveLength($style, 'paddingLeft', $totalW);
            $padR = self::resolveLength($style, 'paddingRight', $totalW);
            $bw = (int)($style['borderWidth'] ?? 0);
            return max(0, $totalW - $padL - $padR - $bw * 2);
        }
        return max(0, $totalW);
    }

    /**
     * 计算 content box 高度（考虑 box-sizing）。
     */
    public static function contentBoxHeight(array $style, int $totalH): int
    {
        $boxSizing = self::getKeyword($style, 'boxSizing', 'content-box');
        if ($boxSizing === 'border-box') {
            $padT = self::resolveLength($style, 'paddingTop', $totalH);
            $padB = self::resolveLength($style, 'paddingBottom', $totalH);
            $bw = (int)($style['borderWidth'] ?? 0);
            return max(0, $totalH - $padT - $padB - $bw * 2);
        }
        return max(0, $totalH);
    }

    /**
     * 计算视觉总宽度（border-box width）。
     */
    public static function visualWidth(array $style, int $contentW): int
    {
        $boxSizing = self::getKeyword($style, 'boxSizing', 'content-box');
        if ($boxSizing === 'border-box') {
            return max(0, $contentW);
        }
        $padL = self::resolveLength($style, 'paddingLeft', $contentW);
        $padR = self::resolveLength($style, 'paddingRight', $contentW);
        $bw = (int)($style['borderWidth'] ?? 0);
        $blw = (int)($style['borderLeftWidth'] ?? 0);
        $brw = (int)($style['borderRightWidth'] ?? 0);
        return max(0, $contentW + $padL + $padR + $blw + $brw);
    }

    /**
     * 计算视觉总高度（border-box height）。
     */
    public static function visualHeight(array $style, int $contentH): int
    {
        $boxSizing = self::getKeyword($style, 'boxSizing', 'content-box');
        if ($boxSizing === 'border-box') {
            return max(0, $contentH);
        }
        $padT = self::resolveLength($style, 'paddingTop', $contentH);
        $padB = self::resolveLength($style, 'paddingBottom', $contentH);
        $bw = (int)($style['borderWidth'] ?? 0);
        $btw = (int)($style['borderTopWidth'] ?? 0);
        $bbw = (int)($style['borderBottomWidth'] ?? 0);
        return max(0, $contentH + $padT + $padB + $btw + $bbw);
    }

    /**
     * Resolve line-height from style.
     * 同 PercentResolver::resolveLineHeight 逻辑。
     */
    public static function lineHeight(array $style, int $fontSize, int $rootFontSize = 16, ?array $parentStyle = null): int
    {
        $lh = $style['lineHeight'] ?? null;
        if ($lh === null || $lh === '' || $lh === 'normal') {
            if ($parentStyle !== null) {
                $parentLH = $parentStyle['lineHeight'] ?? null;
                if ($parentLH !== null && $parentLH !== '' && $parentLH !== 'normal') {
                    if (is_numeric($parentLH)) {
                        return (int)((float)$parentLH * $fontSize);
                    }
                    if (is_string($parentLH) && str_ends_with($parentLH, 'px')) {
                        return (int)substr($parentLH, 0, -2);
                    }
                    if (is_int($parentLH) || is_float($parentLH)) {
                        return (int)$parentLH;
                    }
                }
            }
            static $hasNativeLH = null;
            if ($hasNativeLH === null) {
                $hasNativeLH = function_exists('\\sk_measure_text_height');
            }
            if ($hasNativeLH) {
                $measured = (int)\sk_measure_text_height($fontSize, 0);
                if ($measured > 0) {
                    return $measured;
                }
            }
            return (int)($fontSize * 1.2);
        }
        if (is_string($lh) && str_ends_with($lh, 'px')) {
            return (int)substr($lh, 0, -2);
        }
        if (is_numeric($lh)) {
            return (int)((float)$lh * $fontSize);
        }
        if (is_string($lh) && str_contains($lh, '|')) {
            $parts = explode('|', $lh);
            $val = (float)$parts[0];
            $unit = $parts[1] ?? 'px';
            return (int)match ($unit) {
                'rem' => $val * $rootFontSize,
                'vw'  => $val * (defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 1920) / 100.0,
                'vh'  => $val * (defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 1080) / 100.0,
                'vmin' => $val * min(
                    defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 1920,
                    defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 1080
                ) / 100.0,
                'vmax' => $val * max(
                    defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 1920,
                    defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 1080
                ) / 100.0,
                'ch'  => $val * $fontSize * 0.6,
                'ex'  => $val * $fontSize * 0.5,
                default => $val * $fontSize,
            };
        }
        return (int)($fontSize * 1.2);
    }

    /**
     * 安全获取样式数组中的 keyword 值（兼容 CssKeyword 对象和原始字符串）。
     */
    public static function getKeyword(array $style, string $key, string $default = ''): string
    {
        $val = $style[$key] ?? null;
        if ($val === null) return $default;
        if ($val instanceof CssKeyword) return $val->value;
        if ($val instanceof CssLength) {
            // Some properties (like flexBasis) might be CssLength
            return $val->isAuto() ? 'auto' : '';
        }
        return (string)$val;
    }

    /**
     * 安全获取样式数组中的数值（兼容 CssLength 对象和原始 int/string）。
     */
    public static function getInt(array $style, string $key, int $default = 0): int
    {
        $val = $style[$key] ?? null;
        if ($val === null) return $default;
        if ($val instanceof CssLength) return $val->toPx();
        if (is_string($val)) return (int)$val;
        return (int)$val;
    }

    /**
     * 判断样式数组是否包含指定键（兼容 CssValue 类型）。
     */
    public static function hasKey(array $style, string $key): bool
    {
        return array_key_exists($key, $style);
    }

    /**
     * Resolve fontSize from relative unit (em/rem/vw/vh).
     * Inline version of PercentResolver::resolveFontSizeUnit for the mixed style array.
     */
    public static function resolveFontSize(array &$style, int $rootFontSize = 16, int $viewportW = 1920, int $viewportH = 1080): void
    {
        // If font-size is a CssLength, it carries unit info natively
        if (isset($style['fontSize']) && $style['fontSize'] instanceof CssLength) {
            $cl = $style['fontSize'];
            if ($cl->isRelative()) {
                $fs = $cl->resolveInContext(0, 16, $rootFontSize, $viewportW, $viewportH);
                $style['fontSize'] = $fs;
            }
            return;
        }
        // Legacy: font-size from relative unit string
        if (isset($style['fontSizeUnit'])) {
            $parts = explode('|', $style['fontSizeUnit']);
            $val = (float)$parts[0];
            $unit = $parts[1] ?? 'px';
            $parentFontSize = (int)($style['fontSize'] ?? 14);
            $resolved = (int)match ($unit) {
                'rem' => round($val * $rootFontSize),
                'em' => round($val * $parentFontSize),
                'vw' => round($val * $viewportW / 100.0),
                'vh' => round($val * $viewportH / 100.0),
                'vmin' => round($val * min($viewportW, $viewportH) / 100.0),
                'vmax' => round($val * max($viewportW, $viewportH) / 100.0),
                default => round($val),
            };
            $style['fontSize'] = $resolved;
            unset($style['fontSizeUnit']);
        }
    }
}
