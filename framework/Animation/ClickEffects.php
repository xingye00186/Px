<?php

namespace Px\Animation;

use native_types;

use Px\Render\RenderNode;
use Px\Layout\PhysicalFragment;

/**
 * ClickEffects — 点击特效触发器（B1 垂直切片的应用层入口）
 *
 * 由 Application 在 @click 派发后调用（配置门 Px_anim_click_effects，
 * 且依赖 Px_animation_enabled）：
 *   1. 光晕：命中节点上注册 glowColor(常量) + glowAlpha(900→0) 过渡，
 *      PaintPipeline 统一消费点叠加到 shadow 族 → 彩色光晕渐隐；
 *   2. 飞升：命中按钮的标签文本作为 FloatingText，从按钮中心飞向
 *      fly-to 目标（按 bind 键在 RenderNode 树中定位，如 'display'）。
 *
 * 整数确定性契约：彩虹色 HSV→BGR 全整数扇区算法，坐标全 int。
 * AOT 兼容：静态方法、无闭包、无动态调用。
 */
final class ClickEffects
{
    private const GLOW_DURATION_MS = 400;
    private const FLY_DURATION_MS = 450;

    /**
     * 触发点击特效。几何一律取自绘制同源 Fragment（对标 Blink，
     * RenderNode::cachedFragment 双槽 MRU 下不可依赖）：
     * $hitFrag = 命中按钮的 Fragment（RTM::getLastHitFragment）；
     * $targetFrag = 飞升目标 Fragment（RTM::findFragmentByBind），null = 仅光晕。
     */
    public static function trigger(RenderNode $hit, ?PhysicalFragment $hitFrag, ?PhysicalFragment $targetFrag): void
    {
        $manager = AnimationManager::getInstance();

        // ── 1. 随机彩虹色光晕渐隐 ──
        $color = self::rainbowBgr(mt_rand(0, 359));
        $manager->addTransition($hit, 'glowColor', $color, $color, self::GLOW_DURATION_MS, 'linear');
        $manager->addTransition($hit, 'glowAlpha', 900, 0, self::GLOW_DURATION_MS, 'ease-out');
        // 关键：立即写入首帧值——addTransition 只注册不执行首帧计算，
        // 若不写，紧接的 full render 见到 animatedStyle=null 不消费，
        // 用户的第一视觉帧无光晕（低频点击时“基本没效果”）。
        $hit->animatedStyle = $hit->animatedStyle ?? [];
        $hit->animatedStyle['glowColor'] = $color;
        $hit->animatedStyle['glowAlpha'] = 900;
        $hit->isAnimating = true;

        // ── 2. 数字飞升到目标（无目标/无几何则只做光晕）──
        if ($hitFrag === null || $targetFrag === null) {
            return;
        }
        $label = self::extractLabel($hit);
        if ($label === '') {
            return;
        }
        $fromX = (int)$hitFrag->x + intdiv((int)$hitFrag->w, 2) - 8;
        $fromY = (int)$hitFrag->y + intdiv((int)$hitFrag->h, 2) - 12;
        // 目标：显示器文本右端（display 数字右对齐），略向内收
        $toX = (int)$targetFrag->x + (int)$targetFrag->w - 32;
        $toY = (int)$targetFrag->y + intdiv((int)$targetFrag->h, 2) - 12;
        $manager->addFloatingText(new FloatingText(
            $label, $fromX, $fromY, $toX, $toY,
            self::FLY_DURATION_MS, $color, 24, 'ease-out'
        ));
    }

    /**
     * 整数扇区 HSV(h,1,1)→BGR。hue 0..359，输出 0xBBGGRR。
     */
    public static function rainbowBgr(int $hue): int
    {
        $hue = (($hue % 360) + 360) % 360;
        $f = $hue % 60;
        $rising  = intdiv(255 * $f, 60);
        $falling = 255 - $rising;
        $sector = intdiv($hue, 60);
        $r = 0; $g = 0; $b = 0;
        if ($sector === 0)      { $r = 255;      $g = $rising;  $b = 0; }
        elseif ($sector === 1)  { $r = $falling; $g = 255;      $b = 0; }
        elseif ($sector === 2)  { $r = 0;        $g = 255;      $b = $rising; }
        elseif ($sector === 3)  { $r = 0;        $g = $falling; $b = 255; }
        elseif ($sector === 4)  { $r = $rising;  $g = 0;        $b = 255; }
        else                    { $r = 255;      $g = 0;        $b = $falling; }
        return ($b << 16) | ($g << 8) | $r;
    }

    /**
     * 提取按钮标签（对齐 PaintPipeline::makeButtonElement 的取值序）。
     */
    private static function extractLabel(RenderNode $node): string
    {
        if ($node->content !== null && (string)$node->content !== '') {
            return (string)$node->content;
        }
        foreach ($node->children as $childRaw) {
            $child = objval($childRaw, RenderNode::class);
            if ($child->content !== null && (string)$child->content !== '') {
                return (string)$child->content;
            }
        }
        return '';
    }
}
