<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\RenderNode;

/**
 * LayoutContext — 布局上下文值对象
 *
 * 封装布局算法所需的上下文信息，减少 LayoutStrategyInterface
 * 的参数数量，遵循接口隔离原则（ISP）。
 *
 * 包含：父节点坐标、父节点引用。
 * 由 LayoutResolver 在每次 resolveNode 调用时创建并传递。
 * scrollContainers 聚合由 LayoutResolver 自身维护，不在此传递。
 */
class LayoutContext
{
    /** 父节点内容区左上角 x（已包含父 padding） */
    public int $parentX = 0;

    /** 父节点内容区左上角 y（已包含父 padding） */
    public int $parentY = 0;

    /** 父 RenderNode（根节点为 null） */
    public ?RenderNode $parent;

    public function __construct(
        int         $parentX,
        int         $parentY,
        ?RenderNode $parent
    ) {
        $this->parentX = $parentX;
        $this->parentY = $parentY;
        $this->parent = $parent;
    }
}
