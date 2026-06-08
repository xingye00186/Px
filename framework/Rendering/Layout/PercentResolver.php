<?php

namespace Px\Rendering\Layout;

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
     * border-box: content width = totalW - paddingLeft - paddingRight - borderWidth*2
     * content-box: content width = totalW - paddingLeft - paddingRight (current default)
     */
    public static function computeContentWidth(array $style, int $totalW): int
    {
        $boxSizing = $style['boxSizing'] ?? 'content-box';
        $padL = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
        $padR = (int)($style['paddingRight'] ?? $style['padding'] ?? 0);
        $contentW = max(0, $totalW - $padL - $padR);
        if ($boxSizing === 'border-box') {
            $bw = (int)($style['borderWidth'] ?? 0);
            $contentW = max(0, $contentW - $bw * 2);
        }
        return $contentW;
    }

    /**
     * Compute the content area height considering box-sizing and border.
     */
    public static function computeContentHeight(array $style, int $totalH): int
    {
        $boxSizing = $style['boxSizing'] ?? 'content-box';
        $padT = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);
        $padB = (int)($style['paddingBottom'] ?? $style['padding'] ?? 0);
        $contentH = max(0, $totalH - $padT - $padB);
        if ($boxSizing === 'border-box') {
            $bw = (int)($style['borderWidth'] ?? 0);
            $contentH = max(0, $contentH - $bw * 2);
        }
        return $contentH;
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
    public static function measureTextWidth(string $text, int $fontSize = 14, bool $bold = false): int
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
        $raw = $style[$key] ?? null;
        if ($raw === null || $raw === 'auto' || $raw === '') {
            return 0;
        }
        return (int)$raw;
    }

    /**
     * 应用 CSS min-width/max-width 或 min-height/max-height 约束。
     * CSS 规范: 如果 min > max，则 max 被忽略。
     */
    public static function applyMinMax(array $style, int $size, bool $isWidth): int
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
