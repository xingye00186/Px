<?php

namespace Px\Rendering\Layout;

use native_types;
use Px\Rendering\RenderNode;
use Px\Rendering\ComputedStyle;

/**
 * StickyPostProcessor — position:sticky 后处理器
 *
 * 在布局计算完成后，对 position:sticky 节点应用粘性定位效果。
 * 支持垂直和水平方向的 sticky，多滚动容器嵌套，以及粘性堆叠。
 *
 * 使用方式：
 *   $processor = new StickyPostProcessor($scrollContainers);
 *   $processor->process($node, $style);
 *
 * stickyStack/stickyStackX 在同一个 LayoutResolver::resolve() 调用期间保持，
 * 每次 resolve() 开始时调用 reset() 清空。
 */
class StickyPostProcessor
{
    /** @var RenderNode[] 当前布局树中的所有滚动容器 */
    private array $scrollContainers;

    /** @var array<string, array> 垂直粘性堆叠追踪 */
    private array $stickyStack = [];

    /** @var array<string, array> 水平粘性堆叠追踪 */
    private array $stickyStackX = [];

    public function __construct(array &$scrollContainers)
    {
        $this->scrollContainers = &$scrollContainers;
    }

    public function reset(): void
    {
        $this->stickyStack = [];
        $this->stickyStackX = [];
    }

    /**
     * 对单个 position:sticky 节点应用粘性定位。
     *
     * @param RenderNode    $node  sticky 节点
     * @param ComputedStyle $style 节点的计算样式
     */
    public function process(RenderNode $node, ComputedStyle $style): void
    {
        $stickyTop = (int)($style->top?->toPx() ?? 0);

        // 查找最近的包含此节点的滚动容器
        for ($i = count($this->scrollContainers) - 1; $i >= 0; $i--) {
            $sc = $this->scrollContainers[$i];
            if ($node->x >= $sc->x && $node->x < $sc->x + $sc->w &&
                $node->y >= $sc->y && $node->y < $sc->y + $sc->h) {

                $scKey = $sc->groupId . ':' . $i;
                $this->applyVerticalSticky($node, $sc, $scKey, $stickyTop);
                $this->applyHorizontalSticky($node, $sc, $scKey, $style);
                break;
            }
        }
    }

    private function applyVerticalSticky(RenderNode $node, RenderNode $sc, string $scKey, int $stickyTop): void
    {
        $visualY = $node->y - $sc->scrollTop;
        if (!isset($this->stickyStack[$scKey])) {
            $this->stickyStack[$scKey] = [];
        }

        $baseStuckY = $sc->y + $stickyTop;
        $adjustedStuckY = $baseStuckY;
        foreach ($this->stickyStack[$scKey] as $prev) {
            $adjustedStuckY = (int)max($adjustedStuckY, $prev['stuckY'] + $prev['height']);
        }

        if ($visualY < $adjustedStuckY) {
            $dy = $adjustedStuckY - $visualY;
            $node->y = $adjustedStuckY + $sc->scrollTop;
            foreach ($node->children as $child) {
                $child->y += $dy;
            }
            $this->stickyStack[$scKey][] = [
                'stuckY' => $adjustedStuckY,
                'height' => $node->h,
            ];
        }
    }

    private function applyHorizontalSticky(RenderNode $node, RenderNode $sc, string $scKey, ComputedStyle $style): void
    {
        $stickyLeft = (int)($style->left?->toPx() ?? 0);
        if ($stickyLeft === 0) return;

        $visualX = $node->x - $sc->scrollLeft;
        if (!isset($this->stickyStackX[$scKey])) {
            $this->stickyStackX[$scKey] = [];
        }

        $baseStuckX = $sc->x + $stickyLeft;
        $adjustedStuckX = $baseStuckX;
        foreach ($this->stickyStackX[$scKey] as $prev) {
            $adjustedStuckX = (int)max($adjustedStuckX, $prev['stuckX'] + $prev['width']);
        }

        if ($visualX < $adjustedStuckX) {
            $dx = $adjustedStuckX - $visualX;
            $node->x = $adjustedStuckX + $sc->scrollLeft;
            foreach ($node->children as $child) {
                $child->x += $dx;
            }
            $this->stickyStackX[$scKey][] = [
                'stuckX' => $adjustedStuckX,
                'width' => $node->w,
            ];
        }
    }
}
