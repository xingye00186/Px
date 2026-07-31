<?php

namespace Px\Animation;

use Px\Render\RenderNode;
use Px\Animation\EasingFunctions;
use Px\Animation\Interpolator;

/**
 * AnimationManager — 动画管理器（单例）
 *
 * 负责管理所有活跃动画实例，包括：
 * - 帧驱动：每帧调用 tick() 推进动画
 * - 对象池：复用样式数组以降低 GC 压力
 * - MAX_ANIMATIONS 上限：防止动画泛滥
 * - RenderNode 更新：将 animatedStyle 写入 RenderNode
 *
 * AOT 兼容: 使用 static protected 数组，不使用闭包
 */
class AnimationManager
{
    // 最大同时活跃动画数
    private const MAX_ANIMATIONS = 50;

    // 对象池容量
    private const POOL_CAPACITY = 100;

    /** @var AnimationManager|null 单例 */
    private static ?self $instance = null;

    /** @var Animation[] 活跃动画实例 */
    private array $animations = [];

    /** @var FloatingText[] overlay 浮动文本（飞升动画）；不绑定 RenderNode */
    private array $floaters = [];

    /** @var RenderNode[] nodeId → RenderNode 映射（用于快速查找） */
    private array $nodeMap = [];

    // 对象池：复用样式数组
    /** @var array[] 空闲样式数组池 */
    private array $stylePool = [];
    private int $poolSize = 0;

    /**
     * 获取单例实例。
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 重置单例（测试用）。
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private function __construct()
    {
    }

    // ============================================================
    // 对象池
    // ============================================================

    /**
     * 从对象池获取一个空的样式数组。
     */
    private function acquireStyleArray(): array
    {
        if ($this->poolSize > 0) {
            $this->poolSize--;
            $arr = $this->stylePool[$this->poolSize];
            unset($this->stylePool[$this->poolSize]);
            return $arr;
        }
        return [];
    }

    /**
     * 归还样式数组到对象池。
     */
    private function releaseStyleArray(array $arr): void
    {
        if ($this->poolSize < self::POOL_CAPACITY) {
            $this->stylePool[$this->poolSize] = $arr;
            $this->poolSize++;
        }
    }

    // ============================================================
    // 动画注册
    // ============================================================

    /**
     * 注册一个 CSS transition 动画。
     *
     * @param RenderNode $node           目标 RenderNode
     * @param string $property           CSS 属性名
     * @param mixed $fromValue           起始值
     * @param mixed $toValue             目标值
     * @param int $durationMs            动画时长（毫秒）
     * @param string $easing             缓动函数名称
     */
    public function addTransition(
        RenderNode $node,
        string $property,
        mixed $fromValue,
        mixed $toValue,
        int $durationMs,
        string $easing = 'ease'
    ): void {
        if (count($this->animations) >= self::MAX_ANIMATIONS) {
            if (class_exists('Px\\Core\\Config') && \Px\Core\Config::get('diag_enabled', false)) {
                error_log('[AnimationManager] addTransition rejected: max animations reached');
            }
            return; // 达到上限，忽略新动画
        }

        // 生成节点唯一标识
        $nodeId = $this->getNodeId($node);
        if (!isset($this->nodeMap[$nodeId])) {
            $this->nodeMap[$nodeId] = $node;
        }

        // 检查是否已有同节点同属性的动画，如有则替换
        $existingKey = null;
        foreach ($this->animations as $key => $anim) {
            if ($anim->nodeId === $nodeId && $anim->property === $property) {
                $existingKey = $key;
                break;
            }
        }

        $animation = new Animation(
            nodeId: $nodeId,
            property: $property,
            fromValue: $fromValue,
            toValue: $toValue,
            durationMs: $durationMs,
            easing: $easing
        );

        if ($existingKey !== null) {
            $this->animations[$existingKey] = $animation;
        } else {
            $this->animations[] = $animation;
        }
    }

    /**
     * 注册一个 overlay 浮动文本（如点击数字飞升）。
     * 不绑定 RenderNode，由 PaintPipeline 在所有 layer 之后绘制。
     */
    public function addFloatingText(FloatingText $item): void
    {
        if (count($this->floaters) >= self::MAX_ANIMATIONS) {
            return;
        }
        $this->floaters[] = $item;
    }

    /** @return FloatingText[] 当前活跃浮动项（PaintPipeline 消费） */
    public function getFloaters(): array
    {
        return $this->floaters;
    }

    /**
     * 取消节点上指定属性的动画。
     */
    public function cancelTransition(RenderNode $node, string $property): void
    {
        $nodeId = $this->getNodeId($node);
        foreach ($this->animations as $key => $anim) {
            if ($anim->nodeId === $nodeId && $anim->property === $property) {
                unset($this->animations[$key]);
            }
        }
        $this->animations = array_values($this->animations);
    }

    /**
     * 取消节点上的所有动画，并从 nodeMap 中移除。
     *
     * 由 destroyRenderNodeTree 在节点销毁时调用，确保动画系统无残留引用。
     */
    public function cancelAllTransitions(RenderNode $node): void
    {
        $nodeId = $this->getNodeId($node);
        foreach ($this->animations as $key => $anim) {
            if ($anim->nodeId === $nodeId) {
                unset($this->animations[$key]);
            }
        }
        $this->animations = array_values($this->animations);
        unset($this->nodeMap[$nodeId]);
    }

    /**
     * 检查节点是否正在动画。
     */
    public function isAnimating(RenderNode $node): bool
    {
        $nodeId = $this->getNodeId($node);
        foreach ($this->animations as $anim) {
            if ($anim->nodeId === $nodeId) {
                return true;
            }
        }
        return false;
    }

    // ============================================================
    // 帧驱动
    // ============================================================

    /**
     * 每帧驱动所有动画前进。
     * 由 Platform 的 WM_TIMER 回调调用。
     *
     * @param int $deltaMs 距离上一帧的毫秒数
     */
    public function tick(int $deltaMs): void
    {
        $completedKeys = [];

        foreach ($this->animations as $key => $animRaw) {
            // AOT：对象数组元素需 objval 显式标注后才能安全访问属性/方法
            $anim = objval($animRaw, Animation::class);
            $anim->elapsed += $deltaMs;

            // 计算进度
            $progress = $anim->elapsed / $anim->durationMs;
            if ($progress > 1.0) {
                $progress = 1.0;
            }

            // 应用缓动函数
            $t = $this->applyEasing($progress, $anim->easing);

            // 更新 RenderNode 的 animatedStyle
            $this->updateRenderNode($anim, $t);

            // 完成检查
            if ($progress >= 1.0) {
                $completedKeys[] = $key;
                // 动画完成，写入最终值到 RenderNode
                $this->finishAnimation($anim);
            }
        }

        // 移除完成的动画
        foreach ($completedKeys as $key) {
            unset($this->animations[$key]);
        }
        if (count($completedKeys) > 0) {
            $this->animations = array_values($this->animations);
        }

        // 推进 overlay 浮动项（坐标/alpha 整数插值，完成即移除）
        $doneFloaters = [];
        foreach ($this->floaters as $fk => $fRaw) {
            $f = objval($fRaw, FloatingText::class);
            $f->elapsed += $deltaMs;
            $fp = $f->elapsed / $f->durationMs;
            if ($fp > 1.0) {
                $fp = 1.0;
            }
            $ft = $this->applyEasing($fp, $f->easing);
            $f->currentX = Interpolator::lerpInt($f->fromX, $f->toX, $ft);
            $f->currentY = Interpolator::lerpInt($f->fromY, $f->toY, $ft);
            $f->alphaPermille = Interpolator::lerpInt(1000, 0, $ft);
            if ($fp >= 1.0) {
                $doneFloaters[] = $fk;
            }
        }
        foreach ($doneFloaters as $fk) {
            unset($this->floaters[$fk]);
        }
        if (count($doneFloaters) > 0) {
            $this->floaters = array_values($this->floaters);
        }

        // 推进 @keyframes 动画（KeyframeResolver 独立系统，共享 nodeMap）
        KeyframeResolver::tick($deltaMs, $this->nodeMap);
    }

    /**
     * 应用缓动函数。
     *
     * @param float $progress 原始进度 [0, 1]
     * @param string $easing  缓动函数名称
     * @return float 应用缓动后的进度
     */
    private function applyEasing(float $progress, string $easing): float
    {
        $easeFn = EasingFunctions::get($easing);
        return $easeFn($progress);
    }

    /**
     * 更新 RenderNode 的 animatedStyle。
     */
    private function updateRenderNode(Animation $anim, float $t): void
    {
        $node = $this->nodeMap[$anim->nodeId] ?? null;
        if ($node === null) {
            return;
        }

        // 确保 animatedStyle 存在
        if ($node->animatedStyle === null) {
            $node->animatedStyle = $this->acquireStyleArray();
        }

        // 根据属性类型计算插值
        $blended = $this->interpolateValue($anim, $t);
        foreach ($blended as $prop => $value) {
            $node->animatedStyle[$prop] = $value;
        }

        $node->isAnimating = true;
        // paint-only 失效：沿父链置 paintDirty，使 directRender 不跳过动画中节点。
        $n = $node;
        while ($n !== null) {
            $n->paintDirty = true;
            $n = $n->parent;
        }
        // E4: 几何属性动画额外标记 layoutDirty（对标 Blink：
        // width/height 变化需触发 relayout）。
        foreach ($blended as $prop => $_) {
            if ($prop === 'width' || $prop === 'height'
                || $prop === 'minWidth' || $prop === 'maxWidth'
                || $prop === 'minHeight' || $prop === 'maxHeight') {
                $node->layoutDirty = true;
                $node->cachedFragment = null;
                $node->cachedConstraintSpace = null;
                break;
            }
        }
    }

    /**
     * 计算插值结果。
     *
     * @return array 要写入 animatedStyle 的键值对
     */
    private function interpolateValue(Animation $anim, float $t): array
    {
        switch ($anim->property) {
            case 'translateX':
                return [
                    'translateX' => Interpolator::lerpInt(
                        (int)$anim->fromValue,
                        (int)$anim->toValue,
                        $t
                    ),
                ];

            case 'translateY':
                return [
                    'translateY' => Interpolator::lerpInt(
                        (int)$anim->fromValue,
                        (int)$anim->toValue,
                        $t
                    ),
                ];

            case 'backgroundColor':
                return [
                    'backgroundColor' => Interpolator::interpolateColor(
                        (int)$anim->fromValue,
                        (int)$anim->toValue,
                        $t
                    ),
                ];

            case 'color':
                return [
                    'color' => Interpolator::interpolateColor(
                        (int)$anim->fromValue,
                        (int)$anim->toValue,
                        $t
                    ),
                ];

            case 'opacity':
                return [
                    'opacity' => Interpolator::lerpFloat(
                        (float)$anim->fromValue,
                        (float)$anim->toValue,
                        $t
                    ),
                ];

            case 'width':
            case 'height':
                return [
                    $anim->property => Interpolator::lerpInt(
                        (int)$anim->fromValue,
                        (int)$anim->toValue,
                        $t
                    ),
                ];

            // 光晕通道（非 CSS 属性，引擎自定义）：由 PaintPipeline
            // fragmentToElement 统一入口叠加到 shadow 族字段。
            // glowAlpha 为千分比整数（1000=不透明），守整数确定性契约。
            case 'glowAlpha':
                return [
                    'glowAlpha' => Interpolator::lerpInt(
                        (int)$anim->fromValue,
                        (int)$anim->toValue,
                        $t
                    ),
                ];

            case 'glowColor':
                return [
                    'glowColor' => Interpolator::interpolateColor(
                        (int)$anim->fromValue,
                        (int)$anim->toValue,
                        $t
                    ),
                ];

            default:
                return [];
        }
    }

    /**
     * 动画完成：将最终值写入 RenderNode.style，并清理 animatedStyle。
     */
    private function finishAnimation(Animation $anim): void
    {
        $node = $this->nodeMap[$anim->nodeId] ?? null;
        if ($node === null) {
            return;
        }

        // 将最终值写入 animatedStyle（保留 animatedStyle 作为最终状态）
        if ($node->animatedStyle === null) {
            $node->animatedStyle = [];
        }
        $node->animatedStyle[$anim->property] = $anim->toValue;

        // 清理 animatedStyle 中该属性的动画值
        if ($node->animatedStyle !== null) {
            unset($node->animatedStyle[$anim->property]);

            // 如果 animatedStyle 为空，清理整个对象
            if (empty($node->animatedStyle)) {
                $this->releaseStyleArray($node->animatedStyle);
                $node->animatedStyle = null;
                $node->isAnimating = false;
            }
        }

        // 终帧失效：动画结束后节点需重绘一次恢复基线外观
        $n = $node;
        while ($n !== null) {
            $n->paintDirty = true;
            $n = $n->parent;
        }
    }

    // ============================================================
    // 工具方法
    // ============================================================

    /**
     * 生成 RenderNode 的唯一标识。
     */
    private function getNodeId(RenderNode $node): string
    {
        return spl_object_id($node) . '-' . ($node->key ?? '');
    }

    /**
     * T4: 仅把节点加入 nodeMap（供 KeyframeResolver::tick 查找），
     * 不创建 Animation 实例。解决仅有 @keyframes 动画的节点不在
     * nodeMap 中的问题。
     */
    public function registerNodeForKeyframe(RenderNode $node): void
    {
        $nodeId = $this->getNodeId($node);
        if (!isset($this->nodeMap[$nodeId])) {
            $this->nodeMap[$nodeId] = $node;
        }
    }

    /**
     * 获取当前活跃动画数量（含 overlay 浮动项）。
     */
    public function getActiveCount(): int
    {
        return count($this->animations) + count($this->floaters);
    }
}

/**
 * Animation — 动画实例
 *
 * AOT 兼容: 使用 public 属性而非 private + getter
 */
class Animation
{
    public string $nodeId;
    public string $property;
    public mixed $fromValue;
    public mixed $toValue;
    public int $durationMs;
    public string $easing;
    public int $elapsed = 0;

    public function __construct(
        string $nodeId,
        string $property,
        mixed $fromValue,
        mixed $toValue,
        int $durationMs,
        string $easing
    ) {
        $this->nodeId = $nodeId;
        $this->property = $property;
        $this->fromValue = $fromValue;
        $this->toValue = $toValue;
        $this->durationMs = $durationMs;
        $this->easing = $easing;
    }
}
