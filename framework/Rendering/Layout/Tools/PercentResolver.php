<?php

namespace Px\Rendering\Layout\Tools;

use native_types;

use Px\Rendering\RenderNode;

/**
 * PercentResolver — 百分比尺寸与 CSS 辅助方法
 *
 * 纯静态工具类，包含从 LayoutResolver 提取的百分比解析、min/max 约束、
 * 文本测量、内容尺寸计算等无状态方法。
 */
class PercentResolver
{
    /**
     * Compute the content area width considering box-sizing and border.
     *
     * CSS Box Model: $node->w represents the CSS 'width' property value.
     *   content-box: CSS 'width' = content width, so totalW IS content width.
     *   border-box:  CSS 'width' = border-box width, subtract padding+border to get content.
     */
    public static function resolveContentWidth(array $style, int $totalW): int
    {
        $boxSizing = $style['boxSizing'] ?? 'content-box';
        if ($boxSizing === 'border-box') {
            $padL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
            $padR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);
            $bw = (int)($style['borderWidth'] ?? 0);
            return max(0, $totalW - $padL - $padR - $bw * 2);
        }
        // content-box: totalW ($node->w) = CSS 'width' which IS the content width
        return max(0, $totalW);
    }

    /**
     * Compute the content area height considering box-sizing and border.
     *
     * CSS Box Model: $node->h represents the CSS 'height' property value.
     *   content-box: CSS 'height' = content height, so totalH IS content height.
     *   border-box:  CSS 'height' = border-box height, subtract padding+border to get content.
     */
    public static function resolveContentHeight(array $style, int $totalH): int
    {
        $boxSizing = $style['boxSizing'] ?? 'content-box';
        if ($boxSizing === 'border-box') {
            $padT = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
            $padB = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);
            $bw = (int)($style['borderWidth'] ?? 0);
            return max(0, $totalH - $padT - $padB - $bw * 2);
        }
        // content-box: totalH ($node->h) = CSS 'height' which IS the content height
        return max(0, $totalH);
    }

    /**
     * Resolve line-height from style.
     *
     * CSS line-height can be:
     *   - unitless number (e.g., 1.5): multiplier × font-size
     *   - pixel value (e.g., 20px): fixed line height in pixels
     *   - 'normal' or unset: fallback to font-size × 1.2 (CSS 2.2 §10.8.1)
     *   - 'value|unit' pattern (e.g., '2|rem'): resolved with context (root font-size)
     *
     * CSS 2.2 §10.8.1 规定 line-height 是继承属性。当子元素未设置时应继承父元素的计算值：
     *   - 父元素为无单位数(1.7)：继承乘数，used value = 1.7 × childFontSize
     *   - 父元素为长度值(28px)：直接继承像素值
     *   - 父元素为百分比(150%)：继承计算后的长度值
     *
     * @param array $style 当前元素样式
     * @param int $fontSize 当前元素字体大小
     * @param int $rootFontSize 根元素字体大小（默认16）
     * @param array|null $parentStyle 父元素样式（用于继承）
     * @return int
     */
    public static function resolveLineHeight(array $style, int $fontSize, int $rootFontSize = 16, ?array $parentStyle = null): int
    {
        $lh = $style['lineHeight'] ?? null;
        // 当前元素没有显式设置 line-height 时，尝试从父元素继承
        if ($lh === null || $lh === '' || $lh === 'normal') {
            if ($parentStyle !== null) {
                $parentLH = $parentStyle['lineHeight'] ?? null;
                if ($parentLH !== null && $parentLH !== '' && $parentLH !== 'normal') {
                    // CSS 继承规则：
                    if (is_numeric($parentLH)) {
                        // 无单位数：继承乘数，应用于子元素的 font-size
                        return (int)((float)$parentLH * $fontSize);
                    }
                    // px 值：直接继承像素值
                    if (is_string($parentLH) && str_ends_with($parentLH, 'px')) {
                        return (int)substr($parentLH, 0, -2);
                    }
                    // 已解析的 px 数值：直接继承
                    if (is_int($parentLH) || is_float($parentLH)) {
                        return (int)$parentLH;
                    }
                }
                // 父元素也没有显式 line-height，继续向上查找
                // 但我们只有一层 parentStyle，因此这里用 normal 处理
            }
            // CSS 2.2 §10.8.1: 'normal' 的 line-height 由 UA 决定
            // 使用 GDI 测量真实字体行高（ascent + descent），替代硬编码倍数公式
            // GDI 默认字体现为 Segoe UI（与浏览器一致），测高结果 ≈ fontSize × 1.2
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
            // Fallback: font-size × 1.2 (CSS standard ratio for most fonts)
            return (int)($fontSize * 1.2);
        }
        // String ending in 'px' — extract pixel value
        if (is_string($lh) && str_ends_with($lh, 'px')) {
            return (int)substr($lh, 0, -2);
        }
        // Unitless numeric multiplier — multiply by font-size
        if (is_numeric($lh)) {
            return (int)((float)$lh * $fontSize);
        }
        // "value|unit" pattern (rem, vw, vh, vmin, vmax, ch, ex)
        if (is_string($lh) && str_contains($lh, '|')) {
            $parts = explode('|', $lh);
            $val = (float)$parts[0];
            $unit = $parts[1] ?? 'px';
            // rem → relative to root font-size; viewport units → use WINDOW_* constants
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
                'ch'  => $val * $fontSize * 0.6,  // approximate: 1ch ≈ 0.6em
                'ex'  => $val * $fontSize * 0.5,  // approximate: 1ex ≈ 0.5em
                default => $val * $fontSize,
            };
        }
        return (int)($fontSize * 1.2);
    }

    /**
     * Resolve fontSizeUnit (rem/em/vw/vh) to actual pixel fontSize.
     * Must be called before fontSize is used in layout calculations.
     *
     * @param array &$style The node's style array (modified in-place)
     * @param int $rootFontSize Root element font-size for rem resolution (default 16)
     * @param int $viewportW Viewport width for vw resolution
     * @param int $viewportH Viewport height for vh resolution
     */
    public static function resolveFontSizeUnit(array &$style, int $rootFontSize = 16, int $viewportW = 1920, int $viewportH = 1080): void
    {
        if (!isset($style['fontSizeUnit'])) return;
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

    /**
     * Measure text width for a given string using current font settings.
     *
     * Uses native C++ sk_measure_text_width when available, falls back to
     * character-width estimation (same logic as VNodeRenderer).
     *
     * @param string $text The text to measure
     * @param int $fontSize Font size in pixels (default 14)
     * @param bool $bold Whether text is bold (default false)
     * @return int Measured width in pixels
     */
    public static function resolveTextWidth(string $text, int $fontSize = 14, bool $bold = false): int
    {
        if (strlen($text) === 0) {
            return 0;
        }

        static $hasNative = null;

        if ($hasNative === null) {
            $hasNative = function_exists('\\sk_measure_text_width');
            error_log("[PX_DEBUG] resolveTextWidth: hasNative=" . ($hasNative ? 'true' : 'false'));
        }

        if ($hasNative) {
            $result = (int)\sk_measure_text_width($text, $fontSize, $bold);
            error_log("[PX_DEBUG] resolveTextWidth: native called text='$text' font=$fontSize bold=" . ($bold ? '1' : '0') . " => $result");
            return $result;
        }

        // Fallback: character-width estimation
        $boldFactor = $bold ? 1.35 : 1.0;

        $charW = (int)($fontSize * 0.6 * $boldFactor);

        $cjkW = (int)($fontSize * $boldFactor);

        $len = strlen($text);

        $total = 0;

        for ($i = 0; $i < $len;) {
            $b = ord($text[$i]);

            if ($b < 0x80) {
                // ASCII
                $total += $charW;

                $i++;
            } elseif ($b < 0xC0) {
                $i++;
            } elseif ($b < 0xE0) {
                $total += $cjkW;

                $i += 2;
            } elseif ($b < 0xF0) {
                $total += $cjkW;

                $i += 3;
            } else {
                $total += $cjkW;

                $i += 4;
            }
        }

        return $total;
    }

    /**
     * 解析百分比尺寸并计算
     * 如果 percentKey 存在（如 'widthPercent'），从 parentSize 计算实际像素值。
     * 否则回退到 pixel key（如 'width'）。
     */
    public static function resolvePercent(array $style, string $key, string $percentKey, int $parentSize): int
    {
        $pct = $style[$percentKey] ?? null;

        if ($pct !== null && $parentSize > 0) {
            $result = (int)($parentSize * $pct / 100.0);

            // Apply calc offset (e.g., calc(100% - 40px) stores -40 in widthCalcOffset)
            $calcOffsetKey = str_replace('Percent', 'CalcOffset', $percentKey);
            $calcOffset = $style[$calcOffsetKey] ?? 0;
            if ($calcOffset !== 0) {
                $result += (int)$calcOffset;
            }
            return max(0, $result);
        }

        $raw = $style[$key] ?? null;

        if ($raw === null || $raw === 'auto' || $raw === '' || is_string($raw)) {
            return 0;
        }

        return (int)$raw;
    }

    /**
     * 解析 margin/padding 百分比值（CSS Box Model §7）。
     * 所有方向（top/right/bottom/left）的百分比均基于包含块宽度计算。
     *
     * @param array $style 样式数组
     * @param string $key 像素值 key（如 'marginLeft'）
     * @param string $percentKey 百分比 key（如 'marginLeftPercent'）
     * @param int $containingBlockWidth 包含块宽度（百分比基准）
     * @return int 解析后的像素值
     */
    public static function resolveMarginPaddingPercent(array $style, string $key, string $percentKey, int $containingBlockWidth): int
    {
        $pct = $style[$percentKey] ?? null;
        if ($pct !== null && $containingBlockWidth > 0) {
            return (int)($containingBlockWidth * $pct / 100.0);
        }
        $raw = $style[$key] ?? $style['margin'] ?? null;
        if ($raw === null || $raw === 'auto' || $raw === '') {
            return 0;
        }
        return (int)$raw;
    }

    /**
     * 计算视觉总宽度（border-box width）。
     * content-box: visualW = w + padding + border
     * border-box:  visualW = w（因为 w 已包含 padding+border）
     */
    public static function resolveVisualW(array $style, int $w): int
    {
        $boxSizing = $style['boxSizing'] ?? 'content-box';
        if ($boxSizing === 'border-box') {
            return max(0, $w);
        }
        $padL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
        $padR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);
        $bw = (int)($style['borderWidth'] ?? 0);
        $blw = (int)($style['borderLeftWidth'] ?? 0);
        $brw = (int)($style['borderRightWidth'] ?? 0);
        return max(0, $w + $padL + $padR + $blw + $brw);
    }

    /**
     * 计算视觉总高度（border-box height）。
     * content-box: visualH = h + padding + border
     * border-box:  visualH = h（因为 h 已包含 padding+border）
     */
    public static function resolveVisualH(array $style, int $h): int
    {
        $boxSizing = $style['boxSizing'] ?? 'content-box';
        if ($boxSizing === 'border-box') {
            return max(0, $h);
        }
        $padT = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
        $padB = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);
        $bw = (int)($style['borderWidth'] ?? 0);
        $btw = (int)($style['borderTopWidth'] ?? 0);
        $bbw = (int)($style['borderBottomWidth'] ?? 0);
        return max(0, $h + $padT + $padB + $btw + $bbw);
    }

    /**
     * 应用 CSS min-width/max-width 或 min-height/max-height 约束。
     * CSS 规范: 如果 min > max，则 max 被忽略。
     */
    public static function resolveMinMax(array $style, int $size, bool $isWidth): int
    {
        $min = $isWidth ? (int)($style['minWidth'] ?? 0) : (int)($style['minHeight'] ?? 0);

        $max = $isWidth ? (int)($style['maxWidth'] ?? 0) : (int)($style['maxHeight'] ?? 0);

        // CSS 规范: 如果 min > max，max 被忽略
        if ($min > 0 && $max > 0 && $min > $max) {
            $max = 0;
        }

        if ($min > 0 && $size < $min) {
            $size = (int)$min;
        }

        if ($max > 0 && $size > $max) {
            $size = (int)$max;
        }

        return max(0, $size);
    }
}
