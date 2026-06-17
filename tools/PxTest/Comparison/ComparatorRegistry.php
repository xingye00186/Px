<?php

namespace PxTest\Comparison;

use PxTest\Core\ToleranceConfig;

/**
 * 对比器注册表 — 组合多个对比器，链式调用。
 */
class ComparatorRegistry
{
    /** @var ComparatorInterface[] */
    private array $comparators = [];

    public function register(ComparatorInterface $comparator): self
    {
        $this->comparators[] = $comparator;
        return $this;
    }

    /** @return array<string, ComparisonResult> */
    public function compareAll(array $baseline, array $current, ToleranceConfig $tolerance): array
    {
        $results = [];
        foreach ($this->comparators as $comp) {
            $results[$comp->name()] = $comp->compare($baseline, $current, $tolerance);
        }
        return $results;
    }

    /** 默认注册表（几何+样式+稳定性） */
    public static function default(): self
    {
        return (new self())
            ->register(new GeometryComparator())
            ->register(new StyleComparator())
            ->register(new StabilityComparator());
    }

    /** 全量注册表 */
    public static function full(): self
    {
        return (new self())
            ->register(new GeometryComparator())
            ->register(new StyleComparator())
            ->register(new StabilityComparator())
            ->register(new PixelComparator())
            ->register(new RenderNodeComparator());
    }
}
