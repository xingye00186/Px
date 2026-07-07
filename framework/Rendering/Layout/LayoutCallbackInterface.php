<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\RenderNode;

/**
 * LayoutCallbackInterface — 布局策略回调接口
 *
 * 替代 \Closure::fromCallable 传递方法引用的模式。
 * AOT 编译器无法编译包含 fromCallable 的方法，会降级为 eval()。
 * 使用接口方法传递，AOT 直接编译为原生 C++ 调用。
 */
interface LayoutCallbackInterface
{
    /**
     * 重新解析子节点（多阶段布局用）。
     * 对应 LayoutResolver::reResolveChild()。
     */
    public function reResolveChild(RenderNode $child, LayoutConstraints $constraints): LayoutResult;

    /**
     * 测量节点内在尺寸（保留）。
     * 对应 LayoutResolver::measureIntrinsic()。
     */
    public function measureIntrinsic(RenderNode $node): LayoutResult;
}
