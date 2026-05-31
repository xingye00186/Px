<?php

namespace Px\Animation;

/**
 * EasingFunctions — 缓动函数库
 *
 * 提供 Vue 3 风格的标准缓动函数，支持 CSS transition 和 @keyframes。
 * 所有函数签名: float ease(float t) 其中 t ∈ [0, 1]
 *
 * AOT 兼容: 使用 static protected 数组存储 easing 函数（不使用闭包）
 */
class EasingFunctions
{
    // 标准缓动函数名称 → 对应方法名
    protected static array $easingMap = [
        'linear'      => 'easeLinear',
        'ease'        => 'easeEase',
        'ease-in'     => 'easeInQuad',
        'ease-out'    => 'easeOutQuad',
        'ease-in-out' => 'easeInOutQuad',
        'ease-in-sine'  => 'easeInSine',
        'ease-out-sine' => 'easeOutSine',
        'ease-in-out-sine' => 'easeInOutSine',
        'ease-in-quad'   => 'easeInQuad',
        'ease-out-quad'  => 'easeOutQuad',
        'ease-in-out-quad' => 'easeInOutQuad',
        'ease-in-cubic'   => 'easeInCubic',
        'ease-out-cubic'  => 'easeOutCubic',
        'ease-in-out-cubic' => 'easeInOutCubic',
        'ease-in-quart'   => 'easeInQuart',
        'ease-out-quart'  => 'easeOutQuart',
        'ease-in-out-quart' => 'easeInOutQuart',
        'ease-in-expo'   => 'easeInExpo',
        'ease-out-expo'  => 'easeOutExpo',
        'ease-in-out-expo' => 'easeInOutExpo',
        'ease-in-back'  => 'easeInBack',
        'ease-out-back' => 'easeOutBack',
    ];

    /**
     * 根据名称获取缓动函数。
     * 返回一个可调用的缓动函数。
     *
     * @param string $name CSS easing 名称
     * @return callable 缓动函数 (float t) => float
     */
    public static function get(string $name): callable
    {
        $normalized = strtolower(trim($name));
        $method = self::$easingMap[$normalized] ?? 'easeLinear';
        return [self::class, $method];
    }

    /**
     * 检查是否为已知的缓动函数名称。
     */
    public static function isKnown(string $name): bool
    {
        $normalized = strtolower(trim($name));
        return isset(self::$easingMap[$normalized]);
    }

    // ============================================================
    // 缓动函数实现（每个方法独立，便于 AOT）
    // ============================================================

    public static function easeLinear(float $t): float
    {
        return $t;
    }

    public static function easeEase(float $t): float
    {
        // cubic-bezier(0.25, 0.1, 0.25, 1) — 近似 ease
        return self::cubicBezier($t, 0.25, 0.1, 0.25, 1.0);
    }

    public static function easeInQuad(float $t): float
    {
        return $t * $t;
    }

    public static function easeOutQuad(float $t): float
    {
        $t1 = 1.0 - $t;
        return 1.0 - $t1 * $t1;
    }

    public static function easeInOutQuad(float $t): float
    {
        if ($t < 0.5) {
            return 2.0 * $t * $t;
        }
        $t1 = 2.0 * $t - 2.0;
        return 0.5 * $t1 * $t1 + 1.0;
    }

    public static function easeInSine(float $t): float
    {
        return 1.0 - cos(($t * M_PI) / 2.0);
    }

    public static function easeOutSine(float $t): float
    {
        return sin(($t * M_PI) / 2.0);
    }

    public static function easeInOutSine(float $t): float
    {
        return -0.5 * (cos(M_PI * $t) - 1.0);
    }

    public static function easeInCubic(float $t): float
    {
        return $t * $t * $t;
    }

    public static function easeOutCubic(float $t): float
    {
        $t1 = 1.0 - $t;
        return 1.0 - $t1 * $t1 * $t1;
    }

    public static function easeInOutCubic(float $t): float
    {
        if ($t < 0.5) {
            return 4.0 * $t * $t * $t;
        }
        $t1 = 2.0 * $t - 2.0;
        return 0.5 * $t1 * $t1 * $t1 + 1.0;
    }

    public static function easeInQuart(float $t): float
    {
        return $t * $t * $t * $t;
    }

    public static function easeOutQuart(float $t): float
    {
        $t1 = 1.0 - $t;
        return 1.0 - $t1 * $t1 * $t1 * $t1;
    }

    public static function easeInOutQuart(float $t): float
    {
        if ($t < 0.5) {
            return 8.0 * $t * $t * $t * $t;
        }
        $t1 = 2.0 * $t - 2.0;
        return 1.0 - 0.5 * $t1 * $t1 * $t1 * $t1;
    }

    public static function easeInExpo(float $t): float
    {
        if ($t == 0.0) {
            return 0.0;
        }
        return pow(2.0, 10.0 * ($t - 1.0));
    }

    public static function easeOutExpo(float $t): float
    {
        if ($t == 1.0) {
            return 1.0;
        }
        return 1.0 - pow(2.0, -10.0 * $t);
    }

    public static function easeInOutExpo(float $t): float
    {
        if ($t == 0.0) {
            return 0.0;
        }
        if ($t == 1.0) {
            return 1.0;
        }
        if ($t < 0.5) {
            return pow(2.0, 20.0 * $t - 10.0) / 2.0;
        }
        return (2.0 - pow(2.0, -20.0 * $t + 10.0)) / 2.0;
    }

    public static function easeInBack(float $t): float
    {
        $C1 = 1.70158;
        return $t * $t * (($C1 + 1.0) * $t - $C1);
    }

    public static function easeOutBack(float $t): float
    {
        $C1 = 1.70158;
        $C3 = $C1 + 1.0;
        $t1 = $t - 1.0;
        return 1.0 + $C3 * $t1 * $t1 * $t1 + $C1 * $t1 * $t1;
    }

    /**
     * 三次贝塞尔曲线实现。
     *
     * @param float $t 参数 t ∈ [0, 1]
     * @param float $p1x 控制点 1 x
     * @param float $p1y 控制点 1 y
     * @param float $p2x 控制点 2 x
     * @param float $p2y 控制点 2 y
     */
    public static function cubicBezier(float $t, float $p1x, float $p1y, float $p2x, float $p2y): float
    {
        // 解析 x 方向的 t（牛顿迭代法）
        $cx = 3.0 * $p1x;
        $bx = 3.0 * ($p2x - $p1x) - $cx;
        $ax = 1.0 - $cx - $bx;

        $t1 = $t;
        for ($i = 0; $i < 8; $i++) {
            $x = (($ax * $t1 + $bx) * $t1 + $cx) * $t1;
            if (abs($x - $t) < 1e-6) {
                break;
            }
            $t1 = $t1 - ($x - $t) / ((3.0 * $ax * $t1 + 2.0 * $bx) * $t1 + $cx);
        }

        // 用解析出的 t 计算 y 值
        $cy = 3.0 * $p1y;
        $by = 3.0 * ($p2y - $p1y) - $cy;
        $ay = 1.0 - $cy - $by;
        return (($ay * $t1 + $by) * $t1 + $cy) * $t1;
    }
}
