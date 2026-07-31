<?php

namespace Px\Animation;

use Px\Css\CssMappings;
use Px\Render\RenderNode;
use Px\Animation\Interpolator;

/**
 * KeyframeResolver — @keyframes 动画解析器
 *
 * 负责处理 CSS @keyframes 动画：
 * - 管理已注册的 @keyframes 定义
 * - 根据当前进度计算插值属性
 * - 与 AnimationManager 集成驱动动画
 *
 * AOT 兼容: 使用 protected static 数组，不使用闭包
 */
class KeyframeResolver
{
    // 已注册的 @keyframes 定义
    /** @var array<string, array<int, array<string, mixed>>> animationName => [percentage => [property => value]] */
    protected static array $keyframesRegistry = [];

    // 活跃的 @keyframes 动画实例
    /** @var array<string, KeyframeAnimation> 动画实例 */
    protected static array $activeAnimations = [];

    /**
     * 注册 @keyframes 规则。
     *
     * @param string $name       动画名称
     * @param array $frames     关键帧定义
     *   [
     *     0 => ['opacity' => 0, 'transform' => 'translateX(-100px)'],
     *     50 => ['opacity' => 1, 'transform' => 'translateX(0)'],
     *     100 => ['opacity' => 0, 'transform' => 'translateX(100px)'],
     *   ]
     */
    public static function register(string $name, array $frames): void
    {
        // 按百分比排序
        ksort($frames);
        self::$keyframesRegistry[$name] = $frames;
    }

    /**
     * 从 CSS 字符串解析并注册 @keyframes。
     *
     * @param string $css 包含 @keyframes 规则的 CSS
     */
    public static function parseAndRegister(string $css): void
    {
        // 解析并注册到本类的 $keyframesRegistry（同时继续注册到
        // CssAnimationParser，保持双影存储兼容旧代码）。
        CssAnimationParser::parseAndRegisterKeyframes($css);
        // 同步：CssAnimationParser::$keyframes → self::$keyframesRegistry
        foreach (CssAnimationParser::getAllKeyframes() as $name => $frames) {
            if (!isset(self::$keyframesRegistry[$name])) {
                self::$keyframesRegistry[$name] = $frames;
            }
        }
    }

    /**
     * 获取 @keyframes 定义。
     */
    public static function getKeyframes(string $name): array
    {
        return self::$keyframesRegistry[$name] ?? [];
    }

    /**
     * 检查是否已注册。
     */
    public static function isRegistered(string $name): bool
    {
        return isset(self::$keyframesRegistry[$name]);
    }

    // ============================================================
    // 动画控制
    // ============================================================

    /**
     * 开始 @keyframes 动画。
     *
     * @param RenderNode $node         目标节点
     * @param string $animationName     动画名称
     * @param int $durationMs           动画时长（毫秒）
     * @param string $easing            缓动函数
     * @param int $delayMs              延迟（毫秒）
     * @param int $iterations           循环次数（-1 = 无限）
     * @return string 动画实例 ID
     */
    public static function startAnimation(
        RenderNode $node,
        string $animationName,
        int $durationMs,
        string $easing = 'ease',
        int $delayMs = 0,
        int $iterations = 1
    ): string {
        $keyframes = self::getKeyframes($animationName);
        if (empty($keyframes)) {
            return '';
        }

        $id = uniqid('kf_');
        $animation = new KeyframeAnimation(
            id: $id,
            nodeId: spl_object_id($node),
            animationName: $animationName,
            durationMs: $durationMs,
            easing: $easing,
            delayMs: $delayMs,
            iterations: $iterations
        );

        self::$activeAnimations[$id] = $animation;

        // T4 治本：把节点注册到 AnimationManager 的 nodeMap，
        // 否则 tick 时 $nodeMap[$anim->nodeId] 为 null（仅有 @keyframes
        // 动画的节点不会经 transition 路径被加入）。
        AnimationManager::getInstance()->registerNodeForKeyframe($node);

        return $id;
    }

    /**
     * 停止动画。
     */
    public static function stopAnimation(string $id): void
    {
        unset(self::$activeAnimations[$id]);
    }

    /**
     * 停止节点上的所有动画。
     */
    public static function stopAllOnNode(int $nodeId): void
    {
        foreach (self::$activeAnimations as $id => $anim) {
            if ($anim->nodeId === $nodeId) {
                unset(self::$activeAnimations[$id]);
            }
        }
    }

    // ============================================================
    // 帧计算
    // ============================================================

    /**
     * 根据进度获取插值属性。
     *
     * @param string $animationName 动画名称
     * @param float $progress       进度 [0, 1]
     * @return array 插值后的属性值
     */
    public static function getInterpolatedProps(string $animationName, float $progress): array
    {
        $keyframes = self::getKeyframes($animationName);
        if (empty($keyframes)) {
            return [];
        }

        $percent = $progress * 100;
        $frameKeys = array_keys($keyframes);

        // 边界处理
        if ($percent <= $frameKeys[0]) {
            return $keyframes[$frameKeys[0]];
        }
        if ($percent >= $frameKeys[count($frameKeys) - 1]) {
            return $keyframes[$frameKeys[count($frameKeys) - 1]];
        }

        // 查找相邻帧
        $prevKey = null;
        $nextKey = null;
        foreach ($frameKeys as $key) {
            if ($key <= $percent) {
                $prevKey = $key;
            }
            if ($key > $percent && $nextKey === null) {
                $nextKey = $key;
                break;
            }
        }

        if ($prevKey === null || $nextKey === null) {
            return $keyframes[$frameKeys[0]];
        }

        // 计算插值
        $t = ($percent - $prevKey) / ($nextKey - $prevKey);
        return self::interpolateFrames($keyframes[$prevKey], $keyframes[$nextKey], $t);
    }

    /**
     * 在两个关键帧之间插值。
     */
    private static function interpolateFrames(array $from, array $to, float $t): array
    {
        $result = [];

        // 合并所有属性键
        $allKeys = array_unique(array_merge(array_keys($from), array_keys($to)));

        foreach ($allKeys as $key) {
            $fromVal = $from[$key] ?? null;
            $toVal = $to[$key] ?? null;

            if ($fromVal === null) {
                $result[$key] = $toVal;
            } elseif ($toVal === null) {
                $result[$key] = $fromVal;
            } else {
                $result[$key] = self::interpolateValue($key, $fromVal, $toVal, $t);
            }
        }

        return $result;
    }

    /**
     * 根据属性类型插值单个值。
     */
    private static function interpolateValue(string $prop, mixed $from, mixed $to, float $t): mixed
    {
        // 数值（opacity、width、height 等）
        if (is_numeric($from) && is_numeric($to)) {
            return Interpolator::lerpFloat((float)$from, (float)$to, $t);
        }

        // 整数
        if (is_int($from) && is_int($to)) {
            return Interpolator::lerpInt($from, $to, $t);
        }

        // 颜色
        if (is_int($from) && is_int($to)) {
            return Interpolator::interpolateColor($from, $to, $t);
        }

        // Transform（translateX/Y）
        if ($prop === 'transform' || $prop === 'translateX' || $prop === 'translateY') {
            $fromTransform = is_array($from) ? $from : CssMappings::parseTransform($from);
            $toTransform = is_array($to) ? $to : CssMappings::parseTransform($to);
            return Interpolator::interpolateTransform($fromTransform, $toTransform, $t);
        }

        // 字符串值：返回目标值
        return $to;
    }

    // ============================================================
    // 批量处理（由 AnimationManager 每帧调用）
    // ============================================================

    /**
     * 更新所有活跃的 @keyframes 动画。
     * 由 AnimationManager::tick() 调用。
     *
     * @param int $deltaMs 距离上一帧的毫秒数
     * @param array &$nodeMap nodeId => RenderNode 映射
     */
    public static function tick(int $deltaMs, array &$nodeMap): void
    {
        $completed = [];

        foreach (self::$activeAnimations as $id => $anim) {
            $anim->elapsed += $deltaMs;

            // 跳过延迟
            if ($anim->elapsed < $anim->delayMs) {
                continue;
            }

            $activeTime = $anim->elapsed - $anim->delayMs;
            $progress = $activeTime / $anim->durationMs;

            // 处理循环
            if ($progress >= 1.0) {
                if ($anim->iterations > 0) {
                    $anim->iterations--;
                }
                if ($anim->iterations !== 0) {
                    $progress = fmod($progress, 1.0);
                    $anim->elapsed = $anim->delayMs + ($progress * $anim->durationMs);
                } else {
                    $progress = 1.0;
                    $completed[] = $id;
                }
            }

            // 应用缓动
            $easedProgress = self::applyEasing($progress, $anim->easing);

            // 更新节点属性
            $node = $nodeMap[$anim->nodeId] ?? null;
            if ($node !== null) {
                $props = self::getInterpolatedProps($anim->animationName, $easedProgress);

                // 将属性写入 animatedStyle
                foreach ($props as $prop => $value) {
                    if ($prop === 'transform') {
                        // transform 需要解析为 translateX/translateY
                        if (is_array($value)) {
                            if (isset($value['translateX'])) {
                                $node->animatedStyle['translateX'] = $value['translateX'];
                            }
                            if (isset($value['translateY'])) {
                                $node->animatedStyle['translateY'] = $value['translateY'];
                            }
                        }
                    } else {
                        $node->animatedStyle[$prop] = $value;
                    }
                }
                $node->isAnimating = true;
            }
        }

        // 移除已完成的动画
        foreach ($completed as $id) {
            unset(self::$activeAnimations[$id]);
        }
    }

    /**
     * 应用缓动函数。
     */
    private static function applyEasing(float $progress, string $easing): float
    {
        $easeFn = EasingFunctions::get($easing);
        return $easeFn($progress);
    }

    // ============================================================
    // 工具方法
    // ============================================================

    /**
     * 清空所有注册的 @keyframes（测试用）。
     */
    public static function reset(): void
    {
        self::$keyframesRegistry = [];
        self::$activeAnimations = [];
    }

    /**
     * 获取活跃动画数量。
     */
    public static function getActiveCount(): int
    {
        return count(self::$activeAnimations);
    }
}

/**
 * KeyframeAnimation — @keyframes 动画实例
 */
class KeyframeAnimation
{
    public string $id;
    public int $nodeId;
    public string $animationName;
    public int $durationMs;
    public string $easing;
    public int $delayMs;
    public int $iterations;
    public int $elapsed = 0;

    public function __construct(
        string $id,
        int $nodeId,
        string $animationName,
        int $durationMs,
        string $easing,
        int $delayMs,
        int $iterations
    ) {
        $this->id = $id;
        $this->nodeId = $nodeId;
        $this->animationName = $animationName;
        $this->durationMs = $durationMs;
        $this->easing = $easing;
        $this->delayMs = $delayMs;
        $this->iterations = $iterations;
    }
}
