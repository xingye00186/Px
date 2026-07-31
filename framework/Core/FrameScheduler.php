<?php

namespace Px\Core;

use native_types;

use Px\Animation\AnimationManager;

/**
 * FrameScheduler — 帧调度器
 *
 * 集中管理"一帧"的时序与动画驱动，替代散落在 Application 事件循环里的
 * 即时渲染 / 空转睡眠逻辑，为 P1.3 的帧预算与后续脏区域绘制（P2.5）奠基。
 *
 * 职责：
 *   - 帧计时：计算相邻两帧的墙钟时间差（deltaMs）
 *   - 动画驱动：把 deltaMs 喂给 AnimationManager::tick()，将此前从未通电的
 *     动画子系统接上帧驱动源
 *   - 帧预算：暴露目标帧间隔（≈16ms ≈ 60fps）供事件循环节流
 *
 * 动画默认关闭（$animationEnabled=false）：
 *   保证接线本身行为等价——关闭时 driveFrame() 是空操作，不触碰任何 RenderNode。
 *   由 project.yml 的 Px_animation_enabled 开启（Application::mount 注入）。
 *
 * AOT 约束：
 *   - 墙钟时间是非确定性量，只影响动画节奏，不参与布局几何，
 *     故此处使用 microtime(true)（float）不违反"整数确定性算术契约"；
 *     deltaMs 在传给 AnimationManager::tick(int) 前显式 (int) 强转。
 *   - 无闭包、无动态调用。
 */
class FrameScheduler
{
    /** 目标帧间隔（毫秒），≈60fps */
    private int $frameIntervalMs;

    /** 动画驱动开关；默认关闭以保证行为等价 */
    private bool $animationEnabled;

    /** 上一帧的墙钟时间戳（秒）；< 0 表示尚未开始 */
    private float $lastFrameTime = -1.0;

    public function __construct(int $frameIntervalMs = 16, bool $animationEnabled = false)
    {
        $this->frameIntervalMs = $frameIntervalMs > 0 ? $frameIntervalMs : 16;
        $this->animationEnabled = $animationEnabled;
    }

    /**
     * 计算自上一帧以来的毫秒数，并推进内部时钟。
     *
     * 首帧（尚无基准）返回一个目标帧间隔，避免首帧 delta=0 或异常大。
     *
     * @return int deltaMs（>= 0）
     */
    public function computeDeltaMs(): int
    {
        $now = microtime(true);
        if ($this->lastFrameTime < 0) {
            $this->lastFrameTime = $now;
            return $this->frameIntervalMs;
        }
        $deltaMs = (int)(($now - $this->lastFrameTime) * 1000.0);
        $this->lastFrameTime = $now;
        if ($deltaMs < 0) {
            $deltaMs = 0;
        }
        return $deltaMs;
    }

    /**
     * 用显式 delta 驱动一帧动画。
     *
     * 动画关闭或无活跃动画时为空操作。
     *
     * @param int $deltaMs 距上一帧的毫秒数
     * @return bool 本帧驱动后是否仍有活跃动画（true = 需要继续请求下一帧）
     */
    public function driveFrame(int $deltaMs): bool
    {
        if (!$this->animationEnabled) {
            return false;
        }
        $manager = AnimationManager::getInstance();
        if ($manager->getActiveCount() === 0) {
            return false;
        }
        $manager->tick($deltaMs);
        return $manager->getActiveCount() > 0;
    }

    /**
     * 便捷帧推进：computeDeltaMs() + driveFrame()。
     *
     * @return bool 是否仍有活跃动画需要继续渲染
     */
    public function tick(): bool
    {
        $deltaMs = $this->computeDeltaMs();
        return $this->driveFrame($deltaMs);
    }

    /**
     * 当前是否有活跃动画（不推进时钟，供事件循环判定是否继续请求渲染）。
     */
    public function hasActiveAnimations(): bool
    {
        if (!$this->animationEnabled) {
            return false;
        }
        return AnimationManager::getInstance()->getActiveCount() > 0;
    }

    public function isAnimationEnabled(): bool
    {
        return $this->animationEnabled;
    }

    public function setAnimationEnabled(bool $enabled): void
    {
        $this->animationEnabled = $enabled;
    }

    public function getFrameIntervalMs(): int
    {
        return $this->frameIntervalMs;
    }

    /**
     * 重置帧时钟（下一次 computeDeltaMs 视为首帧）。
     */
    public function resetClock(): void
    {
        $this->lastFrameTime = -1.0;
    }
}
