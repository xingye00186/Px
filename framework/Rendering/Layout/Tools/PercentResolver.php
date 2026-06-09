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
     *   - 'normal' or unset: fallback to font-size × 1.35
     */
    public static function resolveLineHeight(array $style, int $fontSize): int
    {
        $lh = $style['lineHeight'] ?? 'normal';
        if ($lh === 'normal' || $lh === '') {
            return (int)($fontSize * 1.35);
        }
        // String ending in 'px' — extract pixel value
        if (is_string($lh) && str_ends_with($lh, 'px')) {
            return (int)substr($lh, 0, -2);
        }
        // Unitless numeric multiplier — multiply by font-size
        if (is_numeric($lh)) {
            return (int)((float)$lh * $fontSize);
        }
        return (int)($fontSize * 1.35);
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
        }

        if ($hasNative) {
            return (int)\sk_measure_text_width($text, $fontSize, $bold);
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
