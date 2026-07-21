<?php

use Px\Dom\VNode;

/**
 * TransformInterface — VNode 树变换趟的契约接口
 *
 * 每个变换趟遍历 VNode 树，分析/标注节点，将结果存入
 * 共享 metadata 数组供后续 phase（如 codegen）使用。
 */
interface TransformInterface
{
    /**
     * 对 VNode 树执行一趟变换。
     *
     * @param VNode $root       VNode 树根节点（原地修改）
     * @param array &$metadata  共享元数据容器（各趟间传递）
     */
    public function transform(VNode $root, array &$metadata): void;
}
