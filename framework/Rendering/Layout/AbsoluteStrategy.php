<?php

namespace Px\Rendering\Layout;

/**
 * AbsoluteStrategy — 绝对/固定定位策略接口
 *
 * CSS Positioned Layout Module Level 3:
 * - position:absolute 的 containing block = 最近定位祖先的 padding box
 * - position:fixed 的 containing block = viewport (0,0)
 *
 * 终态：唯一协议为 absoluteLayout(LayoutInput): LayoutResult。
 *
 * AOT 兼容：接口方法返回类型明确，无引用参数。
 */
interface AbsoluteStrategy
{
    /**
     * 纯函数绝对定位布局。
     *
     * 通过 LayoutInput 接收定位祖先坐标和 viewport 尺寸，
     * 返回绝对定位节点的不可变布局结果。
     * 不接收 RenderNode 引用，不产生副作用。
     *
     * @param LayoutInput $input 纯函数输入（含定位祖先信息）
     * @return LayoutResult 不可变布局输出
     */
    public function absoluteLayout(LayoutInput $input): LayoutResult;
}
