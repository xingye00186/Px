<?php

namespace PxTest\Comparison;

use PxTest\Core\ToleranceConfig;

/**
 * 几何对比器 — 对比 RenderNode 的 x/y/w/h/visualW/visualH。
 */
class GeometryComparator implements ComparatorInterface
{
    /** @var string[] 几何字段列表 */
    private const GEO_FIELDS = ['x', 'y', 'w', 'h', 'visualW', 'visualH'];

    public function name(): string
    {
        return 'geometry';
    }

    public function compare(array $baseline, array $current, ToleranceConfig $tolerance): ComparisonResult
    {
        $diffs = [];

        foreach (self::GEO_FIELDS as $f) {
            $b = (int)($baseline[$f] ?? 0);
            $c = (int)($current[$f] ?? 0);
            $delta = abs($b - $c);
            $tol = $tolerance->forProperty($f);

            if ($delta > $tol) {
                $diffs[] = "$f: baseline=$b current=$c diff=$delta (tolerance=$tol)";
            }
        }

        // 类型一致性检查
        if (($baseline['type'] ?? '') !== ($current['type'] ?? '')) {
            $diffs[] = 'type: baseline=' . ($baseline['type'] ?? '?')
                     . ' current=' . ($current['type'] ?? '?');
        }

        return empty($diffs)
            ? ComparisonResult::pass($this->name())
            : ComparisonResult::fail($this->name(), $diffs);
    }
}
