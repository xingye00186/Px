<?php

namespace Px\Animation;

/**
 * Interpolator — 值插值工具
 *
 * 支持数值（translateX/Y）、颜色（整数 BGR）、transform 的插值。
 * 所有插值函数返回目标类型的值，便于直接赋值给 RenderNode。
 *
 * AOT 兼容: 使用静态方法，不使用闭包
 */
class Interpolator
{
    // ============================================================
    // 数值插值
    // ============================================================

    /**
     * 数值插值。
     *
     * @param float $from 起始值
     * @param float $to    目标值
     * @param float $t     进度 [0, 1]
     * @return float 插值结果
     */
    public static function lerpFloat(float $from, float $to, float $t): float
    {
        return $from + ($to - $from) * $t;
    }

    /**
     * 整数插值（用于像素坐标）。
     *
     * @param int $from 起始值
     * @param int $to    目标值
     * @param float $t   进度 [0, 1]
     * @return int 插值结果（四舍五入）
     */
    public static function lerpInt(int $from, int $to, float $t): int
    {
        return (int)round($from + ($to - $from) * $t);
    }

    /**
     * translateX/translateY 插值。
     * 返回格式与 CssMappings::parseTransform 兼容。
     *
     * @param array $from ['translateX' => int, 'translateY' => int]
     * @param array $to   ['translateX' => int, 'translateY' => int]
     * @param float $t    进度 [0, 1]
     * @return array ['translateX' => int, 'translateY' => int]
     */
    public static function interpolateTransform(array $from, array $to, float $t): array
    {
        return [
            'translateX' => self::lerpInt(
                $from['translateX'] ?? 0,
                $to['translateX'] ?? 0,
                $t
            ),
            'translateY' => self::lerpInt(
                $from['translateY'] ?? 0,
                $to['translateY'] ?? 0,
                $t
            ),
        ];
    }

    // ============================================================
    // 颜色插值（整数 BGR 格式）
    // ============================================================

    /**
     * 颜色插值。
     * 输入/输出格式: 整数 BGR (0xBBGGRR)，与 CssMappings 兼容。
     *
     * @param int $bgrFrom 起始颜色 (BGR)
     * @param int $bgrTo   目标颜色 (BGR)
     * @param float $t     进度 [0, 1]
     * @return int 插值颜色 (BGR)
     */
    public static function interpolateColor(int $bgrFrom, int $bgrTo, float $t): int
    {
        // 提取 BGR 分量
        $fromB = $bgrFrom & 0xFF;
        $fromG = ($bgrFrom >> 8) & 0xFF;
        $fromR = ($bgrFrom >> 16) & 0xFF;

        $toB = $bgrTo & 0xFF;
        $toG = ($bgrTo >> 8) & 0xFF;
        $toR = ($bgrTo >> 16) & 0xFF;

        // 线性插值
        $b = (int)round($fromB + ($toB - $fromB) * $t);
        $g = (int)round($fromG + ($toG - $fromG) * $t);
        $r = (int)round($fromR + ($toR - $fromR) * $t);

        // 重新组装为 BGR
        return ($b) | ($g << 8) | ($r << 16);
    }

    /**
     * 带透明度的颜色插值。
     *
     * @param int $bgrFrom   起始颜色 (BGR)
     * @param int $bgrTo     目标颜色 (BGR)
     * @param float $t       进度 [0, 1]
     * @param float $opacityFrom 起始透明度
     * @param float $opacityTo   目标透明度
     * @return array ['color' => int, 'opacity' => float]
     */
    public static function interpolateColorWithOpacity(
        int $bgrFrom,
        int $bgrTo,
        float $t,
        float $opacityFrom,
        float $opacityTo
    ): array {
        return [
            'color'   => self::interpolateColor($bgrFrom, $bgrTo, $t),
            'opacity' => self::lerpFloat($opacityFrom, $opacityTo, $t),
        ];
    }

    // ============================================================
    // 样式插值（批量属性）
    // ============================================================

    /**
     * 样式数组插值（用于 CSS transition 批量属性动画）。
     *
     * 支持的属性:
     * - 'translateX', 'translateY': int
     * - 'backgroundColor': int (BGR)
     * - 'color': int (BGR)
     * - 'opacity': float
     *
     * @param array $fromStyle 起始样式（$node->style 的子集）
     * @param array $toStyle   目标样式
     * @param float $t         进度 [0, 1]
     * @return array 插值后的样式数组
     */
    public static function interpolateStyle(array $fromStyle, array $toStyle, float $t): array
    {
        $result = [];

        // translateX / translateY
        if (isset($toStyle['translateX']) || isset($toStyle['translateY'])) {
            $fromTransform = [
                'translateX' => $fromStyle['translateX'] ?? 0,
                'translateY' => $fromStyle['translateY'] ?? 0,
            ];
            $toTransform = [
                'translateX' => $toStyle['translateX'] ?? 0,
                'translateY' => $toStyle['translateY'] ?? 0,
            ];
            $transform = self::interpolateTransform($fromTransform, $toTransform, $t);
            $result['translateX'] = $transform['translateX'];
            $result['translateY'] = $transform['translateY'];
        }

        // backgroundColor
        if (isset($toStyle['backgroundColor'])) {
            $fromColor = $fromStyle['backgroundColor'] ?? 0xFFFFFF;
            $toColor = $toStyle['backgroundColor'];
            $result['backgroundColor'] = self::interpolateColor($fromColor, $toColor, $t);
        }

        // color
        if (isset($toStyle['color'])) {
            $fromColor = $fromStyle['color'] ?? 0x000000;
            $toColor = $toStyle['color'];
            $result['color'] = self::interpolateColor($fromColor, $toColor, $t);
        }

        // opacity
        if (isset($toStyle['opacity'])) {
            $fromOpacity = $fromStyle['opacity'] ?? 1.0;
            $toOpacity = $toStyle['opacity'];
            $result['opacity'] = self::lerpFloat($fromOpacity, $toOpacity, $t);
        }

        return $result;
    }

    /**
     * 样式数组批量混合。
     * 将 fromStyle 和 toStyle 按照 t 混合，结果写入 $result。
     *
     * @param array $result    结果数组（会被修改）
     * @param array $fromStyle  起始样式
     * @param array $toStyle    目标样式
     * @param float $t          进度 [0, 1]
     */
    public static function blendStyle(array &$result, array $fromStyle, array $toStyle, float $t): void
    {
        $blended = self::interpolateStyle($fromStyle, $toStyle, $t);
        foreach ($blended as $key => $value) {
            $result[$key] = $value;
        }
    }
}
