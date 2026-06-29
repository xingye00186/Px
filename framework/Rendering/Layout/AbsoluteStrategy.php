<?php

namespace Px\Rendering\Layout;

use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;

/**
 * AbsoluteStrategy — 绝对/固定定位策略接口
 *
 * Phase 3: 签名改为使用 LayoutConstraints + ComputedStyle + FragmentBuilder。
 */
interface AbsoluteStrategy
{
    /**
     * 对单个 RenderNode 执行绝对/固定定位布局计算。
     *
     * @param RenderNode         $node        当前布局节点
     * @param LayoutConstraints  $constraints 布局约束
     * @param ComputedStyle|null $style       样式快照
     * @param FragmentBuilder    $builder     Fragment 构建器
     */
    public function resolveAbsolutePositioning(
        RenderNode         $node,
        LayoutConstraints  $constraints,
        ?ComputedStyle     $style,
        FragmentBuilder    $builder
    ): void;

    /**
     * 解析 margin:auto 居中。
     *
     * @param RenderNode    $node           当前节点
     * @param ComputedStyle $style          样式快照
     * @param int           $parentContentW 父内容区宽度
     * @param int           $parentContentH 父内容区高度
     */
    public function resolveMarginAuto(RenderNode $node, ?ComputedStyle $style, int $parentContentW, int $parentContentH = 0): void;
}
