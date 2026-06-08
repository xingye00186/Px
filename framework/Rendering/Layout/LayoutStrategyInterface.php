<?php

namespace Px\Rendering\Layout;

use Px\Rendering\RenderNode;

/**
 * LayoutStrategyInterface — 布局策略接口
 *
 * 所有布局策略（Block、Flex、Grid）实现此接口，
 * LayoutResolver 通过接口调用，而非依赖具体策略类。
 */
interface LayoutStrategyInterface
{
    /**
     * 对单个 RenderNode 执行布局计算。
     *
     * @param RenderNode  $node            当前布局节点（mutated in-place）
     * @param int         $parentX         父节点左上角 x
     * @param int         $parentY         父节点左上角 y
     * @param RenderNode|null $parent      父节点（根节点为 null）
     * @param array       &$scrollContainers  滚动容器引用收集
     * @param array       $style           合并后的 effective style
     */
    public function resolve(
        RenderNode  $node,
        int         $parentX,
        int         $parentY,
        ?RenderNode $parent,
        array       &$scrollContainers,
        array       $style
    ): void;
}
