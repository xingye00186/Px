<?php

namespace Px\Layout;

use native_types;

/**
 * GridTrack — 网格轨道（行/列）数据对象
 *
 * 封装单个轨道的信息，包括原始规格、计算后尺寸、起始/结束位置。
 */
class GridTrack
{
    /** 原始规格字符串（如 "100px", "1fr", "auto", "minmax(100px,1fr)"） */
    public string $spec = '';

    /** 是否为 fr 单位 */
    public bool $isFr = false;
    /** fr 数值 */
    public float $frValue = 0;
    /** 是否为 auto */
    public bool $isAuto = false;
    /** 是否为 minmax() */
    public bool $isMinmax = false;
    /** minmax min 值（像素） */
    public int $minmaxMin = 0;
    /** minmax max fr 值 */
    public float $minmaxMaxFr = 0;

    /** 计算后的像素尺寸 */
    public int $size = 0;
    /** 起始位置（像素，相对于容器 content box） */
    public int $start = 0;
    /** 结束位置（像素） */
    public int $end = 0;

    public function __construct(string $spec = '')
    {
        $this->spec = $spec;
    }
}
