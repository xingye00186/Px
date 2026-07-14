<?php

namespace Px\Animation;

use Px\Render\RenderNode;

/**
 * TransitionController — 过渡控制器
 *
 * 管理单个元素/组件的进入/离开过渡状态。
 * 与 Vue 3 的 <Transition> 组件对齐：
 * - 维护 CSS 类名（enter-* / leave-*）
 * - 提供 enter/leave 生命周期钩子
 * - 驱动 CSS transition
 *
 * AOT 兼容: 使用 public 属性，不使用闭包
 */
class TransitionController
{
    // 过渡状态
    public const STATE_IDLE = 'idle';
    public const STATE_ENTERING = 'entering';
    public const STATE_ENTERED = 'entered';
    public const STATE_LEAVING = 'leaving';
    public const STATE_LEFT = 'left';

    /** @var string 当前状态 */
    public string $state = 'idle';

    /** @var RenderNode|null 关联的 RenderNode */
    public ?RenderNode $node = null;

    /** @var string CSS 类名前缀（默认为 'v'） */
    public string $name = 'v';

    /** @var int 进入动画时长（毫秒） */
    public int $enterDuration = 300;

    /** @var int 离开动画时长（毫秒） */
    public int $leaveDuration = 300;

    /** @var string 进入缓动函数 */
    public string $enterEasing = 'ease';

    /** @var string 离开缓动函数 */
    public string $leaveEasing = 'ease';

    /** @var string 当前应用的 CSS 类名 */
    public string $currentClass = '';

    /**
     * 创建过渡控制器。
     *
     * @param RenderNode|null $node    关联的 RenderNode
     * @param string $name             CSS 类名前缀
     */
    public function __construct(?RenderNode $node = null, string $name = 'v')
    {
        $this->node = $node;
        $this->name = $name;
    }

    // ============================================================
    // 状态切换
    // ============================================================

    /**
     * 开始进入过渡。
     *
     * @param int|null $duration  动画时长（毫秒），null 则使用默认值
     */
    public function enter(?int $duration = null): void
    {
        if ($this->state === self::STATE_ENTERING || $this->state === self::STATE_ENTERED) {
            return; // 已在进入中或已进入
        }

        $this->state = self::STATE_ENTERING;
        $this->currentClass = $this->name . '-enter-active';
        $this->applyClass();

        $this->scheduleEnterDone($duration ?? $this->enterDuration);
    }

    /**
     * 开始离开过渡。
     *
     * @param int|null $duration  动画时长（毫秒），null 则使用默认值
     */
    public function leave(?int $duration = null): void
    {
        if ($this->state === self::STATE_LEAVING || $this->state === self::STATE_LEFT) {
            return; // 已在离开中或已离开
        }

        $this->state = self::STATE_LEAVING;
        $this->currentClass = $this->name . '-leave-active';
        $this->applyClass();

        $this->scheduleLeaveDone($duration ?? $this->leaveDuration);
    }

    /**
     * 强制进入完成（用于非过渡模式）。
     */
    public function forceEnter(): void
    {
        $this->state = self::STATE_ENTERED;
        $this->currentClass = '';
        $this->applyClass();
    }

    /**
     * 强制离开完成（用于非过渡模式）。
     */
    public function forceLeave(): void
    {
        $this->state = self::STATE_LEFT;
        $this->currentClass = '';
        $this->applyClass();
    }

    // ============================================================
    // 内部调度
    // ============================================================

    /**
     * 调度进入完成回调。
     */
    private function scheduleEnterDone(int $durationMs): void
    {
        // 注意：这里需要与 Application 的事件循环集成
        // 实际调度由 AnimationManager 或 Application 处理
        // 此处仅记录状态，实际的状态转换由 tick() 触发
    }

    /**
     * 调度离开完成回调。
     */
    private function scheduleLeaveDone(int $durationMs): void
    {
    }

    /**
     * 标记进入完成。
     * 由 AnimationManager 在动画结束时调用。
     */
    public function markEnterDone(): void
    {
        $this->state = self::STATE_ENTERED;
        $this->currentClass = '';
        $this->applyClass();
    }

    /**
     * 标记离开完成。
     * 由 AnimationManager 在动画结束时调用。
     */
    public function markLeaveDone(): void
    {
        $this->state = self::STATE_LEFT;
        $this->currentClass = '';
        $this->applyClass();
    }

    // ============================================================
    // CSS 类名管理
    // ============================================================

    /**
     * 应用当前 CSS 类名到 RenderNode。
     */
    private function applyClass(): void
    {
        if ($this->node === null) {
            return;
        }

        // 将 CSS 类名写入 props
        $existingClass = $this->node->props['class'] ?? '';
        if ($this->currentClass !== '') {
            $this->node->props['class'] = $existingClass . ' ' . $this->currentClass;
        }
    }

    // ============================================================
    // 查询
    // ============================================================

    /**
     * 是否正在过渡中。
     */
    public function isTransitioning(): bool
    {
        return $this->state === self::STATE_ENTERING || $this->state === self::STATE_LEAVING;
    }

    /**
     * 是否已完成进入。
     */
    public function isEntered(): bool
    {
        return $this->state === self::STATE_ENTERED;
    }

    /**
     * 是否已离开。
     */
    public function isLeft(): bool
    {
        return $this->state === self::STATE_LEFT;
    }

    /**
     * 是否可见（可用于 visibility 控制）。
     */
    public function isVisible(): bool
    {
        return $this->state !== self::STATE_LEFT;
    }
}
