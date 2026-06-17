<?php

namespace PxTest\Comparison;

use PxTest\Core\ToleranceConfig;

/**
 * 样式对比器 — 对比 RenderNode style 对象中所有字段逐值对比。
 */
class StyleComparator implements ComparatorInterface
{
    public function name(): string
    {
        return 'style';
    }

    public function compare(array $baseline, array $current, ToleranceConfig $tolerance): ComparisonResult
    {
        $diffs = [];
        $bStyle = $baseline['style'] ?? [];
        $cStyle = $current['style'] ?? [];
        $allKeys = array_unique(array_merge(array_keys($bStyle), array_keys($cStyle)));

        foreach ($allKeys as $k) {
            $bv = $bStyle[$k] ?? null;
            $cv = $cStyle[$k] ?? null;

            if ($bv === null && $cv === null) continue;
            if ($bv === null) {
                $diffs[] = "style.$k: added=" . json_encode($cv, JSON_UNESCAPED_UNICODE);
            } elseif ($cv === null) {
                $diffs[] = "style.$k: removed (was " . json_encode($bv, JSON_UNESCAPED_UNICODE) . ")";
            } elseif ($bv !== $cv) {
                $diffs[] = "style.$k: baseline=" . json_encode($bv, JSON_UNESCAPED_UNICODE)
                         . " current=" . json_encode($cv, JSON_UNESCAPED_UNICODE);
            }
        }

        return empty($diffs)
            ? ComparisonResult::pass($this->name())
            : ComparisonResult::fail($this->name(), $diffs);
    }
}
