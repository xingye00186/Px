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
     * @param RenderNode $node   当前布局节点
     * @param object     $ctx    布局上下文（父坐标、父引用）
     * @param array      $style  合并后的 effective style
     */
    public function resolve(
        RenderNode    $node,
        object        $ctx,
        array         $style
    ): void;
}
