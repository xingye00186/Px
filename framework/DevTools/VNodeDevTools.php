<?php
/**
 * VNode DevTools - 用于调试 VNode 树结构和渲染状态
 * 在开发模式下运行应用时自动生成调试报告
 */

namespace Px\DevTools;

use Px\Dom\VNode;

class VNodeDevTools
{
    private string $reportPath;
    private array $snapshots = [];

    public function __construct(string $reportPath = './vnode-debug.json')
    {
        $this->reportPath = $reportPath;
    }

    /**
     * 快照当前 VNode 树
     */
    public function snapshot(string $label, VNode $root): void
    {
        $this->snapshots[$label] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'tree' => $this->serializeTree($root),
            'summary' => $this->summarizeTree($root),
        ];
    }

    /**
     * 将完整树结构序列化为数组
     */
    private function serializeTree(VNode $node, int $depth = 0): array
    {
        if ($depth > 10) {
            return ['_truncated' => true];
        }

        $result = [
            'type' => $node->type,
            'key' => $node->key,
            'groupId' => $node->groupId,
            'isComponent' => $node->isComponent,
        ];

        // Props (排除大型数据)
        if ($node->props !== null) {
            $props = [];
            foreach ($node->props as $k => $v) {
                if (is_string($v) && strlen($v) > 100) {
                    $props[$k] = substr($v, 0, 50) . '...';
                } else {
                    $props[$k] = $v;
                }
            }
            $result['props'] = $props;
        }

        // Component info
        if ($node->isComponent) {
            $result['component'] = [
                'class' => $node->componentClass,
            ];
        }

        // Children
        if ($node->children instanceof VNode) {
            $result['children'] = [$this->serializeTree($node->children, $depth + 1)];
        } elseif (is_array($node->children)) {
            $result['children'] = array_map(
                fn($child) => $child instanceof VNode ? $this->serializeTree($child, $depth + 1) : ['_text' => $child],
                $node->children
            );
        } elseif (is_string($node->children)) {
            $text = $node->children;
            if (strlen($text) > 100) {
                $text = substr($text, 0, 100) . '...';
            }
            $result['text'] = $text;
        }

        return $result;
    }

    /**
     * 生成树的统计摘要
     */
    private function summarizeTree(VNode $node): array
    {
        $stats = [
            'total' => 0,
            'byType' => [],
            'components' => 0,
            'withText' => 0,
        ];

        $this->collectStats($node, $stats);

        return $stats;
    }

    private function collectStats(VNode $node, array &$stats): void
    {
        $stats['total']++;
        $stats['byType'][$node->type] = ($stats['byType'][$node->type] ?? 0) + 1;

        if ($node->isComponent) {
            $stats['components']++;
        }
        if (is_string($node->children) && trim($node->children) !== '') {
            $stats['withText']++;
        }

        if ($node->children instanceof VNode) {
            $this->collectStats($node->children, $stats);
        } elseif (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $this->collectStats($child, $stats);
                }
            }
        }
    }

    /**
     * 生成人类可读的调试报告
     */
    public function generateReport(): string
    {
        $report = [
            'generated' => date('Y-m-d H:i:s'),
            'snapshots' => $this->snapshots,
            'comparison' => $this->generateComparison(),
        ];

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 生成快照之间的对比
     */
    private function generateComparison(): array
    {
        $keys = array_keys($this->snapshots);
        if (count($keys) < 2) {
            return [];
        }

        $comparison = [];
        for ($i = 1; $i < count($keys); $i++) {
            $prev = $this->snapshots[$keys[$i - 1]];
            $curr = $this->snapshots[$keys[$i]];

            $comparison["{$keys[$i-1]} -> {$keys[$i]}"] = [
                'prevSummary' => $prev['summary'],
                'currSummary' => $curr['summary'],
                'changes' => $this->diffSummaries($prev['summary'], $curr['summary']),
            ];
        }

        return $comparison;
    }

    private function diffSummaries(array $prev, array $curr): array
    {
        $changes = [];

        foreach ($prev['byType'] as $type => $count) {
            $currCount = $curr['byType'][$type] ?? 0;
            if ($currCount !== $count) {
                $changes["type_$type"] = ['prev' => $count, 'curr' => $currCount];
            }
        }

        foreach ($curr['byType'] as $type => $count) {
            if (!isset($prev['byType'][$type])) {
                $changes["type_$type"] = ['prev' => 0, 'curr' => $count];
            }
        }

        return $changes;
    }

    /**
     * 保存报告到文件
     */
    public function saveReport(): void
    {
        file_put_contents($this->reportPath, $this->generateReport());
    }

    /**
     * 获取最后生成的报告
     */
    public function getReport(): string
    {
        return $this->generateReport();
    }

    /**
     * 打印简化版树结构到控制台 (用于开发调试)
     */
    public static function printTree(VNode $node, int $depth = 0, bool $verbose = false): void
    {
        $indent = str_repeat('  ', $depth);
        $extra = '';

        if ($verbose) {
            $extra = sprintf(" [groupId=%s]", $node->groupId);
        }

        $text = is_string($node->children) ? " \"{$node->children}\"" : '';

        echo $indent . $node->type . $extra . $text . "\n";

        if ($node->children instanceof VNode) {
            self::printTree($node->children, $depth + 1, $verbose);
        } elseif (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    self::printTree($child, $depth + 1, $verbose);
                }
            }
        }
    }

    /**
     * 查找具有特定属性值的节点
     */
    public function findNodes(VNode $root, callable $predicate): array
    {
        $results = [];
        $this->searchNodes($root, $predicate, $results);
        return $results;
    }

    private function searchNodes(VNode $node, callable $predicate, array &$results): void
    {
        if ($predicate($node)) {
            $results[] = $node;
        }

        if ($node->children instanceof VNode) {
            $this->searchNodes($node->children, $predicate, $results);
        } elseif (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $this->searchNodes($child, $predicate, $results);
                }
            }
        }
    }

    /**
     * 查找所有 span 节点
     */
    public function findSpanNodes(VNode $root): array
    {
        return $this->findNodes($root, fn($n) => $n->type === 'span');
    }

    /**
     * 查找所有带文本的节点
     */
    public function findTextNodes(VNode $root): array
    {
        return $this->findNodes($root, fn($n) =>
            is_string($n->children) && trim($n->children) !== ''
        );
    }

    /**
     * 查找滚动容器
     */
    public function findScrollContainers(VNode $root): array
    {
        // RenderNode 持有 isScrollContainer，VNode 不再有此属性。
        // 使用 RenderTreeManager::findScrollContainerAt 替代。
        return [];
    }

    /**
     * 输出所有 span 节点的详细信息
     */
    public static function dumpSpanNodes(VNode $root): void
    {
        $spans = (new self())->findSpanNodes($root);
        echo "\n=== SPAN NODES ===\n";
        foreach ($spans as $i => $span) {
            echo sprintf("#%d: type=%s, children=\"%s\"\n",
                $i,
                $span->type,
                is_string($span->children) ? $span->children : '(not string)'
            );
            if ($span->props !== null) {
                echo "  props: " . json_encode($span->props) . "\n";
            }
        }
        echo "Total spans: " . count($spans) . "\n";
    }

    /**
     * 输出布局信息
     */
    public static function dumpLayoutInfo(VNode $root): void
    {
        echo "\n=== LAYOUT INFO ===\n";
        self::printTree($root, 0, true);
    }
}