<?php

namespace Px\Layout;

use native_types;

/**
 * GridItem — 网格子项数据对象
 */
class GridItem
{
    public int $colStart = 0;
    public int $colEnd = 0;
    public int $rowStart = 0;
    public int $rowEnd = 0;
    public int $x = 0;
    public int $y = 0;
    public int $w = 0;
    public int $h = 0;
    public int $visualW = 0;
    public int $visualH = 0;
    public ?object $style = null;
    public ?array $originalChildren = null;
}
