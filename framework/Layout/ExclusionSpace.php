<?php

namespace Px\Layout;

use native_types;

/**
 * ExclusionSpace — 浮动排除区域（对标 Blink NGExclusionSpace）
 *
 * 管理当前 BFC 内所有浮动元素占据的矩形区域。
 * Block/Inline 布局时查询 ExclusionSpace 以获取给定 Y 位置的可用宽度区间。
 *
 * CSS 2.2 §9.5:
 *   - float:left 元素从左侧占据空间，后续内容从其右边缘开始
 *   - float:right 元素从右侧占据空间，后续内容到其左边缘截止
 *   - clear:left/right/both 使元素移动到指定侧浮动元素的下方
 *
 * AOT 安全：use native_types + 纯数组操作。
 */
class ExclusionSpace
{
    /** @var array{x:int, y:int, w:int, h:int, side:string}[] 左浮动排除区 */
    private array $leftFloats = [];

    /** @var array{x:int, y:int, w:int, h:int, side:string}[] 右浮动排除区 */
    private array $rightFloats = [];

    /** 容器宽度（用于计算 right float 位置） */
    private int $containerWidth;

    public function __construct(int $containerWidth)
    {
        $this->containerWidth = $containerWidth;
    }

    /**
     * 添加一个浮动排除区域。
     *
     * @param string $side 'left' 或 'right'
     * @param int $x 排除区 X 坐标
     * @param int $y 排除区 Y 坐标
     * @param int $w 排除区宽度
     * @param int $h 排除区高度
     */
    public function addFloat(string $side, int $x, int $y, int $w, int $h): void
    {
        $rect = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'side' => $side];
        if ($side === 'left') {
            $this->leftFloats[] = $rect;
        } else {
            $this->rightFloats[] = $rect;
        }
    }

    /**
     * 获取给定 Y 位置的可用宽度区间。
     *
     * 对标 Blink NGExclusionSpace::FindLayoutOpportunity:
     * 在指定 Y 坐标处，返回不被浮动元素占据的水平区间 [leftEdge, rightEdge]。
     *
     * @param int $y 当前 Y 坐标
     * @param int $height 查询的高度范围
     * @return array{left: int, right: int} 可用区间
     */
    public function findAvailableSpace(int $y, int $height = 0): array
    {
        $leftEdge = 0;
        $rightEdge = $this->containerWidth;

        // 左浮动：右边缘 = max(所有在 y 范围内的左浮动的右边缘)
        foreach ($this->leftFloats as $f) {
            if ($y + $height > $f['y'] && $y < $f['y'] + $f['h']) {
                $fRight = $f['x'] + $f['w'];
                if ($fRight > $leftEdge) $leftEdge = $fRight;
            }
        }

        // 右浮动：左边缘 = min(所有在 y 范围内的右浮动的左边缘)
        foreach ($this->rightFloats as $f) {
            if ($y + $height > $f['y'] && $y < $f['y'] + $f['h']) {
                if ($f['x'] < $rightEdge) $rightEdge = $f['x'];
            }
        }

        return ['left' => $leftEdge, 'right' => $rightEdge];
    }

    /**
     * 获取 clear 后的 Y 位置。
     *
     * CSS 2.2 §9.5.2: clear 使元素的顶 margin edge 在指定侧浮动的底 margin edge 之下。
     *
     * @param string $clear 'left' | 'right' | 'both'
     * @param int $currentY 当前 Y 位置
     * @return int clear 后的 Y 位置
     */
    public function getClearY(string $clear, int $currentY): int
    {
        $clearY = $currentY;

        if ($clear === 'left' || $clear === 'both') {
            foreach ($this->leftFloats as $f) {
                $bottom = $f['y'] + $f['h'];
                if ($bottom > $clearY) $clearY = $bottom;
            }
        }

        if ($clear === 'right' || $clear === 'both') {
            foreach ($this->rightFloats as $f) {
                $bottom = $f['y'] + $f['h'];
                if ($bottom > $clearY) $clearY = $bottom;
            }
        }

        return $clearY;
    }

    /**
     * 计算下一个浮动元素的放置位置。
     *
     * 对标 Blink NGExclusionSpace::FindLayoutOpportunity:
     * 浮动元素放置规则（CSS 2.2 §9.5.1）：
     *   - float:left → 从当前行最左侧可用位置放置
     *   - float:right → 从当前行最右侧可用位置放置
     *   - 不能超出容器边界
     *   - 不能与已有同侧浮动重叠
     *
     * @param string $side 'left' 或 'right'
     * @param int $floatW 浮动元素宽度
     * @param int $floatH 浮动元素高度
     * @param int $currentY 当前 Y 位置（float 不能出现在此之上）
     * @return array{x: int, y: int} 放置坐标
     */
    public function placeFloat(string $side, int $floatW, int $floatH, int $currentY): array
    {
        $y = $currentY;

        // 向下搜索直到找到足够宽的位置
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $avail = $this->findAvailableSpace($y, $floatH);
            $availW = $avail['right'] - $avail['left'];

            if ($availW >= $floatW) {
                if ($side === 'left') {
                    return ['x' => $avail['left'], 'y' => $y];
                } else {
                    return ['x' => $avail['right'] - $floatW, 'y' => $y];
                }
            }

            // 空间不够：移到下一个浮动底部之下
            $nextY = PHP_INT_MAX;
            foreach ($this->leftFloats as $f) {
                $bottom = $f['y'] + $f['h'];
                if ($bottom > $y && $bottom < $nextY) $nextY = $bottom;
            }
            foreach ($this->rightFloats as $f) {
                $bottom = $f['y'] + $f['h'];
                if ($bottom > $y && $bottom < $nextY) $nextY = $bottom;
            }
            if ($nextY === PHP_INT_MAX) break;
            $y = $nextY;
        }

        // 最终 fallback
        if ($side === 'left') {
            return ['x' => 0, 'y' => $y];
        }
        return ['x' => max(0, $this->containerWidth - $floatW), 'y' => $y];
    }

    /** 是否为空（无浮动） */
    public function isEmpty(): bool
    {
        return empty($this->leftFloats) && empty($this->rightFloats);
    }
}
