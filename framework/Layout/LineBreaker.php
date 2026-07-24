<?php

namespace Px\Layout;

use native_types;

/**
 * LineBreaker — 行断裂器（对标 Blink NGLineBreaker）
 *
 * 将 InlineItem 序列按可用宽度断裂为多行（LineBox[]）。
 *
 * 当前实现：简单贪心断裂（一行装满后换行）。
 * 未来扩展：
 *   - word-break: break-all / break-word
 *   - overflow-wrap: anywhere
 *   - hyphens: auto
 *   - text-align: justify（需回传行最终宽度）
 */
class LineBreaker
{
    /**
     * 将 InlineItem 序列断裂为多个 LineBox。
     *
     * @param InlineItem[] $items 待布局的内联项序列
     * @param int $availableWidth 可用行宽
     * @param int $defaultLineHeight 默认行高（line-height: normal 时使用）
     * @return LineBox[] 断裂后的行盒序列
     */
    public static function breakLines(array $items, int $availableWidth, int $defaultLineHeight): array
    {
        if (empty($items)) return [];

        $lines = [];
        $currentItems = [];
        $currentWidth = 0;
        $currentAscent = 0;
        $currentDescent = 0;

        foreach ($items as $item) {
            $itemW = $item->totalWidth();

            // 换行判断：当前行放不下且已有内容（第一项永不单独换行）
            if ($currentWidth + $itemW > $availableWidth && !empty($currentItems)) {
                $lh = self::computeLineHeight($currentAscent, $currentDescent, $currentItems, $defaultLineHeight);
                $lines[] = new LineBox($currentItems, $currentAscent, $currentDescent, $currentWidth, $lh);
                $currentItems = [];
                $currentWidth = 0;
                $currentAscent = 0;
                $currentDescent = 0;
            }

            $currentItems[] = $item;
            $currentWidth += $itemW;
            if ($item->ascent > $currentAscent) $currentAscent = $item->ascent;
            if ($item->descent > $currentDescent) $currentDescent = $item->descent;
        }

        // 最后一行
        if (!empty($currentItems)) {
            $lh = self::computeLineHeight($currentAscent, $currentDescent, $currentItems, $defaultLineHeight);
            $lines[] = new LineBox($currentItems, $currentAscent, $currentDescent, $currentWidth, $lh);
        }

        return $lines;
    }

    /**
     * 计算行高（CSS 2.2 §10.8）。
     *
     * 仅 text 类型 InlineItem 贡献 font-based line-height。
     * atomic 类型（inline-block/replaced）的贡献已在 ascent 中体现。
     *
     * @param InlineItem[] $items
     */
    private static function computeLineHeight(int $ascent, int $descent, array $items, int $defaultLineHeight): int
    {
        $maxLH = $ascent + $descent;

        foreach ($items as $item) {
            if ($item->type !== InlineItem::TYPE_TEXT) continue;

            $itemLH = $defaultLineHeight;
            if ($item->style !== null) {
                $declaredLH = $item->style->getLineHeight();
                if ($declaredLH > 0) {
                    $itemLH = (int)$declaredLH;
                } else {
                    $fs = $item->style->getFontSize() > 0 ? $item->style->getFontSize() : 16;
                    $itemLH = (int)($fs * 1.2);
                }
            }
            if ($itemLH > $maxLH) $maxLH = $itemLH;
        }

        return $maxLH;
    }
}
