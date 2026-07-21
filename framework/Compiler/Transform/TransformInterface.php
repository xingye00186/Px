<?php

use Px\Dom\VNode;

/**
 * TransformInterface — VNode 树变换趟的契约接口
 *
 * 每个变换趟遍历 VNode 树，分析/标注节点，将结果存入
 * 共享 metadata 数组供后续 phase（如 codegen）使用。
 *
 * 架构原则：
 *   1. transform 是 VNode 树标注的唯一途径。
 *   2. codegen 只读取 transform 设置的标注（__* 属性），不重复计算。
 *   3. 如果 codegen 需要新的标注信息，应新增 transform 趟，
 *      而不是在 codegen 中加内联 fallback。
 *   4. transform 之间通过 metadata 数组通信，不要直接读写 VNode 的 style/props。
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
