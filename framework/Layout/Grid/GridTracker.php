<?php

namespace Px\Layout\Grid;

use Px\Css\CssMappings;

/**
 * GridTracker — 网格轨道解析器
 *
 * 将 grid-template-columns/rows 字符串解析为 GridTrack[]，
 * 并基于容器尺寸计算每个轨道的像素尺寸。
 */
class GridTracker
{
    /**
     * 解析模板字符串并计算轨道尺寸。
     *
     * @param string $template   grid-template-columns 或 grid-template-rows 字符串
     * @param int    $containerSize 容器 content box 尺寸
     * @param int    $gap        轨道间隙
     * @return GridTrack[]
     */
    public static function computeTracks(string $template, int $containerSize, int $gap = 0): array
    {
        $spec = CssMappings::parseGridTemplateValue($template);
        $tracks = [];
        $repeat = $spec['repeat'] ?? null;
        $list = $spec['list'] ?? [];

        $repeatCount = 0;
        $repeatSize = null;
        if ($repeat !== null) {
            if ($repeat === 'auto-fill' || $repeat === 'auto-fit') {
                // auto-fill: compute number of columns that fit min size
                $minSize = (int)($spec['min'] ?? 100);
                $maxVal = (float)($spec['max'] ?? 1);
                $maxUnit = $spec['maxTrack'] ?? 'fr';
                $effectiveMin = $minSize + $gap;
                if ($effectiveMin > 0) {
                    $repeatCount = max(1, (int)(($containerSize + $gap) / $effectiveMin));
                } else {
                    $repeatCount = 1;
                }
                // Build spec string like '1fr' from parsed max
                $repeatSize = $maxVal . $maxUnit;
            } else {
                $repeatCount = (int)($spec['count'] ?? 1);
                $repeatSize = $spec['size'] ?? ($spec['track'] ?? null);
            }
        }

        // Build track list (expand repeat)
        foreach ($list as $item) {
            $tracks[] = self::parseTrackSpec($item);
        }
        if ($repeatCount > 0 && $repeatSize !== null) {
            for ($i = 0; $i < $repeatCount; $i++) {
                $tracks[] = self::parseTrackSpec($repeatSize);
            }
        }

        if (empty($tracks)) {
            return [];
        }

        // Phase 1: resolve non-fr tracks
        $totalFr = 0;
        $usedSpace = $gap * (count($tracks) - 1);
        foreach ($tracks as $t) {
            if ($t->isFr) {
                $totalFr += $t->frValue;
            } elseif ($t->isAuto) {
                $t->size = 0; // content-based, resolved later
            } elseif ($t->isMinmax) {
                // minmax with fr max: treat as fr
                if ($t->minmaxMaxFr > 0) {
                    $totalFr += $t->minmaxMaxFr;
                    $t->isFr = true;
                    $t->frValue = $t->minmaxMaxFr;
                } else {
                    $t->size = $t->minmaxMin;
                    // Resolve percentage in minmax spec if size is 0
                    if ($t->size <= 0 && $t->spec !== '' && str_contains($t->spec, '%')) {
                        $t->size = max(1, self::resolveSize($t->spec, $containerSize));
                    }
                    $usedSpace += max(0, $t->size);
                }
            } else {
                // fixed size: resolve percentage if needed
                if ($t->size <= 0 && $t->spec !== '' && str_ends_with(trim($t->spec), '%')) {
                    $t->size = self::resolveSize($t->spec, $containerSize);
                }
                $usedSpace += max(0, $t->size);
            }
        }

        // Phase 2: distribute remaining space to fr tracks
        $remaining = max(0, $containerSize - $usedSpace);
        if ($totalFr > 0) {
            $frUnit = $remaining / $totalFr;
            $usedSoFar = 0;
            $frIdx = 0;
            $frCount = 0;
            foreach ($tracks as $t) { if ($t->isFr) $frCount++; }
            foreach ($tracks as $t) {
                if ($t->isFr) {
                    $frIdx++;
                    if ($frIdx < $frCount) {
                        $t->size = (int)floor($t->frValue * $frUnit);
                        $usedSoFar += $t->size;
                    } else {
                        $t->size = max(0, $remaining - $usedSoFar);
                    }
                }
            }
        } elseif (count($tracks) > 0) {
            // No fr: distribute remaining equally (for auto tracks)
            $autoCount = 0;
            foreach ($tracks as $t) { if ($t->isAuto) $autoCount++; }
            if ($autoCount > 0) {
                $autoSize = max(0, (int)($remaining / $autoCount));
                foreach ($tracks as $t) {
                    if ($t->isAuto) $t->size = $autoSize;
                }
            }
        }

        // Phase 3: compute positions
        $cursor = 0;
        foreach ($tracks as $t) {
            $t->start = $cursor;
            $t->end = $cursor + max(0, $t->size);
            $cursor = $t->end + $gap;
        }

        return $tracks;
    }

    /**
     * 解析单个轨道规格字符串。
     */
    private static function parseTrackSpec(string $spec): GridTrack
    {
        $t = new GridTrack($spec);
        $s = trim($spec);

        if ($s === '' || $s === 'auto') {
            $t->isAuto = true;
            return $t;
        }

        // minmax(min, max)
        if (preg_match('/^minmax\((.+),(.+)\)$/i', $s, $m)) {
            $t->isMinmax = true;
            $minStr = trim($m[1]);
            $maxStr = trim($m[2]);

            if (str_ends_with($minStr, 'fr')) {
                $t->minmaxMin = 0;
            } else {
                $t->minmaxMin = self::parsePxValue($minStr);
            }

            if (str_ends_with($maxStr, 'fr')) {
                $t->minmaxMaxFr = (float)$maxStr;
            } else {
                $t->size = self::parsePxValue($maxStr);
            }
            return $t;
        }

        // fr unit
        if (str_ends_with($s, 'fr')) {
            $t->isFr = true;
            $t->frValue = (float)$s;
            return $t;
        }

        // px / other units
        $t->size = self::parsePxValue($s);
        return $t;
    }

    /**
     * 将 CSS 长度字符串转为像素值。
     * Parses px, %, and unitless values against containerSize for % resolution.
     */
    private static function parsePxValue(string $s): int
    {
        $s = trim($s);
        if (str_ends_with($s, 'px')) return (int)$s;
        if (str_ends_with($s, '%')) return 0; // percentage resolved in computeTracks
        if (is_numeric($s)) return (int)$s;
        return 0;
    }

    /**
     * Resolve px/num/% value against container size.
     */
    private static function resolveSize(string $raw, int $containerSize): int
    {
        $raw = trim($raw);
        if (str_ends_with($raw, '%')) {
            $pct = (float)$raw;
            return (int)($containerSize * $pct / 100);
        }
        if (str_ends_with($raw, 'px')) return (int)$raw;
        if (is_numeric($raw)) return (int)$raw;
        return 0;
    }
}
