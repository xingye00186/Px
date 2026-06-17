<?php

namespace PxTest\Baseline;

use PxTest\Layout\RenderNodeSerializer;
use Px\Rendering\RenderNode;

/**
 * 基线归档器 — 将当前布局冻结为基线快照。
 */
class BaselineArchive
{
    private BaselineRegistry $registry;
    private RenderNodeSerializer $serializer;

    public function __construct(BaselineRegistry $registry)
    {
        $this->registry = $registry;
        $this->serializer = new RenderNodeSerializer();
    }

    /** 归档一个 case 的当前布局 */
    public function archive(string $caseName, RenderNode $rootNode, int $frameCount = 5): void
    {
        $json = $this->serializer->toJson($rootNode);
        $this->registry->archive($caseName, [
            'md5' => md5($json),
            'frames' => $frameCount,
            'node_count' => $this->countNodes($rootNode),
        ]);
    }

    private function countNodes(RenderNode $node): int
    {
        $count = 1;
        foreach ($node->children as $child) $count += $this->countNodes($child);
        return $count;
    }
}
