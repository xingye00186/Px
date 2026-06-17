<?php

namespace PxTest\Comparison;

use PxTest\Core\ToleranceConfig;

/**
 * 稳定性对比器 — 多帧间位置/尺寸漂移检测。
 */
class StabilityComparator implements ComparatorInterface
{
    public function name(): string { return 'stability'; }

    public function compare(array $baseline, array $current, ToleranceConfig $tolerance): ComparisonResult
    {
        $diffs = [];
        $bNodes = $baseline['nodes'] ?? $baseline;
        $cNodes = $current['nodes'] ?? $current;

        $max = max(count($bNodes), count($cNodes));
        for ($i = 0; $i < $max; $i++) {
            $b = $bNodes[$i] ?? null;
            $c = $cNodes[$i] ?? null;
            if ($b === null || $c === null) { $diffs[] = "node[$i]: count mismatch"; continue; }

            foreach (['x','y','w','h'] as $f) {
                $delta = abs((int)($c[$f] ?? 0) - (int)($b[$f] ?? 0));
                if ($delta > $tolerance->forProperty($f)) {
                    $diffs[] = "node[$i].$f: delta=$delta";
                }
            }
        }
        return empty($diffs) ? ComparisonResult::pass($this->name()) : ComparisonResult::fail($this->name(), $diffs);
    }
}
