<?php

namespace Px\Rendering\Layout;

/**
 * LayoutStrategyInterface — 布局策略接口
 *
 * 所有布局策略（Block、Flex、Grid）实现此接口。
 * LayoutResolver 通过接口调用，而非依赖具体策略类。
 *
 * 终态：唯一协议为 layout(LayoutInput): LayoutResult。
 *
 * AOT 兼容：接口方法返回类型明确，无引用参数。
 */
interface LayoutStrategyInterface
{
    /**
     * 纯函数布局：输入 LayoutInput → 输出 LayoutResult。
     *
     * 接收约束、样式、子结果（已由上一级递归计算），
     * 返回自身节点的不可变布局结果。
     * 不接收任何 RenderNode 引用，不产生副作用。
     *
     * @param LayoutInput $input 纯函数输入（含约束、样式、子结果）
     * @return LayoutResult 不可变布局输出
     */
    public function layout(LayoutInput $input): LayoutResult;
}
