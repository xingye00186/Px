<?php

namespace Px\Rendering\Layout\Flex;

use native_types;

/**
 * FlexLine — Flex 行数据对象
 *
 * 由 FlexLineBreaker 生成，FlexDistributor 消费。
 * 每行包含一组 FlexItem，以及该行的聚合尺寸。
 */
class FlexLine
{
    /** @var FlexItem[] 该行的 flex 子项 */
    public array $items = [];

    /** 行内子项主轴总尺寸（不含 gap） */
    public int $totalMain = 0;

    /** 行内最大交叉轴尺寸 */
    public int $maxCross = 0;

    /** 行在交叉轴方向的偏移（由 align-content 决定） */
    public int $crossOffset = 0;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }
}
