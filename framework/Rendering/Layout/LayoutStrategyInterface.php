<?php

namespace Px\Rendering\Layout;

use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;

/**
 * LayoutStrategyInterface — 布局策略接口
 *
 * 所有布局策略（Block、Flex、Grid）实现此接口，
 * LayoutResolver 通过接口调用，而非依赖具体策略类。
 *
 * Phase 3 终态：唯一协议为 resolveWithBuilder。
 */
interface LayoutStrategyInterface
{
    /**
     * Phase 3 终态协议：使用 Constraints + ComputedStyle + FragmentBuilder。
     *
     * @param RenderNode         $node        当前布局节点
     * @param LayoutConstraints  $constraints 布局约束
     * @param ComputedStyle|null $style       计算样式
     * @param FragmentBuilder    $builder     片段构建器
     */
    public function resolveWithBuilder(
        RenderNode         $node,
        LayoutConstraints  $constraints,
        ?ComputedStyle     $style,
        FragmentBuilder    $builder
    ): void;
}
