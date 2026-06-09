<?php

namespace Px\Rendering\Layout;

use Px\Rendering\RenderNode;

/**
 * AbsoluteStrategy — 绝对/固定定位策略接口
 *
 * 封装 position:absolute/fixed 的布局计算。
 * LayoutResolver 通过此接口调用，而非依赖具体实现类。
 */
interface AbsoluteStrategy
{
    /**
     * 对单个 RenderNode 执行绝对/固定定位布局计算。
     *
     * @param RenderNode    $node   当前布局节点（mutated in-place）
     * @param LayoutContext $ctx    布局上下文
     * @param array         $style  合并后的 effective style
     */
    public function resolveAbsolutePositioning(
        RenderNode    $node,
        LayoutContext $ctx,
        array         $style
    ): void;

    /**
     * 解析 margin:auto 居中。
     * 在绝对定位和 normal flow 中均可能使用。
     *
     * @param RenderNode $node           当前节点
     * @param array      $style          样式数组
     * @param int        $parentContentW 父内容区宽度
     * @param int        $parentContentH 父内容区高度（可选，用于垂直居中）
     */
    public function resolveMarginAuto(RenderNode $node, array $style, int $parentContentW, int $parentContentH = 0): void;
}
