<?php

namespace Px\Rendering\Layout\Grid;

use native_types;
use Px\Rendering\RenderNode;

/**
 * GridItem — 网格子项数据对象
 *
 * 封装 grid 子项的放置信息（列/行跨度）和计算结果坐标。
 */
class GridItem
{
    /** 来源 RenderNode */
    public RenderNode $node;

    // ── 放置信息 ──
    public int $colStart = 0;
    public int $colEnd = 0;  // 列跨度实际结束索引（最后一个轨道索引 + 1）
    public int $rowStart = 0;
    public int $rowEnd = 0;  // 行跨度实际结束索引

    // ── 计算结果 ──
    public int $x = 0;
    public int $y = 0;
    public int $w = 0;
    public int $h = 0;
    public int $visualW = 0;
    public int $visualH = 0;

    public function __construct(RenderNode $node)
    {
        $this->node = $node;
    }
}
