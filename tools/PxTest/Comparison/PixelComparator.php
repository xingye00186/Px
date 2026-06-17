<?php

namespace PxTest\Comparison;

use PxTest\Core\ToleranceConfig;

/**
 * 像素对比器 — 截图像素级差异对比。
 */
class PixelComparator implements ComparatorInterface
{
    public function name(): string { return 'pixel'; }

    public function compare(array $baseline, array $current, ToleranceConfig $tolerance): ComparisonResult
    {
        $bTotal = $baseline['pixels'] ?? $baseline['totalPixels'] ?? 0;
        $cTotal = $current['pixels'] ?? $current['totalPixels'] ?? 0;
        $bDiff  = $baseline['diffPixels'] ?? $baseline['diffCount'] ?? 0;
        $cDiff  = $current['diffPixels'] ?? $current['diffCount'] ?? 0;
        $threshold = $tolerance->forCategory('pixel');

        $pct = $bTotal > 0 ? round(($bDiff / $bTotal) * 100, 2) : 0;
        $diffs = [];
        if ($pct > $threshold) {
            $diffs[] = "pixel diff: {$pct}% > {$threshold}% threshold";
        }
        return empty($diffs) ? ComparisonResult::pass($this->name()) : ComparisonResult::fail($this->name(), $diffs);
    }
}
