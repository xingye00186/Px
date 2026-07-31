<?php

namespace Px\Animation;

use native_types;

/**
 * FloatingText — overlay 浮动文本动画项
 *
 * 对标 Flutter Overlay：飞行元素不进 RenderNode/Fragment 树
 * （PhysicalFragment 为 readonly，飞行物不应扰动布局），
 * 由 AnimationManager 持有并逐帧推进，PaintPipeline 在所有
 * layer 绘制完成后最后绘制（视觉上位于最顶层）。
 *
 * 整数确定性契约：坐标/alpha/颜色全为 int，插值经 Interpolator::lerpInt。
 * AOT 兼容：public typed 字段，无闭包。
 */
class FloatingText
{
    public string $text;
    public int $fromX;
    public int $fromY;
    public int $toX;
    public int $toY;
    public int $durationMs;
    public string $easing;
    public int $colorBgr;
    public int $fontSize;
    public int $elapsed = 0;

    // 每帧由 AnimationManager::tick 写入的当前插值状态
    public int $currentX;
    public int $currentY;
    /** 透明度千分比（1000=不透明）；文本后端无 alpha 通道时仅作完成判定用 */
    public int $alphaPermille = 1000;

    public function __construct(
        string $text,
        int $fromX,
        int $fromY,
        int $toX,
        int $toY,
        int $durationMs,
        int $colorBgr,
        int $fontSize = 22,
        string $easing = 'ease-out'
    ) {
        $this->text = $text;
        $this->fromX = $fromX;
        $this->fromY = $fromY;
        $this->toX = $toX;
        $this->toY = $toY;
        $this->durationMs = $durationMs > 0 ? $durationMs : 300;
        $this->colorBgr = $colorBgr;
        $this->fontSize = $fontSize;
        $this->easing = $easing;
        $this->currentX = $fromX;
        $this->currentY = $fromY;
    }
}
