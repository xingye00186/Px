<?php

namespace PxTest\Layout;

/**
 * 统一树展平器 — 消除 5 个 flatten 变体。
 *
 * 策略模式支持三种展平方式：
 *   PathStrategy:     路径索引 (用于 check_regression)
 *   ParentRelativeStrategy: 相对父容器坐标 (用于 shared_test_lib)
 *   TypeFilterStrategy:     按类型过滤 (用于 run.php)
 */
class TreeFlattener
{
    /** @var callable */
    private $strategy;

    private function __construct(callable $strategy)
    {
        $this->strategy = $strategy;
    }

    /** Path 模式: 节点路径如 "0.1.2" */
    public static function pathStrategy(): self
    {
        return new self(function (array $node, string $path, int $depth, ?array $containerOffset) {
            return [[
                'path' => $path,
                'type' => $node['type'] ?? '?',
                'x' => (int)($node['x'] ?? 0),
                'y' => (int)($node['y'] ?? 0),
                'w' => (int)($node['w'] ?? 0),
                'h' => (int)($node['h'] ?? 0),
                'visualW' => (int)($node['visualW'] ?? 0),
                'visualH' => (int)($node['visualH'] ?? 0),
                'layer' => (int)($node['layer'] ?? 0),
                'isScrollContainer' => (bool)($node['isScrollContainer'] ?? false),
                'content' => $node['content'] ?? null,
                'style' => $node['style'] ?? [],
            ]];
        });
    }

    /** ParentRelative 模式: relX/relY 相对于容器 */
    public static function parentRelativeStrategy(): self
    {
        return new self(function (array $node, string $path, int $depth, ?array $containerOffset) {
            $nodeX = (int)($node['x'] ?? 0);
            $nodeY = (int)($node['y'] ?? 0);

            if ($containerOffset !== null) {
                $relX = $nodeX - $containerOffset['x'];
                $relY = $nodeY - $containerOffset['y'];
            } else {
                $relX = $nodeX;
                $relY = $nodeY;
            }

            $useW = isset($node['visualW']) && $node['visualW'] > ($node['w'] ?? 0)
                ? $node['visualW'] : ($node['w'] ?? 0);
            $useH = isset($node['visualH']) && $node['visualH'] > ($node['h'] ?? 0)
                ? $node['visualH'] : ($node['h'] ?? 0);

            return [[
                'type' => $node['type'] ?? '?',
                'x' => $nodeX, 'y' => $nodeY,
                'relX' => $relX, 'relY' => $relY,
                'w' => $useW, 'h' => $useH,
                'content' => str_replace("\r\n", "\n", (string)($node['content'] ?? '')),
                'style' => $node['style'] ?? [],
                'depth' => $depth,
            ]];
        });
    }

    /** TypeFilter 模式: 仅收集特定 type 节点 */
    public static function typeFilterStrategy(string $filterType): self
    {
        return new self(function (array $node, string $path, int $depth, ?array $containerOffset) use ($filterType) {
            if (($node['type'] ?? '') !== $filterType) return [];
            return [[
                'type' => $node['type'],
                'x' => (int)($node['x'] ?? 0),
                'y' => (int)($node['y'] ?? 0),
                'w' => (int)($node['w'] ?? 0),
                'h' => (int)($node['h'] ?? 0),
                'idx' => $node['idx'] ?? null,
            ]];
        });
    }

    /**
     * 展平整棵树。
     * @return array<int, array>
     */
    public function flatten(array $root): array
    {
        return $this->flattenRecursive($root, '0', 0, null);
    }

    private function flattenRecursive(array $node, string $path, int $depth, ?array $containerOffset): array
    {
        $result = ($this->strategy)($node, $path, $depth, $containerOffset);

        if (!empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $childPath = $path . '.' . $i;
                    $result = array_merge($result, $this->flattenRecursive($child, $childPath, $depth + 1, $containerOffset));
                }
            }
        }

        return $result;
    }
}
