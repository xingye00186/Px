<?php

use Px\Dom\VNode;

/**
 * VForHelperGenerator — v-for 辅助方法代码生成器
 *
 * 生成 render_N() 辅助方法。
 * 当前委托给 sfc-compiler.php 的 generateVForHelpers() 实现。
 */
class VForHelperGenerator
{
    /**
     * 生成所有 v-for 辅助方法。
     *
     * @param array $loops [name => [...]] 由 collectVForLoops 收集
     * @param StaticNodeContext|null &$ctx 静态 VNode 上下文
     * @return string PHP 代码
     */
    public function generateHelpers(array $loops, ?StaticNodeContext &$ctx = null): string
    {
        return generateVForHelpers($loops, $ctx);
    }
}
