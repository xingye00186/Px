<?php

use Px\Dom\VNode;

/**
 * TransformPipeline — VNode 变换管线编排器
 *
 * 按注册顺序运行一组 TransformInterface 实现，
 * 每趟共享同一个 metadata 容器，前一趟的输出作为后一趟的输入。
 */
class TransformPipeline
{
    /** @var TransformInterface[] */
    private array $transforms = [];

    /** @var array 共享元数据容器 */
    private array $metadata = [];

    /**
     * 注册一个变换趟。
     */
    public function add(TransformInterface $transform): void
    {
        $this->transforms[] = $transform;
    }

    /**
     * 在 VNode 树上运行所有已注册的变换趟。
     *
     * @param VNode $root  VNode 树根节点
     * @return array       汇总后的共享元数据
     */
    public function run(VNode $root): array
    {
        $this->metadata = [];
        foreach ($this->transforms as $transform) {
            $transform->transform($root, $this->metadata);
        }
        return $this->metadata;
    }

    /**
     * 获取已注册的 transform 数量。
     */
    public function count(): int
    {
        return count($this->transforms);
    }
}
