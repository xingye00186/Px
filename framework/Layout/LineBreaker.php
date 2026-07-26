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
     * @param int $strutAscent 行盒 root inline box strut ascent（对标 Blink
     *        NGInlineBoxState 根盒：容器字体 metrics + half-leading，可为负——
     *        负值在 max 中自然淘汰，line-height:0 时 strut 不撑行）
     * @param int $strutDescent 同上 descent 分量
     * @return LineBox[] 断裂后的行盒序列
     */
    public static function breakLines(array $items, int $availableWidth, int $defaultLineHeight, int $strutAscent = 0, int $strutDescent = 0): array
    {
        if (empty($items)) return [];

        $lines = [];
        $currentItems = [];
        $currentWidth = 0;
        // 每行以 strut 开始（CSS 2.2 §10.8.1：每个行盒都含容器字体 strut，
        // 即使行内只有 atomic inline）；负 strut 分量被 item max 自然覆盖。
        $currentAscent = $strutAscent;
        $currentDescent = $strutDescent;

        foreach ($items as $item) {
            $itemW = $item->totalWidth();

            // 换行判断：当前行放不下且已有内容（第一项永不单独换行）
            if ($currentWidth + $itemW > $availableWidth && !empty($currentItems)) {
                $lh = self::computeLineHeight($currentAscent, $currentDescent, $currentItems, $defaultLineHeight);
                $lines[] = new LineBox($currentItems, max(0, $currentAscent), max(0, $currentDescent), $currentWidth, $lh);
                $currentItems = [];
                $currentWidth = 0;
                $currentAscent = $strutAscent;
                $currentDescent = $strutDescent;
            }

            $currentItems[] = $item;
            $currentWidth += $itemW;
            if ($item->ascent > $currentAscent) $currentAscent = $item->ascent;
            if ($item->descent > $currentDescent) $currentDescent = $item->descent;
        }

        // 最后一行
        if (!empty($currentItems)) {
            $lh = self::computeLineHeight($currentAscent, $currentDescent, $currentItems, $defaultLineHeight);
            $lines[] = new LineBox($currentItems, max(0, $currentAscent), max(0, $currentDescent), $currentWidth, $lh);
        }

        return $lines;
    }

    /**
     * 计算行高（CSS 2.2 §10.8）。
     *
     * ascent/descent 已含 root strut 分量（可负，负分量表示 line-height 小于
     * 字体自然高度，不得反向拉伸行盒）；行盒高 = max(0,asc)+max(0,desc) 与
     * 文本项 line-height 取大（文本项的 line-height 贡献保留既有近似）。
     *
     * @param InlineItem[] $items
     */
    private static function computeLineHeight(int $ascent, int $descent, array $items, int $defaultLineHeight): int
    {
        $maxLH = max(0, $ascent) + max(0, $descent);

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
