<?php

namespace Px\Rendering\Layout;

use native_types;

/**
 * LayoutCache — 布局缓存（Phase 4）
 *
 * Map<LayoutCacheKey, PhysicalFragment>。
 * 使用精确匹配：ConstraintSpace 完全相同则跳过算法。
 *
 * AOT 安全：无闭包，无动态数组键。
 */
class LayoutCache
{
    /** @var array<int, array{cacheKey: LayoutCacheKey, fragment: PhysicalFragment}> */
    private array $entries = [];

    /** 最大缓存条目数 */
    private int $maxSize;

    public function __construct(int $maxSize = 256)
    {
        $this->maxSize = $maxSize;
    }

    /**
     * 查找缓存。
     * @return PhysicalFragment|null 缓存命中返回 Fragment，否则 null
     */
    public function find(LayoutCacheKey $key): ?PhysicalFragment
    {
        foreach ($this->entries as $entry) {
            if ($entry['cacheKey']->equals($key)) {
                return $entry['fragment'];
            }
        }
        return null;
    }

    /**
     * 存入缓存。
     */
    public function set(LayoutCacheKey $key, PhysicalFragment $fragment): void
    {
        if (count($this->entries) >= $this->maxSize) {
            array_shift($this->entries);
        }
        $this->entries[] = [
            'cacheKey' => $key,
            'fragment' => $fragment,
        ];
    }

    /**
     * 清空缓存（样式变更等场景）。
     */
    public function clear(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
