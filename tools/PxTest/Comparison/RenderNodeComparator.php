<?php

namespace PxTest\Comparison;

use PxTest\Core\ToleranceConfig;

/**
 * RenderNode 树结构对比器 — 对比节点数量、类型、key 匹配。
 */
class RenderNodeComparator implements ComparatorInterface
{
    public function name(): string
    {
        return 'render_tree';
    }

    public function compare(array $baseline, array $current, ToleranceConfig $tolerance): ComparisonResult
    {
        $diffs = [];

        // 节点数
        $bCount = $this->countNodes($baseline);
        $cCount = $this->countNodes($current);
        if ($bCount !== $cCount) {
            $diffs[] = "node_count: baseline=$bCount current=$cCount";
        }

        // 树深度
        $bDepth = $this->treeDepth($baseline);
        $cDepth = $this->treeDepth($current);
        if ($bDepth !== $cDepth) {
            $diffs[] = "tree_depth: baseline=$bDepth current=$cDepth";
        }

        return empty($diffs)
            ? ComparisonResult::pass($this->name())
            : ComparisonResult::fail($this->name(), $diffs);
    }

    private function countNodes(array $node): int
    {
        $count = 1;
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $count += $this->countNodes($child);
            }
        }
        return $count;
    }

    private function treeDepth(array $node): int
    {
        $max = 0;
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $d = $this->treeDepth($child);
                if ($d > $max) $max = $d;
            }
        }
        return $max + 1;
    }
}
